<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use PHPUnit\Framework\TestCase;

final class BlogSeparatorEditorAssetContractTest extends TestCase
{
    public function testEditorOffersAControlledSeparatorAndSpacingPresets(): void
    {
        $root = dirname(__DIR__, 3);
        $javascript = (string) file_get_contents(
            $root . '/modules/blog/published/assets/blog-editor.js'
        );
        $adminCss = (string) file_get_contents(
            $root . '/modules/blog/published/assets/blog-admin.css'
        );
        $publicCss = (string) file_get_contents(
            $root . '/modules/blog/published/assets/blog-public.css'
        );

        foreach ([
            "separator: 'Separador'",
            "var SEPARATOR_LINE_STYLES = ['solid', 'dashed', 'dotted', 'double']",
            "var SEPARATOR_THICKNESSES = ['thin', 'medium', 'thick']",
            "var PRESENTATION_SPACINGS = ['none', 's', 'm', 'l', 'xl']",
            "'separator-style'",
            "'separator-thickness'",
            "'separator-color'",
            "'spacing-before'",
            "'spacing-after'",
        ] as $contract) {
            self::assertStringContainsString($contract, $javascript);
        }
        self::assertStringContainsString(
            '.webadmin .blogEditor__previewSeparator',
            $adminCss
        );
        self::assertStringContainsString(
            '.blogDocument__separator--double',
            $publicCss
        );
        self::assertStringContainsString(
            '.blogDocument__module--spacing-xl-after',
            $publicCss
        );
    }
}
