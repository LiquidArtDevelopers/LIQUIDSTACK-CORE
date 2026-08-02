<?php

declare(strict_types=1);

use App\Core\Composer\ManagedFileRegistry;
use App\Core\Modules\ModuleCatalog;
use App\Core\Modules\ModulePublishedSourceFinder;
use PHPUnit\Framework\TestCase;

final class ManagedFileManifestTest extends TestCase
{
    public function testHistoryContainsEveryCurrentManagedSource(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = json_decode(
            (string) file_get_contents(
                $root . '/manifests/managed-file-history.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(1, $manifest['schema'] ?? null);
        self::assertSame(
            'sha256-eol-lf-v1',
            $manifest['algorithm'] ?? null
        );
        self::assertIsArray($manifest['files'] ?? null);

        foreach ($this->currentFiles($root) as $sourceId => $path) {
            self::assertArrayHasKey(
                $sourceId,
                $manifest['files'],
                "Falta regenerar el historial para {$sourceId}"
            );

            $current = ManagedFileRegistry::fingerprintFile($path);
            $known = $manifest['files'][$sourceId];

            self::assertNotSame(
                [],
                array_intersect($current, $known),
                "La huella actual de {$sourceId} no está registrada"
            );
        }
    }

    public function testProjectOwnedFilesAreOutsideTheRegistry(): void
    {
        foreach ([
            'stubs/App/config/routes/get.php',
            'stubs/App/config/routes/post.php',
            'src/scss/_config.scss',
            'src/scss/_global.scss',
            'src/scss/home.scss',
            'vite.config.js',
        ] as $path) {
            self::assertSame(
                ManagedFileRegistry::POLICY_IGNORE,
                ManagedFileRegistry::policyForSource($path),
                "{$path} debe pertenecer al proyecto consumidor"
            );
        }
    }

    public function testHistoryBuilderIncludesUntrackedReleaseFiles(): void
    {
        $builder = (string) file_get_contents(
            dirname(__DIR__, 2)
                . '/tools/build-managed-file-history.php'
        );

        self::assertStringContainsString(
            "'--others'",
            $builder
        );
        self::assertStringContainsString(
            "'--exclude-standard'",
            $builder
        );
        self::assertStringContainsString(
            'managed-file-legacy-baselines.json',
            $builder
        );
        self::assertStringContainsString(
            'ModulePublishedSourceFinder::currentManagedFiles',
            $builder
        );
        self::assertStringContainsString(
            'addTaggedModuleSources',
            $builder
        );
    }

    public function testVerifiedBaseLegacyFilesRemainRecognized(): void
    {
        $root = dirname(__DIR__, 2);
        $history = json_decode(
            (string) file_get_contents(
                $root . '/manifests/managed-file-history.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $baselines = json_decode(
            (string) file_get_contents(
                $root . '/manifests/managed-file-legacy-baselines.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ($baselines['files'] as $sourceId => $fingerprints) {
            self::assertSame(
                [],
                array_values(array_diff(
                    $fingerprints,
                    $history['files'][$sourceId] ?? []
                )),
                "Faltan baselines legacy verificados para {$sourceId}"
            );
        }
    }

    public function testSegmentedShowroomFilesShareOneAtomicGroup(): void
    {
        foreach ([
            'stubs/App/views/_showroom.php',
            'stubs/App/views/_templates.php',
            'stubs/App/views/showroom/_heroes.php',
            'src/js/templates.js',
            'src/js/showroom/heroes.js',
            'src/js/showroom/catalog-routing.mjs',
            'src/scss/templates.scss',
            'src/scss/showroom/heroes.scss',
        ] as $sourceId) {
            self::assertSame(
                'catalog:showroom',
                ManagedFileRegistry::groupForSource($sourceId),
                "{$sourceId} debe actualizarse con el catálogo completo"
            );
        }

        foreach ([
            'resources/js/_languagePreference.mjs',
            'resources/js/_traducciones.js',
        ] as $sourceId) {
            self::assertSame(
                'runtime:translations',
                ManagedFileRegistry::groupForSource($sourceId),
                "{$sourceId} debe actualizarse con el runtime de idiomas"
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function currentFiles(string $root): array
    {
        $files = [];
        $roots = [
            'resources/img',
            'resources/js',
            'resources/scss',
            'resources/video',
            'src/js/showroom',
            'src/scss/showroom',
            'stubs/App/config/languages',
            'stubs/App/controllers',
            'stubs/App/templates',
            'stubs/App/tools',
            'stubs/App/views',
        ];

        foreach ($roots as $relativeRoot) {
            $directory = $root . '/' . $relativeRoot;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS
                )
            );

            foreach ($iterator as $item) {
                if (!$item->isFile() || $item->isLink()) {
                    continue;
                }

                $sourceId = str_replace(
                    '\\',
                    '/',
                    substr(
                        $item->getPathname(),
                        strlen($root) + 1
                    )
                );

                if (
                    ManagedFileRegistry::policyForSource($sourceId)
                        === ManagedFileRegistry::POLICY_MANAGED
                ) {
                    $files[$sourceId] = $item->getPathname();
                }
            }
        }

        foreach ([
            'src/js/templates.js',
            'src/scss/templates.scss',
            'stubs/App/app/updateLanguage.php',
            'stubs/App/app/url.php',
            'stubs/App/config/helpers.php',
            'stubs/public/index.php',
            'stubs/tools/liquidstack/vite/update-languages-plugin.mjs',
        ] as $sourceId) {
            $path = $root . '/' . $sourceId;

            if (
                is_file($path)
                && ManagedFileRegistry::policyForSource($sourceId)
                    === ManagedFileRegistry::POLICY_MANAGED
            ) {
                $files[$sourceId] = $path;
            }
        }

        foreach (
            ModulePublishedSourceFinder::currentManagedFiles(
                ModuleCatalog::fromCoreRoot($root)
            ) as $sourceId => $path
        ) {
            $files[$sourceId] = $path;
        }

        ksort($files, SORT_STRING);

        return $files;
    }
}
