<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Media;

use App\Core\Blog\BlogInput;
use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use PDOStatement;
use Throwable;

/** Read-only cross-scope port; the schemas remain unrelated by foreign keys. */
final class PdoWebAdminMediaAvailabilityAdapter implements
    BlogMediaAvailabilityPortInterface
{
    private const SUPPORTED_DRIVERS = ['mysql', 'sqlite'];

    private readonly string $assets;
    private readonly string $variants;

    public function __construct(
        private readonly PDO $pdo,
        MigrationScope $webAdminScope
    ) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (
                !is_string($driver)
                || !in_array($driver, self::SUPPORTED_DRIVERS, true)
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
                throw new BlogStructuredContentException(
                    BlogStructuredContentException::MEDIA_UNAVAILABLE
                );
            }
            if ($driver === 'sqlite') {
                $foreignKeys = $pdo->query('PRAGMA foreign_keys');
                if (
                    !$foreignKeys instanceof PDOStatement
                    || !in_array($foreignKeys->fetchColumn(), [1, '1'], true)
                ) {
                    throw new BlogStructuredContentException(
                        BlogStructuredContentException::MEDIA_UNAVAILABLE
                    );
                }
            }

            $this->assets = $webAdminScope->quotedTable(
                'media_assets',
                $driver
            );
            $this->variants = $webAdminScope->quotedTable(
                'media_variants',
                $driver
            );
        } catch (BlogStructuredContentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::MEDIA_UNAVAILABLE
            );
        }
    }

    public function assertAvailable(
        PDO $transaction,
        array $mediaAssetPublicIds
    ): void {
        if (
            $transaction !== $this->pdo
            || !$this->pdo->inTransaction()
        ) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::MEDIA_UNAVAILABLE
            );
        }
        if (
            !array_is_list($mediaAssetPublicIds)
            || count($mediaAssetPublicIds) > BlogDocument::MAX_BLOCKS
        ) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::INVALID_INPUT
            );
        }

        $unique = [];
        try {
            foreach ($mediaAssetPublicIds as $publicId) {
                if (!is_string($publicId)) {
                    throw new BlogStructuredContentException(
                        BlogStructuredContentException::INVALID_INPUT
                    );
                }
                $unique[BlogInput::generatedPublicId($publicId)] = true;
            }
        } catch (BlogStructuredContentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::INVALID_INPUT
            );
        }

        $publicIds = array_keys($unique);
        if ($publicIds === []) {
            return;
        }

        $placeholders = [];
        foreach ($publicIds as $position => $_publicId) {
            $placeholders[] = ':asset_' . $position;
        }

        try {
            $statement = $this->pdo->prepare(
                'SELECT a.public_id FROM ' . $this->assets . ' a '
                . 'JOIN ' . $this->variants . ' v ON v.asset_id = a.id '
                . 'WHERE a.public_id IN (' . implode(', ', $placeholders) . ') '
                . 'GROUP BY a.id, a.public_id'
            );
            if (!$statement instanceof PDOStatement) {
                throw new \RuntimeException('prepare failed');
            }
            foreach ($publicIds as $position => $publicId) {
                if (!$statement->bindValue(
                    ':asset_' . $position,
                    $publicId,
                    PDO::PARAM_STR
                )) {
                    throw new \RuntimeException('bind failed');
                }
            }
            if (!$statement->execute()) {
                throw new \RuntimeException('execute failed');
            }
            $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
            if (!is_array($rows)) {
                throw new \RuntimeException('fetch failed');
            }
            $found = [];
            foreach ($rows as $publicId) {
                if (!is_string($publicId)) {
                    throw new \RuntimeException('invalid row');
                }
                $found[$publicId] = true;
            }
        } catch (Throwable) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::MEDIA_UNAVAILABLE
            );
        }

        if (
            count($found) !== count($publicIds)
            || array_diff_key($unique, $found) !== []
        ) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::MEDIA_NOT_FOUND
            );
        }
    }
}
