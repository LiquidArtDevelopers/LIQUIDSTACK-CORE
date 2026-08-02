<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Editing;

use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredDocumentRecord;

/** Read-only state needed to open the structured editor. */
final class BlogStructuredEditorState
{
    public function __construct(
        private readonly BlogPostVariant $variant,
        private readonly ?BlogStructuredDocumentRecord $current
    ) {
        if (
            $current !== null
            && $current->localizationPublicId()
                !== $variant->localizationPublicId()
        ) {
            throw new \InvalidArgumentException(
                'Structured Blog state localization mismatch.'
            );
        }
    }

    public function variant(): BlogPostVariant
    {
        return $this->variant;
    }

    public function current(): ?BlogStructuredDocumentRecord
    {
        return $this->current;
    }

    public function hasStructuredContent(): bool
    {
        return $this->current !== null;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'post_public_id' => $this->variant->postPublicId(),
            'localization_public_id' =>
                $this->variant->localizationPublicId(),
            'locale' => $this->variant->locale(),
            'has_structured_content' => $this->hasStructuredContent(),
            'content' => '[redacted]',
        ];
    }
}
