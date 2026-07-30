<?php

declare(strict_types=1);

use App\Core\Composer\ManagedFileRegistry;
use App\Core\Composer\ManagedFileSynchronizer;
use Composer\IO\BufferIO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ManagedFileSynchronizerTest extends TestCase
{
    private Filesystem $filesystem;
    private string $root;
    private string $packageRoot;
    private string $projectRoot;
    private BufferIO $io;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-safe-sync-'
            . bin2hex(random_bytes(8));
        $this->packageRoot = $this->root . '/package';
        $this->projectRoot = $this->root . '/project';
        $this->io = new BufferIO();

        $this->filesystem->mkdir([
            $this->packageRoot . '/manifests',
            $this->projectRoot,
        ]);
        $this->writeHistory([]);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testStateUpdatesOnlyAnUnmodifiedManagedFile(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $target = $this->projectRoot . '/src/scss/resources/_sample.scss';
        $this->writeFile($source, ".sample {\r\n  color: red;\r\n}\r\n");

        $first = $this->synchronizer();
        $first->queueFile(
            $source,
            $target,
            'resources/scss/_sample.scss',
            'src/scss/resources/_sample.scss'
        );
        $first->apply();

        self::assertFileEquals($source, $target);
        self::assertFileExists(
            $this->projectRoot
                . '/.liquidstack/core/managed-files.json'
        );

        $this->writeFile($source, ".sample {\n  color: blue;\n}\n");

        $second = $this->synchronizer();
        $second->queueFile(
            $source,
            $target,
            'resources/scss/_sample.scss',
            'src/scss/resources/_sample.scss'
        );
        $second->apply();

        self::assertSame(
            ".sample {\n  color: blue;\n}\n",
            file_get_contents($target)
        );
        self::assertSame(1, $second->stats()['updated']);
    }

    public function testLocalChangeBlocksTheWholeResourceGroup(): void
    {
        $scssSource = $this->packageRoot
            . '/resources/scss/_sample.scss';
        $scssTarget = $this->projectRoot
            . '/src/scss/resources/_sample.scss';
        $templateSource = $this->packageRoot
            . '/stubs/App/templates/_sample.html';
        $templateTarget = $this->projectRoot
            . '/App/templates/_sample.html';

        $this->writeFile($scssSource, '.sample { color: red; }');

        $initial = $this->synchronizer();
        $initial->queueFile(
            $scssSource,
            $scssTarget,
            'resources/scss/_sample.scss',
            'src/scss/resources/_sample.scss'
        );
        $initial->apply();

        $this->writeFile($scssTarget, '.sample { color: local; }');
        $this->writeFile($scssSource, '.sample { color: blue; }');
        $this->writeFile($templateSource, '<article>CORE</article>');

        $update = $this->synchronizer();
        $update->queueFile(
            $scssSource,
            $scssTarget,
            'resources/scss/_sample.scss',
            'src/scss/resources/_sample.scss'
        );
        $update->queueFile(
            $templateSource,
            $templateTarget,
            'stubs/App/templates/_sample.html',
            'App/templates/_sample.html'
        );
        $update->apply();

        self::assertSame(
            '.sample { color: local; }',
            file_get_contents($scssTarget)
        );
        self::assertFileDoesNotExist($templateTarget);
        self::assertSame(2, $update->stats()['preserved']);
        self::assertStringContainsString(
            'grupo resource:sample',
            $this->io->getOutput()
        );
    }

    public function testHistoricalFingerprintRecognizesWindowsLineEndings(): void
    {
        $sourceId = 'resources/scss/_legacy.scss';
        $source = $this->packageRoot . '/' . $sourceId;
        $target = $this->projectRoot
            . '/src/scss/resources/_legacy.scss';
        $legacy = ".legacy {\n  color: red;\n}\n";

        $this->writeHistory([
            $sourceId => ManagedFileRegistry::fingerprintContents(
                $sourceId,
                $legacy
            ),
        ]);
        $this->writeFile($source, ".legacy {\n  color: blue;\n}\n");
        $this->writeFile(
            $target,
            str_replace("\n", "\r\n", $legacy)
        );

        $sync = $this->synchronizer();
        $sync->queueFile(
            $source,
            $target,
            $sourceId,
            'src/scss/resources/_legacy.scss'
        );
        $sync->apply();

        self::assertSame(
            ".legacy {\n  color: blue;\n}\n",
            file_get_contents($target)
        );
        self::assertSame(1, $sync->stats()['updated']);
    }

    public function testHistoricalFingerprintIgnoresBlankLinesAtEof(): void
    {
        $sourceId = 'resources/scss/_legacy.scss';
        $source = $this->packageRoot . '/' . $sourceId;
        $target = $this->projectRoot
            . '/src/scss/resources/_legacy.scss';
        $legacy = ".legacy {\n  color: red;\n}\n";

        $this->writeHistory([
            $sourceId => ManagedFileRegistry::fingerprintContents(
                $sourceId,
                $legacy
            ),
        ]);
        $this->writeFile($source, ".legacy {\n  color: blue;\n}\n");
        $this->writeFile(
            $target,
            ".legacy {\r\n  color: red;\r\n}\r\n \r\n\t\r\n"
        );

        $sync = $this->synchronizer();
        $sync->queueFile(
            $source,
            $target,
            $sourceId,
            'src/scss/resources/_legacy.scss'
        );
        $sync->apply();

        self::assertSame(
            ".legacy {\n  color: blue;\n}\n",
            file_get_contents($target)
        );
        self::assertSame(1, $sync->stats()['updated']);
    }

    public function testUnknownExistingFileIsPreserved(): void
    {
        $source = $this->packageRoot
            . '/stubs/App/controllers/sample.php';
        $target = $this->projectRoot
            . '/App/controllers/sample.php';

        $this->writeFile($source, '<?php return "core";');
        $this->writeFile($target, '<?php return "local";');

        $sync = $this->synchronizer();
        $sync->queueFile(
            $source,
            $target,
            'stubs/App/controllers/sample.php',
            'App/controllers/sample.php'
        );
        $sync->apply();

        self::assertSame(
            '<?php return "local";',
            file_get_contents($target)
        );
        self::assertSame(1, $sync->stats()['preserved']);
    }

    public function testJsonMergeAddsOnlyMissingKeysAndProperties(): void
    {
        $source = $this->packageRoot
            . '/stubs/App/config/languages/templates/es.json';
        $target = $this->projectRoot
            . '/App/config/languages/templates/es.json';

        $this->writeFile(
            $source,
            json_encode([
                'existing' => [
                    'text' => 'dummy',
                    'title' => 'Nuevo title',
                ],
                'empty' => ['text' => 'dummy'],
                'null' => ['text' => 'dummy'],
                'list' => ['core-a', 'core-b'],
                'new' => ['text' => 'Nueva clave'],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
        $this->writeFile(
            $target,
            "{\r\n"
                . "    \"existing\": {\r\n"
                . "        \"text\": \"Copy cliente\"\r\n"
                . "    },\r\n"
                . "\r\n"
                . "    \"empty\": \"\",\r\n"
                . "    \"null\": null,\r\n"
                . "    \"list\": []\r\n"
                . "}\r\n"
        );

        $sync = $this->synchronizer();
        $sync->queueFile(
            $source,
            $target,
            'stubs/App/config/languages/templates/es.json',
            'App/config/languages/templates/es.json'
        );
        $sync->apply();

        $merged = json_decode(
            (string) file_get_contents($target),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('Copy cliente', $merged['existing']['text']);
        self::assertSame(
            'Nuevo title',
            $merged['existing']['title']
        );
        self::assertSame('', $merged['empty']);
        self::assertNull($merged['null']);
        self::assertSame([], $merged['list']);
        self::assertSame('Nueva clave', $merged['new']['text']);
        self::assertSame(1, $sync->stats()['merged']);

        $patched = (string) file_get_contents($target);
        self::assertStringContainsString(
            "    },\r\n\r\n    \"empty\": \"\"",
            $patched,
            'La fusión no debe reformatear el catálogo completo'
        );
        self::assertDoesNotMatchRegularExpression(
            '/(?<!\r)\n/',
            $patched,
            'La fusión debe conservar CRLF en catálogos Windows'
        );
    }

    public function testInvalidJsonAndInstallOnlySeedsStayUntouched(): void
    {
        $jsonSource = $this->packageRoot
            . '/stubs/App/config/languages/templates/es.json';
        $jsonTarget = $this->projectRoot
            . '/App/config/languages/templates/es.json';
        $seedSource = $this->packageRoot
            . '/stubs/App/app/formContact.php';
        $seedTarget = $this->projectRoot
            . '/App/app/formContact.php';

        $this->writeFile($jsonSource, '{"new":{"text":"dummy"}}');
        $this->writeFile($jsonTarget, '{"broken":');
        $this->writeFile($seedSource, '<?php // core seed');
        $this->writeFile($seedTarget, '<?php // project backend');

        $sync = $this->synchronizer();
        $sync->queueFile(
            $jsonSource,
            $jsonTarget,
            'stubs/App/config/languages/templates/es.json',
            'App/config/languages/templates/es.json'
        );
        $sync->queueFile(
            $seedSource,
            $seedTarget,
            'stubs/App/app/formContact.php',
            'App/app/formContact.php'
        );
        $sync->apply();

        self::assertSame('{"broken":', file_get_contents($jsonTarget));
        self::assertSame(
            '<?php // project backend',
            file_get_contents($seedTarget)
        );
        self::assertSame(1, $sync->stats()['protected']);
        self::assertSame(1, $sync->stats()['errors']);
    }

    private function synchronizer(): ManagedFileSynchronizer
    {
        return new ManagedFileSynchronizer(
            $this->projectRoot,
            $this->packageRoot,
            $this->io
        );
    }

    /**
     * @param array<string, list<string>> $files
     */
    private function writeHistory(array $files): void
    {
        $this->writeFile(
            $this->packageRoot
                . '/manifests/managed-file-history.json',
            json_encode([
                'schema' => 1,
                'algorithm' => 'sha256-eol-lf-v1',
                'files' => $files,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
    }

    private function writeFile(string $path, string $contents): void
    {
        $this->filesystem->mkdir(dirname($path));
        $this->filesystem->dumpFile($path, $contents);
    }
}
