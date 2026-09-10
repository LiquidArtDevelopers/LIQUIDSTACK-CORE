<?php

declare(strict_types=1);

use App\Core\Composer\Command\ManagedSyncCommand;
use App\Core\Composer\ManagedFileSynchronizer;
use App\Core\Composer\ManagedProjectSyncRuntime;
use Composer\Console\Application as ComposerApplication;
use Composer\IO\BufferIO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ManagedSyncCommandTest extends TestCase
{
    private Filesystem $filesystem;
    private string $root;
    private string $packageRoot;
    private string $projectRoot;
    private string $source;
    private string $target;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-managed-sync-command-'
            . bin2hex(random_bytes(8));
        $this->packageRoot = $this->root . '/package';
        $this->projectRoot = $this->root . '/project';
        $this->source = $this->packageRoot
            . '/resources/scss/_sample.scss';
        $this->target = $this->projectRoot
            . '/src/scss/resources/_sample.scss';
        $this->filesystem->mkdir([
            dirname($this->source),
            $this->packageRoot . '/manifests',
            $this->projectRoot,
        ]);
        $this->filesystem->dumpFile(
            $this->packageRoot . '/manifests/managed-file-history.json',
            json_encode([
                'schema' => 1,
                'algorithm' => 'sha256-eol-lf-v1',
                'files' => [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
        $this->filesystem->dumpFile(
            $this->source,
            '.sample { color: core; }'
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testPlanAndDryRunAreDeterministicAndReadOnly(): void
    {
        $before = $this->snapshot($this->projectRoot);
        $planTester = $this->tester();
        $plan = $planTester->execute([
            '--plan' => true,
            '--format' => 'json',
        ]);
        $planPayload = $this->payload($planTester->getDisplay());

        self::assertSame(Command::SUCCESS, $plan);
        self::assertTrue($planPayload['ok']);
        self::assertSame('plan', $planPayload['mode']);
        self::assertSame('managed-sync-v1', $planPayload['protocol']);
        self::assertSame(
            'src/scss/resources/_sample.scss',
            $planPayload['entries'][0]['target']
        );
        self::assertSame($before, $this->snapshot($this->projectRoot));

        $firstTester = $this->tester();
        self::assertSame(Command::SUCCESS, $firstTester->execute([
            '--dry-run' => true,
            '--format' => 'json',
        ]));
        $firstDisplay = $firstTester->getDisplay();
        $first = $this->payload($firstDisplay);
        $secondTester = $this->tester();
        self::assertSame(Command::SUCCESS, $secondTester->execute([
            '--dry-run' => true,
            '--format' => 'json',
        ]));
        $second = $this->payload($secondTester->getDisplay());

        self::assertSame($first, $second);
        self::assertSame($before, $this->snapshot($this->projectRoot));
        self::assertSame('ready', $first['status']);
        self::assertTrue($first['ok']);
        self::assertSame('add', $first['entries'][0]['action']);
        self::assertMatchesRegularExpression(
            '/\Asha256:[a-f0-9]{64}\z/',
            $first['plan_hash']
        );
        self::assertStringNotContainsString(
            str_replace('\\', '/', $this->root),
            str_replace('\\', '/', $firstDisplay)
        );
    }

    public function testApplyRequiresTheReviewedHashAndConfirmation(): void
    {
        $missingHash = $this->tester();
        self::assertSame(Command::INVALID, $missingHash->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        self::assertSame(
            'sync.plan_hash_required',
            $this->payload($missingHash->getDisplay())['error']['code']
        );

        $preview = $this->tester();
        $preview->execute([
            '--dry-run' => true,
            '--format' => 'json',
        ]);
        $hash = $this->payload($preview->getDisplay())['plan_hash'];
        $unconfirmed = $this->tester();
        self::assertSame(Command::FAILURE, $unconfirmed->execute([
            '--apply' => true,
            '--plan-hash' => $hash,
            '--format' => 'json',
        ]));
        self::assertFileDoesNotExist($this->target);
    }

    public function testApplyRejectsAStaleHashWithoutOverwritingTheTarget(): void
    {
        $preview = $this->tester();
        $preview->execute([
            '--dry-run' => true,
            '--format' => 'json',
        ]);
        $hash = $this->payload($preview->getDisplay())['plan_hash'];
        $this->filesystem->mkdir(dirname($this->target));
        $this->filesystem->dumpFile(
            $this->target,
            '.sample { color: local; }'
        );

        $apply = $this->tester();
        self::assertSame(Command::FAILURE, $apply->execute([
            '--apply' => true,
            '--plan-hash' => $hash,
            '--yes' => true,
            '--format' => 'json',
        ]));
        self::assertSame(
            'sync.plan_changed',
            $this->payload($apply->getDisplay())['error']['code']
        );
        self::assertSame(
            '.sample { color: local; }',
            file_get_contents($this->target)
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/.liquidstack/core/managed-files.json'
        );
    }

    public function testApplyInstallsTheExactReviewedPlan(): void
    {
        $preview = $this->tester();
        $preview->execute([
            '--dry-run' => true,
            '--format' => 'json',
        ]);
        $hash = $this->payload($preview->getDisplay())['plan_hash'];
        $apply = $this->tester();

        self::assertSame(Command::SUCCESS, $apply->execute([
            '--apply' => true,
            '--plan-hash' => $hash,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = $this->payload($apply->getDisplay());

        self::assertSame('applied', $payload['status']);
        self::assertTrue($payload['ok']);
        self::assertSame('managed-sync-v1', $payload['protocol']);
        self::assertSame(1, $payload['stats']['added']);
        self::assertFileEquals($this->source, $this->target);
        self::assertFileExists(
            $this->projectRoot . '/.liquidstack/core/managed-files.json'
        );
    }

    public function testMissingScssContractBlocksTheExplicitApply(): void
    {
        $command = new ManagedSyncCommand(new ManagedProjectSyncRuntime(
            $this->synchronizer(),
            false
        ));
        $application = new ComposerApplication();
        $application->setAutoExit(false);
        $application->add($command);
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--dry-run' => true,
            '--format' => 'json',
        ]));
        $payload = $this->payload($tester->getDisplay());
        self::assertSame('blocked', $payload['status']);
        self::assertFalse($payload['ok']);
        self::assertSame(
            ['sync.scss_contract_not_satisfied'],
            $payload['blockers']
        );
        self::assertFileDoesNotExist($this->target);
    }

    public function testApplyRecoversAPendingJournalBeforeCheckingTheHash(): void
    {
        $preview = $this->tester();
        self::assertSame(Command::SUCCESS, $preview->execute([
            '--dry-run' => true,
            '--format' => 'json',
        ]));
        $hash = $this->payload($preview->getDisplay())['plan_hash'];
        $transactionRoot = $this->projectRoot
            . '/.liquidstack/core/sync-transactions/'
            . str_repeat('a', 24);
        $this->filesystem->mkdir($transactionRoot);
        $this->filesystem->dumpFile(
            $transactionRoot . '/journal.json',
            json_encode([
                'schema' => 1,
                'group' => 'module:blog:staging-test',
                'status' => 'staging',
                'files' => [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );

        $apply = $this->tester();
        self::assertSame(Command::SUCCESS, $apply->execute([
            '--apply' => true,
            '--plan-hash' => $hash,
            '--yes' => true,
            '--format' => 'json',
        ]));

        $payload = $this->payload($apply->getDisplay());
        self::assertTrue($payload['ok']);
        self::assertSame('applied', $payload['status']);
        self::assertDirectoryDoesNotExist($transactionRoot);
        self::assertFileEquals($this->source, $this->target);
    }

    public function testJsonFailureCarriesStableEnvelopeAndMode(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::INVALID, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));

        $payload = $this->payload($tester->getDisplay());
        self::assertSame(1, $payload['schema']);
        self::assertSame('liquidstack/core', $payload['package']);
        self::assertSame('managed-sync-v1', $payload['protocol']);
        self::assertFalse($payload['ok']);
        self::assertSame('apply', $payload['mode']);
        self::assertSame(
            'sync.plan_hash_required',
            $payload['error']['code']
        );
    }

    private function tester(): CommandTester
    {
        $command = new ManagedSyncCommand(new ManagedProjectSyncRuntime(
            $this->synchronizer()
        ));
        $application = new ComposerApplication();
        $application->setAutoExit(false);
        $application->add($command);

        return new CommandTester($command);
    }

    private function synchronizer(): ManagedFileSynchronizer
    {
        $synchronizer = new ManagedFileSynchronizer(
            $this->projectRoot,
            $this->packageRoot,
            new BufferIO()
        );
        $synchronizer->queueFile(
            $this->source,
            $this->target,
            'resources/scss/_sample.scss',
            'src/scss/resources/_sample.scss'
        );

        return $synchronizer;
    }

    /** @return array<string, mixed> */
    private function payload(string $display): array
    {
        return json_decode(
            $display,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /** @return array<string, string> */
    private function snapshot(string $root): array
    {
        $snapshot = [];
        if (!is_dir($root)) {
            return $snapshot;
        }
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
}
