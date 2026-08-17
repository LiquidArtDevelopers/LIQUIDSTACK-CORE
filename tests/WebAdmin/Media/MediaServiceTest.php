<?php

declare(strict_types=1);

namespace Tests\WebAdmin\Media;

use App\Core\Http\Request;
use App\Core\Http\UploadedFile;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizedActor;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationRepository;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Media\MediaAssetPage;
use App\Core\WebAdmin\Media\MediaCatalogAsset;
use App\Core\WebAdmin\Media\MediaPickerCatalogRepositoryInterface;
use App\Core\WebAdmin\Media\MediaPickerItem;
use App\Core\WebAdmin\Media\MediaPickerPage;
use App\Core\WebAdmin\Media\MediaPickerQuery;
use App\Core\WebAdmin\Media\MediaDeletionCandidate;
use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\MediaFileMetadata;
use App\Core\WebAdmin\Media\MediaFilePayload;
use App\Core\WebAdmin\Media\MediaImageProcessorInterface;
use App\Core\WebAdmin\Media\MediaQuarantineManifest;
use App\Core\WebAdmin\Media\MediaQuarantineLease;
use App\Core\WebAdmin\Media\MediaRepositoryInterface;
use App\Core\WebAdmin\Media\MediaService;
use App\Core\WebAdmin\Media\MediaStorageInterface;
use App\Core\WebAdmin\Media\MediaStoredVariant;
use App\Core\WebAdmin\Media\Http\WebAdminMediaHttpController;
use App\Core\WebAdmin\Media\Http\WebAdminMediaHttpRuntime;
use App\Core\WebAdmin\Media\ProcessedMediaUpload;
use App\Core\WebAdmin\Media\ProcessedMediaVariant;
use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalog;
use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

final class MediaServiceTest extends TestCase
{
    private const USER_ID = '10000000-0000-4000-8000-000000000001';
    private const SESSION_ID = '20000000-0000-4000-8000-000000000002';
    private const ASSET_ID = '30000000-0000-4000-8000-000000000003';
    private const REQUEST_ID = '40000000-0000-4000-8000-000000000004';

    private PDO $pdo;
    private SecurityKey $securityKey;
    private string $sessionToken;
    private string $csrfToken;
    private MediaTestClock $clock;
    private MediaTestEventLog $events;
    private MediaTestRepository $repository;
    private MediaTestStorage $storage;
    private string $temporaryUpload;
    private string $previousExceptionTraceSetting;
    private ?string $processorIssue = null;

    protected function setUp(): void
    {
        $this->previousExceptionTraceSetting = (string) ini_get(
            'zend.exception_ignore_args'
        );
        ini_set('zend.exception_ignore_args', '1');
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite es necesario.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $scope = MigrationScope::forTablePrefix('webadmin', 'ls_webadmin_');
        foreach (WebAdminMigrationProvider::migrations() as $migration) {
            foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
                $this->pdo->exec($sql);
            }
        }

        $this->securityKey = SecurityKey::fromRawBytes(str_repeat('M', 32));
        $this->sessionToken = self::token('A');
        $this->csrfToken = $this->securityKey->deriveToken(
            'csrf.session',
            $this->sessionToken
        );
        $this->clock = new MediaTestClock(
            new DateTimeImmutable('2030-01-01 00:10:00 UTC')
        );
        $this->seedAuthorizedSiteAdmin();
        $this->events = new MediaTestEventLog();
        $this->repository = new MediaTestRepository($this->pdo, $this->events);
        $this->storage = new MediaTestStorage($this->events);
        $this->temporaryUpload = tempnam(sys_get_temp_dir(), 'ls-media-service-');
        self::assertIsString($this->temporaryUpload);
        file_put_contents($this->temporaryUpload, 'source-payload');
    }

    protected function tearDown(): void
    {
        ini_set(
            'zend.exception_ignore_args',
            $this->previousExceptionTraceSetting
        );
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->temporaryUpload) && is_file($this->temporaryUpload)) {
            @unlink($this->temporaryUpload);
        }
    }

    public function testUploadSerializesRateAndQuotaBeforeSumAndPersistence(): void
    {
        $publicId = $this->service()->upload(
            $this->upload(),
            'Portada principal',
            $this->sessionToken,
            $this->csrfToken,
            '127.0.0.1'
        );

        self::assertSame(self::ASSET_ID, $publicId);
        self::assertSame([
            'storage.stage',
            'processor.process',
            'repository.transaction.begin',
            'repository.quota.lock',
            'repository.idempotency.find',
            'repository.rate.media.upload.user',
            'repository.rate.media.upload.ip',
            'repository.quota.sum',
            'storage.promote',
            'repository.asset.insert',
            'storage.key',
            'repository.variant.insert',
            'repository.audit.insert',
            'repository.transaction.commit',
        ], $this->events->all());
        self::assertLessThan(
            array_search('repository.quota.sum', $this->events->all(), true),
            array_search('repository.quota.lock', $this->events->all(), true)
        );
        self::assertLessThan(
            array_search('storage.promote', $this->events->all(), true),
            array_search('repository.quota.sum', $this->events->all(), true)
        );
    }

    public function testRepeatedUploadRequestReturnsExistingAssetWithoutPromotingAgain(): void
    {
        $this->repository->createdRequest = [
            'request_id' => self::REQUEST_ID,
            'label' => 'Portada principal',
            'source_sha256' => hash_file('sha256', $this->temporaryUpload),
            'public_id' => self::ASSET_ID,
        ];

        $publicId = $this->service()->upload(
            $this->upload(),
            'Portada principal',
            $this->sessionToken,
            $this->csrfToken,
            '127.0.0.1',
            self::REQUEST_ID
        );

        self::assertSame(self::ASSET_ID, $publicId);
        self::assertSame([
            'storage.stage',
            'processor.process',
            'repository.transaction.begin',
            'repository.quota.lock',
            'repository.idempotency.find',
            'storage.remove_staging',
            'repository.transaction.commit',
        ], $this->events->all());
        self::assertNotContains('storage.promote', $this->events->all());
        self::assertNotContains('repository.asset.insert', $this->events->all());
        self::assertNotContains(
            'repository.rate.media.upload.user',
            $this->events->all()
        );
    }

    public function testDatabaseFailureRollsBackAndRemovesPromotedAsset(): void
    {
        $this->repository->failVariantInsert = true;

        try {
            $this->service()->upload(
                $this->upload(),
                'Portada principal',
                $this->sessionToken,
                $this->csrfToken,
                null
            );
            self::fail('Variant persistence failure must escape.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.persistence_test_failure',
                $exception->issueCode()
            );
        }

        self::assertContains('repository.transaction.rollback', $this->events->all());
        self::assertContains('storage.remove_asset', $this->events->all());
        self::assertNotContains('repository.audit.insert', $this->events->all());
    }

    public function testQuotaFailureNeverPromotesAndCleansStaging(): void
    {
        $this->repository->usedBytes = MediaService::DEFAULT_QUOTA_BYTES;

        try {
            $this->service()->upload(
                $this->upload(),
                'Portada principal',
                $this->sessionToken,
                $this->csrfToken,
                null
            );
            self::fail('Quota exhaustion must fail closed.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.storage_quota_exceeded',
                $exception->issueCode()
            );
        }

        self::assertContains('repository.quota.lock', $this->events->all());
        self::assertContains('repository.quota.sum', $this->events->all());
        self::assertContains('storage.remove_staging', $this->events->all());
        self::assertNotContains('storage.promote', $this->events->all());
    }

    public function testMetadataProbeDoesNotReadTheFullFilePayload(): void
    {
        $this->repository->storedVariant = new MediaStoredVariant(
            '30/' . self::ASSET_ID . '/480.avif',
            480,
            320,
            123,
            str_repeat('a', 64)
        );

        $metadata = $this->service()->fileMetadata(self::ASSET_ID, 480);

        self::assertInstanceOf(MediaFileMetadata::class, $metadata);
        self::assertSame(123, $metadata->bytes());
        self::assertSame(['storage.probe'], $this->events->all());
        self::assertSame(0, $this->storage->fullReads);
    }

    public function testHeadFileUsesMetadataProbeAndReturnsGetHeadersWithoutBody(): void
    {
        $this->repository->storedVariant = new MediaStoredVariant(
            '30/' . self::ASSET_ID . '/480.avif',
            480,
            320,
            123,
            str_repeat('a', 64)
        );
        $controller = $this->controller();
        $response = $controller->file($this->authenticatedRequest(
            'HEAD',
            '/admin/media/file',
            ['asset' => self::ASSET_ID, 'width' => '480']
        ));

        self::assertSame(200, $response->status());
        self::assertSame('', $response->body());
        self::assertSame('image/avif', $response->headers()['Content-Type']);
        self::assertSame('123', $response->headers()['Content-Length']);
        self::assertSame(['storage.probe'], $this->events->all());
        self::assertSame(0, $this->storage->fullReads);
    }

    public function testHeadIndexExecutesTheSameListProbeAsGet(): void
    {
        $controller = $this->controller();
        $head = $controller->index($this->authenticatedRequest(
            'HEAD',
            '/admin/media'
        ));

        self::assertSame(200, $head->status());
        self::assertSame('', $head->body());
        self::assertSame(['repository.list'], $this->events->all());
    }

    public function testListAddsSafeStandaloneUsageStatus(): void
    {
        $this->repository->listedItems = [[
            'public_id' => self::ASSET_ID,
            'label' => 'Portada principal',
            'source_width' => 1200,
            'source_height' => 800,
            'created_at' => '2030-01-01T00:00:00+00:00',
            'thumbnail_width' => 480,
            'variants' => [[
                'width' => 480,
                'height' => 320,
                'bytes' => 123,
            ]],
        ]];

        $items = $this->service()->list(1)->items();

        self::assertSame('unused', $items[0]['usage_status']);
    }

    public function testGetIndexUsesTheAuthenticatedSharedShell(): void
    {
        $response = $this->controller()->index($this->authenticatedRequest(
            'GET',
            '/admin/media'
        ));

        self::assertSame(200, $response->status());
        self::assertSame(1, substr_count($response->body(), '<main'));
        self::assertStringContainsString(
            'data-webadmin-shell',
            $response->body()
        );
        self::assertStringContainsString(
            'href="/admin/media" aria-current="page"',
            $response->body()
        );
        self::assertStringContainsString(
            'action="/admin/logout"',
            $response->body()
        );
        self::assertSame(
            "default-src 'none'; connect-src 'self'; img-src 'self'; "
                . "style-src 'self'; script-src 'self'; form-action 'self'; "
                . "frame-ancestors 'none'; base-uri 'none'",
            $response->headers()['Content-Security-Policy']
        );
    }

    public function testHeadAndGetIndexHaveStatusParityWhenListingFails(): void
    {
        $this->repository->failList = true;
        $controller = $this->controller();

        $get = $controller->index($this->authenticatedRequest(
            'GET',
            '/admin/media'
        ));
        $head = $controller->index($this->authenticatedRequest(
            'HEAD',
            '/admin/media'
        ));

        self::assertSame(503, $get->status());
        self::assertSame($get->status(), $head->status());
    }

    public function testUploadMapsOnlyImageContractFailuresTo422(): void
    {
        $this->processorIssue = 'webadmin.media.source_type_rejected';
        $invalidImage = $this->controller()->upload(
            $this->authenticatedUploadRequest()
        );
        self::assertSame(422, $invalidImage->status());

        $this->processorIssue = 'webadmin.media.codec_operational_failure';
        $operationalFailure = $this->controller()->upload(
            $this->authenticatedUploadRequest()
        );
        self::assertSame(503, $operationalFailure->status());

        $this->processorIssue = 'webadmin.media.avif_source_schema_pending';
        $pendingAvif = $this->controller()->upload(
            $this->authenticatedUploadRequest()
        );
        self::assertSame(409, $pendingAvif->status());
        self::assertSame(
            'AVIF source upload is not enabled yet',
            $pendingAvif->body()
        );
    }

    public function testAsyncUploadReturnsTheNewBoundedCatalogProjection(): void
    {
        $response = $this->controller()->upload(
            $this->authenticatedUploadRequest(true)
        );

        self::assertSame(200, $response->status());
        self::assertSame(
            'application/json; charset=utf-8',
            $response->headers()['Content-Type']
        );
        self::assertSame(
            "default-src 'none'; form-action 'none'; "
                . "frame-ancestors 'none'; base-uri 'none'",
            $response->headers()['Content-Security-Policy']
        );
        self::assertSame([
            'ok' => true,
            'media' => [
                'public_id' => self::ASSET_ID,
                'label' => 'Portada',
                'thumbnail_width' => 480,
                'thumbnail_url' => '/admin/media/file?asset=' . self::ASSET_ID
                    . '&width=480',
            ],
        ], json_decode($response->body(), true, 8, JSON_THROW_ON_ERROR));
    }

    public function testPrgUploadContractRemainsTheDefault(): void
    {
        $response = $this->controller()->upload(
            $this->authenticatedUploadRequest()
        );

        self::assertSame(303, $response->status());
        self::assertSame('/admin/media/updated', $response->headers()['Location']);
    }

    public function testPrivatePickerCatalogRequiresSessionAndReturnsSafeJson(): void
    {
        $query = new MediaPickerQuery('Árbol', 1, 24);
        $this->repository->pickerPage = new MediaPickerPage($query, [
            new MediaPickerItem(
                self::ASSET_ID,
                'Árbol de portada',
                1800,
                1200,
                new DateTimeImmutable('2030-01-01 10:00:00 UTC'),
                480,
                [
                    ['width' => 480, 'height' => 320, 'bytes' => 100],
                    ['width' => 1800, 'height' => 1200, 'bytes' => 900],
                ]
            ),
        ], false);
        $headers = [
            'Accept' => 'application/json',
            'X-LiquidStack-Media-Picker' => 'async',
        ];
        $response = $this->controller()->catalog(
            $this->authenticatedRequest(
                'GET',
                '/admin/media/catalog?q=%C3%81rbol&page=1&per_page=24',
                ['q' => 'Árbol', 'page' => '1', 'per_page' => '24'],
                $headers
            )
        );

        self::assertSame(200, $response->status());
        $payload = json_decode(
            $response->body(),
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(self::ASSET_ID, $payload['items'][0]['public_id']);
        self::assertSame(1800, $payload['items'][0]['source_width']);
        self::assertArrayNotHasKey('storage_key', $payload['items'][0]);
        self::assertArrayNotHasKey('sha256', $payload['items'][0]);
        self::assertSame(
            'no-store, no-cache, must-revalidate, max-age=0',
            $response->headers()['Cache-Control']
        );

        $anonymous = Request::fromInput(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/media/catalog'],
            [],
            [],
            [],
            $headers
        );
        self::assertSame(
            303,
            $this->controller()->catalog($anonymous)->status()
        );
    }

    private function service(): MediaService
    {
        return new MediaService(
            $this->repository,
            $this->storage,
            new MediaTestProcessor($this->events, $this->processorIssue),
            new WebAdminMutationActorGate(
                $this->pdo,
                WebAdminTableNames::fromPdo($this->pdo, 'ls_webadmin_'),
                WebAdminConfig::defaults(),
                $this->securityKey,
                $this->clock
            ),
            $this->securityKey,
            $this->clock,
            new MediaTestUuidGenerator([self::ASSET_ID, self::REQUEST_ID])
        );
    }

    private function controller(): WebAdminMediaHttpController
    {
        $tables = WebAdminTableNames::fromPdo($this->pdo, 'ls_webadmin_');
        $config = WebAdminConfig::defaults();
        $authentication = new WebAdminAuthenticationService(
            new WebAdminAuthenticationRepository($this->pdo, $tables),
            $config,
            $this->securityKey,
            $this->clock,
            new MediaTestUuidGenerator([self::REQUEST_ID])
        );
        $authorization = new WebAdminAuthorizationService(
            $this->pdo,
            $tables,
            $this->clock
        );

        return new WebAdminMediaHttpController(new WebAdminMediaHttpRuntime(
            $config,
            $authentication,
            $authorization,
            $this->service(),
            new WebAdminNavigationCatalog([
                new WebAdminNavigationItem(
                    'webadmin',
                    'Medios',
                    '/media',
                    MediaService::VIEW_CAPABILITY
                ),
            ])
        ));
    }

    /** @param array<string, string> $query */
    private function authenticatedRequest(
        string $method,
        string $path,
        array $query = [],
        array $headers = []
    ): Request {
        return Request::fromInput(
            ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path],
            $query,
            [],
            [WebAdminConfig::defaults()->cookieName() => $this->sessionToken],
            $headers
        );
    }

    private function authenticatedUploadRequest(bool $async = false): Request
    {
        return Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/media/upload',
                'CONTENT_TYPE' => 'multipart/form-data; boundary=LiquidBoundary',
                'CONTENT_LENGTH' => '2048',
            ],
            [],
            [
                'csrf' => $this->csrfToken,
                'label' => 'Portada',
                'idempotency_key' => self::REQUEST_ID,
            ],
            [WebAdminConfig::defaults()->cookieName() => $this->sessionToken],
            $async ? [
                'Accept' => 'application/json',
                'X-LiquidStack-Media-Manager' => 'async',
            ] : [],
            '',
            ['image' => [
                'name' => 'ignored.png',
                'type' => 'image/png',
                'tmp_name' => $this->temporaryUpload,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($this->temporaryUpload),
            ]]
        );
    }

    private function upload(): UploadedFile
    {
        $upload = UploadedFile::fromTestInput([
            'name' => 'ignored.png',
            'type' => 'image/png',
            'tmp_name' => $this->temporaryUpload,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($this->temporaryUpload),
        ]);
        self::assertInstanceOf(UploadedFile::class, $upload);

        return $upload;
    }

    private function seedAuthorizedSiteAdmin(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_users '
            . '(public_id, email_canonical, status, auth_version, activated_at) '
            . "VALUES (:public_id, 'admin@example.test', 'active', 1, "
            . "'2030-01-01 00:00:00.000000')"
        );
        $statement->execute(['public_id' => self::USER_ID]);
        $userId = (int) $this->pdo->lastInsertId();
        $credential = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_credentials '
            . '(user_id, password_hash, password_set_at) '
            . "VALUES (:user, :hash, '2030-01-01 00:00:00.000000')"
        );
        $credential->execute([
            'user' => $userId,
            'hash' => PasswordHasher::productive()->verificationDummyHash(),
        ]);
        $session = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_sessions '
            . '(public_id, user_id, session_type, token_hash, csrf_token_hash, '
            . 'auth_version, created_at, last_seen_at, idle_expires_at, '
            . 'absolute_expires_at) VALUES (:public_id, :user, '
            . "'authenticated', :token, :csrf, 1, "
            . "'2030-01-01 00:00:00.000000', "
            . "'2030-01-01 00:05:00.000000', "
            . "'2030-01-01 00:20:00.000000', "
            . "'2030-01-01 01:00:00.000000')"
        );
        $session->execute([
            'public_id' => self::SESSION_ID,
            'user' => $userId,
            'token' => hash('sha256', $this->sessionToken),
            'csrf' => hash('sha256', $this->csrfToken),
        ]);
        $role = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_user_roles (user_id, role_id, source) '
            . "SELECT :user, id, 'manual' FROM ls_webadmin_roles "
            . "WHERE code = 'site_admin'"
        );
        $role->execute(['user' => $userId]);
    }

    private static function token(string $byte): string
    {
        return rtrim(strtr(base64_encode(str_repeat($byte, 32)), '+/', '-_'), '=');
    }
}

final class MediaTestEventLog
{
    /** @var list<string> */
    private array $events = [];
    public function add(string $event): void { $this->events[] = $event; }
    /** @return list<string> */
    public function all(): array { return $this->events; }
}

final class MediaTestRepository implements MediaPickerCatalogRepositoryInterface
{
    public int $usedBytes = 0;
    public bool $failVariantInsert = false;
    public bool $failList = false;
    public ?MediaStoredVariant $storedVariant = null;
    /** @var list<array<string, mixed>> */
    public array $listedItems = [];
    public ?MediaPickerPage $pickerPage = null;
    /** @var array{request_id:string,label:string,source_sha256:string,public_id:string}|null */
    public ?array $createdRequest = null;
    /** @var array<string, string> */
    private array $catalogLabels = [];
    public function __construct(private PDO $pdo, private MediaTestEventLog $events) {}
    public function transaction(callable $operation): mixed
    {
        $this->events->add('repository.transaction.begin');
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            $this->events->add('repository.transaction.commit');
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            $this->events->add('repository.transaction.rollback');
            throw $exception;
        }
    }
    public function listPage(int $page, int $pageSize): MediaAssetPage
    {
        $this->events->add('repository.list');
        if ($this->failList) {
            throw new MediaException('webadmin.media.list_failed');
        }
        return new MediaAssetPage($this->listedItems, $page, false);
    }
    public function pickerPage(MediaPickerQuery $query): MediaPickerPage
    {
        if ($this->pickerPage === null) {
            throw new MediaException('test.picker_not_configured');
        }
        if (
            $this->pickerPage->query()->search() !== $query->search()
            || $this->pickerPage->query()->page() !== $query->page()
            || $this->pickerPage->query()->pageSize() !== $query->pageSize()
        ) {
            throw new MediaException('test.picker_query_mismatch');
        }

        return $this->pickerPage;
    }
    public function findVariant(string $publicId, int $width): ?MediaStoredVariant
    { return $this->storedVariant; }
    public function totalVariantBytes(): int
    { $this->events->add('repository.quota.sum'); return $this->usedBytes; }
    public function lockQuota(): void
    { self::assertTransaction($this->pdo); $this->events->add('repository.quota.lock'); }
    public function lockDeletionCandidate(string $publicId): ?MediaDeletionCandidate
    { throw new MediaException('test.delete_not_configured'); }
    public function quarantinedPublicIdForRequest(WebAdminAuthorizedActor $actor, string $requestId, string $publicId, string $assetVersion): ?string
    { throw new MediaException('test.delete_not_configured'); }
    public function recordQuarantine(WebAdminAuthorizedActor $actor, string $requestId, MediaDeletionCandidate $candidate, MediaQuarantineManifest $manifest, ?string $ipHash, DateTimeImmutable $occurredAt): void
    { throw new MediaException('test.delete_not_configured'); }
    public function createdPublicIdForRequest(WebAdminAuthorizedActor $actor, string $requestId, string $label, string $sourceSha256): ?string
    {
        self::assertTransaction($this->pdo);
        $this->events->add('repository.idempotency.find');
        if ($this->createdRequest === null) {
            return null;
        }
        if (
            $this->createdRequest['request_id'] !== $requestId
            || $this->createdRequest['label'] !== $label
            || $this->createdRequest['source_sha256'] !== $sourceSha256
        ) {
            throw new MediaException('webadmin.media.idempotency_conflict');
        }
        return $this->createdRequest['public_id'];
    }
    public function consumeRateLimit(string $action, string $subjectHash, DateTimeImmutable $now, int $windowSeconds, int $maximumAttempts): bool
    { self::assertTransaction($this->pdo); $this->events->add('repository.rate.' . $action); return true; }
    public function insertAsset(string $publicId, string $label, ProcessedMediaUpload $processed, int $authorUserId, DateTimeImmutable $createdAt): int
    { $this->events->add('repository.asset.insert'); $this->catalogLabels[$publicId] = $label; return 99; }
    public function insertVariant(int $assetId, ProcessedMediaVariant $variant, string $storageKey, DateTimeImmutable $createdAt): void
    { $this->events->add('repository.variant.insert'); if ($this->failVariantInsert) { throw new MediaException('webadmin.media.persistence_test_failure'); } }
    public function auditCreated(WebAdminAuthorizedActor $actor, string $requestId, string $publicId, ?string $ipHash, DateTimeImmutable $occurredAt): void
    { $this->events->add('repository.audit.insert'); }
    public function publicIds(int $limit): array { return []; }
    public function catalogAssetsByPublicIds(array $publicIds): array
    {
        $assets = [];
        foreach ($publicIds as $publicId) {
            if (isset($this->catalogLabels[$publicId])) {
                $assets[] = new MediaCatalogAsset(
                    $publicId,
                    $this->catalogLabels[$publicId],
                    480
                );
            }
        }
        return $assets;
    }
    private static function assertTransaction(PDO $pdo): void
    { if (!$pdo->inTransaction()) { throw new MediaException('test.transaction_missing'); } }
}

final class MediaTestStorage implements MediaStorageInterface
{
    public int $fullReads = 0;
    public function __construct(private MediaTestEventLog $events) {}
    public function createStagingDirectory(): string
    { $this->events->add('storage.stage'); return 'test-staging'; }
    public function promote(string $stagingDirectory, string $publicId): void
    { $this->events->add('storage.promote'); }
    public function removeStaging(string $stagingDirectory): void
    { $this->events->add('storage.remove_staging'); }
    public function removeAsset(string $publicId): void
    { $this->events->add('storage.remove_asset'); }
    public function quarantine(MediaDeletionCandidate $candidate, string $requestId): MediaQuarantineLease
    { throw new MediaException('test.delete_not_configured'); }
    public function storageKey(string $publicId, int $width): string
    { $this->events->add('storage.key'); return substr($publicId, 0, 2) . '/' . $publicId . '/' . $width . '.avif'; }
    public function readVerified(MediaStoredVariant $variant): MediaFilePayload
    { ++$this->fullReads; $this->events->add('storage.read'); return new MediaFilePayload('x', 1, 1); }
    public function probeVerified(MediaStoredVariant $variant): MediaFileMetadata
    { $this->events->add('storage.probe'); return new MediaFileMetadata($variant->width(), $variant->height(), $variant->bytes()); }
    public function diagnostic(?array $knownPublicIds = null): array
    { return ['ready' => true, 'status' => 'ready', 'orphan_count' => 0, 'orphan_scan_status' => 'checked', 'staging_count' => 0]; }
}

final class MediaTestProcessor implements MediaImageProcessorInterface
{
    public function __construct(
        private MediaTestEventLog $events,
        private ?string $issue = null
    ) {}
    public function process(UploadedFile $upload, string $stagingDirectory): ProcessedMediaUpload
    {
        $this->events->add('processor.process');
        if ($this->issue !== null) {
            throw new MediaException($this->issue);
        }
        $variant = new ProcessedMediaVariant(480, 320, 100, str_repeat('a', 64), '480.avif');
        return new ProcessedMediaUpload('image/png', 600, 400, $upload->size(), hash_file('sha256', $upload->temporaryPath()), [$variant]);
    }
}

final class MediaTestClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
}

final class MediaTestUuidGenerator implements UuidGeneratorInterface
{
    /** @param list<string> $values */
    public function __construct(private array $values) {}
    public function generateV4(): string
    { return array_shift($this->values) ?? throw new \LogicException('No UUID left.'); }
}
