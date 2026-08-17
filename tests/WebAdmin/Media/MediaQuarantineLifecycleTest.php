<?php

declare(strict_types=1);

namespace Tests\WebAdmin\Media;

use App\Core\Http\UploadedFile;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\Modules\WebAdmin\WebAdminMediaQuarantineMigrationPostconditionVerifier;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\MediaImageProcessorInterface;
use App\Core\WebAdmin\Media\MediaService;
use App\Core\WebAdmin\Media\PdoMediaRepository;
use App\Core\WebAdmin\Media\PrivateMediaStorage;
use App\Core\WebAdmin\Media\ProcessedMediaUpload;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class MediaQuarantineLifecycleTest extends TestCase
{
    private const USER_PUBLIC_ID =
        '10000000-0000-4000-8000-000000000001';
    private const SESSION_PUBLIC_ID =
        '20000000-0000-4000-8000-000000000002';
    private const ASSET_PUBLIC_ID =
        '30000000-0000-4000-8000-000000000003';
    private const REQUEST_ID =
        '40000000-0000-4000-8000-000000000004';

    private Filesystem $filesystem;
    private string $projectRoot;
    private string $storageRoot;
    private PDO $pdo;
    private PrivateMediaStorage $storage;
    private SecurityKey $securityKey;
    private string $sessionToken;
    private string $csrfToken;
    private MediaQuarantineFixedClock $clock;
    private string $variantContents = 'private-avif-fixture';

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite es necesario.');
        }
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-media-quarantine-' . bin2hex(random_bytes(8));
        $this->storageRoot = $this->projectRoot
            . '/storage/liquidstack/webadmin/media';
        $this->filesystem->mkdir($this->projectRoot);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $scope = MigrationScope::forTablePrefix(
            'webadmin',
            'ls_webadmin_'
        );
        foreach (WebAdminMigrationProvider::migrations() as $migration) {
            foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
                $this->pdo->exec($sql);
            }
        }

        $this->securityKey = SecurityKey::fromRawBytes(str_repeat('Q', 32));
        $this->sessionToken = self::token('S');
        $this->csrfToken = $this->securityKey->deriveToken(
            'csrf.session',
            $this->sessionToken
        );
        $this->clock = new MediaQuarantineFixedClock(
            new DateTimeImmutable('2030-01-01 00:10:00 UTC')
        );
        $this->seedAuthorizedSiteAdmin();

        $this->storage = new PrivateMediaStorage(
            $this->projectRoot,
            $this->storageRoot
        );
        $this->storage->initialize();
        $this->seedAssetAndFile();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->filesystem, $this->projectRoot)) {
            $this->filesystem->remove($this->projectRoot);
        }
    }

    public function testQuarantineKeepsRecoverableDatabaseAndFilesystemState(): void
    {
        $repository = $this->repository();
        $version = $this->assetVersion($repository);

        self::assertSame(self::ASSET_PUBLIC_ID, $this->service($repository)
            ->delete(
                self::ASSET_PUBLIC_ID,
                $version,
                $this->sessionToken,
                $this->csrfToken,
                '127.0.0.1',
                self::REQUEST_ID
            ));

        $original = $this->originalVariantPath();
        $quarantined = $this->quarantinedVariantPath();
        $manifest = $this->manifestPath();
        self::assertFileDoesNotExist($original);
        self::assertFileExists($quarantined);
        self::assertSame($this->variantContents, file_get_contents($quarantined));
        self::assertFileExists($manifest);

        $row = $this->pdo->query(
            'SELECT * FROM ls_webadmin_media_quarantines'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('quarantined', $row['state']);
        self::assertSame($version, $row['asset_version']);
        self::assertSame(
            hash('sha256', (string) file_get_contents($manifest)),
            $row['manifest_sha256']
        );
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_media_assets'
        )->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_media_variants'
        )->fetchColumn());
        self::assertSame('webadmin.media.quarantined', $this->pdo->query(
            'SELECT event_code FROM ls_webadmin_audit_log '
            . "WHERE request_id = '" . self::REQUEST_ID . "'"
        )->fetchColumn());
        self::assertSame([], $repository->listPage(1, 24)->items());
        self::assertSame([], $repository->publicIds(10));
        self::assertNull($repository->findVariant(self::ASSET_PUBLIC_ID, 480));
        self::assertSame(0, $repository->totalVariantBytes());

        // The same actor/request/CAS tuple is a receipt lookup, not a second
        // filesystem mutation.
        self::assertSame(self::ASSET_PUBLIC_ID, $this->service($repository)
            ->delete(
                self::ASSET_PUBLIC_ID,
                $version,
                $this->sessionToken,
                $this->csrfToken,
                '127.0.0.1',
                self::REQUEST_ID
            ));
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_media_quarantines'
        )->fetchColumn());
        self::assertFileExists($quarantined);

        $verifier = new WebAdminMediaQuarantineMigrationPostconditionVerifier();
        $scope = MigrationScope::forTablePrefix(
            'webadmin',
            'ls_webadmin_'
        );
        self::assertTrue($verifier->verify($this->pdo, $scope));
        $this->pdo->exec(
            "UPDATE ls_webadmin_media_quarantines SET manifest_sha256 = '"
            . str_repeat('0', 64) . "'"
        );
        self::assertContains(
            'webadmin.media.quarantine_data_invalid',
            $verifier->issueCodes($this->pdo, $scope)
        );
    }

    public function testDatabaseFailureRestoresTheOriginalAssetAndManifest(): void
    {
        $repository = $this->repository();
        $version = $this->assetVersion($repository);
        $this->pdo->exec('DROP TABLE ls_webadmin_audit_log');

        try {
            $this->service($repository)->delete(
                self::ASSET_PUBLIC_ID,
                $version,
                $this->sessionToken,
                $this->csrfToken,
                null,
                self::REQUEST_ID
            );
            self::fail('The failed DB transaction must escape.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.quarantine_record_failed',
                $exception->issueCode()
            );
        }

        self::assertFileExists($this->originalVariantPath());
        self::assertFileDoesNotExist($this->quarantinedVariantPath());
        self::assertFileDoesNotExist($this->manifestPath());
        self::assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_media_quarantines'
        )->fetchColumn());
    }

    public function testStaleCasNeverMovesTheAsset(): void
    {
        $repository = $this->repository();

        try {
            $this->service($repository)->delete(
                self::ASSET_PUBLIC_ID,
                str_repeat('f', 64),
                $this->sessionToken,
                $this->csrfToken,
                null,
                self::REQUEST_ID
            );
            self::fail('A stale CAS token must be rejected.');
        } catch (MediaException $exception) {
            self::assertSame('webadmin.media.delete_stale', $exception->issueCode());
        }

        self::assertFileExists($this->originalVariantPath());
        self::assertFileDoesNotExist($this->quarantinedVariantPath());
        self::assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_media_quarantines'
        )->fetchColumn());
    }

    public function testMutationRevalidatesCsrfAndDeleteCapability(): void
    {
        $repository = $this->repository();
        $version = $this->assetVersion($repository);
        foreach (['invalid-csrf', $this->csrfToken] as $attempt => $csrf) {
            if ($attempt === 1) {
                $this->pdo->exec(
                    'DELETE FROM ls_webadmin_role_capabilities '
                    . 'WHERE role_id = (SELECT id FROM ls_webadmin_roles '
                    . "WHERE code = 'site_admin') AND capability_id = "
                    . '(SELECT id FROM ls_webadmin_capabilities WHERE code '
                    . "= 'webadmin.media.delete')"
                );
            }
            try {
                $this->service($repository)->delete(
                    self::ASSET_PUBLIC_ID,
                    $version,
                    $this->sessionToken,
                    $csrf,
                    null,
                    self::REQUEST_ID
                );
                self::fail('Authorization must be revalidated in transaction.');
            } catch (MediaException $exception) {
                self::assertSame(
                    'webadmin.media.delete_forbidden',
                    $exception->issueCode()
                );
            }
            self::assertFileExists($this->originalVariantPath());
            self::assertSame(0, (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ls_webadmin_media_quarantines'
            )->fetchColumn());
        }
    }

    private function repository(): PdoMediaRepository
    {
        return new PdoMediaRepository(
            $this->pdo,
            WebAdminTableNames::fromPdo($this->pdo, 'ls_webadmin_'),
            true
        );
    }

    private function service(PdoMediaRepository $repository): MediaService
    {
        return new MediaService(
            $repository,
            $this->storage,
            new MediaQuarantineUnusedProcessor(),
            new WebAdminMutationActorGate(
                $this->pdo,
                WebAdminTableNames::fromPdo($this->pdo, 'ls_webadmin_'),
                WebAdminConfig::defaults(),
                $this->securityKey,
                $this->clock
            ),
            $this->securityKey,
            $this->clock
        );
    }

    private function assetVersion(PdoMediaRepository $repository): string
    {
        $items = $repository->listPage(1, 24)->items();
        self::assertCount(1, $items);
        $version = $items[0]['delete_version'] ?? null;
        self::assertIsString($version);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $version);

        return $version;
    }

    private function seedAssetAndFile(): void
    {
        $staging = $this->storage->createStagingDirectory();
        file_put_contents($staging . '/480.avif', $this->variantContents);
        $this->storage->promote($staging, self::ASSET_PUBLIC_ID);

        $asset = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_media_assets '
            . '(public_id, label, source_mime, source_width, source_height, '
            . 'source_bytes, source_sha256, created_by_user_id, created_at) '
            . "VALUES (:public_id, 'Portada', 'image/avif', 800, 600, 64, "
            . ":source_hash, 1, '2030-01-01 00:00:00.000000')"
        );
        $asset->execute([
            'public_id' => self::ASSET_PUBLIC_ID,
            'source_hash' => str_repeat('a', 64),
        ]);
        $variant = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_media_variants '
            . '(asset_id, width, height, bytes, sha256, storage_key, mime, '
            . "created_at) VALUES (1, 480, 360, :bytes, :hash, :storage, "
            . "'image/avif', '2030-01-01 00:00:00.000000')"
        );
        $variant->execute([
            'bytes' => strlen($this->variantContents),
            'hash' => hash('sha256', $this->variantContents),
            'storage' => '30/' . self::ASSET_PUBLIC_ID . '/480.avif',
        ]);
    }

    private function seedAuthorizedSiteAdmin(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_users '
            . '(public_id, email_canonical, status, auth_version, activated_at) '
            . "VALUES (:public_id, 'admin@example.test', 'active', 1, "
            . "'2030-01-01 00:00:00.000000')"
        );
        $statement->execute(['public_id' => self::USER_PUBLIC_ID]);
        $credential = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_credentials '
            . '(user_id, password_hash, password_set_at) '
            . "VALUES (1, :hash, '2030-01-01 00:00:00.000000')"
        );
        $credential->execute([
            'hash' => PasswordHasher::productive()->verificationDummyHash(),
        ]);
        $session = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_sessions '
            . '(public_id, user_id, session_type, token_hash, csrf_token_hash, '
            . 'auth_version, created_at, last_seen_at, idle_expires_at, '
            . 'absolute_expires_at) VALUES (:public_id, 1, '
            . "'authenticated', :token, :csrf, 1, "
            . "'2030-01-01 00:00:00.000000', "
            . "'2030-01-01 00:05:00.000000', "
            . "'2030-01-01 00:20:00.000000', "
            . "'2030-01-01 01:00:00.000000')"
        );
        $session->execute([
            'public_id' => self::SESSION_PUBLIC_ID,
            'token' => hash('sha256', $this->sessionToken),
            'csrf' => hash('sha256', $this->csrfToken),
        ]);
        $this->pdo->exec(
            'INSERT INTO ls_webadmin_user_roles (user_id, role_id, source) '
            . "SELECT 1, id, 'manual' FROM ls_webadmin_roles "
            . "WHERE code = 'site_admin'"
        );
    }

    private function originalVariantPath(): string
    {
        return $this->storageRoot . '/30/' . self::ASSET_PUBLIC_ID
            . '/480.avif';
    }

    private function quarantinedVariantPath(): string
    {
        return $this->storageRoot . '/.quarantine/assets/30/'
            . self::ASSET_PUBLIC_ID . '/' . self::REQUEST_ID . '/480.avif';
    }

    private function manifestPath(): string
    {
        return $this->storageRoot . '/.quarantine/manifests/'
            . self::REQUEST_ID . '.json';
    }

    private static function token(string $byte): string
    {
        return rtrim(strtr(
            base64_encode(str_repeat($byte, 32)),
            '+/',
            '-_'
        ), '=');
    }
}

final class MediaQuarantineFixedClock implements ClockInterface
{
    public function __construct(private readonly DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
}

final class MediaQuarantineUnusedProcessor implements MediaImageProcessorInterface
{
    public function process(
        UploadedFile $upload,
        string $stagingDirectory
    ): ProcessedMediaUpload {
        throw new MediaException('test.processor_not_expected');
    }
}
