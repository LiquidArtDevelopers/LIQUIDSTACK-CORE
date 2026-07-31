<?php

declare(strict_types=1);

use App\Core\Modules\ModuleCatalog;
use App\Core\Modules\ModuleSelection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ModuleSelectionTest extends TestCase
{
    private string $fixtureRoot;
    private Filesystem $filesystem;
    private ModuleCatalog $catalog;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-module-selection-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->fixtureRoot);
        $this->catalog = ModuleCatalog::fromCoreRoot(dirname(__DIR__, 2));
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->fixtureRoot);
    }

    /**
     * @param array<string, string> $requirements
     * @param list<string> $requested
     * @param list<string> $enabled
     */
    #[DataProvider('selectionProvider')]
    public function testOnlyDirectProductionRequirementsSelectModules(
        array $requirements,
        array $requested,
        array $enabled
    ): void {
        $this->writeComposer(['require' => $requirements]);

        $selection = ModuleSelection::fromComposerJson(
            $this->catalog,
            $this->fixtureRoot . '/composer.json'
        );

        self::assertSame($requested, $selection->requestedIds());
        self::assertSame($enabled, $selection->enabledIds());
    }

    public static function selectionProvider(): array
    {
        return [
            'core only' => [
                ['liquidstack/core' => '^1.8'],
                [],
                [],
            ],
            'webadmin' => [
                [
                    'liquidstack/core' => '^1.8',
                    'liquidstack/webadmin' => '*',
                ],
                ['webadmin'],
                ['webadmin'],
            ],
            'blog closes dependencies' => [
                [
                    'liquidstack/core' => '^1.8',
                    'liquidstack/blog' => '*',
                ],
                ['blog'],
                ['webadmin', 'blog'],
            ],
            'both without duplicates' => [
                [
                    'liquidstack/core' => '^1.8',
                    'liquidstack/blog' => '*',
                    'liquidstack/webadmin' => '*',
                ],
                ['blog', 'webadmin'],
                ['webadmin', 'blog'],
            ],
        ];
    }

    public function testRequireDevReplaceProvideAndLockNeverEnableModules(): void
    {
        $this->writeComposer([
            'require' => ['liquidstack/core' => '^1.8'],
            'require-dev' => ['liquidstack/blog' => '*'],
            'replace' => ['liquidstack/webadmin' => 'self.version'],
            'provide' => ['liquidstack/blog' => '*'],
        ]);
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/composer.lock',
            json_encode([
                'packages' => [[
                    'name' => 'liquidstack/core',
                    'replace' => [
                        'liquidstack/blog' => 'self.version',
                        'liquidstack/webadmin' => 'self.version',
                    ],
                ]],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );

        $selection = ModuleSelection::fromComposerJson(
            $this->catalog,
            $this->fixtureRoot . '/composer.json'
        );

        self::assertSame([], $selection->requestedIds());
        self::assertSame([], $selection->enabledIds());
    }

    public function testMissingComposerDefinitionMeansCoreOnly(): void
    {
        $selection = ModuleSelection::fromComposerJson(
            $this->catalog,
            $this->fixtureRoot . '/composer.json'
        );

        self::assertSame([], $selection->enabledIds());
    }

    /**
     * @param array<string, mixed> $composer
     */
    private function writeComposer(array $composer): void
    {
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/composer.json',
            json_encode(
                $composer,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
    }
}
