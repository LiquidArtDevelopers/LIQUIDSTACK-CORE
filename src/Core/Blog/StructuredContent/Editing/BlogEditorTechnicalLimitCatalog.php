<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Editing;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentValidator;
use App\Core\Blog\StructuredContent\Document\BlogEmbedHtmlSanitizer;
use App\Core\Blog\StructuredContent\Document\BlogCustomTextCssSanitizer;
use App\Core\Blog\StructuredContent\Document\BlogCustomTextHtmlSanitizer;
use App\Core\Blog\StructuredContent\Presentation\BlogImagePresentationPolicy;

/** Canonical, public-safe limits shared by the Blog editor and HTTP boundary. */
final class BlogEditorTechnicalLimitCatalog
{
    /** @return array<string, mixed> */
    public function toSafeArray(): array
    {
        return [
            'entry' => [
                'h1' => $this->entry(BlogDraft::MAX_H1_BYTES, true, 20, 80),
                'slug' => $this->entry(BlogDraft::MAX_SLUG_BYTES, false, null, 75),
                'seo_title' => $this->entry(
                    BlogDraft::MAX_SEO_TITLE_BYTES,
                    false,
                    30,
                    65
                ),
                'meta_description' => $this->entry(
                    BlogDraft::MAX_META_DESCRIPTION_BYTES,
                    false,
                    120,
                    160
                ),
                'excerpt' => $this->entry(BlogDraft::MAX_EXCERPT_BYTES, false),
            ],
            'document' => [
                'document_json' => ['bytes' => BlogDocument::MAX_JSON_BYTES],
            ],
            'block' => [
                'content' => ['bytes' => BlogDocumentValidator::MAX_INLINE_TEXT_BYTES],
                'text_content' => [
                    'bytes' => BlogDocumentValidator::MAX_TEXT_MODULE_BYTES,
                ],
                'label' => ['bytes' => BlogDocumentValidator::MAX_LABEL_BYTES],
                'alt' => ['bytes' => BlogDocumentValidator::MAX_ALT_BYTES],
                'title' => ['bytes' => BlogDocumentValidator::MAX_TITLE_BYTES],
                'caption' => ['bytes' => BlogDocumentValidator::MAX_CAPTION_BYTES],
                'href' => ['bytes' => BlogDocumentValidator::MAX_URL_BYTES],
                'html' => ['bytes' => BlogCustomTextHtmlSanitizer::MAX_HTML_BYTES],
                'embed_html' => ['bytes' => BlogEmbedHtmlSanitizer::MAX_HTML_BYTES],
                'css' => ['bytes' => BlogCustomTextCssSanitizer::MAX_CSS_BYTES],
            ],
            'custom_text_policy' => [
                'html' => (new BlogCustomTextHtmlSanitizer())->policy(),
                'css' => (new BlogCustomTextCssSanitizer())->policy(),
            ],
            'embed_policy' => [
                'html' => (new BlogEmbedHtmlSanitizer())->policy(),
                'css' => (new BlogCustomTextCssSanitizer())->policy(),
            ],
            'image_presentation_policy' =>
                BlogImagePresentationPolicy::toSafeArray(),
        ];
    }

    public function entryBytes(string $field): ?int
    {
        $value = $this->toSafeArray()['entry'][$field]['bytes'] ?? null;

        return is_int($value) ? $value : null;
    }

    public function documentBytes(string $field): ?int
    {
        $value = $this->toSafeArray()['document'][$field]['bytes'] ?? null;

        return is_int($value) ? $value : null;
    }

    public function blockBytes(string $field): ?int
    {
        $value = $this->toSafeArray()['block'][$field]['bytes'] ?? null;

        return is_int($value) ? $value : null;
    }

    /** @return array<string, int|bool> */
    private function entry(
        int $bytes,
        bool $required,
        ?int $editorialMin = null,
        ?int $editorialMax = null
    ): array {
        $value = ['bytes' => $bytes, 'required' => $required];
        if ($editorialMin !== null) {
            $value['editorial_min_characters'] = $editorialMin;
        }
        if ($editorialMax !== null) {
            $value['editorial_max_characters'] = $editorialMax;
        }

        return $value;
    }
}
