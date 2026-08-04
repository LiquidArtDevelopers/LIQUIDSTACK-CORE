<?php

declare(strict_types=1);

use App\Core\Composer\ManagedFileRegistry;
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

    public function testRecoveryRejectsLinkedTransactionPath(): void
    {
        $external = $this->root . '/linked-runtime';
        $link = $this->projectRoot . '/.liquidstack';
        $this->filesystem->mkdir($external);
        $linked = @symlink($external, $link);
        if (!$linked && PHP_OS_FAMILY === 'Windows') {
            $process = new Process([
                'powershell.exe',
                '-NoProfile',
                '-NonInteractive',
                '-Command',
                "New-Item -ItemType Junction -LiteralPath '"
                    . str_replace("'", "''", $link)
                    . "' -Target '"
                    . str_replace("'", "''", $external)
                    . "' | Out-Null",
            ]);
            $process->run();
            $linked = $process->isSuccessful() && is_dir($link);
        }
        if (!$linked) {
            self::markTestSkipped(
                'El entorno no permite crear enlaces de directorio.'
            );
        }

        try {
            $this->synchronizer()->apply();
            self::fail('Recovery no debe atravesar un enlace de directorio.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'contiene un enlace',
                $exception->getMessage()
            );
        } finally {
            PHP_OS_FAMILY === 'Windows' ? @rmdir($link) : @unlink($link);
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
            $this->filesystemFailingTransactionCleanup($cleanup)
        );
        $this->queueManagedPair($sync, $pair);
        $sync->apply();

        self::assertSame('first-v2', file_get_contents($pair['first_target']));
        self::assertSame('second-v2', file_get_contents($pair['second_target']));
        self::assertSame(3, $cleanup->attempts);
        self::assertSame(0, $sync->stats()['errors']);
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
        $filesystem = $this->filesystemFailingTransactionCleanup($cleanup);

        $second = $this->synchronizer($filesystem);
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
        $third = $this->synchronizer($filesystem);
        $this->queueManagedPair($third, $pair);
        $third->apply();

        self::assertSame('second-v3', file_get_contents($pair['second_target']));
        self::assertSame(0, $third->stats()['errors']);

        $cleanup->blocked = false;
        $this->synchronizer($filesystem)->apply();
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

    private function synchronizer(
        ?Filesystem $filesystem = null
    ): ManagedFileSynchronizer
    {
        return new ManagedFileSynchronizer(
            $this->projectRoot,
            $this->packageRoot,
            $this->io,
            null,
            null,
            $filesystem
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

    private function filesystemFailingTransactionCleanup(
        object $state
    ): Filesystem {
        return new class ($state) extends Filesystem {
            public function __construct(
                private readonly object $state
            ) {
            }

            public function remove(string|iterable $files)
            {
                $paths = is_string($files) ? [$files] : $files;
                foreach ($paths as $path) {
                    $normalized = strtolower(str_replace('\\', '/', $path));
                    if (
                        !str_contains($normalized, '/sync-transactions/')
                        || !str_ends_with($normalized, '/backup')
                    ) {
                        continue;
                    }

                    ++$this->state->attempts;
                    if (!$this->state->blocked) {
                        break;
                    }
                    if ($this->state->failures_remaining === 0) {
                        break;
                    }
                    if (is_int($this->state->failures_remaining)) {
                        --$this->state->failures_remaining;
                    }

                    throw new RuntimeException(
                        'backup temporalmente bloqueado por Windows'
                    );
                }

                parent::remove($files);
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

    private function normalizeTestPath(string $path): string
    {
        return strtolower(str_replace('\\', '/', $path));
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
