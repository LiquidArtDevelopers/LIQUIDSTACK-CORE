<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

use App\Core\Blog\StructuredContent\Presentation\BlogPublicColor;
use DOMDocument;
use DOMElement;
use DOMNode;
use InvalidArgumentException;
use ValueError;

/**
 * Strict HTML fragment contract for an advanced V2 Text module.
 *
 * The canonical document keeps editor-facing class and id tokens. Public
 * output namespaces both families with the immutable block UUID so neither
 * browser hooks nor document-wide fragment identifiers can collide.
 */
final class BlogCustomTextHtmlSanitizer
{
    public const MAX_HTML_BYTES = 200_000;
    public const MAX_NODES = 1_024;
    public const MAX_DEPTH = 32;
    public const MAX_LIST_DEPTH = 4;

    private const MAX_ATTRIBUTES_PER_ELEMENT = 24;
    private const MAX_CLASSES_PER_ELEMENT = 16;
    private const MAX_IDREFS_PER_ATTRIBUTE = 8;
    private const MAX_ATTRIBUTE_VALUE_BYTES = 500;

    private const UUID_V4 =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D';
    private const TOKEN = '/\A[A-Za-z_][A-Za-z0-9_-]{0,63}\z/D';
    private const ALLOWED_TAGS = [
        'a', 'aside', 'blockquote', 'br', 'code', 'div', 'em', 'h2', 'h3',
        'h4', 'h5', 'h6', 'iframe', 'li', 'mark', 'ol', 'p', 'small', 'span',
        'strong', 'sub', 'sup', 'u', 'ul',
    ];
    private const BLOCK_TAGS = [
        'aside', 'blockquote', 'div', 'h2', 'h3', 'h4', 'h5', 'h6',
        'li', 'ol', 'p', 'ul',
    ];
    private const HEADING_TAGS = ['h2', 'h3', 'h4', 'h5', 'h6'];
    private const ALLOWED_ROLES = [
        'group', 'list', 'listitem', 'none', 'note', 'presentation', 'region',
    ];
    private const RESERVED_CLASS_PREFIXES = [
        'blogdocument', 'blog-', 'liquidstack', 'ls-', 'webadmin',
    ];
    private const SAFE_REL_TOKENS = [
        'nofollow', 'noopener', 'noreferrer', 'sponsored', 'ugc',
    ];
    private const FORMAT_ATTRIBUTES = [
        'data-content-format-size',
        'data-content-format-text-color',
        'data-content-format-background-color',
        'data-content-format-text-rgba',
        'data-content-format-background-rgba',
    ];
    private const FORMAT_SIZES = ['small', 'large', 'xlarge'];
    private const FORMAT_COLORS = [
        'color00', 'color01', 'color02', 'color03', 'color04', 'color05',
        'basic-red', 'basic-orange', 'basic-yellow', 'basic-green',
        'basic-blue', 'basic-purple', 'basic-pink', 'basic-gray',
    ];

    /** @return array<string, mixed> */
    public function policy(): array
    {
        return [
            'tags' => self::ALLOWED_TAGS,
            'global_attributes' => [
                'class', 'id', 'title', 'lang', 'dir', 'role', 'aria-label',
                'aria-hidden', 'aria-labelledby', 'aria-describedby',
            ],
            'data_attribute_prefix' => 'data-content-',
            'roles' => self::ALLOWED_ROLES,
            'rel_tokens' => self::SAFE_REL_TOKENS,
            'reserved_class_prefixes' => self::RESERVED_CLASS_PREFIXES,
            'special_attributes' => [
                'a' => ['href', 'target', 'rel'],
                'blockquote' => ['cite'],
                'aside' => ['data-content-callout', 'role'],
                'ol' => ['start', 'reversed', 'type'],
                'li' => ['value'],
                'iframe' => BlogSafeIframePolicy::attributes(),
            ],
            'iframe_sources' => BlogSafeIframePolicy::sources(),
            'max_html_bytes' => self::MAX_HTML_BYTES,
            'max_nodes' => self::MAX_NODES,
            'max_depth' => self::MAX_DEPTH,
            'max_list_depth' => self::MAX_LIST_DEPTH,
            'max_attributes_per_element' => self::MAX_ATTRIBUTES_PER_ELEMENT,
            'max_classes_per_element' => self::MAX_CLASSES_PER_ELEMENT,
            'max_idrefs_per_attribute' => self::MAX_IDREFS_PER_ATTRIBUTE,
            'max_attribute_value_bytes' => self::MAX_ATTRIBUTE_VALUE_BYTES,
        ];
    }

    public function sanitize(mixed $html, bool $allowEmpty = false): string
    {
        if (!is_string($html)) {
            throw $this->invalid();
        }
        if ($html === '' && $allowEmpty) {
            return '';
        }
        if (!$this->hasSafeSourceShape($html)) {
            throw $this->invalid();
        }

        [$document, $root] = $this->parse($html);
        $ids = [];
        $references = [];
        $nodeCount = 0;
        $this->sanitizeChildren($root, $ids, $references, $nodeCount, 0, 0);
        foreach ($references as $reference) {
            if (!isset($ids[$reference])) {
                throw $this->invalid();
            }
        }

        $canonical = $this->innerHtml($document, $root);
        if (
            (!$allowEmpty && !$this->hasMeaningfulContent($root))
            || strlen($canonical) > self::MAX_HTML_BYTES
        ) {
            throw $this->invalid();
        }

        return $canonical;
    }

    public function plainText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $canonical = $this->sanitize($html, true);
        [, $root] = $this->parse($canonical);
        $text = preg_replace('/[\t ]+/u', ' ', $this->plainTextFromRoot($root));
        $text = is_string($text)
            ? preg_replace('/\n[\t ]+/u', "\n", $text)
            : null;
        $text = is_string($text)
            ? preg_replace('/\n{3,}/u', "\n\n", $text)
            : null;
        if (!is_string($text)) {
            throw $this->invalid();
        }

        return trim($text);
    }

    /** @return array{level: int, text: string}|null */
    public function firstRootHeading(string $html): ?array
    {
        if ($html === '') {
            return null;
        }
        $canonical = $this->sanitize($html, true);
        [, $root] = $this->parse($canonical);
        foreach (iterator_to_array($root->childNodes) as $node) {
            if (
                $node->nodeType === XML_TEXT_NODE
                && trim((string) $node->nodeValue) === ''
            ) {
                continue;
            }
            if (!$node instanceof DOMElement) {
                return null;
            }
            if ($node->tagName === 'br') {
                continue;
            }

            return $this->headingProjection($node);
        }

        return null;
    }

    /** @return list<array{level: int, text: string}> */
    public function headings(string $html): array
    {
        if ($html === '') {
            return [];
        }
        $canonical = $this->sanitize($html, true);
        [, $root] = $this->parse($canonical);
        $headings = [];
        foreach ($this->elements($root) as $element) {
            $heading = $this->headingProjection($element);
            if ($heading !== null) {
                $headings[] = $heading;
            }
        }

        return $headings;
    }

    public function firstHeadingRenderId(string $html, string $blockId): ?string
    {
        if ($html === '') {
            return null;
        }
        $canonical = $this->sanitize($html, true);
        [, $root] = $this->parse($canonical);
        foreach ($this->elements($root) as $element) {
            if (!in_array(strtolower($element->tagName), self::HEADING_TAGS, true)) {
                continue;
            }

            return $element->hasAttribute('id')
                ? $this->namespacePrefix($blockId)
                    . $element->getAttribute('id')
                : $this->flowHeadingId($blockId, 0);
        }

        return null;
    }

    public function flowHeadingId(string $blockId, int $index): string
    {
        if (preg_match(self::UUID_V4, $blockId) !== 1 || $index < 0) {
            throw $this->invalid();
        }

        return 'blog-flow-heading-' . str_replace('-', '', $blockId)
            . '-' . $index;
    }

    /** Returns canonical HTML with local classes, ids and IDREFs namespaced. */
    public function namespaceForRender(string $html, string $blockId): string
    {
        $prefix = $this->namespacePrefix($blockId);
        if ($html === '') {
            return '';
        }
        $canonical = $this->sanitize($html);
        [$document, $root] = $this->parse($canonical);
        $headingIndex = 0;
        foreach ($this->elements($root) as $element) {
            if ($element->hasAttribute('id')) {
                $element->setAttribute(
                    'id',
                    $prefix . $element->getAttribute('id')
                );
            } elseif (in_array(
                strtolower($element->tagName),
                self::HEADING_TAGS,
                true
            )) {
                $element->setAttribute(
                    'id',
                    $this->flowHeadingId($blockId, $headingIndex)
                );
            }
            if (in_array(
                strtolower($element->tagName),
                self::HEADING_TAGS,
                true
            )) {
                ++$headingIndex;
            }
            if ($element->hasAttribute('class')) {
                $classes = preg_split(
                    '/\s+/',
                    trim($element->getAttribute('class'))
                ) ?: [];
                $element->setAttribute('class', implode(' ', array_map(
                    static fn (string $class): string => $prefix . $class,
                    $classes
                )));
            }
            if (
                $element->tagName === 'a'
                && str_starts_with($element->getAttribute('href'), '#')
            ) {
                $element->setAttribute(
                    'href',
                    '#' . $prefix . substr($element->getAttribute('href'), 1)
                );
            }
            foreach (['aria-describedby', 'aria-labelledby'] as $attribute) {
                if (!$element->hasAttribute($attribute)) {
                    continue;
                }
                $tokens = preg_split(
                    '/\s+/',
                    trim($element->getAttribute($attribute))
                ) ?: [];
                $element->setAttribute($attribute, implode(' ', array_map(
                    static fn (string $token): string => $prefix . $token,
                    $tokens
                )));
            }
            $this->projectSemanticClassesForRender($element);
            $this->projectFormatAttributesForRender($element);
        }

        return $this->innerHtml($document, $root);
    }

    /** Adds the same public presentation hooks used by canonical Text flow. */
    private function projectSemanticClassesForRender(
        DOMElement $element
    ): void {
        $tag = strtolower($element->tagName);
        $classes = match ($tag) {
            'h2', 'h3', 'h4', 'h5', 'h6' => [
                'blogDocument__textHeading',
                'blogDocument__heading',
            ],
            'ul', 'ol' => ['blogDocument__textList'],
            'li' => ['blogDocument__textListItem'],
            'blockquote' => ['blogDocument__textQuote'],
            'aside' => ['blogDocument__textCallout'],
            'p' => [$this->paragraphRenderClass($element)],
            default => [],
        };
        if ($classes === []) {
            return;
        }

        $existing = $element->hasAttribute('class')
            ? preg_split('/\s+/', trim($element->getAttribute('class'))) ?: []
            : [];
        $element->setAttribute(
            'class',
            implode(' ', array_values(array_unique(array_merge(
                $existing,
                $classes
            ))))
        );
    }

    private function paragraphRenderClass(DOMElement $element): string
    {
        $parent = $element->parentNode;
        if ($parent instanceof DOMElement) {
            $parentTag = strtolower($parent->tagName);
            if ($parentTag === 'blockquote') {
                return 'blogDocument__textQuoteContent';
            }
            if ($parentTag === 'aside') {
                return 'blogDocument__textCalloutContent';
            }
        }

        return 'blogDocument__textParagraph';
    }

    public function namespacePrefix(string $blockId): string
    {
        if (preg_match(self::UUID_V4, $blockId) !== 1) {
            throw $this->invalid();
        }

        return 'lsb-' . str_replace('-', '', $blockId) . '-';
    }

    private function hasSafeSourceShape(string $html): bool
    {
        return trim($html) !== ''
            && strlen($html) <= self::MAX_HTML_BYTES
            && preg_match('//u', $html) === 1
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $html) !== 1
            && preg_match('/<\s*[!?]/', $html) !== 1;
    }

    /** @return array{DOMDocument, DOMElement} */
    private function parse(string $html): array
    {
        if (!class_exists(DOMDocument::class)) {
            throw $this->invalid();
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><div data-ls-custom-root="1">'
                    . $html . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($loaded !== true) {
            throw $this->invalid();
        }
        $rootCandidate = null;
        foreach (iterator_to_array($document->childNodes) as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                continue;
            }
            if ($node instanceof DOMElement && $rootCandidate === null) {
                $rootCandidate = $node;
                continue;
            }
            if (
                $node->nodeType !== XML_TEXT_NODE
                || trim((string) $node->nodeValue) !== ''
            ) {
                throw $this->invalid();
            }
        }
        foreach ($document->getElementsByTagName('div') as $element) {
            if ($element->getAttribute('data-ls-custom-root') !== '1') {
                continue;
            }
            if ($rootCandidate !== $element) {
                throw $this->invalid();
            }
            $element->removeAttribute('data-ls-custom-root');

            return [$document, $element];
        }

        throw $this->invalid();
    }

    /**
     * @param array<string, true> $ids
     * @param list<string> $references
     */
    private function sanitizeChildren(
        DOMNode $parent,
        array &$ids,
        array &$references,
        int &$nodeCount,
        int $depth,
        int $listDepth
    ): void {
        if ($depth > self::MAX_DEPTH) {
            throw $this->invalid();
        }
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if (++$nodeCount > self::MAX_NODES) {
                throw $this->invalid();
            }
            if ($node->nodeType === XML_TEXT_NODE) {
                continue;
            }
            if (!$node instanceof DOMElement) {
                throw $this->invalid();
            }
            $tag = strtolower($node->tagName);
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                throw $this->invalid();
            }
            $this->sanitizeElement($node, $tag, $ids, $references);
            if ($tag === 'br' || $tag === 'iframe') {
                if ($node->childNodes->length !== 0) {
                    throw $this->invalid();
                }
                continue;
            }
            $childListDepth = in_array($tag, ['ol', 'ul'], true)
                ? $listDepth + 1 : $listDepth;
            if ($childListDepth > self::MAX_LIST_DEPTH) {
                throw $this->invalid();
            }
            $this->sanitizeChildren(
                $node,
                $ids,
                $references,
                $nodeCount,
                $depth + 1,
                $childListDepth
            );
        }
    }

    /**
     * @param array<string, true> $ids
     * @param list<string> $references
     */
    private function sanitizeElement(
        DOMElement $element,
        string $tag,
        array &$ids,
        array &$references
    ): void {
        if ($element->attributes->length > self::MAX_ATTRIBUTES_PER_ELEMENT) {
            throw $this->invalid();
        }
        $raw = [];
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            if (isset($raw[$name]) || str_starts_with($name, 'on')) {
                throw $this->invalid();
            }
            $raw[$name] = $attribute->value;
        }
        while ($element->attributes->length > 0) {
            $attribute = $element->attributes->item(0);
            if ($attribute !== null) {
                $element->removeAttributeNode($attribute);
            }
        }

        $allowed = [
            'aria-describedby', 'aria-hidden', 'aria-label',
            'aria-labelledby', 'class', 'dir', 'id', 'lang', 'role', 'title',
        ];
        if ($tag === 'a') {
            array_push($allowed, 'href', 'rel', 'target');
        } elseif ($tag === 'blockquote') {
            $allowed[] = 'cite';
        } elseif ($tag === 'ol') {
            array_push($allowed, 'reversed', 'start', 'type');
        } elseif ($tag === 'li') {
            $allowed[] = 'value';
        } elseif ($tag === 'iframe') {
            array_push($allowed, ...BlogSafeIframePolicy::attributes());
        }
        foreach (array_keys($raw) as $name) {
            if (
                !in_array($name, $allowed, true)
                && preg_match(
                    '/\Adata-content-[a-z][a-z0-9_-]{0,47}\z/D',
                    $name
                ) !== 1
            ) {
                throw $this->invalid();
            }
            if (
                str_starts_with($name, 'data-content-format-')
                && !in_array($name, self::FORMAT_ATTRIBUTES, true)
            ) {
                throw $this->invalid();
            }
            if ($name === 'data-content-callout' && $tag !== 'aside') {
                throw $this->invalid();
            }
        }
        if (
            $tag === 'aside'
            && strtolower(trim($raw['data-content-callout'] ?? '')) !== 'true'
        ) {
            throw $this->invalid();
        }

        $attributes = [];
        if (array_key_exists('id', $raw)) {
            $id = trim($raw['id']);
            if (preg_match(self::TOKEN, $id) !== 1 || isset($ids[$id])) {
                throw $this->invalid();
            }
            $ids[$id] = true;
            $attributes['id'] = $id;
        }
        if (array_key_exists('class', $raw)) {
            $attributes['class'] = $this->classes($raw['class']);
        }
        foreach (['title', 'aria-label'] as $name) {
            if (array_key_exists($name, $raw)) {
                $attributes[$name] = $this->plainAttribute(
                    $raw[$name],
                    self::MAX_ATTRIBUTE_VALUE_BYTES
                );
            }
        }
        foreach (['aria-describedby', 'aria-labelledby'] as $name) {
            if (!array_key_exists($name, $raw)) {
                continue;
            }
            $tokens = $this->referenceTokens($raw[$name]);
            array_push($references, ...$tokens);
            $attributes[$name] = implode(' ', $tokens);
        }
        if (array_key_exists('aria-hidden', $raw)) {
            $value = strtolower(trim($raw['aria-hidden']));
            if (!in_array($value, ['false', 'true'], true)) {
                throw $this->invalid();
            }
            $attributes['aria-hidden'] = $value;
        }
        if (array_key_exists('lang', $raw)) {
            $lang = trim($raw['lang']);
            if (preg_match('/\A[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8}){0,3}\z/D', $lang) !== 1) {
                throw $this->invalid();
            }
            $attributes['lang'] = strtolower($lang);
        }
        if (array_key_exists('dir', $raw)) {
            $dir = strtolower(trim($raw['dir']));
            if (!in_array($dir, ['auto', 'ltr', 'rtl'], true)) {
                throw $this->invalid();
            }
            $attributes['dir'] = $dir;
        }
        if (array_key_exists('role', $raw)) {
            $role = strtolower(trim($raw['role']));
            if (!in_array($role, self::ALLOWED_ROLES, true)) {
                throw $this->invalid();
            }
            $attributes['role'] = $role;
        }
        $attributes = array_merge(
            $attributes,
            $this->formatAttributes($raw, $tag)
        );
        foreach ($raw as $name => $value) {
            if (
                !str_starts_with($name, 'data-content-')
                || in_array($name, self::FORMAT_ATTRIBUTES, true)
            ) {
                continue;
            }
            $attributes[$name] = $this->plainAttribute(
                $value,
                self::MAX_ATTRIBUTE_VALUE_BYTES
            );
        }
        if ($tag === 'aside') {
            if (($attributes['role'] ?? 'note') !== 'note') {
                throw $this->invalid();
            }
            $attributes['role'] = 'note';
            $attributes['data-content-callout'] = 'true';
        }

        if ($tag === 'a') {
            if (!array_key_exists('href', $raw)) {
                throw $this->invalid();
            }
            $href = $this->safeUrl($raw['href']);
            if (str_starts_with($href, '#')) {
                $references[] = substr($href, 1);
            }
            $attributes['href'] = $href;
            $target = strtolower(trim($raw['target'] ?? '_self'));
            if (!in_array($target, ['_blank', '_self'], true)) {
                throw $this->invalid();
            }
            if ($target === '_blank') {
                $attributes['target'] = '_blank';
            }
            $rel = $this->relTokens($raw['rel'] ?? '');
            if ($target === '_blank') {
                $rel = array_values(array_unique(array_merge(
                    $rel,
                    ['noopener', 'noreferrer']
                )));
                sort($rel, SORT_STRING);
            }
            if ($rel !== []) {
                $attributes['rel'] = implode(' ', $rel);
            }
        } elseif ($tag === 'blockquote' && array_key_exists('cite', $raw)) {
            $attributes['cite'] = $this->safeUrl($raw['cite'], false);
        } elseif ($tag === 'ol') {
            if (array_key_exists('start', $raw)) {
                $attributes['start'] = $this->boundedInteger($raw['start']);
            }
            if (array_key_exists('reversed', $raw)) {
                $attributes['reversed'] = 'reversed';
            }
            if (array_key_exists('type', $raw)) {
                $type = trim($raw['type']);
                if (!in_array($type, ['1', 'A', 'I', 'a', 'i'], true)) {
                    throw $this->invalid();
                }
                $attributes['type'] = $type;
            }
        } elseif ($tag === 'li' && array_key_exists('value', $raw)) {
            $attributes['value'] = $this->boundedInteger($raw['value']);
        } elseif ($tag === 'iframe') {
            $iframe = (new BlogSafeIframePolicy())->canonicalAttributes($raw);
            if ($iframe === null) {
                throw $this->invalid();
            }
            $attributes = array_merge($attributes, $iframe);
        }

        ksort($attributes, SORT_STRING);
        foreach ($attributes as $name => $value) {
            $element->setAttribute($name, $value);
        }
    }

    /**
     * @param array<string, string> $raw
     * @return array<string, string>
     */
    private function formatAttributes(array $raw, string $tag): array
    {
        $present = array_values(array_filter(
            self::FORMAT_ATTRIBUTES,
            static fn (string $name): bool => array_key_exists($name, $raw)
        ));
        if ($present === []) {
            return [];
        }
        if ($tag !== 'span') {
            throw $this->invalid();
        }
        if (
            array_key_exists('data-content-format-text-color', $raw)
            && array_key_exists('data-content-format-text-rgba', $raw)
        ) {
            throw $this->invalid();
        }
        if (
            array_key_exists('data-content-format-background-color', $raw)
            && array_key_exists('data-content-format-background-rgba', $raw)
        ) {
            throw $this->invalid();
        }

        $attributes = [];
        foreach ($present as $name) {
            $value = $this->plainAttribute(
                $raw[$name],
                self::MAX_ATTRIBUTE_VALUE_BYTES
            );
            if ($name === 'data-content-format-size') {
                if (!in_array($value, self::FORMAT_SIZES, true)) {
                    throw $this->invalid();
                }
                $attributes[$name] = $value;
                continue;
            }
            if (
                $name === 'data-content-format-text-color'
                || $name === 'data-content-format-background-color'
            ) {
                if (!in_array($value, self::FORMAT_COLORS, true)) {
                    throw $this->invalid();
                }
                $attributes[$name] = $value;
                continue;
            }
            try {
                $color = BlogPublicColor::fromInput($value);
            } catch (InvalidArgumentException) {
                throw $this->invalid();
            }
            if ($color->kind() !== BlogPublicColor::RGBA) {
                throw $this->invalid();
            }
            $attributes[$name] = $color->value();
        }

        return $attributes;
    }

    private function projectFormatAttributesForRender(
        DOMElement $element
    ): void {
        $classes = [];
        $renderAttributes = [];
        if ($element->hasAttribute('data-content-format-size')) {
            $classes[] = 'blogDocument__inline--size-'
                . $element->getAttribute('data-content-format-size');
        }
        if ($element->hasAttribute('data-content-format-text-color')) {
            $classes[] = 'blogDocument__inline--text-'
                . $element->getAttribute('data-content-format-text-color');
        }
        if ($element->hasAttribute('data-content-format-background-color')) {
            $classes[] = 'blogDocument__inline--background-'
                . $element->getAttribute('data-content-format-background-color');
        }
        if ($element->hasAttribute('data-content-format-text-rgba')) {
            $classes[] = 'blogDocument__inline--text-rgba';
            $renderAttributes['data-blog-inline-text-rgba'] =
                $element->getAttribute('data-content-format-text-rgba');
        }
        if ($element->hasAttribute('data-content-format-background-rgba')) {
            $classes[] = 'blogDocument__inline--background-rgba';
            $renderAttributes['data-blog-inline-background-rgba'] =
                $element->getAttribute('data-content-format-background-rgba');
        }
        if ($classes === []) {
            return;
        }

        $userClasses = $element->hasAttribute('class')
            ? preg_split('/\s+/', trim($element->getAttribute('class'))) ?: []
            : [];
        $element->setAttribute('class', implode(' ', array_merge(
            $userClasses,
            ['blogDocument__inline'],
            $classes
        )));
        foreach (self::FORMAT_ATTRIBUTES as $attribute) {
            $element->removeAttribute($attribute);
        }
        foreach ($renderAttributes as $name => $value) {
            $element->setAttribute($name, $value);
        }
    }

    private function classes(string $raw): string
    {
        $tokens = preg_split('/\s+/', trim($raw)) ?: [];
        if ($tokens === [] || count($tokens) > self::MAX_CLASSES_PER_ELEMENT) {
            throw $this->invalid();
        }
        $classes = [];
        foreach ($tokens as $token) {
            $lower = strtolower($token);
            if (preg_match(self::TOKEN, $token) !== 1) {
                throw $this->invalid();
            }
            foreach (self::RESERVED_CLASS_PREFIXES as $prefix) {
                if (str_starts_with($lower, $prefix)) {
                    throw $this->invalid();
                }
            }
            $classes[$token] = true;
        }
        $classes = array_keys($classes);
        sort($classes, SORT_STRING);

        return implode(' ', $classes);
    }

    /** @return list<string> */
    private function referenceTokens(string $raw): array
    {
        $tokens = preg_split('/\s+/', trim($raw)) ?: [];
        if ($tokens === [] || count($tokens) > self::MAX_IDREFS_PER_ATTRIBUTE) {
            throw $this->invalid();
        }
        $unique = [];
        foreach ($tokens as $token) {
            if (preg_match(self::TOKEN, $token) !== 1) {
                throw $this->invalid();
            }
            $unique[$token] = true;
        }

        return array_keys($unique);
    }

    /** @return list<string> */
    private function relTokens(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $tokens = preg_split('/\s+/', strtolower(trim($raw))) ?: [];
        $normalized = [];
        foreach ($tokens as $token) {
            if (!in_array($token, self::SAFE_REL_TOKENS, true)) {
                throw $this->invalid();
            }
            $normalized[$token] = true;
        }
        $normalized = array_keys($normalized);
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    private function safeUrl(string $raw, bool $allowFragment = true): string
    {
        $url = trim($raw);
        if (
            $url === ''
            || strlen($url) > BlogDocumentValidator::MAX_URL_BYTES
            || preg_match('/[\x00-\x20\x7F]/', $url) === 1
            || str_contains($url, '\\')
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $url) === 1
        ) {
            throw $this->invalid();
        }
        if ($allowFragment && str_starts_with($url, '#')) {
            return preg_match('/\A#[A-Za-z_][A-Za-z0-9_-]{0,63}\z/D', $url) === 1
                ? $url : throw $this->invalid();
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            $decoded = $url;
            $stable = false;
            for ($pass = 0; $pass < 8; ++$pass) {
                if (
                    preg_match('/%(?![0-9A-Fa-f]{2})/', $decoded) === 1
                    || preg_match('/%(?:2f|5c)/i', $decoded) === 1
                ) {
                    throw $this->invalid();
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
                || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
                || str_contains($decoded, '\\')
                || str_contains($decoded, '//')
            ) {
                throw $this->invalid();
            }
            foreach (explode('/', $decoded) as $segment) {
                if ($segment === '.' || $segment === '..') {
                    throw $this->invalid();
                }
            }

            return $url;
        }
        if (str_starts_with($url, 'mailto:')) {
            $address = substr($url, 7);
            if (!str_contains($address, '?')
                && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                return $url;
            }
            throw $this->invalid();
        }
        if (preg_match('/\Atel:\+?[0-9][0-9 .()\-]{2,31}\z/D', $url) === 1) {
            return $url;
        }
        try {
            $parts = parse_url($url);
        } catch (ValueError) {
            throw $this->invalid();
        }
        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw $this->invalid();
        }

        return $url;
    }

    private function plainAttribute(string $raw, int $maxBytes): string
    {
        $value = trim($raw);
        if (
            $value === ''
            || strlen($value) > $maxBytes
            || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw $this->invalid();
        }

        return $value;
    }

    private function boundedInteger(string $raw): string
    {
        $value = trim($raw);
        if (preg_match('/\A-?[0-9]{1,6}\z/D', $value) !== 1) {
            throw $this->invalid();
        }

        return (string) (int) $value;
    }

    /** @return list<DOMElement> */
    private function elements(DOMElement $root): array
    {
        $elements = [];
        $visit = static function (DOMNode $parent) use (&$visit, &$elements): void {
            foreach (iterator_to_array($parent->childNodes) as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }
                $elements[] = $child;
                $visit($child);
            }
        };
        $visit($root);

        return $elements;
    }

    private function plainTextFromRoot(DOMNode $root): string
    {
        $text = '';
        foreach (iterator_to_array($root->childNodes) as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $text .= $node->nodeValue ?? '';
                continue;
            }
            if (!$node instanceof DOMElement) {
                continue;
            }
            if ($node->tagName === 'br') {
                $text .= "\n";
                continue;
            }
            $text .= $this->plainTextFromRoot($node);
            if (in_array($node->tagName, self::BLOCK_TAGS, true)) {
                $text .= "\n";
            }
        }

        return $text;
    }

    private function hasMeaningfulContent(DOMNode $root): bool
    {
        if (trim($this->plainTextFromRoot($root)) !== '') {
            return true;
        }
        foreach ($this->elements($root instanceof DOMElement ? $root : throw $this->invalid()) as $element) {
            if (strtolower($element->tagName) === 'iframe') {
                return true;
            }
        }

        return false;
    }

    /** @return array{level: int, text: string}|null */
    private function headingProjection(DOMElement $element): ?array
    {
        $tag = strtolower($element->tagName);
        if (!in_array($tag, self::HEADING_TAGS, true)) {
            return null;
        }
        $text = preg_replace(
            '/\s+/u',
            ' ',
            $this->plainTextFromRoot($element)
        );
        if (!is_string($text)) {
            throw $this->invalid();
        }

        return ['level' => (int) substr($tag, 1), 'text' => trim($text)];
    }

    private function innerHtml(DOMDocument $document, DOMElement $root): string
    {
        $html = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $fragment = $document->saveHTML($child);
            if (!is_string($fragment)) {
                throw $this->invalid();
            }
            $html .= $fragment;
        }

        return trim($html);
    }

    private function invalid(): BlogDocumentException
    {
        return new BlogDocumentException(BlogDocumentException::INVALID_BLOCK);
    }
}
