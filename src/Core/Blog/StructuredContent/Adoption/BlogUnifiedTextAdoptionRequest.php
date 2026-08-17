<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Adoption;

use App\Core\Blog\BlogInput;
use App\Core\Blog\BlogPostVariant;
use Throwable;

final class BlogUnifiedTextAdoptionRequest
{
    public const DEFAULT_MAX_CANDIDATES = 250;
    public const MAX_CANDIDATES = 1_000;

    /** @var list<string> */
    private readonly array $postPublicIds;

    /** @var list<string> */
    private readonly array $locales;

    /** @var list<string> */
    private readonly array $statuses;

    /**
     * @param list<string> $postPublicIds
     * @param list<string> $locales
     * @param list<string> $statuses
     */
    public function __construct(
        array $postPublicIds,
        array $locales,
        array $statuses,
        private readonly int $maximumCandidates,
        private readonly bool $apply,
        private readonly ?string $actorPublicId
    ) {
        if (
            $maximumCandidates < 1
            || $maximumCandidates > self::MAX_CANDIDATES
            || ($apply && $actorPublicId === null)
            || ($apply && (
                count($postPublicIds) !== 1
                || count($locales) !== 1
            ))
        ) {
            throw $this->failure(
                $apply && (
                    count($postPublicIds) !== 1
                    || count($locales) !== 1
                ) ? 'single_variant_required' : 'request_invalid'
            );
        }
        $this->postPublicIds = $this->uniqueValidated(
            $postPublicIds,
            static fn (string $value): string => BlogInput::publicId($value),
            100
        );
        $this->locales = $this->uniqueValidated(
            $locales,
            static fn (string $value): string => BlogInput::locale($value),
            100
        );
        $this->statuses = $this->uniqueValidated(
            $statuses,
            static function (string $value): string {
                if (!in_array(
                    $value,
                    [BlogPostVariant::DRAFT, BlogPostVariant::PUBLISHED],
                    true
                )) {
                    throw new \InvalidArgumentException();
                }

                return $value;
            },
            2
        );
        if ($actorPublicId !== null) {
            try {
                BlogInput::publicId($actorPublicId);
            } catch (Throwable) {
                throw $this->failure('actor_invalid');
            }
        }
    }

    /** @return list<string> */
    public function postPublicIds(): array
    {
        return $this->postPublicIds;
    }

    /** @return list<string> */
    public function locales(): array
    {
        return $this->locales;
    }

    /** @return list<string> */
    public function statuses(): array
    {
        return $this->statuses;
    }

    public function maximumCandidates(): int
    {
        return $this->maximumCandidates;
    }

    public function apply(): bool
    {
        return $this->apply;
    }

    public function actorPublicId(): ?string
    {
        return $this->actorPublicId;
    }

    /**
     * @param list<string> $values
     * @param callable(string): string $validator
     * @return list<string>
     */
    private function uniqueValidated(
        array $values,
        callable $validator,
        int $maximum
    ): array {
        if (!array_is_list($values) || count($values) > $maximum) {
            throw $this->failure('filters_invalid');
        }
        $validated = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw $this->failure('filters_invalid');
            }
            try {
                $value = $validator($value);
            } catch (Throwable) {
                throw $this->failure('filters_invalid');
            }
            $validated[$value] = true;
        }
        $result = array_keys($validated);
        sort($result, SORT_STRING);

        return $result;
    }

    private function failure(string $suffix): BlogUnifiedTextAdoptionException
    {
        return new BlogUnifiedTextAdoptionException(
            'blog.unified_text_adoption.' . $suffix
        );
    }
}
