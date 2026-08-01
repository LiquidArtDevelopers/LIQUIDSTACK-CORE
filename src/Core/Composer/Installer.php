<?php

namespace App\Core\Composer;

use App\Core\Modules\ModuleCatalog;
use App\Core\Modules\ModuleSelection;
use App\Core\Support\Paths;
use Composer\IO\IOInterface;
use Composer\Script\Event;
use FilesystemIterator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class Installer
{
    private const AGENT_SKILLS_MANIFEST = '.liquidstack-core-skills.json';
    private const SCSS_CONFIG_CONTRACT_PATH = 'manifests/scss-config-contract-v2.json';
    private const SCSS_CONFIG_TARGET_PATH = 'src/scss/_config.scss';
    private const PHP_DEV_ROUTER_PATH = 'App/tools/php-dev-router.php';
    private const VITE_LANGUAGE_PLUGIN_PATH = 'tools/liquidstack/vite/update-languages-plugin.mjs';
    private const VITE_LANGUAGE_PLUGIN_IMPORT = 'import { createUpdateLanguagesPlugin } from "./tools/liquidstack/vite/update-languages-plugin.mjs";';
    private const VITE_LANGUAGE_PLUGIN_CALL = 'createUpdateLanguagesPlugin(env)';

    public static function postInstall(Event $event): void
    {
        self::syncManagedProjectFiles($event);
        self::syncAgentGuidance($event);
        self::syncFrontendDependencies($event);
    }

    public static function postUpdate(Event $event): void
    {
        self::syncManagedProjectFiles($event);
        self::syncAgentGuidance($event);
        self::syncFrontendDependencies($event);
    }

    private static function syncManagedProjectFiles(Event $event): void
    {
        $scssContractReady = self::syncScssConfigContract($event);
        if (!$scssContractReady) {
            $event->getIO()->writeError(
                '<warning>Se omiten los recursos base cuyo contrato SCSS no está garantizado; los módulos internos se resolverán de forma independiente.</warning>'
            );
        }

        $synchronizer = self::createManagedFileSynchronizer($event);

        // The local router is runtime infrastructure, not an SCSS resource.
        // Queue it even when the visual-resource contract cannot be extended;
        // package.json must never point at a file withheld by an unrelated
        // config failure.
        self::queueDevelopmentRuntimeAssets($event, $synchronizer);

        if ($scssContractReady) {
            self::syncProjectAssets($event, $synchronizer);
            self::queueResources($event, $synchronizer);
        }
        self::queueInternalModules($event, $synchronizer);
        $synchronizer->apply();

        if (!$scssContractReady) {
            return;
        }

        $projectRoot = self::resolveProjectRoot($event);
        $packageRoot = dirname(__DIR__, 3);

        self::syncViteLanguagePluginConfig(
            new Filesystem(),
            $projectRoot,
            $packageRoot
                . '/stubs/'
                . self::VITE_LANGUAGE_PLUGIN_PATH,
            $event->getIO()
        );
    }

    public static function syncAgentGuidance(Event $event): void
    {
        $io = $event->getIO();

        try {
            $composer    = $event->getComposer();
            $vendorDir   = rtrim((string) $composer->getConfig()->get('vendor-dir'), DIRECTORY_SEPARATOR);
            $projectRoot = dirname($vendorDir);
            $packageRoot = dirname(__DIR__, 3);
            $sourceRoot  = $packageRoot . '/.codex';

            if (!is_dir($sourceRoot)) {
                $io->writeError(sprintf(
                    '<warning>Skipping agent guidance sync: source directory not found: %s</warning>',
                    $sourceRoot
                ));
                return;
            }

            $filesystem   = new Filesystem();
            $configSource = $sourceRoot . '/config.toml';
            $configTarget = $projectRoot . '/.codex/config.toml';

            if (!is_file($configSource)) {
                $io->writeError(sprintf(
                    '<warning>Skipping core agent config: source file not found: %s</warning>',
                    $configSource
                ));
            } else {
                try {
                    self::assertSafeAgentPath($projectRoot, $configTarget);

                    if (file_exists($configTarget)) {
                        $io->write(sprintf(
                            '<info>Preserved existing project agent config: %s</info>',
                            $configTarget
                        ));
                    } else {
                        $filesystem->mkdir(dirname($configTarget), 0775);
                        $filesystem->copy($configSource, $configTarget, false);
                        $io->write(sprintf('<info>Installed core agent config: %s</info>', $configTarget));
                    }
                } catch (\Throwable $exception) {
                    $io->writeError(sprintf(
                        '<error>Failed to install core agent config at %s: %s</error>',
                        $configTarget,
                        $exception->getMessage()
                    ));
                }
            }

            $skillsSource = $sourceRoot . '/skills';

            if (!is_dir($skillsSource)) {
                $io->writeError(sprintf(
                    '<warning>Skipping core agent skills: source directory not found: %s</warning>',
                    $skillsSource
                ));
                return;
            }

            $skillsTarget = $projectRoot . '/.codex/skills';
            $manifestPath = $skillsTarget . DIRECTORY_SEPARATOR . self::AGENT_SKILLS_MANIFEST;

            $io->write(sprintf('<info>Using canonical .codex/skills directory: %s</info>', $skillsTarget));

            try {
                self::assertSafeAgentPath($projectRoot, $skillsTarget);
                $filesystem->mkdir($skillsTarget, 0775);
                self::assertSafeAgentPath($projectRoot, $manifestPath);
            } catch (\Throwable $exception) {
                $io->writeError(sprintf(
                    '<error>Failed to prepare agent skills directory %s: %s</error>',
                    $skillsTarget,
                    $exception->getMessage()
                ));
                return;
            }

            $previouslyManaged = self::readManagedAgentSkills($manifestPath, $io) ?? [];
            $coreSkills        = self::discoverCoreAgentSkills($skillsSource, $io);

            if ($coreSkills === null) {
                return;
            }

            $allCoreSkillsSynced = true;

            foreach ($coreSkills as $skillName => $sourcePath) {
                $destinationPath = $skillsTarget . DIRECTORY_SEPARATOR . $skillName;

                try {
                    // Deletion is deliberately scoped to one CORE-owned skill.
                    // Never mirror with deletion enabled at the shared skills root.
                    self::assertSafeAgentTree($projectRoot, $destinationPath);
                    $filesystem->mirror($sourcePath, $destinationPath, null, [
                        'override' => true,
                        'delete'   => true,
                    ]);
                    $io->write(sprintf('<info>Synced core agent skill: %s</info>', $skillName));
                } catch (\Throwable $exception) {
                    $allCoreSkillsSynced = false;
                    $io->writeError(sprintf(
                        '<error>Failed to sync core agent skill %s to %s: %s</error>',
                        $skillName,
                        $destinationPath,
                        $exception->getMessage()
                    ));
                }
            }

            $currentSkillNames  = array_keys($coreSkills);
            $manifestSkillNames = $currentSkillNames;

            foreach (array_diff($previouslyManaged, $currentSkillNames) as $retiredSkillName) {
                if (!self::isSafeAgentSkillName($retiredSkillName)) {
                    $io->writeError(sprintf(
                        '<warning>Skipped unsafe retired core skill name from manifest: %s</warning>',
                        $retiredSkillName
                    ));
                    continue;
                }

                $retiredPath = $skillsTarget . DIRECTORY_SEPARATOR . $retiredSkillName;

                if (!file_exists($retiredPath) && !is_link($retiredPath)) {
                    continue;
                }

                try {
                    self::assertSafeAgentTree($projectRoot, $retiredPath);

                    if (!is_dir($retiredPath)) {
                        $manifestSkillNames[] = $retiredSkillName;
                        $io->writeError(sprintf(
                            '<warning>Preserved retired core skill path because it is not a regular directory: %s</warning>',
                            $retiredPath
                        ));
                        continue;
                    }

                    $filesystem->remove($retiredPath);
                    $io->write(sprintf('<info>Removed retired core agent skill: %s</info>', $retiredSkillName));
                } catch (\Throwable $exception) {
                    $manifestSkillNames[] = $retiredSkillName;
                    $io->writeError(sprintf(
                        '<error>Failed to remove retired core agent skill %s: %s</error>',
                        $retiredPath,
                        $exception->getMessage()
                    ));
                }
            }

            $manifestUpdated = false;

            try {
                $manifestSkillNames = array_values(array_unique($manifestSkillNames));
                sort($manifestSkillNames, SORT_STRING);

                $manifest = json_encode(
                    ['managed' => $manifestSkillNames],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
                $filesystem->dumpFile($manifestPath, $manifest . PHP_EOL);
                $io->write(sprintf('<info>Updated core agent skills manifest: %s</info>', $manifestPath));
                $manifestUpdated = true;
            } catch (\Throwable $exception) {
                $io->writeError(sprintf(
                    '<error>Failed to update core agent skills manifest %s: %s</error>',
                    $manifestPath,
                    $exception->getMessage()
                ));
            }

            if ($allCoreSkillsSynced && $manifestUpdated) {
                self::removeAlternateManagedAgentSkills($filesystem, $projectRoot, $io);
            }
        } catch (\Throwable $exception) {
            $io->writeError(sprintf(
                '<error>Agent guidance sync failed without interrupting Composer: %s</error>',
                $exception->getMessage()
            ));
        }
    }

    public static function syncFrontendDependencies(Event $event): void
    {
        $io          = $event->getIO();
        $composer    = $event->getComposer();
        $vendorDir   = rtrim($composer->getConfig()->get('vendor-dir'), DIRECTORY_SEPARATOR);
        $projectRoot = dirname($vendorDir);
        $packageRoot = dirname(__DIR__, 3);

        $coreManifestPath  = $packageRoot . '/package.core.json';
        $projectPackagePath = $projectRoot . '/package.json';

        if (!is_file($coreManifestPath)) {
            $io->writeError(sprintf('<warning>Skipping frontend dependency sync: missing manifest %s</warning>', $coreManifestPath));
            return;
        }

        if (!is_file($projectPackagePath)) {
            $io->write(sprintf('<info>Skipping frontend dependency sync: missing %s</info>', $projectPackagePath));
            return;
        }

        $coreManifest = self::decodeJsonFile($coreManifestPath);
        if ($coreManifest === null) {
            $io->writeError(sprintf('<error>Skipping frontend dependency sync: invalid JSON in %s</error>', $coreManifestPath));
            return;
        }

        $projectPackage = self::decodeJsonFile($projectPackagePath);
        if ($projectPackage === null) {
            $io->writeError(sprintf('<error>Skipping frontend dependency sync: invalid JSON in %s</error>', $projectPackagePath));
            return;
        }

        $sections = ['dependencies', 'devDependencies'];
        $added    = [];
        $updatedScripts = [];
        $preservedScripts = [];
        $deferredScripts = [];

        foreach ($sections as $section) {
            $required = $coreManifest[$section] ?? null;
            if (!is_array($required) || $required === []) {
                continue;
            }

            $hadSection = array_key_exists($section, $projectPackage);
            if (!isset($projectPackage[$section]) || !is_array($projectPackage[$section])) {
                $projectPackage[$section] = [];
            }

            foreach ($required as $name => $version) {
                if (!is_string($name) || $name === '') {
                    continue;
                }

                if (self::dependencyExistsAnywhere($projectPackage, $name)) {
                    continue;
                }

                $normalizedVersion = is_string($version) && $version !== '' ? $version : '*';
                $projectPackage[$section][$name] = $normalizedVersion;
                $added[] = sprintf('%s@%s', $name, $normalizedVersion);
            }

            if ($projectPackage[$section] === [] && !$hadSection) {
                unset($projectPackage[$section]);
            }
        }

        $scriptMigrations = $coreManifest['scriptMigrations'] ?? [];
        if (is_array($scriptMigrations)) {
            foreach ($scriptMigrations as $name => $migration) {
                if (
                    !is_string($name)
                    || $name === ''
                    || !is_array($migration)
                    || !is_string($migration['to'] ?? null)
                    || $migration['to'] === ''
                    || !is_array($migration['from'] ?? null)
                ) {
                    continue;
                }

                $scripts = $projectPackage['scripts'] ?? null;
                if (!is_array($scripts) || !array_key_exists($name, $scripts)) {
                    continue;
                }

                $current = $scripts[$name];
                if (!is_string($current)) {
                    $preservedScripts[] = $name;
                    continue;
                }
                if (hash_equals($migration['to'], $current)) {
                    if (!self::scriptMigrationPrerequisitesAreReady(
                        $migration,
                        $packageRoot,
                        $projectRoot
                    )) {
                        $deferredScripts[] = $name;
                    }
                    continue;
                }

                $legacyValues = array_values(array_filter(
                    $migration['from'],
                    static fn (mixed $value): bool =>
                        is_string($value) && $value !== ''
                ));
                if (!in_array($current, $legacyValues, true)) {
                    $preservedScripts[] = $name;
                    continue;
                }

                if (!self::scriptMigrationPrerequisitesAreReady(
                    $migration,
                    $packageRoot,
                    $projectRoot
                )) {
                    $deferredScripts[] = $name;
                    continue;
                }

                $projectPackage['scripts'][$name] = $migration['to'];
                $updatedScripts[] = $name;
            }
        }

        foreach (array_values(array_unique($preservedScripts)) as $name) {
            $io->write(sprintf(
                '<comment>Preserved customized frontend script in package.json: %s</comment>',
                $name
            ));
        }
        foreach (array_values(array_unique($deferredScripts)) as $name) {
            $io->write(sprintf(
                '<comment>Deferred canonical frontend script migration until its managed files are available: %s</comment>',
                $name
            ));
        }

        if ($added === [] && $updatedScripts === []) {
            if ($deferredScripts === []) {
                $io->write('<info>Frontend dependencies already up to date in package.json</info>');
            }
            return;
        }

        $encoded = json_encode($projectPackage, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            $io->writeError('<error>Skipping frontend dependency sync: unable to encode merged package.json</error>');
            return;
        }

        $written = @file_put_contents($projectPackagePath, $encoded . PHP_EOL);
        if ($written === false) {
            $io->writeError(sprintf('<error>Failed to write merged dependencies to %s</error>', $projectPackagePath));
            return;
        }

        if ($updatedScripts !== []) {
            $io->write(sprintf(
                '<info>Updated canonical frontend scripts in package.json: %s</info>',
                implode(', ', array_values(array_unique($updatedScripts)))
            ));
        }
        if ($added !== []) {
            $io->write(sprintf('<info>Added frontend dependencies to package.json: %s</info>', implode(', ', $added)));
            $io->write('<comment>Run npm install/yarn install/pnpm install to fetch new packages.</comment>');
        }
    }

    /** @param array<string, mixed> $migration */
    private static function scriptMigrationPrerequisitesAreReady(
        array $migration,
        string $packageRoot,
        string $projectRoot
    ): bool {
        $requirements = $migration['requiresManagedFiles'] ?? [];
        if (!is_array($requirements)) {
            return false;
        }

        foreach ($requirements as $requirement) {
            if (
                !is_array($requirement)
                || !is_string($requirement['source'] ?? null)
                || !is_string($requirement['target'] ?? null)
            ) {
                return false;
            }

            $source = self::safeChildPath(
                $packageRoot,
                $requirement['source']
            );
            $target = self::safeChildPath(
                $projectRoot,
                $requirement['target']
            );
            if (
                $source === null
                || $target === null
                || !is_file($source)
                || is_link($source)
                || !is_file($target)
                || is_link($target)
            ) {
                return false;
            }

            try {
                $sourceFingerprints = ManagedFileRegistry::fingerprintFile(
                    $source
                );
                $targetFingerprints = ManagedFileRegistry::fingerprintFile(
                    $target
                );
            } catch (\Throwable) {
                return false;
            }

            if (array_intersect(
                $sourceFingerprints,
                $targetFingerprints
            ) === []) {
                return false;
            }
        }

        return true;
    }

    private static function safeChildPath(
        string $root,
        string $relativePath
    ): ?string {
        $relativePath = str_replace('\\', '/', $relativePath);
        if (
            $relativePath === ''
            || Path::isAbsolute($relativePath)
            || preg_match('#(?:\A|/)\.\.?(?:/|\z)#', $relativePath) === 1
        ) {
            return null;
        }

        $canonicalRoot = rtrim(
            Path::canonicalize(str_replace('\\', '/', $root)),
            '/'
        );
        $candidate = Path::canonicalize(
            $canonicalRoot . '/' . $relativePath
        );
        $compareRoot = PHP_OS_FAMILY === 'Windows'
            ? strtolower($canonicalRoot)
            : $canonicalRoot;
        $compareCandidate = PHP_OS_FAMILY === 'Windows'
            ? strtolower($candidate)
            : $candidate;

        if (!str_starts_with($compareCandidate, $compareRoot . '/')) {
            return null;
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $candidate);
    }

    public static function syncResources(Event $event): void
    {
        if (!self::syncScssConfigContract($event)) {
            $event->getIO()->writeError(
                '<warning>Se omite la sincronización de recursos para no instalar SCSS cuyo contrato no está garantizado.</warning>'
            );
            return;
        }

        $synchronizer = self::createManagedFileSynchronizer($event);
        self::queueResources($event, $synchronizer);
        $synchronizer->apply();
    }

    private static function syncScssConfigContract(Event $event): bool
    {
        $io = $event->getIO();

        try {
            $projectRoot = self::resolveProjectRoot($event);
            $packageRoot = dirname(__DIR__, 3);
            $synchronizer = new ScssConfigContractSynchronizer($io);
            $added = $synchronizer->sync(
                $projectRoot . '/' . self::SCSS_CONFIG_TARGET_PATH,
                $packageRoot . '/' . self::SCSS_CONFIG_CONTRACT_PATH
            );

            if ($added > 0) {
                $io->write(sprintf(
                    '<info>Contrato SCSS de CORE: %d variable(s) de color añadida(s) sin reemplazar valores del proyecto.</info>',
                    $added
                ));
            }

            return $synchronizer->wasSuccessful();
        } catch (\Throwable $exception) {
            $io->writeError(sprintf(
                '<warning>No se pudo ampliar el contrato SCSS; el config del proyecto se preservó: %s</warning>',
                $exception->getMessage()
            ));

            return false;
        }
    }

    private static function queueResources(
        Event $event,
        ManagedFileSynchronizer $synchronizer
    ): void {
        $io = $event->getIO();
        $projectRoot = self::resolveProjectRoot($event);

        Paths::setProjectRoot($projectRoot);

        $packageRoot  = dirname(__DIR__, 3);
        $resourcesDir = $packageRoot . '/resources';

        if (!is_dir($resourcesDir)) {
            $io->writeError(sprintf('<warning>Resources directory not found: %s</warning>', $resourcesDir));
            return;
        }

        $targets = self::resolveResourceTargets($projectRoot);

        foreach ($targets as $target) {
            $pairs = [
                [
                    'source' => $resourcesDir . '/js',
                    'destination' => $target['js'],
                    'source_id' => 'resources/js',
                    'target_id' => $target['js_id'],
                ],
                [
                    'source' => $resourcesDir . '/scss',
                    'destination' => $target['scss'],
                    'source_id' => 'resources/scss',
                    'target_id' => $target['scss_id'],
                ],
            ];

            foreach ($pairs as $pair) {
                $source      = $pair['source'];
                $destination = $pair['destination'];

                if (!is_dir($source)) {
                    $io->writeError(sprintf('<warning>Skipping missing resources dir: %s</warning>', $source));
                    continue;
                }

                $synchronizer->queueDirectory(
                    $source,
                    $destination,
                    $pair['source_id'],
                    $pair['target_id'],
                    $target['track_state']
                );
            }
        }

        $imagesSource      = $resourcesDir . '/img';
        $imagesDestination = self::resolveImageResourceTarget($projectRoot);

        if (is_dir($imagesSource)) {
            $synchronizer->queueDirectory(
                $imagesSource,
                $imagesDestination,
                'resources/img',
                self::logicalTargetPrefix(
                    $projectRoot,
                    $imagesDestination,
                    '@custom-resources/img'
                )
            );
        }

        $videosSource      = $resourcesDir . '/video';
        $videosDestination = self::resolveVideoResourceTarget($projectRoot);

        if (is_dir($videosSource)) {
            $synchronizer->queueDirectory(
                $videosSource,
                $videosDestination,
                'resources/video',
                self::logicalTargetPrefix(
                    $projectRoot,
                    $videosDestination,
                    '@custom-resources/video'
                )
            );
        }
    }

    private static function syncProjectAssets(
        Event $event,
        ManagedFileSynchronizer $synchronizer
    ): void
    {
        $io = $event->getIO();
        $projectRoot = self::resolveProjectRoot($event);

        Paths::setProjectRoot($projectRoot);

        $packageRoot = dirname(__DIR__, 3);
        $stubsDir    = $packageRoot . '/stubs';

        $assets = [
            ['path' => 'public/index.php', 'type' => 'file'],
            ['path' => 'App/config/helpers.php', 'type' => 'file'],
            ['path' => 'App/config/languages', 'type' => 'dir'],
            ['path' => 'App/app/url.php', 'type' => 'file'],
            ['path' => 'App/app/formContact.php', 'type' => 'file'],
            ['path' => 'App/app/_phpmailer.php', 'type' => 'file'],
            ['path' => 'App/class/_comprobaciones.php', 'type' => 'file'],
            ['path' => 'App/app/updateLanguage.php', 'type' => 'file'],
            ['path' => 'App/controllers', 'type' => 'dir'],
            ['path' => 'App/templates', 'type' => 'dir'],
            ['path' => 'App/views', 'type' => 'dir'],
            ['path' => 'App/tools', 'type' => 'dir'],
            [
                'path' => self::VITE_LANGUAGE_PLUGIN_PATH,
                'type' => 'file',
            ],
            ['path' => 'src/js/showroom', 'type' => 'dir', 'base' => $packageRoot],
            ['path' => 'src/scss/showroom', 'type' => 'dir', 'base' => $packageRoot],
            ['path' => 'src/js/templates.js', 'type' => 'file', 'base' => $packageRoot],
            ['path' => 'src/scss/templates.scss', 'type' => 'file', 'base' => $packageRoot],
        ];

        foreach ($assets as $asset) {
            $assetPath = $asset['path'];
            $assetType = $asset['type'];
            $assetBase = $asset['base'] ?? $stubsDir;

            $source = $assetBase . '/' . $assetPath;
            $target = $projectRoot . '/' . $assetPath;

            if ($assetType === 'file' && !is_file($source)) {
                $io->writeError(sprintf('<warning>Skipping missing asset: %s</warning>', $source));
                continue;
            }

            if ($assetType === 'dir' && !is_dir($source)) {
                $io->writeError(sprintf('<warning>Skipping missing directory: %s</warning>', $source));
                continue;
            }

            $sourceId = $assetBase === $packageRoot
                ? $assetPath
                : 'stubs/' . $assetPath;

            if ($assetType === 'dir') {
                $synchronizer->queueDirectory(
                    $source,
                    $target,
                    $sourceId,
                    $assetPath
                );
                continue;
            }

            $synchronizer->queueFile(
                $source,
                $target,
                $sourceId,
                $assetPath
            );
        }

    }

    private static function queueDevelopmentRuntimeAssets(
        Event $event,
        ManagedFileSynchronizer $synchronizer
    ): void {
        $projectRoot = self::resolveProjectRoot($event);
        $packageRoot = dirname(__DIR__, 3);
        $source = $packageRoot
            . '/stubs/'
            . self::PHP_DEV_ROUTER_PATH;

        if (!is_file($source)) {
            $event->getIO()->writeError(sprintf(
                '<warning>Skipping missing development router: %s</warning>',
                $source
            ));
            return;
        }

        $synchronizer->queueFile(
            $source,
            $projectRoot . '/' . self::PHP_DEV_ROUTER_PATH,
            'stubs/' . self::PHP_DEV_ROUTER_PATH,
            self::PHP_DEV_ROUTER_PATH
        );
    }

    private static function queueInternalModules(
        Event $event,
        ManagedFileSynchronizer $synchronizer
    ): void {
        $io = $event->getIO();
        $projectRoot = self::resolveProjectRoot($event);
        $packageRoot = dirname(__DIR__, 3);

        try {
            $catalog = ModuleCatalog::fromCoreRoot($packageRoot);
            $selection = ModuleSelection::fromComposerJson(
                $catalog,
                $projectRoot . '/composer.json'
            );
        } catch (\Throwable $exception) {
            $io->writeError(sprintf(
                '<warning>No se pudieron resolver los módulos internos; el CORE base continuará sincronizándose: %s</warning>',
                $exception->getMessage()
            ));
            return;
        }

        $active = array_merge(['core'], $selection->enabledIds());
        $io->write(sprintf(
            '<info>Módulos LiquidStack activos: %s.</info>',
            implode(', ', $active)
        ));

        (new ModuleProjectFileSynchronizer($projectRoot, $io))->queue(
            $selection,
            $synchronizer
        );
    }

    private static function syncViteLanguagePluginConfig(
        Filesystem $filesystem,
        string $projectRoot,
        string $pluginSource,
        IOInterface $io
    ): void {
        $pluginTarget = $projectRoot . '/' . self::VITE_LANGUAGE_PLUGIN_PATH;
        $configPath = $projectRoot . '/vite.config.js';

        $sourceHash = is_file($pluginSource)
            ? hash_file('sha256', $pluginSource)
            : false;
        $targetHash = is_file($pluginTarget)
            ? hash_file('sha256', $pluginTarget)
            : false;

        if (
            !is_string($sourceHash)
            || !is_string($targetHash)
            || !hash_equals($sourceHash, $targetHash)
        ) {
            $io->writeError(sprintf(
                '<warning>Skipped Vite config integration because the managed plugin was not synced correctly to %s.</warning>',
                $pluginTarget
            ));
            return;
        }

        if (!is_file($configPath)) {
            $io->write(sprintf(
                '<info>Installed the Vite language plugin; skipped integration because %s is missing</info>',
                $configPath
            ));
            return;
        }

        if (is_link($configPath)) {
            $io->writeError(sprintf(
                '<warning>Preserved linked Vite config %s; integrate the CORE language plugin manually.</warning>',
                $configPath
            ));
            return;
        }

        $contents = @file_get_contents($configPath);
        if ($contents === false) {
            $io->writeError(sprintf(
                '<warning>Could not read %s; the Vite language plugin was installed but not integrated.</warning>',
                $configPath
            ));
            return;
        }

        $importCount = substr_count(
            $contents,
            self::VITE_LANGUAGE_PLUGIN_IMPORT
        );
        $callCount = preg_match_all(
            '~(?<![A-Za-z0-9_$])createUpdateLanguagesPlugin\s*\(\s*env\s*\)~',
            $contents
        );
        $callCount = is_int($callCount) ? $callCount : 0;

        if ($importCount === 1 && $callCount === 1) {
            $io->write('<info>Vite language plugin already integrated</info>');
            return;
        }

        if (
            $importCount !== 0
            || substr_count(
                $contents,
                'const createUpdateLanguagesPlugin = (env) => {'
            ) !== 1
            || $callCount !== 1
        ) {
            $io->writeError(sprintf(
                '<warning>Preserved custom %s. To enable the CORE language watcher, add `%s` and `%s,` inside `plugins`.</warning>',
                $configPath,
                self::VITE_LANGUAGE_PLUGIN_IMPORT,
                self::VITE_LANGUAGE_PLUGIN_CALL
            ));
            return;
        }

        $pattern = '~^const createUpdateLanguagesPlugin = \(env\) => \{'
            . '.*?^\};\R+(?=export default defineConfig)~ms';
        $updated = preg_replace($pattern, '', $contents, 1, $replacements);

        if (!is_string($updated) || $replacements !== 1) {
            $io->writeError(sprintf(
                '<warning>Preserved %s because its legacy language plugin could not be migrated safely.</warning>',
                $configPath
            ));
            return;
        }

        $childProcessImportPattern = '~^import\s+\{\s*'
            . '(exec(?:File)?Sync)'
            . '\s*\}\s+from\s+'
            . '["\'](?:node:)?child_process["\'];[^\r\n]*\R~m';
        if (
            preg_match(
                $childProcessImportPattern,
                $updated,
                $childProcessImport
            ) === 1
        ) {
            $withoutChildProcessImport = preg_replace(
                $childProcessImportPattern,
                '',
                $updated,
                1
            );
            $identifier = $childProcessImport[1] ?? '';

            // Retira el import legacy solo cuando el identificador ya no se
            // usa en ninguna personalización restante del proyecto.
            if (
                is_string($withoutChildProcessImport)
                && $identifier !== ''
                && preg_match(
                    '~\b' . preg_quote($identifier, '~') . '\b~',
                    $withoutChildProcessImport
                ) !== 1
            ) {
                $updated = $withoutChildProcessImport;
            }
        }

        $lineEnding = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $bom = str_starts_with($updated, "\xEF\xBB\xBF")
            ? "\xEF\xBB\xBF"
            : '';
        if ($bom !== '') {
            $updated = substr($updated, strlen($bom));
        }

        $updated = $bom
            . self::VITE_LANGUAGE_PLUGIN_IMPORT
            . $lineEnding
            . $updated;

        try {
            $filesystem->dumpFile($configPath, $updated);
            $io->write(sprintf(
                '<info>Migrated the legacy language watcher in %s to the CORE Vite plugin</info>',
                $configPath
            ));
        } catch (\Throwable $exception) {
            $io->writeError(sprintf(
                '<error>Failed to integrate the Vite language plugin in %s: %s</error>',
                $configPath,
                $exception->getMessage()
            ));
        }
    }

    private static function isAbsolutePath(string $path): bool
    {
        return self::startsWith($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:\\\\/', $path) === 1
            || self::startsWith($path, '\\\\');
    }

    private static function createManagedFileSynchronizer(
        Event $event
    ): ManagedFileSynchronizer {
        return new ManagedFileSynchronizer(
            self::resolveProjectRoot($event),
            dirname(__DIR__, 3),
            $event->getIO()
        );
    }

    private static function resolveProjectRoot(Event $event): string
    {
        $vendorDir = rtrim(
            (string) $event
                ->getComposer()
                ->getConfig()
                ->get('vendor-dir'),
            DIRECTORY_SEPARATOR
        );

        return dirname($vendorDir);
    }

    private static function logicalTargetPrefix(
        string $projectRoot,
        string $target,
        string $externalFallback
    ): string {
        $projectPath = rtrim(
            str_replace('\\', '/', $projectRoot),
            '/'
        );
        $targetPath = rtrim(str_replace('\\', '/', $target), '/');
        $projectKey = PHP_OS_FAMILY === 'Windows'
            ? strtolower($projectPath)
            : $projectPath;
        $targetKey = PHP_OS_FAMILY === 'Windows'
            ? strtolower($targetPath)
            : $targetPath;

        if (str_starts_with($targetKey, $projectKey . '/')) {
            return substr($targetPath, strlen($projectPath) + 1);
        }

        return $externalFallback;
    }

    /**
     * Obtiene los destinos a los que se replicaran los assets front.
     *
     * Por defecto se copian a `src/js/resources` y `src/scss/resources` para
     * que Vite recomponga cualquier archivo eliminado. La copia original ya
     * vive en `vendor/liquidstack/core/resources`. Si se define la variable
     * de entorno
     * STACK_CORE_RESOURCES_TARGET, se tomara como raiz (absoluta o
     * relativa al proyecto) y se crearan las carpetas `js` y `scss` bajo dicha
     * ruta. Se mantiene compatibilidad con STACK_LIQUID_CORE_RESOURCES_TARGET
     * como alias heredado.
     */
    private static function resolveResourceTargets(string $projectRoot): array
    {
        $configured = getenv('STACK_CORE_RESOURCES_TARGET');

        if (!is_string($configured) || $configured === '') {
            $configured = getenv('STACK_LIQUID_CORE_RESOURCES_TARGET');
        }

        if (is_string($configured) && $configured !== '') {
            $base = self::isAbsolutePath($configured)
                ? rtrim($configured, DIRECTORY_SEPARATOR)
                : $projectRoot . DIRECTORY_SEPARATOR . ltrim($configured, DIRECTORY_SEPARATOR);

            return [[
                'js' => $base . DIRECTORY_SEPARATOR . 'js',
                'scss' => $base . DIRECTORY_SEPARATOR . 'scss',
                'js_id' => '@custom-resources/js',
                'scss_id' => '@custom-resources/scss',
                'track_state' => true,
            ]];
        }

        return [[
            'js' => $projectRoot . '/src/js/resources',
            'scss' => $projectRoot . '/src/scss/resources',
            'js_id' => 'src/js/resources',
            'scss_id' => 'src/scss/resources',
            'track_state' => true,
        ]];
    }

    /**
     * Obtiene el destino donde se replicaran las imagenes de recursos.
     *
     * Por defecto se copian en `public/assets/img`. Si se define
     * STACK_CORE_RESOURCES_IMG_TARGET, se tomara como ruta base absoluta o
     * relativa al proyecto. Se mantiene STACK_LIQUID_CORE_RESOURCES_IMG_TARGET
     * como alias heredado.
     */
    private static function resolveImageResourceTarget(string $projectRoot): string
    {
        $configured = getenv('STACK_CORE_RESOURCES_IMG_TARGET');

        if (!is_string($configured) || $configured === '') {
            $configured = getenv('STACK_LIQUID_CORE_RESOURCES_IMG_TARGET');
        }

        if (is_string($configured) && $configured !== '') {
            return self::isAbsolutePath($configured)
                ? rtrim($configured, DIRECTORY_SEPARATOR)
                : $projectRoot . DIRECTORY_SEPARATOR . ltrim($configured, DIRECTORY_SEPARATOR);
        }

        return $projectRoot . DIRECTORY_SEPARATOR . 'public/assets/img';
    }

    /**
     * Obtiene el destino donde se replicaran los videos de recursos.
     *
     * Por defecto se copian en `public/assets/video`. Si se define
     * STACK_CORE_RESOURCES_VIDEO_TARGET, se tomara como ruta base absoluta o
     * relativa al proyecto. Se mantiene
     * STACK_LIQUID_CORE_RESOURCES_VIDEO_TARGET como alias heredado.
     */
    private static function resolveVideoResourceTarget(string $projectRoot): string
    {
        $configured = getenv('STACK_CORE_RESOURCES_VIDEO_TARGET');

        if (!is_string($configured) || $configured === '') {
            $configured = getenv('STACK_LIQUID_CORE_RESOURCES_VIDEO_TARGET');
        }

        if (is_string($configured) && $configured !== '') {
            return self::isAbsolutePath($configured)
                ? rtrim($configured, DIRECTORY_SEPARATOR)
                : $projectRoot . DIRECTORY_SEPARATOR . ltrim($configured, DIRECTORY_SEPARATOR);
        }

        return $projectRoot . DIRECTORY_SEPARATOR . 'public/assets/video';
    }

    private static function startsWith(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    private static function decodeJsonFile(string $path): ?array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function dependencyExistsAnywhere(array $package, string $name): bool
    {
        foreach (['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'] as $section) {
            if (!isset($package[$section]) || !is_array($package[$section])) {
                continue;
            }

            if (array_key_exists($name, $package[$section])) {
                return true;
            }
        }

        return false;
    }

    private static function removeAlternateManagedAgentSkills(
        Filesystem $filesystem,
        string $projectRoot,
        IOInterface $io
    ): void {
        $alternateTarget = $projectRoot . '/.agents/skills';
        $manifestPath    = $alternateTarget . DIRECTORY_SEPARATOR . self::AGENT_SKILLS_MANIFEST;

        if (!file_exists($manifestPath) && !is_link($manifestPath)) {
            return;
        }

        try {
            self::assertSafeAgentPath($projectRoot, $alternateTarget);
            self::assertSafeAgentPath($projectRoot, $manifestPath);
        } catch (\Throwable $exception) {
            $io->writeError(sprintf(
                '<warning>Preserved alternate managed agent skills because their path is unsafe: %s</warning>',
                $exception->getMessage()
            ));
            return;
        }

        $managedSkills = self::readManagedAgentSkills($manifestPath, $io);

        if ($managedSkills === null) {
            return;
        }

        $remainingSkills = [];

        foreach ($managedSkills as $skillName) {
            if (!self::isSafeAgentSkillName($skillName)) {
                $remainingSkills[] = $skillName;
                $io->writeError(sprintf(
                    '<warning>Preserved unsafe skill name in alternate CORE manifest: %s</warning>',
                    $skillName
                ));
                continue;
            }

            $skillPath = $alternateTarget . DIRECTORY_SEPARATOR . $skillName;

            if (!file_exists($skillPath) && !is_link($skillPath)) {
                continue;
            }

            try {
                self::assertSafeAgentTree($projectRoot, $skillPath);

                if (!is_dir($skillPath)) {
                    $remainingSkills[] = $skillName;
                    $io->writeError(sprintf(
                        '<warning>Preserved alternate managed skill because it is not a regular directory: %s</warning>',
                        $skillPath
                    ));
                    continue;
                }

                $filesystem->remove($skillPath);
                $io->write(sprintf('<info>Removed alternate managed agent skill: %s</info>', $skillName));
            } catch (\Throwable $exception) {
                $remainingSkills[] = $skillName;
                $io->writeError(sprintf(
                    '<warning>Preserved alternate managed agent skill %s: %s</warning>',
                    $skillName,
                    $exception->getMessage()
                ));
            }
        }

        try {
            if ($remainingSkills === []) {
                $filesystem->remove($manifestPath);
                $io->write('<info>Removed obsolete alternate CORE skills manifest from .agents/skills</info>');
                return;
            }

            $remainingSkills = array_values(array_unique($remainingSkills));
            sort($remainingSkills, SORT_STRING);

            $manifest = json_encode(
                ['managed' => $remainingSkills],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $filesystem->dumpFile($manifestPath, $manifest . PHP_EOL);
        } catch (\Throwable $exception) {
            $io->writeError(sprintf(
                '<warning>Failed to update alternate CORE skills manifest %s: %s</warning>',
                $manifestPath,
                $exception->getMessage()
            ));
        }
    }

    private static function assertSafeAgentTree(string $projectRoot, string $path): void
    {
        self::assertSafeAgentPath($projectRoot, $path);

        if (!is_dir($path)) {
            return;
        }

        $pendingDirectories = [$path];

        while ($pendingDirectories !== []) {
            $directory = array_pop($pendingDirectories);
            $iterator  = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);

            foreach ($iterator as $item) {
                $itemPath = $item->getPathname();
                self::assertSafeAgentPath($projectRoot, $itemPath);

                if ($item->isDir()) {
                    $pendingDirectories[] = $itemPath;
                }
            }
        }
    }

    private static function assertSafeAgentPath(string $projectRoot, string $path): void
    {
        $projectRealPath = realpath($projectRoot);

        if ($projectRealPath === false) {
            throw new \RuntimeException(sprintf('Unable to resolve project root: %s', $projectRoot));
        }

        $projectPath = self::normalizeAgentPath($projectRoot);
        $targetPath  = self::normalizeAgentPath($path);
        $projectKey  = self::comparableAgentPath($projectPath);
        $targetKey   = self::comparableAgentPath($targetPath);
        $prefix      = $projectKey . (str_ends_with($projectKey, '/') ? '' : '/');

        if ($targetKey !== $projectKey && !str_starts_with($targetKey, $prefix)) {
            throw new \RuntimeException(sprintf('Agent path escapes the project root: %s', $path));
        }

        if ($targetKey === $projectKey) {
            return;
        }

        $relativePath = ltrim(substr($targetPath, strlen($projectPath)), '/');
        $segments     = explode('/', $relativePath);
        $currentPath  = rtrim($projectRoot, '/\\');
        $expectedPath = self::normalizeAgentPath($projectRealPath);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException(sprintf('Agent path contains an unsafe segment: %s', $path));
            }

            $currentPath  = rtrim($currentPath, '/\\') . DIRECTORY_SEPARATOR . $segment;
            $expectedPath = rtrim($expectedPath, '/') . '/' . $segment;

            if (!file_exists($currentPath) && !is_link($currentPath)) {
                continue;
            }

            if (self::isRedirectingAgentEntry($currentPath)) {
                throw new \RuntimeException(sprintf(
                    'Agent path contains a symbolic link or redirected junction: %s',
                    $currentPath
                ));
            }

            $resolvedPath = realpath($currentPath);

            if (
                $resolvedPath === false
                || self::comparableAgentPath($resolvedPath) !== self::comparableAgentPath($expectedPath)
            ) {
                throw new \RuntimeException(sprintf(
                    'Agent path contains a redirected directory or junction: %s',
                    $currentPath
                ));
            }
        }
    }

    private static function normalizeAgentPath(string $path): string
    {
        $path = self::stripWindowsAgentPathPrefix($path);
        $path = Path::canonicalize($path);

        if ($path === '/' || preg_match('/\A[A-Za-z]:\/\z/', $path) === 1) {
            return $path;
        }

        return rtrim($path, '/');
    }

    private static function comparableAgentPath(string $path): string
    {
        $path = self::normalizeAgentPath($path);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }

    private static function isRedirectingAgentEntry(string $path): bool
    {
        clearstatcache(true, $path);

        if (is_link($path)) {
            return true;
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            return false;
        }

        $target = @readlink($path);

        if (!is_string($target) || $target === '') {
            return false;
        }

        if (!Path::isAbsolute(self::stripWindowsAgentPathPrefix($target))) {
            $target = dirname($path) . DIRECTORY_SEPARATOR . $target;
        }

        return self::comparableAgentPath($target) !== self::comparableAgentPath($path);
    }

    private static function stripWindowsAgentPathPrefix(string $path): string
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return $path;
        }

        $path = str_replace('/', '\\', $path);

        if (strncasecmp($path, '\\\\?\\UNC\\', 8) === 0) {
            return '\\\\' . substr($path, 8);
        }

        if (strncmp($path, '\\\\?\\', 4) === 0 || strncmp($path, '\\??\\', 4) === 0) {
            return substr($path, 4);
        }

        return $path;
    }

    /**
     * @return array<string, string>|null
     */
    private static function discoverCoreAgentSkills(string $skillsSource, IOInterface $io): ?array
    {
        $skills = [];

        try {
            $iterator = new FilesystemIterator($skillsSource, FilesystemIterator::SKIP_DOTS);

            foreach ($iterator as $item) {
                if (!$item->isDir()) {
                    continue;
                }

                $skillName = $item->getFilename();
                $skillPath = $item->getPathname();

                if (!is_file($skillPath . DIRECTORY_SEPARATOR . 'SKILL.md')) {
                    continue;
                }

                if (!self::isSafeAgentSkillName($skillName)) {
                    $io->writeError(sprintf(
                        '<warning>Skipping core agent skill with unsafe directory name: %s</warning>',
                        $skillName
                    ));
                    continue;
                }

                try {
                    self::assertSafeAgentTree($skillsSource, $skillPath);
                } catch (\Throwable $exception) {
                    $io->writeError(sprintf(
                        '<warning>Skipping core agent skill with redirected files: %s (%s)</warning>',
                        $skillName,
                        $exception->getMessage()
                    ));
                    continue;
                }

                $skills[$skillName] = $skillPath;
            }
        } catch (\Throwable $exception) {
            $io->writeError(sprintf(
                '<error>Failed to discover core agent skills in %s; existing managed skills were left unchanged: %s</error>',
                $skillsSource,
                $exception->getMessage()
            ));
            return null;
        }

        ksort($skills, SORT_STRING);

        return $skills;
    }

    /**
     * @return list<string>|null
     */
    private static function readManagedAgentSkills(string $manifestPath, IOInterface $io): array
    {
        if (!is_file($manifestPath)) {
            return [];
        }

        $raw = @file_get_contents($manifestPath);

        if ($raw === false) {
            $io->writeError(sprintf(
                '<warning>Unable to read core agent skills manifest; no retired skills will be removed: %s</warning>',
                $manifestPath
            ));
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !isset($decoded['managed']) || !is_array($decoded['managed'])) {
            $io->writeError(sprintf(
                '<warning>Invalid core agent skills manifest; no retired skills will be removed: %s</warning>',
                $manifestPath
            ));
            return null;
        }

        $managed = [];

        foreach ($decoded['managed'] as $skillName) {
            if (!is_string($skillName)) {
                continue;
            }

            // Keep unsafe entries so the deletion loop can explicitly report
            // that they were rejected instead of silently trusting the JSON.
            $managed[] = $skillName;
        }

        return array_values(array_unique($managed));
    }

    private static function isSafeAgentSkillName(string $skillName): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $skillName) === 1;
    }

}
