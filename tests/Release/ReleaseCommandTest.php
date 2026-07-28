<?php

declare(strict_types=1);

use App\Core\Composer\ReleaseScript;
use Composer\Composer;
use Composer\IO\BufferIO;
use Composer\Script\Event;
use LiquidStack\Release\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

require_once dirname(__DIR__, 2) . '/tools/release.php';

final class ReleaseCommandTest extends TestCase
{
    private Filesystem $filesystem;
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-release-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->fixtureRoot);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->fixtureRoot)) {
            return;
        }

        try {
            $this->filesystem->chmod($this->fixtureRoot, 0777, 0000, true);
        } catch (Throwable) {
            // Best effort for read-only Git objects on Windows.
        }

        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testHistoricalTagsAreReadLenientlyButNewTagsUseCanonicalSemver(): void
    {
        $latest = Version::latestFromTags([
            'not-a-release',
            'v1.3.8113',
            'v1.4.00',
            'v1.4.01',
        ]);

        self::assertNotNull($latest);
        self::assertSame('v1.4.01', $latest['tag']);
        self::assertSame('v1.4.1', $latest['version']->tag());
        self::assertSame('v1.4.2', $latest['version']->bump('patch')->tag());
        self::assertSame('v1.5.0', $latest['version']->bump('minor')->tag());
        self::assertSame('v2.0.0', $latest['version']->bump('major')->tag());
        self::assertNull(Version::fromTag('v1.4.02', true));
        self::assertSame('v1.4.2', Version::fromTag('1.4.2', true)?->tag());
    }

    public function testReleasePublishesBranchAndAnnotatedTagAtomicallyToLocalRemote(): void
    {
        [$remoteRoot, $repositoryRoot] = $this->createReleaseRepository();

        [$exitCode, $output] = $this->runProcess([
            PHP_BINARY,
            'tools/release.php',
            '--version=v1.5.0',
            '--yes',
            '--skip-tests',
        ], $repositoryRoot);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Release v1.5.0 publicada correctamente.', $output);

        $localHead = trim($this->runChecked(['git', 'rev-parse', 'HEAD'], $repositoryRoot));
        $remoteHead = trim($this->runChecked([
            'git',
            '--git-dir=' . $remoteRoot,
            'rev-parse',
            'refs/heads/main',
        ], $this->fixtureRoot));
        $remoteTag = $this->runChecked([
            'git',
            '--git-dir=' . $remoteRoot,
            'rev-parse',
            'refs/tags/v1.5.0^{}',
        ], $this->fixtureRoot);
        $remoteTagType = $this->runChecked([
            'git',
            '--git-dir=' . $remoteRoot,
            'cat-file',
            '-t',
            'refs/tags/v1.5.0',
        ], $this->fixtureRoot);

        self::assertSame($localHead, $remoteHead);
        self::assertSame($localHead, trim($remoteTag));
        self::assertSame('tag', trim($remoteTagType));

        $this->filesystem->dumpFile($repositoryRoot . '/dirty.txt', 'not committed');
        [$exitCode, $output] = $this->runProcess([
            PHP_BINARY,
            'tools/release.php',
            '--version=v1.5.1',
            '--yes',
            '--skip-tests',
            '--no-fetch',
        ], $repositoryRoot);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('El árbol de trabajo no está limpio', $output);
        self::assertSame(
            1,
            $this->runProcess([
                'git',
                '--git-dir=' . $remoteRoot,
                'show-ref',
                '--verify',
                '--quiet',
                'refs/tags/v1.5.1',
            ], $this->fixtureRoot)[0]
        );

        $this->filesystem->remove($repositoryRoot . '/dirty.txt');
        [$exitCode, $output] = $this->runProcess([
            PHP_BINARY,
            'tools/release.php',
            '--version=v1.5.1',
            '--yes',
            '--skip-tests',
            '--dry-run',
        ], $repositoryRoot);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Simulación completada', $output);
        self::assertStringContainsString('AVISO: main ya está sincronizada', $output);
        self::assertSame(
            1,
            $this->runProcess([
                'git',
                '--git-dir=' . $remoteRoot,
                'show-ref',
                '--verify',
                '--quiet',
                'refs/tags/v1.5.1',
            ], $this->fixtureRoot)[0]
        );
    }

    public function testReleaseExplainsWhenLocalAndRemoteMainHaveDiverged(): void
    {
        [$remoteRoot, $repositoryRoot] = $this->createReleaseRepository();
        $publisherRoot = $this->fixtureRoot
            . DIRECTORY_SEPARATOR
            . 'publisher-'
            . bin2hex(random_bytes(6));

        $this->runChecked([
            'git',
            'clone',
            '--branch',
            'main',
            $remoteRoot,
            $publisherRoot,
        ], $this->fixtureRoot);
        $this->runChecked(
            ['git', 'config', 'user.name', 'LiquidStack Remote Test'],
            $publisherRoot
        );
        $this->runChecked(
            ['git', 'config', 'user.email', 'remote@example.invalid'],
            $publisherRoot
        );
        $this->filesystem->dumpFile(
            $publisherRoot . '/remote-change.txt',
            "Remote change\n"
        );
        $this->runChecked(['git', 'add', 'remote-change.txt'], $publisherRoot);
        $this->runChecked(['git', 'commit', '-m', 'Remote change'], $publisherRoot);
        $this->runChecked(['git', 'push', 'origin', 'main'], $publisherRoot);

        [$exitCode, $output] = $this->runProcess([
            PHP_BINARY,
            'tools/release.php',
            '--version=v1.5.0',
            '--yes',
            '--skip-tests',
        ], $repositoryRoot);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('han divergido', $output);
        self::assertStringContainsString(
            'git pull --ff-only no puede resolver este estado',
            $output
        );
        self::assertStringContainsString(
            'hay 1 commit(s) local(es) por subir y 1 commit(s) remoto(s) por integrar',
            $output
        );
    }

    public function testComposerEntrypointForwardsArgumentsAndExitCodes(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $composerCli = $projectRoot . '/vendor/composer/composer/bin/composer';

        self::assertFileExists($composerCli);

        [$exitCode, $output] = $this->runProcess([
            PHP_BINARY,
            $composerCli,
            'release',
            '--',
            '--help',
        ], $projectRoot);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Publica una release estable', $output);

        [$exitCode, $output] = $this->runProcess([
            PHP_BINARY,
            $composerCli,
            'release',
            '--',
            '--opcion-inexistente',
        ], $projectRoot);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('Opción desconocida: --opcion-inexistente', $output);
    }

    public function testComposerScriptUsesComposerIoAndRejectsNonInteractivePrompts(): void
    {
        [$remoteRoot, $repositoryRoot] = $this->createReleaseRepository();
        $releaseScript = new ReleaseScript($repositoryRoot);

        $nonInteractiveIo = new BufferIO();
        $nonInteractiveEvent = new Event(
            'release',
            new Composer(),
            $nonInteractiveIo,
            true,
            ['--skip-tests', '--no-fetch']
        );

        $nonInteractiveResult = $releaseScript->handle($nonInteractiveEvent);

        self::assertFalse($nonInteractiveResult);
        self::assertStringContainsString(
            'No hay una consola interactiva',
            $nonInteractiveIo->getOutput()
        );
        self::assertSame(
            1,
            $this->runProcess([
                'git',
                '--git-dir=' . $remoteRoot,
                'show-ref',
                '--verify',
                '--quiet',
                'refs/tags/v1.5.0',
            ], $this->fixtureRoot)[0]
        );

        $interactiveIo = new BufferIO();
        $interactiveIo->setUserInputs(['v1.5.0', '']);
        $interactiveEvent = new Event(
            'release',
            new Composer(),
            $interactiveIo,
            true,
            ['--skip-tests', '--no-fetch']
        );

        $interactiveResult = $releaseScript->handle($interactiveEvent);

        self::assertTrue($interactiveResult, $interactiveIo->getOutput());
        self::assertStringContainsString(
            'Etiqueta a publicar [v1.4.2]:',
            $interactiveIo->getOutput()
        );
        self::assertStringContainsString(
            'Pulsa Enter para crear v1.5.0',
            $interactiveIo->getOutput()
        );

        $remoteHead = trim($this->runChecked([
            'git',
            '--git-dir=' . $remoteRoot,
            'rev-parse',
            'refs/heads/main',
        ], $this->fixtureRoot));
        $remoteTag = trim($this->runChecked([
            'git',
            '--git-dir=' . $remoteRoot,
            'rev-parse',
            'refs/tags/v1.5.0^{}',
        ], $this->fixtureRoot));

        self::assertSame($remoteHead, $remoteTag);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function createReleaseRepository(): array
    {
        $fixtureSuffix = bin2hex(random_bytes(6));
        $remoteRoot = $this->fixtureRoot . DIRECTORY_SEPARATOR . 'remote-' . $fixtureSuffix . '.git';
        $repositoryRoot = $this->fixtureRoot . DIRECTORY_SEPARATOR . 'core-' . $fixtureSuffix;

        $this->runChecked(['git', 'init', '--bare', $remoteRoot], $this->fixtureRoot);
        $this->runChecked(['git', 'init', '--initial-branch=main', $repositoryRoot], $this->fixtureRoot);

        $composer = json_encode([
            'name'        => 'liquidstack/core',
            'description' => 'Release command fixture',
            'type'        => 'library',
            'license'     => 'MIT',
            'require'     => ['php' => '>=8.1'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->filesystem->dumpFile($repositoryRoot . '/composer.json', $composer . PHP_EOL);
        $this->filesystem->mkdir($repositoryRoot . '/tools');
        $this->filesystem->copy(
            dirname(__DIR__, 2) . '/tools/release.php',
            $repositoryRoot . '/tools/release.php'
        );

        $this->runChecked(['git', 'config', 'user.name', 'LiquidStack Release Test'], $repositoryRoot);
        $this->runChecked(['git', 'config', 'user.email', 'release@example.invalid'], $repositoryRoot);
        $this->runChecked(['git', 'add', '.'], $repositoryRoot);
        $this->runChecked(['git', 'commit', '-m', 'Initial release'], $repositoryRoot);
        $this->runChecked(['git', 'remote', 'add', 'origin', $remoteRoot], $repositoryRoot);
        $this->runChecked(['git', 'tag', '-a', 'v1.4.01', '-m', 'Release v1.4.01'], $repositoryRoot);
        $this->runChecked(['git', 'push', '-u', 'origin', 'main', '--tags'], $repositoryRoot);

        $this->filesystem->dumpFile($repositoryRoot . '/CHANGELOG.md', "Release candidate\n");
        $this->runChecked(['git', 'add', 'CHANGELOG.md'], $repositoryRoot);
        $this->runChecked(['git', 'commit', '-m', 'Prepare next release'], $repositoryRoot);

        return [$remoteRoot, $repositoryRoot];
    }

    /**
     * @param list<string> $command
     */
    private function runChecked(array $command, string $workingDirectory): string
    {
        [$exitCode, $output] = $this->runProcess($command, $workingDirectory);

        self::assertSame(0, $exitCode, sprintf(
            "Command failed: %s\n%s",
            implode(' ', $command),
            $output
        ));

        return $output;
    }

    /**
     * @param list<string> $command
     *
     * @return array{0: int, 1: string}
     */
    private function runProcess(array $command, string $workingDirectory): array
    {
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['redirect', 1],
            ],
            $pipes,
            $workingDirectory,
            null,
            ['bypass_shell' => true]
        );

        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        return [$exitCode, is_string($output) ? $output : ''];
    }
}
