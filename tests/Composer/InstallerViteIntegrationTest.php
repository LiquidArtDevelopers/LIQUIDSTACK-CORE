<?php

declare(strict_types=1);

use App\Core\Composer\Installer;
use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Script\Event;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2) . '/src/Core/Composer/Installer.php';

final class InstallerViteIntegrationTest extends TestCase
{
    private const PLUGIN_IMPORT = 'import { createUpdateLanguagesPlugin } from "./tools/liquidstack/vite/update-languages-plugin.mjs";';
    private const PLUGIN_CALL = 'createUpdateLanguagesPlugin(env)';

    private const RESOURCE_TARGET_ENV = [
        'STACK_CORE_RESOURCES_TARGET',
        'STACK_LIQUID_CORE_RESOURCES_TARGET',
        'STACK_CORE_RESOURCES_IMG_TARGET',
        'STACK_LIQUID_CORE_RESOURCES_IMG_TARGET',
        'STACK_CORE_RESOURCES_VIDEO_TARGET',
        'STACK_LIQUID_CORE_RESOURCES_VIDEO_TARGET',
    ];

    private string $projectRoot;
    private Filesystem $filesystem;

    /** @var list<string> */
    private array $externalRoots = [];

    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        $this->filesystem  = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-vite-integration-'
            . bin2hex(random_bytes(8));

        $this->filesystem->mkdir(
            $this->projectRoot . DIRECTORY_SEPARATOR . 'vendor'
        );

        foreach (self::RESOURCE_TARGET_ENV as $environmentName) {
            $this->previousEnvironment[$environmentName] = getenv($environmentName);
            putenv($environmentName);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $environmentName => $value) {
            if ($value === false) {
                putenv($environmentName);
                continue;
            }

            putenv($environmentName . '=' . $value);
        }

        $this->filesystem->remove($this->projectRoot);

        foreach ($this->externalRoots as $externalRoot) {
            $this->filesystem->remove($externalRoot);
        }
    }

    public function testSharedPluginResolvesUpdatesAndHonorsSkipFlag(): void
    {
        $node = new Process(['node', '--version']);

        try {
            $node->run();
        } catch (\Throwable $exception) {
            self::markTestSkipped(
                'Node is required to execute the shared Vite plugin: '
                . $exception->getMessage()
            );
        }

        if (!$node->isSuccessful()) {
            self::markTestSkipped(
                'Node is required to execute the shared Vite plugin.'
            );
        }

        $pluginPath = dirname(__DIR__, 2)
            . '/stubs/tools/liquidstack/vite/update-languages-plugin.mjs';
        $pluginSource = (string) file_get_contents($pluginPath);
        self::assertStringContainsString(
            'App/tools/update-languages.php',
            $pluginSource
        );
        self::assertStringContainsString('stdio: "inherit"', $pluginSource);

        $script = <<<'JS'
import { pathToFileURL } from "node:url";

const {
  createUpdateLanguagesPlugin,
  resolveLanguageUpdate,
} = await import(pathToFileURL(process.argv[1]).href);

const calls = [];
const logs = [];
const plugin = createUpdateLanguagesPlugin(
  { LANG_SKIP_UPDATE: "false" },
  {
    runUpdate: (slug) => calls.push(slug),
    logger: { log: (message) => logs.push(message) },
  },
);

plugin.handleHotUpdate({
  file: String.raw`C:\stack\App\views\_showroom.php`,
});
plugin.handleHotUpdate({ file: "/stack/App/views/quienes-somos.php" });
plugin.handleHotUpdate({ file: "App/includes/navigation.php" });
plugin.handleHotUpdate({ file: "/stack/src/ignored.js" });

const skippedCalls = [];
const skippedLogs = [];
const skippedPlugin = createUpdateLanguagesPlugin(
  { LANG_SKIP_UPDATE: "TRUE" },
  {
    runUpdate: (slug) => skippedCalls.push(slug),
    logger: { log: (message) => skippedLogs.push(message) },
  },
);
skippedPlugin.handleHotUpdate({ file: "/stack/App/views/contact.php" });

process.stdout.write(JSON.stringify({
  calls,
  logs,
  name: plugin.name,
  resolutions: [
    resolveLanguageUpdate(String.raw`C:\stack\App\views\_templates.php`),
    resolveLanguageUpdate("App/views/home.php"),
    resolveLanguageUpdate("/stack/App/includes/footer.php"),
    resolveLanguageUpdate("/stack/App/controllers/home.php"),
  ],
  skippedCalls,
  skippedLogs,
}));
JS;

        $process = new Process([
            'node',
            '--input-type=module',
            '--eval',
            $script,
            $pluginPath,
        ]);
        $process->setTimeout(20);
        $process->run();

        self::assertTrue(
            $process->isSuccessful(),
            trim($process->getErrorOutput() . PHP_EOL . $process->getOutput())
        );

        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('update-languages', $result['name']);
        self::assertSame(
            ['_showroom.php', 'quienes-somos.php', 'global'],
            $result['calls']
        );
        self::assertSame([], $result['logs']);
        self::assertSame(
            '_templates.php',
            $result['resolutions'][0]['slug']
        );
        self::assertSame('home.php', $result['resolutions'][1]['slug']);
        self::assertSame('global', $result['resolutions'][2]['slug']);
        self::assertNull($result['resolutions'][3]);
        self::assertSame([], $result['skippedCalls']);
        self::assertCount(1, $result['skippedLogs']);
        self::assertStringContainsString(
            'LANG_SKIP_UPDATE',
            $result['skippedLogs'][0]
        );
    }

    public function testComposerHooksCopyPluginMigrateLegacyConfigAndRemainIdempotent(): void
    {
        $configPath = $this->projectRoot . '/vite.config.js';
        $this->writeFile($configPath, $this->legacyViteConfig());

        [$installEvent, $installIo] = $this->createEvent('post-install-cmd');
        Installer::postInstall($installEvent);

        $coreRoot = dirname(__DIR__, 2);
        $pluginPath = $this->projectRoot
            . '/tools/liquidstack/vite/update-languages-plugin.mjs';

        self::assertFileEquals(
            $coreRoot
                . '/stubs/tools/liquidstack/vite/update-languages-plugin.mjs',
            $pluginPath
        );

        $migrated = file_get_contents($configPath);
        self::assertIsString($migrated);
        self::assertSame(
            1,
            substr_count($migrated, self::PLUGIN_IMPORT)
        );
        self::assertSame(
            1,
            substr_count($migrated, self::PLUGIN_CALL)
        );
        self::assertStringNotContainsString(
            'const createUpdateLanguagesPlugin = (env) => {',
            $migrated
        );
        self::assertStringNotContainsString(
            'import { execSync } from "node:child_process";',
            $migrated
        );
        self::assertStringContainsString('auditPlugin()', $migrated);
        self::assertStringContainsString(
            'origin: "http://localhost:4711"',
            $migrated
        );
        self::assertStringContainsString('publicDir: false', $migrated);
        self::assertStringContainsString(
            'Migrated the legacy language watcher',
            $installIo->getOutput()
        );

        [$updateEvent, $updateIo] = $this->createEvent('post-update-cmd');
        Installer::postUpdate($updateEvent);

        self::assertSame($migrated, file_get_contents($configPath));
        self::assertStringContainsString(
            'Vite language plugin already integrated',
            $updateIo->getOutput()
        );
    }

    public function testLegacyMigrationKeepsChildProcessImportUsedByProject(): void
    {
        $configPath = $this->projectRoot . '/vite.config.js';
        $legacyConfig = str_replace(
            'const env = { ...process.env, ...loadEnv(mode, process.cwd(), "LANG_") };',
            'const env = { ...process.env, ...loadEnv(mode, process.cwd(), "LANG_") };'
                . "\n"
                . '  const runAudit = () => execSync ("node audit.mjs");',
            $this->legacyViteConfig()
        );
        $this->writeFile($configPath, $legacyConfig);

        [$event] = $this->createEvent('post-update-cmd');
        Installer::postUpdate($event);

        $migrated = file_get_contents($configPath);
        self::assertIsString($migrated);
        self::assertStringContainsString(
            'import { execSync } from "node:child_process";',
            $migrated
        );
        self::assertStringContainsString(
            'const runAudit = () => execSync ("node audit.mjs");',
            $migrated
        );
        self::assertStringNotContainsString(
            'const createUpdateLanguagesPlugin = (env) => {',
            $migrated
        );
        self::assertSame(1, substr_count($migrated, self::PLUGIN_IMPORT));
        self::assertSame(1, substr_count($migrated, self::PLUGIN_CALL));
    }

    public function testComposerUpdateInstallsPluginButPreservesCustomViteConfig(): void
    {
        $configPath = $this->projectRoot . '/vite.config.js';
        $customConfig = <<<'JS'
import { defineConfig } from "vite";
import auditPlugin from "./build/audit-plugin.mjs";

export default defineConfig({
  plugins: [auditPlugin()],
  server: {
    origin: "http://localhost:9876",
  },
});
JS;
        $this->writeFile($configPath, $customConfig);

        [$event, $io] = $this->createEvent('post-update-cmd');
        Installer::postUpdate($event);

        self::assertSame($customConfig, file_get_contents($configPath));
        self::assertFileEquals(
            dirname(__DIR__, 2)
                . '/stubs/tools/liquidstack/vite/update-languages-plugin.mjs',
            $this->projectRoot
                . '/tools/liquidstack/vite/update-languages-plugin.mjs'
        );
        self::assertStringContainsString(
            'Preserved custom',
            $io->getOutput()
        );
        self::assertStringContainsString(
            self::PLUGIN_IMPORT,
            $io->getOutput()
        );
        self::assertStringContainsString(
            self::PLUGIN_CALL . ',',
            $io->getOutput()
        );
        self::assertStringContainsString(
            'inside `plugins`',
            $io->getOutput()
        );
    }

    public function testComposerUpdatePreservesOrphanedImportAndReportsBothManualSteps(): void
    {
        $configPath = $this->projectRoot . '/vite.config.js';
        $orphanedImport = self::PLUGIN_IMPORT . <<<'JS'

import { defineConfig, loadEnv } from "vite";

export default defineConfig(({ mode }) => {
  const env = { ...process.env, ...loadEnv(mode, process.cwd(), "LANG_") };

  return {
    plugins: [],
    server: {
      origin: "http://localhost:7654",
    },
  };
});
JS;
        $this->writeFile($configPath, $orphanedImport);

        [$event, $io] = $this->createEvent('post-update-cmd');
        Installer::postUpdate($event);

        self::assertSame($orphanedImport, file_get_contents($configPath));
        self::assertStringContainsString(
            'Preserved custom',
            $io->getOutput()
        );
        self::assertStringNotContainsString(
            'Vite language plugin already integrated',
            $io->getOutput()
        );
        self::assertStringContainsString(
            self::PLUGIN_IMPORT,
            $io->getOutput()
        );
        self::assertStringContainsString(
            self::PLUGIN_CALL . ',',
            $io->getOutput()
        );
    }

    public function testComposerUpdatePreservesAmbiguousDuplicatePluginCalls(): void
    {
        $configPath = $this->projectRoot . '/vite.config.js';
        $ambiguousConfig = self::PLUGIN_IMPORT . <<<'JS'

import { defineConfig, loadEnv } from "vite";

export default defineConfig(({ mode }) => {
  const env = { ...process.env, ...loadEnv(mode, process.cwd(), "LANG_") };

  return {
    plugins: [
      createUpdateLanguagesPlugin(env),
      createUpdateLanguagesPlugin(env),
    ],
    server: {
      origin: "http://localhost:6543",
    },
  };
});
JS;
        $this->writeFile($configPath, $ambiguousConfig);

        [$event, $io] = $this->createEvent('post-update-cmd');
        Installer::postUpdate($event);

        self::assertSame($ambiguousConfig, file_get_contents($configPath));
        self::assertStringContainsString(
            'Preserved custom',
            $io->getOutput()
        );
        self::assertStringContainsString(
            self::PLUGIN_IMPORT,
            $io->getOutput()
        );
        self::assertStringContainsString(
            self::PLUGIN_CALL . ',',
            $io->getOutput()
        );
    }

    public function testComposerUpdatePreservesLinkedViteConfig(): void
    {
        $externalRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-vite-config-external-'
            . bin2hex(random_bytes(8));
        $externalConfigPath = $externalRoot . '/vite.config.js';
        $linkedConfigPath = $this->projectRoot . '/vite.config.js';
        $externalConfig = $this->legacyViteConfig();

        $this->externalRoots[] = $externalRoot;
        $this->writeFile($externalConfigPath, $externalConfig);

        if (!@symlink($externalConfigPath, $linkedConfigPath)) {
            self::markTestSkipped(
                'This environment cannot create a Vite config file symlink.'
            );
        }

        [$event, $io] = $this->createEvent('post-update-cmd');
        Installer::postUpdate($event);

        self::assertTrue(is_link($linkedConfigPath));
        self::assertSame(
            $externalConfig,
            file_get_contents($externalConfigPath)
        );
        self::assertStringContainsString(
            'Preserved linked Vite config',
            $io->getOutput()
        );
    }

    /**
     * @return array{0: Event, 1: BufferIO}
     */
    private function createEvent(string $name): array
    {
        $config = new Config(false, $this->projectRoot);
        $config->merge([
            'config' => [
                'vendor-dir' => $this->projectRoot
                    . DIRECTORY_SEPARATOR
                    . 'vendor',
            ],
        ]);

        $composer = new Composer();
        $composer->setConfig($config);
        $io = new BufferIO();

        return [new Event($name, $composer, $io), $io];
    }

    private function legacyViteConfig(): string
    {
        return <<<'JS'
import { defineConfig, loadEnv } from "vite";
import { execSync } from "node:child_process";
import auditPlugin from "./build/audit-plugin.mjs";

const createUpdateLanguagesPlugin = (env) => {
  const shouldSkipUpdate = env.LANG_SKIP_UPDATE === "1";

  return {
    name: "update-languages",
    handleHotUpdate({ file }) {
      if (!shouldSkipUpdate && file.endsWith(".php")) {
        execSync("php tools/update-languages.php home");
      }
    },
  };
};

export default defineConfig(({ mode }) => {
  const env = { ...process.env, ...loadEnv(mode, process.cwd(), "LANG_") };

  return {
    plugins: [
      createUpdateLanguagesPlugin(env),
      auditPlugin(),
    ],
    publicDir: false,
    server: {
      origin: "http://localhost:4711",
    },
  };
});
JS;
    }

    private function writeFile(string $path, string $contents): void
    {
        $this->filesystem->mkdir(dirname($path));
        $this->filesystem->dumpFile($path, $contents);
    }
}
