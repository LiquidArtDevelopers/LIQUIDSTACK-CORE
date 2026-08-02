<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

use JsonException;

/** Bounded JSON boundary for schema-v1 structured Blog documents. */
final class BlogDocumentCodec
{
    private readonly BlogDocumentValidator $validator;
    private readonly BlogDocumentCanonicalizer $canonicalizer;

    public function __construct(
        ?BlogDocumentValidator $validator = null,
        ?BlogDocumentCanonicalizer $canonicalizer = null
    ) {
        $this->validator = $validator ?? new BlogDocumentValidator();
        $this->canonicalizer = $canonicalizer
            ?? new BlogDocumentCanonicalizer();
    }

    public function decode(string $json): BlogDocument
    {
        if ($json === '' || strlen($json) > BlogDocument::MAX_JSON_BYTES) {
            throw new BlogDocumentException(
                $json === ''
                    ? BlogDocumentException::INVALID_JSON
                    : BlogDocumentException::DOCUMENT_TOO_LARGE
            );
        }

        try {
            $decoded = json_decode(
                $json,
                true,
                16,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING
            );
        } catch (JsonException) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_JSON
            );
        }
        if (!is_array($decoded)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_DOCUMENT
            );
        }

        return BlogDocument::fromArray($decoded, $this->validator);
    }

    public function encode(BlogDocument $document): string
    {
        return $this->canonicalizer->canonicalize($document);
    }

    public function canonicalize(string $json): string
    {
        return $this->encode($this->decode($json));
    }

    public function isCanonical(string $json): bool
    {
        try {
            return hash_equals($this->canonicalize($json), $json);
        } catch (BlogDocumentException) {
            return false;
        }
    }
}
