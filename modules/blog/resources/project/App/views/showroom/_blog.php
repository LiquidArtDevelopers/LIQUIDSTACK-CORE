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
    ],
    'en' => [
        'grid' => 'sectionBlogGrid01 · Recent posts grid',
        'list' => 'sectionBlogList01 · Editorial post list',
        'featured' => 'sectionBlogFeatured01 · Featured and secondary posts',
        'slider' => 'sectionBlogSlider01 · Recent posts carousel',
    ],
    'eu' => [
        'grid' => 'sectionBlogGrid01 · Azken sarreren sareta',
        'list' => 'sectionBlogList01 · Sarreren zerrenda editoriala',
        'featured' => 'sectionBlogFeatured01 · Sarrera nabarmena eta bigarrenak',
        'slider' => 'sectionBlogSlider01 · Azken sarreren karrusela',
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

unset(
    $blogHeadings,
    $blogShowroomCopy,
    $blogShowroomHeadings,
    $blogShowroomItems,
    $blogShowroomLabels,
    $blogShowroomLanguage
);
