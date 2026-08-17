<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Canonical allowlist sanitizer for the V2 HTML / embed module.
 *
 * The sanitized fragment is the value persisted in the canonical document,
 * so the public renderer never has to trust or reinterpret raw editor HTML.
 */
final class BlogEmbedHtmlSanitizer
{
    public const MAX_HTML_BYTES = 50_000;

    private const TOKEN = '/\A[A-Za-z_][A-Za-z0-9_-]{0,63}\z/D';

    private const ALLOWED_TAGS = [
        'a', 'b', 'blockquote', 'br', 'cite', 'code', 'div', 'em',
        'figcaption', 'figure', 'i', 'iframe', 'img', 'li', 'ol', 'p',
        'pre', 'span', 'strong', 'u', 'ul',
    ];
    private const DROP_WITH_CONTENT = [
        'applet', 'audio', 'base', 'button', 'embed', 'form', 'frame',
        'frameset', 'input', 'link', 'meta', 'noscript', 'object', 'script',
        'select', 'source', 'style', 'textarea', 'track', 'video',
    ];
    private readonly BlogSafeIframePolicy $iframePolicy;

    public function __construct(?BlogSafeIframePolicy $iframePolicy = null)
    {
        $this->iframePolicy = $iframePolicy ?? new BlogSafeIframePolicy();
    }

    /** @return array<string, mixed> */
    public function policy(): array
    {
        return [
            'tags' => self::ALLOWED_TAGS,
            'global_attributes' => ['class', 'id', 'title', 'aria-label'],
            'special_attributes' => [
                'a' => ['href', 'target', 'rel'],
                'img' => ['src', 'alt', 'width', 'height', 'loading', 'decoding'],
                'iframe' => BlogSafeIframePolicy::attributes(),
            ],
            'iframe_sources' => BlogSafeIframePolicy::sources(),
            'max_html_bytes' => self::MAX_HTML_BYTES,
        ];
    }

    public function sanitize(mixed $html): string
    {
        if (
            !is_string($html)
            || trim($html) === ''
            || strlen($html) > self::MAX_HTML_BYTES
            || preg_match('//u', $html) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $html) === 1
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        if (!class_exists(DOMDocument::class)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><div data-liquidstack-embed-root="1">'
                    . $html . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($loaded !== true) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }

        $root = $this->findRoot($document);
        if ($root === null) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $ids = [];
        $this->sanitizeChildren($root, $ids);

        $canonical = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $fragment = $document->saveHTML($child);
            if (!is_string($fragment)) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_BLOCK
                );
            }
            $canonical .= $fragment;
        }
        $canonical = trim($canonical);
        if ($canonical === '' || strlen($canonical) > self::MAX_HTML_BYTES) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }

        return $canonical;
    }

    /** Namespaces editor classes and ids so Embed CSS cannot leak or miss. */
    public function namespaceForRender(string $html, string $blockId): string
    {
        $canonical = $this->sanitize($html);
        $prefix = (new BlogCustomTextHtmlSanitizer())
            ->namespacePrefix($blockId);
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><div data-liquidstack-embed-root="1">'
                    . $canonical . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $loaded === true ? $this->findRoot($document) : null;
        if ($root === null) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        foreach ($root->getElementsByTagName('*') as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }
            if ($element->hasAttribute('id')) {
                $element->setAttribute(
                    'id',
                    $prefix . $element->getAttribute('id')
                );
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
                strtolower($element->tagName) === 'a'
                && str_starts_with($element->getAttribute('href'), '#')
            ) {
                $element->setAttribute(
                    'href',
                    '#' . $prefix . substr($element->getAttribute('href'), 1)
                );
            }
        }

        $rendered = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $fragment = $document->saveHTML($child);
            if (!is_string($fragment)) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_BLOCK
                );
            }
            $rendered .= $fragment;
        }

        return trim($rendered);
    }

    private function findRoot(DOMDocument $document): ?DOMElement
    {
        foreach ($document->getElementsByTagName('div') as $element) {
            if ($element->getAttribute('data-liquidstack-embed-root') === '1') {
                $element->removeAttribute('data-liquidstack-embed-root');

                return $element;
            }
        }

        return null;
    }

    /** @param array<string, true> $ids */
    private function sanitizeChildren(DOMNode $parent, array &$ids): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                continue;
            }
            if (!$node instanceof DOMElement) {
                $parent->removeChild($node);
                continue;
            }

            $tag = strtolower($node->tagName);
            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $parent->removeChild($node);
                continue;
            }
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->sanitizeChildren($node, $ids);
                while ($node->firstChild !== null) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }
            if (!$this->sanitizeElement($node, $tag, $ids)) {
                $parent->removeChild($node);
                continue;
            }
            if ($tag !== 'iframe' && $tag !== 'img') {
                $this->sanitizeChildren($node, $ids);
            } else {
                while ($node->firstChild !== null) {
                    $node->removeChild($node->firstChild);
                }
            }
        }
    }

    /** @param array<string, true> $ids */
    private function sanitizeElement(
        DOMElement $element,
        string $tag,
        array &$ids
    ): bool
    {
        $raw = [];
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            if (str_starts_with($name, 'on')) {
                continue;
            }
            $raw[$name] = trim($attribute->value);
        }
        while ($element->attributes->length > 0) {
            $attribute = $element->attributes->item(0);
            if ($attribute !== null) {
                $element->removeAttributeNode($attribute);
            }
        }

        $attributes = [];
        $id = $raw['id'] ?? null;
        if (
            is_string($id)
            && preg_match(self::TOKEN, $id) === 1
            && !isset($ids[$id])
        ) {
            $ids[$id] = true;
            $attributes['id'] = $id;
        }
        $class = $this->classNames($raw['class'] ?? null);
        if ($class !== null) {
            $attributes['class'] = $class;
        }
        $title = $this->plainAttribute($raw['title'] ?? null, 500);
        if ($title !== null) {
            $attributes['title'] = $title;
        }
        $ariaLabel = $this->plainAttribute($raw['aria-label'] ?? null, 500);
        if ($ariaLabel !== null) {
            $attributes['aria-label'] = $ariaLabel;
        }

        if ($tag === 'a') {
            $href = $this->safeLinkUrl($raw['href'] ?? null);
            if ($href === null) {
                return false;
            }
            $attributes['href'] = $href;
            $target = ($raw['target'] ?? '') === '_blank' ? '_blank' : null;
            if ($target !== null) {
                $attributes['target'] = '_blank';
                $attributes['rel'] = 'noopener noreferrer';
            }
        } elseif ($tag === 'img') {
            $src = $this->safeImageUrl($raw['src'] ?? null);
            if ($src === null) {
                return false;
            }
            $attributes['src'] = $src;
            $attributes['alt'] = $this->plainAttribute(
                $raw['alt'] ?? '',
                BlogDocumentValidator::MAX_ALT_BYTES,
                true
            ) ?? '';
            $attributes['loading'] = 'lazy';
            $attributes['decoding'] = 'async';
            $this->copyDimensions($raw, $attributes);
        } elseif ($tag === 'iframe') {
            $iframe = $this->iframePolicy->canonicalAttributes($raw);
            if ($iframe === null) {
                return false;
            }
            $attributes = array_merge($attributes, $iframe);
        }

        ksort($attributes, SORT_STRING);
        foreach ($attributes as $name => $value) {
            $element->setAttribute($name, $value);
        }

        return true;
    }

    /** @param array<string, string> $raw @param array<string, string> $target */
    private function copyDimensions(array $raw, array &$target): void
    {
        foreach (['height', 'width'] as $name) {
            $value = $raw[$name] ?? null;
            if (
                is_string($value)
                && preg_match('/\A[1-9][0-9]{0,3}\z/', $value) === 1
            ) {
                $target[$name] = $value;
            }
        }
    }

    private function classNames(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $tokens = preg_split('/\s+/', trim($value)) ?: [];
        $tokens = array_values(array_unique(array_filter(
            $tokens,
            static fn (string $token): bool =>
                strlen($token) <= 64
                && preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*\z/', $token) === 1
        )));
        sort($tokens, SORT_STRING);

        return $tokens === [] ? null : implode(' ', array_slice($tokens, 0, 16));
    }

    private function plainAttribute(
        mixed $value,
        int $maxBytes,
        bool $allowEmpty = false
    ): ?string {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (
            (!$allowEmpty && $value === '')
            || strlen($value) > $maxBytes
            || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            return null;
        }

        return $value;
    }

    private function safeLinkUrl(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (str_starts_with($value, '#')) {
            return preg_match('/\A#[A-Za-z][A-Za-z0-9_-]*\z/', $value) === 1
                ? $value : null;
        }
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return preg_match('/[\s\\]/u', $value) === 1 ? null : $value;
        }
        if (str_starts_with($value, 'mailto:')) {
            $email = substr($value, 7);

            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
                ? $value : null;
        }
        if (
            str_starts_with($value, 'tel:')
            && preg_match('/\Atel:\+?[0-9][0-9 .()\-]{2,31}\z/', $value) === 1
        ) {
            return $value;
        }

        return $this->safeHttpsUrl($value) ? $value : null;
    }

    private function safeImageUrl(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return str_starts_with($value, '/')
            && !str_starts_with($value, '//')
            && preg_match('/[\s\\]/u', $value) !== 1
                ? $value : null;
    }

    private function safeHttpsUrl(string $value): bool
    {
        if (
            $value === ''
            || strlen($value) > BlogDocumentValidator::MAX_URL_BYTES
            || preg_match('/\s/u', $value) === 1
            || str_contains($value, '\\')
            || filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            return false;
        }
        $parts = parse_url($value);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_string($parts['host'] ?? null)
            && ($parts['host'] ?? '') !== ''
            && !array_key_exists('user', $parts)
            && !array_key_exists('pass', $parts);
    }
}
