<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Document\BlogDocumentWalker;
use App\Core\Blog\StructuredContent\Document\BlogCustomTextCssSanitizer;
use App\Core\Blog\StructuredContent\Document\BlogCustomTextHtmlSanitizer;
use App\Core\Blog\StructuredContent\Document\BlogEmbedHtmlSanitizer;
use App\Core\Blog\StructuredContent\Presentation\BlogHeadingPresetCatalog;
use App\Core\Blog\StructuredContent\Presentation\BlogImagePresentationPolicy;
use App\Core\Blog\StructuredContent\Presentation\BlogImageRadiusPreset;
use App\Core\Blog\StructuredContent\Presentation\BlogPresentationSize;
use App\Core\Blog\StructuredContent\Presentation\BlogPublicColor;
use InvalidArgumentException;
use Throwable;

/** Pure, CSP-neutral renderer for canonical Blog document projections. */
final class BlogDocumentHtmlRenderer
{
    /** Bounded by BlogDocument::MAX_JSON_BYTES and CSS namespace expansion. */
    private const MAX_SCOPED_CSS_BYTES = 6_100_000;
    private const TEMPLATE_CLASSES = [
        BlogDocumentTemplateRegistry::ARTICLE_BASIC => 'blogDocument--basic',
        BlogDocumentTemplateRegistry::ARTICLE_COVER => 'blogDocument--cover',
        BlogDocumentTemplateRegistry::ARTICLE_HERO00 => 'blogDocument--cover blogDocument--hero00',
        BlogDocumentTemplateRegistry::ARTICLE_HERO06 => 'blogDocument--cover blogDocument--hero06',
    ];
    private const CALLOUT_CLASSES = [
        'neutral' => 'blogDocument__callout--neutral',
        'info' => 'blogDocument__callout--info',
        'warning' => 'blogDocument__callout--warning',
    ];
    private const IMAGE_CLASSES = [
        'content' => 'blogDocument__image--content',
        'wide' => 'blogDocument__image--wide',
        'cover' => 'blogDocument__image--cover',
    ];
    private const IMAGE_SIZES = [
        'content' => '(max-width: 48rem) 100vw, 48rem',
        'wide' => '(max-width: 72rem) 100vw, 72rem',
        'cover' => '100vw',
    ];
    private const CTA_CLASSES = [
        'primary' => 'blogDocument__cta--primary',
        'secondary' => 'blogDocument__cta--secondary',
        'type03' => 'blogDocument__cta--type03',
        'type04' => 'blogDocument__cta--type04',
    ];
    private const LIST_MARKER_CLASSES = [
        'disc' => 'blogDocument__list--marker-disc',
        'circle' => 'blogDocument__list--marker-circle',
        'square' => 'blogDocument__list--marker-square',
        'decimal' => 'blogDocument__list--marker-decimal',
        'lower-alpha' => 'blogDocument__list--marker-lower-alpha',
        'upper-alpha' => 'blogDocument__list--marker-upper-alpha',
    ];
    private const QUOTE_PRESET_CLASSES = [
        'default' => 'blogDocument__quote--preset-default',
        'accent' => 'blogDocument__quote--preset-accent',
        'minimal' => 'blogDocument__quote--preset-minimal',
    ];
    private const SEPARATOR_STYLE_CLASSES = [
        'solid' => 'blogDocument__separator--solid',
        'dashed' => 'blogDocument__separator--dashed',
        'dotted' => 'blogDocument__separator--dotted',
        'double' => 'blogDocument__separator--double',
    ];
    private const SEPARATOR_THICKNESS_CLASSES = [
        'thin' => 'blogDocument__separator--thin',
        'medium' => 'blogDocument__separator--medium',
        'thick' => 'blogDocument__separator--thick',
    ];
    private const MODULE_WIDTH_CLASSES = [
        'full' => 'blogDocument__module--width-full',
        '80' => 'blogDocument__module--width-80',
        '60' => 'blogDocument__module--width-60',
        '40' => 'blogDocument__module--width-40',
    ];
    private const MODULE_ALIGN_CLASSES = [
        'start' => 'blogDocument__module--align-start',
        'center' => 'blogDocument__module--align-center',
        'end' => 'blogDocument__module--align-end',
    ];
    private const CONTAINER_WIDTH_CLASSES = [
        'full' => 'blogDocument__container--width-full',
        '80' => 'blogDocument__container--width-80',
        '60' => 'blogDocument__container--width-60',
        '40' => 'blogDocument__container--width-40',
    ];
    private const CONTAINER_ALIGN_CLASSES = [
        'start' => 'blogDocument__container--align-start',
        'center' => 'blogDocument__container--align-center',
        'end' => 'blogDocument__container--align-end',
    ];
    private const MODULE_TEXT_ALIGN_CLASSES = [
        'start' => 'blogDocument__module--text-align-start',
        'center' => 'blogDocument__module--text-align-center',
        'end' => 'blogDocument__module--text-align-end',
        'justify' => 'blogDocument__module--text-align-justify',
    ];
    private const MODULE_SIZE_CLASSES = [
        BlogPresentationSize::SMALL => 'blogDocument__module--size-s',
        BlogPresentationSize::MEDIUM => 'blogDocument__module--size-m',
        BlogPresentationSize::LARGE => 'blogDocument__module--size-l',
        BlogPresentationSize::EXTRA_LARGE => 'blogDocument__module--size-xl',
    ];
    private const MODULE_SPACING_CLASSES = [
        'none' => 'blogDocument__module--spacing-none',
        's' => 'blogDocument__module--spacing-s',
        'm' => 'blogDocument__module--spacing-m',
        'l' => 'blogDocument__module--spacing-l',
        'xl' => 'blogDocument__module--spacing-xl',
    ];
    private const MODULE_FONT_SIZE_CLASSES = [
        'default' => 'blogDocument__module--font-size-default',
        'small' => 'blogDocument__module--font-size-small',
        'large' => 'blogDocument__module--font-size-large',
        'xlarge' => 'blogDocument__module--font-size-xlarge',
        BlogPresentationSize::SMALL =>
            'blogDocument__module--font-size-small',
        BlogPresentationSize::MEDIUM =>
            'blogDocument__module--font-size-default',
        BlogPresentationSize::LARGE =>
            'blogDocument__module--font-size-large',
        BlogPresentationSize::EXTRA_LARGE =>
            'blogDocument__module--font-size-xlarge',
    ];
    private const MODULE_FONT_WEIGHT_CLASSES = [
        'default' => 'blogDocument__module--font-weight-default',
        'regular' => 'blogDocument__module--font-weight-regular',
        'medium' => 'blogDocument__module--font-weight-medium',
        'semibold' => 'blogDocument__module--font-weight-semibold',
        'bold' => 'blogDocument__module--font-weight-bold',
    ];
    private const MODULE_TEXT_COLOR_CLASSES = [
        'default' => 'blogDocument__module--text-color-default',
        'color00' => 'blogDocument__module--text-color00',
        'color01' => 'blogDocument__module--text-color01',
        'color02' => 'blogDocument__module--text-color02',
        'color03' => 'blogDocument__module--text-color03',
        'color04' => 'blogDocument__module--text-color04',
        'color05' => 'blogDocument__module--text-color05',
    ];
    private const CONTAINER_BACKGROUND_CLASSES = [
        'color00' => 'blogDocument__container--background-color00',
        'color01' => 'blogDocument__container--background-color01',
        'color02' => 'blogDocument__container--background-color02',
        'color03' => 'blogDocument__container--background-color03',
        'color04' => 'blogDocument__container--background-color04',
        'color05' => 'blogDocument__container--background-color05',
    ];
    private const IMAGE_RADIUS_CLASSES = [
        BlogImageRadiusPreset::NONE => 'blogDocument__image--radius-none',
        BlogImageRadiusPreset::SMALL => 'blogDocument__image--radius-small',
        BlogImageRadiusPreset::MEDIUM => 'blogDocument__image--radius-medium',
        BlogImageRadiusPreset::LARGE => 'blogDocument__image--radius-large',
    ];
    private const IMAGE_OBJECT_FIT_CLASSES = [
        'cover' => 'blogDocument__image--fit-cover',
        'contain' => 'blogDocument__image--fit-contain',
    ];
    private const IMAGE_OBJECT_POSITION_CLASSES = [
        'top' => 'blogDocument__image--position-y-top',
        'center' => 'blogDocument__image--position-y-center',
        'bottom' => 'blogDocument__image--position-y-bottom',
    ];
    private const IMAGE_OVERLAY_MODE_CLASSES = [
        'normal' => 'blogDocument__image--overlay-normal',
        'multiply' => 'blogDocument__image--overlay-multiply',
        'screen' => 'blogDocument__image--overlay-screen',
        'overlay' => 'blogDocument__image--overlay-overlay',
    ];
    private const INLINE_STYLE_CLASSES = [
        'size-small' => 'blogDocument__inline--size-small',
        'size-large' => 'blogDocument__inline--size-large',
        'size-xlarge' => 'blogDocument__inline--size-xlarge',
        'text-color00' => 'blogDocument__inline--text-color00',
        'text-color01' => 'blogDocument__inline--text-color01',
        'text-color02' => 'blogDocument__inline--text-color02',
        'text-color03' => 'blogDocument__inline--text-color03',
        'text-color04' => 'blogDocument__inline--text-color04',
        'text-color05' => 'blogDocument__inline--text-color05',
        'text-basic-red' => 'blogDocument__inline--text-basic-red',
        'text-basic-orange' => 'blogDocument__inline--text-basic-orange',
        'text-basic-yellow' => 'blogDocument__inline--text-basic-yellow',
        'text-basic-green' => 'blogDocument__inline--text-basic-green',
        'text-basic-blue' => 'blogDocument__inline--text-basic-blue',
        'text-basic-purple' => 'blogDocument__inline--text-basic-purple',
        'text-basic-pink' => 'blogDocument__inline--text-basic-pink',
        'text-basic-gray' => 'blogDocument__inline--text-basic-gray',
        'background-color00' => 'blogDocument__inline--background-color00',
        'background-color01' => 'blogDocument__inline--background-color01',
        'background-color02' => 'blogDocument__inline--background-color02',
        'background-color03' => 'blogDocument__inline--background-color03',
        'background-color04' => 'blogDocument__inline--background-color04',
        'background-color05' => 'blogDocument__inline--background-color05',
        'background-basic-red' =>
            'blogDocument__inline--background-basic-red',
        'background-basic-orange' =>
            'blogDocument__inline--background-basic-orange',
        'background-basic-yellow' =>
            'blogDocument__inline--background-basic-yellow',
        'background-basic-green' =>
            'blogDocument__inline--background-basic-green',
        'background-basic-blue' =>
            'blogDocument__inline--background-basic-blue',
        'background-basic-purple' =>
            'blogDocument__inline--background-basic-purple',
        'background-basic-pink' =>
            'blogDocument__inline--background-basic-pink',
        'background-basic-gray' =>
            'blogDocument__inline--background-basic-gray',
    ];

    private readonly BlogHeadingPresetCatalog $headingPresets;
    private readonly BlogCustomTextHtmlSanitizer $customTextHtml;
    private readonly BlogCustomTextCssSanitizer $customTextCss;
    private readonly BlogEmbedHtmlSanitizer $embedHtml;
    private readonly BlogConsentIframeProjector $consentIframes;

    public function __construct(
        private readonly BlogImageResolverInterface $imageResolver,
        ?BlogHeadingPresetCatalog $headingPresets = null,
        ?BlogCustomTextHtmlSanitizer $customTextHtml = null,
        ?BlogCustomTextCssSanitizer $customTextCss = null,
        ?BlogEmbedHtmlSanitizer $embedHtml = null,
        ?BlogConsentIframeProjector $consentIframes = null
    ) {
        $this->headingPresets = $headingPresets
            ?? new BlogHeadingPresetCatalog();
        $this->customTextHtml = $customTextHtml
            ?? new BlogCustomTextHtmlSanitizer();
        $this->customTextCss = $customTextCss
            ?? new BlogCustomTextCssSanitizer();
        $this->embedHtml = $embedHtml ?? new BlogEmbedHtmlSanitizer();
        $this->consentIframes = $consentIframes
            ?? new BlogConsentIframeProjector();
    }

    public function render(BlogDocument $document): string
    {
        return $this->renderBody($document, false);
    }

    /**
     * Semantic body for new public compositions. Unlike render(), retained
     * for compatibility, this projection omits the featured header medium.
     */
    public function renderMain(BlogDocument $document): string
    {
        return $this->renderBody($document, true);
    }

    /** Sanitized stylesheet projection for a nonce-bearing public shell. */
    public function renderScopedCss(BlogDocument $document): string
    {
        $css = '';
        foreach ((new BlogDocumentWalker())->modules($document) as $module) {
            if (($module['type'] ?? null) === 'image') {
                $css .= $this->imageScopedCss($module);
                if (strlen($css) > self::MAX_SCOPED_CSS_BYTES) {
                    throw new BlogRenderingException(
                        BlogRenderingException::INVALID_RENDER_STATE
                    );
                }
                continue;
            }
            if (
                !in_array(
                    $module['type'] ?? null,
                    ['paragraph', 'embed'],
                    true
                )
                || !is_string($module['css'] ?? null)
                || $module['css'] === ''
            ) {
                continue;
            }
            $id = $module['id'] ?? null;
            if (!is_string($id)) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            }
            $css .= $this->customTextCss->renderScoped($module['css'], $id);
            if (strlen($css) > self::MAX_SCOPED_CSS_BYTES) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            }
        }

        return $css;
    }

    private function renderBody(
        BlogDocument $document,
        bool $omitHeaderMedia
    ): string
    {
        $templateClass = self::TEMPLATE_CLASSES[$document->template()] ?? null;
        if ($templateClass === null) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        if ($document->version() === BlogDocument::LAYOUT_VERSION) {
            return $this->renderLayoutBody(
                $document,
                $templateClass,
                $omitHeaderMedia
            );
        }

        $blocks = $document->blocks();
        if (
            $omitHeaderMedia
            && BlogDocumentTemplateRegistry::hasCover($document->template())
        ) {
            array_shift($blocks);
        }

        $html = '<div class="blogDocument ' . $templateClass
            . ' blogDocument--background-white" data-blog-canvas="white">';
        $sectionOpen = false;
        $articleOpen = false;
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'heading') {
                $level = (int) ($block['level'] ?? 0);

                if ($level === 2) {
                    if ($articleOpen) {
                        $html .= '</article>';
                        $articleOpen = false;
                    }
                    if ($sectionOpen) {
                        $html .= '</section>';
                    }

                    $html .= '<section class="blogDocument__section"'
                        . ' aria-labelledby="' . $this->blockId($block['id'])
                        . '">' . $this->renderHeading($block);
                    $sectionOpen = true;
                    continue;
                }

                if ($level === 3) {
                    if (!$sectionOpen) {
                        throw new BlogRenderingException(
                            BlogRenderingException::INVALID_RENDER_STATE
                        );
                    }
                    if ($articleOpen) {
                        $html .= '</article>';
                    }

                    $html .= '<article class="blogDocument__article"'
                        . ' aria-labelledby="' . $this->blockId($block['id'])
                        . '">' . $this->renderHeading($block);
                    $articleOpen = true;
                    continue;
                }

                if (!$sectionOpen || !$articleOpen) {
                    throw new BlogRenderingException(
                        BlogRenderingException::INVALID_RENDER_STATE
                    );
                }
            }

            $html .= $this->renderBlock($block);
        }

        if ($articleOpen) {
            $html .= '</article>';
        }
        if ($sectionOpen) {
            $html .= '</section>';
        }

        return $html . '</div>';
    }

    private function renderLayoutBody(
        BlogDocument $document,
        string $templateClass,
        bool $omitHeaderMedia
    ): string
    {
        $nodes = $document->blocks();
        if (
            $omitHeaderMedia
            && BlogDocumentTemplateRegistry::hasCover($document->template())
        ) {
            array_shift($nodes);
        }
        $html = '<div class="blogDocument ' . $templateClass
            . ' blogDocument--layout blogDocument--background-white"'
            . ' data-blog-canvas="white">';
        foreach ($nodes as $node) {
            $type = $node['type'] ?? null;
            if ($type === 'image') {
                $html .= $this->renderPresentedModule($node);
                continue;
            }
            if ($type !== 'section') {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            }
            $children = $node['children'] ?? null;
            $heading = is_array($children) ? ($children[0] ?? null) : null;
            $headingId = is_array($heading)
                ? $this->firstHeadingId($heading) : null;
            if ($headingId === null) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            }
            [$backgroundClass, $backgroundAttributes] =
                $this->containerBackgroundProjection($node);
            $html .= '<section id="' . $this->containerId($node['id'])
                . '" class="blogDocument__section' . $backgroundClass
                . '"' . $backgroundAttributes . ' aria-labelledby="'
                . $headingId . '">';
            foreach ($children as $child) {
                $html .= $this->renderLayoutNode($child, true);
            }
            $html .= '</section>';
        }

        return $html . '</div>';
    }

    /** @param array<string, mixed> $node */
    private function renderLayoutNode(
        array $node,
        bool $directSectionChild = false
    ): string
    {
        $type = $node['type'] ?? null;
        if ($type === 'article' || $type === 'div') {
            return $this->renderLayoutContainer($node, $type);
        }
        if ($type === 'section') {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        return $this->renderPresentedModule($node, $directSectionChild);
    }

    /** @param array<string, mixed> $node */
    private function renderLayoutContainer(array $node, string $type): string
    {
        $layout = $node['layout'] ?? null;
        $preset = is_array($layout) ? ($layout['preset'] ?? null) : null;
        $columns = is_array($layout) ? ($layout['columns'] ?? null) : null;
        if (!is_string($preset) || !is_array($columns)) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        $tag = $type === 'article' ? 'article' : 'div';
        $class = $type === 'article'
            ? 'blogDocument__article' : 'blogDocument__division';
        $label = $type === 'article'
            ? $this->firstHeadingId($node) : null;
        [$backgroundClass, $backgroundAttributes] =
            $this->containerBackgroundProjection($node);
        $layoutPresentationClass = $this->containerLayoutProjection($node);
        $html = '<' . $tag . ' id="' . $this->containerId($node['id'])
            . '" class="' . $class
            . ' blogDocument__layout blogDocument__layout--'
            . $this->escapeClassToken($preset) . $layoutPresentationClass
            . $backgroundClass . '"'
            . $backgroundAttributes
            . ($label === null ? '' : ' aria-labelledby="' . $label . '"')
            . '>';
        foreach ($columns as $column) {
            if (!is_array($column) || !is_array($column['children'] ?? null)) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            }
            $html .= '<div id="' . $this->containerId($column['id'])
                . '" class="blogDocument__column">';
            foreach ($column['children'] as $child) {
                if (!is_array($child)) {
                    throw new BlogRenderingException(
                        BlogRenderingException::INVALID_RENDER_STATE
                    );
                }
                $html .= $this->renderLayoutNode($child);
            }
            $html .= '</div>';
        }

        return $html . '</' . $tag . '>';
    }

    /** @param array<string, mixed> $module */
    private function renderPresentedModule(
        array $module,
        bool $directSectionChild = false
    ): string
    {
        $presentation = $module['presentation'] ?? null;
        $width = is_array($presentation)
            ? ($presentation['width'] ?? null) : null;
        $align = is_array($presentation)
            ? ($presentation['align'] ?? null) : null;
        $textAlign = is_array($presentation)
            ? ($presentation['text_align'] ?? null) : null;
        $widthClass = is_string($width)
            ? (self::MODULE_WIDTH_CLASSES[$width] ?? null) : null;
        $alignClass = is_string($align)
            ? (self::MODULE_ALIGN_CLASSES[$align] ?? null) : null;
        $textAlignClass = is_string($textAlign)
            ? (self::MODULE_TEXT_ALIGN_CLASSES[$textAlign] ?? null) : null;
        $sizeValue = is_array($presentation)
            ? ($presentation['size'] ?? $presentation['font_size'] ?? null)
            : null;
        $size = $sizeValue === null
            ? BlogPresentationSize::MEDIUM
            : BlogPresentationSize::canonicalize($sizeValue);
        $sizeClass = is_string($size)
            ? (self::MODULE_SIZE_CLASSES[$size] ?? null) : null;
        if ($widthClass === null || $alignClass === null || $textAlignClass === null) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        if ($sizeClass === null) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        $classes = implode(' ', [
            'blogDocument__module',
            $widthClass,
            $alignClass,
            $textAlignClass,
            $sizeClass,
        ]);
        foreach (
            ['spacing_before' => 'before', 'spacing_after' => 'after']
            as $spacingKey => $spacingDirection
        ) {
            $spacingValue = is_array($presentation)
                ? ($presentation[$spacingKey] ?? 'none')
                : 'none';
            $spacingClass = is_string($spacingValue)
                ? (self::MODULE_SPACING_CLASSES[$spacingValue] ?? null)
                : null;
            if ($spacingClass === null) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            }
            $classes .= ' ' . $spacingClass . '-' . $spacingDirection;
        }
        $typographyKeys = ['font_size', 'font_weight', 'text_color'];
        $hasTypography = false;
        $moduleAttributes = '';
        foreach ($typographyKeys as $key) {
            $hasTypography = $hasTypography
                || (is_array($presentation) && array_key_exists($key, $presentation));
        }
        if ($hasTypography) {
            $moduleType = $module['type'] ?? null;
            if ($moduleType === 'list') {
                if (
                    !is_array($presentation)
                    || array_key_exists('font_size', $presentation)
                    || array_key_exists('font_weight', $presentation)
                    || !array_key_exists('text_color', $presentation)
                ) {
                    throw new BlogRenderingException(
                        BlogRenderingException::INVALID_RENDER_STATE
                    );
                }
                [$textColorClass, $textColorAttributes] =
                    $this->moduleTextColorProjection(
                        $presentation['text_color']
                    );
                if ($textColorClass === null) {
                    throw new BlogRenderingException(
                        BlogRenderingException::INVALID_RENDER_STATE
                    );
                }
                $classes .= ' ' . $textColorClass;
                $moduleAttributes .= $textColorAttributes;
            } elseif (!in_array($moduleType, ['paragraph', 'heading'], true)) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            } else {
                $fontSize = is_array($presentation)
                    ? ($presentation['font_size'] ?? $presentation['size'] ?? null)
                    : null;
                $fontWeight = is_array($presentation)
                    ? ($presentation['font_weight'] ?? null) : null;
                $textColor = is_array($presentation)
                    ? ($presentation['text_color'] ?? null) : null;
                $fontSizeClass = is_string($fontSize)
                    ? (self::MODULE_FONT_SIZE_CLASSES[$fontSize] ?? null) : null;
                $fontWeightClass = is_string($fontWeight)
                    ? (self::MODULE_FONT_WEIGHT_CLASSES[$fontWeight] ?? null) : null;
                [$textColorClass, $textColorAttributes] =
                    $this->moduleTextColorProjection($textColor);
                if (
                    $fontSizeClass === null
                    || $fontWeightClass === null
                    || $textColorClass === null
                ) {
                    throw new BlogRenderingException(
                        BlogRenderingException::INVALID_RENDER_STATE
                    );
                }
                $classes .= ' ' . $fontSizeClass . ' ' . $fontWeightClass
                    . ' ' . $textColorClass;
                $moduleAttributes .= $textColorAttributes;
            }
        }
        $imagePresentation = null;
        if (($module['type'] ?? null) === 'image') {
            $imagePresentation = $this->resolveImagePresentation(
                $presentation,
                $directSectionChild && $width === 'full'
            );
        }
        $html = $this->renderBlock($module, $imagePresentation);
        $classPosition = strpos($html, ' class="');
        if ($classPosition === false) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        $projected = substr($html, 0, $classPosition)
            . ' class="' . $classes . ' '
            . substr($html, $classPosition + strlen(' class="'));
        if ($moduleAttributes === '') {
            return $projected;
        }
        $openingEnd = strpos($projected, '>');
        if ($openingEnd === false) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        return substr($projected, 0, $openingEnd)
            . $moduleAttributes . substr($projected, $openingEnd);
    }

    /** @return array{0: string|null, 1: string} */
    private function moduleTextColorProjection(mixed $value): array
    {
        if ($value === 'default') {
            return [self::MODULE_TEXT_COLOR_CLASSES['default'], ''];
        }
        try {
            $color = BlogPublicColor::fromInput($value);
        } catch (InvalidArgumentException) {
            return [null, ''];
        }
        if ($color->isThemeToken()) {
            return [
                self::MODULE_TEXT_COLOR_CLASSES[$color->value()] ?? null,
                '',
            ];
        }

        return [
            'blogDocument__module--text-rgba',
            ' data-blog-text-rgba="' . $this->escape($color->value()) . '"',
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @return array{0: string, 1: string}
     */
    private function containerBackgroundProjection(array $node): array
    {
        $presentation = $node['presentation'] ?? null;
        if ($presentation === null) {
            return ['', ''];
        }
        if (!is_array($presentation)) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        if (!array_key_exists('background', $presentation)) {
            return ['', ''];
        }
        try {
            $background = BlogPublicColor::fromInput(
                $presentation['background']
            );
        } catch (InvalidArgumentException) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        if ($background->isThemeToken()) {
            $class = self::CONTAINER_BACKGROUND_CLASSES[
                $background->value()
            ] ?? null;
            if ($class === null) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            }

            return [
                ' blogDocument__container--background ' . $class,
                ' data-blog-background="theme" data-blog-background-token="'
                    . $this->escape($background->value()) . '"',
            ];
        }

        return [
            ' blogDocument__container--background '
                . 'blogDocument__container--background-rgba',
            ' data-blog-background="rgba" data-blog-background-rgba="'
                . $this->escape($background->value()) . '"',
        ];
    }

    /** @param array<string, mixed> $node */
    private function containerLayoutProjection(array $node): string
    {
        $presentation = $node['presentation'] ?? null;
        if ($presentation === null) {
            $presentation = [];
        }
        if (!is_array($presentation)) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        $width = $presentation['width'] ?? 'full';
        $align = $presentation['align'] ?? 'start';
        $widthClass = is_string($width)
            ? (self::CONTAINER_WIDTH_CLASSES[$width] ?? null)
            : null;
        $alignClass = is_string($align)
            ? (self::CONTAINER_ALIGN_CLASSES[$align] ?? null)
            : null;
        if ($widthClass === null || $alignClass === null) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        return ' ' . $widthClass . ' ' . $alignClass;
    }

    /** @param array<string, mixed> $node */
    private function firstHeadingId(array $node): ?string
    {
        if (($node['type'] ?? null) === 'heading') {
            return $this->blockId((string) $node['id']);
        }
        if (($node['type'] ?? null) === 'paragraph') {
            $id = $node['id'] ?? null;
            if (!is_string($id)) {
                return null;
            }
            if (array_key_exists('html', $node)) {
                $html = $node['html'] ?? null;

                return is_string($html)
                    ? $this->customTextHtml->firstHeadingRenderId($html, $id)
                    : null;
            }
            $content = $node['content'] ?? null;
            if (!$this->isTextFlow(is_array($content) ? $content : [])) {
                return null;
            }
            foreach ($content as $flowNode) {
                if (($flowNode['type'] ?? null) === 'heading') {
                    return $this->customTextHtml->flowHeadingId($id, 0);
                }
            }

            return null;
        }
        $type = $node['type'] ?? null;
        if (!in_array($type, ['article', 'div'], true)) {
            return null;
        }
        foreach (($node['layout']['columns'] ?? []) as $column) {
            foreach (($column['children'] ?? []) as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $id = $this->firstHeadingId($child);
                if ($id !== null) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * Renders the optional featured medium for the page header. It is kept
     * separate from render() so the public body always starts with its own
     * section hierarchy and the cover is never duplicated.
     */
    public function renderHeaderMedia(BlogDocument $document): string
    {
        return $this->headerMedia($document)?->html() ?? '';
    }

    /**
     * Resolves the canonical, typed header image once for both project
     * resources and the resource-free SSR fallback.
     */
    public function headerMedia(
        BlogDocument $document
    ): ?BlogArticleHeaderMedia {
        if (!BlogDocumentTemplateRegistry::hasCover($document->template())) {
            return null;
        }
        $cover = $document->blocks()[0] ?? null;
        if (
            !is_array($cover)
            || ($cover['type'] ?? null) !== 'image'
            || ($cover['display'] ?? null) !== 'cover'
        ) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        $presentation = $cover['presentation'] ?? null;
        $resolvedPresentation = $this->resolveImagePresentation(
            $presentation,
            false
        );

        $image = $this->resolveImage($cover['media_asset_public_id']);
        $overlayColor = $resolvedPresentation['overlay_color'];

        return new BlogArticleHeaderMedia(
            $image,
            $cover['id'],
            $cover['alt'],
            $cover['title'],
            $cover['caption'],
            $resolvedPresentation['radius'],
            self::IMAGE_SIZES['cover'],
            $resolvedPresentation['radius_percent'],
            $resolvedPresentation['height_dvh'],
            $resolvedPresentation['object_fit'],
            $resolvedPresentation['object_position_y'],
            $resolvedPresentation['overlay_mode'],
            $overlayColor instanceof BlogPublicColor
                ? $overlayColor->value() : null,
            $resolvedPresentation['overlay_opacity']
        );
    }

    /** @param array<string, mixed> $block */
    private function renderBlock(
        array $block,
        ?array $imagePresentation = null
    ): string
    {
        return match ($block['type']) {
            'paragraph' => $this->renderParagraph($block),
            'heading' => $this->renderHeading($block),
            'list' => $this->renderList($block),
            'callout' => $this->renderCallout($block),
            'link' => $this->renderStandaloneLink($block),
            'image' => $this->renderImage($block, $imagePresentation),
            'video' => $this->renderYoutube($block),
            'cta' => $this->renderCta($block),
            'quote' => $this->renderQuote($block),
            'embed' => $this->renderEmbed($block),
            'separator' => $this->renderSeparator($block),
            default => throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            ),
        };
    }

    /** @param array<string, mixed> $block */
    private function renderParagraph(array $block): string
    {
        if (array_key_exists('html', $block)) {
            $id = $block['id'] ?? null;
            $html = $block['html'] ?? null;
            if (!is_string($id) || !is_string($html)) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            }

            return '<div id="' . $this->blockId($id)
                . '" class="blogDocument__text blogDocument__text--custom"'
                . ' data-ls-blog-custom="' . $this->escape($id) . '">'
                . $this->consentIframes->inert(
                    $this->customTextHtml->namespaceForRender($html, $id)
                )
                . '</div>';
        }
        if ($this->isTextFlow($block['content'])) {
            return '<div id="' . $this->blockId($block['id'])
                . '" class="blogDocument__text">'
                . $this->renderTextFlow($block['content'], $block['id'])
                . '</div>';
        }

        return '<p id="' . $this->blockId($block['id'])
            . '" class="blogDocument__paragraph">'
            . $this->renderInline($block['content']) . '</p>';
    }

    /** @param list<array<string, mixed>> $content */
    private function isTextFlow(array $content): bool
    {
        foreach ($content as $node) {
            $type = $node['type'] ?? null;
            if ($type === 'break') {
                continue;
            }

            return in_array(
                $type,
                ['paragraph', 'heading', 'list', 'quote', 'callout'],
                true
            );
        }

        return true;
    }

    /** @param list<array<string, mixed>> $content */
    private function renderTextFlow(array $content, string $blockId): string
    {
        $html = '';
        $headingIndex = 0;
        foreach ($content as $flowNode) {
            $type = $flowNode['type'] ?? null;
            if ($type === 'break') {
                $html .= '<br class="blogDocument__flowBreak">';
                continue;
            }
            if ($type === 'paragraph') {
                $html .= '<p class="blogDocument__textParagraph">'
                    . $this->renderInline($flowNode['content']) . '</p>';
                continue;
            }
            if ($type === 'heading') {
                $level = $flowNode['level'] ?? null;
                if (!is_int($level) || $level < 2 || $level > 6) {
                    throw new BlogRenderingException(
                        BlogRenderingException::INVALID_RENDER_STATE
                    );
                }
                $tag = 'h' . $level;
                $classes = 'blogDocument__textHeading '
                    . 'blogDocument__heading';
                if (array_key_exists('preset', $flowNode)) {
                    $preset = is_string($flowNode['preset'])
                        ? $this->headingPresets->find($flowNode['preset'])
                        : null;
                    if ($preset === null) {
                        throw new BlogRenderingException(
                            BlogRenderingException::INVALID_RENDER_STATE
                        );
                    }
                    $classes .= ' ' . $preset->ssrClass();
                }
                $html .= '<' . $tag . ' id="'
                    . $this->customTextHtml->flowHeadingId(
                        $blockId,
                        $headingIndex++
                    )
                    . '" class="' . $classes . '">'
                    . $this->renderInline($flowNode['content'])
                    . '</' . $tag . '>';
                continue;
            }
            if ($type === 'quote') {
                $classes = 'blogDocument__textQuote';
                if (array_key_exists('preset', $flowNode)) {
                    $presetClass = is_string($flowNode['preset'])
                        ? (self::QUOTE_PRESET_CLASSES[
                            $flowNode['preset']
                        ] ?? null)
                        : null;
                    if ($presetClass === null) {
                        throw new BlogRenderingException(
                            BlogRenderingException::INVALID_RENDER_STATE
                        );
                    }
                    $classes .= ' ' . $presetClass;
                }
                $html .= '<blockquote class="' . $classes . '">'
                    . '<p class="blogDocument__textQuoteContent">'
                    . $this->renderInline($flowNode['content'])
                    . '</p>';
                if (
                    ($flowNode['author'] ?? null) !== null
                    || ($flowNode['source'] ?? null) !== null
                ) {
                    $html .= '<footer class="blogDocument__quoteFooter">';
                    if (($flowNode['author'] ?? null) !== null) {
                        $html .= '<span class="blogDocument__quoteAuthor">'
                            . $this->escape($flowNode['author']) . '</span>';
                    }
                    if (($flowNode['source'] ?? null) !== null) {
                        $html .= '<cite class="blogDocument__quoteSource">'
                            . $this->escape($flowNode['source']) . '</cite>';
                    }
                    $html .= '</footer>';
                }
                $html .= '</blockquote>';
                continue;
            }
            if ($type === 'callout') {
                $classes = 'blogDocument__textCallout';
                if (array_key_exists('tone', $flowNode)) {
                    $toneClass = is_string($flowNode['tone'])
                        ? (self::CALLOUT_CLASSES[$flowNode['tone']] ?? null)
                        : null;
                    if ($toneClass === null) {
                        throw new BlogRenderingException(
                            BlogRenderingException::INVALID_RENDER_STATE
                        );
                    }
                    $classes .= ' ' . $toneClass;
                }
                $html .= '<aside class="' . $classes . '" '
                    . 'role="note"><p '
                    . 'class="blogDocument__textCalloutContent">'
                    . $this->renderInline($flowNode['content'])
                    . '</p></aside>';
                continue;
            }
            if ($type !== 'list') {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            }
            $tag = ($flowNode['ordered'] ?? null) === true ? 'ol' : 'ul';
            $classes = 'blogDocument__textList';
            if (array_key_exists('marker', $flowNode)) {
                $markerClass = is_string($flowNode['marker'])
                    ? (self::LIST_MARKER_CLASSES[$flowNode['marker']] ?? null)
                    : null;
                if ($markerClass === null) {
                    throw new BlogRenderingException(
                        BlogRenderingException::INVALID_RENDER_STATE
                    );
                }
                $classes .= ' ' . $markerClass;
            }
            $html .= '<' . $tag . ' class="' . $classes . '">';
            foreach ($flowNode['items'] as $item) {
                $itemId = array_key_exists('id', $item)
                    ? ' id="blog-item-' . $this->escape($item['id']) . '"'
                    : '';
                $html .= '<li' . $itemId
                    . ' class="blogDocument__textListItem">'
                    . $this->renderInline($item['content']) . '</li>';
            }
            $html .= '</' . $tag . '>';
        }

        return $html;
    }

    /** @param array<string, mixed> $block */
    private function renderHeading(array $block): string
    {
        $level = $block['level'] ?? null;
        if (!is_int($level) || $level < 2 || $level > 6) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        $tag = 'h' . $level;

        $classes = 'blogDocument__heading';
        if (array_key_exists('preset', $block)) {
            $preset = is_string($block['preset'])
                ? $this->headingPresets->find($block['preset'])
                : null;
            if ($preset === null) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            }
            $classes .= ' ' . $preset->ssrClass();
        }

        return '<' . $tag . ' id="' . $this->blockId($block['id'])
            . '" class="' . $classes . '">'
            . $this->renderInline($block['content'])
            . '</' . $tag . '>';
    }

    /** @param array<string, mixed> $block */
    private function renderList(array $block): string
    {
        $tag = match ($block['ordered']) {
            true => 'ol',
            false => 'ul',
            default => throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            ),
        };
        $classes = 'blogDocument__list';
        if (array_key_exists('marker', $block)) {
            $markerClass = is_string($block['marker'])
                ? (self::LIST_MARKER_CLASSES[$block['marker']] ?? null)
                : null;
            if ($markerClass === null) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            }
            $classes .= ' ' . $markerClass;
        }
        $html = '<' . $tag . ' id="' . $this->blockId($block['id'])
            . '" class="' . $classes . '">';
        foreach ($block['items'] as $item) {
            $html .= '<li id="blog-item-' . $this->escape($item['id'])
                . '" class="blogDocument__listItem">'
                . $this->renderInline($item['content']) . '</li>';
        }

        return $html . '</' . $tag . '>';
    }

    /** @param array<string, mixed> $block */
    private function renderCallout(array $block): string
    {
        $toneClass = self::CALLOUT_CLASSES[$block['tone']] ?? null;
        if ($toneClass === null) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        return '<aside id="' . $this->blockId($block['id'])
            . '" class="blogDocument__callout ' . $toneClass
            . '" role="note"><p class="blogDocument__calloutContent">'
            . $this->renderInline($block['content']) . '</p></aside>';
    }

    /** @param array<string, mixed> $block */
    private function renderStandaloneLink(array $block): string
    {
        return '<p id="' . $this->blockId($block['id'])
            . '" class="blogDocument__standaloneLink">'
            . $this->renderAnchor(
                $block['label'],
                $block['href'],
                $block['title'],
                $block['target'],
                'blogDocument__standaloneLinkAnchor'
            )
            . '</p>';
    }

    /**
     * @return array{
     *   radius: string|null,
     *   radius_percent: int|null,
     *   height_dvh: int|null,
     *   object_fit: string,
     *   object_position_y: string,
     *   overlay_mode: string|null,
     *   overlay_color: BlogPublicColor|null,
     *   overlay_opacity: int|null
     * }
     */
    private function resolveImagePresentation(
        mixed $presentation,
        bool $fullDirectSectionChild
    ): array {
        if ($presentation === null) {
            $presentation = [];
        }
        if (
            !is_array($presentation)
            || ($presentation !== [] && array_is_list($presentation))
        ) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        $hasRadius = array_key_exists('radius', $presentation);
        $hasRadiusPercent = array_key_exists(
            'radius_percent',
            $presentation
        );
        if ($hasRadius && $hasRadiusPercent) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        $radiusPercent = $hasRadiusPercent
            ? $presentation['radius_percent'] : null;
        if (
            $hasRadiusPercent
            && !BlogImagePresentationPolicy::supportsRadiusPercent(
                $radiusPercent
            )
        ) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        $radius = $hasRadiusPercent
            ? null
            : BlogImageRadiusPreset::resolve(
                $hasRadius ? $presentation['radius'] : null,
                $fullDirectSectionChild
            );
        if (!$hasRadiusPercent && $radius === null) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        $heightDvh = $presentation['height_dvh'] ?? null;
        if (
            $heightDvh !== null
            && !BlogImagePresentationPolicy::supportsHeightDvh($heightDvh)
        ) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        $objectFit = $presentation['object_fit']
            ?? BlogImagePresentationPolicy::DEFAULT_OBJECT_FIT;
        $objectPositionY = $presentation['object_position_y']
            ?? BlogImagePresentationPolicy::DEFAULT_OBJECT_POSITION_Y;
        if (
            !BlogImagePresentationPolicy::supportsObjectFit($objectFit)
            || !BlogImagePresentationPolicy::supportsObjectPositionY(
                $objectPositionY
            )
        ) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        $overlayKeys = [
            'overlay_mode',
            'overlay_color',
            'overlay_opacity',
        ];
        $overlayKeyCount = count(array_filter(
            $overlayKeys,
            static fn (string $key): bool => array_key_exists(
                $key,
                $presentation
            )
        ));
        if ($overlayKeyCount !== 0 && $overlayKeyCount !== 3) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        $overlayMode = null;
        $overlayColor = null;
        $overlayOpacity = null;
        if ($overlayKeyCount === 3) {
            $overlayMode = $presentation['overlay_mode'];
            $overlayOpacity = $presentation['overlay_opacity'];
            try {
                $overlayColor = BlogPublicColor::fromInput(
                    $presentation['overlay_color']
                );
            } catch (InvalidArgumentException) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            }
            if (
                !BlogImagePresentationPolicy::supportsOverlayMode(
                    $overlayMode
                )
                || !BlogImagePresentationPolicy::supportsOverlayOpacity(
                    $overlayOpacity
                )
            ) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            }
        }

        return [
            'radius' => $radius,
            'radius_percent' => $radiusPercent,
            'height_dvh' => $heightDvh,
            'object_fit' => $objectFit,
            'object_position_y' => $objectPositionY,
            'overlay_mode' => $overlayMode,
            'overlay_color' => $overlayColor,
            'overlay_opacity' => $overlayOpacity,
        ];
    }

    /** @param array<string, mixed> $module */
    private function imageScopedCss(array $module): string
    {
        $presentation = $module['presentation'] ?? null;
        if ($presentation === null) {
            return '';
        }
        $id = $module['id'] ?? null;
        if (!is_string($id)) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        $selector = '#' . $this->escapeClassToken($this->blockId($id));
        $resolved = $this->resolveImagePresentation($presentation, false);
        $css = '';
        if (is_int($resolved['height_dvh'])) {
            $height = $resolved['height_dvh'];
            $css .= $selector . ' .blogDocument__imageElement{height:'
                . $height . 'vh;height:' . $height . 'dvh;}';
        }
        if (is_int($resolved['radius_percent'])) {
            $radius = $resolved['radius_percent'];
            $css .= $selector . ' .blogDocument__picture,' . $selector
                . ' .blogDocument__imageElement{border-radius:'
                . $radius . '%;}';
        }
        if (
            $resolved['overlay_color'] instanceof BlogPublicColor
            && is_int($resolved['overlay_opacity'])
        ) {
            $color = $resolved['overlay_color'];
            $colorCss = $color->isThemeToken()
                ? 'var(--ls-blog-' . $color->value() . ')'
                : $color->value();
            $opacity = rtrim(
                rtrim(
                    number_format(
                        $resolved['overlay_opacity'] / 100,
                        2,
                        '.',
                        ''
                    ),
                    '0'
                ),
                '.'
            );
            $css .= $selector
                . ' .blogDocument__picture::after{background-color:'
                . $colorCss . ';opacity:' . $opacity . ';}';
        }

        return $css;
    }

    /** @param array<string, mixed> $block */
    private function renderImage(
        array $block,
        ?array $presentation = null,
        ?BlogResolvedImage $resolvedImage = null
    ): string
    {
        $displayClass = self::IMAGE_CLASSES[$block['display']] ?? null;
        $sizes = self::IMAGE_SIZES[$block['display']] ?? null;
        $resolvedPresentation = $presentation
            ?? $this->resolveImagePresentation([], false);
        $resolvedRadius = $resolvedPresentation['radius'];
        $radiusPercent = $resolvedPresentation['radius_percent'];
        $radiusClass = is_string($resolvedRadius)
            ? (self::IMAGE_RADIUS_CLASSES[$resolvedRadius] ?? null)
            : ($radiusPercent === null
                ? null : 'blogDocument__image--radius-percent');
        $objectFit = $resolvedPresentation['object_fit'];
        $objectPositionY = $resolvedPresentation['object_position_y'];
        $objectFitClass = is_string($objectFit)
            ? (self::IMAGE_OBJECT_FIT_CLASSES[$objectFit] ?? null)
            : null;
        $objectPositionClass = is_string($objectPositionY)
            ? (self::IMAGE_OBJECT_POSITION_CLASSES[$objectPositionY] ?? null)
            : null;
        if (
            $displayClass === null
            || $sizes === null
            || $radiusClass === null
            || $objectFitClass === null
            || $objectPositionClass === null
        ) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        $image = $resolvedImage
            ?? $this->resolveImage($block['media_asset_public_id']);
        if ($image->mediaAssetPublicId() !== $block['media_asset_public_id']) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_IMAGE_PRESENTATION
            );
        }
        $srcset = implode(', ', array_map(
            fn (BlogResolvedImageCandidate $candidate): string =>
                $this->escape($candidate->url()) . ' ' . $candidate->width() . 'w',
            $image->candidates()
        ));
        $title = $block['title'] === null
            ? ''
            : ' title="' . $this->escape($block['title']) . '"';
        $loading = $block['display'] === 'cover'
            ? ' loading="eager" fetchpriority="high" decoding="async"'
            : ' loading="lazy" decoding="async"';

        $classes = 'blogDocument__image ' . $displayClass . ' '
            . $radiusClass . ' ' . $objectFitClass . ' '
            . $objectPositionClass;
        $attributes = ' data-blog-image-object-fit="'
            . $this->escape($objectFit) . '"'
            . ' data-blog-image-object-position-y="'
            . $this->escape($objectPositionY) . '"';
        if (is_string($resolvedRadius)) {
            $attributes .= ' data-blog-image-radius="'
                . $this->escape($resolvedRadius) . '"';
        } else {
            $attributes .= ' data-blog-image-radius-percent="'
                . $radiusPercent . '"';
        }
        $heightDvh = $resolvedPresentation['height_dvh'];
        if (is_int($heightDvh)) {
            $classes .= ' blogDocument__image--height-custom';
            $attributes .= ' data-blog-image-height-dvh="'
                . $heightDvh . '"';
        }
        $overlayMode = $resolvedPresentation['overlay_mode'];
        $overlayColor = $resolvedPresentation['overlay_color'];
        $overlayOpacity = $resolvedPresentation['overlay_opacity'];
        if (
            is_string($overlayMode)
            && $overlayColor instanceof BlogPublicColor
            && is_int($overlayOpacity)
        ) {
            $overlayClass = self::IMAGE_OVERLAY_MODE_CLASSES[
                $overlayMode
            ] ?? null;
            if ($overlayClass === null) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            }
            $classes .= ' blogDocument__image--overlay ' . $overlayClass;
            $attributes .= ' data-blog-image-overlay-mode="'
                . $this->escape($overlayMode) . '"'
                . ' data-blog-image-overlay-color="'
                . $this->escape($overlayColor->value()) . '"'
                . ' data-blog-image-overlay-opacity="'
                . $overlayOpacity . '"';
        }

        $html = '<figure id="' . $this->blockId($block['id'])
            . '" class="' . $classes . '"' . $attributes . '>'
            . '<picture class="blogDocument__picture">'
            . '<source type="image/avif" srcset="' . $srcset
            . '" sizes="' . $sizes . '">'
            . '<img class="blogDocument__imageElement" src="'
            . $this->escape($image->sourceUrl()) . '" width="'
            . $image->width() . '" height="' . $image->height()
            . '" alt="' . $this->escape($block['alt']) . '"'
            . $title . $loading . '>'
            . '</picture>';
        if ($block['caption'] !== null) {
            $html .= '<figcaption class="blogDocument__imageCaption">'
                . $this->escape($block['caption']) . '</figcaption>';
        }

        return $html . '</figure>';
    }

    /** @param array<string, mixed> $block */
    private function renderYoutube(array $block): string
    {
        $captionId = 'blog-video-caption-' . $this->escape($block['id']);
        $watchUrl = 'https://www.youtube.com/watch?v=' . $block['video_id'];
        if ($block['start_seconds'] > 0) {
            $watchUrl .= '&t=' . $block['start_seconds'] . 's';
        }

        return '<figure id="' . $this->blockId($block['id'])
            . '" class="blogDocument__video">'
            . '<div class="blogDocument__liteYoutube" data-blog-lite-youtube'
            . ' data-video-id="' . $this->escape($block['video_id']) . '"'
            . ' data-start-seconds="' . $block['start_seconds'] . '">'
            . '<a class="blogDocument__videoTrigger" href="'
            . $this->escape($watchUrl)
            . '" target="_blank" rel="noopener noreferrer"'
            . ' aria-labelledby="' . $captionId . '" data-blog-youtube-play>'
            . '<span class="blogDocument__videoPlay" aria-hidden="true">&#9654;</span>'
            . '</a></div>'
            . '<figcaption id="' . $captionId
            . '" class="blogDocument__videoCaption">'
            . $this->escape($block['title']) . '</figcaption></figure>';
    }

    /** @param array<string, mixed> $block */
    private function renderCta(array $block): string
    {
        $variantClass = self::CTA_CLASSES[$block['variant']] ?? null;
        if ($variantClass === null) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        return '<p id="' . $this->blockId($block['id'])
            . '" class="blogDocument__cta ' . $variantClass . '">'
            . $this->renderAnchor(
                $block['label'],
                $block['href'],
                $block['title'],
                $block['target'],
                'blogDocument__ctaLink'
            )
            . '</p>';
    }

    /** @param array<string, mixed> $block */
    private function renderQuote(array $block): string
    {
        $presetClass = is_string($block['preset'] ?? null)
            ? (self::QUOTE_PRESET_CLASSES[$block['preset']] ?? null)
            : null;
        if ($presetClass === null) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        $html = '<blockquote id="' . $this->blockId($block['id'])
            . '" class="blogDocument__quote ' . $presetClass . '">'
            . '<p class="blogDocument__quoteContent">'
            . $this->renderInline($block['content']) . '</p>';
        if ($block['author'] !== null || $block['source'] !== null) {
            $html .= '<footer class="blogDocument__quoteFooter">';
            if ($block['author'] !== null) {
                $html .= '<span class="blogDocument__quoteAuthor">'
                    . $this->escape($block['author']) . '</span>';
            }
            if ($block['source'] !== null) {
                $html .= '<cite class="blogDocument__quoteSource">'
                    . $this->escape($block['source']) . '</cite>';
            }
            $html .= '</footer>';
        }

        return $html . '</blockquote>';
    }

    /** @param array<string, mixed> $block */
    private function renderEmbed(array $block): string
    {
        if (!is_string($block['html'] ?? null) || $block['html'] === '') {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        $html = '<figure id="' . $this->blockId($block['id'])
            . '" class="blogDocument__embed" data-ls-blog-custom="'
            . $this->escape($block['id']) . '"><div '
            . 'class="blogDocument__embedContent">'
            . $this->consentIframes->inert(
                $this->embedHtml->namespaceForRender(
                    $block['html'],
                    $block['id']
                )
            ) . '</div>';
        if ($block['caption'] !== null) {
            $html .= '<figcaption class="blogDocument__embedCaption">'
                . $this->escape($block['caption']) . '</figcaption>';
        }

        return $html . '</figure>';
    }

    /** @param array<string, mixed> $block */
    private function renderSeparator(array $block): string
    {
        $styleClass = is_string($block['line_style'] ?? null)
            ? (self::SEPARATOR_STYLE_CLASSES[$block['line_style']] ?? null)
            : null;
        $thicknessClass = is_string($block['thickness'] ?? null)
            ? (self::SEPARATOR_THICKNESS_CLASSES[$block['thickness']] ?? null)
            : null;
        try {
            $color = BlogPublicColor::fromInput($block['color'] ?? null);
        } catch (InvalidArgumentException) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        if ($styleClass === null || $thicknessClass === null) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        $colorClass = $color->isThemeToken()
            ? ' blogDocument__separator--' . $color->value()
            : ' blogDocument__separator--rgba';
        $colorAttribute = $color->isThemeToken()
            ? ' data-blog-separator-color="' . $this->escape($color->value()) . '"'
            : ' data-blog-separator-rgba="' . $this->escape($color->value()) . '"';

        return '<hr id="' . $this->blockId($block['id'])
            . '" class="blogDocument__separator ' . $styleClass . ' '
            . $thicknessClass . $colorClass . '"' . $colorAttribute . '>';
    }

    /** @param list<array<string, mixed>> $content */
    private function renderInline(array $content): string
    {
        $html = '';
        foreach ($content as $node) {
            if ($node['type'] === 'break') {
                $html .= '<br>';
                continue;
            }
            if (!in_array($node['type'], ['text', 'link'], true)) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            }

            $text = $this->escape($node['text']);
            $semanticMarks = [];
            $styleClasses = [];
            $styleAttributes = '';
            foreach ($node['marks'] as $mark) {
                if (isset(self::INLINE_STYLE_CLASSES[$mark])) {
                    $styleClasses[] = self::INLINE_STYLE_CLASSES[$mark];
                    continue;
                }
                $dynamic = $this->inlineColorProjection($mark);
                if ($dynamic !== null) {
                    $styleClasses[] = $dynamic[0];
                    $styleAttributes .= $dynamic[1];
                    continue;
                }
                $semanticMarks[] = $mark;
            }
            if ($styleClasses !== [] || $styleAttributes !== '') {
                $text = '<span class="blogDocument__inline '
                    . implode(' ', $styleClasses) . '"' . $styleAttributes
                    . '>' . $text . '</span>';
            }
            foreach (array_reverse($semanticMarks) as $mark) {
                $tag = match ($mark) {
                    'strong' => 'strong',
                    'em' => 'em',
                    'underline' => 'u',
                    default => throw new BlogRenderingException(
                        BlogRenderingException::INVALID_RENDER_STATE
                    ),
                };
                $class = $mark === 'underline'
                    ? ' class="blogDocument__inlineUnderline"'
                    : '';
                $text = '<' . $tag . $class . '>' . $text
                    . '</' . $tag . '>';
            }
            if ($node['type'] === 'link') {
                $text = $this->renderAnchor(
                    $text,
                    $node['href'],
                    $node['title'],
                    $node['target'],
                    'blogDocument__inlineLink',
                    false
                );
            }
            $html .= $text;
        }

        return $html;
    }

    /** @return array{0: string, 1: string}|null */
    private function inlineColorProjection(mixed $mark): ?array
    {
        if (!is_string($mark)) {
            return null;
        }
        $textColor = str_starts_with($mark, 'text-rgba:');
        $backgroundColor = str_starts_with($mark, 'background-rgba:');
        if (!$textColor && !$backgroundColor) {
            return null;
        }
        $prefix = $textColor ? 'text-rgba:' : 'background-rgba:';
        try {
            $color = BlogPublicColor::fromInput(
                substr($mark, strlen($prefix))
            );
        } catch (InvalidArgumentException) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        if ($color->kind() !== BlogPublicColor::RGBA) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        return $textColor
            ? [
                'blogDocument__inline--text-rgba',
                ' data-blog-inline-text-rgba="'
                    . $this->escape($color->value()) . '"',
            ]
            : [
                'blogDocument__inline--background-rgba',
                ' data-blog-inline-background-rgba="'
                    . $this->escape($color->value()) . '"',
            ];
    }

    private function renderAnchor(
        string $label,
        string $href,
        ?string $title,
        string $target,
        string $className,
        bool $escapeLabel = true
    ): string {
        if (!in_array($target, ['same', 'new'], true)) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        $attributes = ' class="' . $className . '" href="'
            . $this->escape($href) . '"';
        if ($title !== null) {
            $attributes .= ' title="' . $this->escape($title) . '"';
        }
        if ($target === 'new') {
            $attributes .= ' target="_blank" rel="noopener noreferrer"';
        }

        return '<a' . $attributes . '>'
            . ($escapeLabel ? $this->escape($label) : $label)
            . '</a>';
    }

    private function resolveImage(string $mediaAssetPublicId): BlogResolvedImage
    {
        try {
            $image = $this->imageResolver->resolve($mediaAssetPublicId);
        } catch (Throwable) {
            throw new BlogRenderingException(
                BlogRenderingException::MEDIA_UNAVAILABLE
            );
        }
        if (
            !$image instanceof BlogResolvedImage
            || $image->mediaAssetPublicId() !== $mediaAssetPublicId
        ) {
            throw new BlogRenderingException(
                BlogRenderingException::MEDIA_UNAVAILABLE
            );
        }

        return $image;
    }

    private function blockId(string $id): string
    {
        return 'blog-block-' . $this->escape($id);
    }

    private function containerId(string $id): string
    {
        return 'blog-container-' . $this->escape($id);
    }

    private function escapeClassToken(string $value): string
    {
        if (preg_match('/\A[a-z0-9-]+\z/', $value) !== 1) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        return $value;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );
    }
}
