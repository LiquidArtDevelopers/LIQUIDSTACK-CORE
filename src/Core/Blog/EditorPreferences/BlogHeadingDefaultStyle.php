<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorPreferences;

use App\Core\Blog\StructuredContent\Presentation\BlogHeadingPresetCatalog;

/** Complete presentation default for one semantic heading level. */
final class BlogHeadingDefaultStyle
{
    private const FONT_SIZES = ['default', 'small', 'large', 'xlarge'];
    private const FONT_WEIGHTS = [
        'default', 'regular', 'medium', 'semibold', 'bold',
    ];
    private const TEXT_COLORS = [
        'default', 'color00', 'color01', 'color02', 'color03', 'color04',
        'color05',
    ];
    private const TEXT_ALIGNS = ['start', 'center', 'end', 'justify'];

    /**
     * @param array<string, mixed> $style
     */
    public function __construct(
        array $style,
        ?BlogHeadingPresetCatalog $catalog = null
    ) {
        $catalog ??= new BlogHeadingPresetCatalog();
        $keys = array_keys($style);
        sort($keys, SORT_STRING);
        $expectedKeys = [
            'preset',
            'font_size',
            'font_weight',
            'text_color',
            'text_align',
        ];
        sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys) {
            throw new BlogEditorPreferencesException(
                BlogEditorPreferencesException::INVALID_INPUT
            );
        }
        foreach ($style as $value) {
            if (!is_string($value)) {
                throw new BlogEditorPreferencesException(
                    BlogEditorPreferencesException::INVALID_INPUT
                );
            }
        }
        if (
            !$catalog->isAllowed($style['preset'])
            || !in_array($style['font_size'], self::FONT_SIZES, true)
            || !in_array($style['font_weight'], self::FONT_WEIGHTS, true)
            || !in_array($style['text_color'], self::TEXT_COLORS, true)
            || !in_array($style['text_align'], self::TEXT_ALIGNS, true)
        ) {
            throw new BlogEditorPreferencesException(
                BlogEditorPreferencesException::INVALID_INPUT
            );
        }

        $this->preset = $style['preset'];
        $this->fontSize = $style['font_size'];
        $this->fontWeight = $style['font_weight'];
        $this->textColor = $style['text_color'];
        $this->textAlign = $style['text_align'];
    }

    private readonly string $preset;
    private readonly string $fontSize;
    private readonly string $fontWeight;
    private readonly string $textColor;
    private readonly string $textAlign;

    public static function defaults(
        ?BlogHeadingPresetCatalog $catalog = null
    ): self {
        $catalog ??= new BlogHeadingPresetCatalog();

        return new self([
            'preset' => $catalog->defaultKey(),
            'font_size' => 'default',
            'font_weight' => 'default',
            'text_color' => 'default',
            'text_align' => 'start',
        ], $catalog);
    }

    public function preset(): string
    {
        return $this->preset;
    }

    public function fontSize(): string
    {
        return $this->fontSize;
    }

    public function fontWeight(): string
    {
        return $this->fontWeight;
    }

    public function textColor(): string
    {
        return $this->textColor;
    }

    public function textAlign(): string
    {
        return $this->textAlign;
    }

    /**
     * @return array{
     *   preset: string,
     *   font_size: string,
     *   font_weight: string,
     *   text_color: string,
     *   text_align: string
     * }
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'font_size' => $this->fontSize,
            'font_weight' => $this->fontWeight,
            'text_color' => $this->textColor,
            'text_align' => $this->textAlign,
        ];
    }
}
