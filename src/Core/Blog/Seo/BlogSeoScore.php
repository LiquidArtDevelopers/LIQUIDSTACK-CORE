<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

/**
 * Explainable catalog projection of the existing on-page editorial checks.
 *
 * The percentage is the share of the fixed checks that are in `good` state;
 * it remains advisory and is not a prediction of search-engine performance.
 */
final class BlogSeoScore
{
    public const BAND_RED = 'red';
    public const BAND_ORANGE = 'orange';
    public const BAND_GREEN = 'green';

    /** @var list<string> */
    private const CATALOG_CHECK_KEYS = [
        'metadata.title_length',
        'metadata.description_length',
        'metadata.h1_length',
        'metadata.slug',
        'metadata.title_h1_overlap',
        'content.word_count',
        'content.first_100_words',
        'content.heading_structure',
        'media.image_alternatives',
        'content.mechanical_repetition',
        'content.term_concentration',
    ];

    private readonly int $percentage;

    private function __construct(
        private readonly int $goodChecks,
        private readonly int $totalChecks
    ) {
        if (
            $totalChecks < 1
            || $totalChecks > 100
            || $goodChecks < 0
            || $goodChecks > $totalChecks
        ) {
            throw new \InvalidArgumentException('Invalid Blog SEO score.');
        }

        $this->percentage = (int) round(
            ($goodChecks * 100) / $totalChecks
        );
    }

    public static function fromCounts(
        int $goodChecks,
        int $totalChecks = 11
    ): self {
        return new self($goodChecks, $totalChecks);
    }

    public static function fromAnalysis(BlogSeoAnalysis $analysis): self
    {
        $expected = array_fill_keys(self::CATALOG_CHECK_KEYS, true);
        $found = [];
        $good = 0;

        foreach ($analysis->checks() as $check) {
            $key = $check->key();
            if (!isset($expected[$key])) {
                continue;
            }
            if (isset($found[$key])) {
                throw new \InvalidArgumentException(
                    'Duplicate Blog SEO catalog check.'
                );
            }
            $found[$key] = true;
            if ($check->status() === BlogSeoStatus::GOOD) {
                ++$good;
            }
        }

        if (count($found) !== count(self::CATALOG_CHECK_KEYS)) {
            throw new \InvalidArgumentException(
                'Incomplete Blog SEO catalog analysis.'
            );
        }

        return self::fromCounts($good, count(self::CATALOG_CHECK_KEYS));
    }

    public function percentage(): int
    {
        return $this->percentage;
    }

    public function band(): string
    {
        return match (true) {
            $this->percentage < 50 => self::BAND_RED,
            $this->percentage < 80 => self::BAND_ORANGE,
            default => self::BAND_GREEN,
        };
    }

    public function label(): string
    {
        return match ($this->band()) {
            self::BAND_RED => 'Bajo',
            self::BAND_ORANGE => 'Mejorable',
            self::BAND_GREEN => 'Bien',
        };
    }

    public function goodChecks(): int
    {
        return $this->goodChecks;
    }

    public function totalChecks(): int
    {
        return $this->totalChecks;
    }

    /** @return array<string, int|string> */
    public function __debugInfo(): array
    {
        return [
            'percentage' => $this->percentage,
            'band' => $this->band(),
            'label' => $this->label(),
            'good_checks' => $this->goodChecks,
            'total_checks' => $this->totalChecks,
        ];
    }
}
