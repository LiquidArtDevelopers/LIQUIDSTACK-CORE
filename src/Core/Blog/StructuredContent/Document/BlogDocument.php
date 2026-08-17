<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

/** Immutable, validated representation of a structured Blog document. */
final class BlogDocument
{
    public const SCHEMA = 'liquidstack.blog.document';
    /** Historical flat document contract. */
    public const VERSION = 1;
    public const LAYOUT_VERSION = 2;
    public const MAX_JSON_BYTES = 300_000;
    public const MAX_BODY_TEXT_BYTES = 300_000;
    public const MAX_BLOCKS = 200;
    public const MAX_LIST_ITEMS = 100;

    /**
     * @param array{
     *   schema: string,
     *   version: int,
     *   template: string,
     *   header?: array{hero: ?string, h1_module: string},
     *   blocks: list<array<string, mixed>>
     * } $data
     */
    private function __construct(private readonly array $data)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(
        array $data,
        ?BlogDocumentValidator $validator = null
    ): self {
        return new self(
            ($validator ?? new BlogDocumentValidator())->validate($data)
        );
    }

    public function schema(): string
    {
        return $this->data['schema'];
    }

    public function version(): int
    {
        return $this->data['version'];
    }

    public function template(): string
    {
        return $this->data['template'];
    }

    /** @return array{hero: ?string, h1_module: string}|null */
    public function headerSelectionData(): ?array
    {
        return $this->data['header'] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function blocks(): array
    {
        return $this->data['blocks'];
    }

    public function blockCount(): int
    {
        if ($this->version() === self::VERSION) {
            return count($this->data['blocks']);
        }

        return (new BlogDocumentWalker())->nodeCount($this);
    }

    /**
     * @return array{
     *   schema: string,
     *   version: int,
     *   template: string,
     *   header?: array{hero: ?string, h1_module: string},
     *   blocks: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
