<?php
/**
 * Catálogo segmentado de recursos.
 *
 * Las rutas registradas /showroom y /templates actúan como padres. CORE
 * resuelve de forma segura sus subrutas usando únicamente estas categorías.
 * Los requires se mantienen literales para que update-languages.php pueda
 * recorrer todo el catálogo aunque en runtime solo se renderice una categoría.
 *
 * Contrato literal para update-languages (las categorías se renderizan desde
 * el array y sus claves dinámicas no son inferibles por la expresión regular):
 * data-lang="showroom_catalog_category_heroes_label"
 * data-lang="showroom_catalog_category_heroes_description"
 * data-lang="showroom_catalog_category_particles_label"
 * data-lang="showroom_catalog_category_particles_description"
 * data-lang="showroom_catalog_category_gsap_specials_label"
 * data-lang="showroom_catalog_category_gsap_specials_description"
 * data-lang="showroom_catalog_category_common_label"
 * data-lang="showroom_catalog_category_common_description"
 * data-lang="showroom_catalog_category_cards_grids_label"
 * data-lang="showroom_catalog_category_cards_grids_description"
 * data-lang="showroom_catalog_category_media_label"
 * data-lang="showroom_catalog_category_media_description"
 * data-lang="showroom_catalog_category_forms_interactive_label"
 * data-lang="showroom_catalog_category_forms_interactive_description"
 * data-lang="showroom_catalog_category_modules_sections_label"
 * data-lang="showroom_catalog_category_modules_sections_description"
 */
$showroomCategories = [
    'heroes' => [
        'key' => 'showroom_catalog_category_heroes',
        'es' => 'Héroes',
        'en' => 'Heroes',
        'eu' => 'Heroiak',
        'description' => [
            'es' => 'Headers de apertura y sus composiciones H1.',
            'en' => 'Opening headers and their H1 compositions.',
            'eu' => 'Hasierako headerrak eta haien H1 konposizioak.',
        ],
    ],
    'particles' => [
        'key' => 'showroom_catalog_category_particles',
        'es' => 'Partículas',
        'en' => 'Particles',
        'eu' => 'Partikulak',
        'description' => [
            'es' => 'Fondos de partículas, canvas y WebGL.',
            'en' => 'Particle, canvas and WebGL backgrounds.',
            'eu' => 'Partikula, canvas eta WebGL atzeko planoak.',
        ],
    ],
    'gsap-specials' => [
        'key' => 'showroom_catalog_category_gsap_specials',
        'es' => 'GSAP especiales',
        'en' => 'GSAP specials',
        'eu' => 'GSAP bereziak',
        'description' => [
            'es' => 'Experiencias inmersivas, pin, scroll y WebGL.',
            'en' => 'Immersive pin, scroll and WebGL experiences.',
            'eu' => 'Pin, scroll eta WebGL esperientzia murgiltzaileak.',
        ],
    ],
    'common' => [
        'key' => 'showroom_catalog_category_common',
        'es' => 'Artículos comunes',
        'en' => 'Common articles',
        'eu' => 'Artikulu arruntak',
        'description' => [
            'es' => 'Composiciones directas de imagen, título y texto.',
            'en' => 'Straightforward image, heading and text compositions.',
            'eu' => 'Irudi, izenburu eta testu konposizio zuzenak.',
        ],
    ],
    'cards-grids' => [
        'key' => 'showroom_catalog_category_cards_grids',
        'es' => 'Fichas y rejillas',
        'en' => 'Cards and grids',
        'eu' => 'Fitxak eta saretak',
        'description' => [
            'es' => 'Cards, matrices, listados y bloques repetibles.',
            'en' => 'Cards, matrices, lists and repeatable blocks.',
            'eu' => 'Txartelak, matrizeak, zerrendak eta bloke errepikagarriak.',
        ],
    ],
    'media' => [
        'key' => 'showroom_catalog_category_media',
        'es' => 'Multimedia',
        'en' => 'Media',
        'eu' => 'Multimedia',
        'description' => [
            'es' => 'Vídeo, imagen, sliders y galerías interactivas.',
            'en' => 'Video, image, sliders and interactive galleries.',
            'eu' => 'Bideoa, irudia, sliderrak eta galeria interaktiboak.',
        ],
    ],
    'forms-interactive' => [
        'key' => 'showroom_catalog_category_forms_interactive',
        'es' => 'Formularios e interacción',
        'en' => 'Forms and interaction',
        'eu' => 'Formularioak eta interakzioa',
        'description' => [
            'es' => 'Formularios, acordeones y pestañas.',
            'en' => 'Forms, accordions and tabs.',
            'eu' => 'Formularioak, akordeoiak eta fitxak.',
        ],
    ],
    'modules-sections' => [
        'key' => 'showroom_catalog_category_modules_sections',
        'es' => 'Módulos y secciones',
        'en' => 'Modules and sections',
        'eu' => 'Moduluak eta sekzioak',
        'description' => [
            'es' => 'Átomos reutilizables y composiciones de sección.',
            'en' => 'Reusable atoms and section compositions.',
            'eu' => 'Atomo berrerabilgarriak eta sekzio konposizioak.',
        ],
    ],
];

$showroomLanguage = isset($lang) && is_string($lang) ? $lang : 'es';
$showroomCategory = $rutaConfig['showroom_category'] ?? null;
if (!is_string($showroomCategory) || !isset($showroomCategories[$showroomCategory])) {
    $showroomCategory = null;
}

$showroomBasePath = $rutaConfig['showroom_base_path'] ?? ($url ?? '');
if (!is_string($showroomBasePath) || $showroomBasePath === '') {
    $showroomBasePath = '/' . $showroomLanguage . '/showroom';
}
$showroomBasePath = rtrim($showroomBasePath, '/');

$showroomUi = [
    'es' => [
        'title' => 'Catálogo de recursos LiquidStack',
        'intro' => 'Selecciona una categoría para revisar recursos sin cargar el catálogo completo.',
        'nav' => 'Categorías del showroom',
        'index' => 'Índice',
    ],
    'en' => [
        'title' => 'LiquidStack resource catalogue',
        'intro' => 'Choose a category to review resources without loading the complete catalogue.',
        'nav' => 'Showroom categories',
        'index' => 'Index',
    ],
    'eu' => [
        'title' => 'LiquidStack baliabideen katalogoa',
        'intro' => 'Aukeratu kategoria bat katalogo osoa kargatu gabe baliabideak berrikusteko.',
        'nav' => 'Showroom kategoriak',
        'index' => 'Aurkibidea',
    ],
];
$showroomFallbackCopy = $showroomUi[$showroomLanguage] ?? $showroomUi['es'];

$showroomCatalogText = static function (
    string $key,
    string $fallback
): string {
    $entry = $GLOBALS[$key] ?? null;
    if (is_object($entry) && property_exists($entry, 'text')) {
        return (string) $entry->text;
    }
    if (is_array($entry) && array_key_exists('text', $entry)) {
        return (string) $entry['text'];
    }

    return $fallback;
};

$showroomCopy = [
    'title' => $showroomCatalogText(
        'showroom_catalog_title',
        $showroomFallbackCopy['title']
    ),
    'intro' => $showroomCatalogText(
        'showroom_catalog_intro',
        $showroomFallbackCopy['intro']
    ),
    'nav' => $showroomCatalogText(
        'showroom_catalog_nav',
        $showroomFallbackCopy['nav']
    ),
    'index' => $showroomCatalogText(
        'showroom_catalog_index',
        $showroomFallbackCopy['index']
    ),
];

$showroomCategoryLabel = static function (
    array $category,
    string $language
) use ($showroomCatalogText): string {
    return $showroomCatalogText(
        $category['key'] . '_label',
        (string) ($category[$language] ?? $category['es'])
    );
};
$showroomCategoryDescription = static function (
    array $category,
    string $language
) use ($showroomCatalogText): string {
    return $showroomCatalogText(
        $category['key'] . '_description',
        (string) (
            $category['description'][$language]
                ?? $category['description']['es']
        )
    );
};
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($showroomLanguage, ENT_QUOTES) ?>">

<head>
    <?php include_once __DIR__ . '/../includes/_globalHead.php' ?>
</head>

<body data-showroom-category="<?= htmlspecialchars($showroomCategory ?? 'index', ENT_QUOTES) ?>">
    <?php include_once __DIR__ . '/../includes/_globalBody.php' ?>
    <?php include __DIR__ . '/../includes/_nav.php' ?>

    <div id="smooth-wrapper">
        <div id="smooth-content">
            <main class="showroomCatalog">
                <div class="showroomCatalog-header">
                    <p
                        class="showroomCatalog-title"
                        data-lang="showroom_catalog_title"
                    ><?= htmlspecialchars($showroomCopy['title'], ENT_QUOTES) ?></p>
                    <p data-lang="showroom_catalog_intro"><?= htmlspecialchars($showroomCopy['intro'], ENT_QUOTES) ?></p>
                </div>

                <nav
                    class="showroomCatalog-nav"
                    aria-labelledby="showroom-catalog-nav-label"
                >
                    <span
                        id="showroom-catalog-nav-label"
                        class="showroomCatalog-srOnly"
                        data-lang="showroom_catalog_nav"
                    ><?= htmlspecialchars($showroomCopy['nav'], ENT_QUOTES) ?></span>
                    <ul>
                        <li>
                            <a
                                href="<?= htmlspecialchars($showroomBasePath, ENT_QUOTES) ?>"
                                data-showroom-link="index"
                                <?= $showroomCategory === null ? 'aria-current="page"' : '' ?>
                            >
                                <span data-lang="showroom_catalog_index"><?= htmlspecialchars($showroomCopy['index'], ENT_QUOTES) ?></span>
                            </a>
                        </li>
                        <?php foreach ($showroomCategories as $categorySlug => $categoryMeta): ?>
                            <?php $categoryHref = $showroomBasePath . '/' . $categorySlug; ?>
                            <li>
                                <a
                                    href="<?= htmlspecialchars($categoryHref, ENT_QUOTES) ?>"
                                    <?= $showroomCategory === $categorySlug ? 'aria-current="page"' : '' ?>
                                    data-lang="<?= htmlspecialchars($categoryMeta['key'] . '_label', ENT_QUOTES) ?>"
                                    data-showroom-link="<?= htmlspecialchars($categorySlug, ENT_QUOTES) ?>"
                                >
                                    <?= htmlspecialchars(
                                        $showroomCategoryLabel($categoryMeta, $showroomLanguage),
                                        ENT_QUOTES
                                    ) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>

                <?php if ($showroomCategory === null): ?>
                    <div class="showroomCatalog-index">
                        <?php foreach ($showroomCategories as $categorySlug => $categoryMeta): ?>
                            <article>
                                <h2>
                                    <a
                                        href="<?= htmlspecialchars($showroomBasePath . '/' . $categorySlug, ENT_QUOTES) ?>"
                                        data-lang="<?= htmlspecialchars($categoryMeta['key'] . '_label', ENT_QUOTES) ?>"
                                        data-showroom-link="<?= htmlspecialchars($categorySlug, ENT_QUOTES) ?>"
                                    >
                                        <?= htmlspecialchars(
                                            $showroomCategoryLabel($categoryMeta, $showroomLanguage),
                                            ENT_QUOTES
                                        ) ?>
                                    </a>
                                </h2>
                                <p data-lang="<?= htmlspecialchars($categoryMeta['key'] . '_description', ENT_QUOTES) ?>"><?= htmlspecialchars(
                                    $showroomCategoryDescription(
                                        $categoryMeta,
                                        $showroomLanguage
                                    ),
                                    ENT_QUOTES
                                ) ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php
                switch ($showroomCategory) {
                    case 'heroes':
                        require __DIR__ . '/showroom/_heroes.php';
                        break;
                    case 'particles':
                        require __DIR__ . '/showroom/_particles.php';
                        break;
                    case 'gsap-specials':
                        require __DIR__ . '/showroom/_gsap-specials.php';
                        break;
                    case 'common':
                        require __DIR__ . '/showroom/_common.php';
                        break;
                    case 'cards-grids':
                        require __DIR__ . '/showroom/_cards-grids.php';
                        break;
                    case 'media':
                        require __DIR__ . '/showroom/_media.php';
                        break;
                    case 'forms-interactive':
                        require __DIR__ . '/showroom/_forms-interactive.php';
                        break;
                    case 'modules-sections':
                        require __DIR__ . '/showroom/_modules-sections.php';
                        break;
                }

                // Punto de extensión reservado para BASE y consumidores.
                // CORE nunca distribuye este fichero.
                if (is_file(__DIR__ . '/showroom/_local.php')) {
                    require __DIR__ . '/showroom/_local.php';
                }
                ?>
            </main>

            <?php include __DIR__ . '/../includes/_footer.php' ?>
        </div>
    </div>
</body>

</html>
