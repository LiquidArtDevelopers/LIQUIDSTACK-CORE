<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Presentation\BlogH1ModuleCatalog;
use App\Core\Blog\StructuredContent\Presentation\BlogHeaderPresetCatalog;
use App\Core\Blog\StructuredContent\Presentation\BlogHeaderSelection;
use App\Core\Blog\StructuredContent\Presentation\BlogHeroCatalog;

/**
 * Composes the Blog H1 with the canonical LiquidStack hero/moduleH1 pairs.
 *
 * In a consumer stack it delegates to the real resource controllers. The
 * fallback preserves the same semantic/class contract for standalone CORE.
 */
final class BlogArticleHeaderResourceRenderer
{
    private readonly BlogHeaderResourceAdapterInterface $resources;
    private readonly BlogHeroCatalog $heroes;
    private readonly BlogH1ModuleCatalog $h1Modules;

    public function __construct(
        private readonly BlogHeaderPresetCatalog $presets =
            new BlogHeaderPresetCatalog(),
        ?BlogHeaderResourceAdapterInterface $resources = null,
        ?BlogHeroCatalog $heroes = null,
        ?BlogH1ModuleCatalog $h1Modules = null
    ) {
        $this->resources = $resources
            ?? new BlogLiquidStackHeaderResourceAdapter();
        $this->heroes = $heroes ?? new BlogHeroCatalog();
        $this->h1Modules = $h1Modules ?? new BlogH1ModuleCatalog();
    }

    public function renderDocument(
        BlogDocument $document,
        string $h1,
        ?string $excerpt,
        ?string $eyebrow,
        BlogArticleHeaderMedia|string|null $headerMedia = null,
        ?BlogArticleHeaderAttribution $attribution = null
    ): string {
        return $this->renderSelection(
            BlogHeaderSelection::forDocument(
                $document,
                $this->heroes,
                $this->h1Modules
            ),
            $h1,
            $excerpt,
            $eyebrow,
            $headerMedia,
            $attribution
        );
    }

    public function renderSelection(
        BlogHeaderSelection $selection,
        string $h1,
        ?string $excerpt,
        ?string $eyebrow,
        BlogArticleHeaderMedia|string|null $headerMedia = null,
        ?BlogArticleHeaderAttribution $attribution = null
    ): string {
        $typedHeaderMedia = $headerMedia instanceof BlogArticleHeaderMedia
            ? $headerMedia
            : null;
        $legacyHeaderMediaHtml = is_string($headerMedia)
            ? trim($headerMedia)
            : '';
        $resource = $this->renderIndependentProjectResources(
            $selection,
            $h1,
            $excerpt,
            $eyebrow,
            $typedHeaderMedia
        );

        $html = $resource ?? $this->renderIndependentFallback(
            $selection,
            $h1,
            $excerpt,
            $eyebrow,
            $typedHeaderMedia,
            $legacyHeaderMediaHtml
        );

        return $this->withAttribution($html, $attribution);
    }

    public function render(
        string $template,
        string $h1,
        ?string $excerpt,
        ?string $eyebrow,
        BlogArticleHeaderMedia|string|null $headerMedia = null,
        ?BlogArticleHeaderAttribution $attribution = null
    ): string {
        $preset = $this->presets->forTemplate($template);
        $typedHeaderMedia = $headerMedia instanceof BlogArticleHeaderMedia
            ? $headerMedia
            : null;
        $legacyHeaderMediaHtml = is_string($headerMedia)
            ? trim($headerMedia)
            : '';
        $resource = $this->renderWithProjectResources(
            $preset,
            $h1,
            $excerpt,
            $eyebrow,
            $typedHeaderMedia
        );

        $html = $resource ?? $this->renderFallback(
            $preset,
            $h1,
            $excerpt,
            $eyebrow,
            $typedHeaderMedia,
            $legacyHeaderMediaHtml
        );

        return $this->withAttribution($html, $attribution);
    }

    private function withAttribution(
        string $headerHtml,
        ?BlogArticleHeaderAttribution $attribution
    ): string {
        if ($attribution === null) {
            return $headerHtml;
        }
        $offset = strripos($headerHtml, '</header>');
        if ($offset === false) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        $signature = '<p class="blogArticleHero-signature">'
            . '<span class="blogArticleHero-author">'
            . $this->escape($attribution->displayName()) . '</span>'
            . '<span class="blogArticleHero-role">'
            . $this->escape($attribution->roleLabel()) . '</span>'
            . '<time class="blogArticleHero-date" datetime="'
            . $this->escape($attribution->publishedAt()->format(DATE_ATOM))
            . '">' . $this->escape($attribution->localizedDate())
            . '</time></p>';

        return substr($headerHtml, 0, $offset) . $signature
            . substr($headerHtml, $offset);
    }

    private function renderWithProjectResources(
        string $preset,
        string $h1,
        ?string $excerpt,
        ?string $eyebrow,
        ?BlogArticleHeaderMedia $headerMedia
    ): ?string {
        $requiredResources = match ($preset) {
            BlogHeaderPresetCatalog::HERO00 => [
                'moduleH1Type01',
                'hero00',
            ],
            BlogHeaderPresetCatalog::HERO06 => [
                'moduleH1Type03',
                'hero06',
            ],
            BlogHeaderPresetCatalog::HERO07 => [
                'moduleH1Type04',
                'hero07',
            ],
            BlogHeaderPresetCatalog::BASIC => ['moduleH1Type04'],
            default => [],
        };
        if (!$this->resources->supports($requiredResources)) {
            return null;
        }
        if (
            $preset !== BlogHeaderPresetCatalog::BASIC
            && $headerMedia === null
        ) {
            // Compatibility path for project views still passing the already
            // sanitized HTML projection. It is never parsed back into data.
            return null;
        }

        $content = match ($preset) {
                BlogHeaderPresetCatalog::HERO00 => $this->resources->render(
                    'moduleH1Type01',
                    [
                        '{classVar}' => 'blogArticleHero-heading',
                        '{h1-dl}' => '',
                        '{h1-text}' => $this->escape($h1),
                        '{p-01-dl}' => '',
                        '{p-01-text}' => $this->escape($eyebrow ?? ''),
                        '{p-02-dl}' => '',
                        '{p-02-text}' => $this->escape($excerpt ?? ''),
                        '{a-button-primary}' => '',
                    ]
                ),
                BlogHeaderPresetCatalog::HERO06 => $this->module03(
                    $h1,
                    $excerpt,
                    $eyebrow
                ),
                BlogHeaderPresetCatalog::HERO07,
                BlogHeaderPresetCatalog::BASIC => $this->module04(
                    $h1,
                    $excerpt,
                    $eyebrow
                ),
                default => null,
            };
        if (!is_string($content) || trim($content) === '') {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        if ($preset === BlogHeaderPresetCatalog::BASIC) {
            return '<header class="blogArticleHero blogArticleHero--basic">'
                . $content . '</header>';
        }
        if ($preset === BlogHeaderPresetCatalog::HERO00) {
            return $this->resources->render('hero00', array_merge([
                '{editor-attributes}' => '',
                '{hero00-content}' => $content,
            ], $this->mediaParameters($headerMedia)));
        }

        $hero = $preset === BlogHeaderPresetCatalog::HERO06
            ? 'hero06' : 'hero07';

        return $this->resources->render($hero, array_merge([
            '{classVar}' => 'blogArticleHero blogArticleHero--' . $preset,
            '{editor-attributes}' => '',
            '{' . $hero . '-content}' => $content,
        ], $this->mediaParameters($headerMedia)));
    }

    private function renderIndependentProjectResources(
        BlogHeaderSelection $selection,
        string $h1,
        ?string $excerpt,
        ?string $eyebrow,
        ?BlogArticleHeaderMedia $headerMedia
    ): ?string {
        $moduleResource = $this->h1Modules->resource(
            $selection->h1Module()
        );
        $heroResource = $selection->hero() === null
            ? null
            : $this->heroes->resource($selection->hero());
        $requiredResources = [$moduleResource];
        if ($heroResource !== null) {
            $requiredResources[] = $heroResource;
        }
        if (!$this->resources->supports($requiredResources)) {
            return null;
        }
        if ($heroResource !== null && $headerMedia === null) {
            return null;
        }

        $content = $this->renderSelectedModule(
            $selection->h1Module(),
            $h1,
            $excerpt,
            $eyebrow
        );
        if (trim($content) === '') {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        if ($heroResource === null) {
            return '<header class="blogArticleHero blogArticleHero--basic">'
                . $content . '</header>';
        }
        if ($heroResource === BlogHeroCatalog::HERO00) {
            return $this->resources->render($heroResource, array_merge([
                '{editor-attributes}' => '',
                '{hero00-content}' => $content,
            ], $this->mediaParameters($headerMedia)));
        }

        return $this->resources->render($heroResource, array_merge([
            '{classVar}' => 'blogArticleHero blogArticleHero--'
                . $selection->hero(),
            '{editor-attributes}' => '',
            '{' . $heroResource . '-content}' => $content,
        ], $this->mediaParameters($headerMedia)));
    }

    private function renderSelectedModule(
        string $module,
        string $h1,
        ?string $excerpt,
        ?string $eyebrow
    ): string {
        return match ($module) {
            BlogH1ModuleCatalog::TYPE01 => $this->resources->render(
                BlogH1ModuleCatalog::TYPE01,
                [
                    '{classVar}' => 'blogArticleHero-heading',
                    '{h1-dl}' => '',
                    '{h1-text}' => $this->escape($h1),
                    '{p-01-dl}' => '',
                    '{p-01-text}' => $this->escape($eyebrow ?? ''),
                    '{p-02-dl}' => '',
                    '{p-02-text}' => $this->escape($excerpt ?? ''),
                    '{a-button-primary}' => '',
                ]
            ),
            BlogH1ModuleCatalog::TYPE03 => $this->module03(
                $h1,
                $excerpt,
                $eyebrow
            ),
            BlogH1ModuleCatalog::TYPE04 => $this->module04(
                $h1,
                $excerpt,
                $eyebrow
            ),
            default => throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            ),
        };
    }

    private function renderIndependentFallback(
        BlogHeaderSelection $selection,
        string $h1,
        ?string $excerpt,
        ?string $eyebrow,
        ?BlogArticleHeaderMedia $headerMedia,
        string $legacyHeaderMediaHtml = ''
    ): string {
        $module = match ($selection->h1Module()) {
            BlogH1ModuleCatalog::TYPE01 =>
                '<div class="moduleH1Type01 blogArticleHero-heading">'
                . '<h1 class="moduleH1Type01-header">'
                . $this->escape($h1) . '</h1>'
                . $this->paragraph('destacado', $eyebrow)
                . $this->paragraph('', $excerpt) . '</div>',
            BlogH1ModuleCatalog::TYPE03 =>
                '<div class="moduleH1Type03 blogArticleHero-heading">'
                . $this->eyebrow('moduleH1Type03-eyebrow', $eyebrow)
                . '<h1 class="moduleH1Type03-title">'
                . $this->escape($h1) . '</h1>'
                . $this->paragraph('moduleH1Type03-text', $excerpt)
                . '</div>',
            BlogH1ModuleCatalog::TYPE04 =>
                '<div class="moduleH1Type04 blogArticleHero-heading">'
                . $this->eyebrow('moduleH1Type04-eyebrow', $eyebrow)
                . '<h1 class="moduleH1Type04-title">'
                . $this->escape($h1) . '</h1>'
                . $this->paragraph('moduleH1Type04-text', $excerpt)
                . '</div>',
            default => throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            ),
        };
        if (!$selection->hasHero()) {
            return '<header class="blogArticleHero blogArticleHero--basic">'
                . $module . '</header>';
        }
        $mediaHtml = $headerMedia?->html() ?? $legacyHeaderMediaHtml;
        $media = $mediaHtml === ''
            ? ''
            : '<div class="blogArticleHero-media">' . $mediaHtml . '</div>';
        $hero = (string) $selection->hero();

        return '<header class="blogArticleHero ' . $hero
            . ' blogArticleHero--' . $hero . '">' . $media
            . '<div class="' . $hero . '-content">'
            . $module . '</div></header>';
    }

    private function module03(
        string $h1,
        ?string $excerpt,
        ?string $eyebrow
    ): string {
        return $this->resources->render('moduleH1Type03', [
            '{classVar}' => 'blogArticleHero-heading',
            '{eyebrow}' => $this->eyebrow(
                'moduleH1Type03-eyebrow',
                $eyebrow
            ),
            '{header-primary}' => '<h1 class="moduleH1Type03-title">'
                . $this->escape($h1) . '</h1>',
            '{intro}' => $this->paragraph(
                'moduleH1Type03-text',
                $excerpt
            ),
            '{a-button-primary}' => '',
        ]);
    }

    private function module04(
        string $h1,
        ?string $excerpt,
        ?string $eyebrow
    ): string {
        return $this->resources->render('moduleH1Type04', [
            '{classVar}' => 'blogArticleHero-heading',
            '{eyebrow}' => $this->eyebrow(
                'moduleH1Type04-eyebrow',
                $eyebrow
            ),
            '{header-primary}' => '<h1 class="moduleH1Type04-title">'
                . $this->escape($h1) . '</h1>',
            '{intro}' => $this->paragraph(
                'moduleH1Type04-text',
                $excerpt
            ),
            '{a-button-primary}' => '',
        ]);
    }

    private function renderFallback(
        string $preset,
        string $h1,
        ?string $excerpt,
        ?string $eyebrow,
        ?BlogArticleHeaderMedia $headerMedia,
        string $legacyHeaderMediaHtml = ''
    ): string {
        $module = match ($preset) {
            BlogHeaderPresetCatalog::HERO00 =>
                '<div class="moduleH1Type01 blogArticleHero-heading">'
                . '<h1 class="moduleH1Type01-header">'
                . $this->escape($h1) . '</h1>'
                . $this->paragraph('destacado', $eyebrow)
                . $this->paragraph('', $excerpt) . '</div>',
            BlogHeaderPresetCatalog::HERO06 =>
                '<div class="moduleH1Type03 blogArticleHero-heading">'
                . $this->eyebrow('moduleH1Type03-eyebrow', $eyebrow)
                . '<h1 class="moduleH1Type03-title">'
                . $this->escape($h1) . '</h1>'
                . $this->paragraph('moduleH1Type03-text', $excerpt)
                . '</div>',
            default => '<div class="moduleH1Type04 blogArticleHero-heading">'
                . $this->eyebrow('moduleH1Type04-eyebrow', $eyebrow)
                . '<h1 class="moduleH1Type04-title">'
                . $this->escape($h1) . '</h1>'
                . $this->paragraph('moduleH1Type04-text', $excerpt)
                . '</div>',
        };
        if ($preset === BlogHeaderPresetCatalog::BASIC) {
            return '<header class="blogArticleHero blogArticleHero--basic">'
                . $module . '</header>';
        }
        $hero = $preset;
        $headerMediaHtml = $headerMedia?->html()
            ?? $legacyHeaderMediaHtml;
        $media = $headerMediaHtml === ''
            ? ''
            : '<div class="blogArticleHero-media">'
                . $headerMediaHtml . '</div>';

        return '<header class="blogArticleHero ' . $hero
            . ' blogArticleHero--' . $preset . '">'
            . $media . '<div class="' . $hero . '-content">'
            . $module . '</div></header>';
    }

    private function srcset(BlogArticleHeaderMedia $headerMedia): string
    {
        return implode(', ', array_map(
            fn (BlogResolvedImageCandidate $candidate): string =>
                $this->escape($candidate->url()) . ' '
                    . $candidate->width() . 'w',
            $headerMedia->image()->candidates()
        ));
    }

    /** @return array<string, string> */
    private function mediaParameters(
        BlogArticleHeaderMedia $headerMedia
    ): array {
        return [
            '{img-dl}' => '',
            '{img-src}' => $this->escape(
                $headerMedia->image()->sourceUrl()
            ),
            '{img-srcset}' => $this->srcset($headerMedia),
            '{img-sizes}' => $this->escape($headerMedia->sizes()),
            '{img-alt}' => $this->escape($headerMedia->alt()),
            '{img-title}' => $this->escape($headerMedia->title() ?? ''),
            '{img-width}' => (string) $headerMedia->image()->width(),
            '{img-height}' => (string) $headerMedia->image()->height(),
            '{img-object-position-y}' => $this->escape(
                $headerMedia->objectPositionY()
            ),
        ];
    }

    private function eyebrow(string $class, ?string $text): string
    {
        return $this->paragraph($class, $text);
    }

    private function paragraph(string $class, ?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }
        $attribute = $class === ''
            ? '' : ' class="' . $class . '"';

        return '<p' . $attribute . '>' . $this->escape($text) . '</p>';
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
