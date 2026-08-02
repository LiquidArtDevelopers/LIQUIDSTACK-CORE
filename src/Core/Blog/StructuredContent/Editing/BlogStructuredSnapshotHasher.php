<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Editing;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\StructuredContent\Document\BlogDocumentException;
use JsonException;

/** Stable, content-only hash used for no-op detection and revision integrity. */
final class BlogStructuredSnapshotHasher
{
    public function hash(BlogDraft $draft, string $documentSha256): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $documentSha256) !== 1) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_DOCUMENT
            );
        }

        try {
            $json = json_encode([
                'schema' => 'liquidstack.blog.snapshot',
                'version' => 1,
                'document_sha256' => $documentSha256,
                'h1' => $draft->h1(),
                'slug' => $draft->slug(),
                'seo_title' => $draft->seoTitle(),
                'meta_description' => $draft->metaDescription(),
                'excerpt' => $draft->excerpt(),
                'body_text' => $draft->bodyText(),
            ], JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_DOCUMENT
            );
        }

        return hash('sha256', $json);
    }
}
