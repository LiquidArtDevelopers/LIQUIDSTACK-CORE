<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class BlogPublicIndexAdapterContractTest extends TestCase
{
    private string $coreRoot;
    private string $adapter;
    private string $fixtureRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->coreRoot = dirname(__DIR__, 2);
        $this->adapter = $this->coreRoot
            . '/modules/blog/resources/project/App/app/'
            . '_moduleBlogPublicIndex.php';
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-blog-index-adapter-'
            . bin2hex(random_bytes(8));
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->fixtureRoot . '/App/config');
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/langs.php',
            "<?php\n\nreturn ['es'];\n"
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/.env',
            "RAIZ=https://example.test\nDEV_MODE=0\n"
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testManagedAdapterOwnsTheWholePreDocumentBootstrap(): void
    {
        $source = (string) file_get_contents($this->adapter);

        self::assertStringContainsString(
            'BlogPublicIndexBootstrapOptions::fromProject(',
            $source
        );
        self::assertStringContainsString(
            'BlogPublicIndexBootstrap::current()->resolve(',
            $source
        );
        self::assertStringContainsString('$blogIndex =', $source);
        self::assertStringContainsString('$robots->content =', $source);
        self::assertStringContainsString('$pageMeta = array_replace(', $source);
        self::assertStringContainsString('$languageAlternates =', $source);
        self::assertStringContainsString('$cspNonce =', $source);
        self::assertStringContainsString('->response()->emit()', $source);
        self::assertStringContainsString('->shouldTerminate()', $source);
        self::assertMatchesRegularExpression('/\bexit\s*;/', $source);
        self::assertStringContainsString(
            'App/config/modules/blog-public-index.php',
            $source
        );

        foreach ([
            'return static function',
            '<!DOCTYPE',
            'controller(',
            'new PDO',
            'qa_matrix',
            'project_specific_brand',
            'blogQaPaginationPreview',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    public function testGetLeavesOneReadyPageAndSeoGlobalsForTheView(): void
    {
        $process = $this->runAdapter('GET', '/blog');

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('unavailable', $result['state']);
        self::assertSame(503, $result['status']);
        self::assertSame('https://example.test/blog', $result['canonical']);
        self::assertSame('noindex, nofollow', $result['robots']);
        self::assertSame(
            'https://example.test/blog',
            $result['page_meta']['canonical']
        );
        self::assertSame(
            ['es' => 'https://example.test/blog'],
            $result['language_alternates']
        );
        self::assertMatchesRegularExpression(
            '/\A[A-Za-z0-9_-]{16,128}\z/D',
            $result['nonce']
        );
    }

    public function testHeadAndRedirectExitBeforeAnyViewBody(): void
    {
        $head = $this->runAdapter('HEAD', '/blog');
        self::assertTrue($head->isSuccessful(), $head->getErrorOutput());
        self::assertSame('', $head->getOutput());

        $redirect = $this->runAdapter(
            'GET',
            '/blog?page=2',
            ['page' => '2']
        );
        self::assertTrue(
            $redirect->isSuccessful(),
            $redirect->getErrorOutput()
        );
        self::assertSame('', $redirect->getOutput());
    }

    /** @param array<string, string> $query */
    private function runAdapter(
        string $method,
        string $uri,
        array $query = []
    ): Process {
        $script = $this->fixtureRoot . '/adapter-harness.php';
        $autoload = var_export(
            $this->coreRoot . '/vendor/autoload.php',
            true
        );
        $projectRoot = var_export($this->fixtureRoot, true);
        $adapter = var_export($this->adapter, true);
        $methodValue = var_export($method, true);
        $uriValue = var_export($uri, true);
        $queryValue = var_export($query, true);
        $this->filesystem->dumpFile($script, <<<PHP
<?php

declare(strict_types=1);

require {$autoload};

\App\Core\Support\Paths::setProjectRoot({$projectRoot});
\$_SERVER = [
    'REQUEST_METHOD' => {$methodValue},
    'REQUEST_URI' => {$uriValue},
];
\$_GET = {$queryValue};
\$GLOBALS['routeParams'] = [];
\$lang = 'es';
\$title = (object) ['text' => 'Noticias'];
\$description = (object) ['content' => 'Actualidad.'];
\$robots = (object) ['content' => 'index, follow'];
\$pageMeta = [];

require {$adapter};

echo json_encode([
    'state' => \$blogIndex->state(),
    'status' => http_response_code(),
    'canonical' => \$blogIndex->canonicalUrl(),
    'robots' => \$robots->content,
    'page_meta' => \$pageMeta,
    'language_alternates' => \$languageAlternates,
    'nonce' => \$cspNonce,
], JSON_THROW_ON_ERROR);
PHP);

        $process = new Process([PHP_BINARY, $script], null, [
            'RAIZ' => 'https://example.test',
            'DEV_MODE' => '0',
            'WEBADMIN_PUBLIC_ORIGIN' => 'https://example.test',
        ]);
        $process->setTimeout(20);
        $process->run();

        return $process;
    }
}
