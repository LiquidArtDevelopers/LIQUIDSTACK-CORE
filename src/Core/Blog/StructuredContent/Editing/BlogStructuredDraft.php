<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Editing;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\Seo\BlogRobotsPreferences;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTextProjector;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV1CompatibilityProjector;
use App\Core\Blog\StructuredContent\Document\BlogDocumentWalker;

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
    private readonly BlogDocument $compatibilityDocument;
    private readonly string $compatibilityCanonicalJson;
    private readonly string $compatibilityDocumentSha256;
    private readonly string $compatibilitySnapshotSha256;
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
        ?BlogStructuredSnapshotHasher $snapshotHasher = null,
        ?BlogDocumentV1CompatibilityProjector $compatibilityProjector = null,
        ?BlogRobotsPreferences $robotsPreferences = null
    ) {
        $codec ??= new BlogDocumentCodec();
        $projector ??= new BlogDocumentTextProjector();
        $snapshotHasher ??= new BlogStructuredSnapshotHasher();
        $compatibilityProjector ??= new BlogDocumentV1CompatibilityProjector();

        $this->canonicalJson = $codec->encode($document);
        $this->compatibilityDocument = $document->version()
            === BlogDocument::LAYOUT_VERSION
                ? $compatibilityProjector->project($document)
                : $document;
        $this->compatibilityCanonicalJson = $codec->encode(
            $this->compatibilityDocument
        );
        $bodyText = $projector->project($document);
        if (!hash_equals(
            $bodyText,
            $projector->project($this->compatibilityDocument)
        )) {
            throw new \LogicException(
                'The compatibility projection changed the visible text.'
            );
        }
        $this->compatibilityDraft = new BlogDraft(
            $h1,
            $bodyText,
            $slug,
            $seoTitle,
            $metaDescription,
            $excerpt,
            $robotsPreferences
        );
        $this->documentSha256 = hash('sha256', $this->canonicalJson);
        $this->compatibilityDocumentSha256 = hash(
            'sha256',
            $this->compatibilityCanonicalJson
        );
        $this->bodyTextSha256 = hash('sha256', $bodyText);
        $this->snapshotSha256 = $snapshotHasher->hash(
            $this->compatibilityDraft,
            $this->documentSha256
        );
        $this->compatibilitySnapshotSha256 = $snapshotHasher->hash(
            $this->compatibilityDraft,
            $this->compatibilityDocumentSha256
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

    public function robotsPreferences(): BlogRobotsPreferences
    {
        return $this->compatibilityDraft->robotsPreferences();
    }

    public function compatibilityDocument(): BlogDocument
    {
        return $this->compatibilityDocument;
    }

    public function compatibilityCanonicalJson(): string
    {
        return $this->compatibilityCanonicalJson;
    }

    public function compatibilitySchemaVersion(): int
    {
        return $this->compatibilityDocument->version();
    }

    public function compatibilityTemplateKey(): string
    {
        return $this->compatibilityDocument->template();
    }

    public function compatibilityDocumentBytes(): int
    {
        return strlen($this->compatibilityCanonicalJson);
    }

    public function compatibilityDocumentSha256(): string
    {
        return $this->compatibilityDocumentSha256;
    }

    public function compatibilitySnapshotSha256(): string
    {
        return $this->compatibilitySnapshotSha256;
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
        foreach ((new BlogDocumentWalker())->modules($document) as $block) {
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
