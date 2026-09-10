<?php

declare(strict_types=1);

use App\Core\Composer\ManagedFileRegistry;
use App\Core\Composer\ManagedFilePlanChangedException;
use App\Core\Composer\ManagedFilePlanBlockedException;
use App\Core\Composer\ManagedFileSynchronizer;
use Composer\IO\BufferIO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

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

    public function testPreviewIsReadOnlyDeterministicAndUsesRelativeIds(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $target = $this->projectRoot
            . '/src/scss/resources/_sample.scss';
        $this->writeFile($source, '.sample { color: red; }');

        $sync = $this->synchronizer();
        $sync->queueFile(
            $source,
            $target,
            'resources/scss/_sample.scss',
            'src/scss/resources/_sample.scss'
        );
        $before = $this->snapshot($this->projectRoot);
        $first = $sync->preview();
        $second = $sync->preview();

        self::assertSame($before, $this->snapshot($this->projectRoot));
        self::assertSame($first, $second);
        self::assertSame('ready', $first['status']);
        self::assertSame('managed-sync-v1', $first['protocol']);
        self::assertMatchesRegularExpression(
            '/\Asha256:[a-f0-9]{64}\z/',
            $first['plan_hash']
        );
        self::assertSame('resources/scss/_sample.scss', $first['entries'][0]['source']);
        self::assertSame('src/scss/resources/_sample.scss', $first['entries'][0]['target']);
        self::assertSame('add', $first['entries'][0]['action']);
        self::assertStringNotContainsString(
            str_replace('\\', '/', $this->root),
            json_encode($first, JSON_THROW_ON_ERROR)
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/.liquidstack/core/managed-files.json'
        );
    }

    public function testExpectedPlanHashRejectsAChangedTarget(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $target = $this->projectRoot
            . '/src/scss/resources/_sample.scss';
        $this->writeFile($source, '.sample { color: core; }');

        $sync = $this->synchronizer();
        $sync->queueFile(
            $source,
            $target,
            'resources/scss/_sample.scss',
            'src/scss/resources/_sample.scss'
        );
        $planHash = $sync->preview()['plan_hash'];
        $this->writeFile($target, '.sample { color: local; }');

        try {
            $sync->apply($planHash);
            self::fail('El plan obsoleto debia rechazarse.');
        } catch (ManagedFilePlanChangedException $exception) {
            self::assertSame('sync.plan_changed', $exception->getMessage());
        }

        self::assertSame(
            '.sample { color: local; }',
            file_get_contents($target)
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/.liquidstack/core/managed-files.json'
        );
    }

    public function testPlanHashCannotBeReusedInAnotherProject(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $targetA = $this->projectRoot . '/src/scss/resources/_sample.scss';
        $projectB = $this->root . '/project-b';
        $targetB = $projectB . '/src/scss/resources/_sample.scss';
        $this->writeFile($source, '.sample { color: core; }');
        $this->filesystem->mkdir($projectB);

        $syncA = $this->synchronizer();
        $syncA->queueFile(
            $source,
            $targetA,
            'resources/scss/_sample.scss',
            'src/scss/resources/_sample.scss'
        );
        $syncB = new ManagedFileSynchronizer(
            $projectB,
            $this->packageRoot,
            new BufferIO()
        );
        $syncB->queueFile(
            $source,
            $targetB,
            'resources/scss/_sample.scss',
            'src/scss/resources/_sample.scss'
        );

        $hashA = $syncA->preview()['plan_hash'];
        $hashB = $syncB->preview()['plan_hash'];

        self::assertNotSame($hashA, $hashB);
        $this->expectException(ManagedFilePlanChangedException::class);
        $this->expectExceptionMessage('sync.plan_changed');
        try {
            $syncB->apply($hashA);
        } finally {
            self::assertFileDoesNotExist($targetA);
            self::assertFileDoesNotExist($targetB);
        }
    }

    public function testPlanHashBindsAnExternalOverrideTarget(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $targetA = $this->root . '/external-a/scss/_sample.scss';
        $targetB = $this->root . '/external-b/scss/_sample.scss';
        $this->writeFile($source, '.sample { color: core; }');

        $syncA = $this->synchronizer();
        $syncA->queueFile(
            $source,
            $targetA,
            'resources/scss/_sample.scss',
            '@custom-resources/scss/_sample.scss',
            null,
            null,
            true,
            $this->root . '/external-a'
        );
        $syncB = $this->synchronizer();
        $syncB->queueFile(
            $source,
            $targetB,
            'resources/scss/_sample.scss',
            '@custom-resources/scss/_sample.scss',
            null,
            null,
            true,
            $this->root . '/external-b'
        );

        $hashA = $syncA->preview()['plan_hash'];
        self::assertNotSame($hashA, $syncB->preview()['plan_hash']);

        $this->expectException(ManagedFilePlanChangedException::class);
        try {
            $syncB->apply($hashA);
        } finally {
            self::assertFileDoesNotExist($targetA);
            self::assertFileDoesNotExist($targetB);
        }
    }

    public function testManagedGroupCanApplyToAnExplicitExternalRoot(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $externalRoot = $this->root . '/explicit-external';
        $target = $externalRoot . '/scss nice/_sample.scss';
        $this->writeFile($source, '.sample { color: core; }');

        $sync = $this->synchronizer();
        $sync->queueFile(
            $source,
            $target,
            'resources/scss/_sample.scss',
            '@custom-resources/scss/_sample.scss',
            ManagedFileRegistry::POLICY_MANAGED,
            'resource:sample',
            true,
            $externalRoot
        );
        $preview = $sync->preview();

        self::assertSame('ready', $preview['status']);
        self::assertSame('external', $preview['entries'][0]['scope']);
        $sync->apply($preview['plan_hash']);

        self::assertFileEquals($source, $target);
        self::assertSame(1, $sync->stats()['added']);
        $this->assertTransactionDirectoryIsEmpty();
    }

    public function testCrossDeviceExternalNoOpRemainsReady(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $externalRoot = $this->root . '/explicit-external';
        $target = $externalRoot . '/scss/_sample.scss';
        $contents = '.sample { color: core; }';
        $this->writeFile($source, $contents);
        $this->writeFile($target, $contents);
        $sync = new ManagedFileSynchronizer(
            $this->projectRoot,
            $this->packageRoot,
            $this->io,
            null,
            null,
            null,
            static fn (string $path): string => str_contains(
                str_replace('\\', '/', $path),
                '/explicit-external/'
            ) ? 'external-device' : 'project-device'
        );
        $sync->queueFile(
            $source,
            $target,
            'resources/scss/_sample.scss',
            '@custom-resources/scss/_sample.scss',
            ManagedFileRegistry::POLICY_MANAGED,
            'resource:sample',
            true,
            $externalRoot
        );

        $preview = $sync->preview();

        self::assertSame('ready', $preview['status']);
        self::assertSame('unchanged', $preview['entries'][0]['action']);
    }

    public function testCrossDeviceExternalMutationIsBlockedBeforeRename(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $externalRoot = $this->root . '/explicit-external';
        $target = $externalRoot . '/scss/_sample.scss';
        $this->writeFile($source, '.sample { color: core; }');
        $sync = new ManagedFileSynchronizer(
            $this->projectRoot,
            $this->packageRoot,
            $this->io,
            null,
            null,
            null,
            static fn (string $path): string => str_contains(
                str_replace('\\', '/', $path),
                '/explicit-external/'
            ) ? 'external-device' : 'project-device'
        );
        $sync->queueFile(
            $source,
            $target,
            'resources/scss/_sample.scss',
            '@custom-resources/scss/_sample.scss',
            ManagedFileRegistry::POLICY_MANAGED,
            'resource:sample',
            true,
            $externalRoot
        );

        $preview = $sync->preview();

        self::assertSame('blocked', $preview['status']);
        self::assertSame(
            'sync.external_target_cross_device_unsupported',
            $preview['entries'][0]['code']
        );
        try {
            $sync->apply($preview['plan_hash']);
            self::fail('Un rename externo entre volúmenes debe bloquearse.');
        } catch (ManagedFilePlanBlockedException $exception) {
            self::assertSame('sync.plan_blocked', $exception->getMessage());
        }
        self::assertFileDoesNotExist($target);
    }

    public function testCrossDeviceStandaloneMutationIsBlockedBeforeTransaction(): void
    {
        $source = $this->packageRoot . '/misc/external.txt';
        $externalRoot = $this->root . '/standalone-external';
        $target = $externalRoot . '/external.txt';
        $this->writeFile($source, 'payload');
        $sync = new ManagedFileSynchronizer(
            $this->projectRoot,
            $this->packageRoot,
            $this->io,
            null,
            null,
            null,
            static fn (string $path): string => str_contains(
                str_replace('\\', '/', $path),
                '/standalone-external/'
            ) ? 'external-device' : 'project-device'
        );
        $sync->queueFile(
            $source,
            $target,
            'misc/external.txt',
            '@custom/external.txt',
            ManagedFileRegistry::POLICY_INSTALL_IF_MISSING,
            null,
            false,
            $externalRoot
        );

        $preview = $sync->preview();

        self::assertSame('blocked', $preview['status']);
        self::assertSame(
            'sync.external_target_cross_device_unsupported',
            $preview['entries'][0]['code']
        );
        self::assertFileDoesNotExist($target);
    }

    public function testWindowsDeviceIdentityPreservesDriveAndUncRoots(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Comprobación específica de rutas Windows.');
        }
        $method = new ReflectionMethod(
            ManagedFileSynchronizer::class,
            'filesystemDeviceIdentity'
        );
        $method->setAccessible(true);
        $sync = $this->synchronizer();

        self::assertSame(
            'drive:c',
            $method->invoke($sync, 'C:\\work\\project\\file.php')
        );
        self::assertSame(
            'unc:server/share',
            $method->invoke($sync, '\\\\server\\share\\folder\\file.php')
        );
        self::assertSame(
            'unc:server/share',
            $method->invoke(
                $sync,
                '\\\\?\\UNC\\server\\share\\folder\\file.php'
            )
        );
        $normalizer = new ReflectionMethod(
            ManagedFileSynchronizer::class,
            'normalizeBindingPath'
        );
        $normalizer->setAccessible(true);
        self::assertSame(
            '//server/share/folder/file.php',
            $normalizer->invoke(
                $sync,
                '\\\\server\\share\\folder\\file.php'
            )
        );
    }

    public function testWindowsPhysicalWritesPreserveCaseSensitiveTargetNames(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Comprobacion especifica de Windows.');
        }
        $caseRoot = $this->projectRoot . '/CaseSensitive';
        $this->filesystem->mkdir($caseRoot);
        $enable = new Process([
            'fsutil.exe',
            'file',
            'SetCaseSensitiveInfo',
            $caseRoot,
            'enable',
        ]);
        $enable->run();
        if (!$enable->isSuccessful()) {
            self::markTestSkipped(
                'El entorno no permite habilitar case sensitivity por directorio.'
            );
        }

        try {
            $source = $this->packageRoot . '/misc/CasePayload.txt';
            $target = $caseRoot . '/Foo.txt';
            $wrongCase = $caseRoot . '/foo.txt';
            $this->writeFile($source, 'payload');
            $sync = $this->synchronizer();
            $sync->queueFile(
                $source,
                $wrongCase,
                'misc/CasePayload.txt',
                'CaseSensitive/Foo.txt',
                ManagedFileRegistry::POLICY_MANAGED,
                null
            );
            $sync->apply();

            self::assertFileExists($target);
            self::assertFileDoesNotExist($wrongCase);
            self::assertSame('payload', file_get_contents($target));
            self::assertSame(1, $sync->stats()['added']);
            self::assertSame(0, $sync->stats()['errors']);
        } finally {
            $disable = new Process([
                'fsutil.exe',
                'file',
                'SetCaseSensitiveInfo',
                $caseRoot,
                'disable',
            ]);
            $disable->run();
        }
    }

    public function testWindowsProjectRootAcceptsCanonicalCaseDifferences(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Comprobacion especifica de Windows.');
        }

        $alternateRoot = strtolower($this->projectRoot);
        if ($alternateRoot === $this->projectRoot) {
            $alternateRoot = strtoupper(substr($this->projectRoot, 0, 1))
                . substr($this->projectRoot, 1);
        }
        self::assertNotSame($this->projectRoot, $alternateRoot);
        self::assertSame(realpath($this->projectRoot), realpath($alternateRoot));

        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $targetId = 'src/scss/resources/_sample.scss';
        $this->writeFile($source, '.sample { color: core; }');
        $sync = new ManagedFileSynchronizer(
            $alternateRoot,
            $this->packageRoot,
            $this->io
        );
        $sync->queueFile(
            $source,
            $alternateRoot . '/' . $targetId,
            'resources/scss/_sample.scss',
            $targetId
        );

        $plan = $sync->preview();

        self::assertSame('ready', $plan['status']);
        self::assertSame('add', $plan['entries'][0]['action']);
        $sync->apply($plan['plan_hash']);

        $installed = $this->projectRoot . '/' . $targetId;
        self::assertFileEquals($source, $installed);
        self::assertSame(1, $sync->stats()['added']);
        self::assertSame(0, $sync->stats()['errors']);
        $this->assertTransactionDirectoryIsEmpty();

        $second = new ManagedFileSynchronizer(
            $alternateRoot,
            $this->packageRoot,
            $this->io
        );
        $second->queueFile(
            $source,
            $alternateRoot . '/' . $targetId,
            'resources/scss/_sample.scss',
            $targetId
        );
        $secondPlan = $second->preview();
        self::assertSame('ready', $secondPlan['status']);
        self::assertSame('unchanged', $secondPlan['entries'][0]['action']);
        $second->apply($secondPlan['plan_hash']);

        self::assertSame(1, $second->stats()['unchanged']);
        self::assertSame(0, $second->stats()['errors']);
        $this->assertTransactionDirectoryIsEmpty();
    }

    public function testWindowsExternalAuthorizationDistinguishesCaseOnlyRoots(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Comprobacion especifica de Windows.');
        }
        $parent = $this->root . '/CaseExternal';
        $this->filesystem->mkdir($parent);
        $enable = new Process([
            'fsutil.exe',
            'file',
            'SetCaseSensitiveInfo',
            $parent,
            'enable',
        ]);
        $enable->run();
        if (!$enable->isSuccessful()) {
            self::markTestSkipped(
                'El entorno no permite habilitar case sensitivity por directorio.'
            );
        }

        $allowedRoot = $parent . '/Allowed';
        $outsideRoot = $parent . '/allowed';
        $source = $this->packageRoot . '/misc/case-authorization.txt';
        $target = $outsideRoot . '/payload.txt';
        $this->filesystem->mkdir([$allowedRoot, $outsideRoot]);
        $this->writeFile($source, 'payload');
        $sync = $this->synchronizer();
        $sync->queueFile(
            $source,
            $target,
            'misc/case-authorization.txt',
            '@custom/payload.txt',
            ManagedFileRegistry::POLICY_INSTALL_IF_MISSING,
            null,
            false,
            $allowedRoot
        );

        $preview = $sync->preview();

        self::assertSame('blocked', $preview['status']);
        self::assertSame(
            'sync.target_outside_authorized_root',
            $preview['entries'][0]['code']
        );
        self::assertFileDoesNotExist($target);
    }

    public function testPlanHashBindsAdditionalRuntimeInputs(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $target = $this->projectRoot . '/src/scss/resources/_sample.scss';
        $scssConfig = $this->projectRoot . '/src/scss/_config.scss';
        $this->writeFile($source, '.sample { color: core; }');
        $this->writeFile($scssConfig, '$color00: #fff;');

        $sync = $this->synchronizer();
        $sync->queueFile(
            $source,
            $target,
            'resources/scss/_sample.scss',
            'src/scss/resources/_sample.scss'
        );
        $sync->bindPlanInput('scss_config', $scssConfig);
        $hash = $sync->preview()['plan_hash'];
        $this->writeFile($scssConfig, '$color00: #000;');

        $this->expectException(ManagedFilePlanChangedException::class);
        try {
            $sync->apply($hash);
        } finally {
            self::assertFileDoesNotExist($target);
        }
    }

    public function testPlanHashBindsStateTrackingPolicy(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $target = $this->projectRoot . '/src/scss/resources/_sample.scss';
        $this->writeFile($source, '.sample { color: core; }');
        $tracked = $this->synchronizer();
        $tracked->queueFile(
            $source,
            $target,
            'resources/scss/_sample.scss',
            'src/scss/resources/_sample.scss',
            null,
            null,
            true
        );
        $untracked = $this->synchronizer();
        $untracked->queueFile(
            $source,
            $target,
            'resources/scss/_sample.scss',
            'src/scss/resources/_sample.scss',
            null,
            null,
            false
        );
        $trackedPreview = $tracked->preview();
        $untrackedPreview = $untracked->preview();

        self::assertTrue($trackedPreview['entries'][0]['track_state']);
        self::assertFalse($untrackedPreview['entries'][0]['track_state']);
        self::assertNotSame(
            $trackedPreview['plan_hash'],
            $untrackedPreview['plan_hash']
        );
        try {
            $untracked->apply($trackedPreview['plan_hash']);
            self::fail('El cambio de tracking debe invalidar el plan.');
        } catch (ManagedFilePlanChangedException $exception) {
            self::assertSame('sync.plan_changed', $exception->getMessage());
        }
        self::assertFileDoesNotExist($target);
    }

    public function testMissingTargetBindingResolvesAnExistingLinkedAncestor(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $externalA = $this->root . '/linked-a';
        $externalB = $this->root . '/linked-b';
        $link = $this->projectRoot . '/linked-target';
        $target = $link . '/nested/_sample.scss';
        $this->writeFile($source, '.sample { color: core; }');
        $this->filesystem->mkdir([$externalA, $externalB]);

        if (!$this->createDirectoryLink($externalA, $link)) {
            self::markTestSkipped(
                'El entorno no permite crear enlaces de directorio.'
            );
        }

        try {
            $first = $this->synchronizer();
            $first->queueFile(
                $source,
                $target,
                'resources/scss/_sample.scss',
                '@custom-resources/scss/_sample.scss',
                null,
                null,
                true,
                $link
            );
            $hash = $first->preview()['plan_hash'];

            $this->removeDirectoryLink($link);
            self::assertTrue($this->createDirectoryLink($externalB, $link));
            $second = $this->synchronizer();
            $second->queueFile(
                $source,
                $target,
                'resources/scss/_sample.scss',
                '@custom-resources/scss/_sample.scss',
                null,
                null,
                true,
                $link
            );
            self::assertNotSame(
                $hash,
                $second->preview()['plan_hash'],
                json_encode([
                    'realpath' => realpath($link),
                    'is_link' => is_link($link),
                    'readlink' => @readlink($link),
                    'lstat' => @lstat($link),
                ], JSON_THROW_ON_ERROR)
            );

            $this->expectException(ManagedFilePlanChangedException::class);
            $second->apply($hash);
        } finally {
            $this->removeDirectoryLink($link);
        }
    }

    public function testManagedGroupRejectsExternalRootRetargetAfterStaging(): void
    {
        $source = $this->packageRoot . '/modules/ext/retarget.php';
        $rootA = $this->root . '/external-root-a';
        $rootB = $this->root . '/external-root-b';
        $alias = $this->root . '/external-root-alias';
        $target = $alias . '/retarget.php';
        $this->writeFile($source, 'payload');
        $this->filesystem->mkdir([$rootA, $rootB]);
        if (!$this->createDirectoryLink($rootA, $alias)) {
            self::markTestSkipped(
                'El entorno no permite crear enlaces de directorio.'
            );
        }

        $retargeted = false;
        $physicalRootKey = $this->normalizeTestPath($rootA);
        $filesystem = $this->filesystemWithMkdirHook(
            function (string $directory) use (
                &$retargeted,
                $physicalRootKey,
                $alias,
                $rootB
            ): void {
                if (
                    $retargeted
                    || $this->normalizeTestPath($directory) !== $physicalRootKey
                ) {
                    return;
                }
                $retargeted = true;
                $this->removeDirectoryLink($alias);
                if (!$this->createDirectoryLink($rootB, $alias)) {
                    throw new RuntimeException('No se pudo retargetear el fixture.');
                }
            }
        );

        try {
            $sync = $this->synchronizer($filesystem);
            $sync->queueFile(
                $source,
                $target,
                'modules/ext/retarget.php',
                '@ext/retarget.php',
                ManagedFileRegistry::POLICY_MANAGED,
                'module:external:retarget-test',
                true,
                $alias
            );
            $sync->apply();

            self::assertTrue($retargeted);
            self::assertFileDoesNotExist($rootA . '/retarget.php');
            self::assertFileDoesNotExist($rootB . '/retarget.php');
            self::assertSame(1, $sync->stats()['errors']);
            $this->assertTransactionDirectoryIsEmpty();
        } finally {
            $this->removeDirectoryLink($alias);
        }
    }

    public function testStandaloneCopyRejectsExternalRootRetargetDuringMkdir(): void
    {
        $source = $this->packageRoot . '/resources/img/retarget.svg';
        $rootA = $this->root . '/standalone-root-a';
        $rootB = $this->root . '/standalone-root-b';
        $alias = $this->root . '/standalone-root-alias';
        $target = $alias . '/retarget.svg';
        $this->writeFile($source, '<svg/>');
        $this->filesystem->mkdir([$rootA, $rootB]);
        if (!$this->createDirectoryLink($rootA, $alias)) {
            self::markTestSkipped(
                'El entorno no permite crear enlaces de directorio.'
            );
        }

        $retargeted = false;
        $physicalRootKey = $this->normalizeTestPath($rootA);
        $filesystem = $this->filesystemWithMkdirHook(
            function (string $directory) use (
                &$retargeted,
                $physicalRootKey,
                $alias,
                $rootB
            ): void {
                if (
                    $retargeted
                    || $this->normalizeTestPath($directory) !== $physicalRootKey
                ) {
                    return;
                }
                $retargeted = true;
                $this->removeDirectoryLink($alias);
                if (!$this->createDirectoryLink($rootB, $alias)) {
                    throw new RuntimeException('No se pudo retargetear el fixture.');
                }
            }
        );

        try {
            $sync = $this->synchronizer($filesystem);
            $sync->queueFile(
                $source,
                $target,
                'resources/img/retarget.svg',
                '@ext/retarget.svg',
                ManagedFileRegistry::POLICY_INSTALL_IF_MISSING,
                null,
                false,
                $alias
            );
            $sync->apply();

            self::assertTrue($retargeted);
            self::assertFileDoesNotExist($rootA . '/retarget.svg');
            self::assertFileDoesNotExist($rootB . '/retarget.svg');
            self::assertSame(1, $sync->stats()['errors']);
        } finally {
            $this->removeDirectoryLink($alias);
        }
    }

    public function testStandaloneCopyRejectsExternalRootRetargetDuringStaging(): void
    {
        $source = $this->packageRoot . '/misc/retarget-copy.txt';
        $rootA = $this->root . '/copy-root-a';
        $rootB = $this->root . '/copy-root-b';
        $alias = $this->root . '/copy-root-alias';
        $target = $alias . '/retarget-copy.txt';
        $this->writeFile($source, 'payload');
        $this->filesystem->mkdir([$rootA, $rootB]);
        if (!$this->createDirectoryLink($rootA, $alias)) {
            self::markTestSkipped(
                'El entorno no permite crear enlaces de directorio.'
            );
        }

        $retargeted = false;
        $filesystem = $this->filesystemWithCopyHook(
            function (string $origin, string $destination, string $phase) use (
                &$retargeted,
                $alias,
                $rootB
            ): void {
                if (
                    $retargeted
                    || $phase !== 'before'
                    || !str_contains($destination, '/sync-transactions/')
                    || !str_contains($destination, '/staged/')
                ) {
                    return;
                }
                $retargeted = true;
                $this->removeDirectoryLink($alias);
                if (!$this->createDirectoryLink($rootB, $alias)) {
                    throw new RuntimeException('No se pudo retargetear el fixture.');
                }
            }
        );

        try {
            $sync = $this->synchronizer($filesystem);
            $sync->queueFile(
                $source,
                $target,
                'misc/retarget-copy.txt',
                '@ext/retarget-copy.txt',
                ManagedFileRegistry::POLICY_INSTALL_IF_MISSING,
                null,
                false,
                $alias
            );
            $sync->apply();

            self::assertTrue($retargeted);
            self::assertFileDoesNotExist($rootA . '/retarget-copy.txt');
            self::assertFileDoesNotExist($rootB . '/retarget-copy.txt');
            self::assertSame(1, $sync->stats()['errors']);
            $this->assertTransactionDirectoryIsEmpty();
        } finally {
            $this->removeDirectoryLink($alias);
        }
    }

    public function testManagedGroupUsesFrozenPhysicalTargetDuringRetargetedPromotion(): void
    {
        $source = $this->packageRoot . '/modules/ext/retarget-rename.php';
        $rootA = $this->root . '/rename-root-a';
        $rootB = $this->root . '/rename-root-b';
        $alias = $this->root . '/rename-root-alias';
        $target = $alias . '/retarget-rename.php';
        $this->writeFile($source, 'payload');
        $this->filesystem->mkdir([$rootA, $rootB]);
        if (!$this->createDirectoryLink($rootA, $alias)) {
            self::markTestSkipped(
                'El entorno no permite crear enlaces de directorio.'
            );
        }

        $retargeted = false;
        $physicalTarget = $this->normalizeTestPath(
            $rootA . '/retarget-rename.php'
        );
        $filesystem = $this->filesystemWithRenameHook(
            function (string $origin, string $destination, string $phase) use (
                &$retargeted,
                $physicalTarget,
                $alias,
                $rootB
            ): void {
                if (
                    $retargeted
                    || $phase !== 'before'
                    || $destination !== $physicalTarget
                    || !str_contains($origin, '/staged/')
                ) {
                    return;
                }
                $retargeted = true;
                $this->removeDirectoryLink($alias);
                if (!$this->createDirectoryLink($rootB, $alias)) {
                    throw new RuntimeException('No se pudo retargetear el fixture.');
                }
            }
        );

        try {
            $sync = $this->synchronizer($filesystem);
            $sync->queueFile(
                $source,
                $target,
                'modules/ext/retarget-rename.php',
                '@ext/retarget-rename.php',
                ManagedFileRegistry::POLICY_MANAGED,
                'module:external:retarget-rename-test',
                true,
                $alias
            );
            $sync->apply();

            self::assertTrue($retargeted);
            self::assertFileDoesNotExist($rootA . '/retarget-rename.php');
            self::assertFileDoesNotExist($rootB . '/retarget-rename.php');
            self::assertSame(1, $sync->stats()['errors']);
            $this->assertTransactionDirectoryIsEmpty();
        } finally {
            $this->removeDirectoryLink($alias);
        }
    }

    public function testStandaloneAddPreservesTargetCreatedDuringMkdir(): void
    {
        $source = $this->packageRoot . '/resources/img/concurrent.svg';
        $target = $this->projectRoot . '/public/img/concurrent.svg';
        $directory = dirname($target);
        $this->writeFile($source, '<svg>core</svg>');
        $created = false;
        $directoryKey = $this->normalizeTestPath($directory);
        $filesystem = $this->filesystemWithMkdirHook(
            function (string $createdDirectory) use (
                &$created,
                $directoryKey,
                $target
            ): void {
                if (
                    $created
                    || $this->normalizeTestPath($createdDirectory)
                        !== $directoryKey
                ) {
                    return;
                }
                $created = true;
                file_put_contents($target, '<svg>consumer</svg>');
            }
        );
        $sync = $this->synchronizer($filesystem);
        $sync->queueFile(
            $source,
            $target,
            'resources/img/concurrent.svg',
            'public/img/concurrent.svg',
            ManagedFileRegistry::POLICY_INSTALL_IF_MISSING,
            null,
            false
        );

        $sync->apply();

        self::assertTrue($created);
        self::assertSame('<svg>consumer</svg>', file_get_contents($target));
        self::assertSame(1, $sync->stats()['errors']);
    }

    public function testStandaloneCopyRejectsSourceChangedInsideCopy(): void
    {
        $source = $this->packageRoot . '/resources/img/source-race.svg';
        $target = $this->projectRoot . '/public/img/source-race.svg';
        $this->writeFile($source, '<svg>approved</svg>');
        $changed = false;
        $filesystem = new class ($source, $changed) extends Filesystem {
            public function __construct(
                private readonly string $source,
                private bool &$changed
            ) {
            }

            public function copy(
                string $originFile,
                string $targetFile,
                bool $overwriteNewerFiles = false
            ) {
                if (
                    !$this->changed
                    && $originFile === $this->source
                    && str_contains(
                        strtolower(str_replace('\\', '/', $targetFile)),
                        '/sync-transactions/'
                    )
                ) {
                    $this->changed = true;
                    file_put_contents($this->source, '<svg>changed</svg>');
                }
                parent::copy($originFile, $targetFile, $overwriteNewerFiles);
            }
        };
        $sync = $this->synchronizer($filesystem);
        $sync->queueFile(
            $source,
            $target,
            'resources/img/source-race.svg',
            'public/img/source-race.svg',
            ManagedFileRegistry::POLICY_INSTALL_IF_MISSING,
            null,
            false
        );

        $sync->apply();

        self::assertTrue($changed);
        self::assertFileDoesNotExist($target);
        self::assertSame(0, $sync->stats()['added']);
        self::assertSame(1, $sync->stats()['errors']);
    }

    public function testStandaloneUpdateBreaksAHardLinkWithoutChangingItsAlias(): void
    {
        $source = $this->packageRoot . '/misc/hardlink.txt';
        $target = $this->projectRoot . '/App/hardlink.txt';
        $sentinel = $this->root . '/hardlink-sentinel.txt';
        $this->writeFile($source, 'version-one');

        $initial = $this->synchronizer();
        $initial->queueFile(
            $source,
            $target,
            'misc/hardlink.txt',
            'App/hardlink.txt',
            ManagedFileRegistry::POLICY_MANAGED,
            null
        );
        $initial->apply();
        if (!@link($target, $sentinel)) {
            self::markTestSkipped('El entorno no permite crear hard links.');
        }

        $this->writeFile($source, 'version-two');
        $update = $this->synchronizer();
        $update->queueFile(
            $source,
            $target,
            'misc/hardlink.txt',
            'App/hardlink.txt',
            ManagedFileRegistry::POLICY_MANAGED,
            null
        );
        $update->apply();

        self::assertSame('version-two', file_get_contents($target));
        self::assertSame('version-one', file_get_contents($sentinel));
        self::assertSame(1, $update->stats()['updated']);
        self::assertSame(0, $update->stats()['errors']);
        $this->assertTransactionDirectoryIsEmpty();
    }

    public function testProjectTargetCannotEscapeThroughALinkedAncestor(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $external = $this->root . '/outside-project';
        $link = $this->projectRoot . '/redirect';
        $target = $link . '/created.scss';
        $this->writeFile($source, '.sample { color: core; }');
        $this->filesystem->mkdir($external);

        if (!$this->createDirectoryLink($external, $link)) {
            self::markTestSkipped(
                'El entorno no permite crear enlaces de directorio.'
            );
        }

        try {
            $sync = $this->synchronizer();
            try {
                $sync->queueFile(
                    $source,
                    $target,
                    'resources/scss/_sample.scss',
                    'redirect/created.scss'
                );
                self::fail('Una ruta enlazada no debe entrar en la cola project.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString(
                    'ruta de proyecto enlazada',
                    $exception->getMessage()
                );
            }
        } finally {
            self::assertFileDoesNotExist($external . '/created.scss');
            $this->removeDirectoryLink($link);
        }
    }

    public function testExplicitApplyRejectsAPreparationBlocker(): void
    {
        $sync = $this->synchronizer();
        $sync->queueDirectory(
            $this->packageRoot . '/missing-canonical-directory',
            $this->projectRoot . '/App/missing',
            'stubs/App/missing',
            'App/missing'
        );
        $preview = $sync->preview();

        self::assertSame('blocked', $preview['status']);
        self::assertSame(
            ['sync.canonical_source_missing'],
            $preview['blockers']
        );

        $this->expectException(ManagedFilePlanBlockedException::class);
        $this->expectExceptionMessage('sync.plan_blocked');
        $sync->apply($preview['plan_hash']);
    }

    public function testInvalidStateBlocksExplicitApplyBeforeTargetWrites(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $target = $this->projectRoot . '/src/scss/resources/_sample.scss';
        $state = $this->projectRoot . '/.liquidstack/core/managed-files.json';
        $this->writeFile($source, '.sample { color: core; }');
        $this->writeFile($state, '{"schema":');

        $sync = $this->synchronizer();
        $sync->queueFile(
            $source,
            $target,
            'resources/scss/_sample.scss',
            'src/scss/resources/_sample.scss'
        );
        $preview = $sync->preview();

        self::assertSame('blocked', $preview['status']);
        self::assertContains('sync.state_invalid', $preview['blockers']);
        try {
            $sync->apply($preview['plan_hash']);
            self::fail('Un estado inválido debe bloquear el apply explícito.');
        } catch (ManagedFilePlanBlockedException $exception) {
            self::assertSame('sync.plan_blocked', $exception->getMessage());
        }

        self::assertFileDoesNotExist($target);
        self::assertSame('{"schema":', file_get_contents($state));
    }

    public function testLinkedStatePathInsideProjectBlocksWithoutWrites(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $target = $this->projectRoot . '/src/scss/resources/_sample.scss';
        $stateStore = $this->projectRoot . '/state-store';
        $stateLink = $this->projectRoot . '/state-link';
        $state = $stateLink . '/managed-files.json';
        $this->writeFile($source, '.sample { color: core; }');
        $this->filesystem->mkdir($stateStore);
        if (!$this->createDirectoryLink($stateStore, $stateLink)) {
            self::markTestSkipped(
                'El entorno no permite crear enlaces de directorio.'
            );
        }

        try {
            $sync = new ManagedFileSynchronizer(
                $this->projectRoot,
                $this->packageRoot,
                $this->io,
                null,
                $state
            );
            $sync->queueFile(
                $source,
                $target,
                'resources/scss/_sample.scss',
                'src/scss/resources/_sample.scss'
            );
            $preview = $sync->preview();

            self::assertSame('blocked', $preview['status']);
            self::assertContains('sync.state_invalid', $preview['blockers']);
            try {
                $sync->apply($preview['plan_hash']);
                self::fail('Un estado enlazado debe bloquear el apply explícito.');
            } catch (ManagedFilePlanBlockedException $exception) {
                self::assertSame('sync.plan_blocked', $exception->getMessage());
            }
            self::assertFileDoesNotExist($target);
            self::assertFileDoesNotExist(
                $stateStore . '/managed-files.json'
            );
        } finally {
            $this->removeDirectoryLink($stateLink);
        }
    }

    public function testInvalidTransactionGitIgnoreBlocksReadOnly(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $target = $this->projectRoot . '/src/scss/resources/_sample.scss';
        $gitIgnore = $this->projectRoot
            . '/.liquidstack/core/sync-transactions/.gitignore';
        $this->writeFile($source, '.sample { color: core; }');
        $this->writeFile($gitIgnore, "custom\n");
        $sync = $this->synchronizer();
        $sync->queueFile(
            $source,
            $target,
            'resources/scss/_sample.scss',
            'src/scss/resources/_sample.scss'
        );
        $before = $this->snapshot($this->projectRoot);

        $preview = $sync->preview();

        self::assertSame($before, $this->snapshot($this->projectRoot));
        self::assertSame('blocked', $preview['status']);
        self::assertContains(
            'sync.transaction_gitignore_invalid',
            $preview['blockers']
        );
        try {
            $sync->apply($preview['plan_hash']);
            self::fail('El metadato transaccional inválido debe bloquear.');
        } catch (ManagedFilePlanBlockedException $exception) {
            self::assertSame('sync.plan_blocked', $exception->getMessage());
        }
        self::assertSame($before, $this->snapshot($this->projectRoot));
        self::assertFileDoesNotExist($target);
    }

    public function testStalePlanDoesNotCreateMissingTransactionGitIgnore(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $target = $this->projectRoot . '/src/scss/resources/_sample.scss';
        $transactionRoot = $this->projectRoot
            . '/.liquidstack/core/sync-transactions';
        $gitIgnore = $transactionRoot . '/.gitignore';
        $this->filesystem->mkdir($transactionRoot);
        $this->writeFile($source, '.sample { color: core; }');
        $sync = $this->synchronizer();
        $sync->queueFile(
            $source,
            $target,
            'resources/scss/_sample.scss',
            'src/scss/resources/_sample.scss'
        );
        $planHash = $sync->preview()['plan_hash'];
        $this->writeFile($source, '.sample { color: changed; }');

        try {
            $sync->apply($planHash);
            self::fail('El plan obsoleto debe rechazarse sin metadatos nuevos.');
        } catch (ManagedFilePlanChangedException $exception) {
            self::assertSame('sync.plan_changed', $exception->getMessage());
        }

        self::assertFileDoesNotExist($gitIgnore);
        self::assertFileDoesNotExist($target);
    }

    public function testPreviewReportsButDoesNotRecoverAPendingJournal(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $target = $this->projectRoot
            . '/src/scss/resources/_sample.scss';
        $journal = $this->projectRoot
            . '/.liquidstack/core/sync-transactions/'
            . str_repeat('a', 24)
            . '/journal.json';
        $this->writeFile($source, '.sample { color: core; }');
        $this->writeFile($journal, '{"state":"prepared"}');
        $sync = $this->synchronizer();
        $sync->queueFile(
            $source,
            $target,
            'resources/scss/_sample.scss',
            'src/scss/resources/_sample.scss'
        );
        $before = $this->snapshot($this->projectRoot);

        $preview = $sync->preview();

        self::assertSame('blocked', $preview['status']);
        self::assertSame(1, $preview['pending_transactions']);
        self::assertSame($before, $this->snapshot($this->projectRoot));
        self::assertFileExists($journal);
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

    public function testPromotedConsumerScssIsAdoptedWithoutBlockingItsGroup(): void
    {
        $scssSource = $this->packageRoot
            . '/resources/scss/_sample.scss';
        $scssTarget = $this->projectRoot
            . '/src/scss/resources/_sample.scss';
        $templateSource = $this->packageRoot
            . '/stubs/App/templates/_sample.html';
        $templateTarget = $this->projectRoot
            . '/App/templates/_sample.html';
        $scssSourceId = 'resources/scss/_sample.scss';
        $scssTargetId = 'src/scss/resources/_sample.scss';
        $templateSourceId = 'stubs/App/templates/_sample.html';
        $templateTargetId = 'App/templates/_sample.html';

        $this->writeFile($scssSource, ".sample {\n  color: red;\n}\n");
        $this->writeFile($templateSource, '<article>sample</article>');

        $initial = $this->synchronizer();
        $initial->queueFile(
            $scssSource,
            $scssTarget,
            $scssSourceId,
            $scssTargetId
        );
        $initial->queueFile(
            $templateSource,
            $templateTarget,
            $templateSourceId,
            $templateTargetId
        );
        $initial->apply();

        $promoted = ".sample {\n  color: blue;\n}\n";
        $this->writeFile($scssSource, $promoted);
        $this->writeFile(
            $scssTarget,
            str_replace("\n", "\r\n", $promoted)
        );

        $adoption = $this->synchronizer();
        $adoption->queueFile(
            $scssSource,
            $scssTarget,
            $scssSourceId,
            $scssTargetId
        );
        $adoption->queueFile(
            $templateSource,
            $templateTarget,
            $templateSourceId,
            $templateTargetId
        );
        $adoption->apply();

        self::assertSame(0, $adoption->stats()['preserved']);
        self::assertSame(2, $adoption->stats()['unchanged']);

        $nextCore = ".sample {\n  color: green;\n}\n";
        $this->writeFile($scssSource, $nextCore);

        $update = $this->synchronizer();
        $update->queueFile(
            $scssSource,
            $scssTarget,
            $scssSourceId,
            $scssTargetId
        );
        $update->queueFile(
            $templateSource,
            $templateTarget,
            $templateSourceId,
            $templateTargetId
        );
        $update->apply();

        self::assertSame(1, $update->stats()['updated']);
        self::assertSame($nextCore, file_get_contents($scssTarget));
        self::assertSame(
            '<article>sample</article>',
            file_get_contents($templateTarget)
        );
    }

    public function testManagedGroupRemovesNewFilesWhenLaterWriteFails(): void
    {
        $addedSource = $this->packageRoot . '/modules/blog/added.php';
        $updatedSource = $this->packageRoot . '/modules/blog/updated.php';
        $addedTarget = $this->projectRoot . '/App/controllers/added.php';
        $updatedTarget = $this->projectRoot . '/App/controllers/updated.php';
        $group = 'module:blog:mixed-atomic-test';

        $this->writeFile($updatedSource, 'updated-v1');
        $initial = $this->synchronizer();
        $this->queueManagedTestFile(
            $initial,
            $updatedSource,
            $updatedTarget,
            'updated.php',
            $group
        );
        $initial->apply();

        $statePath = $this->projectRoot
            . '/.liquidstack/core/managed-files.json';
        $initialState = (string) file_get_contents($statePath);
        $this->writeFile($addedSource, 'added-v1');
        $this->writeFile($updatedSource, 'updated-v2');

        $failed = $this->synchronizer(
            $this->filesystemFailingPromotionTo($updatedTarget)
        );
        $this->queueManagedTestFile(
            $failed,
            $addedSource,
            $addedTarget,
            'added.php',
            $group
        );
        $this->queueManagedTestFile(
            $failed,
            $updatedSource,
            $updatedTarget,
            'updated.php',
            $group
        );
        $failed->apply();

        self::assertFileDoesNotExist($addedTarget);
        self::assertSame('updated-v1', file_get_contents($updatedTarget));
        self::assertSame($initialState, file_get_contents($statePath));
        self::assertSame(0, $failed->stats()['added']);
        self::assertSame(0, $failed->stats()['updated']);
        self::assertSame(1, $failed->stats()['errors']);
        $this->assertTransactionDirectoryIsEmpty();
    }

    public function testUngroupedManagedFileStillAppliesAfterGroupFailure(): void
    {
        $firstSource = $this->packageRoot . '/modules/blog/first.php';
        $secondSource = $this->packageRoot . '/modules/blog/second.php';
        $standaloneSource = $this->packageRoot . '/modules/blog/standalone.php';
        $firstTarget = $this->projectRoot . '/App/controllers/first.php';
        $secondTarget = $this->projectRoot . '/App/controllers/second.php';
        $standaloneTarget = $this->projectRoot
            . '/public/assets/modules/blog/standalone.php';
        $group = 'module:blog:failed-test';

        $this->writeFile($firstSource, 'first');
        $this->writeFile($secondSource, 'second');
        $this->writeFile($standaloneSource, 'standalone');

        $sync = $this->synchronizer(
            $this->filesystemFailingPromotionTo($secondTarget)
        );
        $this->queueManagedTestFile(
            $sync,
            $firstSource,
            $firstTarget,
            'first.php',
            $group
        );
        $this->queueManagedTestFile(
            $sync,
            $secondSource,
            $secondTarget,
            'second.php',
            $group
        );
        $sync->queueFile(
            $standaloneSource,
            $standaloneTarget,
            'modules/blog/standalone.php',
            'public/assets/modules/blog/standalone.php',
            ManagedFileRegistry::POLICY_MANAGED,
            null
        );
        $sync->apply();

        self::assertFileDoesNotExist($firstTarget);
        self::assertFileDoesNotExist($secondTarget);
        self::assertSame(
            'standalone',
            file_get_contents($standaloneTarget)
        );
        self::assertSame(1, $sync->stats()['added']);
        self::assertSame(1, $sync->stats()['errors']);
    }

    public function testInterruptedPreparedTransactionIsRecoveredWithEmptyQueue(): void
    {
        $targetId = 'App/controllers/interrupted.php';
        $target = $this->projectRoot . '/' . $targetId;
        $transactionRoot = $this->projectRoot
            . '/.liquidstack/core/sync-transactions/'
            . str_repeat('a', 24);

        $this->writeFile($target, 'new-interrupted');
        $this->writeFile(
            $transactionRoot . '/backup/1',
            'original-before-interruption'
        );
        $this->writeFile(
            $transactionRoot . '/journal.json',
            json_encode([
                'schema' => 1,
                'group' => 'module:blog:interrupted-test',
                'status' => 'prepared',
                'files' => [[
                    'target_id' => $targetId,
                    'slot' => 1,
                    'had_target' => true,
                    'original_hash' => 'sha256:'
                        . hash('sha256', 'original-before-interruption'),
                    'expected_hash' => 'sha256:'
                        . hash('sha256', 'new-interrupted'),
                    'target_scope' => 'project',
                    'target_binding' => null,
                ]],
            ],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );

        $sync = $this->synchronizer();
        $sync->apply();

        self::assertSame(
            'original-before-interruption',
            file_get_contents($target)
        );
        self::assertDirectoryDoesNotExist($transactionRoot);
    }

    public function testWindowsPreparedRecoveryPreservesCaseSensitiveTarget(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Comprobacion especifica de Windows.');
        }
        $caseRoot = $this->projectRoot . '/CaseRecovery';
        $this->filesystem->mkdir($caseRoot);
        $enable = new Process([
            'fsutil.exe',
            'file',
            'SetCaseSensitiveInfo',
            $caseRoot,
            'enable',
        ]);
        $enable->run();
        if (!$enable->isSuccessful()) {
            self::markTestSkipped(
                'El entorno no permite habilitar case sensitivity por directorio.'
            );
        }

        try {
            $targetId = 'CaseRecovery/Foo.txt';
            $target = $this->projectRoot . '/' . $targetId;
            $wrongCase = $caseRoot . '/foo.txt';
            $transactionRoot = $this->projectRoot
                . '/.liquidstack/core/sync-transactions/'
                . str_repeat('c', 24);
            $this->writeFile($target, 'new-interrupted');
            $this->writeFile(
                $transactionRoot . '/backup/1',
                'original-before-interruption'
            );
            $this->writeFile(
                $transactionRoot . '/journal.json',
                json_encode([
                    'schema' => 1,
                    'group' => 'standalone:case-recovery',
                    'status' => 'prepared',
                    'files' => [[
                        'target_id' => $targetId,
                        'slot' => 1,
                        'had_target' => true,
                        'original_hash' => 'sha256:'
                            . hash('sha256', 'original-before-interruption'),
                        'expected_hash' => 'sha256:'
                            . hash('sha256', 'new-interrupted'),
                        'target_scope' => 'project',
                        'target_binding' => null,
                    ]],
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
            );

            $this->synchronizer()->apply();

            self::assertSame(
                'original-before-interruption',
                file_get_contents($target)
            );
            self::assertFileDoesNotExist($wrongCase);
            self::assertDirectoryDoesNotExist($transactionRoot);
        } finally {
            $disable = new Process([
                'fsutil.exe',
                'file',
                'SetCaseSensitiveInfo',
                $caseRoot,
                'disable',
            ]);
            $disable->run();
        }
    }

    public function testExternalCleanupPendingRecoversWithoutOriginalQueue(): void
    {
        $transactionRoot = $this->projectRoot
            . '/.liquidstack/core/sync-transactions/'
            . str_repeat('e', 24);
        $this->writeFile(
            $transactionRoot . '/journal.json',
            json_encode([
                'schema' => 1,
                'group' => 'resource:external-cleanup-test',
                'status' => 'cleanup_pending',
                'files' => [[
                    'target_id' => '@custom-resources/scss/_sample.scss',
                    'slot' => 1,
                    'had_target' => false,
                    'original_hash' => null,
                    'expected_hash' => 'sha256:' . str_repeat('a', 64),
                    'target_scope' => 'external',
                    'target_binding' => 'sha256:' . str_repeat('b', 64),
                ]],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );

        $this->synchronizer()->apply();

        self::assertDirectoryDoesNotExist($transactionRoot);
    }

    public function testCleanupRevalidatesSlotsCreatedDuringRemoval(): void
    {
        $transactionRoot = $this->projectRoot
            . '/.liquidstack/core/sync-transactions/'
            . str_repeat('d', 24);
        $watcher = $transactionRoot . '/backup/watcher.tmp';
        $this->writeFile($transactionRoot . '/staged/1', 'staged');
        $this->writeFile($transactionRoot . '/backup/1', 'backup');
        $this->writeFile(
            $transactionRoot . '/journal.json',
            json_encode([
                'schema' => 1,
                'group' => 'resource:cleanup-race-test',
                'status' => 'cleanup_pending',
                'files' => [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
        $created = false;
        $remover = static function (
            string $phase,
            string $path
        ) use (&$created, $watcher): void {
            $normalized = strtolower(str_replace('\\', '/', $path));
            if (
                $phase === 'after'
                && !$created
                && preg_match('#/staged/[1-9][0-9]*\z#', $normalized) === 1
            ) {
                $created = true;
                file_put_contents($watcher, 'must-survive');
            }
        };

        try {
            $this->synchronizer(null, $remover)->apply();
            self::fail('Cleanup debe rechazar un slot concurrente desconocido.');
        } catch (UnexpectedValueException $exception) {
            self::assertSame(
                'sync.transaction_cleanup_layout_invalid',
                $exception->getMessage()
            );
        }

        self::assertSame('must-survive', file_get_contents($watcher));
        self::assertFileExists($transactionRoot . '/journal.json');
    }

    public function testCleanupRejectsRedirectedBackupAndPreservesExternalData(): void
    {
        $transactionRoot = $this->projectRoot
            . '/.liquidstack/core/sync-transactions/'
            . str_repeat('f', 24);
        $external = $this->root . '/external-backup-sentinel';
        $backupLink = $transactionRoot . '/backup';
        $sentinel = $external . '/sentinel.txt';
        $this->writeFile($sentinel, 'must-survive');
        $this->writeFile(
            $transactionRoot . '/journal.json',
            json_encode([
                'schema' => 1,
                'group' => 'resource:unsafe-cleanup-test',
                'status' => 'cleanup_pending',
                'files' => [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
        if (!$this->createDirectoryLink($external, $backupLink)) {
            self::markTestSkipped(
                'El entorno no permite crear enlaces de directorio.'
            );
        }

        try {
            $this->synchronizer()->apply();
            self::fail('Cleanup debe rechazar un backup redirigido.');
        } catch (UnexpectedValueException $exception) {
            self::assertSame(
                'sync.transaction_cleanup_layout_invalid',
                $exception->getMessage()
            );
        } finally {
            self::assertSame('must-survive', file_get_contents($sentinel));
            self::assertFileExists($transactionRoot . '/journal.json');
            $this->removeDirectoryLink($backupLink);
        }
    }

    public function testRecoveryRejectsLinkedTransactionPath(): void
    {
        $external = $this->root . '/linked-runtime';
        $link = $this->projectRoot . '/.liquidstack';
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $target = $this->projectRoot . '/src/scss/resources/_sample.scss';
        $this->filesystem->mkdir($external);
        $this->writeFile($source, '.sample { color: core; }');
        if (!$this->createDirectoryLink($external, $link)) {
            self::markTestSkipped(
                'El entorno no permite crear enlaces de directorio.'
            );
        }

        try {
            $sync = $this->synchronizer();
            $sync->queueFile(
                $source,
                $target,
                'resources/scss/_sample.scss',
                'src/scss/resources/_sample.scss'
            );
            $preview = $sync->preview();
            self::assertSame('blocked', $preview['status']);
            self::assertContains(
                'sync.transaction_root_invalid',
                $preview['blockers']
            );
            try {
                $sync->apply($preview['plan_hash']);
                self::fail('Recovery enlazado debe bloquear el apply.');
            } catch (ManagedFilePlanBlockedException $exception) {
                self::assertSame('sync.plan_blocked', $exception->getMessage());
            }
            self::assertFileDoesNotExist($target);
        } finally {
            $this->removeDirectoryLink($link);
        }
    }

    public function testInterruptedCommittedTransactionIsFinalizedNotRolledBack(): void
    {
        $targetId = 'App/controllers/committed.php';
        $target = $this->projectRoot . '/' . $targetId;
        $safeSource = $this->packageRoot . '/modules/blog/safe.php';
        $safeTarget = $this->projectRoot
            . '/public/assets/modules/blog/safe.php';
        $transactionRoot = $this->projectRoot
            . '/.liquidstack/core/sync-transactions/'
            . str_repeat('b', 24);

        $this->writeFile($target, 'committed-new');
        $this->writeFile($transactionRoot . '/backup/1', 'previous');
        $this->writeFile(
            $transactionRoot . '/journal.json',
            json_encode([
                'schema' => 1,
                'group' => 'module:blog:committed-test',
                'status' => 'committed',
                'files' => [[
                    'target_id' => $targetId,
                    'slot' => 1,
                    'had_target' => true,
                    'original_hash' => 'sha256:'
                        . hash('sha256', 'previous'),
                    'expected_hash' => 'sha256:'
                        . hash('sha256', 'committed-new'),
                ]],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
        $this->writeFile($safeSource, 'safe');

        $sync = $this->synchronizer();
        $sync->queueFile(
            $safeSource,
            $safeTarget,
            'modules/blog/safe.php',
            'public/assets/modules/blog/safe.php',
            ManagedFileRegistry::POLICY_MANAGED,
            null
        );
        $sync->apply();

        self::assertSame('committed-new', file_get_contents($target));
        self::assertDirectoryDoesNotExist($transactionRoot);
    }

    public function testCommittedCleanupRetriesATransientWindowsLock(): void
    {
        $pair = $this->updatedManagedPair(
            'module:blog:transient-cleanup-test'
        );
        $cleanup = (object) [
            'attempts' => 0,
            'failures_remaining' => 2,
            'blocked' => true,
        ];

        $sync = $this->synchronizer(
            null,
            $this->transactionFileRemoverFailingCleanup($cleanup)
        );
        $this->queueManagedPair($sync, $pair);
        $sync->apply();

        self::assertSame('first-v2', file_get_contents($pair['first_target']));
        self::assertSame('second-v2', file_get_contents($pair['second_target']));
        self::assertGreaterThanOrEqual(3, $cleanup->attempts);
        self::assertSame(0, $sync->stats()['errors']);
        $this->assertTransactionDirectoryIsEmpty();
    }

    public function testCleanupTransitionFailureKeepsCommittedState(): void
    {
        $source = $this->packageRoot . '/modules/blog/cleanup-transition.php';
        $target = $this->projectRoot . '/App/controllers/cleanup-transition.php';
        $group = 'module:blog:cleanup-transition-test';
        $this->writeFile($source, 'version-one');
        $initial = $this->synchronizer();
        $this->queueManagedTestFile(
            $initial,
            $source,
            $target,
            'cleanup-transition.php',
            $group
        );
        $initial->apply();
        $this->writeFile($source, 'version-two');

        $failure = (object) ['failed' => false];
        $filesystem = new class ($failure) extends Filesystem {
            public function __construct(private readonly object $failure)
            {
            }

            public function dumpFile(string $filename, $content): void
            {
                $normalized = strtolower(str_replace('\\', '/', $filename));
                if (
                    !$this->failure->failed
                    && preg_match(
                        '#/sync-transactions/[a-f0-9]{24}/journal\.json\z#',
                        $normalized
                    ) === 1
                    && str_contains($content, '"status": "cleanup_pending"')
                ) {
                    $this->failure->failed = true;
                    throw new RuntimeException(
                        'fallo inyectado al marcar cleanup pendiente'
                    );
                }
                parent::dumpFile($filename, $content);
            }
        };
        $sync = $this->synchronizer($filesystem);
        $this->queueManagedTestFile(
            $sync,
            $source,
            $target,
            'cleanup-transition.php',
            $group
        );

        $sync->apply();

        $state = json_decode(
            (string) file_get_contents(
                $this->projectRoot . '/.liquidstack/core/managed-files.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('version-two', file_get_contents($target));
        self::assertContains(
            'sha256:' . hash('sha256', 'version-two'),
            $state['files']['App/controllers/cleanup-transition.php']['fingerprints']
        );
        self::assertSame(1, $sync->stats()['updated']);
        self::assertSame(0, $sync->stats()['errors']);
        self::assertStringNotContainsString(
            'se restauraron todos sus ficheros',
            $this->io->getOutput()
        );
        $journal = json_decode(
            (string) file_get_contents(
                $this->onlyPendingTransactionRoot() . '/journal.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('committed', $journal['status']);

        $this->writeFile($target, 'consumer-custom-after-commit');
        $this->synchronizer($filesystem)->apply();
        self::assertSame(
            'consumer-custom-after-commit',
            file_get_contents($target)
        );
        $this->assertTransactionDirectoryIsEmpty();
    }

    public function testFinalRmdirRaceRestoresCleanupJournal(): void
    {
        $source = $this->packageRoot . '/modules/blog/final-rmdir.php';
        $target = $this->projectRoot . '/App/controllers/final-rmdir.php';
        $group = 'module:blog:final-rmdir-test';
        $this->writeFile($source, 'version-one');
        $initial = $this->synchronizer();
        $this->queueManagedTestFile(
            $initial,
            $source,
            $target,
            'final-rmdir.php',
            $group
        );
        $initial->apply();
        $this->writeFile($source, 'version-two');

        $watcher = (object) ['path' => null, 'created' => false];
        $remover = static function (
            string $phase,
            string $path
        ) use ($watcher): void {
            $normalized = strtolower(str_replace('\\', '/', $path));
            if (
                $phase === 'after'
                && !$watcher->created
                && preg_match(
                    '#/sync-transactions/[a-f0-9]{24}/journal\.json\z#',
                    $normalized
                ) === 1
            ) {
                $watcher->created = true;
                $watcher->path = dirname($path) . '/watcher.tmp';
                file_put_contents($watcher->path, 'must-survive');
            }
        };
        $sync = $this->synchronizer(null, $remover);
        $this->queueManagedTestFile(
            $sync,
            $source,
            $target,
            'final-rmdir.php',
            $group
        );

        $sync->apply();

        $transactionRoot = $this->onlyPendingTransactionRoot();
        self::assertSame('version-two', file_get_contents($target));
        self::assertSame(1, $sync->stats()['updated']);
        self::assertSame(0, $sync->stats()['errors']);
        self::assertSame('must-survive', file_get_contents($watcher->path));
        $journal = json_decode(
            (string) file_get_contents($transactionRoot . '/journal.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('cleanup_pending', $journal['status']);

        $this->filesystem->remove($watcher->path);
        $this->synchronizer(null, $remover)->apply();
        self::assertSame('version-two', file_get_contents($target));
        $this->assertTransactionDirectoryIsEmpty();
    }

    public function testDeferredCommittedCleanupDoesNotBlockANewerVersion(): void
    {
        $pair = $this->updatedManagedPair(
            'module:blog:deferred-cleanup-test'
        );
        $cleanup = (object) [
            'attempts' => 0,
            'failures_remaining' => null,
            'blocked' => true,
        ];
        $remover = $this->transactionFileRemoverFailingCleanup($cleanup);

        $second = $this->synchronizer(null, $remover);
        $this->queueManagedPair($second, $pair);
        $second->apply();

        $transactionRoot = $this->onlyPendingTransactionRoot();
        $journal = json_decode(
            (string) file_get_contents($transactionRoot . '/journal.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('cleanup_pending', $journal['status'] ?? null);
        self::assertSame('second-v2', file_get_contents($pair['second_target']));
        self::assertSame(0, $second->stats()['errors']);

        $this->writeFile($pair['second_source'], 'second-v3');
        $third = $this->synchronizer(null, $remover);
        $this->queueManagedPair($third, $pair);
        $third->apply();

        self::assertSame('second-v3', file_get_contents($pair['second_target']));
        self::assertSame(0, $third->stats()['errors']);

        $cleanup->blocked = false;
        $this->synchronizer(null, $remover)->apply();
        $this->assertTransactionDirectoryIsEmpty();
    }

    public function testLockedWindowsStyleBackupFailureRestoresEarlierTargets(): void
    {
        $pair = $this->updatedManagedPair(
            'module:blog:locked-target-test'
        );

        $sync = $this->synchronizer(
            $this->filesystemFailingBackupOf($pair['second_target'])
        );
        $this->queueManagedPair($sync, $pair);
        $sync->apply();

        self::assertSame('first-v1', file_get_contents($pair['first_target']));
        self::assertSame(
            'second-v1',
            file_get_contents($pair['second_target'])
        );
        self::assertSame(1, $sync->stats()['errors']);
        self::assertSame(0, $sync->stats()['updated']);
        $this->assertTransactionDirectoryIsEmpty();
    }

    public function testRollbackNeverTraversesATargetChangedToDirectoryLink(): void
    {
        $pair = $this->updatedManagedPair(
            'module:blog:rollback-link-race-test'
        );
        $external = $this->root . '/rollback-link-sentinel';
        $probe = $this->root . '/rollback-link-probe';
        $sentinel = $external . '/must-survive.txt';
        $this->writeFile($sentinel, 'must-survive');
        if (!$this->createDirectoryLink($external, $probe)) {
            self::markTestSkipped(
                'El entorno no permite crear enlaces de directorio.'
            );
        }
        $this->removeDirectoryLink($probe);

        $changed = false;
        $firstTarget = $this->normalizeTestPath($pair['first_target']);
        $observer = function (
            string $phase,
            string $path
        ) use (&$changed, $firstTarget, $external): void {
            if (
                $phase !== 'before'
                || $changed
                || $this->normalizeTestPath($path) !== $firstTarget
            ) {
                return;
            }
            $changed = true;
            if (!@unlink($path)) {
                throw new RuntimeException('No se pudo preparar la carrera.');
            }
            if (!$this->createDirectoryLink($external, $path)) {
                throw new RuntimeException('No se pudo crear el enlace de carrera.');
            }
        };
        $sync = $this->synchronizer(
            $this->filesystemFailingPromotionTo($pair['second_target']),
            $observer
        );
        $this->queueManagedPair($sync, $pair);

        try {
            $sync->apply();
            self::fail('El cambio de tipo durante rollback debe ser fatal.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'ni restaurar por completo',
                $exception->getMessage()
            );
        } finally {
            self::assertSame('must-survive', file_get_contents($sentinel));
            $this->removeDirectoryLink($pair['first_target']);
        }

        $recovery = $this->synchronizer();
        $this->queueManagedPair($recovery, $pair);
        $recovery->apply();
        self::assertSame('first-v2', file_get_contents($pair['first_target']));
        self::assertSame('second-v2', file_get_contents($pair['second_target']));
        self::assertSame('must-survive', file_get_contents($sentinel));
        $this->assertTransactionDirectoryIsEmpty();
    }

    public function testRollbackSupportsAStableExternalJunction(): void
    {
        $realRoot = $this->root . '/external-managed-real';
        $aliasRoot = $this->root . '/external-managed-alias';
        $sourceOne = $this->packageRoot . '/modules/ext/one.php';
        $sourceTwo = $this->packageRoot . '/modules/ext/two.php';
        $targetOne = $aliasRoot . '/one.php';
        $targetTwo = $aliasRoot . '/two.php';
        $group = 'module:external:junction-rollback-test';
        $this->writeFile($sourceOne, 'one-v1');
        $this->writeFile($sourceTwo, 'two-v1');
        $this->filesystem->mkdir($realRoot);
        if (!$this->createDirectoryLink($realRoot, $aliasRoot)) {
            self::markTestSkipped(
                'El entorno no permite crear enlaces de directorio.'
            );
        }

        $queue = function (ManagedFileSynchronizer $sync) use (
            $sourceOne,
            $sourceTwo,
            $targetOne,
            $targetTwo,
            $aliasRoot,
            $group
        ): void {
            $sync->queueFile(
                $sourceOne,
                $targetOne,
                'modules/ext/one.php',
                '@ext/one.php',
                ManagedFileRegistry::POLICY_MANAGED,
                $group,
                true,
                $aliasRoot
            );
            $sync->queueFile(
                $sourceTwo,
                $targetTwo,
                'modules/ext/two.php',
                '@ext/two.php',
                ManagedFileRegistry::POLICY_MANAGED,
                $group,
                true,
                $aliasRoot
            );
        };

        try {
            $initial = $this->synchronizer();
            $queue($initial);
            $initial->apply();
            $this->writeFile($sourceOne, 'one-v2');
            $this->writeFile($sourceTwo, 'two-v2');

            $update = $this->synchronizer(
                $this->filesystemFailingPromotionTo($realRoot . '/two.php')
            );
            $queue($update);
            $update->apply();

            self::assertSame('one-v1', file_get_contents($targetOne));
            self::assertSame('two-v1', file_get_contents($targetTwo));
            self::assertSame(0, $update->stats()['updated']);
            self::assertSame(1, $update->stats()['errors']);
            $this->assertTransactionDirectoryIsEmpty();
        } finally {
            $this->removeDirectoryLink($aliasRoot);
        }
    }

    public function testTargetMutationDuringBackupIsPreservedAndFatal(): void
    {
        $source = $this->packageRoot . '/modules/blog/raced.php';
        $target = $this->projectRoot . '/App/controllers/raced.php';
        $group = 'module:blog:backup-race-test';
        $this->writeFile($source, 'version-one');

        $initial = $this->synchronizer();
        $this->queueManagedTestFile(
            $initial,
            $source,
            $target,
            'raced.php',
            $group
        );
        $initial->apply();
        $statePath = $this->projectRoot
            . '/.liquidstack/core/managed-files.json';
        $initialState = (string) file_get_contents($statePath);
        $this->writeFile($source, 'version-two');

        $sync = $this->synchronizer(
            $this->filesystemChangingTargetBeforeBackup($target)
        );
        $this->queueManagedTestFile(
            $sync,
            $source,
            $target,
            'raced.php',
            $group
        );

        try {
            $sync->apply();
            self::fail('La mutacion durante el backup debe ser fatal.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'ni restaurar por completo',
                $exception->getMessage()
            );
        }

        self::assertFileDoesNotExist($target);
        self::assertSame($initialState, file_get_contents($statePath));
        self::assertSame(
            'concurrent-during-backup',
            file_get_contents(
                $this->onlyPendingTransactionRoot() . '/backup/1'
            )
        );
    }

    public function testTargetCreatedBetweenBackupAndInstallIsPreserved(): void
    {
        $source = $this->packageRoot . '/modules/blog/gap.php';
        $target = $this->projectRoot . '/App/controllers/gap.php';
        $group = 'module:blog:install-gap-test';
        $this->writeFile($source, 'gap-v1');
        $initial = $this->synchronizer();
        $this->queueManagedTestFile(
            $initial,
            $source,
            $target,
            'gap.php',
            $group
        );
        $initial->apply();
        $statePath = $this->projectRoot
            . '/.liquidstack/core/managed-files.json';
        $initialState = (string) file_get_contents($statePath);
        $this->writeFile($source, 'gap-v2');

        $sync = $this->synchronizer(
            $this->filesystemCreatingTargetAfterBackup($target)
        );
        $this->queueManagedTestFile(
            $sync,
            $source,
            $target,
            'gap.php',
            $group
        );

        try {
            $sync->apply();
            self::fail('Un destino recreado durante el hueco debe abortar.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'ni restaurar por completo',
                $exception->getMessage()
            );
            self::assertStringContainsString(
                'reaparecio antes de instalarlo',
                $exception->getMessage()
            );
        }

        self::assertSame('concurrent-in-gap', file_get_contents($target));
        self::assertSame($initialState, file_get_contents($statePath));
        self::assertSame(
            'gap-v1',
            file_get_contents(
                $this->onlyPendingTransactionRoot() . '/backup/1'
            )
        );
    }

    public function testConcurrentChangeToUnchangedGroupMemberAbortsCommit(): void
    {
        $pair = $this->updatedManagedPair(
            'module:blog:unchanged-race-test',
            false
        );
        $statePath = $this->projectRoot
            . '/.liquidstack/core/managed-files.json';
        $initialState = (string) file_get_contents($statePath);
        $sync = $this->synchronizer(
            $this->filesystemChangingTargetDuringBackup(
                $pair['second_target'],
                $pair['first_target']
            )
        );
        $this->queueManagedPair($sync, $pair);

        $sync->apply();

        self::assertSame(
            'concurrent-unchanged',
            file_get_contents($pair['first_target'])
        );
        self::assertSame(
            'second-v1',
            file_get_contents($pair['second_target'])
        );
        self::assertSame($initialState, file_get_contents($statePath));
        self::assertSame(1, $sync->stats()['errors']);
        self::assertSame(0, $sync->stats()['updated']);
        $this->assertTransactionDirectoryIsEmpty();
    }

    public function testRollbackFailureIsFatalAndKeepsJournalAndState(): void
    {
        $pair = $this->updatedManagedPair(
            'module:blog:fatal-rollback-test'
        );
        $laterSource = $this->packageRoot . '/modules/blog/later.php';
        $laterTarget = $this->projectRoot
            . '/public/assets/modules/blog/later.php';

        $statePath = $this->projectRoot
            . '/.liquidstack/core/managed-files.json';
        $initialState = (string) file_get_contents($statePath);
        $this->writeFile($laterSource, 'later');

        $sync = $this->synchronizer(
            $this->filesystemFailingPromotionAndRestoreTo(
                $pair['second_target']
            )
        );
        $this->queueManagedPair($sync, $pair);
        $sync->queueFile(
            $laterSource,
            $laterTarget,
            'modules/blog/later.php',
            'public/assets/modules/blog/later.php',
            ManagedFileRegistry::POLICY_MANAGED,
            null
        );

        try {
            $sync->apply();
            self::fail('Un rollback incompleto debe abortar Composer.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'ni restaurar por completo',
                $exception->getMessage()
            );
        }

        self::assertSame($initialState, file_get_contents($statePath));
        self::assertFileDoesNotExist($laterTarget);
        self::assertSame('first-v1', file_get_contents($pair['first_target']));
        self::assertFileDoesNotExist($pair['second_target']);
        $transactionRoot = $this->onlyPendingTransactionRoot();
        self::assertFileExists($transactionRoot . '/journal.json');
        self::assertFileExists($transactionRoot . '/backup/2');
        self::assertSame(
            "*\n",
            file_get_contents(dirname($transactionRoot) . '/.gitignore')
        );
    }

    public function testConcurrentTargetContentIsNeverDeletedByRollback(): void
    {
        $pair = $this->updatedManagedPair(
            'module:blog:concurrent-test'
        );

        $sync = $this->synchronizer(
            $this->filesystemChangingTargetBeforeFailure(
                $pair['second_target'],
                $pair['first_target']
            )
        );
        $this->queueManagedPair($sync, $pair);

        try {
            $sync->apply();
            self::fail('El contenido concurrente debe bloquear el rollback.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'contenido concurrente',
                $exception->getMessage()
            );
        }

        self::assertSame(
            'concurrent-local',
            file_get_contents($pair['first_target'])
        );
        self::assertSame(
            'second-v1',
            file_get_contents($pair['second_target'])
        );
        self::assertFileExists(
            $this->onlyPendingTransactionRoot() . '/backup/1'
        );
    }

    public function testStateIsReloadedAfterWaitingForProjectLock(): void
    {
        $source = $this->packageRoot . '/modules/blog/reloaded.php';
        $target = $this->projectRoot . '/App/controllers/reloaded.php';
        $group = 'module:blog:state-reload-test';
        $this->writeFile($source, 'version-one');

        // Se construye antes que el primer sincronizador escriba su estado:
        // reproduce una segunda ejecución que esperaba el lock con una
        // proyección obsoleta cargada en memoria.
        $waiting = $this->synchronizer();
        $this->queueManagedTestFile(
            $waiting,
            $source,
            $target,
            'reloaded.php',
            $group
        );

        $initial = $this->synchronizer();
        $this->queueManagedTestFile(
            $initial,
            $source,
            $target,
            'reloaded.php',
            $group
        );
        $initial->apply();
        $this->writeFile($source, 'version-two');

        $waiting->apply();

        self::assertSame('version-two', file_get_contents($target));
        self::assertSame(1, $waiting->stats()['updated']);
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

    public function testHistoricalFileWithStaleStateCompletesItsManagedGroup(): void
    {
        $runtimeSourceId = 'resources/js/_traducciones.js';
        $runtimeTargetId = 'src/js/resources/_traducciones.js';
        $runtimeSource = $this->packageRoot . '/' . $runtimeSourceId;
        $runtimeTarget = $this->projectRoot . '/' . $runtimeTargetId;
        $preferenceSourceId = 'resources/js/_languagePreference.mjs';
        $preferenceTargetId = 'src/js/resources/_languagePreference.mjs';
        $preferenceSource = $this->packageRoot . '/' . $preferenceSourceId;
        $preferenceTarget = $this->projectRoot . '/' . $preferenceTargetId;
        $stateVersion = 'translation-runtime-state-version';
        $historicalVersion = 'translation-runtime-historical-version';
        $currentVersion = 'translation-runtime-current-version';

        $this->writeHistory([
            $runtimeSourceId => ManagedFileRegistry::fingerprintContents(
                $runtimeSourceId,
                $historicalVersion
            ),
        ]);
        $this->writeFile($runtimeSource, $currentVersion);
        $this->writeFile($runtimeTarget, $historicalVersion);
        $this->writeFile(
            $preferenceSource,
            'export const bindLanguageNavigation = () => () => {};'
        );
        $this->writeFile(
            $this->projectRoot . '/.liquidstack/core/managed-files.json',
            json_encode([
                'schema' => 1,
                'package' => 'liquidstack/core',
                'files' => [
                    $runtimeTargetId => [
                        'source' => $runtimeSourceId,
                        'fingerprints' => ManagedFileRegistry::fingerprintContents(
                            $runtimeSourceId,
                            $stateVersion
                        ),
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );

        $sync = $this->synchronizer();
        $sync->queueFile(
            $runtimeSource,
            $runtimeTarget,
            $runtimeSourceId,
            $runtimeTargetId
        );
        $sync->queueFile(
            $preferenceSource,
            $preferenceTarget,
            $preferenceSourceId,
            $preferenceTargetId
        );
        $sync->apply();

        self::assertSame($currentVersion, file_get_contents($runtimeTarget));
        self::assertFileEquals($preferenceSource, $preferenceTarget);
        self::assertSame(1, $sync->stats()['updated']);
        self::assertSame(1, $sync->stats()['added']);
        self::assertSame(0, $sync->stats()['preserved']);
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

    public function testJsonMergeBreaksAHardLinkWithoutChangingItsAlias(): void
    {
        $source = $this->packageRoot
            . '/stubs/App/config/languages/templates/hardlink.json';
        $target = $this->projectRoot
            . '/App/config/languages/templates/hardlink.json';
        $sentinel = $this->root . '/json-hardlink-sentinel.json';
        $original = '{"project":"custom"}';
        $this->writeFile($source, '{"project":"dummy","new":"value"}');
        $this->writeFile($target, $original);
        if (!@link($target, $sentinel)) {
            self::markTestSkipped('El entorno no permite crear hard links.');
        }

        $sync = $this->synchronizer();
        $sync->queueFile(
            $source,
            $target,
            'stubs/App/config/languages/templates/hardlink.json',
            'App/config/languages/templates/hardlink.json'
        );
        $sync->apply();

        self::assertSame(
            ['project' => 'custom', 'new' => 'value'],
            json_decode(
                (string) file_get_contents($target),
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );
        self::assertSame($original, file_get_contents($sentinel));
        self::assertSame(1, $sync->stats()['merged']);
        self::assertSame(0, $sync->stats()['errors']);
        $this->assertTransactionDirectoryIsEmpty();
    }

    public function testInvalidJsonAndInstallOnlySeedsStayUntouched(): void
    {
        $jsonSource = $this->packageRoot
            . '/stubs/App/config/languages/templates/es.json';
        $jsonTarget = $this->projectRoot
            . '/App/config/languages/templates/es.json';
        $seedSource = $this->packageRoot
            . '/stubs/App/class/_comprobaciones.php';
        $seedTarget = $this->projectRoot
            . '/App/class/_comprobaciones.php';

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
            'stubs/App/class/_comprobaciones.php',
            'App/class/_comprobaciones.php'
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

    public function testInvalidJsonBlocksExplicitBatchBeforeEarlierWrites(): void
    {
        $addSource = $this->packageRoot . '/modules/blog/early.php';
        $addTarget = $this->projectRoot . '/App/a-early.php';
        $jsonSource = $this->packageRoot
            . '/stubs/App/config/languages/templates/es.json';
        $jsonTarget = $this->projectRoot
            . '/App/config/languages/templates/es.json';
        $this->writeFile($addSource, '<?php return "core";');
        $this->writeFile($jsonSource, '{"new":{"text":"dummy"}}');
        $this->writeFile($jsonTarget, '{"broken":');

        $sync = $this->synchronizer();
        $sync->queueFile(
            $addSource,
            $addTarget,
            'modules/blog/early.php',
            'App/a-early.php',
            ManagedFileRegistry::POLICY_MANAGED,
            null
        );
        $sync->queueFile(
            $jsonSource,
            $jsonTarget,
            'stubs/App/config/languages/templates/es.json',
            'App/config/languages/templates/es.json'
        );
        $preview = $sync->preview();

        self::assertSame('blocked', $preview['status']);
        self::assertContains(
            'sync.merge_json_target_invalid',
            $preview['blockers']
        );
        try {
            $sync->apply($preview['plan_hash']);
            self::fail('El lote JSON inválido debe bloquear antes de escribir.');
        } catch (ManagedFilePlanBlockedException $exception) {
            self::assertSame('sync.plan_blocked', $exception->getMessage());
        }

        self::assertFileDoesNotExist($addTarget);
        self::assertSame('{"broken":', file_get_contents($jsonTarget));
    }

    public function testInvalidJsonSourceBlocksMissingTarget(): void
    {
        $source = $this->packageRoot
            . '/stubs/App/config/languages/templates/es.json';
        $target = $this->projectRoot
            . '/App/config/languages/templates/es.json';
        $this->writeFile($source, '["not-an-object"]');
        $sync = $this->synchronizer();
        $sync->queueFile(
            $source,
            $target,
            'stubs/App/config/languages/templates/es.json',
            'App/config/languages/templates/es.json'
        );

        $preview = $sync->preview();

        self::assertSame('blocked', $preview['status']);
        self::assertSame(
            'sync.merge_json_source_invalid',
            $preview['entries'][0]['code']
        );
        try {
            $sync->apply($preview['plan_hash']);
            self::fail('Un catálogo canónico inválido debe bloquear.');
        } catch (ManagedFilePlanBlockedException $exception) {
            self::assertSame('sync.plan_blocked', $exception->getMessage());
        }
        self::assertFileDoesNotExist($target);
    }

    public function testDifferentSourcesCannotTargetTheSameFile(): void
    {
        $firstSource = $this->packageRoot . '/modules/one/app.js';
        $secondSource = $this->packageRoot . '/modules/two/app.js';
        $target = $this->projectRoot . '/public/assets/modules/app.js';
        $this->writeFile($firstSource, 'one');
        $this->writeFile($secondSource, 'two');

        $sync = $this->synchronizer();
        $sync->queueFile(
            $firstSource,
            $target,
            'modules/one/app.js',
            'public/assets/modules/app.js',
            ManagedFileRegistry::POLICY_MANAGED
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Colisión de sincronización');
        $sync->queueFile(
            $secondSource,
            $target,
            'modules/two/app.js',
            'public/assets/modules/app.js',
            ManagedFileRegistry::POLICY_MANAGED
        );
    }

    public function testSameTargetIdCannotResolveToDifferentQueueEntries(): void
    {
        $firstSource = $this->packageRoot . '/modules/one/app.js';
        $secondSource = $this->packageRoot . '/modules/two/app.js';
        $this->writeFile($firstSource, 'one');
        $this->writeFile($secondSource, 'two');
        $sync = $this->synchronizer();
        $sync->queueFile(
            $firstSource,
            $this->root . '/external-one/app.js',
            'modules/one/app.js',
            '@custom/app.js',
            ManagedFileRegistry::POLICY_MANAGED,
            null,
            true,
            $this->root . '/external-one'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('se encoló más de una vez');
        $sync->queueFile(
            $secondSource,
            $this->root . '/external-two/app.js',
            'modules/two/app.js',
            '@custom/app.js',
            ManagedFileRegistry::POLICY_MANAGED,
            null,
            true,
            $this->root . '/external-two'
        );
    }

    public function testProjectTargetMustMatchItsCanonicalTargetId(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $this->writeFile($source, '.sample { color: core; }');
        $sync = $this->synchronizer();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no corresponde a su destino de proyecto');
        $sync->queueFile(
            $source,
            $this->projectRoot . '/src/scss/other.scss',
            'resources/scss/_sample.scss',
            'src/scss/_sample.scss'
        );
    }

    public function testProjectTargetRejectsAnInternalPhysicalAlias(): void
    {
        $source = $this->packageRoot . '/modules/blog/same.php';
        $canonicalDirectory = $this->projectRoot . '/App';
        $alias = $this->projectRoot . '/app-alias';
        $this->writeFile($source, 'same');
        $this->filesystem->mkdir($canonicalDirectory);
        if (!$this->createDirectoryLink($canonicalDirectory, $alias)) {
            self::markTestSkipped(
                'El entorno no permite crear enlaces de directorio.'
            );
        }

        try {
            $sync = $this->synchronizer();
            try {
                $sync->queueFile(
                    $source,
                    $alias . '/same.php',
                    'modules/blog/same.php',
                    'App/same.php',
                    ManagedFileRegistry::POLICY_MANAGED
                );
                self::fail('Un alias físico no debe entrar en la cola project.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString(
                    'ruta de proyecto enlazada',
                    $exception->getMessage()
                );
            }
        } finally {
            $this->removeDirectoryLink($alias);
        }
    }

    public function testPhysicalAliasesCannotTargetTheSameFileTwice(): void
    {
        $firstSource = $this->packageRoot . '/modules/one/app.js';
        $secondSource = $this->packageRoot . '/modules/two/app.js';
        $external = $this->root . '/physical-target';
        $alias = $this->root . '/physical-alias';
        $firstTarget = $external . '/app.js';
        $secondTarget = $alias . '/app.js';
        $this->writeFile($firstSource, 'one');
        $this->writeFile($secondSource, 'two');
        $this->filesystem->mkdir($external);
        if (!$this->createDirectoryLink($external, $alias)) {
            self::markTestSkipped(
                'El entorno no permite crear enlaces de directorio.'
            );
        }

        try {
            $sync = $this->synchronizer();
            $sync->queueFile(
                $firstSource,
                $firstTarget,
                'modules/one/app.js',
                '@custom-resources/one/app.js',
                ManagedFileRegistry::POLICY_MANAGED,
                null,
                true,
                $external
            );
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Colisión de sincronización');
            $sync->queueFile(
                $secondSource,
                $secondTarget,
                'modules/two/app.js',
                '@custom-resources/two/app.js',
                ManagedFileRegistry::POLICY_MANAGED,
                null,
                true,
                $alias
            );
        } finally {
            $this->removeDirectoryLink($alias);
        }
    }

    public function testPreviewBlocksTargetsThatConvergeAfterExternalRetarget(): void
    {
        $sourceOne = $this->packageRoot . '/misc/collision-one.txt';
        $sourceTwo = $this->packageRoot . '/misc/collision-two.txt';
        $rootA = $this->root . '/collision-root-a';
        $rootB = $this->root . '/collision-root-b';
        $alias = $this->root . '/collision-root-alias';
        $target = $rootA . '/same.txt';
        $aliasTarget = $alias . '/same.txt';
        $this->writeFile($sourceOne, 'one');
        $this->writeFile($sourceTwo, 'two');
        $this->filesystem->mkdir([$rootA, $rootB]);
        if (!$this->createDirectoryLink($rootB, $alias)) {
            self::markTestSkipped(
                'El entorno no permite crear enlaces de directorio.'
            );
        }

        try {
            $sync = $this->synchronizer();
            $sync->queueFile(
                $sourceOne,
                $target,
                'misc/collision-one.txt',
                '@custom/collision-one.txt',
                ManagedFileRegistry::POLICY_MANAGED,
                null,
                true,
                $rootA
            );
            $sync->queueFile(
                $sourceTwo,
                $aliasTarget,
                'misc/collision-two.txt',
                '@custom/collision-two.txt',
                ManagedFileRegistry::POLICY_MANAGED,
                null,
                true,
                $alias
            );
            $this->removeDirectoryLink($alias);
            self::assertTrue($this->createDirectoryLink($rootA, $alias));

            $preview = $sync->preview();
            self::assertSame('blocked', $preview['status']);
            self::assertSame(
                ['error', 'error'],
                array_column($preview['entries'], 'action')
            );
            self::assertContains(
                'sync.physical_target_collision',
                $preview['blockers']
            );
            try {
                $sync->apply($preview['plan_hash']);
                self::fail('La colision fisica debe bloquear el lote.');
            } catch (ManagedFilePlanBlockedException $exception) {
                self::assertSame('sync.plan_blocked', $exception->getMessage());
            }
            self::assertFileDoesNotExist($target);
        } finally {
            $this->removeDirectoryLink($alias);
        }
    }

    public function testSameLogicalEntryCannotChangeItsPhysicalContract(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $firstTarget = $this->projectRoot . '/src/scss/first.scss';
        $secondTarget = $this->projectRoot . '/src/scss/second.scss';
        $this->writeFile($source, '.sample { color: core; }');
        $sync = $this->synchronizer();
        $sync->queueFile(
            $source,
            $firstTarget,
            'resources/scss/_sample.scss',
            'src/scss/_sample.scss',
            null,
            null,
            true,
            $this->projectRoot
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contratos distintos');
        $sync->queueFile(
            $source,
            $secondTarget,
            'resources/scss/_sample.scss',
            'src/scss/_sample.scss',
            null,
            null,
            true,
            $this->projectRoot
        );
    }

    public function testIdenticalLogicalEntryCanBeQueuedIdempotently(): void
    {
        $source = $this->packageRoot . '/resources/scss/_sample.scss';
        $target = $this->projectRoot . '/src/scss/_sample.scss';
        $this->writeFile($source, '.sample { color: core; }');
        $sync = $this->synchronizer();
        foreach ([1, 2] as $_) {
            $sync->queueFile(
                $source,
                $target,
                'resources/scss/_sample.scss',
                'src/scss/_sample.scss'
            );
        }

        self::assertCount(1, $sync->catalog()['entries']);
    }

    private function synchronizer(
        ?Filesystem $filesystem = null,
        ?Closure $transactionUnlinkObserver = null
    ): ManagedFileSynchronizer
    {
        return new ManagedFileSynchronizer(
            $this->projectRoot,
            $this->packageRoot,
            $this->io,
            null,
            null,
            $filesystem,
            null,
            $transactionUnlinkObserver
        );
    }

    private function filesystemFailingPromotionTo(
        string $failedTarget
    ): Filesystem {
        $failedTarget = $this->normalizeTestPath($failedTarget);
        $failed = false;

        return $this->filesystemWithRenameHook(
            static function (string $origin, string $target) use (
                &$failed,
                $failedTarget
            ): void {
                if (
                    !$failed
                    && $target === $failedTarget
                    && str_contains($origin, '/sync-transactions/')
                    && str_contains($origin, '/staged/')
                ) {
                    $failed = true;
                    throw new RuntimeException('fallo de escritura inyectado');
                }
            }
        );
    }

    private function filesystemFailingPromotionAndRestoreTo(
        string $failedTarget
    ): Filesystem {
        $failedTarget = $this->normalizeTestPath($failedTarget);
        $promotionFailed = false;
        $restoreFailed = false;

        return $this->filesystemWithRenameHook(
            static function (string $origin, string $target) use (
                &$promotionFailed,
                &$restoreFailed,
                $failedTarget
            ): void {
                if (
                    $target === $failedTarget
                    && !$promotionFailed
                    && str_contains($origin, '/staged/')
                ) {
                    $promotionFailed = true;
                    throw new RuntimeException('fallo de promocion inyectado');
                }
                if (
                    $target === $failedTarget
                    && !$restoreFailed
                    && str_contains($origin, '/backup/')
                ) {
                    $restoreFailed = true;
                    throw new RuntimeException(
                        'fallo de restauracion inyectado'
                    );
                }
            }
        );
    }

    private function filesystemFailingBackupOf(
        string $failedTarget
    ): Filesystem {
        $failedTarget = $this->normalizeTestPath($failedTarget);
        $failed = false;

        return $this->filesystemWithRenameHook(
            static function (string $origin, string $target) use (
                &$failed,
                $failedTarget
            ): void {
                if (
                    !$failed
                    && $origin === $failedTarget
                    && str_contains($target, '/backup/')
                ) {
                    $failed = true;
                    throw new RuntimeException(
                        'acceso denegado al target bloqueado'
                    );
                }
            }
        );
    }

    private function transactionFileRemoverFailingCleanup(
        object $state
    ): Closure {
        return static function (string $phase, string $path) use ($state): void {
            $normalized = strtolower(str_replace('\\', '/', $path));
            if (
                $phase === 'before'
                && str_contains($normalized, '/sync-transactions/')
                && preg_match(
                    '#/backup/[1-9][0-9]*\z#',
                    $normalized
                ) === 1
            ) {
                ++$state->attempts;
                if (
                    $state->blocked
                    && $state->failures_remaining !== 0
                ) {
                    if (is_int($state->failures_remaining)) {
                        --$state->failures_remaining;
                    }
                    throw new RuntimeException(
                        'backup temporalmente bloqueado por Windows'
                    );
                }
            }
        };
    }

    private function filesystemChangingTargetBeforeFailure(
        string $failedTarget,
        string $concurrentTarget
    ): Filesystem {
        $failedTarget = $this->normalizeTestPath($failedTarget);
        $failed = false;

        return $this->filesystemWithRenameHook(
            static function (string $origin, string $target) use (
                &$failed,
                $failedTarget,
                $concurrentTarget
            ): void {
                if (
                    !$failed
                    && $target === $failedTarget
                    && str_contains($origin, '/staged/')
                ) {
                    $failed = true;
                    file_put_contents($concurrentTarget, 'concurrent-local');
                    throw new RuntimeException(
                        'fallo posterior a edicion concurrente'
                    );
                }
            }
        );
    }

    private function filesystemChangingTargetBeforeBackup(
        string $changedTarget
    ): Filesystem {
        $normalizedTarget = $this->normalizeTestPath($changedTarget);
        $changed = false;

        return $this->filesystemWithRenameHook(
            static function (string $origin, string $target) use (
                &$changed,
                $changedTarget,
                $normalizedTarget
            ): void {
                if (
                    !$changed
                    && $origin === $normalizedTarget
                    && str_contains($target, '/backup/')
                ) {
                    $changed = true;
                    file_put_contents(
                        $changedTarget,
                        'concurrent-during-backup'
                    );
                }
            }
        );
    }

    private function filesystemChangingTargetDuringBackup(
        string $backupTarget,
        string $changedTarget
    ): Filesystem {
        $backupTarget = $this->normalizeTestPath($backupTarget);
        $changed = false;

        return $this->filesystemWithRenameHook(
            static function (string $origin, string $target) use (
                &$changed,
                $backupTarget,
                $changedTarget
            ): void {
                if (
                    !$changed
                    && $origin === $backupTarget
                    && str_contains($target, '/backup/')
                ) {
                    $changed = true;
                    file_put_contents(
                        $changedTarget,
                        'concurrent-unchanged'
                    );
                }
            }
        );
    }

    private function filesystemCreatingTargetAfterBackup(
        string $changedTarget
    ): Filesystem {
        $normalizedTarget = $this->normalizeTestPath($changedTarget);
        $changed = false;

        return $this->filesystemWithRenameHook(
            static function (
                string $origin,
                string $target,
                string $phase
            ) use (&$changed, $changedTarget, $normalizedTarget): void {
                if (
                    !$changed
                    && $phase === 'after'
                    && $origin === $normalizedTarget
                    && str_contains($target, '/backup/')
                ) {
                    $changed = true;
                    file_put_contents($changedTarget, 'concurrent-in-gap');
                }
            }
        );
    }

    private function filesystemWithRenameHook(\Closure $hook): Filesystem
    {
        return new class ($hook) extends Filesystem {
            public function __construct(
                private readonly \Closure $hook
            ) {
            }

            public function rename(
                string $origin,
                string $target,
                bool $overwrite = false
            ) {
                $normalize = static fn (string $path): string => strtolower(
                    str_replace('\\', '/', $path)
                );
                $originKey = $normalize($origin);
                $targetKey = $normalize($target);
                ($this->hook)($originKey, $targetKey, 'before');
                parent::rename($origin, $target, $overwrite);
                ($this->hook)($originKey, $targetKey, 'after');
            }
        };
    }

    private function filesystemWithCopyHook(\Closure $hook): Filesystem
    {
        return new class ($hook) extends Filesystem {
            public function __construct(
                private readonly \Closure $hook
            ) {
            }

            public function copy(
                string $originFile,
                string $targetFile,
                bool $overwriteNewerFiles = false
            ) {
                $normalize = static fn (string $path): string => strtolower(
                    str_replace('\\', '/', $path)
                );
                $originKey = $normalize($originFile);
                $targetKey = $normalize($targetFile);
                ($this->hook)($originKey, $targetKey, 'before');
                parent::copy(
                    $originFile,
                    $targetFile,
                    $overwriteNewerFiles
                );
                ($this->hook)($originKey, $targetKey, 'after');
            }
        };
    }

    private function filesystemWithMkdirHook(\Closure $hook): Filesystem
    {
        return new class ($hook) extends Filesystem {
            public function __construct(
                private readonly \Closure $hook
            ) {
            }

            public function mkdir(string|iterable $dirs, int $mode = 0777)
            {
                parent::mkdir($dirs, $mode);
                foreach (is_string($dirs) ? [$dirs] : $dirs as $directory) {
                    ($this->hook)((string) $directory);
                }
            }
        };
    }

    private function normalizeTestPath(string $path): string
    {
        return strtolower(str_replace('\\', '/', $path));
    }

    private function createDirectoryLink(string $target, string $link): bool
    {
        if (@symlink($target, $link)) {
            return true;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        $process = new Process([
            'powershell.exe',
            '-NoProfile',
            '-NonInteractive',
            '-Command',
            "New-Item -ItemType Junction -Path '"
                . str_replace("'", "''", $link)
                . "' -Target '"
                . str_replace("'", "''", $target)
                . "' | Out-Null",
        ]);
        $process->run();

        return $process->isSuccessful() && is_dir($link);
    }

    private function removeDirectoryLink(string $link): void
    {
        clearstatcache(true);
        if (PHP_OS_FAMILY !== 'Windows') {
            if (is_link($link)) {
                @unlink($link);
            }
            return;
        }

        $process = new Process([
            'powershell.exe',
            '-NoProfile',
            '-NonInteractive',
            '-Command',
            "if ([System.IO.Directory]::Exists('"
                . str_replace("'", "''", $link)
                . "')) { [System.IO.Directory]::Delete('"
                . str_replace("'", "''", $link)
                . "') }",
        ]);
        $process->run();
        clearstatcache(true);
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                'No se pudo retirar el enlace de prueba: '
                    . trim($process->getErrorOutput())
            );
        }
    }

    /** @return array<string, string> */
    private function snapshot(string $root): array
    {
        $snapshot = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');

        foreach ($iterator as $item) {
            $path = str_replace('\\', '/', $item->getPathname());
            $relative = substr($path, strlen($normalizedRoot) + 1);
            $snapshot[$relative] = $item->isLink()
                ? 'link'
                : 'sha256:' . hash_file('sha256', $item->getPathname());
        }
        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }

    /**
     * @return array{
     *     group: string,
     *     first_source: string,
     *     second_source: string,
     *     first_target: string,
     *     second_target: string
     * }
     */
    private function updatedManagedPair(
        string $group,
        bool $updateFirst = true
    ): array
    {
        $pair = [
            'group' => $group,
            'first_source' => $this->packageRoot
                . '/modules/blog/first.php',
            'second_source' => $this->packageRoot
                . '/modules/blog/second.php',
            'first_target' => $this->projectRoot
                . '/App/controllers/first.php',
            'second_target' => $this->projectRoot
                . '/App/controllers/second.php',
        ];
        $this->writeFile($pair['first_source'], 'first-v1');
        $this->writeFile($pair['second_source'], 'second-v1');

        $initial = $this->synchronizer();
        $this->queueManagedPair($initial, $pair);
        $initial->apply();
        if ($updateFirst) {
            $this->writeFile($pair['first_source'], 'first-v2');
        }
        $this->writeFile($pair['second_source'], 'second-v2');

        return $pair;
    }

    /** @param array<string, string> $pair */
    private function queueManagedPair(
        ManagedFileSynchronizer $synchronizer,
        array $pair
    ): void {
        $this->queueManagedTestFile(
            $synchronizer,
            $pair['first_source'],
            $pair['first_target'],
            'first.php',
            $pair['group']
        );
        $this->queueManagedTestFile(
            $synchronizer,
            $pair['second_source'],
            $pair['second_target'],
            'second.php',
            $pair['group']
        );
    }

    private function queueManagedTestFile(
        ManagedFileSynchronizer $synchronizer,
        string $source,
        string $target,
        string $fileName,
        string $group
    ): void {
        $synchronizer->queueFile(
            $source,
            $target,
            'modules/blog/' . $fileName,
            'App/controllers/' . $fileName,
            ManagedFileRegistry::POLICY_MANAGED,
            $group
        );
    }

    private function assertTransactionDirectoryIsEmpty(): void
    {
        $directory = $this->projectRoot
            . '/.liquidstack/core/sync-transactions';
        if (!is_dir($directory)) {
            self::assertDirectoryDoesNotExist($directory);
            return;
        }

        self::assertSame(
            ['.gitignore'],
            array_values(array_diff(scandir($directory) ?: [], ['.', '..']))
        );
        self::assertSame(
            "*\n",
            file_get_contents($directory . '/.gitignore')
        );
    }

    private function onlyPendingTransactionRoot(): string
    {
        $directory = $this->projectRoot
            . '/.liquidstack/core/sync-transactions';
        $entries = array_values(array_diff(
            scandir($directory) ?: [],
            ['.', '..', '.gitignore']
        ));
        self::assertCount(1, $entries);

        return $directory . '/' . $entries[0];
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
