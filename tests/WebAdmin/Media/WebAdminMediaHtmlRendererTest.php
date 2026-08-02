<?php

declare(strict_types=1);

namespace Tests\WebAdmin\Media;

use App\Core\WebAdmin\Media\Http\WebAdminMediaHtmlRenderer;
use App\Core\WebAdmin\Media\MediaAssetPage;
use PHPUnit\Framework\TestCase;

final class WebAdminMediaHtmlRendererTest extends TestCase
{
    public function testIndexKeepsH1H2H3HierarchyAndEscapesAssetData(): void
    {
        $html = (new WebAdminMediaHtmlRenderer())->index(
            '/admin',
            'csrf-secret',
            new MediaAssetPage([[
                'public_id' => '12345678-1234-4234-8234-123456789abc',
                'label' => '<Portada & principal>',
                'source_width' => 1600,
                'source_height' => 900,
                'created_at' => '2026-08-02T00:00:00+00:00',
                'thumbnail_width' => 480,
            ]], 1, false),
            true
        );

        self::assertSame(1, substr_count($html, '<h1'));
        self::assertSame(2, substr_count($html, '<h2'));
        self::assertSame(1, substr_count($html, '<h3'));
        self::assertMatchesRegularExpression(
            '/<h1[^>]*>.*?<section[^>]*><h2[^>]*>.*?<ul>'
                . '.*?<li><article>.*?<h3>/s',
            $html
        );
        self::assertStringContainsString(
            '<h3>&lt;Portada &amp; principal&gt;</h3>',
            $html
        );
        self::assertStringNotContainsString(
            '<h2>&lt;Portada &amp; principal&gt;</h2>',
            $html
        );
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertStringContainsString('accept="image/jpeg,image/png,image/webp"', $html);
        self::assertStringContainsString('value="csrf-secret"', $html);
    }

    public function testUploadFormIsOmittedWithoutUploadCapability(): void
    {
        $html = (new WebAdminMediaHtmlRenderer())->index(
            '/gestion',
            'csrf-secret',
            new MediaAssetPage([], 1, false),
            false
        );

        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('csrf-secret', $html);
        self::assertStringContainsString('No hay im&aacute;genes', $html);
    }
}
