<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\StructuredContent\Media\PdoBlogEditorImageResolver;
use App\Core\Blog\StructuredContent\Rendering\BlogRenderingException;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoBlogEditorImageResolverTest extends TestCase
{
    private const ASSET = '10000000-0000-4000-8000-000000000001';

    public function testBuildsAuthenticatedAvifCandidatesWithoutStorageKeys(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO ls_webadmin_media_assets (id, public_id) "
            . "VALUES (1, '" . self::ASSET . "')");
        $pdo->exec("INSERT INTO ls_webadmin_media_variants "
            . "(asset_id, width, height, mime) VALUES "
            . "(1, 480, 270, 'image/avif'), "
            . "(1, 900, 506, 'image/avif')");

        $image = (new PdoBlogEditorImageResolver(
            $pdo,
            MigrationScope::forTablePrefix('webadmin', 'ls_webadmin_'),
            '/admin'
        ))->resolve(self::ASSET);

        self::assertNotNull($image);
        self::assertSame(900, $image->width());
        self::assertSame(506, $image->height());
        self::assertSame([480, 900], array_map(
            static fn ($candidate): int => $candidate->width(),
            $image->candidates()
        ));
        self::assertSame(
            '/admin/media/file?asset=' . self::ASSET . '&width=900',
            $image->sourceUrl()
        );
        self::assertStringNotContainsString('storage', $image->sourceUrl());
    }

    public function testUnknownOrMalformedAssetDoesNotEnumerateCatalog(): void
    {
        $resolver = new PdoBlogEditorImageResolver(
            $this->pdo(),
            MigrationScope::forTablePrefix('webadmin', 'ls_webadmin_'),
            '/admin'
        );

        self::assertNull($resolver->resolve('../asset'));
        self::assertNull($resolver->resolve(self::ASSET));
    }

    public function testRejectsUnsafePrivateBasePath(): void
    {
        foreach ([
            '/admin/../private',
            '/admin/%2e%2e/private',
            '/admin/%252e%252e/private',
            '/admin/%2fprivate',
            '/admin/%255cprivate',
            '/admin/%3fsecret',
            '/admin/%2523secret',
            '/admin/%20private',
            '/admin/%invalid',
        ] as $path) {
            try {
                new PdoBlogEditorImageResolver(
                    $this->pdo(),
                    MigrationScope::forTablePrefix(
                        'webadmin',
                        'ls_webadmin_'
                    ),
                    $path
                );
                self::fail('Unsafe private path was accepted: ' . $path);
            } catch (BlogRenderingException $exception) {
                self::assertSame(
                    BlogRenderingException::MEDIA_UNAVAILABLE,
                    $exception->issueCode()
                );
            }
        }
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE ls_webadmin_media_assets ('
            . 'id INTEGER PRIMARY KEY, public_id TEXT NOT NULL UNIQUE)');
        $pdo->exec('CREATE TABLE ls_webadmin_media_variants ('
            . 'asset_id INTEGER NOT NULL, width INTEGER NOT NULL, '
            . 'height INTEGER NOT NULL, mime TEXT NOT NULL)');

        return $pdo;
    }
}
