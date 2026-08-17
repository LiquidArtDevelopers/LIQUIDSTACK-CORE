<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Media;

use App\Core\Modules\Migrations\MigrationScope;
use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\Usage\MediaUsageProviderInterface;
use PDO;
use PDOStatement;
use Throwable;

/** Read-only Blog contribution to the cross-module media usage registry. */
final class PdoBlogMediaUsageProvider implements MediaUsageProviderInterface
{
    private readonly string $contentMedia;
    private readonly string $revisionMedia;

    public function __construct(
        private readonly PDO $pdo,
        MigrationScope $blogScope
    ) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (
                !is_string($driver)
                || !in_array($driver, ['mysql', 'sqlite'], true)
                || $blogScope->moduleId() !== 'blog'
                || $pdo->getAttribute(PDO::ATTR_ERRMODE)
                    !== PDO::ERRMODE_EXCEPTION
                || ($driver === 'mysql' && !in_array(
                    $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
                    [false, 0, '0'],
                    true
                ))
            ) {
                throw new MediaException(
                    'webadmin.media.usage_provider_contract_invalid'
                );
            }
            if ($driver === 'sqlite') {
                $foreignKeys = $pdo->query('PRAGMA foreign_keys');
                if (
                    !$foreignKeys instanceof PDOStatement
                    || !in_array($foreignKeys->fetchColumn(), [1, '1'], true)
                ) {
                    throw new MediaException(
                        'webadmin.media.usage_provider_contract_invalid'
                    );
                }
            }
            $this->contentMedia = $blogScope->quotedTable(
                'content_media',
                $driver
            );
            $this->revisionMedia = $blogScope->quotedTable(
                'revision_media',
                $driver
            );
        } catch (MediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MediaException(
                'webadmin.media.usage_provider_contract_invalid'
            );
        }
    }

    public function usedPublicIds(array $mediaPublicIds): array
    {
        if (!array_is_list($mediaPublicIds) || count($mediaPublicIds) > 200) {
            throw new MediaException('webadmin.media.usage_query_invalid');
        }
        if ($mediaPublicIds === []) {
            return [];
        }

        $content = [];
        $revisions = [];
        foreach ($mediaPublicIds as $index => $publicId) {
            if (!is_string($publicId)) {
                throw new MediaException('webadmin.media.usage_query_invalid');
            }
            $content[] = ':content_' . $index;
            $revisions[] = ':revision_' . $index;
        }

        try {
            // content_media covers the mutable draft. revision_media covers
            // every immutable revision, including the current publication.
            $statement = $this->pdo->prepare(
                'SELECT media_asset_public_id FROM ' . $this->contentMedia
                . ' WHERE media_asset_public_id IN ('
                . implode(', ', $content) . ') UNION SELECT '
                . 'media_asset_public_id FROM ' . $this->revisionMedia
                . ' WHERE media_asset_public_id IN ('
                . implode(', ', $revisions) . ')'
            );
            foreach ($mediaPublicIds as $index => $publicId) {
                $statement->bindValue(
                    'content_' . $index,
                    $publicId,
                    PDO::PARAM_STR
                );
                $statement->bindValue(
                    'revision_' . $index,
                    $publicId,
                    PDO::PARAM_STR
                );
            }
            $statement->execute();
            $values = $statement->fetchAll(PDO::FETCH_COLUMN);
            if (!is_array($values)) {
                throw new MediaException('webadmin.media.usage_probe_failed');
            }

            return array_values(array_map(
                static function (mixed $value): string {
                    if (!is_string($value)) {
                        throw new MediaException(
                            'webadmin.media.usage_provider_contract_invalid'
                        );
                    }
                    return $value;
                },
                $values
            ));
        } catch (MediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MediaException('webadmin.media.usage_probe_failed');
        }
    }
}
