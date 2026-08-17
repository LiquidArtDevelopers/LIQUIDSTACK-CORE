<?php

declare(strict_types=1);

use App\Core\Blog\PublicFeed\BlogPublicCollectionResolver;
use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogPublicCollectionsAdapterContractTest extends TestCase
{
    private string $adapter;

    protected function setUp(): void
    {
        $this->adapter = dirname(__DIR__, 2)
            . '/modules/blog/resources/project/App/app/'
            . '_moduleBlogPublicCollections.php';
    }

    public function testManagedHookIsGenericAndInfrastructureFree(): void
    {
        $source = (string) file_get_contents($this->adapter);

        self::assertStringContainsString(
            '$blogPublicCollections = BlogPublicCollectionResolver::current();',
            $source
        );
        foreach ([
            'BlogPublicResourceFeed',
            'BlogPublicResourceQuery',
            'try {',
            'catch (',
            'new PDO',
            'controller(',
            '<!DOCTYPE',
            '$_GET',
            '$_POST',
            '$GLOBALS',
            'project_specific_brand',
            'home_blog_',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    public function testManagedHookExposesAFailClosedTypedResolverWithoutOutput(): void
    {
        $filesystem = new Filesystem();
        $fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-blog-public-collections-hook-'
            . bin2hex(random_bytes(8));
        $filesystem->mkdir($fixtureRoot);
        $previousRoot = Paths::projectRoot();
        Paths::setProjectRoot($fixtureRoot);
        $blogPublicCollections = null;

        ob_start();
        try {
            require $this->adapter;
            $output = (string) ob_get_contents();

            self::assertInstanceOf(
                BlogPublicCollectionResolver::class,
                $blogPublicCollections
            );
            self::assertSame('', $output);
            self::assertTrue(
                $blogPublicCollections->latest('es', 1)->isUnavailable()
            );
        } finally {
            ob_end_clean();
            Paths::setProjectRoot($previousRoot);
            $filesystem->remove($fixtureRoot);
        }
    }
}
