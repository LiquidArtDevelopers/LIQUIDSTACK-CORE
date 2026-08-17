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
                'usage_status' => 'used',
                'variants' => [
                    ['width' => 480, 'height' => 270, 'bytes' => 12_345],
                    ['width' => 1600, 'height' => 900, 'bytes' => 180_000],
                ],
            ]], 1, false),
            true
        );

        self::assertSame(1, substr_count($html, '<h1'));
        self::assertSame(2, substr_count($html, '<h2'));
        self::assertSame(1, substr_count($html, '<h3'));
        self::assertMatchesRegularExpression(
            '/<h1[^>]*>.*?<section[^>]*><h2[^>]*>.*?<ul[^>]*>'
                . '.*?<li[^>]*><article>.*?<h3>/s',
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
        self::assertStringContainsString(
            'accept="image/jpeg,image/png,image/webp,image/avif"',
            $html
        );
        self::assertStringContainsString('JPEG, PNG, WebP o AVIF', $html);
        self::assertStringContainsString(
            '<button class="webadminAction webadminAction--primary" '
                . 'type="submit" data-webadmin-media-submit>',
            $html
        );
        self::assertStringContainsString('Dimensiones disponibles', $html);
        self::assertStringContainsString('480 &times; 270 px', $html);
        self::assertStringContainsString('(12,1 KB)', $html);
        self::assertStringContainsString('Estado de uso', $html);
        self::assertStringContainsString(
            'webadminMedia__usage--used">Usada</span>',
            $html
        );
        self::assertStringContainsString(
            'el original temporal no se conservar&aacute;',
            $html
        );
        self::assertStringContainsString('value="csrf-secret"', $html);
        self::assertMatchesRegularExpression(
            '/name="idempotency_key" value="[0-9a-f-]{36}"/',
            $html
        );
        foreach ([
            'data-webadmin-media-upload',
            'data-webadmin-media-file',
            'data-webadmin-media-file-name',
            'Ning&uacute;n archivo seleccionado',
            'data-webadmin-media-submit',
            'data-webadmin-loader hidden aria-hidden="true"',
        ] as $contract) {
            self::assertStringContainsString($contract, $html);
        }
        self::assertStringNotContainsString(
            'Volver a la gesti&oacute;n web',
            $html
        );
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

    public function testAvifIsNotOfferedUntilItsSchemaMigrationIsReady(): void
    {
        $html = (new WebAdminMediaHtmlRenderer())->index(
            '/admin',
            'csrf-secret',
            new MediaAssetPage([], 1, false),
            true,
            null,
            false
        );

        self::assertStringContainsString(
            'accept="image/jpeg,image/png,image/webp"',
            $html
        );
        self::assertStringNotContainsString('image/avif', $html);
        self::assertStringContainsString(
            'entrada AVIF se habilitar&aacute;',
            $html
        );
    }

    public function testDeleteIsOfferedOnlyForACompleteUnusedProjection(): void
    {
        $version = str_repeat('a', 64);
        $items = [];
        foreach ([
            ['12345678-1234-4234-8234-123456789abc', 'unused', $version],
            ['22345678-1234-4234-8234-123456789abc', 'used', $version],
            ['32345678-1234-4234-8234-123456789abc', 'unknown', $version],
            ['42345678-1234-4234-8234-123456789abc', 'unused', null],
        ] as [$publicId, $usage, $deleteVersion]) {
            $items[] = [
                'public_id' => $publicId,
                'label' => 'Imagen ' . $usage,
                'source_width' => 1600,
                'source_height' => 900,
                'created_at' => '2026-08-02T00:00:00+00:00',
                'thumbnail_width' => 480,
                'usage_status' => $usage,
                'delete_version' => $deleteVersion,
                'variants' => [
                    ['width' => 480, 'height' => 270, 'bytes' => 12_345],
                ],
            ];
        }

        $html = (new WebAdminMediaHtmlRenderer())->index(
            '/admin',
            'csrf-secret',
            new MediaAssetPage($items, 1, false),
            false,
            null,
            true,
            true
        );

        self::assertSame(
            1,
            substr_count($html, 'data-webadmin-media-delete-form')
        );
        self::assertStringContainsString(
            'name="asset" value="12345678-1234-4234-8234-123456789abc"',
            $html
        );
        self::assertStringNotContainsString(
            'name="asset" value="22345678-1234-4234-8234-123456789abc"',
            $html
        );
        self::assertStringContainsString(
            'data-webadmin-media-delete-dialog',
            $html
        );
        self::assertStringContainsString(
            'class="webadminMedia__deleteDialogActions webadminActionGroup"',
            $html
        );
        self::assertStringContainsString(
            '<button class="webadminAction webadminAction--secondary" '
                . 'type="button" data-webadmin-media-delete-cancel>',
            $html
        );
        self::assertStringContainsString(
            '<button class="webadminAction webadminAction--danger" '
                . 'type="button" data-webadmin-media-delete-confirm>',
            $html
        );
        self::assertStringContainsString(
            '<button class="webadminAction webadminAction--danger '
                . 'webadminAction--compact" type="submit" '
                . 'data-webadmin-media-delete-open>',
            $html
        );
        self::assertStringContainsString('aria-labelledby="media-delete-title"', $html);
        self::assertStringContainsString('aria-live="polite" tabindex="-1"', $html);

        $withoutCapability = (new WebAdminMediaHtmlRenderer())->index(
            '/admin',
            'csrf-secret',
            new MediaAssetPage([$items[0]], 1, false),
            false
        );
        self::assertStringNotContainsString(
            'data-webadmin-media-delete-form',
            $withoutCapability
        );
        self::assertStringNotContainsString(
            'data-webadmin-media-delete-dialog',
            $withoutCapability
        );
    }
}
