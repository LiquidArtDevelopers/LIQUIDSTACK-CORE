<?php

declare(strict_types=1);

namespace Tests\Commerce;

use App\Core\Commerce\CommerceInquiryAbuseGuard;
use App\Core\Commerce\InquiryContact;
use App\Core\Commerce\Persistence\CommerceTableNames;
use App\Core\Commerce\Persistence\PdoCommerceInquiryRateLimitRepository;
use App\Core\Modules\Commerce\CommerceMigrationProvider;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\WebAdmin\Security\SecurityKey;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class CommerceInquiryAbuseGuardTest extends TestCase
{
    private PDO $pdo;
    private CommerceInquiryAbuseGuard $guard;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $scope = MigrationScope::forTablePrefix('commerce', 'ls_commerce_');
        $migrations = iterator_to_array(
            CommerceMigrationProvider::migrations(),
            false
        );
        foreach (array_slice($migrations, 0, 2) as $migration) {
            foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
                $this->pdo->exec($sql);
            }
        }
        $this->guard = new CommerceInquiryAbuseGuard(
            new PdoCommerceInquiryRateLimitRepository(
                $this->pdo,
                CommerceTableNames::fromPdo($this->pdo, 'ls_commerce_')
            ),
            SecurityKey::fromRawBytes(str_repeat('k', 32))
        );
    }

    public function testEmailAndIpAreRateLimitedWithoutPersistingPii(): void
    {
        $now = new DateTimeImmutable(
            '2032-04-05 10:00:00.000000',
            new DateTimeZone('UTC')
        );
        $contact = new InquiryContact('Ada', 'ada@example.test');

        self::assertTrue($this->guard->allows('192.0.2.10', $contact, $now));
        self::assertTrue($this->guard->allows('192.0.2.10', $contact, $now));
        self::assertTrue($this->guard->allows('192.0.2.10', $contact, $now));
        self::assertFalse($this->guard->allows('192.0.2.10', $contact, $now));

        $stored = (string) $this->pdo->query(
            'SELECT group_concat(action || subject_hash) '
                . 'FROM ls_commerce_inquiry_rate_limits'
        )->fetchColumn();
        self::assertStringNotContainsString('ada@example.test', $stored);
        self::assertStringNotContainsString('192.0.2.10', $stored);
        self::assertSame(2, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_commerce_inquiry_rate_limits'
        )->fetchColumn());
    }

    public function testFixedWindowResetsAfterExpiry(): void
    {
        $now = new DateTimeImmutable(
            '2032-04-05 10:00:00.000000',
            new DateTimeZone('UTC')
        );
        $contact = new InquiryContact('Ada', 'ada@example.test');
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            self::assertTrue($this->guard->allows(null, $contact, $now));
        }
        self::assertFalse($this->guard->allows(null, $contact, $now));
        self::assertTrue($this->guard->allows(
            null,
            $contact,
            $now->modify('+3601 seconds')
        ));
    }
}
