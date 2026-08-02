<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

use JsonException;

/** Produces the only JSON representation suitable for persistence or hashing. */
final class BlogDocumentCanonicalizer
{
    public function canonicalize(BlogDocument $document): string
    {
        try {
            $json = json_encode(
                $document->toArray(),
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

        return $json;
    }

    public function sha256(BlogDocument $document): string
    {
        return hash('sha256', $this->canonicalize($document));
    }
}
