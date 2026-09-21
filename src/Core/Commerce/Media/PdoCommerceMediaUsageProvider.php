<?php

declare(strict_types=1);

namespace App\Core\Commerce\Media;

use App\Core\Commerce\Persistence\CommerceTableNames;
use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\Usage\MediaUsageProviderInterface;
use PDO;
use PDOStatement;
use Throwable;

/** Read-only Commerce contribution to WebAdmin's shared Media usage guard. */
final class PdoCommerceMediaUsageProvider implements MediaUsageProviderInterface
{
    private readonly string $productMedia;

    public function __construct(
        private readonly PDO $pdo,
        CommerceTableNames $tables
    ) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (
                !is_string($driver)
                || !in_array($driver, ['mysql', 'sqlite'], true)
                || $tables->driver() !== $driver
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
            $this->productMedia = $tables->table('product_media');
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

        $parameters = [];
        foreach ($mediaPublicIds as $index => $publicId) {
            if (
                !is_string($publicId)
                || preg_match(
                    '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                    $publicId
                ) !== 1
            ) {
                throw new MediaException('webadmin.media.usage_query_invalid');
            }
            $parameters[] = ':media_' . $index;
        }

        try {
            $statement = $this->pdo->prepare(
                'SELECT DISTINCT media_asset_public_id FROM '
                . $this->productMedia . ' WHERE media_asset_public_id IN ('
                . implode(', ', $parameters) . ')'
            );
            if (!$statement instanceof PDOStatement) {
                throw new MediaException('webadmin.media.usage_probe_failed');
            }
            foreach ($mediaPublicIds as $index => $publicId) {
                $statement->bindValue(
                    'media_' . $index,
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
