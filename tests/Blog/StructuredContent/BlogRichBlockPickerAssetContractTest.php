<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use PHPUnit\Framework\TestCase;

final class BlogRichBlockPickerAssetContractTest extends TestCase
{
    private string $javascript;
    private string $stylesheet;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $this->javascript = (string) file_get_contents(
            $root . '/modules/blog/published/assets/blog-editor.js'
        );
        $this->stylesheet = (string) file_get_contents(
            $root . '/modules/blog/published/assets/blog-admin.css'
        );
    }

    public function testHeadingPickerOwnsItsMenuAndKeyboardInteraction(): void
    {
        $contracts = [
            'function richToolbarBlockTypePicker(',
            "trigger.setAttribute('aria-haspopup', 'listbox')",
            "menu.setAttribute('role', 'listbox')",
            'function richCloseBlockTypePickerOnPointerDown(',
            'toolbarButton instanceof HTMLButtonElement',
            'function richCloseBlockTypePickerOnFocusLeave(',
            "['ArrowDown', 'Home'].includes(event.key)",
            "['ArrowUp', 'End'].includes(event.key)",
            "event.key === 'Escape'",
            "['Enter', ' ', 'Spacebar'].includes(event.key)",
        ];
        self::assertSame([], array_values(array_filter(
            $contracts,
            fn (string $contract): bool => !str_contains(
                $this->javascript,
                $contract
            )
        )));

        self::assertStringContainsString(
            'position: absolute;',
            $this->cssRule('.webadmin .blogEditor__richBlockTypeMenu {')
        );
        $optionRule = $this->cssRule(
            '.webadmin .blogEditor__richBlockTypeOption {'
        );
        self::assertStringContainsString('min-block-size: 2.75rem;', $optionRule);
        self::assertStringContainsString('padding: 0.65rem 0.85rem;', $optionRule);

        $h2 = $this->headingSampleMetrics('h2');
        $h6 = $this->headingSampleMetrics('h6');
        self::assertGreaterThan($h6['size'], $h2['size']);
        self::assertGreaterThan($h6['weight'], $h2['weight']);
        self::assertStringContainsString(
            "shortLabel: 'P'",
            $this->javascript
        );
    }

    public function testEditorBottomSpaceIsSharedByVisualAndSourceLayers(): void
    {
        self::assertStringContainsString(
            '--ls-blog-rich-editor-bottom-space: clamp(4.5rem, 12vh, 7.5rem);',
            $this->stylesheet
        );
        $selectors = [
            '.webadmin .blogEditor__richCanvas,',
            '.webadmin .blogEditor__richSourceGutter',
            '.webadmin .blogEditor__richSourceEditor .blogEditor__richSource',
            '.webadmin .blogEditor__richSourceHighlight',
            '.webadmin .blogEditor__richSourceMeasure',
        ];
        self::assertSame([], array_values(array_filter(
            $selectors,
            fn (string $selector): bool => !str_contains(
                $this->cssRule($selector),
                'var(--ls-blog-rich-editor-bottom-space)'
            )
        )));
        self::assertGreaterThanOrEqual(
            7,
            substr_count(
                $this->stylesheet,
                'var(--ls-blog-rich-editor-bottom-space)'
            )
        );
    }

    /** @return array{size: float, weight: int} */
    private function headingSampleMetrics(string $level): array
    {
        $pattern = "/data-blog-rich-block-type-value='" . preg_quote(
            $level,
            '/'
        ) . "'\\]\\s+\\.blogEditor__richBlockTypeSample\\s*\\{"
            . "(?<body>[^}]*)\\}/s";
        if (
            preg_match($pattern, $this->stylesheet, $match) !== 1
            || preg_match(
                '/font-size:\s*([0-9.]+)rem;/',
                $match['body'],
                $size
            ) !== 1
            || preg_match(
                '/font-weight:\s*([0-9]+);/',
                $match['body'],
                $weight
            ) !== 1
        ) {
            self::fail('No se pudo leer la escala visual de ' . $level);
        }

        return ['size' => (float) $size[1], 'weight' => (int) $weight[1]];
    }

    private function cssRule(string $selector): string
    {
        $offset = strpos($this->stylesheet, $selector);
        if ($offset === false) {
            self::fail('No se encontró ' . $selector);
        }
        $start = strpos($this->stylesheet, '{', (int) $offset);
        $end = strpos($this->stylesheet, '}', (int) $start);
        if ($start === false || $end === false) {
            self::fail('No se pudo leer la regla ' . $selector);
        }

        return substr(
            $this->stylesheet,
            (int) $start + 1,
            (int) $end - (int) $start - 1
        );
    }
}
