<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

use DomainException;

/** Stable, payload-free failure boundary for structured Blog documents. */
final class BlogDocumentException extends DomainException
{
    public const INVALID_JSON = 'blog.document.invalid_json';
    public const DOCUMENT_TOO_LARGE = 'blog.document.too_large';
    public const INVALID_DOCUMENT = 'blog.document.invalid';
    public const UNSUPPORTED_SCHEMA = 'blog.document.schema_unsupported';
    public const UNSUPPORTED_TEMPLATE = 'blog.document.template_unsupported';
    public const INVALID_TEMPLATE_CONTRACT =
        'blog.document.template_contract_invalid';
    public const INVALID_BLOCK = 'blog.document.block_invalid';
    public const INVALID_INLINE = 'blog.document.inline_invalid';
    public const DUPLICATE_ID = 'blog.document.id_duplicate';
    public const INVALID_HEADING_HIERARCHY =
        'blog.document.heading_hierarchy_invalid';
    public const INVALID_URL = 'blog.document.url_invalid';
    public const PROJECTION_TOO_LARGE = 'blog.document.projection_too_large';

    public function __construct(private readonly string $issueCode)
    {
        parent::__construct('Invalid structured Blog document.');
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
