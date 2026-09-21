<?php

declare(strict_types=1);

namespace App\Core\Commerce\Admin;

use App\Core\Commerce\CommerceCapabilities;
use App\Core\Commerce\CommerceConflictException;
use App\Core\Commerce\CommerceInput;
use App\Core\Commerce\CommerceTranslationStatus;
use App\Core\Commerce\CommerceValidationException;
use App\Core\Commerce\Persistence\CommercePersistenceException;
use App\Core\Commerce\Persistence\CommerceTableNames;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Media\MediaService;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use DateTimeImmutable;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Owns the Commerce-to-Media association boundary.
 *
 * Media bytes and metadata remain owned by WebAdmin. Commerce persists only
 * stable public UUID references after checking the shared private catalog.
 */
final class CommerceAdminMediaRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CommerceTableNames $commerceTables,
        private readonly WebAdminTableNames $webAdminTables,
        private readonly WebAdminMutationActorGate $actorGate,
        private readonly bool $quarantineEnabled = false
    ) {
    }

    /**
     * @param list<string> $galleryPublicIds
     * @param list<string> $activeLocales
     * @param array<string, string> $alternativeTexts
     * @param array<string, string> $captions
     */
    public function replaceProductMedia(
        string $productPublicId,
        ?string $coverPublicId,
        array $galleryPublicIds,
        int $expectedLockVersion,
        string $sourceLocale,
        array $activeLocales,
        array $alternativeTexts,
        array $captions,
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        DateTimeImmutable $now
    ): bool {
        $productPublicId = CommerceInput::uuid($productPublicId);
        $sourceLocale = CommerceInput::locale($sourceLocale);
        $activeLocales = CommerceInput::locales($activeLocales);
        if (!in_array($sourceLocale, $activeLocales, true)) {
            throw new CommerceValidationException('Invalid Commerce media source locale.');
        }
        $coverPublicId = $coverPublicId === null
            ? null : CommerceInput::uuid($coverPublicId);
        if ($expectedLockVersion < 1 || count($galleryPublicIds) > 50) {
            throw new CommerceValidationException('Invalid Commerce media assignment.');
        }
        $gallery = [];
        foreach ($galleryPublicIds as $publicId) {
            if (!is_string($publicId)) {
                throw new CommerceValidationException('Invalid Commerce media assignment.');
            }
            $publicId = CommerceInput::uuid($publicId);
            if (isset($gallery[$publicId]) || $publicId === $coverPublicId) {
                throw new CommerceValidationException('Commerce media references must be unique.');
            }
            $gallery[$publicId] = true;
        }
        $requested = $coverPublicId === null
            ? array_keys($gallery)
            : [$coverPublicId, ...array_keys($gallery)];
        $content = [];
        foreach ($requested as $mediaPublicId) {
            $alt = $alternativeTexts[$mediaPublicId] ?? null;
            $caption = $captions[$mediaPublicId] ?? null;
            if (!is_string($alt) || !is_string($caption)) {
                throw new CommerceValidationException('Media alternative text is required.');
            }
            $content[$mediaPublicId] = [
                'alt' => CommerceInput::text($alt, 500),
                'caption' => CommerceInput::nullableText($caption, 2_000),
            ];
        }

        if ($this->pdo->inTransaction()) {
            throw new CommercePersistenceException();
        }
        try {
            if (!$this->pdo->beginTransaction()) {
                throw new CommercePersistenceException();
            }
            $actor = $this->actorGate->authorizeAll(
                $sessionToken,
                $csrfToken,
                [CommerceCapabilities::PRODUCTS_EDIT, MediaService::VIEW_CAPABILITY]
            );
            if ($actor === null) {
                $this->pdo->rollBack();

                return false;
            }
            $product = $this->one(
                'SELECT id, lock_version FROM '
                . $this->commerceTables->table('products')
                . ' WHERE public_id = :public_id' . $this->forUpdate(),
                ['public_id' => $productPublicId]
            );
            if ($product === null) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            $productId = $this->positiveInt($product['id'] ?? null);
            if ($this->positiveInt($product['lock_version'] ?? null) !== $expectedLockVersion) {
                throw new CommerceConflictException(CommerceConflictException::STALE);
            }
            foreach ($requested as $mediaPublicId) {
                if (!$this->mediaExists($mediaPublicId)) {
                    throw new CommerceValidationException('Selected media is unavailable.');
                }
            }

            $this->execute(
                'DELETE FROM ' . $this->commerceTables->table('product_media')
                . ' WHERE product_id = :product_id',
                ['product_id' => $productId]
            );
            $timestamp = CommerceInput::formatUtc($now);
            if ($coverPublicId !== null) {
                $mediaId = $this->insertMedia($productId, $coverPublicId, 'cover', 0, $timestamp);
                $this->insertLocalizations(
                    $mediaId,
                    $sourceLocale,
                    $activeLocales,
                    $content[$coverPublicId],
                    $timestamp
                );
            }
            foreach (array_keys($gallery) as $sortOrder => $mediaPublicId) {
                $mediaId = $this->insertMedia(
                    $productId,
                    $mediaPublicId,
                    'gallery',
                    $sortOrder,
                    $timestamp
                );
                $this->insertLocalizations(
                    $mediaId,
                    $sourceLocale,
                    $activeLocales,
                    $content[$mediaPublicId],
                    $timestamp
                );
            }
            $statement = $this->execute(
                'UPDATE ' . $this->commerceTables->table('products')
                . ' SET lock_version = lock_version + 1, updated_at = :updated_at '
                . 'WHERE id = :id AND lock_version = :expected',
                [
                    'updated_at' => $timestamp,
                    'id' => $productId,
                    'expected' => $expectedLockVersion,
                ]
            );
            if ($statement->rowCount() !== 1 || !$this->pdo->commit()) {
                throw new CommerceConflictException(CommerceConflictException::STALE);
            }

            return true;
        } catch (CommerceValidationException|CommerceConflictException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        } catch (Throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new CommercePersistenceException();
        }
    }

    public function saveProductMediaLocalization(
        string $productPublicId,
        string $mediaPublicId,
        string $locale,
        string $sourceLocale,
        string $alternativeText,
        ?string $caption,
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        DateTimeImmutable $now
    ): bool {
        $productPublicId = CommerceInput::uuid($productPublicId);
        $mediaPublicId = CommerceInput::uuid($mediaPublicId);
        $locale = CommerceInput::locale($locale);
        $sourceLocale = CommerceInput::locale($sourceLocale);
        $alternativeText = CommerceInput::text($alternativeText, 500);
        $caption = CommerceInput::nullableText($caption, 2_000);
        try {
            if ($this->pdo->inTransaction() || !$this->pdo->beginTransaction()) {
                throw new CommercePersistenceException();
            }
            if ($this->actorGate->authorizeAll(
                $sessionToken,
                $csrfToken,
                [CommerceCapabilities::PRODUCTS_EDIT, MediaService::VIEW_CAPABILITY]
            ) === null) {
                $this->pdo->rollBack();

                return false;
            }
            $row = $this->one(
                'SELECT pm.id FROM ' . $this->commerceTables->table('product_media')
                . ' pm INNER JOIN ' . $this->commerceTables->table('products')
                . ' p ON p.id = pm.product_id INNER JOIN '
                . $this->commerceTables->table('product_media_localizations')
                . ' l ON l.media_id = pm.id AND l.locale = :locale '
                . 'WHERE p.public_id = :product '
                . 'AND pm.media_asset_public_id = :media' . $this->forUpdate(),
                [
                    'locale' => $locale,
                    'product' => $productPublicId,
                    'media' => $mediaPublicId,
                ]
            );
            if ($row === null) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            $statement = $this->execute(
                'UPDATE ' . $this->commerceTables->table('product_media_localizations')
                . ' SET alt_text = :alt, caption = :caption, '
                . 'translation_status = :status, updated_at = :updated_at '
                . 'WHERE media_id = :media_id AND locale = :locale',
                [
                    'alt' => $alternativeText,
                    'caption' => $caption,
                    'status' => $locale === $sourceLocale
                        ? CommerceTranslationStatus::SOURCE->value
                        : CommerceTranslationStatus::TRANSLATED->value,
                    'updated_at' => CommerceInput::formatUtc($now),
                    'media_id' => $this->positiveInt($row['id'] ?? null),
                    'locale' => $locale,
                ]
            );
            if (!$this->pdo->commit()) {
                throw new CommercePersistenceException();
            }

            return true;
        } catch (CommerceValidationException|CommerceConflictException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        } catch (Throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new CommercePersistenceException();
        }
    }

    private function mediaExists(string $publicId): bool
    {
        $quarantineJoin = $this->quarantineEnabled
            ? ' LEFT JOIN ' . $this->webAdminTables->table('media_quarantines')
                . ' q ON q.asset_id = a.id '
            : ' ';
        $quarantineFilter = $this->quarantineEnabled
            ? 'AND q.asset_id IS NULL '
            : '';
        $row = $this->one(
            'SELECT a.id FROM ' . $this->webAdminTables->table('media_assets')
            . ' a INNER JOIN ' . $this->webAdminTables->table('media_variants')
            . ' v ON v.asset_id = a.id' . $quarantineJoin
            . 'WHERE a.public_id = :public_id AND v.mime = :mime '
            . $quarantineFilter . 'LIMIT 1' . $this->forUpdate(),
            ['public_id' => $publicId, 'mime' => 'image/avif']
        );

        return $row !== null;
    }

    private function insertMedia(
        int $productId,
        string $publicId,
        string $role,
        int $sortOrder,
        string $timestamp
    ): int {
        $this->execute(
            'INSERT INTO ' . $this->commerceTables->table('product_media')
            . ' (product_id, media_asset_public_id, role, sort_order, created_at, updated_at) '
            . 'VALUES (:product_id, :media, :role, :sort_order, :created_at, :updated_at)',
            [
                'product_id' => $productId,
                'media' => $publicId,
                'role' => $role,
                'sort_order' => $sortOrder,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        );

        $id = $this->pdo->lastInsertId();
        if (!is_string($id) || preg_match('/\A[1-9][0-9]*\z/', $id) !== 1) {
            throw new CommercePersistenceException();
        }

        return (int) $id;
    }

    /**
     * @param list<string> $activeLocales
     * @param array{alt:string,caption:?string} $content
     */
    private function insertLocalizations(
        int $mediaId,
        string $sourceLocale,
        array $activeLocales,
        array $content,
        string $timestamp
    ): void {
        foreach ($activeLocales as $locale) {
            $source = $locale === $sourceLocale;
            $this->execute(
                'INSERT INTO '
                . $this->commerceTables->table('product_media_localizations')
                . ' (media_id, locale, alt_text, caption, translation_status, '
                . 'created_at, updated_at) VALUES (:media_id, :locale, :alt, '
                . ':caption, :status, :created_at, :updated_at)',
                [
                    'media_id' => $mediaId,
                    'locale' => $locale,
                    'alt' => $source ? $content['alt'] : null,
                    'caption' => $source ? $content['caption'] : null,
                    'status' => $source
                        ? CommerceTranslationStatus::SOURCE->value
                        : CommerceTranslationStatus::FALLBACK->value,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]
            );
        }
    }

    /** @param array<string, int|string|null> $parameters */
    private function execute(string $sql, array $parameters): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new CommercePersistenceException();
        }
        foreach ($parameters as $name => $value) {
            $statement->bindValue(
                $name,
                $value,
                $value === null
                    ? PDO::PARAM_NULL
                    : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR)
            );
        }
        if (!$statement->execute()) {
            throw new CommercePersistenceException();
        }

        return $statement;
    }

    /** @param array<string, int|string|null> $parameters @return array<string, mixed>|null */
    private function one(string $sql, array $parameters): ?array
    {
        $row = $this->execute($sql, $parameters)->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new CommercePersistenceException();
        }

        return $row;
    }

    private function positiveInt(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            return (int) $value;
        }
        throw new CommercePersistenceException();
    }

    private function forUpdate(): string
    {
        return $this->commerceTables->driver() === 'mysql' ? ' FOR UPDATE' : '';
    }
}
