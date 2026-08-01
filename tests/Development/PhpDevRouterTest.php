<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class PhpDevRouterTest extends TestCase
{
    private Filesystem $filesystem;
    private string $fixtureRoot;
    private ?Process $server = null;
    private int $port;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-php-dev-router-'
            . bin2hex(random_bytes(8));

        $this->filesystem->mkdir([
            $this->fixtureRoot . '/App/tools',
            $this->fixtureRoot . '/public/assets',
        ]);
        $this->filesystem->copy(
            dirname(__DIR__, 2) . '/stubs/App/tools/php-dev-router.php',
            $this->fixtureRoot . '/App/tools/php-dev-router.php'
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/public/index.php',
            <<<'PHP'
<?php
header('X-LiquidStack-Front: yes');
echo 'front:' . ($_SERVER['REQUEST_URI'] ?? '');
PHP
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/public/assets/example.txt',
            'native-static-file'
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/outside-public.txt',
            'must-not-be-served'
        );

        $this->port = $this->reservePort();
        $this->startServer();
    }

    protected function tearDown(): void
    {
        if ($this->server instanceof Process && $this->server->isRunning()) {
            $this->server->stop(0.2);
        }

        if (isset($this->filesystem, $this->fixtureRoot)) {
            $this->filesystem->remove($this->fixtureRoot);
        }
    }

    public function testBuiltInServerOnlyServesCanonicalFilesInsidePublicNatively(): void
    {
        $static = $this->request('/assets/example.txt?cache=1');

        self::assertSame(200, $static['status']);
        self::assertSame('native-static-file', $static['body']);
        self::assertArrayNotHasKey('x-liquidstack-front', $static['headers']);

        $dynamic = $this->request('/admin/blog?draft=1');

        self::assertSame(200, $dynamic['status']);
        self::assertSame('front:/admin/blog?draft=1', $dynamic['body']);
        self::assertSame('yes', $dynamic['headers']['x-liquidstack-front'] ?? null);

        $sitemap = $this->request('/blog-sitemap.xml');

        self::assertSame(200, $sitemap['status']);
        self::assertSame('front:/blog-sitemap.xml', $sitemap['body']);
        self::assertSame(
            'yes',
            $sitemap['headers']['x-liquidstack-front'] ?? null
        );

        $directory = $this->request('/assets');

        self::assertSame(200, $directory['status']);
        self::assertSame('front:/assets', $directory['body']);

        $traversal = $this->request('/%2e%2e/outside-public.txt');

        self::assertSame(200, $traversal['status']);
        self::assertSame(
            'front:/%2e%2e/outside-public.txt',
            $traversal['body']
        );
        self::assertStringNotContainsString('must-not-be-served', $traversal['body']);
    }

    public function testMissingFrontControllerFailsClosedWithoutLeakingPaths(): void
    {
        self::assertInstanceOf(Process::class, $this->server);
        $this->server->stop(0.2);
        $this->filesystem->remove($this->fixtureRoot . '/public/index.php');
        $this->port = $this->reservePort();
        $this->startServer();

        $response = $this->request('/admin');

        self::assertSame(500, $response['status']);
        self::assertSame("Development server configuration error.\n", $response['body']);
        self::assertStringNotContainsString($this->fixtureRoot, $response['body']);
    }

    public function testRouterRejectsExecutionOutsideCliServer(): void
    {
        $process = new Process([
            PHP_BINARY,
            $this->fixtureRoot . '/App/tools/php-dev-router.php',
        ], $this->fixtureRoot);
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertSame('', $process->getOutput());
        self::assertStringContainsString(
            'solo puede ejecutarse con php -S',
            $process->getErrorOutput()
        );
    }

    private function startServer(): void
    {
        $this->server = new Process([
            PHP_BINARY,
            '-S',
            '127.0.0.1:' . $this->port,
            '-t',
            $this->fixtureRoot . '/public',
            $this->fixtureRoot . '/App/tools/php-dev-router.php',
        ], $this->fixtureRoot);
        $this->server->setTimeout(null);
        $this->server->start();

        $deadline = microtime(true) + 5.0;
        do {
            if (!$this->server->isRunning()) {
                self::fail(
                    "El servidor PHP no pudo arrancar.\n"
                    . $this->server->getErrorOutput()
                );
            }

            if ($this->canConnect()) {
                return;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::fail(
            "El servidor PHP no quedo disponible a tiempo.\n"
            . $this->server->getErrorOutput()
        );
    }

    private function canConnect(): bool
    {
        $socket = @fsockopen('127.0.0.1', $this->port, $errorCode, $error, 0.1);
        if (!is_resource($socket)) {
            return false;
        }

        fclose($socket);

        return true;
    }

    private function reservePort(): int
    {
        $errorCode = 0;
        $error = '';
        $socket = @stream_socket_server(
            'tcp://127.0.0.1:0',
            $errorCode,
            $error
        );

        if (!is_resource($socket)) {
            self::fail(
                sprintf('No se pudo reservar un puerto local: %s (%d)', $error, $errorCode)
            );
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        if (!is_string($address) || !preg_match('/:(\d+)$/', $address, $matches)) {
            self::fail('No se pudo determinar el puerto local reservado.');
        }

        return (int) $matches[1];
    }

    /**
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function request(string $path): array
    {
        $context = stream_context_create([
            'http' => [
                'ignore_errors' => true,
                'timeout' => 2.0,
            ],
        ]);
        $body = @file_get_contents(
            'http://127.0.0.1:' . $this->port . $path,
            false,
            $context
        );
        $responseHeaders = $http_response_header ?? [];

        self::assertIsArray($responseHeaders);
        self::assertNotEmpty($responseHeaders, 'El servidor no devolvio cabeceras HTTP.');
        self::assertMatchesRegularExpression(
            '/^HTTP\/\d(?:\.\d)?\s+(\d{3})/',
            (string) $responseHeaders[0]
        );
        preg_match(
            '/^HTTP\/\d(?:\.\d)?\s+(\d{3})/',
            (string) $responseHeaders[0],
            $statusMatch
        );

        $headers = [];
        foreach (array_slice($responseHeaders, 1) as $headerLine) {
            if (!is_string($headerLine) || !str_contains($headerLine, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $headerLine, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return [
            'status' => (int) ($statusMatch[1] ?? 0),
            'body' => is_string($body) ? $body : '',
            'headers' => $headers,
        ];
    }
}
