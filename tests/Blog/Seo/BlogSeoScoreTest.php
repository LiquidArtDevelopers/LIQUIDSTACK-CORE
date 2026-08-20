<?php

declare(strict_types=1);

namespace Tests\Blog\Seo;

use App\Core\Blog\Seo\BlogSeoAnalysis;
use App\Core\Blog\Seo\BlogSeoCheck;
use App\Core\Blog\Seo\BlogSeoScore;
use App\Core\Blog\Seo\BlogSeoSerpPreview;
use App\Core\Blog\Seo\BlogSeoStatus;
use PHPUnit\Framework\TestCase;

final class BlogSeoScoreTest extends TestCase
{
    /** @dataProvider bandCases */
    public function testBandsUseTheExactRequestedThresholds(
        int $percentage,
        string $band,
        string $label
    ): void {
        $score = BlogSeoScore::fromCounts($percentage, 100);

        self::assertSame($percentage, $score->percentage());
        self::assertSame($band, $score->band());
        self::assertSame($label, $score->label());
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function bandCases(): iterable
    {
        yield 'zero is red' => [0, BlogSeoScore::BAND_RED, 'Bajo'];
        yield '49 is red' => [49, BlogSeoScore::BAND_RED, 'Bajo'];
        yield '50 is orange' => [50, BlogSeoScore::BAND_ORANGE, 'Mejorable'];
        yield '79 is orange' => [79, BlogSeoScore::BAND_ORANGE, 'Mejorable'];
        yield '80 is green' => [80, BlogSeoScore::BAND_GREEN, 'Bien'];
        yield '100 is green' => [100, BlogSeoScore::BAND_GREEN, 'Bien'];
    }

    public function testDefaultCatalogCountsProduceStablePercentages(): void
    {
        $green = BlogSeoScore::fromCounts(9);
        $orange = BlogSeoScore::fromCounts(6);
        $red = BlogSeoScore::fromCounts(5);

        self::assertSame(82, $green->percentage());
        self::assertSame(BlogSeoScore::BAND_GREEN, $green->band());
        self::assertSame(55, $orange->percentage());
        self::assertSame(BlogSeoScore::BAND_ORANGE, $orange->band());
        self::assertSame(45, $red->percentage());
        self::assertSame(BlogSeoScore::BAND_RED, $red->band());
        self::assertSame(11, $green->totalChecks());
    }

    public function testAnalysisUsesOnlyTheFixedElevenOnPageChecks(): void
    {
        $statuses = [];
        foreach ($this->catalogKeys() as $index => $key) {
            $statuses[$key] = $index < 9
                ? BlogSeoStatus::GOOD
                : ($index === 9
                    ? BlogSeoStatus::REVIEW
                    : BlogSeoStatus::PENDING);
        }
        $checks = $this->checks($statuses);
        $checks[] = $this->check(
            'competition.cannibalization',
            BlogSeoStatus::GOOD
        );

        $score = BlogSeoScore::fromAnalysis($this->analysis($checks));

        self::assertSame(9, $score->goodChecks());
        self::assertSame(11, $score->totalChecks());
        self::assertSame(82, $score->percentage());
        self::assertSame(BlogSeoScore::BAND_GREEN, $score->band());
    }

    public function testMissingCatalogCheckFailsClosed(): void
    {
        $keys = $this->catalogKeys();
        array_pop($keys);

        $this->expectException(\InvalidArgumentException::class);
        BlogSeoScore::fromAnalysis($this->analysis($this->checks(
            array_fill_keys($keys, BlogSeoStatus::GOOD)
        )));
    }

    /** @return list<string> */
    private function catalogKeys(): array
    {
        return [
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
    }

    /**
     * @param array<string, string> $statuses
     * @return list<BlogSeoCheck>
     */
    private function checks(array $statuses): array
    {
        $checks = [];
        foreach ($statuses as $key => $status) {
            $checks[] = $this->check($key, $status);
        }

        return $checks;
    }

    private function check(string $key, string $status): BlogSeoCheck
    {
        return new BlogSeoCheck(
            $key,
            explode('.', $key, 2)[0],
            'Comprobación editorial',
            $status,
            'Resultado editorial explicable.'
        );
    }

    /** @param list<BlogSeoCheck> $checks */
    private function analysis(array $checks): BlogSeoAnalysis
    {
        return new BlogSeoAnalysis(
            $checks,
            new BlogSeoSerpPreview(
                'es',
                'Resultado editorial',
                '/noticias/resultado-editorial',
                'Descripción editorial'
            )
        );
    }
}
