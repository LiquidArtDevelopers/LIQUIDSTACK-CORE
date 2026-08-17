<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

use JsonException;

/** Reconstructs compatible canonical forms used by earlier V2 revisions. */
final class BlogDocumentV2CompatibilityCanonicalizer
{
    /** @return list<string> */
    public function candidates(BlogDocument $document): array
    {
        if ($document->version() !== BlogDocument::LAYOUT_VERSION) {
            return [];
        }
        $forms = [];
        foreach ([
            [true, false],
            [false, true],
            [true, true],
        ] as [$removeTextAlign, $removeEmbedCss]) {
            $data = $document->toArray();
            $changes = 0;
            if ($removeTextAlign && !$this->removeDefaultTextAlign(
                $data,
                $changes
            )) {
                continue;
            }
            if ($removeEmbedCss) {
                $this->removeDefaultEmbedCss($data, $changes);
            }
            if ($changes === 0) {
                continue;
            }
            try {
                $json = json_encode(
                    $data,
                    JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_DOCUMENT
                );
            }
            $forms[$json] = true;
        }

        return array_keys($forms);
    }

    public function canonicalize(BlogDocument $document): ?string
    {
        return $this->candidates($document)[0] ?? null;
    }

    private function removeDefaultTextAlign(
        mixed &$value,
        int &$presentations
    ): bool {
        if (!is_array($value)) {
            return true;
        }
        if (
            !array_is_list($value)
            && array_key_exists('presentation', $value)
        ) {
            $presentation = &$value['presentation'];
            if (!is_array($presentation)) {
                return false;
            }
            if (array_key_exists('text_align', $presentation)) {
                if ($presentation['text_align'] !== 'start') {
                    return false;
                }
                unset($presentation['text_align']);
                ++$presentations;
            }
            unset($presentation);
        }
        foreach ($value as &$child) {
            if (!$this->removeDefaultTextAlign($child, $presentations)) {
                return false;
            }
        }
        unset($child);

        return true;
    }

    private function removeDefaultEmbedCss(mixed &$value, int &$changes): void
    {
        if (!is_array($value)) {
            return;
        }
        if (
            !array_is_list($value)
            && ($value['type'] ?? null) === 'embed'
            && ($value['css'] ?? null) === ''
        ) {
            unset($value['css']);
            ++$changes;
        }
        foreach ($value as &$child) {
            $this->removeDefaultEmbedCss($child, $changes);
        }
        unset($child);
    }
}
