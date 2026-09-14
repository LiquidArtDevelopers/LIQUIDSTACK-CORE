<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

/**
 * Small fail-closed CSS parser for advanced Text modules.
 *
 * It accepts ordinary style rules, native CSS nesting and bounded @media or
 * @supports blocks. The public projection nests the result inside an
 * immutable UUID selector; class and id tokens are rewritten exactly like the
 * HTML projection. This deliberately is not a general-purpose CSS parser.
 */
final class BlogCustomTextCssSanitizer
{
    public const MAX_CSS_BYTES = 30_000;
    public const MAX_RENDERED_CSS_BYTES = 600_000;

    private const MAX_DEPTH = 6;
    private const MAX_RULES = 128;
    private const MAX_DECLARATIONS = 256;
    private const MAX_SELECTOR_BYTES = 512;
    private const MAX_VALUE_BYTES = 2_048;
    private const MAX_NUMERIC_TOKENS_PER_VALUE = 64;
    private const MAX_ROOT_NUMERIC_TOTAL = 4_096;
    private const TOKEN = '/\A[A-Za-z_][A-Za-z0-9_-]{0,63}\z/D';
    private const ALLOWED_TAGS = [
        'a', 'aside', 'b', 'blockquote', 'br', 'cite', 'code', 'div', 'em',
        'figcaption', 'figure', 'h2', 'h3', 'h4', 'h5', 'h6', 'i', 'iframe',
        'img', 'li', 'mark', 'ol', 'p', 'pre', 'small', 'span', 'strong',
        'sub', 'sup', 'u', 'ul',
    ];
    private const SIMPLE_PSEUDOS = [
        'active', 'checked', 'disabled', 'empty', 'enabled', 'first-child',
        'first-of-type', 'focus', 'focus-visible', 'focus-within', 'hover',
        'last-child', 'last-of-type', 'link', 'only-child', 'only-of-type',
        'required', 'target', 'visited',
    ];
    private const PSEUDO_ELEMENTS = ['after', 'before', 'first-letter', 'first-line', 'marker'];
    private const ALLOWED_PROPERTIES = [
        'align-content', 'align-items', 'align-self', 'aspect-ratio',
        'background', 'background-color', 'background-position',
        'background-repeat', 'background-size', 'block-size', 'border',
        'border-block', 'border-block-color', 'border-block-end',
        'border-block-start', 'border-block-style', 'border-block-width',
        'border-bottom', 'border-bottom-color', 'border-bottom-left-radius',
        'border-bottom-right-radius', 'border-bottom-style',
        'border-bottom-width', 'border-collapse', 'border-color',
        'border-inline', 'border-inline-color', 'border-inline-end',
        'border-inline-start', 'border-inline-style', 'border-inline-width',
        'border-left', 'border-left-color', 'border-left-style',
        'border-left-width', 'border-radius', 'border-right',
        'border-right-color', 'border-right-style', 'border-right-width',
        'border-spacing', 'border-style', 'border-top', 'border-top-color',
        'border-top-left-radius', 'border-top-right-radius',
        'border-top-style', 'border-top-width', 'border-width', 'box-shadow',
        'box-sizing', 'break-after', 'break-before', 'break-inside',
        'caret-color', 'clear', 'color', 'column-count', 'column-gap',
        'column-rule', 'column-rule-color', 'column-rule-style',
        'column-rule-width', 'column-width', 'content', 'cursor', 'direction',
        'display', 'flex', 'flex-basis', 'flex-direction', 'flex-flow',
        'flex-grow', 'flex-shrink', 'flex-wrap', 'float', 'font',
        'font-family', 'font-feature-settings', 'font-kerning', 'font-size',
        'font-stretch', 'font-style', 'font-variant', 'font-variant-caps',
        'font-weight', 'gap', 'grid', 'grid-area', 'grid-auto-columns',
        'grid-auto-flow', 'grid-auto-rows', 'grid-column', 'grid-column-end',
        'grid-column-start', 'grid-row', 'grid-row-end', 'grid-row-start',
        'grid-template', 'grid-template-areas', 'grid-template-columns',
        'grid-template-rows', 'height', 'hyphens', 'inline-size',
        'inset', 'inset-block', 'inset-block-end', 'inset-block-start',
        'inset-inline', 'inset-inline-end', 'inset-inline-start',
        'justify-content', 'justify-items', 'justify-self', 'left',
        'letter-spacing', 'line-break', 'line-height', 'list-style',
        'list-style-position', 'list-style-type', 'margin', 'margin-block',
        'margin-block-end', 'margin-block-start', 'margin-bottom',
        'margin-inline', 'margin-inline-end', 'margin-inline-start',
        'margin-left', 'margin-right', 'margin-top', 'max-block-size',
        'max-height', 'max-inline-size', 'max-width', 'min-block-size',
        'min-height', 'min-inline-size', 'min-width', 'object-fit',
        'object-position', 'opacity', 'order', 'orphans', 'outline',
        'outline-color', 'outline-offset', 'outline-style', 'outline-width',
        'overflow', 'overflow-wrap', 'overflow-x', 'overflow-y', 'padding',
        'padding-block', 'padding-block-end', 'padding-block-start',
        'padding-bottom', 'padding-inline', 'padding-inline-end',
        'padding-inline-start', 'padding-left', 'padding-right',
        'padding-top', 'place-content', 'place-items', 'place-self',
        'pointer-events', 'position', 'right', 'row-gap', 'tab-size',
        'table-layout', 'text-align', 'text-align-last', 'text-decoration',
        'text-decoration-color', 'text-decoration-line',
        'text-decoration-style', 'text-decoration-thickness', 'text-indent',
        'text-overflow', 'text-shadow', 'text-transform',
        'text-underline-offset', 'top', 'transform', 'transform-origin',
        'transition', 'transition-delay', 'transition-duration',
        'transition-property', 'transition-timing-function',
        'unicode-bidi', 'vertical-align', 'white-space', 'widows', 'width',
        'word-break', 'word-spacing', 'writing-mode',
    ];
    /** Properties that cannot reposition or enlarge the security wrapper. */
    private const ROOT_ALLOWED_PROPERTIES = [
        'background', 'background-color', 'background-position',
        'background-repeat', 'background-size', 'border', 'border-block',
        'border-block-color', 'border-block-end', 'border-block-start',
        'border-block-style', 'border-block-width', 'border-bottom',
        'border-bottom-color', 'border-bottom-left-radius',
        'border-bottom-right-radius', 'border-bottom-style',
        'border-bottom-width', 'border-color', 'border-inline',
        'border-inline-color', 'border-inline-end', 'border-inline-start',
        'border-inline-style', 'border-inline-width', 'border-left',
        'border-left-color', 'border-left-style', 'border-left-width',
        'border-radius', 'border-right', 'border-right-color',
        'border-right-style', 'border-right-width', 'border-style',
        'border-top', 'border-top-color', 'border-top-left-radius',
        'border-top-right-radius', 'border-top-style', 'border-top-width',
        'border-width', 'box-sizing', 'caret-color', 'color', 'direction',
        'font', 'font-family', 'font-feature-settings', 'font-kerning',
        'font-size', 'font-stretch', 'font-style', 'font-variant',
        'font-variant-caps', 'font-weight', 'hyphens', 'letter-spacing',
        'line-break', 'line-height', 'list-style', 'list-style-position',
        'list-style-type', 'padding', 'padding-block', 'padding-block-end',
        'padding-block-start', 'padding-bottom', 'padding-inline',
        'padding-inline-end', 'padding-inline-start', 'padding-left',
        'padding-right', 'padding-top', 'text-align', 'text-align-last',
        'text-decoration', 'text-decoration-color', 'text-decoration-line',
        'text-decoration-style', 'text-decoration-thickness', 'text-indent',
        'text-overflow', 'text-shadow', 'text-transform',
        'text-underline-offset', 'white-space', 'word-break', 'word-spacing',
    ];
    private const RESERVED_CLASS_PREFIXES = [
        'blogdocument', 'blog-', 'liquidstack', 'ls-', 'webadmin',
    ];

    private int $ruleCount = 0;
    private int $declarationCount = 0;

    /** @return array<string, mixed> */
    public function policy(): array
    {
        return [
            'properties' => self::ALLOWED_PROPERTIES,
            'root_properties' => self::ROOT_ALLOWED_PROPERTIES,
            'tags' => self::ALLOWED_TAGS,
            'at_rules' => ['media', 'supports'],
            'simple_pseudos' => self::SIMPLE_PSEUDOS,
            'pseudo_elements' => self::PSEUDO_ELEMENTS,
            'attribute_selectors' => [
                'aria-hidden', 'dir', 'lang', 'data-content-*',
            ],
            'reserved_class_prefixes' => self::RESERVED_CLASS_PREFIXES,
            'max_css_bytes' => self::MAX_CSS_BYTES,
            'max_rendered_css_bytes' => self::MAX_RENDERED_CSS_BYTES,
            'max_depth' => self::MAX_DEPTH,
            'max_rules' => self::MAX_RULES,
            'max_declarations' => self::MAX_DECLARATIONS,
            'max_selector_bytes' => self::MAX_SELECTOR_BYTES,
            'max_value_bytes' => self::MAX_VALUE_BYTES,
            'max_numeric_tokens_per_value' =>
                self::MAX_NUMERIC_TOKENS_PER_VALUE,
            'max_root_numeric_total' => self::MAX_ROOT_NUMERIC_TOTAL,
        ];
    }

    public function sanitize(mixed $css): string
    {
        if (!is_string($css)) {
            throw $this->invalid();
        }
        if (preg_match('/\A[ \t\r\n]*\z/D', $css) === 1) {
            return '';
        }

        return $this->parseCanonical($css, null);
    }

    /** Emits a complete nonce-able stylesheet, never a style attribute. */
    public function renderScoped(string $css, string $blockId): string
    {
        if ($css === '') {
            return '';
        }
        $prefix = (new BlogCustomTextHtmlSanitizer())
            ->namespacePrefix($blockId);
        $rules = $this->parseCanonical($css, $prefix);

        return '[data-ls-blog-custom="' . $blockId . '"]{'
            . 'position:relative!important;isolation:isolate!important;'
            . 'contain:paint!important;overflow:clip!important;'
            . 'box-sizing:border-box!important;max-inline-size:100%!important;'
            . $rules . '}';
    }

    private function parseCanonical(string $source, ?string $namespace): string
    {
        if (
            strlen($source) > self::MAX_CSS_BYTES
            || preg_match('//u', $source) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $source) === 1
            || str_contains($source, '\\')
            || str_contains($source, '<')
            || str_contains($source, "\u{2028}")
            || str_contains($source, "\u{2029}")
        ) {
            throw $this->invalid();
        }
        $source = $this->stripComments($source);
        $this->ruleCount = 0;
        $this->declarationCount = 0;
        $offset = 0;
        $canonical = $this->parseBlock(
            $source,
            $offset,
            true,
            0,
            0,
            $namespace,
            false
        );
        $this->skipWhitespace($source, $offset);
        if ($offset !== strlen($source)) {
            throw $this->invalid();
        }
        $outputLimit = $namespace === null
            ? self::MAX_CSS_BYTES
            : self::MAX_RENDERED_CSS_BYTES;
        if (strlen($canonical) > $outputLimit || $canonical === '') {
            throw $this->invalid();
        }

        return $canonical;
    }

    private function stripComments(string $source): string
    {
        $result = '';
        $length = strlen($source);
        $quote = null;
        for ($index = 0; $index < $length; ++$index) {
            $char = $source[$index];
            if ($quote !== null) {
                if ($char === "\n" || $char === "\r") {
                    throw $this->invalid();
                }
                $result .= $char;
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $result .= $char;
                continue;
            }
            if ($char === '/' && ($source[$index + 1] ?? '') === '*') {
                $end = strpos($source, '*/', $index + 2);
                if ($end === false) {
                    throw $this->invalid();
                }
                $result .= ' ';
                $index = $end + 1;
                continue;
            }
            $result .= $char;
        }
        if ($quote !== null) {
            throw $this->invalid();
        }

        return trim($result);
    }

    private function parseBlock(
        string $source,
        int &$offset,
        bool $allowDeclarations,
        int $depth,
        int $selectorDepth,
        ?string $namespace,
        bool $expectsClosingBrace
    ): string {
        if ($depth > self::MAX_DEPTH) {
            throw $this->invalid();
        }
        $result = '';
        $length = strlen($source);
        while (true) {
            $this->skipWhitespace($source, $offset);
            if ($offset >= $length) {
                if ($expectsClosingBrace) {
                    throw $this->invalid();
                }

                return $result;
            }
            if ($source[$offset] === '}') {
                if (!$expectsClosingBrace) {
                    throw $this->invalid();
                }
                ++$offset;

                return $result;
            }

            [$segment, $delimiter] = $this->readStatement($source, $offset);
            $segment = trim($segment);
            if ($segment === '') {
                throw $this->invalid();
            }
            if ($delimiter === ';') {
                if (!$allowDeclarations) {
                    throw $this->invalid();
                }
                $result .= $this->declaration(
                    $segment,
                    $selectorDepth === 0
                );
                continue;
            }
            if ($delimiter === '}') {
                if (!$expectsClosingBrace || !$allowDeclarations) {
                    throw $this->invalid();
                }
                $result .= $this->declaration(
                    $segment,
                    $selectorDepth === 0
                );

                return $result;
            }
            if ($delimiter !== '{' || ++$this->ruleCount > self::MAX_RULES) {
                throw $this->invalid();
            }

            if (str_starts_with($segment, '@')) {
                $atRule = $this->atRule($segment);
                $inner = $this->parseBlock(
                    $source,
                    $offset,
                    $allowDeclarations,
                    $depth + 1,
                    $selectorDepth,
                    $namespace,
                    true
                );
                if ($inner === '') {
                    throw $this->invalid();
                }
                $result .= $atRule . '{' . $inner . '}';
                continue;
            }

            $selector = $this->selector(
                $segment,
                $selectorDepth,
                $namespace
            );
            $inner = $this->parseBlock(
                $source,
                $offset,
                true,
                $depth + 1,
                $selectorDepth + 1,
                $namespace,
                true
            );
            if ($inner === '') {
                throw $this->invalid();
            }
            $result .= $selector . '{' . $inner . '}';
        }
    }

    /** @return array{string, string} */
    private function readStatement(string $source, int &$offset): array
    {
        $start = $offset;
        $length = strlen($source);
        $quote = null;
        $parentheses = 0;
        $brackets = 0;
        for (; $offset < $length; ++$offset) {
            $char = $source[$offset];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '(') {
                ++$parentheses;
                continue;
            }
            if ($char === ')') {
                if (--$parentheses < 0) {
                    throw $this->invalid();
                }
                continue;
            }
            if ($char === '[') {
                ++$brackets;
                continue;
            }
            if ($char === ']') {
                if (--$brackets < 0) {
                    throw $this->invalid();
                }
                continue;
            }
            if ($parentheses !== 0 || $brackets !== 0) {
                continue;
            }
            if ($char === ';' || $char === '{' || $char === '}') {
                $segment = substr($source, $start, $offset - $start);
                ++$offset;

                return [$segment, $char];
            }
        }
        if ($quote !== null || $parentheses !== 0 || $brackets !== 0) {
            throw $this->invalid();
        }

        return [substr($source, $start), ''];
    }

    private function declaration(string $segment, bool $root): string
    {
        if (++$this->declarationCount > self::MAX_DECLARATIONS) {
            throw $this->invalid();
        }
        $colon = strpos($segment, ':');
        if ($colon === false) {
            throw $this->invalid();
        }
        $property = strtolower(trim(substr($segment, 0, $colon)));
        $value = trim(substr($segment, $colon + 1));
        if (
            !in_array($property, self::ALLOWED_PROPERTIES, true)
            || ($root && !in_array(
                $property,
                self::ROOT_ALLOWED_PROPERTIES,
                true
            ))
            || $value === ''
            || strlen($value) > self::MAX_VALUE_BYTES
            || str_contains($value, '!')
            || preg_match(
                '/(?:\b(?:attr|calc|clamp|cross-fade|element|env|expression|'
                    . 'image|image-set|max|min|paint|repeat|src|url|var|'
                    . '-moz-element|'
                    . '-webkit-cross-fade)\s*\(|javascript\s*:|data\s*:|'
                    . 'blob\s*:|https?\s*:|\/\/|@)/i',
                $value
            ) === 1
        ) {
            throw $this->invalid();
        }
        if ($property === 'position'
            && !in_array(strtolower($value), ['absolute', 'relative', 'static'], true)) {
            throw $this->invalid();
        }
        if ($property === 'display' && strtolower($value) === 'contents') {
            throw $this->invalid();
        }
        $magnitudeSource = preg_replace(
            '/(?<![A-Za-z0-9_-])#(?:[0-9A-Fa-f]{8}|[0-9A-Fa-f]{6}|'
                . '[0-9A-Fa-f]{4}|[0-9A-Fa-f]{3})\b/',
            '',
            $value
        );
        if (!is_string($magnitudeSource)) {
            throw $this->invalid();
        }
        if (preg_match('/(?:[0-9]|\.)[eE][+-]?[0-9]/', $magnitudeSource) === 1) {
            throw $this->invalid();
        }
        preg_match_all(
            '/(?<![A-Za-z0-9_-])-?[0-9]+(?:\.[0-9]+)?/',
            $magnitudeSource,
            $numbers
        );
        if (count($numbers[0] ?? []) > self::MAX_NUMERIC_TOKENS_PER_VALUE) {
            throw $this->invalid();
        }
        $numericTotal = 0.0;
        foreach (($numbers[0] ?? []) as $number) {
            $numeric = abs((float) $number);
            if ($numeric > 100_000) {
                throw $this->invalid();
            }
            $numericTotal += $numeric;
        }
        if ($root && $numericTotal > self::MAX_ROOT_NUMERIC_TOTAL) {
            throw $this->invalid();
        }
        if (
            $property === 'column-count'
            && (!ctype_digit($value) || (int) $value > 24)
        ) {
            throw $this->invalid();
        }
        if ($property === 'content'
            && preg_match('/\A(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|none|normal|open-quote|close-quote)\z/D', $value) !== 1) {
            throw $this->invalid();
        }

        return $property . ':' . $this->collapseWhitespace($value) . ';';
    }

    private function atRule(string $segment): string
    {
        $normalized = $this->collapseWhitespace($segment);
        if (
            preg_match('/\A@media\s+(.+)\z/Di', $normalized, $match) === 1
            && $this->safeConditional($match[1], true)
        ) {
            return '@media ' . strtolower($match[1]);
        }
        if (
            preg_match('/\A@supports\s+(.+)\z/Di', $normalized, $match) === 1
            && $this->safeConditional($match[1], false)
        ) {
            return '@supports ' . strtolower($match[1]);
        }

        throw $this->invalid();
    }

    private function safeConditional(string $condition, bool $media): bool
    {
        if (
            strlen($condition) > 512
            || preg_match('/[{};@\\"\']/', $condition) === 1
            || preg_match('/\b(?:url|var|selector|style|font-tech)\s*\(/i', $condition) === 1
        ) {
            return false;
        }
        $allowed = $media
            ? '/\A[A-Za-z0-9\s().,:\/%_-]+\z/D'
            : '/\A[A-Za-z0-9\s().,:\/%_-]+\z/D';

        return preg_match($allowed, $condition) === 1;
    }

    private function selector(
        string $raw,
        int $selectorDepth,
        ?string $namespace
    ): string {
        if (strlen($raw) > self::MAX_SELECTOR_BYTES) {
            throw $this->invalid();
        }
        $selectors = $this->splitSelectorList($raw);
        $normalized = [];
        foreach ($selectors as $selector) {
            $normalized[] = $this->singleSelector(
                trim($selector),
                $selectorDepth,
                $namespace
            );
        }

        return implode(',', $normalized);
    }

    /** @return list<string> */
    private function splitSelectorList(string $raw): array
    {
        $selectors = [];
        $start = 0;
        $brackets = 0;
        $parentheses = 0;
        $quote = null;
        $length = strlen($raw);
        for ($index = 0; $index < $length; ++$index) {
            $char = $raw[$index];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '[') {
                ++$brackets;
            } elseif ($char === ']') {
                --$brackets;
            } elseif ($char === '(') {
                ++$parentheses;
            } elseif ($char === ')') {
                --$parentheses;
            } elseif ($char === ',' && $brackets === 0 && $parentheses === 0) {
                $selectors[] = substr($raw, $start, $index - $start);
                $start = $index + 1;
            }
            if ($brackets < 0 || $parentheses < 0) {
                throw $this->invalid();
            }
        }
        if ($quote !== null || $brackets !== 0 || $parentheses !== 0) {
            throw $this->invalid();
        }
        $selectors[] = substr($raw, $start);
        if (count($selectors) > 16) {
            throw $this->invalid();
        }

        return $selectors;
    }

    private function singleSelector(
        string $selector,
        int $selectorDepth,
        ?string $namespace
    ): string {
        if ($selector === '' || str_contains($selector, '+') || str_contains($selector, '~')) {
            throw $this->invalid();
        }
        $result = '';
        $length = strlen($selector);
        $index = 0;
        $ampersands = 0;
        $hasDescendant = false;
        while ($index < $length) {
            $char = $selector[$index];
            if (ctype_space($char)) {
                while ($index < $length && ctype_space($selector[$index])) {
                    ++$index;
                }
                if ($result !== '' && !str_ends_with($result, ' ')) {
                    $result .= ' ';
                }
                continue;
            }
            if ($char === '&') {
                if (++$ampersands > 1 || trim($result) !== '') {
                    throw $this->invalid();
                }
                $result .= '&';
                ++$index;
                continue;
            }
            if ($char === '>') {
                $hasDescendant = true;
                $result = rtrim($result) . '>';
                ++$index;
                continue;
            }
            if ($char === '.' || $char === '#') {
                [$token, $index] = $this->readToken($selector, $index + 1);
                if ($char === '.') {
                    $this->assertClassToken($token);
                }
                $result .= $char . ($namespace === null
                    ? $token : $namespace . $token);
                $hasDescendant = $hasDescendant || trim($result) !== '&';
                continue;
            }
            if ($char === '[') {
                $end = strpos($selector, ']', $index + 1);
                if ($end === false) {
                    throw $this->invalid();
                }
                $attribute = substr($selector, $index, $end - $index + 1);
                if (!$this->safeAttributeSelector($attribute)) {
                    throw $this->invalid();
                }
                $result .= $attribute;
                $hasDescendant = true;
                $index = $end + 1;
                continue;
            }
            if ($char === ':') {
                $double = ($selector[$index + 1] ?? '') === ':';
                [$name, $next] = $this->readToken(
                    $selector,
                    $index + ($double ? 2 : 1)
                );
                $lower = strtolower($name);
                $index = $next;
                if ($double) {
                    if (!in_array($lower, self::PSEUDO_ELEMENTS, true)) {
                        throw $this->invalid();
                    }
                    $result .= '::' . $lower;
                    continue;
                }
                if ($lower === 'nth-child' || $lower === 'nth-of-type') {
                    if (($selector[$index] ?? '') !== '(') {
                        throw $this->invalid();
                    }
                    $end = strpos($selector, ')', $index + 1);
                    $argument = $end === false
                        ? '' : trim(substr($selector, $index + 1, $end - $index - 1));
                    if (preg_match('/\A(?:odd|even|[1-9][0-9]{0,3})\z/Di', $argument) !== 1) {
                        throw $this->invalid();
                    }
                    $result .= ':' . $lower . '(' . strtolower($argument) . ')';
                    $index = $end + 1;
                    continue;
                }
                if (!in_array($lower, self::SIMPLE_PSEUDOS, true)) {
                    throw $this->invalid();
                }
                $result .= ':' . $lower;
                continue;
            }
            if ($char === '*') {
                $result .= '*';
                $hasDescendant = true;
                ++$index;
                continue;
            }
            if (ctype_alpha($char)) {
                [$tag, $index] = $this->readToken($selector, $index);
                $tag = strtolower($tag);
                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    throw $this->invalid();
                }
                $result .= $tag;
                $hasDescendant = true;
                continue;
            }

            throw $this->invalid();
        }
        $result = trim($this->collapseWhitespace($result));
        if ($ampersands === 0) {
            $result = str_starts_with($result, '>')
                ? '&' . $result : '& ' . $result;
        } elseif (
            $selectorDepth === 0
            && preg_match('/\A&(?:\s|>)/D', $result) !== 1
        ) {
            // A top-level `&` would target the security wrapper itself.
            throw $this->invalid();
        }
        if ($result === '&' || $result === '') {
            throw $this->invalid();
        }

        return $result;
    }

    /** @return array{string, int} */
    private function readToken(string $source, int $offset): array
    {
        $start = $offset;
        $length = strlen($source);
        while ($offset < $length) {
            $char = $source[$offset];
            if (!ctype_alnum($char) && $char !== '_' && $char !== '-') {
                break;
            }
            ++$offset;
        }
        $token = substr($source, $start, $offset - $start);
        if (preg_match(self::TOKEN, $token) !== 1) {
            throw $this->invalid();
        }

        return [$token, $offset];
    }

    private function assertClassToken(string $token): void
    {
        $lower = strtolower($token);
        foreach (self::RESERVED_CLASS_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                throw $this->invalid();
            }
        }
    }

    private function safeAttributeSelector(string $selector): bool
    {
        return preg_match(
            '/\A\[(?:aria-hidden|dir|lang|data-content-[a-z][a-z0-9_-]{0,47})'
                . '(?:\s*=\s*(?:"[A-Za-z0-9_-]{1,64}"|'
                . '\'[A-Za-z0-9_-]{1,64}\'))?\]\z/D',
            $selector
        ) === 1;
    }

    private function collapseWhitespace(string $value): string
    {
        $result = '';
        $quote = null;
        $pendingSpace = false;
        $length = strlen($value);
        for ($index = 0; $index < $length; ++$index) {
            $char = $value[$index];
            if ($quote !== null) {
                $result .= $char;
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                if ($pendingSpace && $result !== '') {
                    $result .= ' ';
                }
                $pendingSpace = false;
                $quote = $char;
                $result .= $char;
                continue;
            }
            if (ctype_space($char)) {
                $pendingSpace = true;
                continue;
            }
            if ($pendingSpace && $result !== '') {
                $result .= ' ';
            }
            $pendingSpace = false;
            $result .= $char;
        }

        return trim($result);
    }

    private function skipWhitespace(string $source, int &$offset): void
    {
        $length = strlen($source);
        while ($offset < $length && ctype_space($source[$offset])) {
            ++$offset;
        }
    }

    private function invalid(): BlogDocumentException
    {
        return new BlogDocumentException(BlogDocumentException::INVALID_BLOCK);
    }
}
