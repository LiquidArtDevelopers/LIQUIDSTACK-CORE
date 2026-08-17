<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\StructuredContent\Media\PdoBlogMediaUsageProvider;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoBlogMediaUsageProviderTest extends TestCase
{
    public function testDraftAndRevisionReferencesBothProtectAnAsset(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite es necesario.');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        foreach (['content_media', 'revision_media'] as $table) {
            $pdo->exec(
                'CREATE TABLE ls_blog_' . $table
                . ' (media_asset_public_id TEXT NOT NULL)'
            );
        }
        $draft = '10000000-0000-4000-8000-000000000001';
        $revision = '10000000-0000-4000-8000-000000000002';
        $unused = '10000000-0000-4000-8000-000000000003';
        $pdo->exec(
            "INSERT INTO ls_blog_content_media VALUES ('" . $draft . "')"
        );
        $pdo->exec(
            "INSERT INTO ls_blog_revision_media VALUES ('" . $revision . "')"
        );

        $used = (new PdoBlogMediaUsageProvider(
            $pdo,
            MigrationScope::forTablePrefix('blog', 'ls_blog_')
        ))->usedPublicIds([$draft, $revision, $unused]);

        sort($used, SORT_STRING);
        self::assertSame([$draft, $revision], $used);
    }
}
