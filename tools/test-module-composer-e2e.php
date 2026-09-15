<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Composer\Util\Filesystem as ComposerFilesystem;

require dirname(__DIR__) . '/vendor/autoload.php';

$coreRoot = dirname(__DIR__);
$cleanupPrefix = '--cleanup-path=';
$cleanupArgument = $argv[1] ?? null;
if (
    is_string($cleanupArgument)
    && str_starts_with($cleanupArgument, $cleanupPrefix)
) {
    $cleanupPath = substr($cleanupArgument, strlen($cleanupPrefix));
    $resolvedCleanupPath = realpath($cleanupPath);
    $resolvedSystemTemp = realpath(sys_get_temp_dir());
    $cleanupName = is_string($resolvedCleanupPath)
        ? basename($resolvedCleanupPath)
        : '';

    if (
        !is_string($resolvedCleanupPath)
        || !is_string($resolvedSystemTemp)
        || !str_starts_with(
            strtolower(str_replace('\\', '/', $resolvedCleanupPath)),
            rtrim(
                strtolower(str_replace('\\', '/', $resolvedSystemTemp)),
                '/'
            ) . '/'
        )
        || preg_match(
            '/\A(?:liquidstack-module-e2e-[a-f0-9]{16}|\.!![A-Za-z0-9+_-]+)\z/',
            $cleanupName
        ) !== 1
    ) {
        throw new RuntimeException('Ruta de limpieza temporal no segura.');
    }

    if (!(new ComposerFilesystem())->removeDirectory($resolvedCleanupPath)) {
        throw new RuntimeException(sprintf(
            'No se pudo retirar el consumidor temporal %s.',
            $resolvedCleanupPath
        ));
    }

    fwrite(STDOUT, "TEMP_CLEANED" . PHP_EOL);
    exit(0);
}

$temporaryRoot = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'liquidstack-module-e2e-'
    . bin2hex(random_bytes(8));
$e2eSecurityKey = rtrim(strtr(
    base64_encode(str_repeat('E', 32)),
    '+/',
    '-_'
), '=');
$filesystem = new Filesystem();
$composerFilesystem = new ComposerFilesystem();
$filesystem->mkdir($temporaryRoot);
$filesystem->mkdir([
    $temporaryRoot . '/App/config',
    $temporaryRoot . '/App/config/languages/global',
    $temporaryRoot . '/App/includes',
    $temporaryRoot . '/src/js',
    $temporaryRoot . '/src/scss',
]);
$filesystem->dumpFile(
    $temporaryRoot . '/App/config/config.php',
    "<?php\n"
);
$filesystem->dumpFile(
    $temporaryRoot . '/App/config/langs.php',
    "<?php\nreturn ['es', 'en'];\n"
);
$filesystem->dumpFile(
    $temporaryRoot . '/src/scss/_config.scss',
    '$color00: #fff;' . PHP_EOL
);
$filesystem->dumpFile(
    $temporaryRoot . '/App/includes/_globalHead.php',
    <<<'PHP'
<?php

$title = $pageMeta['title'];
$headline = $pageMeta['headline'];
$description = $pageMeta['description'];
$canonical = $pageMeta['canonical'];
$alternates = $pageMeta['alternates'];
$xDefault = $pageMeta['x_default'];
$type = $pageMeta['type'];
$image = $pageMeta['image'];
$publishedAt = $pageMeta['published_at'];
$updatedAt = $pageMeta['updated_at'];
$nonceAttribute = $cspNonce !== ''
    ? ' nonce="' . htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') . '"'
    : '';
?>
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
<script<?= $nonceAttribute ?> src="/assets/js/global.js"></script>
PHP
);
$filesystem->dumpFile(
    $temporaryRoot . '/App/includes/_globalBody.php',
    "<?php\ndeclare(strict_types=1);\n"
);
$filesystem->dumpFile(
    $temporaryRoot . '/App/includes/_nav.php',
    "<nav aria-label=\"Principal\"></nav>\n"
);
$filesystem->dumpFile(
    $temporaryRoot . '/App/includes/_footer.php',
    "<footer></footer>\n"
);
$filesystem->dumpFile(
    $temporaryRoot . '/App/config/languages/global/es.json',
    "{}\n"
);
$filesystem->dumpFile(
    $temporaryRoot . '/App/config/languages/global/en.json',
    "{}\n"
);
$filesystem->dumpFile(
    $temporaryRoot . '/src/js/_global.js',
    "export {};\n"
);
$filesystem->dumpFile(
    $temporaryRoot . '/src/scss/_global.scss',
    "\n"
);
$filesystem->dumpFile(
    $temporaryRoot . '/.env',
    "BBDD_SERVER=localhost\n"
        . "BBDD_USER=e2e\n"
        . "BBDD_PASS=module-e2e-secret\n"
        . "BBDD_NAME=liquidstack_e2e\n"
        . "RAIZ=http://localhost:1309\n"
        . "DEV_MODE=1\n"
        . "LIQUIDSTACK_WEBADMIN_SECURITY_KEY={$e2eSecurityKey}\n"
        . "LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN=https://module-e2e.example.test\n"
);

/**
 * @param list<string> $arguments
 */
$runComposer = static function (array $arguments) use ($temporaryRoot): string {
    $process = new Process(
        array_merge(['composer'], $arguments),
        $temporaryRoot
    );
    $process->setTimeout(180);
    $process->run(static function (string $type, string $buffer): void {
        echo $buffer;
    });

    if (!$process->isSuccessful()) {
        throw new RuntimeException(sprintf(
            'Falló `%s` con código %d.',
            $process->getCommandLine(),
            $process->getExitCode()
        ));
    }

    return $process->getOutput() . $process->getErrorOutput();
};

/**
 * @param list<string> $arguments
 */
$runComposerExpectingFailure = static function (array $arguments) use (
    $temporaryRoot
): string {
    $process = new Process(
        array_merge(['composer'], $arguments),
        $temporaryRoot
    );
    $process->setTimeout(180);
    $process->run(static function (string $type, string $buffer): void {
        echo $buffer;
    });

    if ($process->isSuccessful()) {
        throw new RuntimeException(sprintf(
            'Se esperaba que `%s` fallara de forma segura.',
            $process->getCommandLine()
        ));
    }

    return $process->getOutput() . $process->getErrorOutput();
};

try {
    $runComposer([
        'init',
        '--name=liquidstack/module-e2e',
        '--type=project',
        '--require=liquidstack/core:dev-main',
        '--no-interaction',
    ]);
    $repository = json_encode([
        'type' => 'path',
        'url' => $coreRoot,
        'options' => ['symlink' => false],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $runComposer([
        'config',
        '--json',
        'repositories.liquidstack-core',
        $repository,
    ]);
    $runComposer([
        'config',
        'allow-plugins.liquidstack/core',
        'true',
    ]);
    $runComposer([
        'install',
        '--no-interaction',
        '--no-progress',
    ]);

    foreach ([
        '.codex/skills/liquidstack-content-localization/SKILL.md',
        '.codex/skills/liquidstack-content-localization/agents/openai.yaml',
        '.codex/skills/liquidstack-content-localization/references/static-content.md',
        '.codex/skills/liquidstack-content-localization/references/blog-localizations.md',
        '.codex/skills/liquidstack-github-actions/SKILL.md',
        '.codex/skills/liquidstack-github-actions/agents/openai.yaml',
        '.codex/skills/liquidstack-github-actions/references/deployment-topologies.md',
        '.codex/skills/liquidstack-module-operations/references/production-db-media-promotion.md',
    ] as $agentGuidancePath) {
        $sourcePath = $coreRoot . '/' . $agentGuidancePath;
        $targetPath = $temporaryRoot . '/' . $agentGuidancePath;
        if (
            !is_file($targetPath)
            || hash_file('sha256', $sourcePath) !== hash_file('sha256', $targetPath)
        ) {
            throw new RuntimeException(sprintf(
                'Composer no distribuyó la guía canónica %s.',
                $agentGuidancePath
            ));
        }
    }

    $webAdminRequireOutput = $runComposer([
        'require',
        'liquidstack/webadmin',
        '--no-interaction',
        '--no-progress',
        '--no-audit',
    ]);
    if (!str_contains(
        $webAdminRequireOutput,
        'Módulos LiquidStack activos: core, webadmin.'
    )) {
        throw new RuntimeException(
            'El hook post-update no resolvió el consumidor WebAdmin-only.'
        );
    }
    $webAdminOnlyComposer = json_decode(
        (string) file_get_contents($temporaryRoot . '/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (
        ($webAdminOnlyComposer['require']['liquidstack/webadmin'] ?? null)
            !== '*'
        || isset($webAdminOnlyComposer['require']['liquidstack/blog'])
    ) {
        throw new RuntimeException(
            'El selector WebAdmin-only no quedó aislado en composer.json.'
        );
    }
    foreach ([
        'webadmin.css',
        'webadmin.js',
        'webadmin-media-picker.css',
        'webadmin-media-picker.js',
    ] as $asset) {
        if (!is_file(
            $temporaryRoot . '/public/assets/modules/webadmin/' . $asset
        )) {
            throw new RuntimeException(sprintf(
                'WebAdmin-only no instaló el asset %s.',
                $asset
            ));
        }
    }
    if (is_file(
        $temporaryRoot . '/public/assets/modules/blog/blog-admin.css'
    )) {
        throw new RuntimeException(
            'WebAdmin-only publicó assets de Blog sin activar el módulo.'
        );
    }
    $webAdminOnlyDoctor = json_decode(
        trim($runComposerExpectingFailure([
            'liquidstack:doctor',
            '--format=json',
            '--no-interaction',
        ])),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (
        ($webAdminOnlyDoctor['ok'] ?? null) !== false
        || ($webAdminOnlyDoctor['modules']['requested'] ?? null)
            !== ['webadmin']
        || ($webAdminOnlyDoctor['modules']['enabled'] ?? null)
            !== ['webadmin']
        || !isset($webAdminOnlyDoctor['module_diagnostics']['webadmin'])
        || isset($webAdminOnlyDoctor['module_diagnostics']['blog'])
    ) {
        throw new RuntimeException(
            'Doctor no representó correctamente el consumidor WebAdmin-only.'
        );
    }
    $webAdminOnlyCommandList = json_decode(
        trim($runComposer([
            'list',
            '--format=json',
            '--no-interaction',
        ])),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $webAdminOnlyCommandNames = array_map(
        static fn (array $command): mixed => $command['name'] ?? null,
        is_array($webAdminOnlyCommandList['commands'] ?? null)
            ? $webAdminOnlyCommandList['commands']
            : []
    );
    if (!in_array(
        'liquidstack:webadmin:onboard',
        $webAdminOnlyCommandNames,
        true
    )) {
        throw new RuntimeException(
            'WebAdmin-only no recibió el comando de onboarding inicial.'
        );
    }
    if (!in_array(
        'liquidstack:sync',
        $webAdminOnlyCommandNames,
        true
    )) {
        throw new RuntimeException(
            'WebAdmin-only did not receive liquidstack:sync.'
        );
    }
    $runComposer([
        'remove',
        'liquidstack/webadmin',
        '--no-interaction',
        '--no-progress',
        '--no-audit',
    ]);

    $requireOutput = $runComposer([
        'require',
        'liquidstack/blog',
        '--no-interaction',
        '--no-progress',
        '--no-audit',
    ]);
    if (!str_contains(
        $requireOutput,
        'Módulos LiquidStack activos: core, webadmin, blog.'
    )) {
        throw new RuntimeException(
            'El hook post-update no resolvió Blog y WebAdmin sin config SCSS.'
        );
    }

    $composerPath = $temporaryRoot . '/composer.json';
    $composer = json_decode(
        (string) file_get_contents($composerPath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (($composer['require']['liquidstack/blog'] ?? null) !== '*') {
        throw new RuntimeException(
            'El selector Blog no quedó registrado con constraint `*`.'
        );
    }

    $lockedPackages = $runComposer(['show', '--locked', '--name-only']);
    if (!str_contains($lockedPackages, 'liquidstack/core')) {
        throw new RuntimeException('CORE no figura en composer.lock.');
    }
    if (str_contains($lockedPackages, 'liquidstack/blog')) {
        throw new RuntimeException(
            'Blog apareció como paquete físico en composer.lock.'
        );
    }

    $managedModuleFiles = [
        'modules/webadmin/published/assets/webadmin.css'
            => 'public/assets/modules/webadmin/webadmin.css',
        'modules/webadmin/published/assets/webadmin.js'
            => 'public/assets/modules/webadmin/webadmin.js',
        'modules/webadmin/published/assets/webadmin-media-picker.css'
            => 'public/assets/modules/webadmin/webadmin-media-picker.css',
        'modules/webadmin/published/assets/webadmin-media-picker.js'
            => 'public/assets/modules/webadmin/webadmin-media-picker.js',
        'modules/blog/published/assets/blog-admin.css'
            => 'public/assets/modules/blog/blog-admin.css',
        'modules/blog/published/assets/blog-editor.js'
            => 'public/assets/modules/blog/blog-editor.js',
        'modules/blog/published/assets/blog-public.css'
            => 'public/assets/modules/blog/blog-public.css',
        'modules/blog/resources/project/App/app/'
            . '_moduleBlogPublicArticle.php'
            => 'App/app/_moduleBlogPublicArticle.php',
        'modules/blog/resources/project/App/views/blog-article.php'
            => 'App/views/blog-article.php',
        'modules/blog/resources/project/src/js/blogArticle.js'
            => 'src/js/blogArticle.js',
        'modules/blog/resources/project/src/scss/blogArticle.scss'
            => 'src/scss/blogArticle.scss',
        'modules/blog/resources/project/App/controllers/'
            . 'artBlogArticle01.php'
            => 'App/controllers/artBlogArticle01.php',
        'modules/blog/resources/project/App/templates/'
            . '_artBlogArticle01.html'
            => 'App/templates/_artBlogArticle01.html',
        'modules/blog/resources/project/src/scss/resources/'
            . '_artBlogArticle01.scss'
            => 'src/scss/resources/_artBlogArticle01.scss',
    ];
    foreach ($managedModuleFiles as $source => $target) {
        $sourcePath = $coreRoot . '/' . $source;
        $targetPath = $temporaryRoot . '/' . $target;
        if (
            !is_file($sourcePath)
            || !is_file($targetPath)
            || hash_file('sha256', $sourcePath)
                !== hash_file('sha256', $targetPath)
        ) {
            throw new RuntimeException(sprintf(
                'El asset modular %s no llegó íntegro al consumidor.',
                $target
            ));
        }
    }

    $normalizerProbe = new Process(
        [
            PHP_BINARY,
            '-r',
            "require 'vendor/autoload.php'; "
                . "exit(function_exists('normalizer_normalize') ? 0 : 1);",
        ],
        $temporaryRoot
    );
    $normalizerProbe->setTimeout(30);
    $normalizerProbe->run();
    if (!$normalizerProbe->isSuccessful()) {
        throw new RuntimeException(
            'El consumidor Blog no recibio el normalizador Unicode.'
        );
    }

    $commandList = json_decode(
        trim($runComposer([
            'list',
            '--format=json',
            '--no-interaction',
        ])),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $commandNames = array_map(
        static fn (array $command): mixed => $command['name'] ?? null,
        is_array($commandList['commands'] ?? null)
            ? $commandList['commands']
            : []
    );
    if (!in_array(
        'liquidstack:webadmin:bootstrap',
        $commandNames,
        true
    )) {
        throw new RuntimeException(
            'El consumidor no recibió el comando de bootstrap WebAdmin.'
        );
    }
    if (!in_array(
        'liquidstack:webadmin:onboard',
        $commandNames,
        true
    )) {
        throw new RuntimeException(
            'El consumidor Blog no recibió el comando de onboarding WebAdmin.'
        );
    }
    if (!in_array('liquidstack:media:init', $commandNames, true)) {
        throw new RuntimeException(
            'El consumidor no recibió el comando de inicialización Media.'
        );
    }
    if (!in_array(
        'liquidstack:blog:adopt-public-shell',
        $commandNames,
        true
    )) {
        throw new RuntimeException(
            'El consumidor Blog no recibió el comando de adopción del shell público.'
        );
    }

    $snapshotProject = static function (string $root): array {
        $hashes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = substr(
                $path,
                strlen(str_replace('\\', '/', $root)) + 1
            );
            if (str_starts_with($relative, 'vendor/')) {
                continue;
            }

            $hashes[$relative] = hash_file('sha256', $file->getPathname())
                ?: '';
        }
        ksort($hashes);

        return $hashes;
    };
    $beforeReadOnlyCommands = $snapshotProject($temporaryRoot);

    $syncOutput = trim($runComposer([
        'liquidstack:sync',
        '--dry-run',
        '--format=json',
        '--no-interaction',
    ]));
    $syncPlan = json_decode(
        $syncOutput,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (
        ($syncPlan['ok'] ?? null) !== true
        || ($syncPlan['status'] ?? null) !== 'ready'
        || ($syncPlan['standard_resources_ready'] ?? null) !== true
        || preg_match(
            '/\Asha256:[a-f0-9]{64}\z/',
            (string) ($syncPlan['plan_hash'] ?? '')
        ) !== 1
        || str_contains(
            str_replace('\\', '/', $syncOutput),
            str_replace('\\', '/', $temporaryRoot)
        )
    ) {
        throw new RuntimeException(
            'LiquidStack sync did not return a safe read-only plan.'
        );
    }

    $doctorOutput = trim($runComposerExpectingFailure([
        'liquidstack:doctor',
        '--format=json',
        '--no-interaction',
    ]));
    $doctor = json_decode(
        $doctorOutput,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (
        ($doctor['ok'] ?? null) !== false
        || ($doctor['modules']['requested'] ?? null) !== ['blog']
        || ($doctor['modules']['enabled'] ?? null) !== ['webadmin', 'blog']
        || ($doctor['migrations']['read_only'] ?? false) !== true
        || ($doctor['module_diagnostics']['webadmin']['readiness']['database_connection'] ?? null)
            !== 'unavailable'
        || ($doctor['module_diagnostics']['webadmin']['readiness']['runtime_ready'] ?? null)
            !== false
        || ($doctor['module_diagnostics']['blog']['configuration']['ready'] ?? null)
            !== true
        || ($doctor['module_diagnostics']['blog']['environment']['public_origin']['ready'] ?? null)
            !== true
        || ($doctor['module_diagnostics']['blog']['readiness']['blog_ready'] ?? null)
            !== false
        || ($doctor['module_diagnostics']['blog']['public_shell']['mode'] ?? null)
            !== 'standalone'
        || ($doctor['module_diagnostics']['blog']['public_shell']['complete'] ?? null)
            !== false
        || str_contains($doctorOutput, 'module-e2e-secret')
        || str_contains($doctorOutput, $e2eSecurityKey)
    ) {
        throw new RuntimeException(
            'LiquidStack doctor no devolvió el diagnóstico seguro esperado.'
        );
    }

    $shellAdoptionOutput = trim($runComposer([
        'liquidstack:blog:adopt-public-shell',
        '--format=json',
        '--no-interaction',
    ]));
    $shellAdoption = json_decode(
        $shellAdoptionOutput,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (
        ($shellAdoption['ok'] ?? null) !== true
        || ($shellAdoption['result']['status'] ?? null) !== 'ready'
        || ($shellAdoption['result']['changed'] ?? null) !== false
        || is_file($temporaryRoot . '/App/config/modules/blog.php')
    ) {
        throw new RuntimeException(
            'La adopción dry-run del shell Blog no conservó el consumidor.'
        );
    }

    $migrationOutput = trim($runComposer([
        'liquidstack:migrate',
        '--plan',
        '--format=json',
        '--no-interaction',
    ]));
    $migrationPlan = json_decode(
        $migrationOutput,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $expectedMigrations = [
        [
            'module' => 'webadmin',
            'target_scope_module' => 'webadmin',
            'id' => '0001_webadmin_identity_and_access',
        ],
        [
            'module' => 'webadmin',
            'target_scope_module' => 'webadmin',
            'id' => '0002_webadmin_media_library',
        ],
        [
            'module' => 'webadmin',
            'target_scope_module' => 'webadmin',
            'id' => '0003_webadmin_media_avif_source',
        ],
        [
            'module' => 'webadmin',
            'target_scope_module' => 'webadmin',
            'id' => '0004_webadmin_profile_preferences',
        ],
        [
            'module' => 'webadmin',
            'target_scope_module' => 'webadmin',
            'id' => '0005_webadmin_media_quarantine',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0001_blog_posts',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'webadmin',
            'id' => '0002_blog_capabilities',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0003_blog_categories',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'webadmin',
            'id' => '0004_blog_category_capabilities',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0005_blog_structured_content',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0006_blog_sitemap_publication_state',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0007_blog_post_tombstones',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'webadmin',
            'id' => '0008_blog_article_delete_capability',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0009_blog_analytics',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'webadmin',
            'id' => '0010_blog_analytics_view_capability',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0011_blog_layout_editor_v2',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0012_blog_editor_preferences',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'webadmin',
            'id' => '0013_blog_settings_manage_capability',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0014_blog_private_draft_publication',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0015_blog_robots_preferences',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0016_blog_url_history',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0017_blog_dummy_category',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0018_blog_dummy_category_normalization',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0019_blog_copy_operation_idempotency',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0020_blog_tags',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0021_blog_localization_tags',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0022_blog_tag_assignment_heads',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0023_blog_tag_assignment_workspaces',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'blog',
            'id' => '0024_blog_tag_assignment_workspace_items',
        ],
        [
            'module' => 'blog',
            'target_scope_module' => 'webadmin',
            'id' => '0025_blog_tag_capabilities',
        ],
    ];
    $plannedMigrations = array_map(
        static fn (array $entry): array => [
            'module' => $entry['module'] ?? null,
            'target_scope_module' =>
                $entry['target_scope_module'] ?? null,
            'id' => $entry['id'] ?? null,
        ],
        is_array($migrationPlan['migrations']['entries'] ?? null)
            ? $migrationPlan['migrations']['entries']
            : []
    );
    if (
        ($migrationPlan['ok'] ?? false) !== true
        || ($migrationPlan['operation'] ?? null) !== 'migrate-plan'
        || ($migrationPlan['migrations']['read_only'] ?? false) !== true
        || ($migrationPlan['migrations']['database_state'] ?? null)
            !== 'not_evaluated'
        || ($migrationPlan['migrations']['count'] ?? null)
            !== count($expectedMigrations)
        || $plannedMigrations !== $expectedMigrations
        || str_contains($migrationOutput, $e2eSecurityKey)
    ) {
        throw new RuntimeException(
            'El plan de migraciones no respetó el contrato read-only.'
        );
    }

    $bootstrapWithoutConfirmationOutput = trim(
        $runComposerExpectingFailure([
            'liquidstack:webadmin:bootstrap',
            '--format=json',
            '--no-interaction',
        ])
    );
    $bootstrapWithoutConfirmation = json_decode(
        $bootstrapWithoutConfirmationOutput,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (
        ($bootstrapWithoutConfirmation['ok'] ?? null) !== false
        || ($bootstrapWithoutConfirmation['error']['code'] ?? null)
            !== 'webadmin.bootstrap.json_requires_yes'
        || str_contains(
            $bootstrapWithoutConfirmationOutput,
            'module-e2e-secret'
        )
    ) {
        throw new RuntimeException(
            'El bootstrap no respetó su gate de confirmación seguro.'
        );
    }

    $onboardWithoutConfirmationOutput = trim(
        $runComposerExpectingFailure([
            'liquidstack:webadmin:onboard',
            '--format=json',
            '--no-interaction',
        ])
    );
    $onboardWithoutConfirmation = json_decode(
        $onboardWithoutConfirmationOutput,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (
        ($onboardWithoutConfirmation['ok'] ?? null) !== false
        || ($onboardWithoutConfirmation['operation'] ?? null)
            !== 'webadmin-onboard'
        || ($onboardWithoutConfirmation['error']['code'] ?? null)
            !== 'webadmin.onboard.json_requires_yes'
        || str_contains(
            $onboardWithoutConfirmationOutput,
            'module-e2e-secret'
        )
        || str_contains(
            $onboardWithoutConfirmationOutput,
            $e2eSecurityKey
        )
    ) {
        throw new RuntimeException(
            'El onboarding no respetó su gate de confirmación seguro.'
        );
    }

    $mediaWithoutConfirmationOutput = trim(
        $runComposerExpectingFailure([
            'liquidstack:media:init',
            '--format=json',
            '--no-interaction',
        ])
    );
    $mediaWithoutConfirmation = json_decode(
        $mediaWithoutConfirmationOutput,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (
        ($mediaWithoutConfirmation['ok'] ?? null) !== false
        || ($mediaWithoutConfirmation['error']['code'] ?? null)
            !== 'webadmin.media.init.json_requires_yes'
        || is_dir($temporaryRoot . '/storage/liquidstack/webadmin/media')
    ) {
        throw new RuntimeException(
            'Media init no respetó su gate sin efectos laterales.'
        );
    }

    if ($beforeReadOnlyCommands !== $snapshotProject($temporaryRoot)) {
        throw new RuntimeException(
            'Los comandos de diagnóstico o sus gates modificaron el consumidor.'
        );
    }

    $mediaRoot = $temporaryRoot . '/storage/liquidstack/webadmin/media';
    $mediaInitOutput = trim($runComposer([
        'liquidstack:media:init',
        '--yes',
        '--format=json',
        '--no-interaction',
    ]));
    $mediaInit = json_decode(
        $mediaInitOutput,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (
        ($mediaInit['ok'] ?? null) !== true
        || ($mediaInit['result']['status'] ?? null) !== 'initialized'
        || ($mediaInit['result']['changed'] ?? null) !== true
        || !is_file($mediaRoot . '/.liquidstack-webadmin-media')
        || file_get_contents($mediaRoot . '/.gitignore') !== "*\n"
    ) {
        throw new RuntimeException(
            'Media init no creó el almacenamiento privado canónico.'
        );
    }
    $mediaSecondOutput = trim($runComposer([
        'liquidstack:media:init',
        '--yes',
        '--format=json',
        '--no-interaction',
    ]));
    $mediaSecond = json_decode(
        $mediaSecondOutput,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (
        ($mediaSecond['result']['status'] ?? null)
            !== 'already_initialized'
        || ($mediaSecond['result']['changed'] ?? null) !== false
    ) {
        throw new RuntimeException('Media init no fue idempotente.');
    }

    $runComposer([
        'remove',
        'liquidstack/blog',
        '--no-interaction',
        '--no-progress',
        '--no-audit',
    ]);
    $composer = json_decode(
        (string) file_get_contents($composerPath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (
        isset($composer['require']['liquidstack/blog'])
        || !isset($composer['require']['liquidstack/core'])
        || !is_file($mediaRoot . '/.liquidstack-webadmin-media')
    ) {
        throw new RuntimeException(
            'Retirar Blog no conservó el contrato de CORE.'
        );
    }
    foreach ($managedModuleFiles as $source => $target) {
        $sourcePath = $coreRoot . '/' . $source;
        $targetPath = $temporaryRoot . '/' . $target;
        if (
            !is_file($targetPath)
            || hash_file('sha256', $sourcePath)
                !== hash_file('sha256', $targetPath)
        ) {
            throw new RuntimeException(sprintf(
                'Retirar Blog eliminó o alteró el asset conservado %s.',
                $target
            ));
        }
    }

    $coreOnlyDoctor = json_decode(
        trim($runComposer([
            'liquidstack:doctor',
            '--format=json',
            '--no-interaction',
        ])),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (
        ($coreOnlyDoctor['modules']['requested'] ?? null) !== []
        || ($coreOnlyDoctor['modules']['enabled'] ?? null) !== []
        || isset($coreOnlyDoctor['module_diagnostics']['webadmin'])
    ) {
        throw new RuntimeException(
            'Doctor no volvió al estado Core-only tras retirar Blog.'
        );
    }

    $beforeCoreOnlyOnboard = $snapshotProject($temporaryRoot);
    $coreOnlyOnboardOutput = trim($runComposerExpectingFailure([
        'liquidstack:webadmin:onboard',
        '--yes',
        '--format=json',
        '--no-interaction',
    ]));
    $coreOnlyOnboard = json_decode(
        $coreOnlyOnboardOutput,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (
        ($coreOnlyOnboard['ok'] ?? null) !== false
        || ($coreOnlyOnboard['operation'] ?? null) !== 'webadmin-onboard'
        || ($coreOnlyOnboard['error']['code'] ?? null)
            !== 'webadmin.mail.module_not_enabled'
        || str_contains($coreOnlyOnboardOutput, 'module-e2e-secret')
        || str_contains($coreOnlyOnboardOutput, $e2eSecurityKey)
        || $beforeCoreOnlyOnboard !== $snapshotProject($temporaryRoot)
    ) {
        throw new RuntimeException(
            'Core-only permitió ejecutar o mutar el onboarding WebAdmin.'
        );
    }

    fwrite(STDOUT, PHP_EOL . "MODULE_COMPOSER_E2E_OK" . PHP_EOL);
} finally {
    $resolvedTemporaryRoot = realpath($temporaryRoot);
    $resolvedSystemTemp = realpath(sys_get_temp_dir());

    if (
        is_string($resolvedTemporaryRoot)
        && is_string($resolvedSystemTemp)
        && str_starts_with(
            strtolower(str_replace('\\', '/', $resolvedTemporaryRoot)),
            rtrim(
                strtolower(str_replace('\\', '/', $resolvedSystemTemp)),
                '/'
            ) . '/liquidstack-module-e2e-'
        )
    ) {
        if (!$composerFilesystem->removeDirectory($resolvedTemporaryRoot)) {
            throw new RuntimeException(sprintf(
                'No se pudo retirar el consumidor temporal %s.',
                $resolvedTemporaryRoot
            ));
        }
    }
}
