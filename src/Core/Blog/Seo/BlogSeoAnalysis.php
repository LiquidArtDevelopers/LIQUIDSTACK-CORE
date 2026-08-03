<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

final class BlogSeoAnalysis
{
    /**
     * @param list<BlogSeoCheck> $checks
     * @param list<array<string, string|null>> $competingPages
     */
    public function __construct(
        private readonly array $checks,
        private readonly BlogSeoSerpPreview $preview,
        private readonly array $competingPages = []
    ) {
        if (!array_is_list($checks) || !array_is_list($competingPages)) {
            throw new \InvalidArgumentException('Invalid Blog SEO analysis.');
        }
        $seen = [];
        foreach ($checks as $check) {
            if (!$check instanceof BlogSeoCheck || isset($seen[$check->key()])) {
                throw new \InvalidArgumentException(
                    'Invalid Blog SEO analysis checks.'
                );
            }
            $seen[$check->key()] = true;
        }
        if (count($competingPages) > 5) {
            throw new \InvalidArgumentException(
                'Too many Blog SEO competing pages.'
            );
        }
    }

    /** @return list<BlogSeoCheck> */
    public function checks(): array
    {
        return $this->checks;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $counts = [
            BlogSeoStatus::GOOD => 0,
            BlogSeoStatus::REVIEW => 0,
            BlogSeoStatus::PENDING => 0,
        ];
        foreach ($this->checks as $check) {
            ++$counts[$check->status()];
        }

        return [
            'schema' => 'liquidstack.blog.seo-analysis',
            'version' => 1,
            'advisory' => true,
            'summary' => $counts,
            'checks' => array_map(
                static fn (BlogSeoCheck $check): array => $check->toArray(),
                $this->checks
            ),
            'serp_preview' => $this->preview->toArray(),
            'competing_pages' => $this->competingPages,
        ];
    }
}
