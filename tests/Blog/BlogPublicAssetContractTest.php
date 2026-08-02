<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BlogPublicAssetContractTest extends TestCase
{
    public function testFallbackStylesheetIsNeutralResponsiveAndStandalone(): void
    {
        $path = dirname(__DIR__, 2)
            . '/modules/blog/published/assets/blog-public.css';
        $css = file_get_contents($path);
        self::assertIsString($css);

        foreach ([
            'color-scheme: light dark',
            'font-family: system-ui',
            'width: min(100%',
            '.blogDocument__image--cover',
            '.blogDocument__liteYoutube',
            'aspect-ratio: 16 / 9',
            '@media (max-width: 35rem)',
            '@media (prefers-reduced-motion: reduce)',
            'a:focus-visible',
        ] as $contract) {
            self::assertStringContainsString($contract, $css);
        }

        foreach ([
            '@import',
            'javascript:',
            'expression(',
            'http://',
            'https://',
            'url(',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $css);
        }
    }
}
