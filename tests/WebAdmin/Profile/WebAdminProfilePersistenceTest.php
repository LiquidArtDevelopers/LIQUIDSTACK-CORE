<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizedActor;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Profile\PdoWebAdminProfileRepository;
use App\Core\WebAdmin\Profile\WebAdminTimeZone;
use PHPUnit\Framework\TestCase;

final class WebAdminProfilePersistenceTest extends TestCase
{
    public function testLiveProjectionUtcFallbackAndCasUpdate(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $scope = MigrationScope::forTablePrefix('webadmin', 'wa_');
        $migrations = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        );
        foreach ([$migrations[0], $migrations[3]] as $migration) {
            foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
                $pdo->exec($sql);
            }
        }
        $user = '11111111-1111-4111-8111-111111111111';
        $session = '22222222-2222-4222-8222-222222222222';
        $pdo->prepare(
            'INSERT INTO wa_users (public_id, email_canonical, display_name, '
            . 'status) VALUES (?, ?, ?, ?)'
        )->execute([$user, 'editor@example.test', 'Nombre inicial', 'active']);
        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO wa_user_roles (user_id, role_id, source) '
            . "SELECT ?, id, 'manual' FROM wa_roles WHERE code = 'site_admin'"
        )->execute([$userId]);

        $repository = new PdoWebAdminProfileRepository(
            $pdo,
            WebAdminTableNames::fromPdo($pdo, 'wa_')
        );
        $initial = $repository->liveByPublicId($user);
        self::assertNotNull($initial);
        self::assertSame('Nombre inicial', $initial->displayName());
        self::assertSame('Administrador', $initial->roleLabel());
        self::assertFalse($initial->timeZoneConfigured());
        self::assertSame('UTC', $initial->timeZone()->value());
        self::assertSame(0, $initial->lockVersion());

        $actor = new WebAdminAuthorizedActor($userId, $user, $session);
        $now = new DateTimeImmutable('2026-08-08T10:00:00Z');
        self::assertTrue($repository->transactional(fn (): bool =>
            $repository->update(
                $actor,
                'Nombre vivo',
                WebAdminTimeZone::fromIana('Europe/Madrid'),
                0,
                $now
            )
        ));
        $updated = $repository->liveByPublicId($user);
        self::assertNotNull($updated);
        self::assertSame('Nombre vivo', $updated->displayName());
        self::assertSame('Europe/Madrid', $updated->timeZone()->value());
        self::assertTrue($updated->timeZoneConfigured());
        self::assertSame(1, $updated->lockVersion());

        self::assertFalse($repository->transactional(fn (): bool =>
            $repository->update(
                $actor,
                'No debe persistir',
                WebAdminTimeZone::utc(),
                0,
                $now
            )
        ));
        self::assertSame(
            'Nombre vivo',
            $repository->liveByPublicId($user)?->displayName()
        );

        $pdo->prepare('DELETE FROM wa_user_roles WHERE user_id = ?')
            ->execute([$userId]);
        $pdo->prepare(
            'INSERT INTO wa_user_roles (user_id, role_id, source) '
            . "SELECT ?, id, 'manual' FROM wa_roles WHERE code = 'editor'"
        )->execute([$userId]);
        self::assertSame(
            'Editor',
            $repository->liveByPublicId($user)?->roleLabel(),
            'The role projection is live and never an editorial snapshot.'
        );
    }

    public function testInvalidIanaZoneIsRejectedServerSide(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WebAdminTimeZone::fromIana('Europe/Not_A_Real_Zone');
    }
}
