<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Adoption;

use App\Core\Blog\BlogInput;
use App\Core\Blog\BlogPostVariant;
use Throwable;

final class BlogUnifiedTextAdoptionResult
{
    /**
     * @var list<array{
     *   post_public_id: string,
     *   locale: string,
     *   status: string,
     *   lock_version: int,
     *   snapshot_sha256: string
     * }>
     */
    private readonly array $pendingVariants;

    /**
     * @param list<array{
     *   post_public_id: string,
     *   locale: string,
     *   status: string,
     *   lock_version: int,
     *   snapshot_sha256: string
     * }> $pendingVariants
     */
    public function __construct(
        private readonly bool $applied,
        private readonly string $driver,
        private readonly int $candidateCount,
        private readonly int $unstructuredCount,
        private readonly int $ineligibleCount,
        private readonly int $alreadyUnifiedCount,
        private readonly int $draftPendingCount,
        private readonly int $publishedPendingCount,
        array $pendingVariants,
        private readonly int $mutatedCount
    ) {
        $pending = $draftPendingCount + $publishedPendingCount;
        if (
            !in_array($driver, ['sqlite', 'mysql'], true)
            || min(
                $candidateCount,
                $unstructuredCount,
                $ineligibleCount,
                $alreadyUnifiedCount,
                $draftPendingCount,
                $publishedPendingCount,
                $mutatedCount
            ) < 0
            || $candidateCount !== $unstructuredCount + $ineligibleCount
                + $alreadyUnifiedCount + $pending
            || $mutatedCount !== ($applied ? $pending : 0)
            || ($applied && $pending > 1)
            || !array_is_list($pendingVariants)
            || count($pendingVariants) !== $pending
        ) {
            throw $this->failure();
        }
        $this->pendingVariants = $this->validatedPendingVariants(
            $pendingVariants,
            $draftPendingCount,
            $publishedPendingCount
        );
    }

    public function pendingCount(): int
    {
        return $this->draftPendingCount + $this->publishedPendingCount;
    }

    public function mutatedCount(): int
    {
        return $this->mutatedCount;
    }

    /** @return array<string, mixed> */
    public function toSafeArray(): array
    {
        return [
            'mode' => $this->applied ? 'apply' : 'dry-run',
            'driver' => $this->driver,
            'active_non_qa_candidates' => $this->candidateCount,
            'qa_and_trash_excluded' => true,
            'without_structured_document' => $this->unstructuredCount,
            'not_losslessly_convertible' => $this->ineligibleCount,
            'already_unified' => $this->alreadyUnifiedCount,
            'pending_total' => $this->pendingCount(),
            'pending_drafts' => $this->draftPendingCount,
            'pending_published' => $this->publishedPendingCount,
            'pending_variants' => $this->pendingVariants,
            'variants_mutated' => $this->mutatedCount,
            'published_private_workspaces_created_or_advanced' =>
                $this->applied ? $this->publishedPendingCount : 0,
            'published_automatically' => 0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $variants
     * @return list<array{
     *   post_public_id: string,
     *   locale: string,
     *   status: string,
     *   lock_version: int,
     *   snapshot_sha256: string
     * }>
     */
    private function validatedPendingVariants(
        array $variants,
        int $expectedDrafts,
        int $expectedPublished
    ): array {
        $validated = [];
        $previousKey = null;
        $drafts = 0;
        $published = 0;
        foreach ($variants as $variant) {
            if (
                !is_array($variant)
                || array_keys($variant) !== [
                    'post_public_id',
                    'locale',
                    'status',
                    'lock_version',
                    'snapshot_sha256',
                ]
                || !is_string($variant['post_public_id'] ?? null)
                || !is_string($variant['locale'] ?? null)
                || !is_string($variant['status'] ?? null)
                || !is_int($variant['lock_version'] ?? null)
                || !is_string($variant['snapshot_sha256'] ?? null)
                || preg_match(
                    '/\A[0-9a-f]{64}\z/D',
                    $variant['snapshot_sha256'] ?? ''
                ) !== 1
            ) {
                throw $this->failure();
            }
            try {
                $post = BlogInput::publicId($variant['post_public_id']);
                $locale = BlogInput::locale($variant['locale']);
                BlogInput::lockVersion($variant['lock_version']);
            } catch (Throwable) {
                throw $this->failure();
            }
            $status = $variant['status'];
            if ($status === BlogPostVariant::DRAFT) {
                ++$drafts;
            } elseif ($status === BlogPostVariant::PUBLISHED) {
                ++$published;
            } else {
                throw $this->failure();
            }
            $key = $post . "\0" . $locale;
            if ($previousKey !== null && strcmp($previousKey, $key) >= 0) {
                throw $this->failure();
            }
            $previousKey = $key;
            $validated[] = [
                'post_public_id' => $post,
                'locale' => $locale,
                'status' => $status,
                'lock_version' => $variant['lock_version'],
                'snapshot_sha256' => $variant['snapshot_sha256'],
            ];
        }
        if ($drafts !== $expectedDrafts || $published !== $expectedPublished) {
            throw $this->failure();
        }

        return $validated;
    }

    private function failure(): BlogUnifiedTextAdoptionException
    {
        return new BlogUnifiedTextAdoptionException(
            'blog.unified_text_adoption.result_invalid'
        );
    }
}
