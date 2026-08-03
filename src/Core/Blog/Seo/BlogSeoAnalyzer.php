<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;

/**
 * Deterministic editorial diagnostics. Results are advisory and never gate
 * save or publication.
 */
final class BlogSeoAnalyzer
{
    public const MAX_COMPETING_RESULTS = 5;

    public function __construct(
        private readonly BlogSeoTextNormalizer $normalizer =
            new BlogSeoTextNormalizer(),
        private readonly BlogSeoSemanticProjector $projector =
            new BlogSeoSemanticProjector()
    ) {
    }

    /**
     * null candidates means the competition source was unavailable.
     *
     * @param list<BlogSeoCompetingPage>|null $candidates
     */
    public function analyze(
        BlogStructuredDraft $structuredDraft,
        string $locale,
        string $publicPath,
        ?array $candidates = [],
        bool $competitionComplete = true
    ): BlogSeoAnalysis {
        $locale = $this->locale($locale);
        $publicPath = $this->publicPath($publicPath);
        $draft = $structuredDraft->compatibilityDraft();
        $semantic = $this->projector->project($structuredDraft->document());
        $checks = [
            $this->lengthCheck(
                'metadata.title_length',
                'Title SEO',
                $draft->seoTitle(),
                30,
                65
            ),
            $this->lengthCheck(
                'metadata.description_length',
                'Meta description',
                $draft->metaDescription(),
                120,
                160
            ),
            $this->lengthCheck(
                'metadata.h1_length',
                'H1',
                $draft->h1(),
                20,
                80
            ),
            $this->slugCheck($draft->slug()),
            $this->coherenceCheck(
                $draft->h1(),
                $draft->seoTitle(),
                $locale
            ),
            $this->bodyLengthCheck($semantic),
            $this->introductionCheck(
                $semantic,
                $draft->h1(),
                $draft->seoTitle(),
                $locale
            ),
            $this->headingCheck($semantic, $locale),
            $this->imageCheck($semantic),
            $this->repetitionCheck($semantic),
            $this->concentrationCheck($semantic, $locale),
        ];

        [$competitionCheck, $matches] = $this->competitionCheck(
            $draft->h1(),
            $draft->seoTitle(),
            $draft->slug(),
            $locale,
            $candidates ?? [],
            $candidates !== null && $competitionComplete
        );
        $checks[] = $competitionCheck;

        $slug = $draft->slug() ?? '{slug-pendiente}';
        $preview = new BlogSeoSerpPreview(
            $locale,
            $draft->seoTitle() ?? $draft->h1(),
            rtrim($publicPath, '/') . '/' . $slug,
            $draft->metaDescription() ?? $draft->excerpt() ?? ''
        );

        return new BlogSeoAnalysis($checks, $preview, $matches);
    }

    private function lengthCheck(
        string $key,
        string $label,
        ?string $value,
        int $minimum,
        int $maximum
    ): BlogSeoCheck {
        if ($value === null || trim($value) === '') {
            return new BlogSeoCheck(
                $key,
                'metadata',
                $label,
                BlogSeoStatus::PENDING,
                'Completa este campo para poder revisarlo.',
                ['characters' => 0, 'recommended_min' => $minimum,
                    'recommended_max' => $maximum]
            );
        }
        $length = $this->normalizer->length($value);
        $good = $length >= $minimum && $length <= $maximum;

        return new BlogSeoCheck(
            $key,
            'metadata',
            $label,
            $good ? BlogSeoStatus::GOOD : BlogSeoStatus::REVIEW,
            $good
                ? 'La longitud está dentro del intervalo editorial recomendado.'
                : 'Revisa la longitud; prima la claridad antes que forzar el límite.',
            ['characters' => $length, 'recommended_min' => $minimum,
                'recommended_max' => $maximum]
        );
    }

    private function slugCheck(?string $slug): BlogSeoCheck
    {
        if ($slug === null || $slug === '') {
            return new BlogSeoCheck(
                'metadata.slug',
                'metadata',
                'Slug',
                BlogSeoStatus::PENDING,
                'Define el slug independiente del H1 y del title.',
                ['characters' => 0, 'terms' => 0]
            );
        }
        $terms = array_values(array_filter(explode('-', $slug)));
        $length = $this->normalizer->length($slug);
        $good = $length <= 75 && count($terms) >= 2 && count($terms) <= 8;

        return new BlogSeoCheck(
            'metadata.slug',
            'metadata',
            'Slug',
            $good ? BlogSeoStatus::GOOD : BlogSeoStatus::REVIEW,
            $good
                ? 'El slug es legible y conciso.'
                : 'Conviene un slug descriptivo de entre dos y ocho términos.',
            ['characters' => $length, 'terms' => count($terms)]
        );
    }

    private function coherenceCheck(
        string $h1,
        ?string $title,
        string $locale
    ): BlogSeoCheck {
        if ($title === null || trim($title) === '') {
            return new BlogSeoCheck(
                'metadata.title_h1_overlap',
                'metadata',
                'Coherencia entre title y H1',
                BlogSeoStatus::PENDING,
                'Falta el title para comparar ambos mensajes.'
            );
        }
        $first = $this->uniqueMeaningful($h1, $locale);
        $second = $this->uniqueMeaningful($title, $locale);
        $overlap = $this->overlap($first, $second);
        $good = $overlap >= 0.45;

        return new BlogSeoCheck(
            'metadata.title_h1_overlap',
            'metadata',
            'Coherencia entre title y H1',
            $good ? BlogSeoStatus::GOOD : BlogSeoStatus::REVIEW,
            $good
                ? 'Title y H1 comparten la intención sin exigir que sean iguales.'
                : 'Revisa que title y H1 respondan a la misma intención de búsqueda.',
            ['overlap_percent' => (int) round($overlap * 100)]
        );
    }

    private function bodyLengthCheck(
        BlogSeoSemanticProjection $semantic
    ): BlogSeoCheck {
        $count = count($semantic->tokens());
        $status = $count === 0
            ? BlogSeoStatus::PENDING
            : ($count >= 300 ? BlogSeoStatus::GOOD : BlogSeoStatus::REVIEW);

        return new BlogSeoCheck(
            'content.word_count',
            'content',
            'Extensión editorial',
            $status,
            match ($status) {
                BlogSeoStatus::GOOD =>
                    'El cuerpo tiene contexto suficiente para una revisión completa.',
                BlogSeoStatus::REVIEW =>
                    'Comprueba que la extensión resuelva la intención; no añadas relleno.',
                default => 'Añade contenido antes de revisar su extensión.',
            },
            ['words' => $count, 'reference_words' => 300]
        );
    }

    private function introductionCheck(
        BlogSeoSemanticProjection $semantic,
        string $h1,
        ?string $title,
        string $locale
    ): BlogSeoCheck {
        if ($semantic->introTokens() === []) {
            return new BlogSeoCheck(
                'content.first_100_words',
                'content',
                'Primeras 100 palabras',
                BlogSeoStatus::PENDING,
                'Añade una introducción que presente la propuesta principal.',
                ['inspected_words' => 0]
            );
        }
        $targets = $this->uniqueMeaningful(
            $h1 . ' ' . ($title ?? ''),
            $locale
        );
        if ($targets === []) {
            return new BlogSeoCheck(
                'content.first_100_words',
                'content',
                'Primeras 100 palabras',
                BlogSeoStatus::PENDING,
                'No hay términos principales suficientes para contrastar la introducción.',
                ['inspected_words' => count($semantic->introTokens())]
            );
        }
        $intro = array_values(array_unique(array_filter(
            $semantic->introTokens(),
            fn (string $token): bool => in_array(
                $token,
                $this->normalizer->meaningfulTokens($token, $locale),
                true
            )
        )));
        $overlap = $this->overlap($targets, $intro);
        $good = $overlap >= 0.35;

        return new BlogSeoCheck(
            'content.first_100_words',
            'content',
            'Primeras 100 palabras',
            $good ? BlogSeoStatus::GOOD : BlogSeoStatus::REVIEW,
            $good
                ? 'La introducción presenta términos centrales del artículo.'
                : 'Aclara antes la propuesta principal en la introducción.',
            ['inspected_words' => count($semantic->introTokens()),
                'overlap_percent' => (int) round($overlap * 100)]
        );
    }

    private function headingCheck(
        BlogSeoSemanticProjection $semantic,
        string $locale
    ): BlogSeoCheck {
        $headings = $semantic->headings();
        if ($headings === []) {
            $status = count($semantic->tokens()) >= 300
                ? BlogSeoStatus::REVIEW
                : BlogSeoStatus::PENDING;

            return new BlogSeoCheck(
                'content.heading_structure',
                'content',
                'Jerarquía H2-H6',
                $status,
                $status === BlogSeoStatus::REVIEW
                    ? 'Segrega el contenido extenso con encabezados interiores cuando aporte claridad.'
                    : 'Todavía no hay encabezados interiores que revisar.',
                [
                    'h2' => 0,
                    'h3' => 0,
                    'h4' => 0,
                    'h5' => 0,
                    'h6' => 0,
                    'empty' => 0,
                    'repeated' => 0,
                    'hierarchy_issues' => 0,
                ]
            );
        }
        $seen = [];
        $repeated = 0;
        $empty = 0;
        $hierarchyIssues = 0;
        $counts = array_fill_keys([2, 3, 4, 5, 6], 0);
        /** @var array<int, true> $activeLevels */
        $activeLevels = [];
        foreach ($headings as $heading) {
            $text = trim($heading['text']);
            if ($text === '') {
                ++$empty;
            }
            $signature = $this->normalizer->phrase($text, $locale);
            if ($signature !== '' && isset($seen[$signature])) {
                ++$repeated;
            }
            $seen[$signature] = true;
            $level = (int) $heading['level'];
            if (isset($counts[$level])) {
                ++$counts[$level];
            }
            if ($level === 2) {
                $activeLevels = [2 => true];
            } else {
                if (!isset($activeLevels[$level - 1])) {
                    ++$hierarchyIssues;
                }
                foreach (array_keys($activeLevels) as $activeLevel) {
                    if ($activeLevel >= $level) {
                        unset($activeLevels[$activeLevel]);
                    }
                }
                $activeLevels[$level] = true;
            }
        }
        $issues = $empty + $repeated + $hierarchyIssues;

        return new BlogSeoCheck(
            'content.heading_structure',
            'content',
            'Jerarquía H2-H6',
            $issues === 0 ? BlogSeoStatus::GOOD : BlogSeoStatus::REVIEW,
            $issues === 0
                ? 'Los encabezados interiores son únicos y respetan la jerarquía.'
                : 'Revisa encabezados vacíos, repetidos o niveles sin su padre inmediato.',
            [
                'h2' => $counts[2],
                'h3' => $counts[3],
                'h4' => $counts[4],
                'h5' => $counts[5],
                'h6' => $counts[6],
                'empty' => $empty,
                'repeated' => $repeated,
                'hierarchy_issues' => $hierarchyIssues,
            ]
        );
    }

    private function imageCheck(
        BlogSeoSemanticProjection $semantic
    ): BlogSeoCheck {
        $images = $semantic->images();
        if ($images === []) {
            return new BlogSeoCheck(
                'media.image_alternatives',
                'media',
                'Texto alternativo de imágenes',
                BlogSeoStatus::PENDING,
                'El documento no contiene imágenes.',
                ['images' => 0, 'issues' => 0]
            );
        }
        $issues = 0;
        foreach ($images as $image) {
            if (
                ($image['decorative'] && $image['alt'] !== '')
                || (!$image['decorative'] && trim($image['alt']) === '')
            ) {
                ++$issues;
            }
        }

        return new BlogSeoCheck(
            'media.image_alternatives',
            'media',
            'Texto alternativo de imágenes',
            $issues === 0 ? BlogSeoStatus::GOOD : BlogSeoStatus::REVIEW,
            $issues === 0
                ? 'Las imágenes informativas tienen ALT y las decorativas lo omiten.'
                : 'Describe las imágenes informativas y deja vacío el ALT decorativo.',
            ['images' => count($images), 'issues' => $issues]
        );
    }

    private function repetitionCheck(
        BlogSeoSemanticProjection $semantic
    ): BlogSeoCheck {
        $tokens = $semantic->tokens();
        if ($tokens === []) {
            return new BlogSeoCheck(
                'content.mechanical_repetition',
                'content',
                'Repetición mecánica',
                BlogSeoStatus::PENDING,
                'Añade contenido para comprobar repeticiones.'
            );
        }
        $runs = 0;
        for ($index = 2, $max = count($tokens); $index < $max; ++$index) {
            if (
                $tokens[$index] === $tokens[$index - 1]
                && $tokens[$index] === $tokens[$index - 2]
            ) {
                ++$runs;
            }
        }

        return new BlogSeoCheck(
            'content.mechanical_repetition',
            'content',
            'Repetición mecánica',
            $runs === 0 ? BlogSeoStatus::GOOD : BlogSeoStatus::REVIEW,
            $runs === 0
                ? 'No se detectan palabras repetidas tres veces seguidas.'
                : 'Revisa las repeticiones consecutivas; pueden dificultar la lectura.',
            ['repeated_runs' => $runs]
        );
    }

    private function concentrationCheck(
        BlogSeoSemanticProjection $semantic,
        string $locale
    ): BlogSeoCheck {
        $tokens = $this->normalizer->meaningfulTokens(
            $semantic->bodyText(),
            $locale
        );
        if (count($tokens) < 50) {
            return new BlogSeoCheck(
                'content.term_concentration',
                'content',
                'Concentración de términos',
                BlogSeoStatus::PENDING,
                'Hace falta más contenido para valorar la concentración con sentido.',
                ['meaningful_words' => count($tokens)]
            );
        }
        $counts = array_count_values($tokens);
        arsort($counts, SORT_NUMERIC);
        $topTerm = (string) array_key_first($counts);
        $topCount = (int) reset($counts);
        $ratio = $topCount / count($tokens);
        $review = $topCount >= 8 && $ratio > 0.08;

        return new BlogSeoCheck(
            'content.term_concentration',
            'content',
            'Concentración de términos',
            $review ? BlogSeoStatus::REVIEW : BlogSeoStatus::GOOD,
            $review
                ? 'Un término concentra demasiado el texto; revisa naturalidad y variantes.'
                : 'No se aprecia una concentración mecánica dominante.',
            ['meaningful_words' => count($tokens), 'top_term' => $topTerm,
                'top_occurrences' => $topCount,
                'top_percent' => (int) round($ratio * 100)]
        );
    }

    /**
     * @param list<BlogSeoCompetingPage> $candidates
     * @return array{BlogSeoCheck,list<array<string,string|null>>}
     */
    private function competitionCheck(
        string $h1,
        ?string $title,
        ?string $slug,
        string $locale,
        array $candidates,
        bool $complete
    ): array {
        $matches = [];
        $currentPhrase = $this->uniqueMeaningful(
            $h1 . ' ' . ($title ?? ''),
            $locale
        );
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof BlogSeoCompetingPage) {
                throw new \InvalidArgumentException(
                    'Invalid Blog SEO candidates.'
                );
            }
            if ($candidate->locale() !== $locale) {
                continue;
            }
            $candidatePhrase = $this->uniqueMeaningful(
                $candidate->h1() . ' ' . ($candidate->seoTitle() ?? ''),
                $locale
            );
            $candidateSegments = explode('/', trim($candidate->url(), '/'));
            $candidateSlug = $candidateSegments === []
                ? ''
                : (string) end($candidateSegments);
            $exact = $slug !== null && hash_equals($slug, $candidateSlug);
            $overlap = $this->jaccard($currentPhrase, $candidatePhrase);
            if (!$exact && ($overlap < 0.6 || count(array_intersect(
                $currentPhrase,
                $candidatePhrase
            )) < 3)) {
                continue;
            }
            $matches[] = $candidate->toSafeArray(
                $exact || $overlap >= 0.9 ? 'complete' : 'partial'
            );
            if (count($matches) === self::MAX_COMPETING_RESULTS) {
                break;
            }
        }
        $count = count($matches);

        $status = $count > 0
            ? BlogSeoStatus::REVIEW
            : ($complete ? BlogSeoStatus::GOOD : BlogSeoStatus::PENDING);

        return [new BlogSeoCheck(
            'competition.cannibalization',
            'competition',
            'Posible canibalización',
            $status,
            match ($status) {
                BlogSeoStatus::REVIEW =>
                    'Revisa las URLs coincidentes antes de publicar; el aviso no bloquea.',
                BlogSeoStatus::GOOD =>
                    'No se detectan solapamientos fuertes en este idioma.',
                default =>
                    'No se pudo consultar el inventario canónico completo.',
            },
            ['matches' => $count, 'inventory_complete' => $complete]
        ), $matches];
    }

    /** @return list<string> */
    private function uniqueMeaningful(string $value, string $locale): array
    {
        return array_values(array_unique(
            $this->normalizer->meaningfulTokens($value, $locale)
        ));
    }

    /** @param list<string> $first @param list<string> $second */
    private function overlap(array $first, array $second): float
    {
        $denominator = min(count($first), count($second));

        return $denominator === 0
            ? 0.0
            : count(array_intersect($first, $second)) / $denominator;
    }

    /** @param list<string> $first @param list<string> $second */
    private function jaccard(array $first, array $second): float
    {
        $union = array_unique(array_merge($first, $second));

        return $union === []
            ? 0.0
            : count(array_intersect($first, $second)) / count($union);
    }

    private function locale(string $locale): string
    {
        if (
            preg_match('/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/', $locale) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid Blog SEO locale.');
        }

        return $locale;
    }

    private function publicPath(string $path): string
    {
        if (
            !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '?')
            || str_contains($path, '#')
        ) {
            throw new \InvalidArgumentException(
                'Invalid Blog SEO public path.'
            );
        }

        return $path;
    }
}
