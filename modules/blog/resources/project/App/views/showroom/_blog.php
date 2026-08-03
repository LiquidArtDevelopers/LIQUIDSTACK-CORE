<?php

/**
 * Recursos públicos de Blog. Estos fixtures Matrix son exclusivos del
 * showroom: no consultan ni se insertan en la base de datos.
 */
$blogShowroomLanguage = in_array(
    (string) ($showroomLanguage ?? 'es'),
    ['es', 'en', 'eu'],
    true
) ? (string) $showroomLanguage : 'es';
$blogShowroomCopy = [
    'es' => [
        [
            'url' => '/es/noticias/despertar-matrix',
            'h1' => 'El despertar de Neo ante la realidad de Matrix',
            'excerpt' => 'Una lectura sobre la primera película, la elección entre ambas píldoras y el instante en que comprender el sistema deja de ser una intuición para convertirse en una responsabilidad.',
            'published_at' => '2026-01-10T09:00:00+00:00',
        ],
        [
            'url' => '/es/noticias/matrix-reloaded',
            'h1' => 'Matrix Reloaded y la arquitectura de una elección',
            'excerpt' => 'El regreso a Zion amplía el conflicto y plantea si cada decisión es realmente libre o forma parte de un mecanismo diseñado para conducir incluso los actos de rebeldía.',
            'published_at' => '2026-02-14T09:00:00+00:00',
        ],
        [
            'url' => '/es/noticias/matrix-revolutions',
            'h1' => 'La tregua imposible de Matrix Revolutions',
            'excerpt' => 'La tercera entrega reúne máquinas, humanos y programas en un desenlace donde vencer deja de significar destruir al adversario y pasa a exigir una negociación con consecuencias duraderas.',
            'published_at' => '2026-03-18T09:00:00+00:00',
        ],
        [
            'url' => '/es/noticias/matrix-resurrections',
            'h1' => 'Matrix Resurrections y el valor de recordar',
            'excerpt' => 'La última película revisa el mito desde la memoria, el vínculo entre Neo y Trinity y la capacidad de recuperar una identidad que el propio sistema intenta convertir en producto.',
            'published_at' => '2026-04-22T09:00:00+00:00',
        ],
    ],
    'en' => [
        [
            'url' => '/en/news/awakening-matrix',
            'h1' => 'Neo awakens to the reality behind the Matrix',
            'excerpt' => 'A reading of the first film, the choice between both pills and the moment when understanding the system stops being an intuition and becomes a personal responsibility.',
            'published_at' => '2026-01-10T09:00:00+00:00',
        ],
        [
            'url' => '/en/news/matrix-reloaded',
            'h1' => 'Matrix Reloaded and the architecture of choice',
            'excerpt' => 'The return to Zion expands the conflict and asks whether every decision is truly free or merely part of a mechanism designed to direct even acts of rebellion.',
            'published_at' => '2026-02-14T09:00:00+00:00',
        ],
        [
            'url' => '/en/news/matrix-revolutions',
            'h1' => 'The unlikely truce in Matrix Revolutions',
            'excerpt' => 'The third film gathers machines, humans and programs in an ending where victory no longer means destroying an opponent, but negotiating an agreement with lasting consequences.',
            'published_at' => '2026-03-18T09:00:00+00:00',
        ],
        [
            'url' => '/en/news/matrix-resurrections',
            'h1' => 'Matrix Resurrections and the value of remembering',
            'excerpt' => 'The latest film revisits the myth through memory, the bond between Neo and Trinity, and the power to recover an identity the system has turned into a product.',
            'published_at' => '2026-04-22T09:00:00+00:00',
        ],
    ],
    'eu' => [
        [
            'url' => '/eu/albisteak/matrix-esnatzea',
            'h1' => 'Neoren esnatzea Matrixen errealitatearen aurrean',
            'excerpt' => 'Lehen filmari, bi pilulen arteko hautuari eta sistema ulertzea intuizio hutsa izatetik erantzukizun pertsonal bihurtzen den uneari buruzko irakurketa bat.',
            'published_at' => '2026-01-10T09:00:00+00:00',
        ],
        [
            'url' => '/eu/albisteak/matrix-reloaded',
            'h1' => 'Matrix Reloaded eta hautu baten arkitektura',
            'excerpt' => 'Zionera itzultzeak gatazka zabaltzen du eta erabaki bakoitza benetan askea den edo matxinada ekintzak ere gidatzeko diseinatutako mekanismo baten parte den galdetzen du.',
            'published_at' => '2026-02-14T09:00:00+00:00',
        ],
        [
            'url' => '/eu/albisteak/matrix-revolutions',
            'h1' => 'Matrix Revolutions filmeko ezinezko su-etena',
            'excerpt' => 'Hirugarren filmak makinak, gizakiak eta programak biltzen ditu; garaipena arerioa suntsitzea baino, ondorio iraunkorrak dituen akordio bat negoziatzea da.',
            'published_at' => '2026-03-18T09:00:00+00:00',
        ],
        [
            'url' => '/eu/albisteak/matrix-resurrections',
            'h1' => 'Matrix Resurrections eta gogoratzearen balioa',
            'excerpt' => 'Azken filmak memoria, Neo eta Trinityren arteko lotura eta sistemak produktu bihurtu nahi duen nortasuna berreskuratzeko gaitasuna erabiliz berrikusten du mitoa.',
            'published_at' => '2026-04-22T09:00:00+00:00',
        ],
    ],
];
$blogShowroomHeadings = [
    'es' => [
        'grid' => 'sectionBlogGrid01 · Rejilla de entradas recientes',
        'list' => 'sectionBlogList01 · Listado editorial de entradas',
        'featured' => 'sectionBlogFeatured01 · Entrada destacada y secundarias',
        'slider' => 'sectionBlogSlider01 · Carrusel de entradas recientes',
        'related' => 'sectionBlogRelated01 · Entradas relacionadas',
        'archive' => 'moduleBlogArchive01 · Archivo de noticias',
    ],
    'en' => [
        'grid' => 'sectionBlogGrid01 · Recent posts grid',
        'list' => 'sectionBlogList01 · Editorial post list',
        'featured' => 'sectionBlogFeatured01 · Featured and secondary posts',
        'slider' => 'sectionBlogSlider01 · Recent posts carousel',
        'related' => 'sectionBlogRelated01 · Related posts',
        'archive' => 'moduleBlogArchive01 · News archive',
    ],
    'eu' => [
        'grid' => 'sectionBlogGrid01 · Azken sarreren sareta',
        'list' => 'sectionBlogList01 · Sarreren zerrenda editoriala',
        'featured' => 'sectionBlogFeatured01 · Sarrera nabarmena eta bigarrenak',
        'slider' => 'sectionBlogSlider01 · Azken sarreren karrusela',
        'related' => 'sectionBlogRelated01 · Lotutako sarrerak',
        'archive' => 'moduleBlogArchive01 · Albisteen artxiboa',
    ],
];
$blogShowroomLabels = [
    'es' => [
        'search' => 'Buscar noticias Matrix',
        'placeholder' => 'Neo, Trinity, Zion…',
        'categories' => 'Filtrar las noticias de prueba',
        'mode' => 'Coincidencia de categorías',
        'any' => 'Cualquiera',
        'all' => 'Todas',
        'submit' => 'Aplicar filtros',
        'clear' => 'Limpiar filtros',
        'status' => 'Resultados de prueba actualizados',
    ],
    'en' => [
        'search' => 'Search Matrix news',
        'placeholder' => 'Neo, Trinity, Zion…',
        'categories' => 'Filter the sample news',
        'mode' => 'Category matching',
        'any' => 'Any',
        'all' => 'All',
        'submit' => 'Apply filters',
        'clear' => 'Clear filters',
        'status' => 'Sample results updated',
    ],
    'eu' => [
        'search' => 'Bilatu Matrixeko albisteak',
        'placeholder' => 'Neo, Trinity, Zion…',
        'categories' => 'Iragazi probako albisteak',
        'mode' => 'Kategorien bat-etortzea',
        'any' => 'Edozein',
        'all' => 'Guztiak',
        'submit' => 'Aplikatu iragazkiak',
        'clear' => 'Garbitu iragazkiak',
        'status' => 'Probako emaitzak eguneratu dira',
    ],
];
$blogShowroomItems = $blogShowroomCopy[$blogShowroomLanguage];
$blogHeadings = $blogShowroomHeadings[$blogShowroomLanguage];
$blogShowroomArchive = [
    'es' => [
        ['url' => '/es/noticias?year=2026&month=4', 'label' => 'Abril de 2026', 'count' => 1, 'active' => true],
        ['url' => '/es/noticias?year=2026&month=3', 'label' => 'Marzo de 2026', 'count' => 1],
        ['url' => '/es/noticias?year=2026&month=2', 'label' => 'Febrero de 2026', 'count' => 1],
        ['url' => '/es/noticias?year=2026&month=1', 'label' => 'Enero de 2026', 'count' => 1],
    ],
    'en' => [
        ['url' => '/en/news?year=2026&month=4', 'label' => 'April 2026', 'count' => 1, 'active' => true],
        ['url' => '/en/news?year=2026&month=3', 'label' => 'March 2026', 'count' => 1],
        ['url' => '/en/news?year=2026&month=2', 'label' => 'February 2026', 'count' => 1],
        ['url' => '/en/news?year=2026&month=1', 'label' => 'January 2026', 'count' => 1],
    ],
    'eu' => [
        ['url' => '/eu/albisteak?year=2026&month=4', 'label' => '2026ko apirila', 'count' => 1, 'active' => true],
        ['url' => '/eu/albisteak?year=2026&month=3', 'label' => '2026ko martxoa', 'count' => 1],
        ['url' => '/eu/albisteak?year=2026&month=2', 'label' => '2026ko otsaila', 'count' => 1],
        ['url' => '/eu/albisteak?year=2026&month=1', 'label' => '2026ko urtarrila', 'count' => 1],
    ],
];
$blogShowroomArchiveCountLabels = [
    'es' => ['singular' => 'entrada', 'plural' => 'entradas'],
    'en' => ['singular' => 'entry', 'plural' => 'entries'],
    'eu' => ['singular' => 'sarrera', 'plural' => 'sarrera'],
];
$blogArticleFixtures = [
    'basic' => [
        'template' => 'article-basic-01',
        'h1' => 'artBlogArticle01 · Composición editorial básica',
        'excerpt' => 'Neo aprende que la lectura de Matrix exige contexto, ritmo y una jerarquía clara. Esta variante concentra el texto en una columna cómoda y reserva aire suficiente para cada bloque.',
        'published_label' => 'Publicado',
        'published_text' => '10/01/2026',
        'published_at' => '2026-01-10T09:00:00+00:00',
        'body_html' => '<div class="blogDocument blogDocument--basic">'
            . '<p class="blogDocument__paragraph">Morpheus describe el sistema sin precipitar la respuesta: cada párrafo sostiene una idea completa, conecta con el siguiente y mantiene una medida de lectura estable incluso cuando la pantalla se estrecha.</p>'
            . '<h2>Elegir después de comprender el código</h2>'
            . '<p class="blogDocument__paragraph">La píldora roja no funciona como un atajo. Es una decisión informada que obliga a Neo a revisar lo aprendido, distinguir la señal del ruido y asumir las consecuencias de mirar más allá de la simulación.</p>'
            . '<ul class="blogDocument__list"><li class="blogDocument__listItem">Jerarquía coherente entre el título del recurso y sus bloques.</li><li class="blogDocument__listItem">Columna legible en móvil, tableta y escritorio.</li><li class="blogDocument__listItem">Contenido estructurado sin HTML libre ni editor inline.</li></ul>'
            . '</div>',
        'back_label' => 'Volver a las noticias Matrix',
        'back_href' => '/es/noticias',
    ],
    'cover' => [
        'template' => 'article-cover-01',
        'h1' => 'artBlogArticle01 · Composición con portada',
        'excerpt' => 'Trinity ocupa la portada y abre una narración más visual. El resto del artículo vuelve después a una anchura editorial controlada para que la imagen no comprometa la lectura.',
        'published_label' => 'Publicado',
        'published_text' => '22/04/2026',
        'published_at' => '2026-04-22T09:00:00+00:00',
        'header_media_html' => '<figure class="blogDocument__image blogDocument__image--cover"><picture class="blogDocument__picture"><img class="blogDocument__imageElement" src="/assets/img/dummy/dummy_1800.avif" width="1800" height="1200" loading="eager" fetchpriority="high" alt="Trinity observa el código de Matrix" title="Portada de artBlogArticle01"></picture><figcaption class="blogDocument__imageCaption">Una portada amplia introduce el relato sin duplicar la imagen social del artículo.</figcaption></figure>',
        'body_html' => '<div class="blogDocument blogDocument--cover">'
            . '<h2>La memoria también construye la realidad</h2>'
            . '<p class="blogDocument__paragraph">La composición permite que la primera imagen respire a todo el ancho disponible y devuelve los párrafos posteriores a una medida contenida. Así conserva impacto visual sin convertir cada bloque en una excepción de CSS.</p>'
            . '<aside class="blogDocument__callout"><p class="blogDocument__calloutContent">Matrix puede alterar el escenario, pero la estructura semántica y el orden de lectura permanecen estables.</p></aside>'
            . '</div>',
        'back_label' => 'Volver a las noticias Matrix',
        'back_href' => '/es/noticias',
    ],
];
$blogShowroomEscape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$renderBlogArticlePreview = static function (
    int $index,
    array $article
) use ($blogShowroomEscape): string {
    $intro = controller('moduleH1Type04', $index, [
        '{classVar}' => 'artBlogArticle01-heading',
        '{eyebrow}' => '<p class="artBlogArticle01-date"><span>'
            . $blogShowroomEscape((string) $article['published_label'])
            . '</span> <time datetime="'
            . $blogShowroomEscape((string) $article['published_at']) . '">'
            . $blogShowroomEscape((string) $article['published_text'])
            . '</time></p>',
        '{header-primary}' => '<h3 class="moduleH1Type04-title">'
            . $blogShowroomEscape((string) $article['h1']) . '</h3>',
        '{intro}' => '<p class="moduleH1Type04-text">'
            . $blogShowroomEscape((string) $article['excerpt']) . '</p>',
        '{a-button-primary}' => '',
    ]);
    $back = controller('moduleButtonType04', $index, [
        '{classVar}' => 'artBlogArticle01-backAction',
        '{cta-link-dl}' => '',
        '{cta-link-href}' => $blogShowroomEscape(
            (string) $article['back_href']
        ),
        '{cta-link-title}' => $blogShowroomEscape(
            (string) $article['back_label']
        ),
        '{cta-link-span-dl}' => '',
        '{cta-link-span-text}' => $blogShowroomEscape(
            (string) $article['back_label']
        ),
        '{cta-link-attributes}' => ' rel="up"',
    ]);

    return controller('artBlogArticle01', $index, [
        'article_data' => $article,
        'header_level' => 3,
        '{article-intro}' => $intro,
        '{article-back}' => $back,
    ]);
};

?>
<section>
<?php
echo $renderBlogArticlePreview(0, $blogArticleFixtures['basic']);
echo $renderBlogArticlePreview(1, $blogArticleFixtures['cover']);
?>
</section>
<section>
<?php
echo controller('moduleBlogFilters01', 0, [
    'action' => match ($blogShowroomLanguage) {
        'en' => '/en/news',
        'eu' => '/eu/albisteak',
        default => '/es/noticias',
    },
    'filters' => [
        ['slug' => 'analysis', 'name' => 'Matrix', 'count' => 4],
        ['slug' => 'characters', 'name' => 'Zion', 'count' => 3],
        ['slug' => 'guides', 'name' => 'Oracle', 'count' => 2],
    ],
    'labels' => $blogShowroomLabels[$blogShowroomLanguage],
]);
echo controller('moduleBlogArchive01', 0, [
    'periods_data' => $blogShowroomArchive[$blogShowroomLanguage],
    'header_text' => $blogHeadings['archive'],
    'count_label_singular' => $blogShowroomArchiveCountLabels[$blogShowroomLanguage]['singular'],
    'count_label_plural' => $blogShowroomArchiveCountLabels[$blogShowroomLanguage]['plural'],
]);
?>
</section>
<?php
echo controller('sectionBlogGrid01', 0, [
    'items_data' => $blogShowroomItems,
    'items' => 4,
    'header_text' => $blogHeadings['grid'],
]);

echo controller('sectionBlogList01', 0, [
    'items_data' => $blogShowroomItems,
    'header_text' => $blogHeadings['list'],
]);

echo controller('sectionBlogFeatured01', 0, [
    'items_data' => $blogShowroomItems,
    'header_text' => $blogHeadings['featured'],
]);

echo controller('sectionBlogSlider01', 0, [
    'items_data' => $blogShowroomItems,
    'header_text' => $blogHeadings['slider'],
]);

echo controller('sectionBlogRelated01', 0, [
    'items_data' => array_slice($blogShowroomItems, 0, 3),
    'header_text' => $blogHeadings['related'],
]);

unset(
    $blogHeadings,
    $blogShowroomCopy,
    $blogShowroomHeadings,
    $blogShowroomItems,
    $blogShowroomLabels,
    $blogShowroomLanguage,
    $blogArticleFixtures,
    $blogShowroomArchive,
    $blogShowroomArchiveCountLabels,
    $blogShowroomEscape,
    $renderBlogArticlePreview
);
