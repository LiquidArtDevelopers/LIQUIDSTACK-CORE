<?php

declare(strict_types=1);

use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Configuration\BlogSitemapCacheConfig;
use App\Core\Blog\Http\BlogSitemapRenderer;
use App\Core\Blog\Sitemap\Cache\BlogSitemapCacheException;
use App\Core\Blog\Sitemap\Cache\BlogSitemapCacheIdentity;
use App\Core\Blog\Sitemap\Cache\BlogSitemapCacheSnapshot;
use App\Core\Blog\Sitemap\Cache\PrivateBlogSitemapCacheStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogSitemapCacheStorageTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;
    private bool $documentRootWasSet;
    private mixed $previousDocumentRoot;
    /** @var array<string, string> */
    private array $environment;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir() . '/ls-blog-cache-test-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir([$this->root, $this->root . '/www']);
        $this->documentRootWasSet = array_key_exists('DOCUMENT_ROOT', $_SERVER);
        $this->previousDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $this->environment = [
            'RAIZ' => 'http://localhost:1309',
            'DEV_MODE' => '1',
        ];
    }

    protected function tearDown(): void
    {
        if ($this->documentRootWasSet) {
            $_SERVER['DOCUMENT_ROOT'] = $this->previousDocumentRoot;
        } else {
            unset($_SERVER['DOCUMENT_ROOT']);
        }
        if (isset($this->filesystem, $this->root)) {
            $this->filesystem->remove($this->root);
        }
    }

    public function testLocalInitializationIsExplicitPrivateAndIdempotent(): void
    {
        $storage = PrivateBlogSitemapCacheStorage::forProject(
            $this->root,
            $this->environment
        );
        self::assertFalse($storage->diagnostic()['ready']);
        $first = $storage->initialize();
        $second = $storage->initialize();
        self::assertTrue($first->changed());
        self::assertFalse($second->changed());
        self::assertSame($first->generation(), $second->generation());
        self::assertTrue($storage->diagnostic()['ready']);
        self::assertFileDoesNotExist($this->root . '/public/sitemap.xml');
    }

    public function testProductionNeedsAnExplicitRoot(): void
    {
        $this->expectException(BlogSitemapCacheException::class);
        PrivateBlogSitemapCacheStorage::forProject($this->root, [
            'RAIZ' => 'https://example.test',
            'DEV_MODE' => '0',
        ]);
    }

    public function testProductionAllowsCanonicalCacheOutsideDocumentRoot(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->root . '/www';
        $cacheRoot = $this->root
            . '/storage/liquidstack/blog/sitemap-cache';

        $storage = PrivateBlogSitemapCacheStorage::forProject($this->root, [
            'RAIZ' => 'https://example.test',
            'DEV_MODE' => '0',
            PrivateBlogSitemapCacheStorage::ROOT_ENV => $cacheRoot,
        ]);

        $storage->initialize();
        self::assertDirectoryExists($cacheRoot);
    }

    public function testProductionRejectsCacheOverlappingDocumentRoot(): void
    {
        $cacheRoot = $this->root
            . '/storage/liquidstack/blog/sitemap-cache';
        $_SERVER['DOCUMENT_ROOT'] = $this->root;

        try {
            PrivateBlogSitemapCacheStorage::forProject($this->root, [
                'RAIZ' => 'https://example.test',
                'DEV_MODE' => '0',
                PrivateBlogSitemapCacheStorage::ROOT_ENV => $cacheRoot,
            ]);
            self::fail('Cache storage cannot overlap the document root.');
        } catch (BlogSitemapCacheException $exception) {
            self::assertSame(
                'blog.sitemap_cache.storage_root_dangerous',
                $exception->issueCode()
            );
        }

        $_SERVER['DOCUMENT_ROOT'] = $this->root . '/www';
        try {
            PrivateBlogSitemapCacheStorage::forProject($this->root, [
                'RAIZ' => 'https://example.test',
                'DEV_MODE' => '0',
                PrivateBlogSitemapCacheStorage::ROOT_ENV => $this->root
                    . '/www/private-sitemap-cache',
            ]);
            self::fail('Cache below the document root must be rejected.');
        } catch (BlogSitemapCacheException $exception) {
            self::assertSame(
                'blog.sitemap_cache.storage_root_dangerous',
                $exception->issueCode()
            );
        }
    }

    public function testProductionAllowsExternalPrivateCache(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->root . '/www';
        $cacheRoot = $this->root . '-persistent-cache';

        $storage = PrivateBlogSitemapCacheStorage::forProject($this->root, [
            'RAIZ' => 'https://example.test',
            'DEV_MODE' => '0',
            PrivateBlogSitemapCacheStorage::ROOT_ENV => $cacheRoot,
        ]);

        $storage->initialize();
        self::assertDirectoryExists($cacheRoot);
        $this->filesystem->remove($cacheRoot);
    }

    public function testSnapshotIsBoundedByIdentityExpiryAndDurableFence(): void
    {
        $storage = PrivateBlogSitemapCacheStorage::forProject(
            $this->root,
            $this->environment
        );
        $generation = $storage->initialize()->generation();
        $identity = $this->identity('/blog');
        $xml = "<?xml version=\"1.0\"?><urlset></urlset>\n";
        $snapshot = BlogSitemapCacheSnapshot::fresh(
            $xml,
            7,
            $generation,
            $identity,
            1_700_000_000,
            300
        );
        $lease = $storage->acquireExclusive();
        $storage->promote($lease, $snapshot);
        $lease->release();

        self::assertSame(
            $snapshot->etag(),
            $storage->readValid($identity, 1_700_000_100, 7, $generation)
                ?->etag()
        );
        self::assertNull($storage->readValid(
            $this->identity('/news'),
            1_700_000_100
        ));
        self::assertNull($storage->readValid($identity, 1_700_000_300));
        self::assertNull($storage->readValid($identity, 1_700_000_301));

        $lease = $storage->acquireExclusive();
        $storage->block($lease, $generation, 7);
        $lease->release();
        self::assertNull($storage->readValid($identity, 1_700_000_100));
        self::assertSame('blocked', $storage->diagnostic()['status']);

        $replacement = BlogSitemapCacheSnapshot::fresh(
            $xml . "<!-- fresh -->\n",
            8,
            $generation,
            $identity,
            1_700_000_200,
            300
        );
        $lease = $storage->acquireExclusive();
        $storage->promote($lease, $replacement);
        $lease->release();
        self::assertSame(
            8,
            $storage->readValid($identity, 1_700_000_250)?->publicRevision()
        );
        self::assertSame('ready', $storage->diagnostic()['status']);
    }

    public function testExistingDurableFenceIsNeverReplacedByAnotherBlock(): void
    {
        $storage = PrivateBlogSitemapCacheStorage::forProject(
            $this->root,
            $this->environment
        );
        $generation = $storage->initialize()->generation();
        $blockedPath = $this->root
            . '/storage/liquidstack/blog/sitemap-cache/.blocked';

        $lease = $storage->acquireExclusive();
        $storage->block($lease, $generation, 7);
        $lease->release();
        $first = file_get_contents($blockedPath);
        self::assertIsString($first);

        $lease = $storage->acquireExclusive();
        $storage->block($lease, $generation, 8);
        $lease->release();
        $second = file_get_contents($blockedPath);

        self::assertSame($first, $second);
        self::assertSame('blocked', $storage->diagnostic()['status']);
    }

    public function testIdentityChangesWhenTheManagedTablePrefixChanges(): void
    {
        self::assertNotSame(
            $this->identity('/blog', 'ls_blog_')->hash(),
            $this->identity('/blog', 'client_blog_')->hash()
        );
    }

    public function testSnapshotFromPreviousEligibilityContractIsRejected(): void
    {
        $storage = PrivateBlogSitemapCacheStorage::forProject(
            $this->root,
            $this->environment
        );
        $generation = $storage->initialize()->generation();
        $identity = $this->identity('/blog');
        $legacyIdentityHash = $this->legacyIdentityHash('/blog');
        $xml = "<?xml version=\"1.0\"?><urlset></urlset>\n";
        $snapshot = new BlogSitemapCacheSnapshot(
            $xml,
            '"' . hash('sha256', $xml) . '"',
            7,
            $generation,
            $legacyIdentityHash,
            1_700_000_000,
            1_700_000_300
        );
        $lease = $storage->acquireExclusive();
        $storage->promote($lease, $snapshot);
        $lease->release();

        self::assertNotSame($legacyIdentityHash, $identity->hash());
        self::assertNull(
            $storage->readValid($identity, 1_700_000_100, 7, $generation)
        );
    }

    public function testInitializationCleansBoundedCrashStaging(): void
    {
        $storage = PrivateBlogSitemapCacheStorage::forProject(
            $this->root,
            $this->environment
        );
        $storage->initialize();
        $stale = $this->root
            . '/storage/liquidstack/blog/sitemap-cache/.staging/'
            . '123e4567-e89b-42d3-a456-426614174000';
        $this->filesystem->mkdir($stale);
        $this->filesystem->dumpFile(
            $stale . '/sitemap.xml',
            '<?xml version="1.0"?>'
        );

        self::assertFalse($storage->initialize()->changed());
        self::assertDirectoryDoesNotExist($stale);
    }

    private function identity(
        string $path,
        string $tablePrefix = 'ls_blog_'
    ): BlogSitemapCacheIdentity
    {
        return BlogSitemapCacheIdentity::fromContract(
            $this->config($path, $tablePrefix),
            BlogPublicOrigin::fromEnvironment($this->environment),
            hash('sha256', 'database-a')
        );
    }

    private function legacyIdentityHash(
        string $path,
        string $tablePrefix = 'ls_blog_'
    ): string {
        $config = $this->config($path, $tablePrefix);
        $origin = BlogPublicOrigin::fromEnvironment($this->environment);
        $payload = json_encode([
            'schema' => 2,
            'renderer' => BlogSitemapRenderer::CONTRACT_VERSION,
            'origin' => $origin->value(),
            'public_paths' => $config->publicPaths(),
            'default_locale' => $config->defaultLocale(),
            'sitemap_path' => $config->sitemapPath(),
            'table_prefix' => $config->tablePrefix(),
            'ttl_seconds' => $config->sitemapCache()->ttlSeconds(),
            'database_identity' => hash('sha256', 'database-a'),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }

    private function config(
        string $path,
        string $tablePrefix = 'ls_blog_'
    ): BlogConfig {
        return new BlogConfig(
            ['es' => $path],
            '/blog-sitemap.xml',
            $tablePrefix,
            'test',
            'shared',
            'es',
            null,
            new BlogSitemapCacheConfig(true, 300)
        );
    }
}
