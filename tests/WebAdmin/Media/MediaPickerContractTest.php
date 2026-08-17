<?php

declare(strict_types=1);

namespace Tests\WebAdmin\Media;

use App\Core\Http\Request;
use App\Core\WebAdmin\Media\Http\WebAdminMediaHttpRequestPolicy;
use App\Core\WebAdmin\Media\Http\WebAdminMediaPickerHtmlRenderer;
use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\MediaPickerItem;
use App\Core\WebAdmin\Media\MediaPickerPage;
use App\Core\WebAdmin\Media\MediaPickerQuery;
use App\Core\WebAdmin\Media\MediaPickerReference;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MediaPickerContractTest extends TestCase
{
    private const ASSET = '12345678-1234-4234-8234-123456789abc';

    public function testTypedQueryNormalizesUnicodeAndStaysBounded(): void
    {
        $query = new MediaPickerQuery('  Árbol    rojo  ', 2, 48);

        self::assertSame('Árbol rojo', $query->search());
        self::assertSame(2, $query->page());
        self::assertSame(48, $query->pageSize());
        self::assertSame(48, $query->offset());
        self::assertSame(49, $query->fetchLimit());

        $this->expectException(MediaException::class);
        new MediaPickerQuery(str_repeat('ñ', 121));
    }

    public function testPayloadAndCardsExposeOnlyTheSafeMediaProjection(): void
    {
        $query = new MediaPickerQuery(null, 1, 24);
        $page = new MediaPickerPage($query, [$this->item()], false);
        $payload = (new WebAdminMediaPickerHtmlRenderer())->pagePayload(
            '/admin',
            $page,
            false,
            true
        );

        self::assertSame(false, $payload['permissions']['upload']);
        self::assertSame([
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/avif',
        ], $payload['upload']['accepted_mimes']);
        self::assertSame(self::ASSET, $payload['items'][0]['public_id']);
        self::assertSame(
            '/admin/media/file?asset=' . self::ASSET . '&width=480',
            $payload['items'][0]['thumbnail']['url']
        );
        self::assertSame(1800, $payload['items'][0]['source_width']);
        self::assertCount(2, $payload['items'][0]['variants']);
        self::assertSame(
            '2030-01-01T10:00:00+00:00',
            $payload['items'][0]['created_at']
        );
        self::assertStringContainsString(
            'Anchos: 480, 1800 px',
            $payload['catalog_html']
        );
        self::assertStringContainsString(
            '<time datetime="2030-01-01T10:00:00+00:00">01/01/2030</time>',
            $payload['catalog_html']
        );
        foreach ([
            'asset_id',
            'storage_key',
            'sha256',
            'delete_version',
            'created_by_user_id',
        ] as $internal) {
            self::assertArrayNotHasKey($internal, $payload['items'][0]);
            self::assertStringNotContainsString(
                $internal,
                $payload['catalog_html']
            );
        }
        self::assertStringContainsString(
            'data-webadmin-media-picker-item',
            $payload['catalog_html']
        );
    }

    public function testViewOnlyDialogKeepsReferencedFallbackWithoutUpload(): void
    {
        $thumbnail = '/admin/media/file?asset=' . self::ASSET . '&width=480';
        $html = (new WebAdminMediaPickerHtmlRenderer())->dialog(
            '/admin',
            'csrf-safe-token',
            'blog-editor-form',
            [new MediaPickerReference(self::ASSET, 'Portada antigua', $thumbnail)],
            false
        );

        self::assertStringContainsString('data-webadmin-media-picker', $html);
        self::assertStringContainsString(
            'data-webadmin-media-picker-catalog="/admin/media/catalog"',
            $html
        );
        self::assertStringContainsString(
            'value="' . self::ASSET . '"',
            $html
        );
        self::assertStringContainsString('Portada antigua', $html);
        self::assertStringContainsString(
            'data-webadmin-media-picker-owner="blog-editor-form"',
            $html
        );
        self::assertStringContainsString(
            'aria-labelledby="blog-editor-form-media-picker-title"',
            $html
        );
        self::assertStringContainsString(
            'id="blog-editor-form-media-picker-fallback-select"',
            $html
        );
        self::assertStringContainsString(
            'data-webadmin-media-picker-select',
            $html
        );
        self::assertStringContainsString(
            'data-webadmin-media-picker-confirm',
            $html
        );
        self::assertStringNotContainsString(
            'data-webadmin-media-picker-upload',
            $html
        );
        foreach ([
            'data-blog-media-use',
            'data-blog-media-close',
            'data-blog-media-upload',
        ] as $privateHook) {
            self::assertStringNotContainsString($privateHook, $html);
        }
        self::assertSame(
            'liquidstack:webadmin-media-picker:selected',
            WebAdminMediaPickerHtmlRenderer::SELECTION_EVENT
        );
    }

    public function testEachPickerOwnerProducesDistinctAccessibleIdsAndUploadHooks(): void
    {
        $renderer = new WebAdminMediaPickerHtmlRenderer();
        $first = $renderer->dialog(
            '/admin',
            'csrf-safe-token',
            'blog-editor-form',
            [],
            true
        );
        $second = $renderer->dialog(
            '/admin',
            'csrf-safe-token',
            'profile-editor-form',
            [],
            true
        );

        self::assertStringContainsString(
            'id="blog-editor-form-media-picker-title"',
            $first
        );
        self::assertStringContainsString(
            'id="profile-editor-form-media-picker-title"',
            $second
        );
        self::assertStringContainsString(
            'id="profile-editor-form-media-picker-fallback-select"',
            $second
        );
        self::assertStringNotContainsString(
            'blog-editor-form-media-picker-fallback-select',
            $second
        );
        foreach ([
            'data-webadmin-media-picker-upload',
            'data-webadmin-media-picker-progress',
            'data-webadmin-media-picker-upload-status',
        ] as $hook) {
            self::assertStringContainsString($hook, $first);
        }
    }

    public function testPrivateCatalogTransportContractIsExact(): void
    {
        $policy = new WebAdminMediaHttpRequestPolicy();
        $valid = Request::fromInput(
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/admin/media/catalog?q=%C3%81rbol&page=1&per_page=24',
            ],
            ['q' => 'Árbol', 'page' => '1', 'per_page' => '24'],
            [],
            [],
            [
                'Accept' => 'application/json',
                'X-LiquidStack-Media-Picker' => 'async',
            ]
        );
        self::assertTrue($policy->acceptsCatalog($valid));

        $unknown = Request::fromInput(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/media/catalog'],
            ['storage_key' => 'secret'],
            [],
            [],
            [
                'Accept' => 'application/json',
                'X-LiquidStack-Media-Picker' => 'async',
            ]
        );
        self::assertFalse($policy->acceptsCatalog($unknown));

        $missingAsyncContract = Request::fromInput(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/media/catalog']
        );
        self::assertFalse($policy->acceptsCatalog($missingAsyncContract));
    }

    private function item(): MediaPickerItem
    {
        return new MediaPickerItem(
            self::ASSET,
            'Portada',
            1800,
            1200,
            new DateTimeImmutable('2030-01-01 10:00:00 UTC'),
            480,
            [
                ['width' => 480, 'height' => 320, 'bytes' => 100],
                ['width' => 1800, 'height' => 1200, 'bytes' => 900],
            ]
        );
    }
}
