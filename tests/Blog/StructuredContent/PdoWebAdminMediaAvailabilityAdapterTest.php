<?php

declare(strict_types=1);

use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\Blog\StructuredContent\Media\PdoWebAdminMediaAvailabilityAdapter;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use PHPUnit\Framework\TestCase;

final class PdoWebAdminMediaAvailabilityAdapterTest extends TestCase
{
    private const USER = '00000000-0000-4000-8000-000000000001';
    private const ASSET_READY = '00000000-0000-4000-8000-000000000002';
    private const ASSET_EMPTY = '00000000-0000-4000-8000-000000000003';
    private const ASSET_MISSING = '00000000-0000-4000-8000-000000000004';

    private PDO $pdo;
    private MigrationScope $scope;
    private PdoWebAdminMediaAvailabilityAdapter $adapter;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->pdo = $this->sqlite();
        $this->scope = MigrationScope::forTablePrefix(
            'webadmin',
            'ls_webadmin_'
        );
        $this->applyMigrations(['0001_webadmin_identity_and_access', '0002_webadmin_media_library']);
        $this->seedMedia();
        $this->adapter = new PdoWebAdminMediaAvailabilityAdapter(
            $this->pdo,
            $this->scope
        );
    }

    public function testExactBoundTransactionCanVerifyAssetsWithVariants(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->adapter->assertAvailable($this->pdo, []);
            $this->adapter->assertAvailable(
                $this->pdo,
                [self::ASSET_READY, self::ASSET_READY]
            );
            self::assertTrue($this->pdo->inTransaction());
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testMissingAssetAndAssetWithoutVariantFailWithoutDisclosure(): void
    {
        $this->pdo->beginTransaction();
        try {
            foreach ([self::ASSET_EMPTY, self::ASSET_MISSING] as $publicId) {
                try {
                    $this->adapter->assertAvailable($this->pdo, [$publicId]);
                    self::fail('Unavailable media was accepted.');
                } catch (BlogStructuredContentException $exception) {
                    self::assertSame(
                        BlogStructuredContentException::MEDIA_NOT_FOUND,
                        $exception->issueCode()
                    );
                    self::assertStringNotContainsString(
                        $publicId,
                        (string) $exception
                    );
                }
            }
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testAdapterRequiresSamePdoAndAnActiveTransaction(): void
    {
        $this->expectIssue(
            BlogStructuredContentException::MEDIA_UNAVAILABLE,
            fn () => $this->adapter->assertAvailable(
                $this->pdo,
                [self::ASSET_READY]
            )
        );

        $other = $this->sqlite();
        $other->beginTransaction();
        try {
            $this->pdo->beginTransaction();
            try {
                $this->expectIssue(
                    BlogStructuredContentException::MEDIA_UNAVAILABLE,
                    fn () => $this->adapter->assertAvailable(
                        $other,
                        [self::ASSET_READY]
                    )
                );
            } finally {
                $this->pdo->rollBack();
            }
        } finally {
            $other->rollBack();
        }
    }

    public function testInputIsBoundedToTwoHundredCanonicalUuids(): void
    {
        $this->pdo->beginTransaction();
        try {
            foreach ([
                array_fill(0, 201, self::ASSET_READY),
                ['not-a-uuid'],
                ['named' => self::ASSET_READY],
            ] as $invalid) {
                $this->expectIssue(
                    BlogStructuredContentException::INVALID_INPUT,
                    fn () => $this->adapter->assertAvailable(
                        $this->pdo,
                        $invalid
                    )
                );
            }
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testPdoContractAndMaximumMediaPrefixAreEnforced(): void
    {
        $withoutForeignKeys = new PDO('sqlite::memory:');
        $withoutForeignKeys->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );
        $this->expectIssue(
            BlogStructuredContentException::MEDIA_UNAVAILABLE,
            fn () => new PdoWebAdminMediaAvailabilityAdapter(
                $withoutForeignKeys,
                $this->scope
            )
        );
        $this->expectIssue(
            BlogStructuredContentException::MEDIA_UNAVAILABLE,
            fn () => new PdoWebAdminMediaAvailabilityAdapter(
                $this->pdo,
                MigrationScope::forTablePrefix('blog', 'ls_webadmin_')
            )
        );

        $maxPrefix = 'w' . str_repeat('x', 48) . '_';
        self::assertSame(50, strlen($maxPrefix));
        self::assertInstanceOf(
            PdoWebAdminMediaAvailabilityAdapter::class,
            new PdoWebAdminMediaAvailabilityAdapter(
                $this->pdo,
                MigrationScope::forTablePrefix('webadmin', $maxPrefix)
            )
        );
        $tooLong = 'w' . str_repeat('x', 49) . '_';
        $this->expectIssue(
            BlogStructuredContentException::MEDIA_UNAVAILABLE,
            fn () => new PdoWebAdminMediaAvailabilityAdapter(
                $this->pdo,
                MigrationScope::forTablePrefix('webadmin', $tooLong)
            )
        );
    }

    /** @param list<string> $ids */
    private function applyMigrations(array $ids): void
    {
        $pending = array_fill_keys($ids, true);
        foreach (WebAdminMigrationProvider::migrations() as $migration) {
            if (!isset($pending[$migration->id()])) {
                continue;
            }
            foreach ($migration->statementsFor('sqlite', $this->scope) as $sql) {
                self::assertNotFalse($this->pdo->exec($sql));
            }
            unset($pending[$migration->id()]);
        }
        self::assertSame([], array_keys($pending));
    }

    private function seedMedia(): void
    {
        $user = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_users '
            . '(public_id, email_canonical, status, auth_version, activated_at) '
            . "VALUES (:public_id, 'media@example.test', 'active', 1, "
            . "'2030-01-01 00:00:00.000000')"
        );
        self::assertNotFalse($user);
        self::assertTrue($user->execute(['public_id' => self::USER]));
        $userId = (int) $this->pdo->lastInsertId();

        $asset = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_media_assets '
            . '(public_id, label, source_mime, source_width, source_height, '
            . 'source_bytes, source_sha256, created_by_user_id, created_at) '
            . "VALUES (:public_id, 'Matrix', 'image/png', 900, 600, 100, "
            . ":hash, :user_id, '2030-01-01 00:00:00.000000')"
        );
        self::assertNotFalse($asset);
        foreach ([self::ASSET_READY, self::ASSET_EMPTY] as $publicId) {
            self::assertTrue($asset->execute([
                'public_id' => $publicId,
                'hash' => str_repeat('a', 64),
                'user_id' => $userId,
            ]));
        }
        $readyId = (int) $this->pdo->query(
            "SELECT id FROM ls_webadmin_media_assets WHERE public_id = '"
            . self::ASSET_READY . "'"
        )->fetchColumn();
        $variant = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_media_variants '
            . '(asset_id, width, height, bytes, sha256, storage_key, mime, '
            . 'created_at) VALUES (:asset_id, 900, 600, 50, :hash, '
            . ":storage_key, 'image/avif', "
            . "'2030-01-01 00:00:00.000000')"
        );
        self::assertNotFalse($variant);
        self::assertTrue($variant->execute([
            'asset_id' => $readyId,
            'hash' => str_repeat('b', 64),
            'storage_key' => 'media/ready/900.avif',
        ]));
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function expectIssue(string $issueCode, callable $operation): void
    {
        try {
            $operation();
            self::fail('Media availability failure was expected.');
        } catch (BlogStructuredContentException $exception) {
            self::assertSame($issueCode, $exception->issueCode());
        }
    }
}
