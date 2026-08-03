<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

/** Bounded repository result whose completeness is explicit. */
final class BlogSeoCandidateScan
{
    /** @param list<BlogSeoCompetingPage> $candidates */
    public function __construct(
        private readonly array $candidates,
        private readonly bool $complete
    ) {
        if (!array_is_list($candidates)) {
            throw new \InvalidArgumentException(
                'Invalid Blog SEO candidate scan.'
            );
        }
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof BlogSeoCompetingPage) {
                throw new \InvalidArgumentException(
                    'Invalid Blog SEO candidate scan.'
                );
            }
        }
    }

    /** @return list<BlogSeoCompetingPage> */
    public function candidates(): array
    {
        return $this->candidates;
    }

    public function complete(): bool
    {
        return $this->complete;
    }
}
