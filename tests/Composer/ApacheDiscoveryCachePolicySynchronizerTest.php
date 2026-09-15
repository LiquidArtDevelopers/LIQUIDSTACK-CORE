<?php

declare(strict_types=1);

use App\Core\Composer\ApacheDiscoveryCachePolicySynchronizer;
use Composer\IO\BufferIO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ApacheDiscoveryCachePolicySynchronizerTest extends TestCase
{
    private Filesystem $filesystem;
    private string $root;
    private string $target;
    private BufferIO $io;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-apache-discovery-cache-'
            . bin2hex(random_bytes(8));
        $this->target = $this->root . '/public/.htaccess';
        $this->io = new BufferIO();

        $this->filesystem->mkdir($this->root . '/public');
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testAddsThePolicyWithoutReplacingProjectRules(): void
    {
        $original = "RewriteEngine On\r\n"
            . "RewriteRule ^ index.php [L,QSA]\r\n"
            . "ExpiresDefault \"access plus 1 week\"\r\n";
        $this->writeTarget($original);

        self::assertTrue($this->synchronizer()->sync($this->root));

        $updated = (string) file_get_contents($this->target);

        self::assertStringStartsWith($original . "\r\n", $updated);
        self::assertSame(
            1,
            substr_count(
                $updated,
                '# <liquidstack-core:discovery-cache-policy>'
            )
        );
        self::assertStringContainsString(
            '<FilesMatch "^(?:sitemap\\.xml|robots\\.txt)$">',
            $updated
        );
        self::assertStringContainsString('ExpiresActive Off', $updated);
        self::assertStringContainsString(
            'Header set Cache-Control "public, no-cache, must-revalidate"',
            $updated
        );
        self::assertStringContainsString('Header unset Expires', $updated);
        self::assertDoesNotMatchRegularExpression('/(?<!\r)\n/', $updated);

        self::assertTrue($this->synchronizer()->sync($this->root));
        self::assertSame($updated, file_get_contents($this->target));
    }

    public function testUpdatesOnlyTheDelimitedCoreBlock(): void
    {
        $original = "RewriteEngine On\n"
            . "# <liquidstack-core:discovery-cache-policy>\n"
            . "Header set Cache-Control \"public, max-age=604800\"\n"
            . "# </liquidstack-core:discovery-cache-policy>\n"
            . "RewriteRule ^ index.php [L,QSA]\n";
        $this->writeTarget($original);

        self::assertTrue($this->synchronizer()->sync($this->root));

        $updated = (string) file_get_contents($this->target);

        self::assertStringStartsWith("RewriteEngine On\n", $updated);
        self::assertStringEndsWith(
            "RewriteRule ^ index.php [L,QSA]\n",
            $updated
        );
        self::assertStringNotContainsString('max-age=604800', $updated);
        self::assertSame(1, substr_count($updated, 'RewriteEngine On'));
        self::assertSame(1, substr_count($updated, 'RewriteRule ^ index.php'));
    }

    public function testCreatesOnlyTheCacheBlockWhenHtaccessIsMissing(): void
    {
        self::assertFileDoesNotExist($this->target);

        self::assertTrue($this->synchronizer()->sync($this->root));

        $created = (string) file_get_contents($this->target);
        self::assertStringStartsWith(
            '# <liquidstack-core:discovery-cache-policy>',
            $created
        );
        self::assertStringEndsWith(
            "# </liquidstack-core:discovery-cache-policy>\n",
            $created
        );
    }

    public function testMalformedMarkersPreserveTheProjectFile(): void
    {
        $original = "RewriteEngine On\n"
            . "# <liquidstack-core:discovery-cache-policy>\n";
        $this->writeTarget($original);

        self::assertFalse($this->synchronizer()->sync($this->root));
        self::assertSame($original, file_get_contents($this->target));
        self::assertStringContainsString(
            'marcadores incompletos, duplicados o desordenados',
            $this->io->getOutput()
        );
    }

    public function testMarkerMentionsInsideProjectTextAreNeverReplaced(): void
    {
        $original = "# Documentation mentions "
            . "# <liquidstack-core:discovery-cache-policy> and "
            . "# </liquidstack-core:discovery-cache-policy> without ownership\n"
            . "RewriteEngine On\n";
        $this->writeTarget($original);

        self::assertTrue($this->synchronizer()->sync($this->root));

        $updated = (string) file_get_contents($this->target);
        self::assertStringStartsWith($original . "\n", $updated);
        self::assertSame(
            2,
            substr_count(
                $updated,
                '# <liquidstack-core:discovery-cache-policy>'
            )
        );
        self::assertTrue($this->synchronizer()->sync($this->root));
        self::assertSame($updated, file_get_contents($this->target));
    }

    public function testMarkersWithTrailingTextRemainProjectOwned(): void
    {
        $original = "# <liquidstack-core:discovery-cache-policy> example\n"
            . "Header set Cache-Control \"public, max-age=604800\"\n"
            . "# </liquidstack-core:discovery-cache-policy> example\n";
        $this->writeTarget($original);

        self::assertTrue($this->synchronizer()->sync($this->root));

        $updated = (string) file_get_contents($this->target);
        self::assertStringStartsWith($original . "\n", $updated);
        self::assertStringContainsString('max-age=604800', $updated);
        self::assertSame(
            1,
            preg_match_all(
                '/^# <liquidstack-core:discovery-cache-policy>$/m',
                $updated
            )
        );
    }

    public function testDoesNotCreateAnOversizedUnmanageableResult(): void
    {
        $original = str_repeat('#', 1_048_500);
        $this->writeTarget($original);

        self::assertFalse($this->synchronizer()->sync($this->root));
        self::assertSame($original, file_get_contents($this->target));
        self::assertStringContainsString(
            'superaría el tamaño máximo',
            $this->io->getOutput()
        );
    }

    public function testMissingOrRedirectedPublicDirectoryFailsClosed(): void
    {
        $this->filesystem->remove($this->root . '/public');

        self::assertFalse($this->synchronizer()->sync($this->root));
        self::assertFileDoesNotExist($this->target);

        if (!function_exists('symlink')) {
            return;
        }

        $outside = $this->root . '-outside';
        $this->filesystem->mkdir($outside);
        if (!@symlink($outside, $this->root . '/public')) {
            $this->filesystem->remove($outside);

            return;
        }

        $this->io = new BufferIO();
        self::assertFalse($this->synchronizer()->sync($this->root));
        self::assertFileDoesNotExist($outside . '/.htaccess');
        $this->filesystem->remove($this->root . '/public');
        $this->filesystem->remove($outside);
    }

    private function synchronizer(): ApacheDiscoveryCachePolicySynchronizer
    {
        return new ApacheDiscoveryCachePolicySynchronizer($this->io);
    }

    private function writeTarget(string $contents): void
    {
        $this->filesystem->dumpFile($this->target, $contents);
    }
}
