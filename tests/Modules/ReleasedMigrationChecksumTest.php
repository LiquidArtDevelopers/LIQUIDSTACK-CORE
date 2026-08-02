<?php

declare(strict_types=1);

use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use PHPUnit\Framework\TestCase;

/** Prevents a release from silently rewriting an already-applied migration. */
final class ReleasedMigrationChecksumTest extends TestCase
{
    public function testEveryFrozenMigrationKeepsItsPublishedChecksum(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 2)
                    . '/manifests/released-migration-checksums.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(1, $manifest['schema'] ?? null);
        self::assertIsString($manifest['frozen_through'] ?? null);
        self::assertNotSame('', $manifest['frozen_through']);
        self::assertMatchesRegularExpression(
            '/\Av\d+\.\d+\.\d+\z/',
            $manifest['frozen_through']
        );

        $actual = [
            'webadmin' => $this->checksums(
                WebAdminMigrationProvider::migrations()
            ),
            'blog' => $this->checksums(BlogMigrationProvider::migrations()),
        ];
        $frozen = $manifest['modules'] ?? null;
        self::assertIsArray($frozen);
        self::assertSame(array_keys($actual), array_keys($frozen));

        foreach ($frozen as $module => $migrations) {
            self::assertIsString($module);
            self::assertIsArray($migrations);
            self::assertNotSame([], $migrations);
            self::assertSame(
                array_keys($migrations),
                array_slice(
                    array_keys($actual[$module]),
                    0,
                    count($migrations)
                ),
                "Las migraciones nuevas de {$module} deben añadirse después "
                    . 'del prefijo ya publicado.'
            );
            foreach ($migrations as $id => $checksum) {
                self::assertMatchesRegularExpression(
                    '/\A[a-f0-9]{64}\z/',
                    $checksum
                );
                self::assertArrayHasKey(
                    $id,
                    $actual[$module] ?? [],
                    "La migración publicada {$module}:{$id} ha desaparecido."
                );
                self::assertSame(
                    $checksum,
                    $actual[$module][$id],
                    "La migración publicada {$module}:{$id} fue modificada. "
                        . 'Añade una migración nueva en lugar de reescribirla.'
                );
            }
        }
    }

    /**
     * @param iterable<MigrationDefinition> $migrations
     * @return array<string, string>
     */
    private function checksums(iterable $migrations): array
    {
        $checksums = [];
        foreach ($migrations as $migration) {
            self::assertArrayNotHasKey($migration->id(), $checksums);
            $checksums[$migration->id()] = $migration->checksum();
        }

        return $checksums;
    }
}
