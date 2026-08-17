<?php

declare(strict_types=1);

namespace App\Core\Blog\QaFixtures;

final class BlogQaMatrixFixtureResult
{
    /** @param list<string> $locales */
    public function __construct(
        private readonly bool $applied,
        private readonly string $driver,
        private readonly array $locales,
        private readonly int $aggregateCount,
        private readonly int $requestedVariantCount,
        private readonly int $pendingAggregateCount,
        private readonly int $pendingVariantCount,
        private readonly int $existingVariantCount
    ) {
        if (
            !in_array($driver, ['sqlite', 'mysql'], true)
            || $aggregateCount < 1
            || $requestedVariantCount < 1
            || $pendingAggregateCount < 0
            || $pendingAggregateCount > $aggregateCount
            || $pendingVariantCount < 0
            || $pendingVariantCount > $requestedVariantCount
            || $existingVariantCount < 0
            || $existingVariantCount + $pendingVariantCount
                !== $requestedVariantCount
        ) {
            throw new BlogQaMatrixFixtureException(
                'blog.qa_fixture.result_invalid'
            );
        }
    }

    public function applied(): bool
    {
        return $this->applied;
    }

    public function pendingAggregateCount(): int
    {
        return $this->pendingAggregateCount;
    }

    public function pendingVariantCount(): int
    {
        return $this->pendingVariantCount;
    }

    public function existingVariantCount(): int
    {
        return $this->existingVariantCount;
    }

    /** @return array<string, mixed> */
    public function toSafeArray(): array
    {
        return [
            'mode' => $this->applied ? 'apply' : 'dry-run',
            'driver' => $this->driver,
            'locales' => $this->locales,
            'aggregates_total' => $this->aggregateCount,
            'variants_requested' => $this->requestedVariantCount,
            'aggregates_pending' => $this->pendingAggregateCount,
            'variants_pending' => $this->pendingVariantCount,
            'variants_existing' => $this->existingVariantCount,
            'aggregates_mutated' => $this->applied
                ? $this->pendingAggregateCount : 0,
            'variants_mutated' => $this->applied
                ? $this->pendingVariantCount : 0,
        ];
    }
}
