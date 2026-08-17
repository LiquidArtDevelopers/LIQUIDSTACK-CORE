<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Hero01ContrastContractTest extends TestCase
{
    public function testContrastSurfaceIsAnExplicitOptInModifier(): void
    {
        $root = dirname(__DIR__, 2);
        $heroScss = (string) file_get_contents(
            $root . '/resources/scss/_hero01.scss'
        );
        $controller = (string) file_get_contents(
            $root . '/stubs/App/controllers/hero01.php'
        );
        $template = (string) file_get_contents(
            $root . '/stubs/App/templates/_hero01.html'
        );

        self::assertMatchesRegularExpression(
            '/&\.hero01--contrast-surface\s*\{'
            . '.*?\.hero01-content>\.moduleH1Type01\s*\{'
            . '.*?background-color:\s*c\.\$color01;'
            . '.*?backdrop-filter:\s*none;'
            . '.*?\.moduleH1Type01-header,'
            . '.*?\.moduleH1Type01-header\s+\*,'
            . '.*?>p,'
            . '.*?>p\s+\*\s*\{'
            . '.*?color:\s*c\.\$color00;'
            . '/s',
            $heroScss
        );
        self::assertStringContainsString(
            "(\$params['contrast_surface'] ?? false) === true",
            $controller
        );
        self::assertStringContainsString(
            '<header class="hero01{contrast-class}">',
            $template
        );
        self::assertStringNotContainsString(
            '.hero01 .hero01-content>.moduleH1Type01',
            preg_replace('/\s+/', ' ', $heroScss) ?? $heroScss
        );
    }

    public function testContrastOverrideStaysScopedToHero01(): void
    {
        $root = dirname(__DIR__, 2);
        $moduleScss = (string) file_get_contents(
            $root . '/resources/scss/_moduleH1Type01.scss'
        );

        self::assertStringContainsString(
            'background-color: c.$color00bis2;',
            $moduleScss
        );
        self::assertStringContainsString(
            'color: c.$color02;',
            $moduleScss
        );
        self::assertStringContainsString(
            'color: c.$color01;',
            $moduleScss
        );
    }
}
