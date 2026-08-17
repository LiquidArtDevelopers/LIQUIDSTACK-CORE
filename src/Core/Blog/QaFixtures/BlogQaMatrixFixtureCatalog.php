<?php

declare(strict_types=1);

namespace App\Core\Blog\QaFixtures;

use App\Core\Blog\BlogInput;
use App\Core\Blog\Seo\BlogRobotsPreferences;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;

/** Code-owned QA catalog. It never reads project languages or public routes. */
final class BlogQaMatrixFixtureCatalog
{
    public const ARTICLE_COUNT = 10;
    public const LOCALES = ['es', 'en', 'eu'];
    public const DEFAULT_LOCALES = 'es,en,eu';

    private const VIDEO_ID = 'vKQi3bBA1y8';
    private const TWO_COLUMN_PRESETS = [
        '2-50-50',
        '2-40-60',
        '2-60-40',
        '2-30-70',
        '2-70-30',
    ];

    /** @return list<string> */
    public static function parseLocales(string $csv): array
    {
        if ($csv === '' || strlen($csv) > 64 || trim($csv) !== $csv) {
            throw new BlogQaMatrixFixtureException(
                'blog.qa_fixture.locales_invalid'
            );
        }
        $selected = [];
        foreach (explode(',', strtolower($csv)) as $locale) {
            if (
                !in_array($locale, self::LOCALES, true)
                || isset($selected[$locale])
            ) {
                throw new BlogQaMatrixFixtureException(
                    'blog.qa_fixture.locales_invalid'
                );
            }
            $selected[$locale] = true;
        }
        if ($selected === []) {
            throw new BlogQaMatrixFixtureException(
                'blog.qa_fixture.locales_invalid'
            );
        }

        return array_values(array_filter(
            self::LOCALES,
            static fn (string $locale): bool => isset($selected[$locale])
        ));
    }

    /** @return list<BlogQaMatrixFixtureArticle> */
    public function articles(string $mediaAssetPublicId): array
    {
        try {
            BlogInput::generatedPublicId($mediaAssetPublicId);
        } catch (\Throwable) {
            throw new BlogQaMatrixFixtureException(
                'blog.qa_fixture.media_asset_invalid'
            );
        }

        $articles = [];
        for ($number = 1; $number <= self::ARTICLE_COUNT; ++$number) {
            $variants = [];
            foreach (self::LOCALES as $locale) {
                $variants[] = $this->variant(
                    $number,
                    $locale,
                    $mediaAssetPublicId
                );
            }
            $articles[] = new BlogQaMatrixFixtureArticle(
                $number,
                $this->uuid($number, 'aggregate'),
                $this->uuid($number, 'dummy-assignment'),
                $variants
            );
        }

        return $articles;
    }

    private function variant(
        int $number,
        string $locale,
        string $mediaAssetPublicId
    ): BlogQaMatrixFixtureVariant {
        $title = $this->title($locale, $number);
        $copy = $this->copy($locale, $number, $title);
        $slug = sprintf('qa-matrix-%02d', $number);
        $document = BlogDocument::fromArray([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => [[
                'id' => $this->uuid($number, $locale . '-section'),
                'type' => 'section',
                'children' => [
                    $this->textModule($number, $locale, 'heading-two', [[
                        'type' => 'heading',
                        'level' => 2,
                        'content' => [$this->text($copy['section'])],
                        'preset' => $number % 2 === 0
                            ? 'accent-line' : 'default',
                    ]]),
                    $this->textModule($number, $locale, 'intro', [[
                        'type' => 'paragraph',
                        'content' => [
                            $this->text($copy['intro'], ['strong']),
                            [
                                'type' => 'link',
                                'text' => $copy['inline_link'],
                                'marks' => ['em', 'underline'],
                                'href' => sprintf(
                                    '/qa-fixtures/matrix-%02d',
                                    $number
                                ),
                                'title' => $copy['link_title'],
                                'target' => 'same',
                            ],
                            $this->text($copy['intro_end']),
                        ],
                    ]]),
                    $this->article(
                        $number,
                        $locale,
                        $mediaAssetPublicId,
                        $copy
                    ),
                    $this->division($number, $locale, $copy),
                    $this->textModule($number, $locale, 'outro', [[
                        'type' => 'paragraph',
                        'content' => [
                            $this->text($copy['outro'], ['em']),
                        ],
                    ]]),
                ],
            ]],
        ]);
        $draft = new BlogStructuredDraft(
            sprintf('[QA Matrix %02d] %s', $number, $title),
            $document,
            $slug,
            sprintf('QA Matrix %02d: %s', $number, $title),
            $copy['meta'],
            $copy['excerpt'],
            robotsPreferences: BlogRobotsPreferences::noIndexNoFollow()
        );

        return new BlogQaMatrixFixtureVariant(
            $locale,
            $this->uuid($number, $locale . '-localization'),
            $this->uuid($number, $locale . '-document'),
            $this->uuid($number, $locale . '-revision'),
            $draft
        );
    }

    /**
     * @param array<string, string> $copy
     * @return array<string, mixed>
     */
    private function article(
        int $number,
        string $locale,
        string $mediaAssetPublicId,
        array $copy
    ): array {
        $ordered = $number % 2 === 0;
        $markers = $ordered
            ? ['decimal', 'lower-alpha', 'upper-alpha']
            : ['disc', 'circle', 'square'];

        return [
            'id' => $this->uuid($number, $locale . '-article'),
            'type' => 'article',
            'layout' => [
                'preset' => self::TWO_COLUMN_PRESETS[
                    ($number - 1) % count(self::TWO_COLUMN_PRESETS)
                ],
                'columns' => [[
                    'id' => $this->uuid($number, $locale . '-article-col-a'),
                    'children' => [
                        $this->textModule(
                            $number,
                            $locale,
                            'heading-three',
                            [[
                                'type' => 'heading',
                                'level' => 3,
                                'content' => [
                                    $this->text($copy['detail_title']),
                                ],
                                'preset' => 'accent-block',
                            ]]
                        ),
                        $this->textModule($number, $locale, 'detail', [[
                            'type' => 'paragraph',
                            'content' => [
                                $this->text($copy['detail'], ['strong']),
                            ],
                        ]]),
                        $this->textModule($number, $locale, 'list', [[
                            'type' => 'list',
                            'ordered' => $ordered,
                            'marker' => $markers[($number - 1) % 3],
                            'items' => [
                                $this->listItem(
                                    $number,
                                    $locale,
                                    'list-a',
                                    $copy['list_a']
                                ),
                                $this->listItem(
                                    $number,
                                    $locale,
                                    'list-b',
                                    $copy['list_b']
                                ),
                                $this->listItem(
                                    $number,
                                    $locale,
                                    'list-c',
                                    $copy['list_c']
                                ),
                            ],
                        ]]),
                    ],
                ], [
                    'id' => $this->uuid($number, $locale . '-article-col-b'),
                    'children' => [
                        $this->textModule($number, $locale, 'visual-copy', [[
                            'type' => 'paragraph',
                            'content' => [
                                $this->text($copy['visual'], ['em']),
                            ],
                        ]]),
                        $this->module($number, $locale, 'image', [
                            'type' => 'image',
                            'media_asset_public_id' => $mediaAssetPublicId,
                            'alt' => $copy['image_alt'],
                            'title' => $copy['image_title'],
                            'caption' => $copy['image_caption'],
                            'decorative' => false,
                            'display' => 'content',
                        ]),
                        $this->module($number, $locale, 'video', [
                            'type' => 'video',
                            'provider' => 'youtube',
                            'video_id' => self::VIDEO_ID,
                            'title' => $copy['video_title'],
                            'start_seconds' => $number - 1,
                        ]),
                    ],
                ]],
            ],
            'presentation' => ['width' => 'full', 'align' => 'start'],
        ];
    }

    /**
     * @param array<string, string> $copy
     * @return array<string, mixed>
     */
    private function division(int $number, string $locale, array $copy): array
    {
        $quotePresets = ['default', 'accent', 'minimal'];
        $tones = ['neutral', 'info', 'warning'];
        $ctaVariants = ['primary', 'secondary', 'type03', 'type04'];
        $lineStyles = ['solid', 'dashed', 'dotted', 'double'];
        $thicknesses = ['thin', 'medium', 'thick'];

        return [
            'id' => $this->uuid($number, $locale . '-division'),
            'type' => 'div',
            'layout' => [
                'preset' => '3',
                'columns' => [[
                    'id' => $this->uuid($number, $locale . '-division-col-a'),
                    'children' => [
                        $this->textModule($number, $locale, 'callout', [[
                            'type' => 'callout',
                            'tone' => $tones[($number - 1) % 3],
                            'content' => [
                                $this->text(
                                    $copy['callout'],
                                    ['strong', 'em']
                                ),
                            ],
                        ]]),
                        $this->textModule($number, $locale, 'quote', [[
                            'type' => 'quote',
                            'content' => [$this->text($copy['quote'])],
                            'author' => $copy['quote_author'],
                            'source' => $copy['quote_source'],
                            'preset' => $quotePresets[($number - 1) % 3],
                        ]]),
                    ],
                ], [
                    'id' => $this->uuid($number, $locale . '-division-col-b'),
                    'children' => [
                        $this->module($number, $locale, 'link', [
                            'type' => 'cta',
                            'label' => $copy['link_label'],
                            'href' => sprintf(
                                '/qa-fixtures/matrix-%02d/reference',
                                $number
                            ),
                            'title' => $copy['link_title'],
                            'target' => 'same',
                            'variant' => 'primary',
                        ]),
                        $this->module($number, $locale, 'cta', [
                            'type' => 'cta',
                            'label' => $copy['cta'],
                            'href' => sprintf(
                                '/qa-fixtures/matrix-%02d/action',
                                $number
                            ),
                            'title' => $copy['link_title'],
                            'target' => 'same',
                            'variant' => $ctaVariants[($number - 1) % 4],
                        ]),
                    ],
                ], [
                    'id' => $this->uuid($number, $locale . '-division-col-c'),
                    'children' => [
                        $this->module($number, $locale, 'separator', [
                            'type' => 'separator',
                            'line_style' => $lineStyles[($number - 1) % 4],
                            'thickness' => $thicknesses[($number - 1) % 3],
                            'color' => 'color0' . (($number - 1) % 6),
                        ]),
                        $this->textModule(
                            $number,
                            $locale,
                            'closing-note',
                            [[
                                'type' => 'paragraph',
                                'content' => [
                                    $this->text($copy['closing_note']),
                                ],
                            ]]
                        ),
                    ],
                ]],
            ],
            'presentation' => ['width' => 'full', 'align' => 'center'],
        ];
    }

    /** @param array<string, mixed> $block @return array<string, mixed> */
    private function module(
        int $number,
        string $locale,
        string $role,
        array $block
    ): array {
        return ['id' => $this->uuid($number, $locale . '-' . $role)]
            + $block
            + ['presentation' => [
                'width' => 'full',
                'align' => 'start',
                'spacing_after' => $number % 2 === 0 ? 'm' : 's',
            ]];
    }

    /**
     * @param list<array<string, mixed>> $flow
     * @return array<string, mixed>
     */
    private function textModule(
        int $number,
        string $locale,
        string $role,
        array $flow
    ): array {
        return $this->module($number, $locale, $role, [
            'type' => 'paragraph',
            'content' => $flow,
        ]);
    }

    /** @return array{id: string, content: list<array<string, mixed>>} */
    private function listItem(
        int $number,
        string $locale,
        string $role,
        string $copy
    ): array {
        return [
            'id' => $this->uuid($number, $locale . '-' . $role),
            'content' => [$this->text($copy, ['strong'])],
        ];
    }

    /** @param list<string> $marks @return array<string, mixed> */
    private function text(string $copy, array $marks = []): array
    {
        return ['type' => 'text', 'text' => $copy, 'marks' => $marks];
    }

    private function title(string $locale, int $number): string
    {
        $titles = match ($locale) {
            'es' => [
                'La llamada del conejo blanco',
                'La elección de Neo',
                'Morpheus y la verdad',
                'Trinity en movimiento',
                'El código de Matrix',
                'La ciudad simulada',
                'Agentes y control',
                'El Oráculo y la decisión',
                'Zion como resistencia',
                'Más allá de la máquina',
            ],
            'en' => [
                'The call of the white rabbit',
                'Neo makes a choice',
                'Morpheus and the truth',
                'Trinity in motion',
                'The code of the Matrix',
                'The simulated city',
                'Agents and control',
                'The Oracle and the decision',
                'Zion as resistance',
                'Beyond the machine',
            ],
            'eu' => [
                'Untxi zuriaren deia',
                'Neoren hautua',
                'Morpheus eta egia',
                'Trinity mugimenduan',
                'Matrixeko kodea',
                'Hiri simulatua',
                'Agenteak eta kontrola',
                'Orakulua eta erabakia',
                'Zion erresistentzia gisa',
                'Makinatik harago',
            ],
            default => throw new BlogQaMatrixFixtureException(
                'blog.qa_fixture.catalog_locale_invalid'
            ),
        };

        return $titles[$number - 1]
            ?? throw new BlogQaMatrixFixtureException(
                'blog.qa_fixture.catalog_article_invalid'
            );
    }

    /** @return array<string, string> */
    private function copy(string $locale, int $number, string $title): array
    {
        return match ($locale) {
            'es' => $this->spanishCopy($number, $title),
            'en' => $this->englishCopy($number, $title),
            'eu' => $this->basqueCopy($number, $title),
            default => throw new BlogQaMatrixFixtureException(
                'blog.qa_fixture.catalog_locale_invalid'
            ),
        };
    }

    /** @return array<string, string> */
    private function spanishCopy(int $number, string $title): array
    {
        return [
            'section' => 'Escenario de prueba: ' . $title,
            'intro' => 'Este artículo QA recrea un recorrido editorial amplio '
                . 'por Matrix. Combina texto narrativo, estructura semántica '
                . 'y componentes visuales para comprobar el comportamiento '
                . 'del editor sin mezclarse con contenido público ordinario. ',
            'inline_link' => 'La referencia interna acompaña el recorrido',
            'intro_end' => ', pero el fixture permanece aislado por la '
                . 'categoría Dummy y por directivas robots restrictivas.',
            'detail_title' => 'Lectura estructurada del escenario ' . $number,
            'detail' => 'La escena se analiza como una secuencia de decisiones, '
                . 'señales y consecuencias. Cada bloque aporta suficiente '
                . 'longitud para observar ritmos, saltos de línea, anchos de '
                . 'columna y jerarquías tipográficas en pantallas distintas.',
            'visual' => 'La columna visual reutiliza un recurso AVIF real de la '
                . 'biblioteca privada y lo acompaña de un vídeo de YouTube. '
                . 'Ambas piezas deben conservar su proporción y su contexto.',
            'outro' => 'El cierre reúne las pistas del artículo y recuerda que '
                . 'el propósito es comprobar composición, persistencia y '
                . 'entrega segura. No pretende promocionar una ruta ni '
                . 'participar en indexación, feeds o mapas del sitio.',
            'callout' => 'Destacado QA: comprobar contraste, espaciado y lectura '
                . 'sin convertir el fixture en contenido descubrible.',
            'quote' => 'Una prueba útil no adivina el sistema: deja evidencia '
                . 'repetible de cada decisión.',
            'quote_author' => 'Equipo QA Matrix',
            'quote_source' => 'Catálogo interno de fixtures',
            'list_a' => 'Validar la jerarquía de títulos y el texto enriquecido.',
            'list_b' => 'Revisar listas, columnas, contenedores y separadores.',
            'list_c' => 'Confirmar imagen real, vídeo y enlaces controlados.',
            'link_label' => 'Abrir referencia QA del escenario',
            'link_title' => 'Referencia privada del fixture Matrix',
            'cta' => 'Probar acción del escenario',
            'closing_note' => 'La nota final ocupa una tercera columna para '
                . 'hacer visibles los límites y el reflujo del contenido.',
            'image_alt' => 'Imagen QA reutilizada para ' . $title,
            'image_title' => 'Recurso visual del escenario Matrix ' . $number,
            'image_caption' => 'Activo AVIF existente seleccionado de forma '
                . 'explícita antes de crear el fixture.',
            'video_title' => 'Vídeo de YouTube para el escenario Matrix '
                . $number,
            'meta' => 'Fixture QA interno de Matrix para comprobar documentos '
                . 'V2, componentes semánticos, medios y publicación aislada.',
            'excerpt' => 'Artículo QA Matrix ' . $number . ' con texto amplio, '
                . 'listas, destacados, columnas, imagen y vídeo. Siempre '
                . 'aislado mediante Dummy, noindex y nofollow.',
        ];
    }

    /** @return array<string, string> */
    private function englishCopy(int $number, string $title): array
    {
        return [
            'section' => 'Test scenario: ' . $title,
            'intro' => 'This QA article recreates a broad editorial journey '
                . 'through the Matrix. It combines narrative copy, semantic '
                . 'structure and visual components so the editor can be '
                . 'checked without joining ordinary public content. ',
            'inline_link' => 'An internal reference follows the journey',
            'intro_end' => ', while the fixture stays isolated by the Dummy '
                . 'category and restrictive robots directives.',
            'detail_title' => 'Structured reading of scenario ' . $number,
            'detail' => 'The scene is examined as a sequence of decisions, '
                . 'signals and consequences. Every block carries enough copy '
                . 'to reveal rhythm, wrapping, column widths and typographic '
                . 'hierarchy across different viewport sizes.',
            'visual' => 'The visual column reuses a real AVIF asset from the '
                . 'private library and places it beside a YouTube video. Both '
                . 'items should preserve proportion, meaning and context.',
            'outro' => 'The closing passage gathers the article clues and '
                . 'restates its purpose: repeatable checks for composition, '
                . 'persistence and safe delivery. It is not intended to '
                . 'promote a route or appear in search, feeds or sitemaps.',
            'callout' => 'QA highlight: check contrast, spacing and reading '
                . 'without turning the fixture into discoverable content.',
            'quote' => 'A useful test does not guess the system; it leaves '
                . 'repeatable evidence for every decision.',
            'quote_author' => 'Matrix QA team',
            'quote_source' => 'Internal fixture catalog',
            'list_a' => 'Validate heading hierarchy and rich text behavior.',
            'list_b' => 'Review lists, columns, containers and separators.',
            'list_c' => 'Confirm the real image, video and controlled links.',
            'link_label' => 'Open the scenario QA reference',
            'link_title' => 'Private reference for the Matrix fixture',
            'cta' => 'Test the scenario action',
            'closing_note' => 'The final note fills a third column so content '
                . 'boundaries and responsive reflow remain visible.',
            'image_alt' => 'Reused QA image for ' . $title,
            'image_title' => 'Visual asset for Matrix scenario ' . $number,
            'image_caption' => 'Existing AVIF asset selected explicitly '
                . 'before any fixture mutation.',
            'video_title' => 'YouTube video for Matrix scenario ' . $number,
            'meta' => 'Internal Matrix QA fixture for checking V2 documents, '
                . 'semantic components, media and isolated publication.',
            'excerpt' => 'Matrix QA article ' . $number . ' with substantial '
                . 'copy, lists, highlights, columns, an image and a video. '
                . 'Always isolated by Dummy, noindex and nofollow.',
        ];
    }

    /** @return array<string, string> */
    private function basqueCopy(int $number, string $title): array
    {
        return [
            'section' => 'Proba-eszenatokia: ' . $title,
            'intro' => 'QA artikulu honek Matrixen barruko ibilbide editorial '
                . 'zabala berreraikitzen du. Narrazio-testua, egitura '
                . 'semantikoa eta osagai bisualak elkartzen ditu editorea '
                . 'eduki publiko arruntarekin nahastu gabe egiaztatzeko. ',
            'inline_link' => 'Barne-erreferentziak ibilbidea laguntzen du',
            'intro_end' => ', baina fixturea Dummy kategoriak eta robots '
                . 'direktiba murriztaileek isolatuta mantentzen dute.',
            'detail_title' => $number . '. eszenatokiaren irakurketa egituratua',
            'detail' => 'Eszena erabaki, seinale eta ondorioen segida gisa '
                . 'aztertzen da. Bloke bakoitzak nahikoa testu dauka erritmoa, '
                . 'lerro-jauziak, zutabeen zabalerak eta tipografia-mailak '
                . 'pantaila desberdinetan ikusi ahal izateko.',
            'visual' => 'Zutabe bisualak liburutegi pribatuko benetako AVIF '
                . 'aktibo bat berrerabiltzen du eta YouTubeko bideo batekin '
                . 'uztartzen du. Biek proportzioa eta testuingurua gorde behar dute.',
            'outro' => 'Amaierak artikuluko pistak bildu eta helburua '
                . 'gogorarazten du: konposizioa, persistentzia eta entrega '
                . 'segurua modu errepikagarrian egiaztatzea. Ez du ibilbiderik '
                . 'sustatu edo bilaketa, feed zein sitemapetan agertu behar.',
            'callout' => 'QA nabarmendua: kontrastea, tarteak eta irakurketa '
                . 'egiaztatu fixturea aurkigarri bihurtu gabe.',
            'quote' => 'Proba erabilgarriak ez du sistema asmatzen; erabaki '
                . 'bakoitzaren ebidentzia errepikagarria uzten du.',
            'quote_author' => 'Matrix QA taldea',
            'quote_source' => 'Barneko fixture katalogoa',
            'list_a' => 'Izenburuen hierarkia eta testu aberatsa balioztatu.',
            'list_b' => 'Zerrendak, zutabeak, edukiontziak eta bereizleak berrikusi.',
            'list_c' => 'Benetako irudia, bideoa eta esteka kontrolatuak baieztatu.',
            'link_label' => 'Ireki eszenatokiaren QA erreferentzia',
            'link_title' => 'Matrix fixturearen erreferentzia pribatua',
            'cta' => 'Probatu eszenatokiaren ekintza',
            'closing_note' => 'Azken oharrak hirugarren zutabea betetzen du '
                . 'edukiaren mugak eta diseinuaren egokitzapena ikusteko.',
            'image_alt' => $title . ' gaiarentzako berrerabilitako QA irudia',
            'image_title' => $number . '. Matrix eszenatokiko aktibo bisuala',
            'image_caption' => 'Fixturea aldatu aurretik esplizituki '
                . 'hautatutako lehendik dagoen AVIF aktiboa.',
            'video_title' => $number . '. Matrix eszenatokiko YouTube bideoa',
            'meta' => 'Matrixeko barne QA fixturea V2 dokumentuak, osagai '
                . 'semantikoak, media eta argitalpen isolatua egiaztatzeko.',
            'excerpt' => $number . '. Matrix QA artikulua: testu zabala, '
                . 'zerrendak, nabarmenduak, zutabeak, irudia eta bideoa. '
                . 'Dummy, noindex eta nofollow bidez beti isolatuta.',
        ];
    }

    private function uuid(int $number, string $role): string
    {
        $hex = substr(hash(
            'sha256',
            sprintf('liquidstack.blog.qa.matrix.v1|%02d|%s', $number, $role)
        ), 0, 32);
        $hex[12] = '4';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
