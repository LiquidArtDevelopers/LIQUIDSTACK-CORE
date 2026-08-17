<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use DOMDocument;
use DOMElement;
use DOMNode;
use JsonException;

/**
 * Turns already-sanitized provider iframes into network-inert public markup.
 *
 * The canonical document remains unchanged. The public runtime may restore
 * these exact safe attributes only after CookieLad grants social consent.
 */
final class BlogConsentIframeProjector
{
    private const ROOT_ATTRIBUTE = 'data-liquidstack-consent-root';
    private const MAX_CONFIG_BYTES = 8_192;

    public function __construct(
        private readonly ?bool $domExtensionAvailable = null
    ) {
    }

    public function inert(string $html): string
    {
        if ($html === '' || stripos($html, '<iframe') === false) {
            return $html;
        }
        if (!$this->hasDomExtension()) {
            throw $this->invalid();
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><div '
                    . self::ROOT_ATTRIBUTE . '="1">' . $html . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $loaded === true ? $this->findRoot($document) : null;
        if ($root === null) {
            throw $this->invalid();
        }

        $frames = iterator_to_array($root->getElementsByTagName('iframe'));
        foreach ($frames as $frame) {
            if (!$frame instanceof DOMElement) {
                throw $this->invalid();
            }
            $attributes = [];
            foreach (iterator_to_array($frame->attributes) as $attribute) {
                $attributes[strtolower($attribute->name)] = $attribute->value;
            }
            if (!is_string($attributes['src'] ?? null)) {
                throw $this->invalid();
            }
            try {
                $config = json_encode(
                    ['v' => 1, 'attributes' => $attributes],
                    JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_HEX_TAG
                        | JSON_HEX_AMP
                        | JSON_HEX_APOS
                        | JSON_HEX_QUOT
                        | JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                throw $this->invalid();
            }
            if (strlen($config) > self::MAX_CONFIG_BYTES) {
                throw $this->invalid();
            }

            $placeholder = $document->createElement('span');
            $placeholderClass = 'blogDocument__consentFrame';
            if (is_string($attributes['class'] ?? null)) {
                $placeholderClass .= ' ' . $attributes['class'];
            }
            $placeholder->setAttribute('class', $placeholderClass);
            $placeholder->setAttribute('data-blog-consent-iframe', $config);
            foreach ($attributes as $name => $value) {
                if (
                    in_array($name, [
                        'aria-describedby', 'aria-hidden', 'aria-label',
                        'aria-labelledby', 'dir', 'id', 'lang', 'role', 'title',
                    ], true)
                    || str_starts_with($name, 'data-content-')
                ) {
                    $placeholder->setAttribute($name, $value);
                }
            }
            if (!$placeholder->hasAttribute('role')) {
                $placeholder->setAttribute('role', 'group');
            }
            if (
                !$placeholder->hasAttribute('aria-label')
                && !$placeholder->hasAttribute('aria-labelledby')
                && $placeholder->getAttribute('aria-hidden') !== 'true'
            ) {
                $placeholder->setAttribute(
                    'aria-label',
                    is_string($attributes['title'] ?? null)
                        && trim($attributes['title']) !== ''
                            ? $attributes['title']
                            : 'Contenido multimedia incrustado'
                );
            }
            $label = $document->createElement('span');
            $label->setAttribute(
                'class',
                'blogDocument__consentFrameLabel'
            );
            $label->setAttribute('aria-hidden', 'true');
            $label->appendChild($document->createTextNode(
                is_string($attributes['title'] ?? null)
                    && trim($attributes['title']) !== ''
                        ? $attributes['title']
                        : 'Contenido multimedia incrustado'
            ));
            $placeholder->appendChild($label);
            $frame->parentNode?->replaceChild($placeholder, $frame);
        }

        return $this->innerHtml($document, $root);
    }

    private function findRoot(DOMDocument $document): ?DOMElement
    {
        foreach ($document->getElementsByTagName('div') as $element) {
            if ($element->getAttribute(self::ROOT_ATTRIBUTE) !== '1') {
                continue;
            }
            $element->removeAttribute(self::ROOT_ATTRIBUTE);

            return $element;
        }

        return null;
    }

    private function innerHtml(DOMDocument $document, DOMElement $root): string
    {
        $html = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            if (!$child instanceof DOMNode) {
                throw $this->invalid();
            }
            $fragment = $document->saveHTML($child);
            if (!is_string($fragment)) {
                throw $this->invalid();
            }
            $html .= $fragment;
        }

        return trim($html);
    }

    private function hasDomExtension(): bool
    {
        return $this->domExtensionAvailable
            ?? (
                extension_loaded('dom')
                && class_exists(DOMDocument::class, false)
            );
    }

    private function invalid(): BlogRenderingException
    {
        return new BlogRenderingException(
            BlogRenderingException::INVALID_RENDER_STATE
        );
    }
}
