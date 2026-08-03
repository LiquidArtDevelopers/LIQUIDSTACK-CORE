<?php

declare(strict_types=1);

namespace Tests\Blog\Seo;

use App\Core\Blog\Seo\BlogSeoAnalyzer;
use App\Core\Blog\Seo\BlogSeoCompetingPage;
use App\Core\Blog\Seo\BlogSeoStatus;
use App\Core\Blog\Seo\BlogSeoTextNormalizer;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use PHPUnit\Framework\TestCase;

final class BlogSeoAnalyzerTest extends TestCase
{
    public function testAnalysisIsDeterministicAdvisoryAndHasNoScore(): void
    {
        $analyzer = new BlogSeoAnalyzer();
        $draft = $this->draft($this->naturalBody(330));

        $first = $analyzer->analyze($draft, 'es', '/noticias')->toArray();
        $second = $analyzer->analyze($draft, 'es', '/noticias')->toArray();

        self::assertSame($first, $second);
        self::assertTrue($first['advisory']);
        self::assertArrayNotHasKey('score', $first);
        self::assertSame('es', $first['serp_preview']['locale']);
        self::assertSame(
            '/noticias/guia-segura-de-la-matrix',
            $first['serp_preview']['url']
        );
        self::assertCount(12, $first['checks']);
        self::assertSame(
            count($first['checks']),
            array_sum($first['summary'])
        );
    }

    public function testUnicodeLengthsAndLocaleTokenizationAreStable(): void
    {
        $normalizer = new BlogSeoTextNormalizer();

        self::assertSame(6, $normalizer->length('áéíóúñ'));
        self::assertSame(
            ['asesoría', 'fiscal', 'bilbao'],
            $normalizer->meaningfulTokens(
                'La ASESORÍA fiscal en Bilbao',
                'es'
            )
        );
        self::assertSame(
            ['aholkularitza', 'bilbon'],
            $normalizer->meaningfulTokens(
                'Aholkularitza eta Bilbon',
                'eu'
            )
        );
        self::assertSame(
            ['asesoría', 'fiscal', 'bilbao'],
            (new BlogSeoTextNormalizer(false))->meaningfulTokens(
                'LA ASESORÍA FISCAL EN BILBAO',
                'es'
            )
        );
    }

    public function testMetadataChecksUseGoodReviewAndPendingWithoutEquality(): void
    {
        $analysis = (new BlogSeoAnalyzer())->analyze(
            $this->draft(
                $this->naturalBody(320),
                title: 'Guía práctica para entender Matrix y elegir con criterio',
                description: null,
                h1: 'Cómo entender Matrix y elegir con criterio sin perder contexto',
                slug: null
            ),
            'es',
            '/noticias'
        )->toArray();
        $checks = $this->checks($analysis);

        self::assertSame(
            BlogSeoStatus::GOOD,
            $checks['metadata.title_h1_overlap']['status']
        );
        self::assertSame(
            BlogSeoStatus::PENDING,
            $checks['metadata.description_length']['status']
        );
        self::assertSame(
            BlogSeoStatus::PENDING,
            $checks['metadata.slug']['status']
        );
        self::assertGreaterThanOrEqual(
            45,
            $checks['metadata.title_h1_overlap']['metrics']['overlap_percent']
        );
    }

    public function testContentChecksExposeWordsHeadingsImagesAndRepetition(): void
    {
        $blocks = [
            $this->paragraph(1, 'matrix matrix matrix ' . $this->naturalBody(110)),
            $this->heading(2, 2, 'Elegir dentro de Matrix'),
            $this->heading(3, 3, 'El contexto de la elección'),
            $this->heading(4, 2, 'Elegir dentro de Matrix'),
            $this->heading(5, 3, 'Una nueva lectura'),
            $this->heading(6, 4, 'Señales de la operadora'),
            $this->heading(7, 5, 'Detalles del código'),
            $this->heading(8, 6, 'La última capa'),
            $this->image(9, false, 'Neo observa el código de Matrix'),
            $this->image(10, true, ''),
        ];
        $analysis = (new BlogSeoAnalyzer())->analyze(
            $this->draftFromBlocks($blocks),
            'es',
            '/noticias'
        )->toArray();
        $checks = $this->checks($analysis);

        self::assertSame(
            BlogSeoStatus::REVIEW,
            $checks['content.heading_structure']['status']
        );
        self::assertSame(
            1,
            $checks['content.heading_structure']['metrics']['repeated']
        );
        self::assertSame(1, $checks['content.heading_structure']['metrics']['h4']);
        self::assertSame(1, $checks['content.heading_structure']['metrics']['h5']);
        self::assertSame(1, $checks['content.heading_structure']['metrics']['h6']);
        self::assertSame(
            0,
            $checks['content.heading_structure']['metrics']['hierarchy_issues']
        );
        self::assertSame(
            BlogSeoStatus::GOOD,
            $checks['media.image_alternatives']['status']
        );
        self::assertSame(
            BlogSeoStatus::REVIEW,
            $checks['content.mechanical_repetition']['status']
        );
        self::assertSame(
            100,
            $checks['content.first_100_words']['metrics']['inspected_words']
        );
    }

    public function testTermConcentrationWarnsOnlyWithEnoughEvidence(): void
    {
        $heavy = implode(' ', array_merge(
            array_fill(0, 20, 'matrix'),
            array_map(
                static fn (int $index): string => 'contexto' . $index,
                range(1, 80)
            )
        ));
        $checks = $this->checks((new BlogSeoAnalyzer())->analyze(
            $this->draft($heavy),
            'es',
            '/noticias'
        )->toArray());

        self::assertSame(
            BlogSeoStatus::REVIEW,
            $checks['content.term_concentration']['status']
        );
        self::assertSame(
            'matrix',
            $checks['content.term_concentration']['metrics']['top_term']
        );
    }

    public function testCompetitionIsSameLocaleLimitedAndDistinguishesMatches(): void
    {
        $candidates = [
            new BlogSeoCompetingPage(
                BlogSeoCompetingPage::BLOG,
                'es',
                '/es/noticias/guia-segura-de-la-matrix',
                'Otra guía de Matrix',
                'Guía segura de Matrix'
            ),
            new BlogSeoCompetingPage(
                BlogSeoCompetingPage::STATIC_PAGE,
                'es',
                '/servicios/matrix',
                'Guía Matrix para elegir con criterio',
                null
            ),
            new BlogSeoCompetingPage(
                BlogSeoCompetingPage::STATIC_PAGE,
                'en',
                '/en/matrix',
                'Safe Matrix guide',
                'Understand Matrix'
            ),
        ];
        $analysis = (new BlogSeoAnalyzer())->analyze(
            $this->draft($this->naturalBody(320)),
            'es',
            '/noticias',
            $candidates
        )->toArray();
        $checks = $this->checks($analysis);

        self::assertSame(
            BlogSeoStatus::REVIEW,
            $checks['competition.cannibalization']['status']
        );
        self::assertCount(2, $analysis['competing_pages']);
        self::assertSame('complete', $analysis['competing_pages'][0]['match']);
        self::assertSame('partial', $analysis['competing_pages'][1]['match']);
        self::assertSame('es', $analysis['serp_preview']['locale']);
    }

    public function testUnavailableCompetitionBecomesPendingWithoutBreakingAnalysis(): void
    {
        $analysis = (new BlogSeoAnalyzer())->analyze(
            $this->draft($this->naturalBody(50)),
            'es',
            '/noticias',
            null
        )->toArray();
        $check = $this->checks($analysis)['competition.cannibalization'];

        self::assertSame(BlogSeoStatus::PENDING, $check['status']);
        self::assertSame([], $analysis['competing_pages']);
    }

    /** @param array<string, mixed> $analysis @return array<string, array<string, mixed>> */
    private function checks(array $analysis): array
    {
        $checks = [];
        foreach ($analysis['checks'] as $check) {
            $checks[$check['key']] = $check;
        }

        return $checks;
    }

    private function draft(
        string $body,
        ?string $title = 'Guía segura para entender Matrix y elegir con criterio',
        ?string $description = 'Descubre cómo entender Matrix con contexto, ordenar cada decisión y valorar sus consecuencias con una guía práctica, clara y útil para lectores.',
        string $h1 = 'Guía segura para entender Matrix y elegir con criterio',
        ?string $slug = 'guia-segura-de-la-matrix'
    ): BlogStructuredDraft {
        return $this->draftFromBlocks(
            [$this->paragraph(1, $body)],
            $title,
            $description,
            $h1,
            $slug
        );
    }

    /** @param list<array<string, mixed>> $blocks */
    private function draftFromBlocks(
        array $blocks,
        ?string $title = 'Guía segura para entender Matrix y elegir con criterio',
        ?string $description = 'Descubre cómo entender Matrix con contexto, ordenar cada decisión y valorar sus consecuencias con una guía práctica, clara y útil para lectores.',
        string $h1 = 'Guía segura para entender Matrix y elegir con criterio',
        ?string $slug = 'guia-segura-de-la-matrix'
    ): BlogStructuredDraft {
        return new BlogStructuredDraft(
            $h1,
            BlogDocument::fromArray([
                'schema' => BlogDocument::SCHEMA,
                'version' => BlogDocument::VERSION,
                'template' => 'article-basic-01',
                'blocks' => $blocks,
            ]),
            $slug,
            $title,
            $description,
            'Una guía clara para comprender Matrix y tomar decisiones informadas.'
        );
    }

    private function naturalBody(int $words): string
    {
        $terms = [
            'matrix', 'decisión', 'contexto', 'personas', 'historia',
            'elección', 'realidad', 'pregunta', 'respuesta', 'camino',
            'criterio', 'detalle', 'ejemplo', 'aprendizaje', 'consecuencia',
        ];
        $result = [];
        for ($index = 0; $index < $words; ++$index) {
            $result[] = $terms[$index % count($terms)];
        }

        return implode(' ', $result);
    }

    /** @return array<string, mixed> */
    private function paragraph(int $id, string $text): array
    {
        return [
            'id' => $this->id($id),
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => $text,
                'marks' => [],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function heading(int $id, int $level, string $text): array
    {
        return [
            'id' => $this->id($id),
            'type' => 'heading',
            'level' => $level,
            'content' => [[
                'type' => 'text',
                'text' => $text,
                'marks' => [],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function image(int $id, bool $decorative, string $alt): array
    {
        return [
            'id' => $this->id($id),
            'type' => 'image',
            'media_asset_public_id' => $this->id(500 + $id),
            'alt' => $alt,
            'title' => null,
            'caption' => null,
            'decorative' => $decorative,
            'display' => 'content',
        ];
    }

    private function id(int $number): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $number);
    }
}
