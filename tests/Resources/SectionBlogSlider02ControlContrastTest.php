<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SectionBlogSlider02ControlContrastTest extends TestCase
{
    public function testAutoplayIconInheritsTheButtonColorInEveryState(): void
    {
        $scss = (string) file_get_contents(
            dirname(__DIR__, 2)
            . '/modules/blog/resources/project/src/scss/resources/_sectionBlogSlider02.scss'
        );

        // Global theme rules may give spans a dark color independently of buttons.
        self::assertMatchesRegularExpression(
            '/\.sectionBlogSlider02-autoplay\s*\{\s*span\s*\{[^}]*color:\s*inherit;/s',
            $scss
        );
        self::assertMatchesRegularExpression(
            '/&:hover\s*\{\s*color:\s*c\.\$color00;\s*background:\s*c\.\$color02;/s',
            $scss
        );
        self::assertStringContainsString('solid currentColor', $scss);
    }
}
