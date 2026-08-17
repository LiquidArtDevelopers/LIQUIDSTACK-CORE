<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

use JsonException;

/** Bounded JSON boundary for supported structured Blog documents. */
final class BlogDocumentCodec
{
    private const MAX_JSON_DEPTH = 32;
    private readonly BlogDocumentValidator $validator;
    private readonly BlogDocumentValidator $draftValidator;
    private readonly BlogDocumentCanonicalizer $canonicalizer;

    public function __construct(
        ?BlogDocumentValidator $validator = null,
        ?BlogDocumentCanonicalizer $canonicalizer = null,
        ?BlogDocumentValidator $draftValidator = null
    ) {
        $this->validator = $validator ?? new BlogDocumentValidator();
        $this->draftValidator = $draftValidator
            ?? $this->validator->forDrafts();
        $this->canonicalizer = $canonicalizer
            ?? new BlogDocumentCanonicalizer();
    }

    public function decode(string $json): BlogDocument
    {
        return $this->decodeWith($json, $this->validator);
    }

    /**
     * Decodes an editorial draft without weakening schema, safety or limits.
     * Only layout-v2 completeness rules are deferred until publication.
     */
    public function decodeDraft(string $json): BlogDocument
    {
        return $this->decodeWith($json, $this->draftValidator);
    }

    private function decodeWith(
        string $json,
        BlogDocumentValidator $validator
    ): BlogDocument
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
                self::MAX_JSON_DEPTH,
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

        return BlogDocument::fromArray($decoded, $validator);
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
