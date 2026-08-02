<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Editing;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCanonicalizer;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTextProjector;

/**
 * Validated editorial payload joining metadata with one canonical document.
 *
 * body_text is always derived here; callers cannot provide a competing value.
 */
final class BlogStructuredDraft
{
    private readonly BlogDraft $compatibilityDraft;
    private readonly string $canonicalJson;
    private readonly string $documentSha256;
    private readonly string $bodyTextSha256;
    private readonly string $snapshotSha256;

    /** @var list<BlogStructuredMediaReference> */
    private readonly array $mediaReferences;

    public function __construct(
        #[\SensitiveParameter] string $h1,
        private readonly BlogDocument $document,
        #[\SensitiveParameter] ?string $slug = null,
        #[\SensitiveParameter] ?string $seoTitle = null,
        #[\SensitiveParameter] ?string $metaDescription = null,
        #[\SensitiveParameter] ?string $excerpt = null,
        ?BlogDocumentCodec $codec = null,
        ?BlogDocumentTextProjector $projector = null,
        ?BlogStructuredSnapshotHasher $snapshotHasher = null
    ) {
        $codec ??= new BlogDocumentCodec();
        $projector ??= new BlogDocumentTextProjector();
        $snapshotHasher ??= new BlogStructuredSnapshotHasher();

        $this->canonicalJson = $codec->encode($document);
        $bodyText = $projector->project($document);
        $this->compatibilityDraft = new BlogDraft(
            $h1,
            $bodyText,
            $slug,
            $seoTitle,
            $metaDescription,
            $excerpt
        );
        $this->documentSha256 = hash('sha256', $this->canonicalJson);
        $this->bodyTextSha256 = hash('sha256', $bodyText);
        $this->snapshotSha256 = $snapshotHasher->hash(
            $this->compatibilityDraft,
            $this->documentSha256
        );
        $this->mediaReferences = $this->extractMediaReferences($document);
    }

    public function document(): BlogDocument
    {
        return $this->document;
    }

    public function compatibilityDraft(): BlogDraft
    {
        return $this->compatibilityDraft;
    }

    public function canonicalJson(): string
    {
        return $this->canonicalJson;
    }

    public function schemaVersion(): int
    {
        return $this->document->version();
    }

    public function templateKey(): string
    {
        return $this->document->template();
    }

    public function documentBytes(): int
    {
        return strlen($this->canonicalJson);
    }

    public function documentSha256(): string
    {
        return $this->documentSha256;
    }

    public function bodyTextSha256(): string
    {
        return $this->bodyTextSha256;
    }

    public function snapshotSha256(): string
    {
        return $this->snapshotSha256;
    }

    /** @return list<BlogStructuredMediaReference> */
    public function mediaReferences(): array
    {
        return $this->mediaReferences;
    }

    /** @return list<string> */
    public function mediaAssetPublicIds(): array
    {
        $ids = [];
        foreach ($this->mediaReferences as $reference) {
            $ids[$reference->mediaAssetPublicId()] = true;
        }

        return array_keys($ids);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'schema_version' => $this->schemaVersion(),
            'template_key' => $this->templateKey(),
            'document_bytes' => $this->documentBytes(),
            'document_sha256' => $this->documentSha256,
            'body_text_sha256' => $this->bodyTextSha256,
            'snapshot_sha256' => $this->snapshotSha256,
            'media_references' => count($this->mediaReferences),
            'content' => '[redacted]',
        ];
    }

    /** @return list<BlogStructuredMediaReference> */
    private function extractMediaReferences(BlogDocument $document): array
    {
        $references = [];
        foreach ($document->blocks() as $block) {
            if (($block['type'] ?? null) !== 'image') {
                continue;
            }
            $references[] = new BlogStructuredMediaReference(
                (string) $block['id'],
                (string) $block['media_asset_public_id'],
                ($block['display'] ?? null) === 'cover'
                    ? BlogStructuredMediaReference::COVER
                    : BlogStructuredMediaReference::IMAGE
            );
        }

        return $references;
    }
}
