<?php

declare(strict_types=1);

namespace Tests\Blog;

use PHPUnit\Framework\TestCase;

final class BlogLanguageNoJsCssContractTest extends TestCase
{
    public function testSsrLanguageFlowKeepsModalCompositionWithoutJavaScript(): void
    {
        $css = file_get_contents(
            dirname(__DIR__, 2)
                . '/modules/blog/published/assets/blog-admin.css'
        );
        self::assertIsString($css);

        foreach ([
            '.blogAdminPage__languageFlow[open] {',
            'position: fixed;',
            'inset: 0;',
            'place-items: center;',
            '> .blogAdminPage__languagePanel {',
            'width: min(100%, 44rem);',
            'max-height: calc(100dvh - 2rem);',
            '[data-blog-language-form] {',
            '.blogAdminPage__languageActions {',
        ] as $contract) {
            self::assertStringContainsString($contract, $css);
        }
    }
}
