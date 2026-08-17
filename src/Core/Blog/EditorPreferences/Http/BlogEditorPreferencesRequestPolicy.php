<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorPreferences\Http;

use App\Core\Blog\EditorPreferences\BlogEditorPreferences;
use App\Core\Blog\StructuredContent\Presentation\BlogHeadingPresetCatalog;
use App\Core\Http\Request;
use App\Core\WebAdmin\Http\WebAdminHttpRequestPolicy;

/** Exact transport contract for the global Blog heading defaults. */
final class BlogEditorPreferencesRequestPolicy
{
    /** @var list<string> */
    private const LEVELS = ['h2', 'h3', 'h4', 'h5', 'h6'];

    /** @var array<string, list<string>> */
    private const VALUES = [
        'font_size' => ['default', 'small', 'large', 'xlarge'],
        'font_weight' => [
            'default', 'regular', 'medium', 'semibold', 'bold',
        ],
        'text_color' => [
            'default', 'color00', 'color01', 'color02', 'color03',
            'color04', 'color05',
        ],
        'text_align' => ['start', 'center', 'end', 'justify'],
    ];

    public function __construct(
        private readonly WebAdminHttpRequestPolicy $webAdminPolicy =
            new WebAdminHttpRequestPolicy(),
        private readonly BlogHeadingPresetCatalog $presetCatalog =
            new BlogHeadingPresetCatalog()
    ) {
    }

    public function acceptsIndex(Request $request): bool
    {
        return $request->isValid()
            && in_array($request->method(), ['GET', 'HEAD'], true)
            && $request->queryParams() === []
            && $request->formParams() === []
            && $request->bodySize() === 0;
    }

    public function acceptsSave(Request $request): bool
    {
        $expected = ['csrf', 'lock_version'];
        foreach (self::LEVELS as $level) {
            foreach (['preset', ...array_keys(self::VALUES)] as $field) {
                $expected[] = $level . '_' . $field;
            }
        }
        if (!$this->webAdminPolicy->acceptsFormPost($request, $expected)) {
            return false;
        }

        $lockVersion = $request->form('lock_version');
        if (
            !is_string($lockVersion)
            || preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', $lockVersion) !== 1
            || (string) (int) $lockVersion !== $lockVersion
        ) {
            return false;
        }

        foreach (self::LEVELS as $level) {
            $preset = $request->form($level . '_preset');
            if (!is_string($preset) || !$this->presetCatalog->has($preset)) {
                return false;
            }
            foreach (self::VALUES as $field => $values) {
                $value = $request->form($level . '_' . $field);
                if (!is_string($value) || !in_array($value, $values, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function preferences(Request $request): BlogEditorPreferences
    {
        $headings = [];
        foreach (self::LEVELS as $level) {
            $headings[$level] = [
                'preset' => (string) $request->form($level . '_preset'),
                'font_size' =>
                    (string) $request->form($level . '_font_size'),
                'font_weight' =>
                    (string) $request->form($level . '_font_weight'),
                'text_color' =>
                    (string) $request->form($level . '_text_color'),
                'text_align' =>
                    (string) $request->form($level . '_text_align'),
            ];
        }

        return new BlogEditorPreferences($headings, $this->presetCatalog);
    }
}
