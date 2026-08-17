<?php

declare(strict_types=1);

use App\Core\Blog\Categories\BlogReservedCategoryPolicy;
use App\Core\Blog\StructuredContent\Adoption\BlogUnifiedTextAdoptionException;
use App\Core\Blog\StructuredContent\Adoption\PdoBlogUnifiedTextAdoptionActorGate;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use PHPUnit\Framework\TestCase;

final class PdoBlogUnifiedTextAdoptionActorGateTest extends TestCase
{
    private const ACTOR = '11111111-1111-4111-8111-111111111111';
    private const POST = '22222222-2222-4222-8222-222222222222';

    public function testActiveOrdinaryVariantIsAuthorizedInsideTransaction(): void
    {
        [$pdo, $gate] = $this->fixture();

        $pdo->beginTransaction();
        try {
            self::assertSame(
                self::ACTOR,
                $gate->authorize($pdo, self::ACTOR, self::POST, 'es')
            );
        } finally {
            $pdo->rollBack();
        }
    }

    public function testTombstoneInsertedAfterPreflightIsRejectedInSaveGate(): void
    {
        [$pdo, $gate] = $this->fixture();
        $gate->assertEligible(self::ACTOR);
        $pdo->exec(
            'INSERT INTO blog_post_tombstones (post_localization_id) '
                . 'VALUES (20)'
        );

        $this->assertVariantRejected($pdo, $gate);
    }

    public function testDummyAssignedAfterPreflightIsRejectedInSaveGate(): void
    {
        [$pdo, $gate] = $this->fixture();
        $gate->assertEligible(self::ACTOR);
        $pdo->exec(
            "INSERT INTO blog_categories (id, public_id) VALUES (30, '"
                . BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID
                . "')"
        );
        $pdo->exec(
            'INSERT INTO blog_post_categories (post_id, category_id) '
                . 'VALUES (10, 30)'
        );

        $this->assertVariantRejected($pdo, $gate);
    }

    private function assertVariantRejected(
        PDO $pdo,
        PdoBlogUnifiedTextAdoptionActorGate $gate
    ): void {
        $pdo->beginTransaction();
        try {
            $gate->authorize($pdo, self::ACTOR, self::POST, 'es');
            self::fail('An excluded variant reached the save boundary.');
        } catch (BlogUnifiedTextAdoptionException $exception) {
            self::assertSame(
                'blog.unified_text_adoption.variant_not_eligible',
                $exception->issueCode()
            );
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /** @return array{PDO, PdoBlogUnifiedTextAdoptionActorGate} */
    private function fixture(): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE wa_users (id INTEGER PRIMARY KEY, public_id TEXT, '
                . 'status TEXT, activated_at TEXT, suspended_at TEXT)',
            'CREATE TABLE wa_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE wa_role_capabilities ('
                . 'role_id INTEGER, capability_id INTEGER)',
            'CREATE TABLE wa_capabilities ('
                . 'id INTEGER PRIMARY KEY, code TEXT)',
            'CREATE TABLE wa_user_capabilities ('
                . 'user_id INTEGER, capability_id INTEGER)',
            'CREATE TABLE blog_posts (id INTEGER PRIMARY KEY, public_id TEXT)',
            'CREATE TABLE blog_post_localizations ('
                . 'id INTEGER PRIMARY KEY, post_id INTEGER, locale TEXT)',
            'CREATE TABLE blog_post_tombstones ('
                . 'post_localization_id INTEGER PRIMARY KEY)',
            'CREATE TABLE blog_categories ('
                . 'id INTEGER PRIMARY KEY, public_id TEXT)',
            'CREATE TABLE blog_post_categories ('
                . 'post_id INTEGER, category_id INTEGER)',
        ] as $sql) {
            $pdo->exec($sql);
        }
        $actor = $pdo->prepare(
            'INSERT INTO wa_users '
                . '(id, public_id, status, activated_at, suspended_at) '
                . "VALUES (1, :actor, 'active', '2026-08-11', NULL)"
        );
        $actor->execute(['actor' => self::ACTOR]);
        $pdo->exec(
            "INSERT INTO wa_capabilities (id, code) VALUES "
                . "(1, 'blog.articles.edit'), (2, 'webadmin.media.view')"
        );
        $pdo->exec(
            'INSERT INTO wa_user_capabilities (user_id, capability_id) '
                . 'VALUES (1, 1), (1, 2)'
        );
        $post = $pdo->prepare(
            'INSERT INTO blog_posts (id, public_id) VALUES (10, :post)'
        );
        $post->execute(['post' => self::POST]);
        $pdo->exec(
            "INSERT INTO blog_post_localizations (id, post_id, locale) "
                . "VALUES (20, 10, 'es')"
        );

        return [
            $pdo,
            new PdoBlogUnifiedTextAdoptionActorGate(
                $pdo,
                WebAdminTableNames::fromPdo($pdo, 'wa_'),
                MigrationScope::forTablePrefix('blog', 'blog_')
            ),
        ];
    }
}
