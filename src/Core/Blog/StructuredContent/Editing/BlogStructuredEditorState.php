<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Editing;

use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\EditorialWorkflow\BlogEditorialWorkspaceState;
use App\Core\Blog\Seo\BlogRobotsPreferences;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredDocumentRecord;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredRevisionRecord;

/** Read-only state needed to open the structured editor. */
final class BlogStructuredEditorState
{
    public function __construct(
        private readonly BlogPostVariant $variant,
        private readonly ?BlogStructuredDocumentRecord $current,
        private readonly ?BlogStructuredRevisionRecord $privateDraft = null,
        private readonly ?BlogEditorialWorkspaceState $workspace = null
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
        if (
            $privateDraft !== null
            && $privateDraft->localizationPublicId()
                !== $variant->localizationPublicId()
        ) {
            throw new \InvalidArgumentException(
                'Private Blog draft localization mismatch.'
            );
        }
        if (
            $workspace !== null
            && $workspace->localizationPublicId()
                !== $variant->localizationPublicId()
        ) {
            throw new \InvalidArgumentException(
                'Blog workspace localization mismatch.'
            );
        }
        if (
            ($privateDraft === null) !== (
                $workspace?->draftRevisionPublicId() === null
            )
            || (
                $privateDraft !== null
                && $privateDraft->revisionPublicId()
                    !== $workspace?->draftRevisionPublicId()
            )
        ) {
            throw new \InvalidArgumentException(
                'Private Blog draft pointer mismatch.'
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
        return $this->workingSnapshot() !== null;
    }

    public function privateDraft(): ?BlogStructuredRevisionRecord
    {
        return $this->privateDraft;
    }

    public function workspace(): ?BlogEditorialWorkspaceState
    {
        return $this->workspace;
    }

    public function hasPrivateDraft(): bool
    {
        return $this->privateDraft !== null;
    }

    public function workingSnapshot(): ?BlogStructuredDraft
    {
        return $this->privateDraft?->snapshot()
            ?? $this->current?->snapshot();
    }

    /** Preferences shown by the editor, including a private workspace. */
    public function robotsPreferences(): BlogRobotsPreferences
    {
        return $this->workingSnapshot()?->robotsPreferences()
            ?? $this->variant->draft()->robotsPreferences();
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
            'has_private_draft' => $this->hasPrivateDraft(),
            'content' => '[redacted]',
        ];
    }
}
