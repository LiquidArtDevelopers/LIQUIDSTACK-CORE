<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorPreferences\Http;

use App\Core\Blog\EditorPreferences\BlogEditorPreferencesState;
use App\Core\Blog\EditorPreferences\BlogHeadingDefaultStyle;
use App\Core\Blog\StructuredContent\Presentation\BlogHeadingPresetCatalog;
use App\Core\WebAdmin\Http\WebAdminShellContext;
use App\Core\WebAdmin\Http\WebAdminShellRenderer;
use InvalidArgumentException;

/** Server-rendered, JavaScript-independent global heading preferences form. */
final class BlogEditorPreferencesHtmlRenderer
{
    /** @var list<string> */
    private const LEVELS = ['h2', 'h3', 'h4', 'h5', 'h6'];

    private readonly WebAdminShellRenderer $shellRenderer;

    public function __construct(
        private readonly BlogHeadingPresetCatalog $presetCatalog =
            new BlogHeadingPresetCatalog(),
        ?WebAdminShellRenderer $shellRenderer = null
    ) {
        $this->shellRenderer = $shellRenderer ?? new WebAdminShellRenderer();
    }

    public function render(
        string $blogBasePath,
        #[\SensitiveParameter] string $csrf,
        BlogEditorPreferencesState $state,
        WebAdminShellContext $shell
    ): string {
        if (
            preg_match('#\A/[a-z0-9][a-z0-9/_-]*\z#', $blogBasePath) !== 1
            || $csrf === ''
            || strlen($csrf) > 4096
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog editor preferences presentation.'
            );
        }

        $form = '<article class="blogEditorPreferences" '
            . 'aria-labelledby="blog-editor-preferences-title">'
            . '<header class="blogEditorPreferences__header">'
            . '<p class="blogEditorPreferences__eyebrow">Configuraci&oacute;n editorial</p>'
            . '<h1 id="blog-editor-preferences-title">Estilo de los t&iacute;tulos</h1>'
            . '<p>Elige la apariencia inicial de cada nivel. Se aplicar&aacute; '
            . 'a los t&iacute;tulos nuevos; los art&iacute;culos ya guardados conservan '
            . 'su dise&ntilde;o.</p></header>'
            . '<form class="blogEditorPreferences__form" method="post" action="'
            . $this->escape(rtrim($blogBasePath, '/') . '/settings/presentation')
            . '"><input type="hidden" name="csrf" value="'
            . $this->escape($csrf) . '"><input type="hidden" '
            . 'name="lock_version" value="' . $state->lockVersion() . '">';

        foreach (self::LEVELS as $level) {
            $form .= $this->headingFieldset(
                $level,
                $state->preferences()->headingDefault($level)
            );
        }

        $form .= '<footer class="blogEditorPreferences__actions '
            . 'webadminActionGroup">'
            . '<button class="webadminAction webadminAction--primary" '
            . 'type="submit">Guardar estilo editorial</button>'
            . '<a href="' . $this->escape($blogBasePath)
            . '">Volver a los art&iacute;culos</a></footer></form></article>';

        return $this->shellRenderer->render(
            'Estilo editorial del Blog',
            $form,
            $shell
        );
    }

    private function headingFieldset(
        string $level,
        BlogHeadingDefaultStyle $style
    ): string {
        $upper = strtoupper($level);
        $html = '<fieldset class="blogEditorPreferences__level">'
            . '<legend><span>' . $upper . '</span> '
            . $this->levelDescription($level) . '</legend>'
            . '<div class="blogEditorPreferences__presetGrid" '
            . 'role="radiogroup" aria-label="Dise&ntilde;o visual de '
            . $upper . '">';

        foreach ($this->presetCatalog->presets() as $preset) {
            $id = 'blog-style-' . $level . '-preset-' . $preset->token();
            $html .= '<label class="blogEditorPreferences__preset" for="'
                . $this->escape($id) . '"><input id="'
                . $this->escape($id) . '" type="radio" name="'
                . $level . '_preset" value="'
                . $this->escape($preset->token()) . '"'
                . ($style->preset() === $preset->token() ? ' checked' : '')
                . '><span class="blogEditorPreferences__presetName">'
                . $this->escape($preset->label()) . '</span><span class="'
                . 'blogEditorPreferences__presetPreview '
                . $this->escape($preset->previewClass()) . '">'
                . '<span class="blogEditor__previewHeading">Ejemplo de '
                . 't&iacute;tulo ' . $upper . '</span></span></label>';
        }
        $html .= '</div><div class="blogEditorPreferences__controls">'
            . $this->select(
                $level,
                'font_size',
                'Tama&ntilde;o',
                [
                    'small' => 'S',
                    'default' => 'M',
                    'large' => 'L',
                    'xlarge' => 'XL',
                ],
                $style->fontSize()
            )
            . $this->select(
                $level,
                'font_weight',
                'Grosor',
                [
                    'default' => 'Predeterminado',
                    'regular' => 'Regular',
                    'medium' => 'Medio',
                    'semibold' => 'Seminegrita',
                    'bold' => 'Negrita',
                ],
                $style->fontWeight()
            )
            . $this->colorChoices($level, $style->textColor())
            . $this->alignmentChoices($level, $style->textAlign())
            . '</div></fieldset>';

        return $html;
    }

    /** @param array<string, string> $options */
    private function select(
        string $level,
        string $field,
        string $label,
        array $options,
        string $selected
    ): string {
        $id = 'blog-style-' . $level . '-' . str_replace('_', '-', $field);
        $html = '<label class="blogEditorPreferences__control" for="'
            . $id . '"><span>' . $label . '</span><select id="' . $id
            . '" name="' . $level . '_' . $field . '">';
        foreach ($options as $value => $copy) {
            $html .= '<option value="' . $value . '"'
                . ($value === $selected ? ' selected' : '') . '>'
                . $copy . '</option>';
        }

        return $html . '</select></label>';
    }

    private function colorChoices(string $level, string $selected): string
    {
        $labels = [
            'default' => 'Tema',
            'color00' => 'Claro',
            'color01' => 'Oscuro',
            'color02' => 'Principal',
            'color03' => 'Secundario',
            'color04' => 'Color 04',
            'color05' => 'Color 05',
        ];
        $html = '<fieldset class="blogEditorPreferences__choiceSet">'
            . '<legend>Color</legend><div class="blogEditorPreferences__choices">';
        foreach ($labels as $value => $label) {
            $id = 'blog-style-' . $level . '-color-' . $value;
            $html .= '<label class="blogEditorPreferences__choice '
                . 'blogEditorPreferences__choice--color" for="' . $id
                . '"><input id="' . $id . '" type="radio" name="'
                . $level . '_text_color" value="' . $value . '"'
                . ($selected === $value ? ' checked' : '') . '><span '
                . 'class="blogEditorPreferences__swatch" data-color="'
                . $value . '" aria-hidden="true"></span><span>' . $label
                . '</span></label>';
        }

        return $html . '</div></fieldset>';
    }

    private function alignmentChoices(string $level, string $selected): string
    {
        $labels = [
            'start' => ['Izquierda', '&#8676;'],
            'center' => ['Centro', '&#8596;'],
            'end' => ['Derecha', '&#8677;'],
            'justify' => ['Justificar', '&#8646;'],
        ];
        $html = '<fieldset class="blogEditorPreferences__choiceSet">'
            . '<legend>Alineaci&oacute;n</legend>'
            . '<div class="blogEditorPreferences__choices">';
        foreach ($labels as $value => [$label, $icon]) {
            $id = 'blog-style-' . $level . '-align-' . $value;
            $html .= '<label class="blogEditorPreferences__choice" for="'
                . $id . '"><input id="' . $id . '" type="radio" name="'
                . $level . '_text_align" value="' . $value . '"'
                . ($selected === $value ? ' checked' : '')
                . '><span aria-hidden="true">' . $icon
                . '</span><span class="webadmin-srOnly">' . $label
                . '</span></label>';
        }

        return $html . '</div></fieldset>';
    }

    private function levelDescription(string $level): string
    {
        return match ($level) {
            'h2' => 'Secciones principales',
            'h3' => 'Art&iacute;culos y temas',
            'h4' => 'Subapartados',
            'h5' => 'Detalle de subapartado',
            default => 'Nivel de detalle final',
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
