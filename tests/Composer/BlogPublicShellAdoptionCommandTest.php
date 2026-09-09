<?php

declare(strict_types=1);

use App\Core\Composer\Command\BlogPublicShellAdoptionCommand;
use Composer\Console\Application as ComposerApplication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class BlogPublicShellAdoptionCommandTest extends TestCase
{
    private Filesystem $filesystem;
    private string $sandbox;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->sandbox = rtrim(sys_get_temp_dir(), '/\\')
            . '/liquidstack-blog-shell-' . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->sandbox);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->sandbox);
    }

    public function testDefaultModeIsReadOnlyAndReportsApplicablePlan(): void
    {
        $config = "<?php\n\nreturn [\n"
            . "    'public_paths' => ['es' => '/es/noticias'],\n"
            . "    'database' => ['connection' => 'shared'],\n"
            . "];\n";
        $project = $this->project('dry-run', $config);
        $tester = $this->tester($project);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame($config, file_get_contents(
            $project . '/App/config/modules/blog.php'
        ));
        self::assertStringContainsString('Plan listo', $tester->getDisplay());
        self::assertStringContainsString('npm run build', $tester->getDisplay());
        self::assertStringContainsString(
            'public/.vite/manifest.json',
            $tester->getDisplay()
        );
        self::assertStringContainsString(
            'composer liquidstack:doctor',
            $tester->getDisplay()
        );
        self::assertStringContainsString(
            '--apply --yes',
            $tester->getDisplay()
        );
    }

    public function testApplyRequiresExplicitYesWithoutMutation(): void
    {
        $config = "<?php\nreturn ['sitemap_path' => '/blog.xml'];\n";
        $project = $this->project('confirmation', $config);
        $tester = $this->tester($project);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--apply' => true,
        ]));
        self::assertSame($config, file_get_contents(
            $project . '/App/config/modules/blog.php'
        ));
        self::assertStringContainsString(
            'blog.public_shell_adoption.confirmation_required',
            $tester->getDisplay()
        );
    }

    public function testApplyUpdatesLiteralConfigAndIsIdempotent(): void
    {
        $config = "<?php\r\n\r\ndeclare(strict_types=1);\r\n\r\n"
            . "return [\r\n"
            . "    'public_paths' => [\r\n"
            . "        'es' => '/es/noticias',\r\n"
            . "    ],\r\n"
            . "    'sitemap_path' => '/blog-sitemap.xml'\r\n"
            . "];\r\n";
        $project = $this->project('apply', $config);
        $path = $project . '/App/config/modules/blog.php';
        $tester = $this->tester($project);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('activated', $payload['result']['status']);
        self::assertTrue($payload['result']['changed']);
        $updated = file_get_contents($path);
        self::assertIsString($updated);
        self::assertStringContainsString(
            "'public_article_view' => 'App/views/blog-article.php',",
            $updated
        );
        self::assertStringContainsString("'sitemap_path'", $updated);
        self::assertStringContainsString("\r\n", $updated);
        $loaded = require $path;
        self::assertSame(
            'App/views/blog-article.php',
            $loaded['public_article_view']
        );

        $second = $this->tester($project);
        self::assertSame(Command::SUCCESS, $second->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $secondPayload = json_decode(
            $second->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('already_active', $secondPayload['result']['status']);
        self::assertFalse($secondPayload['result']['changed']);
        self::assertSame($updated, file_get_contents($path));
    }

    public function testMissingConfigIsPlannedThenCreatedAsMinimalFile(): void
    {
        $project = $this->project('missing-config', null, false);
        $path = $project . '/App/config/modules/blog.php';
        $dryRun = $this->tester($project);

        self::assertSame(Command::SUCCESS, $dryRun->execute([
            '--format' => 'json',
        ]));
        self::assertFileDoesNotExist($path);

        $apply = $this->tester($project);
        self::assertSame(Command::SUCCESS, $apply->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        self::assertFileExists($path);
        self::assertSame([
            'public_article_view' => 'App/views/blog-article.php',
        ], require $path);
    }

    public function testDynamicConfigFailsClosedWithActionableSnippet(): void
    {
        $config = "<?php\n\$config = ['public_paths' => []];\n"
            . "return \$config;\n";
        $project = $this->project('dynamic', $config);
        $tester = $this->tester($project);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'blog.public_shell_adoption.config_not_literal',
            $payload['error']['code']
        );
        self::assertFalse($payload['error']['changed']);
        self::assertSame(
            "'public_article_view' => 'App/views/blog-article.php',",
            $payload['error']['required_config']['entry']
        );
        self::assertSame($config, file_get_contents(
            $project . '/App/config/modules/blog.php'
        ));
    }

    public function testExistingIncompatibleValueFailsWithoutMutation(): void
    {
        $config = "<?php\nreturn [\n"
            . "    'public_article_view' => 'App/views/custom.php',\n"
            . "];\n";
        $project = $this->project('incompatible', $config);
        $tester = $this->tester($project);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'blog.public_shell_adoption.config_value_incompatible',
            $payload['error']['code']
        );
        self::assertSame(
            "'public_article_view' => 'App/views/blog-article.php',",
            $payload['error']['required_config']['entry']
        );
        self::assertSame($config, file_get_contents(
            $project . '/App/config/modules/blog.php'
        ));
    }

    public function testMissingScaffoldFileBlocksBeforeConfigMutation(): void
    {
        $config = "<?php\nreturn [];\n";
        $project = $this->project('missing-scaffold', $config);
        $this->filesystem->remove($project . '/src/js/blogArticle.js');
        $tester = $this->tester($project);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'blog.public_shell_adoption.scaffold_missing',
            $payload['error']['code']
        );
        self::assertSame('src/js/blogArticle.js', $payload['error']['path']);
        self::assertArrayNotHasKey('required_config', $payload['error']);
        self::assertSame($config, file_get_contents(
            $project . '/App/config/modules/blog.php'
        ));
    }

    public function testSymlinkedScaffoldFileIsRejected(): void
    {
        $config = "<?php\nreturn [];\n";
        $project = $this->project('symlink', $config);
        $link = $project . '/App/views/blog-article.php';
        $external = $this->sandbox . '/external-view.php';
        $this->filesystem->dumpFile($external, '<?php');
        $this->filesystem->remove($link);
        if (!@symlink($external, $link)) {
            self::markTestSkipped('This environment cannot create symlinks.');
        }
        $tester = $this->tester($project);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'blog.public_shell_adoption.scaffold_path_invalid',
            $payload['error']['code']
        );
        self::assertSame($config, file_get_contents(
            $project . '/App/config/modules/blog.php'
        ));
    }

    public function testArbitraryPreservedViewIsRejectedWithoutMutation(): void
    {
        $config = "<?php\nreturn [];\n";
        $project = $this->project('arbitrary-view', $config);
        $view = <<<'PHP'
<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html><head><title>Legacy article</title></head>
<body><main>Legacy article</main></body></html>
PHP;
        $path = $project . '/App/views/blog-article.php';
        $this->filesystem->dumpFile($path, $view);
        $tester = $this->tester($project);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'blog.public_shell_adoption.view_contract_invalid',
            $payload['error']['code']
        );
        self::assertArrayNotHasKey('required_config', $payload['error']);
        self::assertSame($config, file_get_contents(
            $project . '/App/config/modules/blog.php'
        ));
        self::assertSame($view, file_get_contents($path));
    }

    public function testHookAfterDoctypeIsRejected(): void
    {
        $config = "<?php\nreturn [];\n";
        $project = $this->project('late-hook', $config);
        $path = $project . '/App/views/blog-article.php';
        $view = str_replace(
            "require __DIR__ . '/../app/_moduleBlogPublicArticle.php';",
            '',
            $this->validArticleView()
        );
        $view = str_replace(
            '<body class="project-customization">',
            "<body class=\"project-customization\">\n"
                . "<?php require __DIR__ . "
                . "'/../app/_moduleBlogPublicArticle.php'; ?>",
            $view
        );
        $this->filesystem->dumpFile($path, $view);
        $tester = $this->tester($project);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'blog.public_shell_adoption.view_contract_invalid',
            $payload['error']['code']
        );
        self::assertSame($config, file_get_contents(
            $project . '/App/config/modules/blog.php'
        ));
    }

    public function testViewStructureMustKeepAnalyticsAndShellOrder(): void
    {
        $config = "<?php\nreturn [];\n";
        $valid = $this->validArticleView();
        $cases = [
            'analytics-outside-html' => str_replace(
                '      data-blog-analytics-page-grant="<?= $escape($blogArticle->analyticsPageGrant()) ?>"<?php endif ?>>',
                '<?php endif ?>>',
                str_replace(
                    '<body class="project-customization">',
                    '<body class="project-customization" data-blog-analytics-page-grant="outside">',
                    $valid
                )
            ),
            'navigation-after-wrapper' => str_replace(
                "    <?php include __DIR__ . '/../includes/_nav.php' ?>\n"
                    . "    <div id=\"smooth-wrapper\">",
                "    <div id=\"smooth-wrapper\">\n"
                    . "    <?php include __DIR__ . '/../includes/_nav.php' ?>",
                $valid
            ),
            'wrapper-before-body' => str_replace(
                "<body class=\"project-customization\">",
                "<div id=\"smooth-wrapper\">\n"
                    . "<body class=\"project-customization\">",
                str_replace(
                    "    <div id=\"smooth-wrapper\">\n"
                        . "        <div id=\"smooth-content\">",
                    "        <div id=\"smooth-content\">",
                    $valid
                )
            ),
        ];
        foreach ($cases as $name => $view) {
            self::assertNotSame($valid, $view, $name);
            $project = $this->project($name, $config);
            $this->filesystem->dumpFile(
                $project . '/App/views/blog-article.php',
                $view
            );
            $tester = $this->tester($project);

            self::assertSame(Command::FAILURE, $tester->execute([
                '--apply' => true,
                '--yes' => true,
                '--format' => 'json',
            ]), $name);
            $payload = json_decode(
                $tester->getDisplay(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            self::assertSame(
                'blog.public_shell_adoption.view_contract_invalid',
                $payload['error']['code'],
                $name
            );
            self::assertSame($config, file_get_contents(
                $project . '/App/config/modules/blog.php'
            ), $name);
        }
    }

    public function testHookValuesMustBePresentInExecutableCode(): void
    {
        $config = "<?php\nreturn [];\n";
        $project = $this->project('hook-semantic', $config);
        $hook = str_replace(
            '$articleMain = $blogArticle->mainHtml();',
            '// $articleMain = $blogArticle->mainHtml();',
            $this->validArticleHook()
        );
        $this->filesystem->dumpFile(
            $project . '/App/app/_moduleBlogPublicArticle.php',
            $hook
        );
        $tester = $this->tester($project);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'blog.public_shell_adoption.hook_contract_invalid',
            $payload['error']['code']
        );
        self::assertSame($config, file_get_contents(
            $project . '/App/config/modules/blog.php'
        ));
    }

    public function testEntryWithoutLanguageBindingCallIsRejected(): void
    {
        $config = "<?php\nreturn [];\n";
        $project = $this->project('missing-language-call', $config);
        $this->filesystem->dumpFile(
            $project . '/src/js/blogArticle.js',
            <<<'JS'
import '../scss/blogArticle.scss';
import './_global.js';
import { bindLanguageNavigation } from './resources/_languagePreference.mjs';

export { bindLanguageNavigation };
JS
        );
        $tester = $this->tester($project);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'blog.public_shell_adoption.entry_contract_invalid',
            $payload['error']['code']
        );
        self::assertSame($config, file_get_contents(
            $project . '/App/config/modules/blog.php'
        ));
    }

    public function testIncompletePageMetadataContractIsRejected(): void
    {
        $config = "<?php\nreturn [];\n";
        $project = $this->project('missing-headline', $config);
        $head = str_replace(
            "\n    \$pageMeta['headline'] ?? '',",
            '',
            $this->validGlobalHead()
        );
        $this->filesystem->dumpFile(
            $project . '/App/includes/_globalHead.php',
            $head
        );
        $tester = $this->tester($project);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'blog.public_shell_adoption.global_head_contract_invalid',
            $payload['error']['code']
        );
        self::assertSame($config, file_get_contents(
            $project . '/App/config/modules/blog.php'
        ));
    }

    public function testHeadWithoutNonceAppliedToScriptsIsRejected(): void
    {
        $config = "<?php\nreturn [];\n";
        $project = $this->project('missing-script-nonce', $config);
        $head = str_replace(
            '<script<?= $headScriptNonceAttribute ?>>',
            '<script>',
            $this->validGlobalHead()
        );
        $this->filesystem->dumpFile(
            $project . '/App/includes/_globalHead.php',
            $head
        );
        $tester = $this->tester($project);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'blog.public_shell_adoption.global_head_contract_invalid',
            $payload['error']['code']
        );
        self::assertSame($config, file_get_contents(
            $project . '/App/config/modules/blog.php'
        ));
    }

    public function testCookieLadRequiresEffectiveCspSources(): void
    {
        $config = "<?php\nreturn [];\n";
        $project = $this->project('cookielad-csp', $config);
        $headPath = $project . '/App/includes/_globalHead.php';
        $head = file_get_contents($headPath);
        self::assertIsString($head);
        $this->filesystem->dumpFile(
            $headPath,
            $head . "\n<script<?= \$headScriptNonceAttribute ?> "
                . "src=\"https://webda.eus/apis/cookielad/loader.js\"></script>\n"
        );
        $missing = $this->tester($project);

        self::assertSame(Command::FAILURE, $missing->execute([
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $missing->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'blog.public_shell_adoption.security_not_ready',
            $payload['error']['code']
        );
        self::assertStringNotContainsString(
            'webda.eus',
            $missing->getDisplay()
        );

        $this->filesystem->dumpFile(
            $project . '/App/config/modules/blog-public.php',
            <<<'PHP'
<?php

return [
    'security_sources' => [
        'script' => ['https://webda.eus'],
        'style' => ['https://webda.eus'],
        'image' => ['https://webda.eus'],
        'connect' => ['https://webda.eus'],
    ],
];
PHP
        );
        $authorized = $this->tester($project);

        self::assertSame(Command::SUCCESS, $authorized->execute([
            '--format' => 'json',
        ]));
    }

    public function testMissingImportedScssDependencyIsRejected(): void
    {
        $config = "<?php\nreturn [];\n";
        $project = $this->project('missing-scss-dependency', $config);
        $this->filesystem->remove(
            $project . '/src/scss/resources/_hero07.scss'
        );
        $tester = $this->tester($project);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'blog.public_shell_adoption.scaffold_dependency_missing',
            $payload['error']['code']
        );
        self::assertArrayNotHasKey('required_config', $payload['error']);
        self::assertSame($config, file_get_contents(
            $project . '/App/config/modules/blog.php'
        ));
    }

    public function testScaffoldFailureTextDoesNotSuggestConfigMutation(): void
    {
        $config = "<?php\nreturn [];\n";
        $project = $this->project('missing-scaffold-text', $config);
        $this->filesystem->remove($project . '/src/js/blogArticle.js');
        $tester = $this->tester($project);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--apply' => true,
            '--yes' => true,
        ]));
        self::assertStringContainsString(
            'blog.public_shell_adoption.scaffold_missing',
            $tester->getDisplay()
        );
        self::assertStringNotContainsString(
            'Configuracion requerida',
            $tester->getDisplay()
        );
        self::assertStringNotContainsString(
            "'public_article_view'",
            $tester->getDisplay()
        );
        self::assertSame($config, file_get_contents(
            $project . '/App/config/modules/blog.php'
        ));
    }

    public function testLocaleGrammarMatchesRuntimeAndNormalizesCatalogNames(): void
    {
        $cases = [
            'pt-br' => 'pt-br',
            'pt-BR' => 'pt-br',
            'zh-Hans' => 'zh-hans',
            'es-419' => 'es-419',
            'en-a1b2c3d4' => 'en-a1b2c3d4',
        ];

        $caseIndex = 0;
        foreach ($cases as $configuredLocale => $catalogLocale) {
            $project = $this->project(
                'locale-' . $caseIndex,
                "<?php\nreturn [];\n"
            );
            ++$caseIndex;
            $this->filesystem->dumpFile(
                $project . '/App/config/langs.php',
                "<?php\nreturn ['" . $configuredLocale . "'];\n"
            );
            $this->filesystem->dumpFile(
                $project . '/App/config/languages/global/'
                    . $catalogLocale . '.json',
                '{}'
            );
            $tester = $this->tester($project);

            self::assertSame(
                Command::SUCCESS,
                $tester->execute(['--format' => 'json']),
                $configuredLocale
            );
            $payload = json_decode(
                $tester->getDisplay(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            self::assertTrue($payload['ok'], $configuredLocale);
        }
    }

    public function testLocaleSuffixLongerThanRuntimeLimitIsRejected(): void
    {
        $config = "<?php\nreturn [];\n";
        $project = $this->project('locale-suffix-too-long', $config);
        $this->filesystem->dumpFile(
            $project . '/App/config/langs.php',
            "<?php\nreturn ['en-abcdefghi'];\n"
        );
        $tester = $this->tester($project);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'blog.public_shell_adoption.locale_config_invalid',
            $payload['error']['code']
        );
        self::assertArrayNotHasKey('required_config', $payload['error']);
        self::assertSame($config, file_get_contents(
            $project . '/App/config/modules/blog.php'
        ));
    }

    public function testMissingActiveLocaleCatalogBlocksMissingConfigCreation(): void
    {
        $project = $this->project('missing-active-catalog', null, false);
        $configPath = $project . '/App/config/modules/blog.php';
        $this->filesystem->dumpFile(
            $project . '/App/config/langs.php',
            "<?php\nreturn ['es', 'en']\n?>\n"
        );
        $tester = $this->tester($project);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'blog.public_shell_adoption.catalog_missing',
            $payload['error']['code']
        );
        self::assertSame(
            'App/config/languages/global/en.json',
            $payload['error']['path']
        );
        self::assertFileDoesNotExist($configPath);
    }

    public function testDynamicLocaleConfigIsRejectedWithoutExecution(): void
    {
        $config = "<?php\nreturn [];\n";
        $project = $this->project('dynamic-locales', $config);
        $marker = $project . '/App/config/locale-config-executed';
        $this->filesystem->dumpFile(
            $project . '/App/config/langs.php',
            "<?php\nfile_put_contents(__DIR__ . "
                . "'/locale-config-executed', 'yes');\nreturn ['es'];\n"
        );
        $tester = $this->tester($project);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'blog.public_shell_adoption.locale_config_invalid',
            $payload['error']['code']
        );
        self::assertSame('App/config/langs.php', $payload['error']['path']);
        self::assertFileDoesNotExist($marker);
        self::assertSame($config, file_get_contents(
            $project . '/App/config/modules/blog.php'
        ));
    }

    public function testCustomizedCompatibleShellIsAcceptedAndPreserved(): void
    {
        $config = "<?php\nreturn [];\n";
        $project = $this->project('custom-compatible', $config);
        $view = <<<'PHP'
<?php
declare(strict_types=1);
require_once(__DIR__ . '/../app/_moduleBlogPublicArticle.php');
?>
<!doctype html>
<html lang="<?= htmlspecialchars($lang ?? 'es') ?>"
      data-project-shell="custom"<?php if ($blogArticle->analyticsEnabled()): ?>
      data-blog-analytics-enabled="true"
      data-blog-analytics-retention-days="<?= $escape($blogArticle->analyticsRetentionDays()) ?>"
      data-blog-analytics-session-timeout="<?= $escape($blogArticle->analyticsSessionTimeoutSeconds()) ?>"
      data-blog-analytics-page-grant="<?= $escape($blogArticle->analyticsPageGrant()) ?>"<?php endif ?>>
<head data-project="custom">
    <?php include(__DIR__ . '/../includes/_globalHead.php') ?>
    <?php if ($articleCustomCss !== ''): ?>
        <style nonce="<?= $escape($cspNonce) ?>"><?php
            echo $articleCustomCss;
        ?></style>
    <?php endif ?>
    <script nonce="<?= $escape($cspNonce) ?>"
            src="<?= $escape($blogPublicRuntimeUrl) ?>"></script>
</head>
<body class="custom-blog-shell">
    <?php include_once(__DIR__ . '/../includes/_globalBody.php') ?>
    <div class="custom-navigation">
        <?php require(__DIR__ . '/../includes/_nav.php') ?>
    </div>
    <div id="smooth-wrapper">
        <div id="smooth-content">
            <?php echo $articleHero ?>
            <main class="custom-content">
                <?php
                // The project may keep its own rendering blocks and comments.
                echo $articleTaxonomiesHtml;
                echo $articleMain;
                ?>
            </main>
            <?php require_once(__DIR__ . '/../includes/_footer.php') ?>
        </div>
    </div>
</body>
</html>
PHP;
        $javascript = <<<'JS'
import '../scss/blogArticle.scss';
import './_global.js';
import './projectEnhancement.js';
import { bindLanguageNavigation as bindProjectLanguages } from './resources/_languagePreference.mjs';

const stopProjectLanguages = bindProjectLanguages(window, document);
export { stopProjectLanguages };
JS;
        $scss = $this->validArticleScss()
            . "\n@use './project/brand';\n.custom-blog-shell { padding: 0; }\n";
        $viewPath = $project . '/App/views/blog-article.php';
        $javascriptPath = $project . '/src/js/blogArticle.js';
        $scssPath = $project . '/src/scss/blogArticle.scss';
        $this->filesystem->dumpFile($viewPath, $view);
        $this->filesystem->dumpFile($javascriptPath, $javascript);
        $this->filesystem->dumpFile(
            $project . '/src/js/projectEnhancement.js',
            "export const projectEnhancement = true;\n"
        );
        $this->filesystem->dumpFile(
            $project . '/src/scss/project/_brand.scss',
            '.project-brand {}'
        );
        $this->filesystem->dumpFile($scssPath, $scss);
        $tester = $this->tester($project);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('activated', $payload['result']['status']);
        self::assertSame($view, file_get_contents($viewPath));
        self::assertSame($javascript, file_get_contents($javascriptPath));
        self::assertSame($scss, file_get_contents($scssPath));
    }

    private function project(
        string $name,
        ?string $config,
        bool $createModulesDirectory = true
    ): string {
        $project = $this->sandbox . '/' . $name;
        $directories = [
            $project . '/App/config',
            $project . '/App/config/languages/global',
            $project . '/App/views',
            $project . '/App/app',
            $project . '/App/includes',
            $project . '/App/controllers',
            $project . '/App/templates',
            $project . '/src/js',
            $project . '/src/js/resources',
            $project . '/src/scss',
            $project . '/src/scss/resources',
            $project . '/public/assets/modules/blog',
        ];
        if ($createModulesDirectory) {
            $directories[] = $project . '/App/config/modules';
        }
        $this->filesystem->mkdir($directories);
        $this->filesystem->dumpFile(
            $project . '/composer.json',
            json_encode([
                'require' => [
                    'liquidstack/core' => '^1.0',
                    'liquidstack/blog' => '*',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
        foreach ([
            'App/views/blog-article.php' => $this->validArticleView(),
            'src/js/blogArticle.js' => $this->validArticleJavascript(),
            'src/scss/blogArticle.scss' => $this->validArticleScss(),
            'App/app/_moduleBlogPublicArticle.php' =>
                $this->validArticleHook(),
            'public/assets/modules/blog/blog-public.js' =>
                'window.LiquidStackBlog = {};',
            'App/includes/_globalHead.php' => $this->validGlobalHead(),
            'App/includes/_globalBody.php' => '<div>global body</div>',
            'App/includes/_nav.php' => '<nav>navigation</nav>',
            'App/includes/_footer.php' => '<footer>footer</footer>',
            'App/controllers/_moduleBlogResources.php' => '<?php',
            'App/controllers/sectionBlogRelated01.php' => '<?php',
            'App/controllers/moduleButtonType04.php' => '<?php',
            'App/templates/_sectionBlogRelated01.html' => '<section></section>',
            'App/templates/_moduleButtonType04.html' => '<a></a>',
            'src/js/_global.js' => "export const globalReady = true;\n",
            'src/js/resources/_languagePreference.mjs' =>
                "export function bindLanguageNavigation() { return () => {}; }\n",
            'src/scss/_config.scss' => '$color: #000;',
            'src/scss/_global.scss' => 'body { margin: 0; }',
            'src/scss/resources/_hero00.scss' => '.hero00 {}',
            'src/scss/resources/_hero06.scss' => '.hero06 {}',
            'src/scss/resources/_hero07.scss' => '.hero07 {}',
            'src/scss/resources/_moduleH1Type01.scss' => '.moduleH1Type01 {}',
            'src/scss/resources/_moduleH1Type03.scss' => '.moduleH1Type03 {}',
            'src/scss/resources/_moduleH1Type04.scss' => '.moduleH1Type04 {}',
            'src/scss/resources/_artBlogArticle01.scss' => '.article {}',
            'src/scss/resources/_sectionBlogRelated01.scss' => '.related {}',
            'src/scss/resources/_moduleButtonType04.scss' => '.button {}',
            'App/config/langs.php' => "<?php\nreturn ['es']\n?>\n",
            'App/config/languages/global/es.json' => '{}',
        ] as $relativePath => $contents) {
            $this->filesystem->dumpFile(
                $project . '/' . $relativePath,
                $contents
            );
        }
        if ($config !== null) {
            $this->filesystem->dumpFile(
                $project . '/App/config/modules/blog.php',
                $config
            );
        }

        return $project;
    }

    private function validArticleView(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

require __DIR__ . '/../app/_moduleBlogPublicArticle.php';
?>
<!DOCTYPE html>
<html lang="en"<?php if ($blogArticle->analyticsEnabled()): ?>
      data-blog-analytics-enabled="true"
      data-blog-analytics-retention-days="<?= $escape($blogArticle->analyticsRetentionDays()) ?>"
      data-blog-analytics-session-timeout="<?= $escape($blogArticle->analyticsSessionTimeoutSeconds()) ?>"
      data-blog-analytics-page-grant="<?= $escape($blogArticle->analyticsPageGrant()) ?>"<?php endif ?>>
<head>
    <?php include_once __DIR__ . '/../includes/_globalHead.php' ?>
    <style nonce="<?= $escape($cspNonce) ?>"><?= $articleCustomCss ?></style>
    <script nonce="<?= $escape($cspNonce) ?>"
            src="<?= $escape($blogPublicRuntimeUrl) ?>"></script>
</head>
<body class="project-customization">
    <?php include_once __DIR__ . '/../includes/_globalBody.php' ?>
    <?php include __DIR__ . '/../includes/_nav.php' ?>
    <div id="smooth-wrapper">
        <div id="smooth-content">
            <?= $articleHero ?>
            <main>
                <?= $articleTaxonomiesHtml ?>
                <?= $articleMain ?>
            </main>
            <?php include __DIR__ . '/../includes/_footer.php' ?>
        </div>
    </div>
</body>
</html>
PHP;
    }

    private function validArticleHook(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use App\Core\Blog\Http\BlogPublicArticleShellContext;
use App\Core\Blog\Http\BlogPublicArticleTaxonomyRenderer;
use App\Core\Blog\Http\BlogPublicArticleViewModel;

$pageMeta = [
    'headline' => $blogArticle->h1(),
];
$cspNonce = $blogArticleShell->nonce();
$blogPublicRuntimeUrl = $blogArticleShell->publicRuntimeUrl();
$articleHero = $blogArticle->headerHtml();
$articleMain = $blogArticle->mainHtml();
$articleCustomCss = $blogArticle->customCss();
$articleCategories = $blogArticle->categories();
$articleTags = $blogArticle->tags();
$articleTaxonomiesHtml = (new BlogPublicArticleTaxonomyRenderer())->render(
    $blogArticle,
    []
);
$relatedArticles = $blogArticle->relatedArticles();
PHP;
    }

    private function validArticleJavascript(): string
    {
        return <<<'JS'
import '../scss/blogArticle.scss';
import './_global.js';
import { bindLanguageNavigation } from './resources/_languagePreference.mjs';

const unbind = bindLanguageNavigation(window, document);
export { unbind };
JS;
    }

    private function validArticleScss(): string
    {
        return <<<'SCSS'
@use './config' as c;
@use './global';
@use './resources/hero00';
@use './resources/hero06';
@use './resources/hero07';
@use './resources/artBlogArticle01';
@use './resources/sectionBlogRelated01';
@use './resources/moduleButtonType04';

body.blog-article-page { color: c.$color; }
SCSS;
    }

    private function validGlobalHead(): string
    {
        return <<<'PHP'
<?php
$resolvedPageMeta = [
    $pageMeta['title'] ?? '',
    $pageMeta['headline'] ?? '',
    $pageMeta['description'] ?? '',
    $pageMeta['canonical'] ?? '',
    $pageMeta['alternates'] ?? [],
    $pageMeta['x_default'] ?? null,
    $pageMeta['type'] ?? 'website',
    $pageMeta['image'] ?? null,
    $pageMeta['published_at'] ?? null,
    $pageMeta['updated_at'] ?? null,
];
$headCspNonce = isset($cspNonce) && is_string($cspNonce)
    ? $cspNonce
    : null;
$headScriptNonceAttribute = $headCspNonce === null
    ? ''
    : ' nonce="' . htmlspecialchars($headCspNonce) . '"';
?>
<title><?= htmlspecialchars((string) $resolvedPageMeta[0]) ?></title>
<script<?= $headScriptNonceAttribute ?>>window.projectReady = true;</script>
PHP;
    }

    private function tester(string $project): CommandTester
    {
        $command = new BlogPublicShellAdoptionCommand($project);
        $application = new ComposerApplication();
        $application->setAutoExit(false);
        $application->add($command);

        return new CommandTester($command);
    }
}
