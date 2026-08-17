<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

use App\Core\Blog\StructuredContent\Presentation\BlogHeadingPresetCatalog;
use App\Core\Blog\StructuredContent\Presentation\BlogH1ModuleCatalog;
use App\Core\Blog\StructuredContent\Presentation\BlogHeaderSelection;
use App\Core\Blog\StructuredContent\Presentation\BlogHeroCatalog;
use App\Core\Blog\StructuredContent\Presentation\BlogImagePresentationPolicy;
use App\Core\Blog\StructuredContent\Presentation\BlogImageRadiusPreset;
use App\Core\Blog\StructuredContent\Presentation\BlogPresentationSize;
use App\Core\Blog\StructuredContent\Presentation\BlogPublicColor;
use InvalidArgumentException;
use JsonException;

/** Strict structural and semantic validator for document schemas v1 and v2. */
final class BlogDocumentValidator
{
    public const MAX_INLINE_NODES = 500;
    public const MAX_INLINE_TEXT_BYTES = 20_000;
    /** Aggregate visible text accepted by one unified V2 Text module. */
    public const MAX_TEXT_MODULE_BYTES = 200_000;
    public const MAX_LABEL_BYTES = 255;
    public const MAX_ALT_BYTES = 500;
    public const MAX_TITLE_BYTES = 500;
    public const MAX_CAPTION_BYTES = 2_000;
    public const MAX_URL_BYTES = 2_048;
    public const MAX_VIDEO_START_SECONDS = 86_400;

    private const UUID_V4_PATTERN =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
    private const V1_MARK_ORDER = ['strong', 'em'];
    private const MARK_ORDER = [
        'strong',
        'em',
        'underline',
        'size-small',
        'size-large',
        'size-xlarge',
        'text-color00',
        'text-color01',
        'text-color02',
        'text-color03',
        'text-color04',
        'text-color05',
        'text-basic-red',
        'text-basic-orange',
        'text-basic-yellow',
        'text-basic-green',
        'text-basic-blue',
        'text-basic-purple',
        'text-basic-pink',
        'text-basic-gray',
        'background-color00',
        'background-color01',
        'background-color02',
        'background-color03',
        'background-color04',
        'background-color05',
        'background-basic-red',
        'background-basic-orange',
        'background-basic-yellow',
        'background-basic-green',
        'background-basic-blue',
        'background-basic-purple',
        'background-basic-pink',
        'background-basic-gray',
    ];
    private const LAYOUT_PRESET_COLUMNS = [
        '1' => 1,
        '2-50-50' => 2,
        '2-40-60' => 2,
        '2-60-40' => 2,
        '2-30-70' => 2,
        '2-70-30' => 2,
        '3' => 3,
        '4' => 4,
        '5' => 5,
    ];
    private const PRESENTATION_WIDTHS = ['full', '80', '60', '40'];
    private const PRESENTATION_ALIGNS = ['start', 'center', 'end'];
    private const PRESENTATION_TEXT_ALIGNS = [
        'start',
        'center',
        'end',
        'justify',
    ];
    private const PRESENTATION_FONT_WEIGHTS = [
        'default', 'regular', 'medium', 'semibold', 'bold',
    ];
    private const QUOTE_PRESETS = ['default', 'accent', 'minimal'];
    private const UNORDERED_LIST_MARKERS = ['disc', 'circle', 'square'];
    private const ORDERED_LIST_MARKERS = [
        'decimal', 'lower-alpha', 'upper-alpha',
    ];
    private const SEPARATOR_LINE_STYLES = [
        'solid', 'dashed', 'dotted', 'double',
    ];
    private const SEPARATOR_THICKNESSES = ['thin', 'medium', 'thick'];
    private const PRESENTATION_SPACINGS = ['none', 's', 'm', 'l', 'xl'];

    private readonly BlogDocumentTemplateRegistry $templates;
    private readonly BlogEmbedHtmlSanitizer $embedSanitizer;
    private readonly BlogHeadingPresetCatalog $headingPresets;
    private readonly BlogHeroCatalog $heroes;
    private readonly BlogH1ModuleCatalog $h1Modules;
    private readonly BlogCustomTextHtmlSanitizer $customTextHtml;
    private readonly BlogCustomTextCssSanitizer $customTextCss;
    private readonly BlogHeadingLevelPolicy $headingLevels;

    public function __construct(
        ?BlogDocumentTemplateRegistry $templates = null,
        ?BlogEmbedHtmlSanitizer $embedSanitizer = null,
        ?BlogHeadingPresetCatalog $headingPresets = null,
        private readonly bool $allowIncompleteLayout = false,
        ?BlogHeroCatalog $heroes = null,
        ?BlogH1ModuleCatalog $h1Modules = null,
        ?BlogCustomTextHtmlSanitizer $customTextHtml = null,
        ?BlogCustomTextCssSanitizer $customTextCss = null,
        ?BlogHeadingLevelPolicy $headingLevels = null
    ) {
        $this->templates = $templates ?? new BlogDocumentTemplateRegistry();
        $this->embedSanitizer = $embedSanitizer
            ?? new BlogEmbedHtmlSanitizer();
        $this->headingPresets = $headingPresets
            ?? new BlogHeadingPresetCatalog();
        $this->heroes = $heroes ?? new BlogHeroCatalog();
        $this->h1Modules = $h1Modules ?? new BlogH1ModuleCatalog();
        $this->customTextHtml = $customTextHtml
            ?? new BlogCustomTextHtmlSanitizer();
        $this->customTextCss = $customTextCss
            ?? new BlogCustomTextCssSanitizer();
        $this->headingLevels = $headingLevels
            ?? new BlogHeadingLevelPolicy();
    }

    public function forDrafts(): self
    {
        if ($this->allowIncompleteLayout) {
            return $this;
        }

        return new self(
            $this->templates,
            $this->embedSanitizer,
            $this->headingPresets,
            true,
            $this->heroes,
            $this->h1Modules,
            $this->customTextHtml,
            $this->customTextCss,
            $this->headingLevels
        );
    }

    /**
     * @param array<string, mixed> $document
     * @return array{
     *   schema: string,
     *   version: int,
     *   template: string,
     *   header?: array{hero: ?string, h1_module: string},
     *   blocks: list<array<string, mixed>>
     * }
     */
    public function validate(array $document): array
    {
        $hasHeader = array_key_exists('header', $document);
        $expectedKeys = ['schema', 'version', 'template', 'blocks'];
        if ($hasHeader) {
            $expectedKeys[] = 'header';
        }
        $this->assertExactKeys(
            $document,
            $expectedKeys,
            BlogDocumentException::INVALID_DOCUMENT
        );
        if ($document['schema'] !== BlogDocument::SCHEMA) {
            throw new BlogDocumentException(
                BlogDocumentException::UNSUPPORTED_SCHEMA
            );
        }
        if ($document['version'] === BlogDocument::LAYOUT_VERSION) {
            return $this->validateLayoutDocument($document);
        }
        if ($hasHeader) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_HEADER_PRESENTATION
            );
        }
        if ($document['version'] !== BlogDocument::VERSION) {
            throw new BlogDocumentException(
                BlogDocumentException::UNSUPPORTED_SCHEMA
            );
        }

        return $this->validateFlatDocument($document);
    }

    /**
     * @param array<string, mixed> $document
     * @return array{
     *   schema: string,
     *   version: int,
     *   template: string,
     *   header?: array{hero: ?string, h1_module: string},
     *   blocks: list<array<string, mixed>>
     * }
     */
    private function validateFlatDocument(array $document): array
    {
        if (!is_string($document['template'])) {
            throw new BlogDocumentException(
                BlogDocumentException::UNSUPPORTED_TEMPLATE
            );
        }
        $template = $this->singleLine(
            $document['template'],
            64,
            BlogDocumentException::UNSUPPORTED_TEMPLATE
        );
        $this->templates->assertSupported($template);

        $rawBlocks = $document['blocks'];
        if (
            !is_array($rawBlocks)
            || !array_is_list($rawBlocks)
            || count($rawBlocks) > BlogDocument::MAX_BLOCKS
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_DOCUMENT
            );
        }

        $seenIds = [];
        $blocks = [];
        foreach ($rawBlocks as $block) {
            $blocks[] = $this->block($block, $seenIds);
        }
        $this->assertV1InlineMarks($blocks);
        $this->assertHeadingHierarchy($blocks);
        $this->templates->assertDocumentContract($template, $blocks);

        $normalized = [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => $template,
            'blocks' => $blocks,
        ];
        $this->assertCanonicalSize($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $document
     * @return array{
     *   schema: string,
     *   version: int,
     *   template: string,
     *   blocks: list<array<string, mixed>>
     * }
     */
    private function validateLayoutDocument(array $document): array
    {
        if (!is_string($document['template'])) {
            throw new BlogDocumentException(
                BlogDocumentException::UNSUPPORTED_TEMPLATE
            );
        }
        $template = $this->singleLine(
            $document['template'],
            64,
            BlogDocumentException::UNSUPPORTED_TEMPLATE
        );
        $this->templates->assertSupported($template);
        if (
            !is_array($document['blocks'])
            || !array_is_list($document['blocks'])
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_DOCUMENT
            );
        }

        $seenIds = [];
        $nodeCount = 0;
        $blocks = [];
        $rawBlocks = $document['blocks'];
        $position = 0;
        if (BlogDocumentTemplateRegistry::hasCover($template)) {
            $cover = $rawBlocks[0] ?? null;
            if (!is_array($cover) || ($cover['type'] ?? null) !== 'image') {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_TEMPLATE_CONTRACT
                );
            }
            $cover = $this->layoutModule($cover, $seenIds, $nodeCount);
            if (($cover['display'] ?? null) !== 'cover') {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_TEMPLATE_CONTRACT
                );
            }
            $blocks[] = $cover;
            $position = 1;
        }

        for ($count = count($rawBlocks); $position < $count; ++$position) {
            $raw = $rawBlocks[$position];
            if (!is_array($raw) || ($raw['type'] ?? null) !== 'section') {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_DOCUMENT
                );
            }
            $blocks[] = $this->layoutSection(
                $raw,
                $seenIds,
                $nodeCount
            );
        }
        if (!$this->allowIncompleteLayout && ($blocks === [] || (
            BlogDocumentTemplateRegistry::hasCover($template)
            && count($blocks) === 1
        ))) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_DOCUMENT
            );
        }

        $normalized = [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => $template,
        ];
        if (array_key_exists('header', $document)) {
            $normalized['header'] = $this->headerSelection(
                $document['header'],
                $template
            );
        }
        $normalized['blocks'] = $blocks;
        $expectedCoverCount = BlogDocumentTemplateRegistry::hasCover($template)
            ? 1 : 0;
        if ($this->layoutCoverCount($blocks) !== $expectedCoverCount) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_TEMPLATE_CONTRACT
            );
        }
        $this->assertCanonicalSize($normalized);

        return $normalized;
    }

    /** @return array{hero: ?string, h1_module: string} */
    private function headerSelection(mixed $value, string $template): array
    {
        if (!is_array($value)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_HEADER_PRESENTATION
            );
        }
        try {
            $selection = BlogHeaderSelection::fromArray(
                $value,
                $this->heroes,
                $this->h1Modules
            );
        } catch (InvalidArgumentException) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_HEADER_PRESENTATION
            );
        }
        if (
            BlogDocumentTemplateRegistry::hasCover($template)
                !== $selection->hasHero()
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_TEMPLATE_CONTRACT
            );
        }

        return $selection->toArray();
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, true> $seenIds
     * @return array<string, mixed>
     */
    private function layoutSection(
        array $node,
        array &$seenIds,
        int &$nodeCount
    ): array {
        $hasPresentation = array_key_exists('presentation', $node);
        $this->assertExactKeys(
            $node,
            $hasPresentation
                ? ['id', 'type', 'children', 'presentation']
                : ['id', 'type', 'children'],
            BlogDocumentException::INVALID_BLOCK
        );
        if (
            $node['type'] !== 'section'
            || !is_array($node['children'])
            || !array_is_list($node['children'])
            || $node['children'] === []
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $this->incrementLayoutNode($nodeCount);
        $id = $this->structuralId($node['id'], $seenIds);

        $first = $node['children'][0] ?? null;
        if (!is_array($first) || array_is_list($first)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_HEADING_HIERARCHY
            );
        }
        $heading = $this->layoutModule($first, $seenIds, $nodeCount);
        if (
            ($heading['type'] ?? null) !== 'heading'
            && !$this->unifiedTextStartsWithHeading($heading)
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_HEADING_HIERARCHY
            );
        }
        $children = [$heading];
        foreach (array_slice($node['children'], 1) as $child) {
            if (!is_array($child) || array_is_list($child)) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_BLOCK
                );
            }
            $type = $child['type'] ?? null;
            if ($type === 'article') {
                $children[] = $this->layoutArticle(
                    $child,
                    $seenIds,
                    $nodeCount
                );
                continue;
            }
            if ($type === 'div') {
                $children[] = $this->layoutDivision(
                    $child,
                    $seenIds,
                    $nodeCount,
                    1,
                    false
                );
                continue;
            }
            $module = $this->layoutModule($child, $seenIds, $nodeCount);
            $children[] = $module;
        }

        $normalized = [
            'id' => $id,
            'type' => 'section',
            'children' => $children,
        ];
        if ($hasPresentation) {
            $normalized['presentation'] = $this->containerPresentation(
                $node['presentation'],
                'section'
            );
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, true> $seenIds
     * @return array<string, mixed>
     */
    private function layoutArticle(
        array $node,
        array &$seenIds,
        int &$nodeCount
    ): array {
        $hasPresentation = array_key_exists('presentation', $node);
        $this->assertExactKeys(
            $node,
            $hasPresentation
                ? ['id', 'type', 'layout', 'presentation']
                : ['id', 'type', 'layout'],
            BlogDocumentException::INVALID_BLOCK
        );
        if ($node['type'] !== 'article') {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $this->incrementLayoutNode($nodeCount);
        $normalized = [
            'id' => $this->structuralId($node['id'], $seenIds),
            'type' => 'article',
            'layout' => $this->layoutGrid(
                $node['layout'],
                $seenIds,
                $nodeCount,
                true,
                0
            ),
        ];
        if ($hasPresentation) {
            $normalized['presentation'] = $this->containerPresentation(
                $node['presentation'],
                'article'
            );
        }
        return $normalized;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, true> $seenIds
     * @return array<string, mixed>
     */
    private function layoutDivision(
        array $node,
        array &$seenIds,
        int &$nodeCount,
        int $depth,
        bool $insideArticle
    ): array {
        if ($depth < 1 || $depth > 3) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $hasPresentation = array_key_exists('presentation', $node);
        $this->assertExactKeys(
            $node,
            $hasPresentation
                ? ['id', 'type', 'layout', 'presentation']
                : ['id', 'type', 'layout'],
            BlogDocumentException::INVALID_BLOCK
        );
        if ($node['type'] !== 'div') {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $this->incrementLayoutNode($nodeCount);

        $normalized = [
            'id' => $this->structuralId($node['id'], $seenIds),
            'type' => 'div',
            'layout' => $this->layoutGrid(
                $node['layout'],
                $seenIds,
                $nodeCount,
                $insideArticle,
                $depth
            ),
        ];
        if ($hasPresentation) {
            $normalized['presentation'] = $this->containerPresentation(
                $node['presentation'],
                'div'
            );
        }

        return $normalized;
    }

    /**
     * @param mixed $layout
     * @param array<string, true> $seenIds
     * @return array{preset: string, columns: list<array{id: string, children: list<array<string, mixed>>}>}
     */
    private function layoutGrid(
        mixed $layout,
        array &$seenIds,
        int &$nodeCount,
        bool $insideArticle,
        int $divDepth
    ): array {
        if (!is_array($layout) || array_is_list($layout)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $this->assertExactKeys(
            $layout,
            ['preset', 'columns'],
            BlogDocumentException::INVALID_BLOCK
        );
        $preset = $layout['preset'] ?? null;
        $columns = $layout['columns'] ?? null;
        if (
            !is_string($preset)
            || !isset(self::LAYOUT_PRESET_COLUMNS[$preset])
            || !is_array($columns)
            || !array_is_list($columns)
            || count($columns) !== self::LAYOUT_PRESET_COLUMNS[$preset]
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }

        $normalizedColumns = [];
        foreach ($columns as $column) {
            if (!is_array($column) || array_is_list($column)) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_BLOCK
                );
            }
            $this->assertExactKeys(
                $column,
                ['id', 'children'],
                BlogDocumentException::INVALID_BLOCK
            );
            if (
                !is_array($column['children'])
                || !array_is_list($column['children'])
            ) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_BLOCK
                );
            }
            $children = [];
            foreach ($column['children'] as $child) {
                if (!is_array($child) || array_is_list($child)) {
                    throw new BlogDocumentException(
                        BlogDocumentException::INVALID_BLOCK
                    );
                }
                $type = $child['type'] ?? null;
                if ($type === 'div') {
                    if ($divDepth >= 3) {
                        throw new BlogDocumentException(
                            BlogDocumentException::INVALID_BLOCK
                        );
                    }
                    $children[] = $this->layoutDivision(
                        $child,
                        $seenIds,
                        $nodeCount,
                        $divDepth + 1,
                        $insideArticle
                    );
                    continue;
                }
                if (in_array($type, ['section', 'article'], true)) {
                    throw new BlogDocumentException(
                        BlogDocumentException::INVALID_BLOCK
                    );
                }
                $module = $this->layoutModule(
                    $child,
                    $seenIds,
                    $nodeCount
                );
                $children[] = $module;
            }
            $normalizedColumns[] = [
                'id' => $this->structuralId($column['id'], $seenIds),
                'children' => $children,
            ];
        }

        return ['preset' => $preset, 'columns' => $normalizedColumns];
    }

    /**
     * @param array<string, mixed> $module
     * @param array<string, true> $seenIds
     * @return array<string, mixed>
     */
    private function layoutModule(
        array $module,
        array &$seenIds,
        int &$nodeCount
    ): array {
        if (!array_key_exists('presentation', $module)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $presentation = $module['presentation'];
        unset($module['presentation']);
        $normalized = $this->block($module, $seenIds, true);
        $normalized['presentation'] = $this->presentation(
            $presentation,
            (string) ($normalized['type'] ?? '')
        );
        $this->incrementLayoutNode($nodeCount);

        return $normalized;
    }

    /** @return array<string, string> */
    private function containerPresentation(
        mixed $presentation,
        string $containerType
    ): array
    {
        if (!is_array($presentation) || array_is_list($presentation)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $allowed = ['background'];
        if (in_array($containerType, ['article', 'div'], true)) {
            $allowed[] = 'width';
            $allowed[] = 'align';
        }
        foreach (array_keys($presentation) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_BLOCK
                );
            }
        }
        $hasBackground = array_key_exists('background', $presentation);
        $hasWidth = array_key_exists('width', $presentation);
        $hasAlign = array_key_exists('align', $presentation);
        if (
            $presentation === []
            || $hasWidth !== $hasAlign
            || ($containerType === 'section' && ($hasWidth || $hasAlign))
            || ($hasWidth && (
                !is_string($presentation['width'])
                || !in_array(
                    $presentation['width'],
                    self::PRESENTATION_WIDTHS,
                    true
                )
                || !is_string($presentation['align'])
                || !in_array(
                    $presentation['align'],
                    self::PRESENTATION_ALIGNS,
                    true
                )
            ))
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }

        $normalized = [];
        if ($hasWidth) {
            $normalized['width'] = $presentation['width'];
            $normalized['align'] = $presentation['align'];
        }
        if ($hasBackground) {
            try {
                $background = BlogPublicColor::fromInput(
                    $presentation['background']
                );
            } catch (InvalidArgumentException) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_BLOCK
                );
            }
            $normalized['background'] = $background->value();
        }

        return $normalized;
    }

    /** @return array<string, int|string> */
    private function presentation(mixed $presentation, string $moduleType): array
    {
        if (!is_array($presentation) || array_is_list($presentation)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $isTextModule = in_array(
            $moduleType,
            ['paragraph', 'heading'],
            true
        );
        $allowsTextColor = $isTextModule || $moduleType === 'list';
        $allowed = [
            'width',
            'align',
            'text_align',
            'size',
            'spacing_before',
            'spacing_after',
        ];
        if ($isTextModule) {
            $allowed[] = 'font_size';
            $allowed[] = 'font_weight';
        }
        if ($allowsTextColor) {
            $allowed[] = 'text_color';
        }
        if ($moduleType === 'image') {
            $allowed[] = 'radius';
            $allowed[] = 'height_dvh';
            $allowed[] = 'object_fit';
            $allowed[] = 'object_position_y';
            $allowed[] = 'radius_percent';
            $allowed[] = 'overlay_mode';
            $allowed[] = 'overlay_color';
            $allowed[] = 'overlay_opacity';
        }
        foreach (array_keys($presentation) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_BLOCK
                );
            }
        }
        if (
            !array_key_exists('width', $presentation)
            || !array_key_exists('align', $presentation)
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $textAlign = 'start';
        if (array_key_exists('text_align', $presentation)) {
            $textAlign = $presentation['text_align'];
        }

        $hasSize = array_key_exists('size', $presentation);
        $hasLegacySize = array_key_exists('font_size', $presentation);
        $hasFontWeight = array_key_exists('font_weight', $presentation);
        $hasTextColor = array_key_exists('text_color', $presentation);
        $hasTypography = $hasLegacySize || $hasFontWeight
            || ($isTextModule && $hasTextColor);
        $canonicalSize = $hasSize
            ? BlogPresentationSize::canonicalize($presentation['size'])
            : null;
        $canonicalLegacySize = $hasLegacySize
            ? BlogPresentationSize::canonicalize($presentation['font_size'])
            : null;
        $canonicalTextColor = $hasTextColor
            ? $this->presentationTextColor($presentation['text_color'])
            : null;
        $hasRadius = array_key_exists('radius', $presentation);
        $hasRadiusPercent = array_key_exists(
            'radius_percent',
            $presentation
        );
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
        $canonicalOverlayColor = null;
        if (array_key_exists('overlay_color', $presentation)) {
            try {
                $canonicalOverlayColor = BlogPublicColor::fromInput(
                    $presentation['overlay_color']
                )->value();
            } catch (InvalidArgumentException) {
                $canonicalOverlayColor = null;
            }
        }
        if (
            !is_string($presentation['width'])
            || !in_array(
                $presentation['width'],
                self::PRESENTATION_WIDTHS,
                true
            )
            || !is_string($presentation['align'])
            || !in_array(
                $presentation['align'],
                self::PRESENTATION_ALIGNS,
                true
            )
            || !is_string($textAlign)
            || !in_array(
                $textAlign,
                self::PRESENTATION_TEXT_ALIGNS,
                true
            )
            || ($moduleType === 'cta' && $textAlign === 'justify')
            || ($hasSize && (
                !BlogPresentationSize::isCanonical($presentation['size'])
                || $canonicalSize === null
            ))
            || ($hasTypography && (
                !$isTextModule
                || !$hasFontWeight
                || !$hasTextColor
                || ($hasSize === $hasLegacySize)
                || ($hasLegacySize && $canonicalLegacySize === null)
                || !is_string($presentation['font_weight'])
                || !in_array(
                    $presentation['font_weight'],
                    self::PRESENTATION_FONT_WEIGHTS,
                    true
                )
                || !is_string($presentation['text_color'])
                || $canonicalTextColor === null
            ))
            || ($hasTextColor && (
                !$allowsTextColor
                || $canonicalTextColor === null
            ))
            || ($hasRadius && (
                $moduleType !== 'image'
                || !BlogImageRadiusPreset::supports(
                    $presentation['radius']
                )
            ))
            || ($hasRadiusPercent && (
                $moduleType !== 'image'
                || !BlogImagePresentationPolicy::supportsRadiusPercent(
                    $presentation['radius_percent']
                )
            ))
            || ($hasRadius && $hasRadiusPercent)
            || (array_key_exists('height_dvh', $presentation) && (
                $moduleType !== 'image'
                || !BlogImagePresentationPolicy::supportsHeightDvh(
                    $presentation['height_dvh']
                )
            ))
            || (array_key_exists('object_fit', $presentation) && (
                $moduleType !== 'image'
                || !BlogImagePresentationPolicy::supportsObjectFit(
                    $presentation['object_fit']
                )
            ))
            || (array_key_exists('object_position_y', $presentation) && (
                $moduleType !== 'image'
                || !BlogImagePresentationPolicy::supportsObjectPositionY(
                    $presentation['object_position_y']
                )
            ))
            || ($overlayKeyCount !== 0 && $overlayKeyCount !== 3)
            || ($overlayKeyCount === 3 && (
                $moduleType !== 'image'
                || !BlogImagePresentationPolicy::supportsOverlayMode(
                    $presentation['overlay_mode']
                )
                || $canonicalOverlayColor === null
                || !BlogImagePresentationPolicy::supportsOverlayOpacity(
                    $presentation['overlay_opacity']
                )
            ))
            || (array_key_exists('spacing_before', $presentation) && (
                !is_string($presentation['spacing_before'])
                || !in_array(
                    $presentation['spacing_before'],
                    self::PRESENTATION_SPACINGS,
                    true
                )
            ))
            || (array_key_exists('spacing_after', $presentation) && (
                !is_string($presentation['spacing_after'])
                || !in_array(
                    $presentation['spacing_after'],
                    self::PRESENTATION_SPACINGS,
                    true
                )
            ))
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }

        $normalized = [
            'width' => $presentation['width'],
            'align' => $presentation['align'],
            'text_align' => $textAlign,
        ];
        if ($hasSize) {
            $normalized['size'] = $presentation['size'];
        }
        if ($hasTypography) {
            if ($hasLegacySize) {
                $normalized['font_size'] = $presentation['font_size'];
            }
            $normalized['font_weight'] = $presentation['font_weight'];
            $normalized['text_color'] = $canonicalTextColor;
        } elseif ($hasTextColor) {
            $normalized['text_color'] = $canonicalTextColor;
        }
        if ($hasRadius) {
            $normalized['radius'] = $presentation['radius'];
        }
        if ($hasRadiusPercent) {
            $normalized['radius_percent'] = $presentation['radius_percent'];
        }
        foreach (['height_dvh', 'object_fit', 'object_position_y'] as $key) {
            if (array_key_exists($key, $presentation)) {
                $normalized[$key] = $presentation[$key];
            }
        }
        if ($overlayKeyCount === 3) {
            $normalized['overlay_mode'] = $presentation['overlay_mode'];
            $normalized['overlay_color'] = $canonicalOverlayColor;
            $normalized['overlay_opacity'] =
                $presentation['overlay_opacity'];
        }
        foreach (['spacing_before', 'spacing_after'] as $spacingKey) {
            if (array_key_exists($spacingKey, $presentation)) {
                $normalized[$spacingKey] = $presentation[$spacingKey];
            }
        }

        return $normalized;
    }

    private function presentationTextColor(mixed $value): ?string
    {
        if ($value === 'default') {
            return 'default';
        }
        try {
            return BlogPublicColor::fromInput($value)->value();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function incrementLayoutNode(int &$nodeCount): void
    {
        ++$nodeCount;
        if ($nodeCount > BlogDocument::MAX_BLOCKS) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_DOCUMENT
            );
        }
    }

    /** @param list<array<string, mixed>> $nodes */
    private function layoutCoverCount(array $nodes): int
    {
        $count = 0;
        $visit = function (array $node) use (&$visit, &$count): void {
            $type = $node['type'] ?? null;
            if ($type === 'image' && ($node['display'] ?? null) === 'cover') {
                ++$count;
                return;
            }
            if ($type === 'section') {
                foreach ($node['children'] as $child) {
                    $visit($child);
                }
                return;
            }
            if (!in_array($type, ['article', 'div'], true)) {
                return;
            }
            foreach ($node['layout']['columns'] as $column) {
                foreach ($column['children'] as $child) {
                    $visit($child);
                }
            }
        };
        foreach ($nodes as $node) {
            $visit($node);
        }

        return $count;
    }

    /**
     * @param mixed $value
     * @param array<string, true> $seenIds
     * @return array<string, mixed>
     */
    private function block(
        mixed $value,
        array &$seenIds,
        bool $layoutVersion = false
    ): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $type = $value['type'] ?? null;
        if (!is_string($type)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }

        return match ($type) {
            'paragraph' => $this->paragraph(
                $value,
                $seenIds,
                $layoutVersion
            ),
            'heading' => $this->heading($value, $seenIds, $layoutVersion),
            'list' => $this->listBlock($value, $seenIds, $layoutVersion),
            'callout' => $this->callout(
                $value,
                $seenIds,
                $layoutVersion
            ),
            'link' => $this->linkBlock($value, $seenIds),
            'image' => $this->image($value, $seenIds),
            'video' => $this->video($value, $seenIds),
            'cta' => $this->cta($value, $seenIds, $layoutVersion),
            'quote' => $layoutVersion
                ? $this->quote($value, $seenIds, true)
                : throw new BlogDocumentException(
                    BlogDocumentException::INVALID_BLOCK
                ),
            'embed' => $layoutVersion
                ? $this->embed($value, $seenIds)
                : throw new BlogDocumentException(
                    BlogDocumentException::INVALID_BLOCK
                ),
            'separator' => $layoutVersion
                ? $this->separator($value, $seenIds)
                : throw new BlogDocumentException(
                    BlogDocumentException::INVALID_BLOCK
                ),
            default => throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            ),
        };
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function paragraph(
        array $block,
        array &$seen,
        bool $layoutVersion = false
    ): array
    {
        $advanced = array_key_exists('html', $block)
            || array_key_exists('css', $block);
        if ($advanced) {
            if (!$layoutVersion || array_key_exists('content', $block)) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_BLOCK
                );
            }
            $this->assertExactKeys(
                $block,
                ['id', 'type', 'html', 'css'],
                BlogDocumentException::INVALID_BLOCK
            );
            $allowIncomplete = $this->allowIncompleteLayout;

            return [
                'id' => $this->structuralId($block['id'], $seen),
                'type' => 'paragraph',
                'html' => $this->customTextHtml->sanitize(
                    $block['html'],
                    $allowIncomplete
                ),
                'css' => $this->customTextCss->sanitize($block['css']),
            ];
        }
        $this->assertExactKeys(
            $block,
            ['id', 'type', 'content'],
            BlogDocumentException::INVALID_BLOCK
        );
        $allowIncomplete = $layoutVersion && $this->allowIncompleteLayout;
        $content = $layoutVersion && $this->isTextFlowContent($block['content'])
            ? $this->textFlowContent(
                $block['content'],
                $allowIncomplete,
                $seen
            )
            : $this->inlineContent(
                $block['content'],
                true,
                $allowIncomplete
            );
        if (
            $layoutVersion
            && $this->isTextFlowContent($content)
            && $this->textFlowBytes($content) > self::MAX_TEXT_MODULE_BYTES
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_INLINE
            );
        }
        if (!$allowIncomplete) {
            $layoutVersion && $this->isTextFlowContent($content)
                ? $this->assertTextFlowMeaningful($content)
                : $this->assertMeaningful($content);
        }

        return [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'paragraph',
            'content' => $content,
        ];
    }

    private function isTextFlowContent(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $node) {
            if (!is_array($node) || array_is_list($node)) {
                return false;
            }
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

    /**
     * @param mixed $value
     * @param array<string, true> $seen
     * @return list<array<string, mixed>>
     */
    private function textFlowContent(
        mixed $value,
        bool $allowIncomplete,
        array &$seen
    ): array {
        if (
            !is_array($value)
            || !array_is_list($value)
            || (!$allowIncomplete && $value === [])
            || count($value) > BlogDocument::MAX_BLOCKS
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_INLINE
            );
        }

        $normalized = [];
        foreach ($value as $flowNode) {
            if (!is_array($flowNode) || array_is_list($flowNode)) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_INLINE
                );
            }
            $type = $flowNode['type'] ?? null;
            if ($type === 'break') {
                $this->assertExactKeys(
                    $flowNode,
                    ['type'],
                    BlogDocumentException::INVALID_INLINE
                );
                $normalized[] = ['type' => 'break'];
                continue;
            }
            if ($type === 'paragraph') {
                $this->assertExactKeys(
                    $flowNode,
                    ['type', 'content'],
                    BlogDocumentException::INVALID_INLINE
                );
                $content = $this->inlineContent(
                    $flowNode['content'],
                    true,
                    $allowIncomplete
                );
                if (!$allowIncomplete) {
                    $this->assertMeaningful($content);
                }
                $normalized[] = [
                    'type' => 'paragraph',
                    'content' => $content,
                ];
                continue;
            }
            if ($type === 'heading') {
                $hasPreset = array_key_exists('preset', $flowNode);
                $this->assertExactKeys(
                    $flowNode,
                    $hasPreset
                        ? ['type', 'level', 'content', 'preset']
                        : ['type', 'level', 'content'],
                    BlogDocumentException::INVALID_INLINE
                );
                if (
                    !is_int($flowNode['level'])
                    || !$this->headingLevels->allows($flowNode['level'])
                    || ($hasPreset && (
                        !is_string($flowNode['preset'])
                        || !$this->headingPresets->isAllowed(
                            $flowNode['preset']
                        )
                    ))
                ) {
                    throw new BlogDocumentException(
                        BlogDocumentException::INVALID_INLINE
                    );
                }
                $content = $this->inlineContent(
                    $flowNode['content'],
                    true,
                    $allowIncomplete
                );
                if (!$allowIncomplete) {
                    $this->assertMeaningful($content);
                }
                $heading = [
                    'type' => 'heading',
                    'level' => $flowNode['level'],
                    'content' => $content,
                ];
                if ($hasPreset) {
                    $heading['preset'] = $flowNode['preset'];
                }
                $normalized[] = $heading;
                continue;
            }
            if ($type === 'quote') {
                $expected = ['type', 'content'];
                foreach (['author', 'source', 'preset'] as $optionalKey) {
                    if (array_key_exists($optionalKey, $flowNode)) {
                        $expected[] = $optionalKey;
                    }
                }
                $this->assertExactKeys(
                    $flowNode,
                    $expected,
                    BlogDocumentException::INVALID_INLINE
                );
                if (
                    array_key_exists('preset', $flowNode)
                    && (
                        !is_string($flowNode['preset'])
                        || !in_array(
                            $flowNode['preset'],
                            self::QUOTE_PRESETS,
                            true
                        )
                    )
                ) {
                    throw new BlogDocumentException(
                        BlogDocumentException::INVALID_INLINE
                    );
                }
                $content = $this->inlineContent(
                    $flowNode['content'],
                    true,
                    $allowIncomplete
                );
                if (!$allowIncomplete) {
                    $this->assertMeaningful($content);
                }
                $quote = ['type' => 'quote', 'content' => $content];
                if (array_key_exists('author', $flowNode)) {
                    $quote['author'] = $this->nullableSingleLine(
                        $flowNode['author'],
                        self::MAX_LABEL_BYTES,
                        BlogDocumentException::INVALID_INLINE
                    );
                }
                if (array_key_exists('source', $flowNode)) {
                    $quote['source'] = $this->nullableSingleLine(
                        $flowNode['source'],
                        self::MAX_TITLE_BYTES,
                        BlogDocumentException::INVALID_INLINE
                    );
                }
                if (array_key_exists('preset', $flowNode)) {
                    $quote['preset'] = $flowNode['preset'];
                }
                $normalized[] = $quote;
                continue;
            }
            if ($type === 'callout') {
                $hasTone = array_key_exists('tone', $flowNode);
                $this->assertExactKeys(
                    $flowNode,
                    $hasTone
                        ? ['type', 'content', 'tone']
                        : ['type', 'content'],
                    BlogDocumentException::INVALID_INLINE
                );
                if (
                    $hasTone
                    && (
                        !is_string($flowNode['tone'])
                        || !in_array(
                            $flowNode['tone'],
                            ['neutral', 'info', 'warning'],
                            true
                        )
                    )
                ) {
                    throw new BlogDocumentException(
                        BlogDocumentException::INVALID_INLINE
                    );
                }
                $content = $this->inlineContent(
                    $flowNode['content'],
                    true,
                    $allowIncomplete
                );
                if (!$allowIncomplete) {
                    $this->assertMeaningful($content);
                }
                $callout = ['type' => 'callout', 'content' => $content];
                if ($hasTone) {
                    $callout['tone'] = $flowNode['tone'];
                }
                $normalized[] = $callout;
                continue;
            }
            if ($type !== 'list') {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_INLINE
                );
            }
            $hasMarker = array_key_exists('marker', $flowNode);
            $this->assertExactKeys(
                $flowNode,
                $hasMarker
                    ? ['type', 'ordered', 'items', 'marker']
                    : ['type', 'ordered', 'items'],
                BlogDocumentException::INVALID_INLINE
            );
            if (
                !is_bool($flowNode['ordered'])
                || !is_array($flowNode['items'])
                || !array_is_list($flowNode['items'])
                || (!$allowIncomplete && $flowNode['items'] === [])
                || count($flowNode['items']) > BlogDocument::MAX_LIST_ITEMS
            ) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_INLINE
                );
            }
            if ($hasMarker) {
                $markers = $flowNode['ordered']
                    ? self::ORDERED_LIST_MARKERS
                    : self::UNORDERED_LIST_MARKERS;
                if (
                    !is_string($flowNode['marker'])
                    || !in_array($flowNode['marker'], $markers, true)
                ) {
                    throw new BlogDocumentException(
                        BlogDocumentException::INVALID_INLINE
                    );
                }
            }
            $items = [];
            foreach ($flowNode['items'] as $item) {
                if (!is_array($item) || array_is_list($item)) {
                    throw new BlogDocumentException(
                        BlogDocumentException::INVALID_INLINE
                    );
                }
                $hasId = array_key_exists('id', $item);
                $this->assertExactKeys(
                    $item,
                    $hasId ? ['id', 'content'] : ['content'],
                    BlogDocumentException::INVALID_INLINE
                );
                $itemContent = $this->inlineContent(
                    $item['content'],
                    true,
                    $allowIncomplete
                );
                if (!$allowIncomplete) {
                    $this->assertMeaningful($itemContent);
                }
                $normalizedItem = ['content' => $itemContent];
                if ($hasId) {
                    $normalizedItem = [
                        'id' => $this->structuralId($item['id'], $seen),
                        'content' => $itemContent,
                    ];
                }
                $items[] = $normalizedItem;
            }
            $list = [
                'type' => 'list',
                'ordered' => $flowNode['ordered'],
                'items' => $items,
            ];
            if ($hasMarker) {
                $list['marker'] = $flowNode['marker'];
            }
            $normalized[] = $list;
        }

        return $normalized;
    }

    /** @param list<array<string, mixed>> $content */
    private function assertTextFlowMeaningful(array $content): void
    {
        foreach ($content as $flowNode) {
            $type = $flowNode['type'] ?? null;
            if ($type === 'break') {
                continue;
            }
            if ($type !== 'list') {
                $this->assertMeaningful($flowNode['content']);
                return;
            }
            foreach (($flowNode['items'] ?? []) as $item) {
                $this->assertMeaningful($item['content']);
                return;
            }
        }
        throw new BlogDocumentException(BlogDocumentException::INVALID_INLINE);
    }

    /** @param list<array<string, mixed>> $content */
    private function textFlowBytes(array $content): int
    {
        $bytes = 0;
        foreach ($content as $flowNode) {
            $type = $flowNode['type'] ?? null;
            if ($type === 'break') {
                ++$bytes;
                continue;
            }
            if ($type === 'list') {
                foreach (($flowNode['items'] ?? []) as $item) {
                    $bytes += $this->inlineBytes($item['content'] ?? []);
                }
                continue;
            }
            $bytes += $this->inlineBytes($flowNode['content'] ?? []);
            if (($flowNode['type'] ?? null) === 'quote') {
                foreach (['author', 'source'] as $creditKey) {
                    if (is_string($flowNode[$creditKey] ?? null)) {
                        $bytes += strlen($flowNode[$creditKey]);
                    }
                }
            }
        }

        return $bytes;
    }

    /** @param mixed $content */
    private function inlineBytes(mixed $content): int
    {
        if (!is_array($content)) {
            return self::MAX_TEXT_MODULE_BYTES + 1;
        }
        $bytes = 0;
        foreach ($content as $node) {
            if (is_array($node) && isset($node['text']) && is_string($node['text'])) {
                $bytes += strlen($node['text']);
            }
        }

        return $bytes;
    }

    /** @param array<string, mixed> $module */
    private function unifiedTextStartsWithHeading(array $module): bool
    {
        if (($module['type'] ?? null) !== 'paragraph') {
            return false;
        }
        if (array_key_exists('html', $module)) {
            $html = $module['html'] ?? null;

            return is_string($html)
                && $this->customTextHtml->firstRootHeading($html) !== null;
        }
        $content = $module['content'] ?? null;

        if (!is_array($content) || !$this->isTextFlowContent($content)) {
            return false;
        }
        foreach ($content as $flowNode) {
            if (($flowNode['type'] ?? null) === 'break') {
                continue;
            }

            return ($flowNode['type'] ?? null) === 'heading';
        }

        return false;
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function heading(
        array $block,
        array &$seen,
        bool $layoutVersion
    ): array
    {
        $hasPreset = $layoutVersion && array_key_exists('preset', $block);
        $this->assertExactKeys(
            $block,
            $hasPreset
                ? ['id', 'type', 'level', 'content', 'preset']
                : ['id', 'type', 'level', 'content'],
            BlogDocumentException::INVALID_BLOCK
        );
        if (
            !$this->headingLevels->allows($block['level'])
            || ($hasPreset && (
                !is_string($block['preset'])
                || !$this->headingPresets->isAllowed($block['preset'])
            ))
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $allowIncomplete = $layoutVersion && $this->allowIncompleteLayout;
        $content = $this->inlineContent(
            $block['content'],
            false,
            $allowIncomplete
        );
        if (!$allowIncomplete) {
            $this->assertMeaningful($content);
        }

        $normalized = [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'heading',
            'level' => $block['level'],
            'content' => $content,
        ];
        if ($hasPreset) {
            $normalized['preset'] = $block['preset'];
        }

        return $normalized;
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function listBlock(
        array $block,
        array &$seen,
        bool $layoutVersion
    ): array
    {
        $hasMarker = $layoutVersion && array_key_exists('marker', $block);
        $this->assertExactKeys(
            $block,
            $hasMarker
                ? ['id', 'type', 'ordered', 'items', 'marker']
                : ['id', 'type', 'ordered', 'items'],
            BlogDocumentException::INVALID_BLOCK
        );
        if (!is_bool($block['ordered'])) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        if ($hasMarker) {
            $markers = $block['ordered']
                ? self::ORDERED_LIST_MARKERS
                : self::UNORDERED_LIST_MARKERS;
            if (
                !is_string($block['marker'])
                || !in_array($block['marker'], $markers, true)
            ) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_BLOCK
                );
            }
        }
        $rawItems = $block['items'];
        $allowIncomplete = $layoutVersion && $this->allowIncompleteLayout;
        if (
            !is_array($rawItems)
            || !array_is_list($rawItems)
            || (!$allowIncomplete && $rawItems === [])
            || count($rawItems) > BlogDocument::MAX_LIST_ITEMS
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem) || array_is_list($rawItem)) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_BLOCK
                );
            }
            $this->assertExactKeys(
                $rawItem,
                ['id', 'content'],
                BlogDocumentException::INVALID_BLOCK
            );
            $content = $this->inlineContent(
                $rawItem['content'],
                true,
                $allowIncomplete
            );
            if (!$allowIncomplete) {
                $this->assertMeaningful($content);
            }
            $items[] = [
                'id' => $this->structuralId($rawItem['id'], $seen),
                'content' => $content,
            ];
        }

        $normalized = [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'list',
            'ordered' => $block['ordered'],
            'items' => $items,
        ];
        if ($hasMarker) {
            $normalized['marker'] = $block['marker'];
        }

        return $normalized;
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function callout(
        array $block,
        array &$seen,
        bool $layoutVersion = false
    ): array
    {
        $this->assertExactKeys(
            $block,
            ['id', 'type', 'tone', 'content'],
            BlogDocumentException::INVALID_BLOCK
        );
        if (
            !is_string($block['tone'])
            || !in_array($block['tone'], ['neutral', 'info', 'warning'], true)
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $allowIncomplete = $layoutVersion && $this->allowIncompleteLayout;
        $content = $this->inlineContent(
            $block['content'],
            true,
            $allowIncomplete
        );
        if (!$allowIncomplete) {
            $this->assertMeaningful($content);
        }

        return [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'callout',
            'tone' => $block['tone'],
            'content' => $content,
        ];
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function linkBlock(array $block, array &$seen): array
    {
        $this->assertExactKeys(
            $block,
            ['id', 'type', 'label', 'href', 'title', 'target'],
            BlogDocumentException::INVALID_BLOCK
        );

        return [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'link',
            'label' => $this->singleLine(
                $block['label'],
                self::MAX_LABEL_BYTES,
                BlogDocumentException::INVALID_BLOCK
            ),
            'href' => $this->url($block['href']),
            'title' => $this->nullableSingleLine(
                $block['title'],
                self::MAX_TITLE_BYTES,
                BlogDocumentException::INVALID_BLOCK
            ),
            'target' => $this->target($block['target']),
        ];
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function image(array $block, array &$seen): array
    {
        $this->assertExactKeys(
            $block,
            [
                'id', 'type', 'media_asset_public_id', 'alt', 'title',
                'caption', 'decorative', 'display',
            ],
            BlogDocumentException::INVALID_BLOCK
        );
        if (!is_bool($block['decorative'])) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $alt = $this->singleLine(
            $block['alt'],
            self::MAX_ALT_BYTES,
            BlogDocumentException::INVALID_BLOCK,
            true
        );
        if (
            ($block['decorative'] && $alt !== '')
            || (!$block['decorative'] && $alt === '')
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        if (
            !is_string($block['display'])
            || !in_array(
                $block['display'],
                ['content', 'wide', 'cover'],
                true
            )
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }

        return [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'image',
            'media_asset_public_id' => $this->uuid(
                $block['media_asset_public_id'],
                BlogDocumentException::INVALID_BLOCK
            ),
            'alt' => $alt,
            'title' => $this->nullableSingleLine(
                $block['title'],
                self::MAX_TITLE_BYTES,
                BlogDocumentException::INVALID_BLOCK
            ),
            'caption' => $this->nullableSingleLine(
                $block['caption'],
                self::MAX_CAPTION_BYTES,
                BlogDocumentException::INVALID_BLOCK
            ),
            'decorative' => $block['decorative'],
            'display' => $block['display'],
        ];
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function video(array $block, array &$seen): array
    {
        $this->assertExactKeys(
            $block,
            [
                'id', 'type', 'provider', 'video_id', 'title',
                'start_seconds',
            ],
            BlogDocumentException::INVALID_BLOCK
        );
        if (
            $block['provider'] !== 'youtube'
            || !is_string($block['video_id'])
            || preg_match('/\A[A-Za-z0-9_-]{11}\z/', $block['video_id']) !== 1
            || !is_int($block['start_seconds'])
            || $block['start_seconds'] < 0
            || $block['start_seconds'] > self::MAX_VIDEO_START_SECONDS
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }

        return [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'video',
            'provider' => 'youtube',
            'video_id' => $block['video_id'],
            'title' => $this->singleLine(
                $block['title'],
                self::MAX_TITLE_BYTES,
                BlogDocumentException::INVALID_BLOCK
            ),
            'start_seconds' => $block['start_seconds'],
        ];
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function cta(
        array $block,
        array &$seen,
        bool $layoutVersion
    ): array
    {
        $this->assertExactKeys(
            $block,
            [
                'id', 'type', 'label', 'href', 'title', 'target',
                'variant',
            ],
            BlogDocumentException::INVALID_BLOCK
        );
        if (
            !is_string($block['variant'])
            || !in_array(
                $block['variant'],
                $layoutVersion
                    ? ['primary', 'secondary', 'type03', 'type04']
                    : ['primary', 'secondary'],
                true
            )
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }

        return [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'cta',
            'label' => $this->singleLine(
                $block['label'],
                self::MAX_LABEL_BYTES,
                BlogDocumentException::INVALID_BLOCK
            ),
            'href' => $this->url($block['href']),
            'title' => $this->nullableSingleLine(
                $block['title'],
                self::MAX_TITLE_BYTES,
                BlogDocumentException::INVALID_BLOCK
            ),
            'target' => $this->target($block['target']),
            'variant' => $block['variant'],
        ];
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function quote(
        array $block,
        array &$seen,
        bool $layoutVersion = false
    ): array
    {
        $this->assertExactKeys(
            $block,
            ['id', 'type', 'content', 'author', 'source', 'preset'],
            BlogDocumentException::INVALID_BLOCK
        );
        if (
            !is_string($block['preset'])
            || !in_array($block['preset'], self::QUOTE_PRESETS, true)
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $allowIncomplete = $layoutVersion && $this->allowIncompleteLayout;
        $content = $this->inlineContent(
            $block['content'],
            true,
            $allowIncomplete
        );
        if (!$allowIncomplete) {
            $this->assertMeaningful($content);
        }

        return [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'quote',
            'content' => $content,
            'author' => $this->nullableSingleLine(
                $block['author'],
                self::MAX_LABEL_BYTES,
                BlogDocumentException::INVALID_BLOCK
            ),
            'source' => $this->nullableSingleLine(
                $block['source'],
                self::MAX_TITLE_BYTES,
                BlogDocumentException::INVALID_BLOCK
            ),
            'preset' => $block['preset'],
        ];
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function embed(array $block, array &$seen): array
    {
        $hasCss = array_key_exists('css', $block);
        $this->assertExactKeys(
            $block,
            $hasCss
                ? ['id', 'type', 'html', 'css', 'caption']
                : ['id', 'type', 'html', 'caption'],
            BlogDocumentException::INVALID_BLOCK
        );

        return [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'embed',
            'html' => $this->embedSanitizer->sanitize($block['html']),
            'css' => $this->customTextCss->sanitize(
                $hasCss ? $block['css'] : ''
            ),
            'caption' => $this->nullableSingleLine(
                $block['caption'],
                self::MAX_CAPTION_BYTES,
                BlogDocumentException::INVALID_BLOCK
            ),
        ];
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function separator(array $block, array &$seen): array
    {
        $this->assertExactKeys(
            $block,
            ['id', 'type', 'line_style', 'thickness', 'color'],
            BlogDocumentException::INVALID_BLOCK
        );
        if (
            !is_string($block['line_style'])
            || !in_array(
                $block['line_style'],
                self::SEPARATOR_LINE_STYLES,
                true
            )
            || !is_string($block['thickness'])
            || !in_array(
                $block['thickness'],
                self::SEPARATOR_THICKNESSES,
                true
            )
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        try {
            $color = BlogPublicColor::fromInput($block['color']);
        } catch (InvalidArgumentException) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }

        return [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'separator',
            'line_style' => $block['line_style'],
            'thickness' => $block['thickness'],
            'color' => $color->value(),
        ];
    }

    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private function inlineContent(
        mixed $value,
        bool $allowBreak,
        bool $allowEmpty = false
    ): array
    {
        if (
            !is_array($value)
            || !array_is_list($value)
            || (!$allowEmpty && $value === [])
            || count($value) > self::MAX_INLINE_NODES
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_INLINE
            );
        }
        $content = [];
        foreach ($value as $node) {
            if (!is_array($node) || array_is_list($node)) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_INLINE
                );
            }
            $type = $node['type'] ?? null;
            if ($type === 'text') {
                $this->assertExactKeys(
                    $node,
                    ['type', 'text', 'marks'],
                    BlogDocumentException::INVALID_INLINE
                );
                $content[] = [
                    'type' => 'text',
                    'text' => $this->inlineText($node['text'], $allowEmpty),
                    'marks' => $this->marks($node['marks']),
                ];
                continue;
            }
            if ($type === 'link') {
                $this->assertExactKeys(
                    $node,
                    [
                        'type', 'text', 'marks', 'href', 'title',
                        'target',
                    ],
                    BlogDocumentException::INVALID_INLINE
                );
                $content[] = [
                    'type' => 'link',
                    'text' => $this->inlineText($node['text']),
                    'marks' => $this->marks($node['marks']),
                    'href' => $this->url($node['href']),
                    'title' => $this->nullableSingleLine(
                        $node['title'],
                        self::MAX_TITLE_BYTES,
                        BlogDocumentException::INVALID_INLINE
                    ),
                    'target' => $this->target($node['target']),
                ];
                continue;
            }
            if ($type === 'break' && $allowBreak) {
                $this->assertExactKeys(
                    $node,
                    ['type'],
                    BlogDocumentException::INVALID_INLINE
                );
                $content[] = ['type' => 'break'];
                continue;
            }

            throw new BlogDocumentException(
                BlogDocumentException::INVALID_INLINE
            );
        }

        return $content;
    }

    /** @param list<array<string, mixed>> $content */
    private function assertMeaningful(array $content): void
    {
        $text = '';
        foreach ($content as $node) {
            if ($node['type'] === 'break') {
                $text .= "\n";
            } else {
                $text .= (string) $node['text'];
            }
        }
        if (trim($text) === '') {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_INLINE
            );
        }
    }

    /** @return list<string> */
    private function marks(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_INLINE
            );
        }
        $seen = [];
        $groups = ['size' => false, 'text' => false, 'background' => false];
        $dynamic = [];
        foreach ($value as $mark) {
            if (!is_string($mark)) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_INLINE
                );
            }
            $canonicalMark = in_array($mark, self::MARK_ORDER, true)
                ? $mark : $this->dynamicColorMark($mark);
            if ($canonicalMark === null || isset($seen[$canonicalMark])) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_INLINE
                );
            }
            $seen[$canonicalMark] = true;
            $group = match (true) {
                str_starts_with($canonicalMark, 'size-') => 'size',
                str_starts_with($canonicalMark, 'text-color'),
                str_starts_with($canonicalMark, 'text-basic-'),
                str_starts_with($canonicalMark, 'text-rgba:') => 'text',
                str_starts_with($canonicalMark, 'background-color'),
                str_starts_with($canonicalMark, 'background-basic-'),
                str_starts_with($canonicalMark, 'background-rgba:') =>
                    'background',
                default => null,
            };
            if ($group !== null) {
                if ($groups[$group]) {
                    throw new BlogDocumentException(
                        BlogDocumentException::INVALID_INLINE
                    );
                }
                $groups[$group] = true;
            }
            if ($canonicalMark !== $mark || !in_array(
                $canonicalMark,
                self::MARK_ORDER,
                true
            )) {
                $dynamic[$group ?? ''] = $canonicalMark;
            }
        }

        $normalized = array_values(array_filter(
            self::MARK_ORDER,
            static fn (string $mark): bool => isset($seen[$mark])
        ));
        foreach (['text', 'background'] as $group) {
            if (isset($dynamic[$group])) {
                $normalized[] = $dynamic[$group];
            }
        }

        return $normalized;
    }

    private function dynamicColorMark(string $mark): ?string
    {
        $prefix = match (true) {
            str_starts_with($mark, 'text-rgba:') => 'text-rgba:',
            str_starts_with($mark, 'background-rgba:') =>
                'background-rgba:',
            default => null,
        };
        if ($prefix === null) {
            return null;
        }
        try {
            $color = BlogPublicColor::fromInput(substr($mark, strlen($prefix)));
        } catch (InvalidArgumentException) {
            return null;
        }
        if ($color->kind() !== BlogPublicColor::RGBA) {
            return null;
        }

        return $prefix . $color->value();
    }

    /** @param list<array<string, mixed>> $blocks */
    private function assertV1InlineMarks(array $blocks): void
    {
        foreach ($blocks as $block) {
            $type = $block['type'] ?? null;
            if (in_array($type, ['paragraph', 'heading', 'callout'], true)) {
                $this->assertV1ContentMarks($block['content']);
                continue;
            }
            if ($type !== 'list') {
                continue;
            }
            foreach ($block['items'] as $item) {
                $this->assertV1ContentMarks($item['content']);
            }
        }
    }

    /** @param list<array<string, mixed>> $content */
    private function assertV1ContentMarks(array $content): void
    {
        foreach ($content as $node) {
            foreach (($node['marks'] ?? []) as $mark) {
                if (!in_array($mark, self::V1_MARK_ORDER, true)) {
                    throw new BlogDocumentException(
                        BlogDocumentException::INVALID_INLINE
                    );
                }
            }
        }
    }

    private function inlineText(
        mixed $value,
        bool $allowEmpty = false
    ): string
    {
        if (
            !is_string($value)
            || (!$allowEmpty && $value === '')
            || strlen($value) > self::MAX_INLINE_TEXT_BYTES
            || !$this->isSafePlainText($value)
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_INLINE
            );
        }

        return $value;
    }

    private function target(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['same', 'new'], true)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }

        return $value;
    }

    private function url(mixed $value): string
    {
        if (!is_string($value)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_URL
            );
        }
        $url = trim($value);
        if (
            $url === ''
            || strlen($url) > self::MAX_URL_BYTES
            || !$this->isSafePlainText($url)
            || str_contains($url, '\\')
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $url) === 1
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_URL
            );
        }
        $hasWhitespace = preg_match('/\s/u', $url) === 1;
        if (
            !$hasWhitespace
            && str_starts_with($url, '/')
            && !str_starts_with($url, '//')
        ) {
            if ($this->isSafeRootRelativeUrl($url)) {
                return $url;
            }

            throw new BlogDocumentException(
                BlogDocumentException::INVALID_URL
            );
        }
        if (!$hasWhitespace && str_starts_with($url, 'https://')) {
            $parts = parse_url($url);
            if (
                filter_var($url, FILTER_VALIDATE_URL) !== false
                && is_array($parts)
                && ($parts['scheme'] ?? null) === 'https'
                && is_string($parts['host'] ?? null)
                && ($parts['host'] ?? '') !== ''
                && !isset($parts['user'])
                && !isset($parts['pass'])
            ) {
                return $url;
            }
        }
        if (!$hasWhitespace && str_starts_with($url, 'mailto:')) {
            $address = substr($url, strlen('mailto:'));
            if (
                !str_contains($address, '?')
                && filter_var($address, FILTER_VALIDATE_EMAIL) !== false
            ) {
                return $url;
            }
        }
        if (
            str_starts_with($url, 'tel:')
            && preg_match(
                '/\Atel:\+?[0-9][0-9 .()\-]{2,31}\z/',
                $url
            ) === 1
        ) {
            return $url;
        }

        throw new BlogDocumentException(
            BlogDocumentException::INVALID_URL
        );
    }

    private function isSafeRootRelativeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
        ) {
            return false;
        }
        $path = $parts['path'] ?? null;
        if (
            !is_string($path)
            || !str_starts_with($path, '/')
            || str_contains($path, '//')
            || preg_match('/%(?:2f|5c)/i', $path) === 1
        ) {
            return false;
        }

        $decoded = $path;
        $stable = false;
        for ($pass = 0; $pass < 8; ++$pass) {
            if (
                preg_match('/%(?:2f|5c)/i', $decoded) === 1
                || preg_match('/%(?![0-9A-Fa-f]{2})/', $decoded) === 1
            ) {
                return false;
            }
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                $stable = true;
                break;
            }
            $decoded = $next;
        }
        if (
            !$stable
            || preg_match('//u', $decoded) !== 1
            || preg_match('/\p{Cc}/u', $decoded) === 1
            || str_contains($decoded, '\\')
            || str_contains($decoded, '//')
        ) {
            return false;
        }
        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, true> $seenIds */
    private function structuralId(mixed $value, array &$seenIds): string
    {
        $id = $this->uuid($value, BlogDocumentException::INVALID_BLOCK);
        if (isset($seenIds[$id])) {
            throw new BlogDocumentException(
                BlogDocumentException::DUPLICATE_ID
            );
        }
        $seenIds[$id] = true;

        return $id;
    }

    private function uuid(mixed $value, string $issueCode): string
    {
        if (
            !is_string($value)
            || preg_match(self::UUID_V4_PATTERN, $value) !== 1
        ) {
            throw new BlogDocumentException($issueCode);
        }

        return $value;
    }

    private function singleLine(
        mixed $value,
        int $maxBytes,
        string $issueCode,
        bool $allowEmpty = false
    ): string {
        if (!is_string($value)) {
            throw new BlogDocumentException($issueCode);
        }
        $value = trim($value);
        if (
            (!$allowEmpty && $value === '')
            || strlen($value) > $maxBytes
            || !$this->isSafePlainText($value)
        ) {
            throw new BlogDocumentException($issueCode);
        }

        return $value;
    }

    private function nullableSingleLine(
        mixed $value,
        int $maxBytes,
        string $issueCode
    ): ?string {
        return $value === null
            ? null
            : $this->singleLine($value, $maxBytes, $issueCode);
    }

    private function isSafePlainText(string $value): bool
    {
        return preg_match('//u', $value) === 1
            && preg_match('/\p{Cc}/u', $value) !== 1;
    }

    /** @param list<array<string, mixed>> $blocks */
    private function assertHeadingHierarchy(array $blocks): void
    {
        /** @var array<int, true> $activeLevels */
        $activeLevels = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) !== 'heading') {
                continue;
            }

            $level = (int) $block['level'];
            if ($level === 2) {
                $activeLevels = [2 => true];
                continue;
            }

            if (!isset($activeLevels[$level - 1])) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_HEADING_HIERARCHY
                );
            }

            foreach (array_keys($activeLevels) as $activeLevel) {
                if ($activeLevel >= $level) {
                    unset($activeLevels[$activeLevel]);
                }
            }
            $activeLevels[$level] = true;
        }
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $expected
     */
    private function assertExactKeys(
        array $value,
        array $expected,
        string $issueCode
    ): void {
        if (array_is_list($value)) {
            throw new BlogDocumentException($issueCode);
        }
        $actual = array_keys($value);
        if (!array_is_list($actual)) {
            throw new BlogDocumentException($issueCode);
        }
        foreach ($actual as $key) {
            if (!is_string($key)) {
                throw new BlogDocumentException($issueCode);
            }
        }
        sort($actual, SORT_STRING);
        $sortedExpected = $expected;
        sort($sortedExpected, SORT_STRING);
        if ($actual !== $sortedExpected) {
            throw new BlogDocumentException($issueCode);
        }
    }

    /** @param array<string, mixed> $document */
    private function assertCanonicalSize(array $document): void
    {
        try {
            $json = json_encode(
                $document,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_DOCUMENT
            );
        }
        if (strlen($json) > BlogDocument::MAX_JSON_BYTES) {
            throw new BlogDocumentException(
                BlogDocumentException::DOCUMENT_TOO_LARGE
            );
        }
    }
}
