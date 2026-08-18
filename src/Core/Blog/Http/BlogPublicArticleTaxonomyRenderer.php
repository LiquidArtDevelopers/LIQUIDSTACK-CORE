<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogException;
use App\Core\Blog\PublicFeed\BlogPublicCardCategory;
use App\Core\Blog\PublicFeed\BlogPublicCardTag;
use App\Core\Blog\PublicFeed\BlogPublicCardTaxonomyBatch;
use Throwable;

/** Neutral, link-free taxonomy projection shared by public article shells. */
final class BlogPublicArticleTaxonomyRenderer
{
    /** @param array{categories?:string,tags?:string} $labels */
    public function render(
        BlogPublicArticleViewModel $article,
        array $labels = []
    ): string {
        return $this->renderProjection(
            $article->locale(),
            $article->categories(),
            $article->tags(),
            $labels
        );
    }

    /**
     * @param list<array{locale:string,slug:string,name:string}> $categories
     * @param list<array{locale:string,slug:string,name:string}> $tags
     * @param array{categories?:string,tags?:string} $labels
     */
    public function renderProjection(
        string $locale,
        array $categories,
        array $tags,
        array $labels = []
    ): string {
        $categories = $this->normalizeTerms($categories, $locale, false);
        $tags = $this->normalizeTerms($tags, $locale, true);
        if ($categories === [] && $tags === []) {
            return '';
        }

        $fallback = match (strtolower(explode('-', $locale, 2)[0])) {
            'es' => ['categories' => 'Categorías', 'tags' => 'Etiquetas'],
            'eu' => ['categories' => 'Kategoriak', 'tags' => 'Etiketak'],
            default => ['categories' => 'Categories', 'tags' => 'Tags'],
        };
        foreach (['categories', 'tags'] as $kind) {
            $label = $labels[$kind] ?? $fallback[$kind];
            if (!is_string($label) || trim($label) === '') {
                throw new BlogException(BlogException::INVALID_STATE);
            }
            $labels[$kind] = trim($label);
        }

        $html = '<div class="blogPublicArticleTaxonomies">';
        foreach (
            ['categories' => $categories, 'tags' => $tags]
            as $kind => $terms
        ) {
            if ($terms === []) {
                continue;
            }
            $html .= '<div class="blogPublicArticleTaxonomies__group '
                . 'blogPublicArticleTaxonomies__group--' . $kind . '">'
                . '<span class="blogPublicArticleTaxonomies__label">'
                . $this->escape($labels[$kind]) . '</span>'
                . '<ul class="blogPublicArticleTaxonomies__list">';
            foreach ($terms as $term) {
                $html .= '<li class="blogPublicArticleTaxonomies__item">'
                    . '<span dir="auto">' . $this->escape($term['name'])
                    . '</span></li>';
            }
            $html .= '</ul></div>';
        }

        return $html . '</div>';
    }

    /**
     * @param array<mixed> $terms
     * @return list<array{locale:string,slug:string,name:string}>
     */
    private function normalizeTerms(
        array $terms,
        string $locale,
        bool $tags
    ): array {
        $maximum = $tags
            ? BlogPublicCardTaxonomyBatch::MAX_TAGS_PER_CARD
            : BlogPublicCardTaxonomyBatch::MAX_CATEGORIES_PER_CARD;
        if (!array_is_list($terms) || count($terms) > $maximum) {
            throw new BlogException(BlogException::INVALID_STATE);
        }
        $normalized = [];
        $seen = [];
        try {
            foreach ($terms as $term) {
                if (
                    !is_array($term)
                    || !is_string($term['locale'] ?? null)
                    || !is_string($term['slug'] ?? null)
                    || !is_string($term['name'] ?? null)
                ) {
                    throw new BlogException(BlogException::INVALID_STATE);
                }
                $projection = $tags
                    ? new BlogPublicCardTag(
                        $term['locale'],
                        $term['slug'],
                        $term['name']
                    )
                    : new BlogPublicCardCategory(
                        $term['locale'],
                        $term['slug'],
                        $term['name']
                    );
                if (
                    $projection->locale() !== $locale
                    || isset($seen[$projection->slug()])
                ) {
                    throw new BlogException(BlogException::INVALID_STATE);
                }
                $seen[$projection->slug()] = true;
                $normalized[] = $projection->toResourceData();
            }
        } catch (BlogException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogException(BlogException::INVALID_STATE);
        }
        usort(
            $normalized,
            static fn (array $left, array $right): int =>
                strcmp($left['slug'], $right['slug'])
        );

        return $normalized;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
