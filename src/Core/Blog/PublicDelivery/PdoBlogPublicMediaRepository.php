<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicDelivery;

use App\Core\Blog\BlogInput;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImageCandidate;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\WebAdmin\Media\MediaStoredVariant;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Read-only cross-scope projection for media referenced by current published
 * Blog documents. Revision-only and draft references never satisfy EXISTS.
 */
final class PdoBlogPublicMediaRepository implements
    BlogPublicMediaRepositoryInterface
{
    private const SUPPORTED_DRIVERS = ['mysql', 'sqlite'];

    private readonly string $contentDocuments;
    private readonly string $contentMedia;
    private readonly string $localizations;
    private readonly string $mediaAssets;
    private readonly string $mediaVariants;

    public function __construct(
        private readonly PDO $pdo,
        MigrationScope $blogScope,
        MigrationScope $webAdminScope
    ) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (
                !is_string($driver)
                || !in_array($driver, self::SUPPORTED_DRIVERS, true)
                || $blogScope->moduleId() !== 'blog'
                || $webAdminScope->moduleId() !== 'webadmin'
                || $pdo->getAttribute(PDO::ATTR_ERRMODE)
                    !== PDO::ERRMODE_EXCEPTION
                || (
                    $driver === 'mysql'
                    && !in_array(
                        $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
                        [false, 0, '0'],
                        true
                    )
                )
            ) {
                throw new BlogPublicMediaException();
            }
            if ($driver === 'sqlite') {
                $foreignKeys = $pdo->query('PRAGMA foreign_keys');
                if (
                    !$foreignKeys instanceof PDOStatement
                    || !in_array($foreignKeys->fetchColumn(), [1, '1'], true)
                ) {
                    throw new BlogPublicMediaException();
                }
            }

            $this->contentDocuments = $blogScope->quotedTable(
                'content_docs',
                $driver
            );
            $this->contentMedia = $blogScope->quotedTable(
                'content_media',
                $driver
            );
            $this->localizations = $blogScope->quotedTable(
                'post_localizations',
                $driver
            );
            $this->mediaAssets = $webAdminScope->quotedTable(
                'media_assets',
                $driver
            );
            $this->mediaVariants = $webAdminScope->quotedTable(
                'media_variants',
                $driver
            );
        } catch (BlogPublicMediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPublicMediaException();
        }
    }

    public function resolvePublishedImage(
        string $localizationPublicId,
        string $mediaAssetPublicId
    ): ?BlogResolvedImage {
        try {
            $localizationPublicId = BlogInput::publicId(
                $localizationPublicId
            );
            $mediaAssetPublicId = BlogInput::publicId($mediaAssetPublicId);
            $statement = $this->prepare(
                'SELECT v.width, v.height FROM ' . $this->mediaVariants
                . ' v JOIN ' . $this->mediaAssets . ' a ON a.id = v.asset_id '
                . 'WHERE a.public_id = :asset_public_id '
                . 'AND v.mime = :mime AND EXISTS ('
                . 'SELECT 1 FROM ' . $this->contentMedia . ' cm JOIN '
                . $this->contentDocuments . ' d ON d.id = cm.document_id '
                . 'JOIN ' . $this->localizations
                . ' l ON l.id = d.localization_id '
                . 'WHERE l.public_id = :localization_public_id '
                . "AND l.status = 'published' "
                . 'AND cm.media_asset_public_id = a.public_id'
                . ') ORDER BY v.width ASC LIMIT 9'
            );
            $this->execute($statement, [
                'asset_public_id' => $mediaAssetPublicId,
                'localization_public_id' => $localizationPublicId,
                'mime' => 'image/avif',
            ]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows) > BlogResolvedImage::MAX_CANDIDATES) {
                throw new BlogPublicMediaException();
            }
            if ($rows === []) {
                return null;
            }

            $candidates = [];
            $largestWidth = 0;
            $largestHeight = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new BlogPublicMediaException();
                }
                $width = $this->positiveInteger($row['width'] ?? null);
                $height = $this->positiveInteger($row['height'] ?? null);
                if ($width > 2_560 || $height > 2_560) {
                    throw new BlogPublicMediaException();
                }
                $candidates[] = new BlogResolvedImageCandidate(
                    BlogPublicMediaRoute::path($mediaAssetPublicId, $width),
                    $width
                );
                $largestWidth = $width;
                $largestHeight = $height;
            }

            return new BlogResolvedImage(
                $mediaAssetPublicId,
                $candidates,
                $largestWidth,
                $largestHeight
            );
        } catch (BlogPublicMediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPublicMediaException();
        }
    }

    public function publishedVariant(
        string $mediaAssetPublicId,
        int $width
    ): ?BlogPublicStoredMediaVariant {
        try {
            $mediaAssetPublicId = BlogInput::publicId($mediaAssetPublicId);
            if ($width < 1 || $width > 2_560) {
                return null;
            }
            $statement = $this->prepare(
                'SELECT v.storage_key, v.width, v.height, v.bytes, v.sha256 '
                . 'FROM ' . $this->mediaVariants . ' v JOIN '
                . $this->mediaAssets . ' a ON a.id = v.asset_id '
                . 'WHERE a.public_id = :asset_public_id '
                . 'AND v.width = :width AND v.mime = :mime AND EXISTS ('
                . 'SELECT 1 FROM ' . $this->contentMedia . ' cm JOIN '
                . $this->contentDocuments . ' d ON d.id = cm.document_id '
                . 'JOIN ' . $this->localizations
                . ' l ON l.id = d.localization_id '
                . "WHERE l.status = 'published' "
                . 'AND cm.media_asset_public_id = a.public_id'
                . ') LIMIT 2'
            );
            $statement->bindValue('asset_public_id', $mediaAssetPublicId);
            $statement->bindValue('width', $width, PDO::PARAM_INT);
            $statement->bindValue('mime', 'image/avif');
            if (!$statement->execute()) {
                throw new BlogPublicMediaException();
            }
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows) > 1) {
                throw new BlogPublicMediaException();
            }
            if ($rows === []) {
                return null;
            }
            $row = $rows[0];

            return new BlogPublicStoredMediaVariant(
                new MediaStoredVariant(
                    $this->requiredString($row, 'storage_key'),
                    $this->positiveInteger($row['width'] ?? null),
                    $this->positiveInteger($row['height'] ?? null),
                    $this->positiveInteger($row['bytes'] ?? null),
                    $this->requiredString($row, 'sha256')
                )
            );
        } catch (BlogPublicMediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPublicMediaException();
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['persistence' => '[redacted]'];
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new BlogPublicMediaException();
        }

        return $statement;
    }

    /** @param array<string, string> $parameters */
    private function execute(PDOStatement $statement, array $parameters): void
    {
        if (!$statement->execute($parameters)) {
            throw new BlogPublicMediaException();
        }
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new BlogPublicMediaException();
        }

        return $value;
    }

    private function positiveInteger(mixed $value): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (
            is_string($value)
            && preg_match('/\A[1-9][0-9]*\z/', $value) === 1
            && (string) (int) $value === $value
        ) {
            $integer = (int) $value;
        } else {
            throw new BlogPublicMediaException();
        }
        if ($integer < 1) {
            throw new BlogPublicMediaException();
        }

        return $integer;
    }
}
