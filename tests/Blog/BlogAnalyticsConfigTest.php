<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Core\Blog\Configuration\BlogAnalyticsConfig;
use App\Core\Blog\Configuration\BlogConfigException;
use App\Core\Blog\Configuration\BlogConfigLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogAnalyticsConfigTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir() . '/liquidstack-blog-analytics-config-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->root . '/App/config/modules');
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testDefaultsAreExplicitlyDisabledAndBounded(): void
    {
        $config = (new BlogConfigLoader())->load($this->root, ['es']);

        self::assertFalse($config->analytics()->enabled());
        self::assertFalse($config->analytics()->collectInDevelopment());
        self::assertSame(90, $config->analytics()->retentionDays());
        self::assertSame(1800, $config->analytics()->sessionTimeoutSeconds());
    }

    public function testProjectCanOptInWithValidatedRetention(): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/blog.php',
            <<<'PHP'
<?php
return [
    'analytics' => [
        'enabled' => true,
        'retention_days' => 120,
        'session_timeout_seconds' => 2400,
        'collect_in_dev' => true,
    ],
];
PHP
        );

        $analytics = (new BlogConfigLoader())
            ->load($this->root, ['es'])
            ->analytics();

        self::assertTrue($analytics->enabled());
        self::assertTrue($analytics->collectInDevelopment());
        self::assertSame(120, $analytics->retentionDays());
        self::assertSame(2400, $analytics->sessionTimeoutSeconds());
    }

    public function testInvalidRetentionFailsClosed(): void
    {
        $this->expectException(BlogConfigException::class);

        new BlogAnalyticsConfig(true, 29);
    }
}
