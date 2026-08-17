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
        [
            'url' => '/es/noticias/agente-smith-copias',
            'h1' => 'El agente Smith y la lógica de una copia',
            'excerpt' => 'La multiplicación de Smith convierte una amenaza individual en un problema de escala. Cada copia conserva el mismo propósito, pero obliga a Neo a reconsiderar qué distingue una identidad de un patrón que puede repetirse sin límite.',
            'published_at' => '2026-05-09T09:00:00+00:00',
        ],
        [
            'url' => '/es/noticias/oraculo-futuros-posibles',
            'h1' => 'El Oráculo frente a los futuros posibles',
            'excerpt' => 'Sus predicciones no eliminan la elección: presentan las consecuencias que cada personaje todavía debe comprender. El encuentro plantea cómo una información parcial puede orientar sin sustituir la responsabilidad de quien finalmente actúa.',
            'published_at' => '2026-06-14T09:00:00+00:00',
        ],
        [
            'url' => '/es/noticias/zion-resistencia-compartida',
            'h1' => 'Zion y la arquitectura de una resistencia compartida',
            'excerpt' => 'La ciudad subterránea funciona como refugio, memoria y proyecto colectivo. Su defensa depende tanto de las máquinas disponibles como de la coordinación entre personas que interpretan de manera distinta el riesgo y la esperanza.',
            'published_at' => '2026-07-03T09:00:00+00:00',
        ],
        [
            'url' => '/es/noticias/animatrix-historias-ocultas',
            'h1' => 'Animatrix amplía las historias ocultas del sistema',
            'excerpt' => 'Los relatos breves exploran épocas, ciudades y personajes que las películas apenas sugieren. Juntos convierten el universo de Matrix en un archivo fragmentado donde cada perspectiva añade contexto sin cerrar todas las preguntas.',
            'published_at' => '2026-07-18T09:00:00+00:00',
        ],
        [
            'url' => '/es/noticias/tripulacion-nabucodonosor-confianza',
            'h1' => 'La tripulación del Nabucodonosor aprende a confiar',
            'excerpt' => 'Morpheus, Trinity, Tank y el resto del equipo comparten una misión, aunque no siempre las mismas certezas. La convivencia revela que la confianza se construye con decisiones pequeñas antes de sostener los grandes sacrificios.',
            'published_at' => '2026-08-01T09:00:00+00:00',
        ],
        [
            'url' => '/es/noticias/libre-albedrio-control-matrix',
            'h1' => 'Libre albedrío y control dentro de Matrix',
            'excerpt' => 'Arquitectos, programas y humanos describen la libertad desde posiciones incompatibles. La saga mantiene abierta la tensión entre un sistema capaz de anticipar conductas y una elección cuyo valor nace precisamente de asumir sus efectos.',
            'published_at' => '2026-08-12T09:00:00+00:00',
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
        [
            'url' => '/en/news/agent-smith-endless-copies',
            'h1' => 'Agent Smith and the logic of endless copies',
            'excerpt' => 'Smith multiplies until an individual threat becomes a problem of scale. Every copy keeps the same purpose, forcing Neo to reconsider what separates an identity from a pattern that can repeat without limit.',
            'published_at' => '2026-05-09T09:00:00+00:00',
        ],
        [
            'url' => '/en/news/oracle-possible-futures',
            'h1' => 'The Oracle confronts a maze of possible futures',
            'excerpt' => 'Her predictions do not remove choice; they reveal consequences each character must still understand. The encounter shows how partial information can provide direction without replacing the responsibility of the person who ultimately acts.',
            'published_at' => '2026-06-14T09:00:00+00:00',
        ],
        [
            'url' => '/en/news/zion-collective-resistance',
            'h1' => 'Zion and the architecture of collective resistance',
            'excerpt' => 'The underground city is simultaneously a refuge, a memory and a shared project. Its defence depends on available machines and on coordination between people who interpret risk and hope in very different ways.',
            'published_at' => '2026-07-03T09:00:00+00:00',
        ],
        [
            'url' => '/en/news/animatrix-hidden-stories',
            'h1' => 'The Animatrix reveals stories hidden inside the system',
            'excerpt' => 'These short stories explore eras, cities and characters that the films only suggest. Together they turn the Matrix universe into a fragmented archive where every perspective adds context without resolving every question.',
            'published_at' => '2026-07-18T09:00:00+00:00',
        ],
        [
            'url' => '/en/news/nebuchadnezzar-crew-trust',
            'h1' => 'The Nebuchadnezzar crew learns how to trust',
            'excerpt' => 'Morpheus, Trinity, Tank and the rest of the crew share a mission, although they do not always share certainty. Life aboard the ship shows how small decisions build the trust required for larger sacrifices.',
            'published_at' => '2026-08-01T09:00:00+00:00',
        ],
        [
            'url' => '/en/news/free-will-control-matrix',
            'h1' => 'Free will and control inside the Matrix',
            'excerpt' => 'Architects, programs and humans describe freedom from incompatible positions. The saga keeps alive the tension between a system capable of anticipating behaviour and a choice whose value comes from accepting its consequences.',
            'published_at' => '2026-08-12T09:00:00+00:00',
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
        [
            'url' => '/eu/albisteak/smith-agentea-kopia-amaigabeak',
            'h1' => 'Smith agentea eta kopia amaigabeen logika',
            'excerpt' => 'Smith ugaritu ahala, banakako mehatxua eskala arazo bihurtzen da. Kopia bakoitzak helburu bera gordetzen du, eta Neok nortasun bat mugarik gabe errepika daitekeen eredu batetik zerk bereizten duen berriro pentsatu behar du.',
            'published_at' => '2026-05-09T09:00:00+00:00',
        ],
        [
            'url' => '/eu/albisteak/orakulua-etorkizun-posibleak',
            'h1' => 'Orakulua etorkizun posibleen labirintoaren aurrean',
            'excerpt' => 'Haren iragarpenek ez dute hautua ezabatzen; pertsonaia bakoitzak oraindik ulertu behar dituen ondorioak erakusten dituzte. Topaketak azaltzen du informazio partzialak norabidea eman dezakeela, azken erabakiaren erantzukizuna ordeztu gabe.',
            'published_at' => '2026-06-14T09:00:00+00:00',
        ],
        [
            'url' => '/eu/albisteak/zion-erresistentzia-partekatua',
            'h1' => 'Zion eta erresistentzia partekatuaren arkitektura',
            'excerpt' => 'Lurpeko hiria aterpea, memoria eta proiektu partekatua da aldi berean. Haren defentsa eskuragarri dauden makinen eta arriskua zein itxaropena modu desberdinean ulertzen duten pertsonen koordinazioaren mende dago.',
            'published_at' => '2026-07-03T09:00:00+00:00',
        ],
        [
            'url' => '/eu/albisteak/animatrix-istorio-ezkutuak',
            'h1' => 'Animatrixek sistemaren barruko istorio ezkutuak erakusten ditu',
            'excerpt' => 'Kontakizun laburrek filmek iradoki baino egiten ez dituzten garaiak, hiriak eta pertsonaiak aztertzen dituzte. Guztiek batera Matrixen unibertsoa artxibo zatitu bihurtzen dute, ikuspegi bakoitzak testuingurua gehitzen duelako.',
            'published_at' => '2026-07-18T09:00:00+00:00',
        ],
        [
            'url' => '/eu/albisteak/nabukodonosor-tripulazioa-konfiantza',
            'h1' => 'Nabukodonosorreko tripulazioak konfiantza izaten ikasten du',
            'excerpt' => 'Morpheusek, Trinityk, Tankek eta gainerako taldeak misio bera partekatzen dute, baina ez beti ziurtasun berak. Ontziko bizitzak erakusten du erabaki txikiek sakrifizio handiak sostengatzeko konfiantza eraikitzen dutela.',
            'published_at' => '2026-08-01T09:00:00+00:00',
        ],
        [
            'url' => '/eu/albisteak/borondate-askea-kontrola-matrix',
            'h1' => 'Borondate askea eta kontrola Matrixen barruan',
            'excerpt' => 'Arkitektoek, programek eta gizakiek askatasuna jarrera bateraezinetatik deskribatzen dute. Sagak bizirik mantentzen du jokabideak aurreikusteko gai den sistemaren eta ondorioak onartzean balioa hartzen duen hautuaren arteko tentsioa.',
            'published_at' => '2026-08-12T09:00:00+00:00',
        ],
    ],
];
$blogShowroomHeadings = [
    'es' => [
        'catalog' => 'sectionBlogCatalog01 · Catálogo interactivo de noticias',
        'grid' => 'sectionBlogGrid01 · Rejilla de entradas recientes',
        'grid02' => 'moduleBlogGrid02 + moduleBlogPagination01 · Resultado paginado',
        'list' => 'sectionBlogList01 · Listado editorial de entradas',
        'featured' => 'sectionBlogFeatured01 · Entrada destacada y secundarias',
        'slider' => 'sectionBlogSlider01 · Carrusel de entradas recientes',
        'slider02' => 'sectionBlogSlider02 · Carrusel GSAP',
        'stack01' => 'sectionBlogStack01 · Pila editorial progresiva',
        'related' => 'sectionBlogRelated01 · Entradas relacionadas',
        'archive' => 'moduleBlogArchive01 · Archivo de noticias',
    ],
    'en' => [
        'catalog' => 'sectionBlogCatalog01 · Interactive news catalogue',
        'grid' => 'sectionBlogGrid01 · Recent posts grid',
        'grid02' => 'moduleBlogGrid02 + moduleBlogPagination01 · Paginated result',
        'list' => 'sectionBlogList01 · Editorial post list',
        'featured' => 'sectionBlogFeatured01 · Featured and secondary posts',
        'slider' => 'sectionBlogSlider01 · Recent posts carousel',
        'slider02' => 'sectionBlogSlider02 · GSAP carousel',
        'stack01' => 'sectionBlogStack01 · Progressive editorial stack',
        'related' => 'sectionBlogRelated01 · Related posts',
        'archive' => 'moduleBlogArchive01 · News archive',
    ],
    'eu' => [
        'catalog' => 'sectionBlogCatalog01 · Albisteen katalogo interaktiboa',
        'grid' => 'sectionBlogGrid01 · Azken sarreren sareta',
        'grid02' => 'moduleBlogGrid02 + moduleBlogPagination01 · Orrikatutako emaitza',
        'list' => 'sectionBlogList01 · Sarreren zerrenda editoriala',
        'featured' => 'sectionBlogFeatured01 · Sarrera nabarmena eta bigarrenak',
        'slider' => 'sectionBlogSlider01 · Azken sarreren karrusela',
        'slider02' => 'sectionBlogSlider02 · GSAP karrusela',
        'stack01' => 'sectionBlogStack01 · Eduki pila progresiboa',
        'related' => 'sectionBlogRelated01 · Lotutako sarrerak',
        'archive' => 'moduleBlogArchive01 · Albisteen artxiboa',
    ],
];
$blogShowroomLabels = [
    'es' => [
        'search' => 'Buscar noticias Matrix',
        'placeholder' => 'Neo, Trinity, Zion…',
        'minimum' => 'Escribe al menos 2 caracteres.',
        'categories' => 'Filtrar las noticias de prueba',
        'mode' => 'Coincidencia de categorías',
        'any' => 'Cualquiera',
        'all' => 'Todas',
        'submit' => 'Aplicar filtros',
        'clear' => 'Limpiar filtros',
        'status' => 'Resultados de prueba actualizados',
        'error' => 'No se pudieron actualizar los resultados. Comprueba la conexión e inténtalo de nuevo.',
        'order' => 'Ordenar noticias',
        'newest' => 'Más recientes',
        'oldest' => 'Más antiguas',
        'updated' => 'Actualizadas recientemente',
        'search_submit' => 'Buscar',
        'search_clear' => 'Limpiar búsqueda',
        'category_submit' => 'Aplicar categorías',
        'category_reset' => 'Quitar categorías',
        'empty' => 'No hay categorías disponibles.',
        'previous_page' => 'Página anterior',
        'next_page' => 'Página siguiente',
        'page' => 'Página',
    ],
    'en' => [
        'search' => 'Search Matrix news',
        'placeholder' => 'Neo, Trinity, Zion…',
        'minimum' => 'Enter at least 2 characters.',
        'categories' => 'Filter the sample news',
        'mode' => 'Category matching',
        'any' => 'Any',
        'all' => 'All',
        'submit' => 'Apply filters',
        'clear' => 'Clear filters',
        'status' => 'Sample results updated',
        'error' => 'The results could not be updated. Check your connection and try again.',
        'order' => 'Sort news',
        'newest' => 'Newest first',
        'oldest' => 'Oldest first',
        'updated' => 'Recently updated',
        'search_submit' => 'Search',
        'search_clear' => 'Clear search',
        'category_submit' => 'Apply categories',
        'category_reset' => 'Clear categories',
        'empty' => 'There are no categories available.',
        'previous_page' => 'Previous page',
        'next_page' => 'Next page',
        'page' => 'Page',
    ],
    'eu' => [
        'search' => 'Bilatu Matrixeko albisteak',
        'placeholder' => 'Neo, Trinity, Zion…',
        'minimum' => 'Idatzi gutxienez 2 karaktere.',
        'categories' => 'Iragazi probako albisteak',
        'mode' => 'Kategorien bat-etortzea',
        'any' => 'Edozein',
        'all' => 'Guztiak',
        'submit' => 'Aplikatu iragazkiak',
        'clear' => 'Garbitu iragazkiak',
        'status' => 'Probako emaitzak eguneratu dira',
        'error' => 'Ezin izan dira emaitzak eguneratu. Egiaztatu konexioa eta saiatu berriro.',
        'order' => 'Ordenatu albisteak',
        'newest' => 'Berrienak lehenengo',
        'oldest' => 'Zaharrenak lehenengo',
        'updated' => 'Duela gutxi eguneratuak',
        'search_submit' => 'Bilatu',
        'search_clear' => 'Garbitu bilaketa',
        'category_submit' => 'Aplikatu kategoriak',
        'category_reset' => 'Kendu kategoriak',
        'empty' => 'Ez dago kategoriarik erabilgarri.',
        'previous_page' => 'Aurreko orria',
        'next_page' => 'Hurrengo orria',
        'page' => 'Orria',
    ],
];
$blogShowroomCollectionLabels = [
    'es' => [
        'cta_label' => 'Leer artículo',
        'previous_label' => 'Ver entradas anteriores',
        'next_label' => 'Ver entradas siguientes',
        'pause_label' => 'Pausar reproducción automática',
        'resume_label' => 'Reanudar reproducción automática',
        'load_more_label' => 'Cargar más entradas',
        'loading_label' => 'Cargando entradas…',
        'loading_message' => 'Cargando entradas…',
        'error_label' => 'No se pudieron cargar las entradas.',
        'error_message' => 'No se pudieron cargar las entradas.',
        'retry_label' => 'Reintentar',
        'end_label' => 'No hay más entradas.',
        'end_message' => 'No hay más entradas.',
        'empty_message' => 'No hay entradas disponibles.',
    ],
    'en' => [
        'cta_label' => 'Read article',
        'previous_label' => 'View previous posts',
        'next_label' => 'View next posts',
        'pause_label' => 'Pause automatic playback',
        'resume_label' => 'Resume automatic playback',
        'load_more_label' => 'Load more posts',
        'loading_label' => 'Loading posts…',
        'loading_message' => 'Loading posts…',
        'error_label' => 'The posts could not be loaded.',
        'error_message' => 'The posts could not be loaded.',
        'retry_label' => 'Try again',
        'end_label' => 'There are no more posts.',
        'end_message' => 'There are no more posts.',
        'empty_message' => 'There are no posts available.',
    ],
    'eu' => [
        'cta_label' => 'Irakurri artikulua',
        'previous_label' => 'Ikusi aurreko sarrerak',
        'next_label' => 'Ikusi hurrengo sarrerak',
        'pause_label' => 'Gelditu erreprodukzio automatikoa',
        'resume_label' => 'Jarraitu erreprodukzio automatikoa',
        'load_more_label' => 'Kargatu sarrera gehiago',
        'loading_label' => 'Sarrerak kargatzen…',
        'loading_message' => 'Sarrerak kargatzen…',
        'error_label' => 'Ezin izan dira sarrerak kargatu.',
        'error_message' => 'Ezin izan dira sarrerak kargatu.',
        'retry_label' => 'Saiatu berriro',
        'end_label' => 'Ez dago sarrera gehiagorik.',
        'end_message' => 'Ez dago sarrera gehiagorik.',
        'empty_message' => 'Ez dago sarrerarik erabilgarri.',
    ],
];
$blogShowroomItems = $blogShowroomCopy[$blogShowroomLanguage];
$blogCollectionLabels = $blogShowroomCollectionLabels[
    $blogShowroomLanguage
];
$blogCategoryNames = [
    'es' => [
        'analysis' => 'Análisis',
        'characters' => 'Personajes',
        'zion' => 'Zion',
    ],
    'en' => [
        'analysis' => 'Analysis',
        'characters' => 'Characters',
        'zion' => 'Zion',
    ],
    'eu' => [
        'analysis' => 'Analisia',
        'characters' => 'Pertsonaiak',
        'zion' => 'Zion',
    ],
][$blogShowroomLanguage];
$blogCategoryGroups = [
    ['analysis', 'characters'],
    ['analysis', 'zion'],
    ['characters', 'zion'],
    ['analysis'],
];
$blogShowroomMedia = [
    [
        'media' => [
            'src' => '/assets/img/dummy/dummy01.avif',
            'width' => 2560,
            'height' => 1600,
        ],
        'thumbnail' => [
            'src' => '/assets/img/dummy/responsive/dummy01-900.avif',
            'srcset' => '/assets/img/dummy/responsive/dummy01-480.avif 480w, '
                . '/assets/img/dummy/responsive/dummy01-900.avif 900w, '
                . '/assets/img/dummy/responsive/dummy01-1800.avif 1800w, '
                . '/assets/img/dummy/responsive/dummy01-2560.avif 2560w',
            'width' => 900,
            'height' => 563,
        ],
    ],
    [
        'media' => [
            'src' => '/assets/img/dummy/dummy02.avif',
            'width' => 2560,
            'height' => 1722,
        ],
        'thumbnail' => [
            // Imagick conserva la proporción y materializa 899 px para el
            // target canónico 900 de este original concreto.
            'src' => '/assets/img/dummy/responsive/dummy02-899.avif',
            'srcset' => '/assets/img/dummy/responsive/dummy02-480.avif 480w, '
                . '/assets/img/dummy/responsive/dummy02-899.avif 899w, '
                . '/assets/img/dummy/responsive/dummy02-1800.avif 1800w, '
                . '/assets/img/dummy/responsive/dummy02-2560.avif 2560w',
            'width' => 899,
            'height' => 605,
        ],
    ],
    [
        'media' => [
            'src' => '/assets/img/dummy/dummy03.avif',
            'width' => 2560,
            'height' => 1696,
        ],
        'thumbnail' => [
            'src' => '/assets/img/dummy/responsive/dummy03-900.avif',
            'srcset' => '/assets/img/dummy/responsive/dummy03-480.avif 480w, '
                . '/assets/img/dummy/responsive/dummy03-900.avif 900w, '
                . '/assets/img/dummy/responsive/dummy03-1800.avif 1800w, '
                . '/assets/img/dummy/responsive/dummy03-2560.avif 2560w',
            'width' => 900,
            'height' => 596,
        ],
    ],
    [
        'media' => [
            'src' => '/assets/img/dummy/dummy04.avif',
            'width' => 2560,
            'height' => 1440,
        ],
        'thumbnail' => [
            'src' => '/assets/img/dummy/responsive/dummy04-900.avif',
            'srcset' => '/assets/img/dummy/responsive/dummy04-480.avif 480w, '
                . '/assets/img/dummy/responsive/dummy04-900.avif 900w, '
                . '/assets/img/dummy/responsive/dummy04-1800.avif 1800w, '
                . '/assets/img/dummy/responsive/dummy04-2560.avif 2560w',
            'width' => 900,
            'height' => 506,
        ],
    ],
];
$blogShowroomCategorizedItems = $blogShowroomItems;
foreach ($blogShowroomCategorizedItems as $position => $item) {
    $item['categories'] = array_map(
        static fn (string $slug): array => [
            'slug' => $slug,
            'name' => $blogCategoryNames[$slug],
        ],
        $blogCategoryGroups[$position % count($blogCategoryGroups)]
    );
    $itemMedia = $blogShowroomMedia[
        $position % count($blogShowroomMedia)
    ];
    $item['media'] = array_merge(
        $itemMedia['media'],
        ['alt' => (string) $item['h1']]
    );
    $item['thumbnail'] = array_merge(
        $itemMedia['thumbnail'],
        ['alt' => (string) $item['h1']]
    );
    $blogShowroomCategorizedItems[$position] = $item;
}
$blogShowroomBasePath = match ($blogShowroomLanguage) {
    'en' => '/en/showroom/blog',
    'eu' => '/eu/showroom/blog',
    default => '/es/showroom/blog',
};
$blogShowroomRawQuery = $_GET['q'] ?? '';
$blogShowroomQuery = is_string($blogShowroomRawQuery)
    ? trim($blogShowroomRawQuery)
    : '';
$blogShowroomNormalizedQuery = preg_replace(
    '/\s+/u',
    ' ',
    $blogShowroomQuery
);
$blogShowroomQuery = is_string($blogShowroomNormalizedQuery)
    ? $blogShowroomNormalizedQuery
    : '';
$blogShowroomQuery = function_exists('mb_substr')
    ? mb_substr($blogShowroomQuery, 0, 120, 'UTF-8')
    : substr($blogShowroomQuery, 0, 120);
$blogShowroomRawOrder = $_GET['order'] ?? 'newest';
$blogShowroomOrder = is_string($blogShowroomRawOrder)
    && in_array($blogShowroomRawOrder, ['newest', 'oldest', 'updated'], true)
        ? $blogShowroomRawOrder
        : 'newest';
$blogShowroomRawCategories = $_GET['category'] ?? [];
if (is_string($blogShowroomRawCategories)) {
    $blogShowroomRawCategories = [$blogShowroomRawCategories];
}
$blogShowroomSelectedCategories = [];
foreach ((array) $blogShowroomRawCategories as $categorySlug) {
    if (
        !is_string($categorySlug)
        || !isset($blogCategoryNames[$categorySlug])
        || preg_match(
            '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
            $categorySlug
        ) !== 1
    ) {
        continue;
    }
    $blogShowroomSelectedCategories[$categorySlug] = $categorySlug;
    if (count($blogShowroomSelectedCategories) >= 10) {
        break;
    }
}
$blogShowroomSelectedCategories = array_values(
    $blogShowroomSelectedCategories
);
$blogShowroomRawCategoryMode = $_GET['category_mode'] ?? 'any';
$blogShowroomCategoryMode = $blogShowroomRawCategoryMode === 'all'
    ? 'all'
    : 'any';
$blogShowroomFilteredItems = array_values(array_filter(
    $blogShowroomCategorizedItems,
    static function (array $item) use (
        $blogShowroomQuery,
        $blogShowroomSelectedCategories,
        $blogShowroomCategoryMode
    ): bool {
        if ($blogShowroomQuery !== '') {
            $haystack = (string) ($item['h1'] ?? '') . ' '
                . (string) ($item['excerpt'] ?? '');
            $matchesQuery = function_exists('mb_stripos')
                ? mb_stripos(
                    $haystack,
                    $blogShowroomQuery,
                    0,
                    'UTF-8'
                ) !== false
                : stripos($haystack, $blogShowroomQuery) !== false;
            if (!$matchesQuery) {
                return false;
            }
        }

        if ($blogShowroomSelectedCategories === []) {
            return true;
        }
        $itemCategories = array_values(array_filter(array_map(
            static fn ($category): string => is_array($category)
                ? (string) ($category['slug'] ?? '')
                : '',
            (array) ($item['categories'] ?? [])
        )));
        if ($blogShowroomCategoryMode === 'all') {
            return array_diff(
                $blogShowroomSelectedCategories,
                $itemCategories
            ) === [];
        }

        return array_intersect(
            $blogShowroomSelectedCategories,
            $itemCategories
        ) !== [];
    }
));
usort(
    $blogShowroomFilteredItems,
    static function (array $left, array $right) use (
        $blogShowroomOrder
    ): int {
        $dateField = $blogShowroomOrder === 'updated'
            ? 'updated_at'
            : 'published_at';
        $leftDate = (string) (
            $left[$dateField] ?? $left['published_at'] ?? ''
        );
        $rightDate = (string) (
            $right[$dateField] ?? $right['published_at'] ?? ''
        );
        $dateComparison = strcmp($leftDate, $rightDate);
        if ($dateComparison !== 0) {
            return $blogShowroomOrder === 'oldest'
                ? $dateComparison
                : -$dateComparison;
        }

        return strcmp(
            (string) ($left['url'] ?? ''),
            (string) ($right['url'] ?? '')
        );
    }
);
$blogShowroomRawPage = $_GET['blog_demo_page'] ?? '1';
$blogShowroomPage = is_string($blogShowroomRawPage)
    && preg_match('/\A[1-9][0-9]*\z/', $blogShowroomRawPage) === 1
        ? (int) $blogShowroomRawPage
        : 1;
$blogShowroomPageSize = 4;
$blogShowroomPageCount = max(1, (int) ceil(
    count($blogShowroomFilteredItems) / $blogShowroomPageSize
));
$blogShowroomPage = min($blogShowroomPage, max(1, $blogShowroomPageCount));
$blogShowroomPaginatedItems = array_slice(
    $blogShowroomFilteredItems,
    ($blogShowroomPage - 1) * $blogShowroomPageSize,
    $blogShowroomPageSize
);
$blogShowroomBaseQuery = [];
if ($blogShowroomQuery !== '') {
    $blogShowroomBaseQuery['q'] = $blogShowroomQuery;
}
if ($blogShowroomOrder !== 'newest') {
    $blogShowroomBaseQuery['order'] = $blogShowroomOrder;
}
if ($blogShowroomSelectedCategories !== []) {
    $blogShowroomBaseQuery['category'] = $blogShowroomSelectedCategories;
    $blogShowroomBaseQuery['category_mode'] = $blogShowroomCategoryMode;
}
$blogShowroomPageUrl = static function (int $page) use (
    $blogShowroomBasePath,
    $blogShowroomBaseQuery
): string {
    $query = $blogShowroomBaseQuery;
    if ($page > 1) {
        $query['blog_demo_page'] = $page;
    }
    $queryString = $query === []
        ? ''
        : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    return $blogShowroomBasePath . $queryString
        . '#showroom-blog-paginated';
};
$blogShowroomPaginationPages = [];
for ($pageNumber = 1; $pageNumber <= $blogShowroomPageCount; $pageNumber++) {
    $blogShowroomPaginationPages[] = [
        'page' => $pageNumber,
        'url' => $blogShowroomPageUrl($pageNumber),
        'current' => $pageNumber === $blogShowroomPage,
    ];
}
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
// artBlogArticle01: template article-basic-01 | article-cover-01.
// article_data admite portada opcional, cuerpo estructurado y CTA de retorno;
// ambas composiciones se prueban sin duplicar la muestra visual del recurso.
echo $renderBlogArticlePreview(0, $blogArticleFixtures['basic']);
?>
</section>
<?php
$blogShowroomFilterCatalog = [];
foreach ($blogCategoryNames as $categorySlug => $categoryName) {
    $categoryCount = count(array_filter(
        $blogShowroomCategorizedItems,
        static fn (array $item): bool => in_array(
            $categorySlug,
            array_map(
                static fn (array $category): string => (string) (
                    $category['slug'] ?? ''
                ),
                (array) ($item['categories'] ?? [])
            ),
            true
        )
    ));
    $blogShowroomFilterCatalog[] = [
        'slug' => $categorySlug,
        'name' => $categoryName,
        'count' => $categoryCount,
    ];
}
$blogShowroomFilterLabels = $blogShowroomLabels[$blogShowroomLanguage];

// moduleBlogSearch01:
// - query admite 0-120 caracteres; con 1 carácter conserva SSR y no solicita.
// - order: newest | oldest | updated.
// - selected_categories y category_mode preservan el filtro hermano.
$blogShowroomSearch = controller('moduleBlogSearch01', 0, [
    'id_prefix' => 'blog-index-search',
    'action' => $blogShowroomBasePath,
    'target_id' => 'blog-results',
    'query' => $blogShowroomQuery,
    'order' => $blogShowroomOrder,
    'selected_categories' => $blogShowroomSelectedCategories,
    'category_mode' => $blogShowroomCategoryMode,
    'labels' => [
        'search' => $blogShowroomFilterLabels['search'],
        'placeholder' => $blogShowroomFilterLabels['placeholder'],
        'minimum' => $blogShowroomFilterLabels['minimum'],
        'order' => $blogShowroomFilterLabels['order'],
        'newest' => $blogShowroomFilterLabels['newest'],
        'oldest' => $blogShowroomFilterLabels['oldest'],
        'updated' => $blogShowroomFilterLabels['updated'],
        'submit' => $blogShowroomFilterLabels['search_submit'],
        'clear' => $blogShowroomFilterLabels['search_clear'],
        'status' => $blogShowroomFilterLabels['status'],
        'error' => $blogShowroomFilterLabels['error'],
    ],
]);

// moduleBlogCategoryBar01:
// - filters admite un catálogo acotado; selected_categories puede estar vacío.
// - category_mode: any | all. Funciona también sin JavaScript.
// - query y order preservan el estado del buscador hermano.
$blogShowroomCategories = controller('moduleBlogCategoryBar01', 0, [
    'id_prefix' => 'blog-index-categories',
    'action' => $blogShowroomBasePath,
    'target_id' => 'blog-results',
    'filters' => $blogShowroomFilterCatalog,
    'query' => $blogShowroomQuery,
    'order' => $blogShowroomOrder,
    'selected_categories' => $blogShowroomSelectedCategories,
    'category_mode' => $blogShowroomCategoryMode,
    'labels' => [
        'categories' => $blogShowroomFilterLabels['categories'],
        'mode' => $blogShowroomFilterLabels['mode'],
        'any' => $blogShowroomFilterLabels['any'],
        'all' => $blogShowroomFilterLabels['all'],
        'submit' => $blogShowroomFilterLabels['category_submit'],
        'reset' => $blogShowroomFilterLabels['category_reset'],
        'empty' => $blogShowroomFilterLabels['empty'],
        'status' => $blogShowroomFilterLabels['status'],
        'error' => $blogShowroomFilterLabels['error'],
    ],
]);

// moduleBlogGrid02:
// - layout: regular | bento; items: 0-50; items_data puede ser 0/1/N.
// - next_url + load_mode manual|near-end habilitan lotes progresivos.
// - Aquí se muestra regular con paginación SSR; bento queda documentado
//   como configuración y no repite visualmente el mismo recurso. Al delegar
//   en moduleBlogPagination01 se usa pagination_mode=external.
// - La raiz siempre es neutra; las tarjetas conservan article/H3.
$blogShowroomResults = controller('moduleBlogGrid02', 2, array_merge(
    $blogCollectionLabels,
    [
        'id_prefix' => 'showroom-blog-paginated',
        'items_data' => $blogShowroomPaginatedItems,
        'items' => count($blogShowroomPaginatedItems),
        'layout' => 'regular',
        'pagination_mode' => 'external',
        'header_level' => 2,
    ]
));

// moduleBlogPagination01:
// - pages_data marca la actual y admite enlaces anterior/siguiente.
// - Las URLs conservan búsqueda, orden y categorías activas.
$blogShowroomPagination = controller('moduleBlogPagination01', 0, [
    'id_prefix' => 'showroom-blog-pagination',
    'pages_data' => $blogShowroomPaginationPages,
    'previous_url' => $blogShowroomPage > 1
        ? $blogShowroomPageUrl($blogShowroomPage - 1)
        : '',
    'next_url' => $blogShowroomPage < $blogShowroomPageCount
        ? $blogShowroomPageUrl($blogShowroomPage + 1)
        : '',
    'labels' => [
        'previous' => $blogShowroomFilterLabels['previous_page'],
        'next' => $blogShowroomFilterLabels['next_page'],
        'page' => $blogShowroomFilterLabels['page'],
    ],
]);
// moduleBlogArchive01:
// - periods_data agrupa año/mes, count y estado active.
// - Es un índice temporal complementario, no una rejilla de resultados.
$blogShowroomArchiveModule = controller('moduleBlogArchive01', 0, [
    'periods_data' => $blogShowroomArchive[$blogShowroomLanguage],
    'header_level' => 3,
    'header_text' => $blogHeadings['archive'],
    'count_label_singular' => $blogShowroomArchiveCountLabels[$blogShowroomLanguage]['singular'],
    'count_label_plural' => $blogShowroomArchiveCountLabels[$blogShowroomLanguage]['plural'],
]);

// moduleBlogResults01:
// - Es la unica region reactiva del documento: indice 0 e id blog-results.
// - Sus hijos son modulos de raiz neutra; no admite section/nav anidados.
// - {pagination-slot} y {archive-slot} son opcionales; usar '' los omite sin
//   dejar wrappers vacios. El compositor no consulta datos ni anade JS.
$blogShowroomResultsRegion = controller('moduleBlogResults01', 0, [
    '{results-slot}' => $blogShowroomResults,
    '{pagination-slot}' => $blogShowroomPagination,
    '{archive-slot}' => $blogShowroomArchiveModule,
]);

// sectionBlogCatalog01:
// - Es singleton porque contiene la unica region reactiva blog-results.
// - header_level admite 2-5; los hijos se configuran desde sus snipers.
// - header_text es obligatorio; header_lang puede enlazar la clave editorial.
// - {search-slot} y {categories-slot} son opcionales; {results-slot} es
//   obligatorio. Todos se insertan como hermanos directos, sin wrappers.
echo controller('sectionBlogCatalog01', 0, [
    'header_level' => 2,
    'header_text' => $blogHeadings['catalog'],
    '{search-slot}' => $blogShowroomSearch,
    '{categories-slot}' => $blogShowroomCategories,
    '{results-slot}' => $blogShowroomResultsRegion,
]);

// sectionBlogGrid01: items_data admite 0-4 entradas; items limita las visibles.
echo controller('sectionBlogGrid01', 0, [
    'items_data' => $blogShowroomItems,
    'items' => 4,
    'header_text' => $blogHeadings['grid'],
]);

// sectionBlogList01: items_data acepta 0-50 entradas y centra la columna.
echo controller('sectionBlogList01', 0, [
    'items_data' => $blogShowroomItems,
    'header_text' => $blogHeadings['list'],
]);

// sectionBlogFeatured01: items 0-12; el primero es destacado y el resto,
// secundarios. Los estados 0/1 siguen siendo semánticamente válidos.
echo controller('sectionBlogFeatured01', 0, [
    'items_data' => $blogShowroomItems,
    'header_text' => $blogHeadings['featured'],
]);

// sectionBlogSlider01: items 0-20; GSAP Draggable + Inertia, autoplay,
// teclado, reduced-motion, varias instancias y miniatura responsive opcional.
echo controller('sectionBlogSlider01', 0, [
    'items_data' => $blogShowroomCategorizedItems,
    'header_text' => $blogHeadings['slider'],
]);

// sectionBlogSlider02:
// - items 0-50; los estados 0/1/N se cubren por test sin repetir la demo.
// - autoplay true|false; autoplay_delay 2-60 s.
// - El carril siempre es infinito cuando JavaScript puede mejorarlo.
// - transition_duration 0.1-5 s; load_mode manual|near-end y next_url.
// - Soporta multiinstancia, drag, Inertia, rueda, teclado y cleanup/HMR.
// - cta_label localiza el acceso explícito a cada artículo.
echo controller('sectionBlogSlider02', 0, array_merge(
    $blogCollectionLabels,
    [
        'items_data' => $blogShowroomCategorizedItems,
        'items' => 10,
        'autoplay' => true,
        'header_text' => $blogHeadings['slider02'],
    ]
));

// sectionBlogStack01: recomendado para 3-8 entradas; items acepta 0-50.
// Un next_url pendiente mantiene el fallback vertical y evita activar el pin.
echo controller('sectionBlogStack01', 0, array_merge(
    $blogCollectionLabels,
    [
        'items_data' => array_slice($blogShowroomCategorizedItems, 0, 8),
        'items' => 8,
        'header_text' => $blogHeadings['stack01'],
    ]
));

// sectionBlogRelated01: selección breve de 0-3 entradas relacionadas.
echo controller('sectionBlogRelated01', 0, [
    'items_data' => array_slice($blogShowroomItems, 0, 3),
    'header_text' => $blogHeadings['related'],
]);

unset(
    $blogHeadings,
    $blogShowroomCopy,
    $blogShowroomHeadings,
    $blogShowroomItems,
    $blogShowroomCategorizedItems,
    $blogShowroomLabels,
    $blogShowroomFilterCatalog,
    $blogShowroomFilterLabels,
    $blogShowroomCollectionLabels,
    $blogCollectionLabels,
    $blogCategoryNames,
    $blogCategoryGroups,
    $blogShowroomMedia,
    $blogShowroomRawPage,
    $blogShowroomRawQuery,
    $blogShowroomQuery,
    $blogShowroomNormalizedQuery,
    $blogShowroomRawOrder,
    $blogShowroomOrder,
    $blogShowroomRawCategories,
    $blogShowroomSelectedCategories,
    $blogShowroomRawCategoryMode,
    $blogShowroomCategoryMode,
    $blogShowroomFilteredItems,
    $blogShowroomPage,
    $blogShowroomPageSize,
    $blogShowroomPageCount,
    $blogShowroomPaginatedItems,
    $blogShowroomBasePath,
    $blogShowroomBaseQuery,
    $blogShowroomPageUrl,
    $blogShowroomPaginationPages,
    $blogShowroomSearch,
    $blogShowroomCategories,
    $blogShowroomResults,
    $blogShowroomPagination,
    $blogShowroomArchiveModule,
    $blogShowroomResultsRegion,
    $pageNumber,
    $blogShowroomLanguage,
    $blogArticleFixtures,
    $blogShowroomArchive,
    $blogShowroomArchiveCountLabels,
    $blogShowroomEscape,
    $renderBlogArticlePreview,
    $position,
    $item,
    $categorySlug,
    $categoryName,
    $categoryCount,
    $itemMedia
);
