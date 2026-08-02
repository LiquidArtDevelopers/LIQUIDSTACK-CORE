<?php

declare(strict_types=1);

use App\Core\Composer\Command\MediaInitCommand;
use App\Core\Composer\MediaInitCommandRuntimeFactory;
use App\Core\Composer\MediaInitCommandRuntimeFactoryInterface;
use App\Core\Composer\MediaInitCommandRuntimeInterface;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\ModuleRegistry;
use App\Core\WebAdmin\Media\MediaStorageInitializationResult;
use App\Core\WebAdmin\Media\PrivateMediaStorage;
use Composer\Console\Application as ComposerApplication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class MediaInitCliRuntimeFixture implements MediaInitCommandRuntimeInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly MediaStorageInitializationResult $result
    ) {
    }

    public function initialize(): MediaStorageInitializationResult
    {
        ++$this->calls;

        return $this->result;
    }
}

final class MediaInitCliFactoryFixture implements
    MediaInitCommandRuntimeFactoryInterface
{
    public int $calls = 0;
    public ?bool $adoptExisting = null;

    public function __construct(
        private readonly MediaInitCommandRuntimeInterface $runtime
    ) {
    }

    public function create(
        string $projectRoot,
        string $coreRoot,
        bool $adoptExisting = false
    ): MediaInitCommandRuntimeInterface {
        ++$this->calls;
        $this->adoptExisting = $adoptExisting;

        return $this->runtime;
    }
}

final class MediaInitCommandTest extends TestCase
{
    private Filesystem $filesystem;
    private string $sandbox;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->sandbox = sys_get_temp_dir() . '/liquidstack-media-init-cli-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->sandbox);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->sandbox);
    }

    public function testConstructionAndJsonGateAreSideEffectFree(): void
    {
        $runtime = new MediaInitCliRuntimeFixture(
            MediaStorageInitializationResult::initialized()
        );
        $factory = new MediaInitCliFactoryFixture($runtime);
        $command = new MediaInitCommand(
            $this->sandbox,
            dirname(__DIR__, 2),
            $factory
        );

        self::assertSame('liquidstack:media:init', $command->getName());
        self::assertSame(0, $factory->calls);
        self::assertSame(0, $runtime->calls);

        $tester = $this->tester($command);
        $status = $tester->execute(['--format' => 'json']);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::INVALID, $status);
        self::assertSame(
            'webadmin.media.init.json_requires_yes',
            $payload['error']['code']
        );
        self::assertSame(0, $factory->calls);
        self::assertSame(0, $runtime->calls);
    }

    public function testLegacyAdoptionRequiresAllThreeExplicitGates(): void
    {
        $runtime = new MediaInitCliRuntimeFixture(
            MediaStorageInitializationResult::adoptedExisting()
        );
        $factory = new MediaInitCliFactoryFixture($runtime);

        $missingYes = $this->tester(new MediaInitCommand(
            $this->sandbox,
            dirname(__DIR__, 2),
            $factory
        ));
        self::assertSame(Command::INVALID, $missingYes->execute([
            '--adopt-existing' => true,
            '--backup-confirmed' => true,
            '--format' => 'json',
        ]));
        self::assertSame(
            'webadmin.media.init.adoption_requires_yes',
            json_decode(
                $missingYes->getDisplay(),
                true,
                512,
                JSON_THROW_ON_ERROR
            )['error']['code']
        );

        $missingBackup = $this->tester(new MediaInitCommand(
            $this->sandbox,
            dirname(__DIR__, 2),
            $factory
        ));
        self::assertSame(Command::INVALID, $missingBackup->execute([
            '--adopt-existing' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        self::assertSame(
            'webadmin.media.init.adoption_requires_backup_confirmation',
            json_decode(
                $missingBackup->getDisplay(),
                true,
                512,
                JSON_THROW_ON_ERROR
            )['error']['code']
        );

        $strayBackup = $this->tester(new MediaInitCommand(
            $this->sandbox,
            dirname(__DIR__, 2),
            $factory
        ));
        self::assertSame(Command::INVALID, $strayBackup->execute([
            '--backup-confirmed' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        self::assertSame(
            'webadmin.media.init.backup_confirmation_without_adoption',
            json_decode(
                $strayBackup->getDisplay(),
                true,
                512,
                JSON_THROW_ON_ERROR
            )['error']['code']
        );
        self::assertSame(0, $factory->calls);
        self::assertSame(0, $runtime->calls);
    }

    public function testConfirmedLegacyAdoptionIsForwardedAndRenderedPathFree(): void
    {
        $runtime = new MediaInitCliRuntimeFixture(
            MediaStorageInitializationResult::adoptedExisting()
        );
        $factory = new MediaInitCliFactoryFixture($runtime);
        $tester = $this->tester(new MediaInitCommand(
            $this->sandbox,
            dirname(__DIR__, 2),
            $factory
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--adopt-existing' => true,
            '--backup-confirmed' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertTrue($factory->adoptExisting);
        self::assertSame(1, $factory->calls);
        self::assertSame(1, $runtime->calls);
        self::assertSame('adopted_existing', $payload['result']['status']);
        self::assertTrue($payload['result']['changed']);
        self::assertStringNotContainsString(
            str_replace('\\', '/', $this->sandbox),
            str_replace('\\', '/', $tester->getDisplay())
        );
    }

    public function testConfirmedLegacyAdoptionVerifiesTheConfiguredDatabase(): void
    {
        $project = $this->project('legacy-database-project', true);
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $coreRoot = dirname(__DIR__, 2);
        $registry = ModuleRegistry::forProject($project, $coreRoot);
        $scopes = (new ConfiguredMigrationScopeFactory())->create(
            $registry,
            $project
        );
        (new MigrationRunner())->apply(
            $pdo,
            MigrationCatalog::fromRegistry($registry),
            $scopes
        );

        $pdo->exec(
            "INSERT INTO ls_webadmin_users "
            . '(public_id, email_canonical, status, auth_version, activated_at) '
            . "VALUES ('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', "
            . "'legacy@example.test', 'active', 1, "
            . "'2030-01-01 00:00:00.000000')"
        );
        $userId = (int) $pdo->lastInsertId();
        $asset = '12345678-1234-4234-8234-123456789abc';
        $contents = 'legacy-command-avif';
        $storageRoot = $project . '/storage/liquidstack/webadmin/media';
        $variantPath = $storageRoot . '/12/' . $asset . '/480.avif';
        $this->filesystem->mkdir([
            $storageRoot . '/.staging',
            dirname($variantPath),
        ]);
        $this->filesystem->dumpFile($variantPath, $contents);
        $assetInsert = $pdo->prepare(
            'INSERT INTO ls_webadmin_media_assets '
            . '(public_id, label, source_mime, source_width, source_height, '
            . 'source_bytes, source_sha256, created_by_user_id) VALUES '
            . "(:public_id, 'Legacy', 'image/png', 480, 320, :source_bytes, "
            . ':source_sha256, :created_by)'
        );
        $assetInsert->execute([
            'public_id' => $asset,
            'source_bytes' => 10,
            'source_sha256' => str_repeat('b', 64),
            'created_by' => $userId,
        ]);
        $assetId = (int) $pdo->lastInsertId();
        $variantInsert = $pdo->prepare(
            'INSERT INTO ls_webadmin_media_variants '
            . '(asset_id, width, height, bytes, sha256, storage_key, mime) '
            . 'VALUES (:asset_id, 480, 320, :bytes, :sha256, '
            . ":storage_key, 'image/avif')"
        );
        $variantInsert->execute([
            'asset_id' => $assetId,
            'bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'storage_key' => '12/' . $asset . '/480.avif',
        ]);

        $connection = new class($pdo) implements PdoConnectionFactoryInterface {
            public function __construct(private readonly PDO $pdo)
            {
            }

            public function connect(): PDO
            {
                return $this->pdo;
            }
        };
        $factory = new MediaInitCommandRuntimeFactory(
            connectionFactoryResolver: static fn (): PdoConnectionFactoryInterface =>
                $connection
        );
        $tester = $this->tester(new MediaInitCommand(
            $project,
            $coreRoot,
            $factory
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--adopt-existing' => true,
            '--backup-confirmed' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('adopted_existing', $payload['result']['status']);
        self::assertFileExists(
            $storageRoot . '/' . PrivateMediaStorage::INITIALIZATION_MARKER
        );
        self::assertSame($contents, file_get_contents($variantPath));
        self::assertStringNotContainsString(
            str_replace('\\', '/', $project),
            str_replace('\\', '/', $tester->getDisplay())
        );
    }

    public function testLegacyAdoptionRejectsUnreadySchemaWithoutFilesystemMutation(): void
    {
        $project = $this->project('legacy-schema-project', true);
        $storageRoot = $project . '/storage/liquidstack/webadmin/media';
        $legacyFile = $storageRoot . '/12/'
            . '12345678-1234-4234-8234-123456789abc/480.avif';
        $this->filesystem->mkdir([
            $storageRoot . '/.staging',
            dirname($legacyFile),
        ]);
        $this->filesystem->dumpFile($legacyFile, 'preserve-legacy');
        $before = scandir($storageRoot);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $connection = new class($pdo) implements PdoConnectionFactoryInterface {
            public function __construct(private readonly PDO $pdo)
            {
            }

            public function connect(): PDO
            {
                return $this->pdo;
            }
        };
        $factory = new MediaInitCommandRuntimeFactory(
            connectionFactoryResolver: static fn (): PdoConnectionFactoryInterface =>
                $connection
        );
        $tester = $this->tester(new MediaInitCommand(
            $project,
            dirname(__DIR__, 2),
            $factory
        ));

        self::assertSame(Command::FAILURE, $tester->execute([
            '--adopt-existing' => true,
            '--backup-confirmed' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'webadmin.media.init.schema_not_ready',
            $payload['error']['code']
        );
        self::assertSame('preserve-legacy', file_get_contents($legacyFile));
        self::assertSame($before, scandir($storageRoot));
        self::assertFileDoesNotExist(
            $storageRoot . '/' . PrivateMediaStorage::INITIALIZATION_MARKER
        );
    }

    public function testExplicitCommandInitializesLocalDefaultIdempotently(): void
    {
        $project = $this->project('local-project', true);
        $storageRoot = $project
            . '/storage/liquidstack/webadmin/media';

        $first = $this->tester(new MediaInitCommand(
            $project,
            dirname(__DIR__, 2)
        ));
        $firstStatus = $first->execute([
            '--yes' => true,
            '--format' => 'json',
        ]);
        $firstPayload = json_decode(
            $first->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::SUCCESS, $firstStatus);
        self::assertSame('initialized', $firstPayload['result']['status']);
        self::assertTrue($firstPayload['result']['changed']);
        self::assertFileExists(
            $storageRoot . '/' . PrivateMediaStorage::INITIALIZATION_MARKER
        );
        self::assertStringNotContainsString(
            str_replace('\\', '/', $project),
            str_replace('\\', '/', $first->getDisplay())
        );

        $second = $this->tester(new MediaInitCommand(
            $project,
            dirname(__DIR__, 2)
        ));
        self::assertSame(Command::SUCCESS, $second->execute([
            '--yes' => true,
            '--format' => 'json',
        ]));
        $secondPayload = json_decode(
            $second->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'already_initialized',
            $secondPayload['result']['status']
        );
        self::assertFalse($secondPayload['result']['changed']);
    }

    public function testNonInteractiveTextRequiresConfirmation(): void
    {
        $project = $this->project('confirmation-project', true);
        $tester = $this->tester(new MediaInitCommand(
            $project,
            dirname(__DIR__, 2)
        ));
        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(
            'webadmin.media.init.confirmation_required',
            $tester->getDisplay()
        );
        self::assertDirectoryDoesNotExist(
            $project . '/storage/liquidstack/webadmin/media'
        );
    }

    public function testDisabledModuleAndMissingProductionRootFailClosed(): void
    {
        $withoutModule = $this->project('without-module', false);
        $disabled = $this->tester(new MediaInitCommand(
            $withoutModule,
            dirname(__DIR__, 2)
        ));
        self::assertSame(Command::FAILURE, $disabled->execute([
            '--yes' => true,
            '--format' => 'json',
        ]));
        $disabledPayload = json_decode(
            $disabled->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'webadmin.media.init.module_not_enabled',
            $disabledPayload['error']['code']
        );

        $production = $this->project('production-project', true, false);
        $missingRoot = $this->tester(new MediaInitCommand(
            $production,
            dirname(__DIR__, 2)
        ));
        self::assertSame(Command::FAILURE, $missingRoot->execute([
            '--yes' => true,
            '--format' => 'json',
        ]));
        $missingPayload = json_decode(
            $missingRoot->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'webadmin.media.storage_configuration_missing',
            $missingPayload['error']['code']
        );
    }

    public function testUnmarkedNonEmptyRootIsPreservedAndNotAdopted(): void
    {
        $project = $this->project('non-empty-root', true);
        $root = $project . '/storage/liquidstack/webadmin/media';
        $this->filesystem->mkdir($root);
        $this->filesystem->dumpFile($root . '/foreign.txt', 'preserve');
        $before = scandir($root);
        $tester = $this->tester(new MediaInitCommand(
            $project,
            dirname(__DIR__, 2)
        ));

        self::assertSame(Command::FAILURE, $tester->execute([
            '--yes' => true,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'webadmin.media.storage_requires_explicit_adoption',
            $payload['error']['code']
        );
        self::assertSame('preserve', file_get_contents($root . '/foreign.txt'));
        self::assertFileDoesNotExist(
            $root . '/' . PrivateMediaStorage::INITIALIZATION_MARKER
        );
        self::assertSame($before, scandir($root));
    }

    private function project(
        string $name,
        bool $webAdmin,
        bool $development = true
    ): string {
        $project = $this->sandbox . '/' . $name;
        $this->filesystem->mkdir($project);
        $require = ['liquidstack/core' => '^1.13'];
        if ($webAdmin) {
            $require['liquidstack/webadmin'] = '*';
        }
        $this->filesystem->dumpFile(
            $project . '/composer.json',
            json_encode(['require' => $require], JSON_THROW_ON_ERROR)
        );
        $this->filesystem->dumpFile(
            $project . '/.env',
            $development
                ? "RAIZ=http://localhost:1309\nDEV_MODE=1\n"
                : "RAIZ=https://example.test\nDEV_MODE=0\n"
        );

        return $project;
    }

    private function tester(MediaInitCommand $command): CommandTester
    {
        $application = new ComposerApplication();
        $application->setAutoExit(false);
        $application->add($command);

        return new CommandTester($command);
    }
}
