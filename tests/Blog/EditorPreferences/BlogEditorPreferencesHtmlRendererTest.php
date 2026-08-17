<?php

declare(strict_types=1);

namespace Tests\Blog\EditorPreferences;

use App\Core\Blog\EditorPreferences\BlogEditorPreferences;
use App\Core\Blog\EditorPreferences\BlogEditorPreferencesState;
use App\Core\Blog\EditorPreferences\Http\BlogEditorPreferencesHtmlRenderer;
use App\Core\WebAdmin\Http\WebAdminPageAssets;
use App\Core\WebAdmin\Http\WebAdminShellContext;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class BlogEditorPreferencesHtmlRendererTest extends TestCase
{
    public function testRendersFiveClosedHeadingLevelsAndVisualPresetCards(): void
    {
        $html = (new BlogEditorPreferencesHtmlRenderer())->render(
            '/admin/blog',
            'csrf-value',
            BlogEditorPreferencesState::fallback(
                BlogEditorPreferences::defaults()
            ),
            $this->shell()
        );
        $xpath = $this->xpath($html);

        self::assertSame(1, $xpath->query('//main')->length);
        self::assertSame(1, $xpath->query('//main//h1')->length);
        self::assertSame(
            5,
            $xpath->query('//fieldset[contains(@class, "blogEditorPreferences__level")]')->length
        );
        self::assertSame(
            15,
            $xpath->query('//input[contains(@name, "_preset")]')->length
        );
        foreach (['h2', 'h3', 'h4', 'h5', 'h6'] as $level) {
            foreach ([
                'preset' => 3,
                'font_size' => 1,
                'font_weight' => 1,
                'text_color' => 7,
                'text_align' => 4,
            ] as $field => $count) {
                self::assertSame(
                    $count,
                    $xpath->query('//*[@name="' . $level . '_'
                        . $field . '"]')->length,
                    $level . '_' . $field
                );
            }
            foreach (['color04', 'color05'] as $color) {
                self::assertSame(
                    1,
                    $xpath->query('//input[@name="' . $level
                        . '_text_color" and @value="' . $color
                        . '"]/following-sibling::span[@data-color="'
                        . $color . '"]')->length,
                    $level . '_' . $color
                );
            }
        }
        self::assertSame(
            ['small' => 'S', 'default' => 'M', 'large' => 'L', 'xlarge' => 'XL'],
            $this->selectOptions($xpath, 'h2_font_size')
        );
        self::assertSame(
            '0',
            $xpath->evaluate('string(//input[@name="lock_version"]/@value)')
        );
        self::assertStringContainsString(
            'blogEditor__headingPreset--moduleH2Type01',
            $html
        );
        self::assertStringContainsString(
            'blogEditor__headingPreset--moduleH2Type02',
            $html
        );
        self::assertStringContainsString(
            '/assets/modules/blog/blog-admin.css',
            $html
        );
        self::assertSame(
            1,
            $xpath->query('//footer[contains(concat(" ", normalize-space(@class), " "), '
                . '" webadminActionGroup ")]')->length
        );
        self::assertSame(
            1,
            $xpath->query('//button[@type="submit" and contains(concat(" ", '
                . 'normalize-space(@class), " "), " webadminAction ") and '
                . 'contains(concat(" ", normalize-space(@class), " "), '
                . '" webadminAction--primary ")]')->length
        );
        self::assertSame(
            0,
            $xpath->query('//a[contains(concat(" ", normalize-space(@class), '
                . '" "), " webadminAction ")]')->length
        );
    }

    public function testEscapesSecretsAndPersistsSelectedPresentation(): void
    {
        $preferences = new BlogEditorPreferences([
            'h2' => $this->style('accent-block', 'xlarge', 'bold', 'color05', 'center'),
            'h3' => $this->style(),
            'h4' => $this->style(),
            'h5' => $this->style(),
            'h6' => $this->style(),
        ]);
        $state = new BlogEditorPreferencesState(
            $preferences,
            4,
            true,
            new \DateTimeImmutable('2026-08-05T10:00:00+00:00')
        );
        $html = (new BlogEditorPreferencesHtmlRenderer())->render(
            '/admin/blog',
            'csrf-<value>',
            $state,
            $this->shell()
        );
        $xpath = $this->xpath($html);

        self::assertSame(
            'csrf-<value>',
            $xpath->evaluate('string(//form[contains(@class, '
                . '"blogEditorPreferences__form")]'
                . '//input[@name="csrf"]/@value)')
        );
        self::assertStringNotContainsString('value="csrf-<value>"', $html);
        self::assertSame(
            '4',
            $xpath->evaluate('string(//input[@name="lock_version"]/@value)')
        );
        self::assertSame(
            1,
            $xpath->query('//input[@name="h2_preset" and '
                . '@value="accent-block" and @checked]')->length
        );
        self::assertSame(
            1,
            $xpath->query('//select[@name="h2_font_size"]/'
                . 'option[@value="xlarge" and @selected]')->length
        );
        self::assertSame(
            1,
            $xpath->query('//input[@name="h2_text_color" and '
                . '@value="color05" and @checked]')->length
        );
    }

    public function testCssKeepsFunctionalGroupingWithoutLateralAccents(): void
    {
        $css = (string) file_get_contents(
            dirname(__DIR__, 3)
                . '/modules/blog/published/assets/blog-admin.css'
        );
        $start = strpos($css, '.webadmin .blogEditorPreferences {');
        self::assertNotFalse($start);
        $end = strpos($css, '.webadmin .webadmin-srOnly {', (int) $start);
        self::assertNotFalse($end);
        $preferencesCss = substr(
            $css,
            (int) $start,
            (int) $end - (int) $start
        );

        self::assertStringContainsString(
            '.blogEditorPreferences__preset:has(input:checked)',
            $preferencesCss
        );
        self::assertStringContainsString(
            "data-color='color03'",
            $preferencesCss
        );
        self::assertStringNotContainsString('border-left', $preferencesCss);
        self::assertStringNotContainsString(
            'border-inline-start',
            $preferencesCss
        );
    }

    /** @return array<string, string> */
    private function style(
        string $preset = 'default',
        string $size = 'default',
        string $weight = 'default',
        string $color = 'default',
        string $align = 'start'
    ): array {
        return [
            'preset' => $preset,
            'font_size' => $size,
            'font_weight' => $weight,
            'text_color' => $color,
            'text_align' => $align,
        ];
    }

    private function shell(): WebAdminShellContext
    {
        return new WebAdminShellContext(
            basePath: '/admin',
            logoutCsrf: 'logout-csrf',
            activePath: '/blog/settings/presentation',
            assets: new WebAdminPageAssets([
                '/assets/modules/blog/blog-admin.css',
            ])
        );
    }

    /** @return array<string, string> */
    private function selectOptions(DOMXPath $xpath, string $name): array
    {
        $options = [];
        foreach ($xpath->query('//select[@name="' . $name . '"]/option') as $option) {
            $options[$option->getAttribute('value')] = trim($option->textContent);
        }

        return $options;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML($html));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }
}
