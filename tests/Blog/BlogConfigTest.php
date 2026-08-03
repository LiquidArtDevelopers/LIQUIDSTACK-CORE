<?php

declare(strict_types=1);

use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogConfigException;
use App\Core\Blog\Configuration\BlogConfigLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogConfigTest extends TestCase
{
    private string $fixtureRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-blog-config-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->fixtureRoot);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testMissingProjectFileUsesDeterministicLanguageDefaults(): void
    {
        $config = $this->load(['es', 'eu', 'en']);

        self::assertSame('defaults', $config->source());
        self::assertSame([
            'es' => '/blog',
            'eu' => '/eu/blog',
            'en' => '/en/blog',
        ], $config->publicPaths());
        self::assertSame('/blog-sitemap.xml', $config->sitemapPath());
        self::assertSame('shared', $config->databaseConnection());
        self::assertSame('ls_blog_', $config->tablePrefix());
        self::assertNull($config->publicArticleView());
        self::assertNull($config->publicArticleViewPath());
        self::assertSame('/eu/blog', $config->publicPath('EU'));
        self::assertNull($config->publicPath('fr'));
        self::assertLessThanOrEqual(
            BlogConfig::MYSQL_IDENTIFIER_MAX_LENGTH,
            strlen(
                $config->tablePrefix()
                . BlogConfig::LONGEST_TABLE_SUFFIX
            )
        );
    }

    public function testProjectCanOverrideOnlyThePublicContract(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

return [
    'public_paths' => [
        'es' => '/noticias',
        'eu' => '/eu/albisteak',
        'en' => '/en/news',
    ],
    'sitemap_path' => '/news-sitemap.xml',
    'database' => [
        'connection' => 'shared',
        'table_prefix' => 'client_blog_',
    ],
];
PHP);

        $config = $this->load(['es', 'eu', 'en']);

        self::assertSame('project', $config->source());
        self::assertSame('/noticias', $config->publicPath('es'));
        self::assertSame('es', $config->defaultLocale());
        self::assertSame('/news-sitemap.xml', $config->sitemapPath());
        self::assertSame('client_blog_', $config->tablePrefix());
        self::assertSame($config->toSafeArray(), [
            'source' => 'project',
            'public_paths' => [
                'es' => '/noticias',
                'eu' => '/eu/albisteak',
                'en' => '/en/news',
            ],
            'sitemap_path' => '/news-sitemap.xml',
            'database' => [
                'connection' => 'shared',
                'table_prefix' => 'client_blog_',
            ],
            'sitemap_cache' => [
                'enabled' => false,
                'ttl_seconds' => 300,
            ],
        ]);
    }

    public function testProjectCanSelectDedicatedLiquidStackConnection(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

return [
    'public_paths' => [
        'es' => '/noticias',
        'en' => '/en/news',
    ],
    'database' => [
        'connection' => 'liquidstack',
        'table_prefix' => 'client_blog_',
    ],
];
PHP);

        $config = $this->load(['es', 'en']);

        self::assertSame('liquidstack', $config->databaseConnection());
        self::assertSame('liquidstack', $config->toSafeArray()[
            'database'
        ]['connection']);
        self::assertSame('client_blog_', $config->tablePrefix());
    }

    public function testProjectCanSelectARegularContainedPublicArticleView(): void
    {
        $view = $this->fixtureRoot
            . '/App/views/blog/public-article.php';
        $this->filesystem->dumpFile(
            $view,
            '<?php echo "article";' . "\n"
        );
        $this->writeConfig(<<<'PHP'
<?php

return [
    'public_article_view' => 'App/views/blog/public-article.php',
];
PHP);

        $config = $this->load(['es', 'en']);

        self::assertSame(
            'App/views/blog/public-article.php',
            $config->publicArticleView()
        );
        self::assertSame(realpath($view), $config->publicArticleViewPath());
        self::assertSame(
            'App/views/blog/public-article.php',
            $config->toSafeArray()['public_article_view']
        );
        self::assertSame(
            'shared',
            (new BlogConfigLoader())->databaseConnection($this->fixtureRoot)
        );
    }

    public function testDefaultLocaleFollowsLanguageConfigurationOrder(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

return [
    'public_paths' => [
        'en' => '/en/news',
        'es' => '/noticias',
        'eu' => '/eu/albisteak',
    ],
];
PHP);

        $config = $this->load(['es', 'eu', 'en']);

        self::assertSame('es', $config->defaultLocale());
    }

    public function testDatabaseConnectionCanBeResolvedWithoutLanguageCatalog(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

return [
    'database' => [
        'connection' => 'liquidstack',
    ],
];
PHP);

        self::assertFileDoesNotExist(
            $this->fixtureRoot . '/App/config/langs.php'
        );
        self::assertSame(
            'liquidstack',
            (new BlogConfigLoader())->databaseConnection($this->fixtureRoot)
        );
    }

    public function testOmittedBlocksKeepDefaults(): void
    {
        $this->writeConfig("<?php\n\nreturn [];\n");

        $config = $this->load(['es', 'en']);

        self::assertSame('project', $config->source());
        self::assertSame([
            'es' => '/blog',
            'en' => '/en/blog',
        ], $config->publicPaths());
        self::assertSame('/blog-sitemap.xml', $config->sitemapPath());
        self::assertSame('ls_blog_', $config->tablePrefix());
    }

    public function testSitemapCacheIsAnExplicitBoundedOptIn(): void
    {
        $this->writeConfig(<<<'PHP'
<?php
return [
    'sitemap_cache' => [
        'enabled' => true,
        'ttl_seconds' => 600,
    ],
];
PHP);

        $cache = $this->load(['es'])->sitemapCache();
        self::assertTrue($cache->enabled());
        self::assertSame(600, $cache->ttlSeconds());

        $this->writeConfig(<<<'PHP'
<?php
return ['sitemap_cache' => ['enabled' => 'yes']];
PHP);
        $this->expectException(BlogConfigException::class);
        $this->load(['es']);
    }

    /**
     * @dataProvider invalidConfigurationProvider
     * @param array<string, mixed> $configuration
     */
    public function testInvalidConfigurationFailsClosed(
        array $configuration,
        string $issue,
        string $key
    ): void {
        $this->writeConfig(
            "<?php\n\nreturn " . var_export($configuration, true) . ";\n"
        );

        try {
            $this->load(['es', 'en']);
            self::fail('Invalid Blog configuration must fail closed.');
        } catch (BlogConfigException $exception) {
            self::assertSame($issue, $exception->issueCode());
            self::assertSame($key, $exception->configKey());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'unknown root key' => [
            ['secret' => 'value'],
            'config.unknown_key',
            'config.secret',
        ];
        yield 'view must be relative to project root' => [[
            'public_article_view' => '/App/views/blog/article.php',
        ], 'config.invalid_public_article_view', 'public_article_view'];
        yield 'view must live below App views' => [[
            'public_article_view' => 'templates/article.php',
        ], 'config.invalid_public_article_view', 'public_article_view'];
        yield 'view traversal is rejected' => [[
            'public_article_view' => 'App/views/../config/secret.php',
        ], 'config.invalid_public_article_view', 'public_article_view'];
        yield 'view backslashes are rejected' => [[
            'public_article_view' => 'App\\views\\article.php',
        ], 'config.invalid_public_article_view', 'public_article_view'];
        yield 'missing view is rejected' => [[
            'public_article_view' => 'App/views/blog/missing.php',
        ], 'config.public_article_view_not_regular', 'public_article_view'];
        yield 'null view is rejected' => [[
            'public_article_view' => null,
        ], 'config.invalid_public_article_view', 'public_article_view'];
        yield 'unknown locale' => [[
            'public_paths' => [
                'es' => '/noticias',
                'en' => '/en/news',
                'fr' => '/fr/actualites',
            ],
        ], 'config.unknown_language', 'public_paths'];
        yield 'missing active locale' => [[
            'public_paths' => ['es' => '/noticias'],
        ], 'config.language_route_missing', 'public_paths.en'];
        yield 'duplicate path' => [[
            'public_paths' => [
                'es' => '/news',
                'en' => '/news',
            ],
        ], 'config.duplicate_route', 'public_paths.en'];
        yield 'nested public path' => [[
            'public_paths' => [
                'es' => '/news',
                'en' => '/news/en',
            ],
        ], 'config.nested_public_path', 'public_paths.en'];
        yield 'query is rejected' => [[
            'public_paths' => [
                'es' => '/noticias?all=1',
                'en' => '/en/news',
            ],
        ], 'config.invalid_path', 'public_paths.es'];
        yield 'uppercase is rejected' => [[
            'public_paths' => [
                'es' => '/Noticias',
                'en' => '/en/news',
            ],
        ], 'config.invalid_path', 'public_paths.es'];
        yield 'sitemap cannot equal a public path' => [[
            'public_paths' => [
                'es' => '/blog-sitemap.xml',
                'en' => '/en/news',
            ],
        ], 'config.duplicate_route', 'sitemap_path'];
        yield 'sitemap cannot live under a public route' => [[
            'public_paths' => [
                'es' => '/news',
                'en' => '/en/news',
            ],
            'sitemap_path' => '/news/sitemap.xml',
        ], 'config.nested_sitemap_path', 'public_paths.es'];
        yield 'separate connection is rejected' => [[
            'database' => ['connection' => 'blog'],
        ], 'config.unsupported_database_connection', 'database.connection'];
        yield 'invalid prefix' => [[
            'database' => ['table_prefix' => 'Blog_'],
        ], 'config.invalid_table_prefix', 'database.table_prefix'];
    }

    public function testInvalidLanguageCatalogAndEmptyCatalogFailClosed(): void
    {
        foreach ([[], ['es', 'invalid_locale'], ['es', 'ES']] as $languages) {
            try {
                $this->load($languages);
                self::fail('Invalid languages must fail closed.');
            } catch (BlogConfigException $exception) {
                self::assertContains($exception->issueCode(), [
                    'config.languages_missing',
                    'config.invalid_language',
                    'config.duplicate_language',
                ]);
            }
        }
    }

    public function testMaximumPrefixRespectsMysqlIdentifierBudget(): void
    {
        $prefix = str_repeat(
            'a',
            BlogConfig::MAX_TABLE_PREFIX_LENGTH - 1
        ) . '_';
        $this->writeConfig("<?php\n\nreturn " . var_export([
            'database' => ['table_prefix' => $prefix],
        ], true) . ";\n");

        self::assertSame($prefix, $this->load(['es'])->tablePrefix());

        $this->writeConfig("<?php\n\nreturn " . var_export([
            'database' => ['table_prefix' => str_repeat(
                'a',
                BlogConfig::MAX_TABLE_PREFIX_LENGTH
            ) . '_'],
        ], true) . ";\n");
        $this->expectException(BlogConfigException::class);
        $this->load(['es']);
    }

    public function testConfigFileCannotEmitOutputOrBeASymlink(): void
    {
        $this->writeConfig("<?php\necho 'leak';\nreturn [];\n");
        try {
            $this->load(['es']);
            self::fail('Output should be rejected.');
        } catch (BlogConfigException $exception) {
            self::assertSame(
                'config.project_file_emitted_output',
                $exception->issueCode()
            );
        }

        $config = $this->fixtureRoot . '/' . BlogConfig::PROJECT_CONFIG_PATH;
        $target = $this->fixtureRoot . '/external-blog.php';
        $this->filesystem->dumpFile($target, "<?php return [];\n");
        $this->filesystem->remove($config);
        if (@symlink($target, $config)) {
            try {
                $this->load(['es']);
                self::fail('Symlink configuration should be rejected.');
            } catch (BlogConfigException $exception) {
                self::assertSame(
                    'config.project_file_not_regular',
                    $exception->issueCode()
                );
            }
        }
    }

    public function testPublicArticleViewCannotContainASymlink(): void
    {
        $external = $this->fixtureRoot . '/external-view.php';
        $link = $this->fixtureRoot . '/App/views/blog/article.php';
        $this->filesystem->dumpFile($external, '<?php echo "external";');
        $this->filesystem->mkdir(dirname($link));
        if (!@symlink($external, $link)) {
            self::markTestSkipped(
                'This environment cannot create a file symlink.'
            );
        }
        $this->writeConfig(<<<'PHP'
<?php
return ['public_article_view' => 'App/views/blog/article.php'];
PHP);

        try {
            $this->load(['es']);
            self::fail('A linked article view must fail closed.');
        } catch (BlogConfigException $exception) {
            self::assertSame(
                'config.public_article_view_not_regular',
                $exception->issueCode()
            );
            self::assertSame(
                'public_article_view',
                $exception->configKey()
            );
        }
    }

    /** @param list<string> $languages */
    private function load(array $languages): BlogConfig
    {
        return (new BlogConfigLoader())->load(
            $this->fixtureRoot,
            $languages
        );
    }

    private function writeConfig(string $contents): void
    {
        $path = $this->fixtureRoot . '/' . BlogConfig::PROJECT_CONFIG_PATH;
        $this->filesystem->mkdir(dirname($path));
        $this->filesystem->dumpFile($path, $contents);
    }
}
