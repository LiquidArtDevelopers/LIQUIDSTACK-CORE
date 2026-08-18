<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicIndex;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;

/** Typed presentation copy supplied by the project language catalog. */
final class BlogPublicIndexTextCatalog
{
    /** @param array<string, string> $values */
    public function __construct(private readonly array $values)
    {
    }

    /**
     * Adapts LiquidStack's extracted catalog without reading $GLOBALS itself.
     *
     * @param array<string, mixed> $catalog
     */
    public static function fromGlobals(array $catalog): self
    {
        $defaults = self::defaults();
        $values = [];
        foreach ($defaults as $key => $fallback) {
            $field = $key === 'description' ? 'content' : 'text';
            $value = self::readField($catalog[$key] ?? null, $field);
            $values[$key] = $value === '' ? $fallback : $value;
        }

        return new self($values);
    }

    public function title(): string
    {
        return $this->value('title');
    }

    public function description(): string
    {
        return $this->value('description');
    }

    public function pageLabel(): string
    {
        return $this->value('blog_index_page');
    }

    public function resultsHeading(): string
    {
        return $this->value('blog_index_results_heading');
    }

    public function cardCtaLabel(): string
    {
        return $this->value('blog_index_card_cta');
    }

    public function emptyMessage(string $state): string
    {
        return match ($state) {
            'unavailable' => $this->value('blog_index_unavailable'),
            'no_results', 'not_found' =>
                $this->value('blog_index_no_results'),
            default => $this->value('blog_index_empty'),
        };
    }

    /** @return array<string, string> */
    public function searchLabels(): array
    {
        return [
            'search' => $this->value('blog_index_search_label'),
            'placeholder' => $this->value(
                'blog_index_search_placeholder'
            ),
            'minimum' => $this->value('blog_index_search_minimum'),
            'order' => $this->value('blog_index_order_label'),
            'newest' => $this->value('blog_index_order_newest'),
            'oldest' => $this->value('blog_index_order_oldest'),
            'updated' => $this->value('blog_index_order_updated'),
            'submit' => $this->value('blog_index_search_submit'),
            'clear' => $this->value('blog_index_clear_search'),
            'status' => $this->value('blog_index_results_updated'),
            'error' => $this->value('blog_index_unavailable'),
        ];
    }

    /** @return array<string, string> */
    public function categoryLabels(): array
    {
        return [
            'categories' => $this->value('blog_index_categories_label'),
            'mode' => $this->value('blog_index_category_mode_label'),
            'any' => $this->value('blog_index_category_mode_any'),
            'all' => $this->value('blog_index_category_mode_all'),
            'submit' => $this->value('blog_index_apply_categories'),
            'reset' => $this->value('blog_index_clear_categories'),
            'empty' => $this->value('blog_index_categories_empty'),
            'status' => $this->value('blog_index_results_updated'),
            'error' => $this->value('blog_index_unavailable'),
        ];
    }

    /** @return array<string, string> */
    public function paginationLabels(): array
    {
        return [
            'previous' => $this->value('blog_index_previous_page'),
            'next' => $this->value('blog_index_next_page'),
            'page' => $this->pageLabel(),
        ];
    }

    public function archiveHeading(): string
    {
        return $this->value('blog_index_archive_heading');
    }

    public function archiveSingularLabel(): string
    {
        return $this->value('blog_index_archive_entry_singular');
    }

    public function archivePluralLabel(): string
    {
        return $this->value('blog_index_archive_entry_plural');
    }

    public function archivePeriodLabel(
        string $locale,
        int $year,
        int $month
    ): string {
        $monthText = $this->value(sprintf('blog_index_month_%02d', $month));
        if ($monthText === '') {
            $monthText = $this->formatMonth($locale, $year, $month);
        }
        $format = $this->value('blog_index_archive_period_format');
        if ($format === '') {
            $format = '{month} {year}';
        }

        return strtr($format, [
            '{month}' => $monthText,
            '{year}' => (string) $year,
        ]);
    }

    private function value(string $key): string
    {
        return trim((string) ($this->values[$key] ?? ''));
    }

    private function formatMonth(
        string $locale,
        int $year,
        int $month
    ): string {
        $date = new DateTimeImmutable(
            sprintf('%04d-%02d-01 00:00:00', $year, $month),
            new DateTimeZone('UTC')
        );
        if (class_exists(IntlDateFormatter::class)) {
            $formatter = new IntlDateFormatter(
                $locale,
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                'UTC',
                IntlDateFormatter::GREGORIAN,
                'LLLL'
            );
            $formatted = $formatter->format($date);
            if (is_string($formatted) && trim($formatted) !== '') {
                return $formatted;
            }
        }

        return sprintf('%04d-%02d', $year, $month);
    }

    private static function readField(mixed $entry, string $field): string
    {
        if (is_object($entry) && isset($entry->{$field})) {
            return trim((string) $entry->{$field});
        }
        if (is_array($entry) && isset($entry[$field])) {
            return trim((string) $entry[$field]);
        }
        if ($field === 'text' && is_scalar($entry)) {
            return trim((string) $entry);
        }

        return '';
    }

    /** @return array<string, string> */
    private static function defaults(): array
    {
        $defaults = [
            'title' => 'Blog',
            'description' => 'Published articles.',
            'blog_index_page' => 'Page',
            'blog_index_results_heading' => 'Articles',
            'blog_index_card_cta' => 'Read more',
            'blog_index_search_label' => 'Search articles',
            'blog_index_search_placeholder' => 'Title, content or tag',
            'blog_index_search_minimum' =>
                'Enter at least 2 characters.',
            'blog_index_order_label' => 'Sort articles',
            'blog_index_order_newest' => 'Newest',
            'blog_index_order_oldest' => 'Oldest',
            'blog_index_order_updated' => 'Recently updated',
            'blog_index_search_submit' => 'Search',
            'blog_index_clear_search' => 'Clear search',
            'blog_index_categories_label' => 'Filter by category',
            'blog_index_category_mode_label' => 'Category matching',
            'blog_index_category_mode_any' => 'Any',
            'blog_index_category_mode_all' => 'All',
            'blog_index_apply_categories' => 'Apply categories',
            'blog_index_clear_categories' => 'Clear categories',
            'blog_index_categories_empty' =>
                'There are no categories available.',
            'blog_index_results_updated' => 'Results updated',
            'blog_index_empty' => 'There are no articles yet.',
            'blog_index_no_results' => 'No matching articles were found.',
            'blog_index_unavailable' =>
                'The articles are temporarily unavailable.',
            'blog_index_previous_page' => 'Previous page',
            'blog_index_next_page' => 'Next page',
            'blog_index_archive_heading' => 'Article archive',
            'blog_index_archive_entry_singular' => 'article',
            'blog_index_archive_entry_plural' => 'articles',
            'blog_index_archive_period_format' => '',
        ];
        for ($month = 1; $month <= 12; ++$month) {
            $defaults[sprintf('blog_index_month_%02d', $month)] = '';
        }

        return $defaults;
    }
}
