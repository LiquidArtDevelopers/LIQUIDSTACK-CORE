<?php

declare(strict_types=1);

namespace App\Core\Commerce\Media;

use App\Core\Commerce\CommerceInput;
use App\Core\Commerce\Persistence\CommerceTableNames;
use App\Core\Commerce\ProductEditorialStatus;
use App\Core\WebAdmin\Media\MediaStoredVariant;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use PDO;
use PDOStatement;
use Throwable;

/** Read-only bridge from active Commerce references to WebAdmin media. */
final class PdoCommercePublicMediaRepository
{
    private readonly string $products;
    private readonly string $productLocalizations;
    private readonly string $productMedia;
    private readonly string $mediaAssets;
    private readonly string $mediaVariants;

    public function __construct(
        private readonly PDO $pdo,
        CommerceTableNames $commerceTables,
        WebAdminTableNames $webAdminTables
    ) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (
                !is_string($driver)
                || !in_array($driver, ['mysql', 'sqlite'], true)
                || $driver !== $commerceTables->driver()
                || $driver !== $webAdminTables->driver()
                || $pdo->getAttribute(PDO::ATTR_ERRMODE)
                    !== PDO::ERRMODE_EXCEPTION
                || ($driver === 'mysql' && !in_array(
                    $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
                    [false, 0, '0'],
                    true
                ))
            ) {
                throw new CommercePublicMediaException();
            }
            if ($driver === 'sqlite') {
                $foreignKeys = $pdo->query('PRAGMA foreign_keys');
                if (
                    !$foreignKeys instanceof PDOStatement
                    || !in_array($foreignKeys->fetchColumn(), [1, '1'], true)
                ) {
                    throw new CommercePublicMediaException();
                }
            }
            $this->products = $commerceTables->table('products');
            $this->productLocalizations = $commerceTables->table(
                'product_localizations'
            );
            $this->productMedia = $commerceTables->table('product_media');
            $this->mediaAssets = $webAdminTables->table('media_assets');
            $this->mediaVariants = $webAdminTables->table('media_variants');
        } catch (CommercePublicMediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new CommercePublicMediaException();
        }
    }

    public function publishedVariant(
        string $mediaAssetPublicId,
        int $width
    ): ?CommercePublicStoredMediaVariant {
        try {
            $mediaAssetPublicId = CommerceInput::uuid($mediaAssetPublicId);
            if ($width < 1 || $width > 2_560) {
                return null;
            }
            $statement = $this->pdo->prepare(
                'SELECT DISTINCT v.storage_key, v.width, v.height, v.bytes, '
                    . 'v.sha256 FROM ' . $this->mediaVariants . ' v INNER JOIN '
                    . $this->mediaAssets . ' a ON a.id = v.asset_id INNER JOIN '
                    . $this->productMedia
                    . ' pm ON pm.media_asset_public_id = a.public_id INNER JOIN '
                    . $this->products . ' p ON p.id = pm.product_id '
                    . 'WHERE a.public_id = :asset_public_id '
                    . 'AND v.mime = :mime '
                    . 'AND p.editorial_status = :active AND EXISTS ('
                    . 'SELECT 1 FROM ' . $this->productLocalizations
                    . ' l WHERE l.product_id = p.id '
                    . 'AND l.public_path IS NOT NULL) '
                    . 'ORDER BY v.width ASC LIMIT 10'
            );
            if (!$statement instanceof PDOStatement) {
                throw new CommercePublicMediaException();
            }
            $statement->bindValue(
                ':asset_public_id',
                $mediaAssetPublicId,
                PDO::PARAM_STR
            );
            $statement->bindValue(':mime', 'image/avif', PDO::PARAM_STR);
            $statement->bindValue(
                ':active',
                ProductEditorialStatus::ACTIVE->value,
                PDO::PARAM_STR
            );
            if (!$statement->execute()) {
                throw new CommercePublicMediaException();
            }
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows) > 9) {
                throw new CommercePublicMediaException();
            }
            if ($rows === []) {
                return null;
            }
            $row = null;
            foreach ($rows as $candidate) {
                if (!is_array($candidate)) {
                    throw new CommercePublicMediaException();
                }
                $candidateWidth = $this->positiveInt(
                    $candidate['width'] ?? null
                );
                if ($candidateWidth === $width) {
                    $row = $candidate;
                    break;
                }
                if ($row === null || $this->positiveInt(
                    $row['width'] ?? null
                ) < $width) {
                    $row = $candidate;
                }
                if ($candidateWidth > $width) {
                    $row = $candidate;
                    break;
                }
            }
            if (!is_array($row)) {
                throw new CommercePublicMediaException();
            }

            return new CommercePublicStoredMediaVariant(
                new MediaStoredVariant(
                    $this->requiredString($row, 'storage_key'),
                    $this->positiveInt($row['width'] ?? null),
                    $this->positiveInt($row['height'] ?? null),
                    $this->positiveInt($row['bytes'] ?? null),
                    $this->sha256($row['sha256'] ?? null)
                )
            );
        } catch (CommercePublicMediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new CommercePublicMediaException();
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['persistence' => '[redacted]'];
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new CommercePublicMediaException();
        }

        return $value;
    }

    private function positiveInt(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (
            is_string($value)
            && preg_match('/\A[1-9][0-9]*\z/', $value) === 1
            && (string) (int) $value === $value
        ) {
            return (int) $value;
        }

        throw new CommercePublicMediaException();
    }

    private function sha256(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[0-9a-f]{64}\z/', $value) !== 1) {
            throw new CommercePublicMediaException();
        }

        return $value;
    }
}
