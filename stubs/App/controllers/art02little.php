<?php
/**
 * Directrices de copy para art02little:
 * - Encabezado principal: 45-70 caracteres que presenten el bloque.
 * - Intro: 12-28 palabras que contextualicen las tarjetas.
 * - Párrafos generales: 30-55 palabras cada uno; se omiten cuando están vacíos.
 * - Encabezados de tarjeta: 4-9 palabras orientadas a un beneficio.
 * - Contenido de tarjeta: se inyecta con {a-content}, {b-content} o {c-content}.
 * - La variante cambia la presentación; cada índice define sus medios *_img.
 * - La raíz expone art02little--items-N para adaptar la rejilla a 1-3 tarjetas.
 */
function controller_art02little(int $i = 0, array $params = []): string
{
    $pad        = sprintf('%02d', $i);
    $letters    = range('a', 'c');
    $itemsCount = isset($params['items']) ? (int) $params['items'] : 2;
    $itemsCount = max(1, min($itemsCount, count($letters)));
    unset($params['items']);

    $variant = strtolower((string) ($params['variant'] ?? 'image'));
    $variant = in_array($variant, ['image', 'icon'], true) ? $variant : 'image';
    unset($params['variant']);

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];

    // Semillas para que update-languages.php conserve el tercer ítem aunque
    // una instancia concreta del showroom se renderice con una o dos tarjetas.
    $seedHeaderC = $GLOBALS["art02little_{$pad}_headerSecondary_c"] ?? null;
    $seedImageC  = $GLOBALS["art02little_{$pad}_c_img"] ?? null;
    $seedHeaderCText = is_object($seedHeaderC) ? ($seedHeaderC->text ?? '') : '';
    $seedImageCSrc   = is_object($seedImageC) ? ($seedImageC->src ?? '') : '';
    $seedImageCAlt   = is_object($seedImageC) ? ($seedImageC->alt ?? '') : '';
    $seedImageCTitle = is_object($seedImageC) ? ($seedImageC->title ?? '') : '';

    $currentLang = (string) ($GLOBALS['lang'] ?? $_ENV['LANG_DEFAULT'] ?? 'es');
    $currentLang = preg_match('/^[A-Za-z0-9_-]+$/', $currentLang) === 1
        ? $currentLang
        : 'es';

    $getTemplateLang = static function (string $languageKey) use ($currentLang) {
        static $templateLang = null;

        if ($templateLang === null) {
            $file         = __DIR__ . '/../config/languages/templates/' . $currentLang . '.json';
            $json         = is_readable($file) ? file_get_contents($file) : '{}';
            $decoded      = json_decode($json);
            $templateLang = is_object($decoded) ? $decoded : new stdClass();
        }

        $templateKey = preg_replace(
            '/^art02little_\d{2}_/',
            'art02little_00_',
            $languageKey
        );

        return $GLOBALS[$languageKey]
            ?? $templateLang->{$languageKey}
            ?? ($templateKey !== null ? ($templateLang->{$templateKey} ?? null) : null);
    };

    $itemTpl = <<<'HTML'
        <div class="art02little-card">
            {X-header-secondary}
            {X-media}
            {X-content}
            {X-button-primary}
        </div>
    HTML;

    $itemsHtml = '';
    $escapeAttr = static fn ($value): string => htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter    = $letters[$j];
        $headerKey = "art02little_{$pad}_headerSecondary_{$letter}";
        $imageKey  = "art02little_{$pad}_{$letter}_img";
        $headerObj = $getTemplateLang($headerKey);
        $imageObj  = $getTemplateLang($imageKey);

        $headerText = is_object($headerObj) ? ($headerObj->text ?? '') : '';
        $imageSource = is_object($imageObj) ? ($imageObj->src ?? '') : '';
        $imageAlt    = is_object($imageObj) ? ($imageObj->alt ?? '') : '';
        $imageTitle  = is_object($imageObj) ? ($imageObj->title ?? '') : '';

        $rootUrl = rtrim((string) ($_ENV['RAIZ'] ?? ''), '/');
        $imageUrl = $imageSource !== ''
            ? ($rootUrl !== '' ? $rootUrl . '/' : '/') . ltrim($imageSource, '/')
            : '';

        $mediaHtml = '';
        if ($imageUrl !== '') {
            $imageWidth  = $variant === 'icon' ? 512 : 1000;
            $imageHeight = $variant === 'icon' ? 512 : 700;

            $mediaHtml = '<img class="art02little-cardMedia" data-lang="'
                . $escapeAttr($imageKey)
                . '" src="' . $escapeAttr($imageUrl)
                . '" width="' . $imageWidth . '" height="' . $imageHeight
                . '" alt="' . $escapeAttr($imageAlt)
                . '" title="' . $escapeAttr($imageTitle)
                . '" loading="lazy" decoding="async">';
        }

        $contentKey  = '{' . $letter . '-content}';
        $content     = trim((string) ($params[$contentKey] ?? ''));
        $contentHtml = $content !== ''
            ? '<div class="art02little-cardContent">' . $content . '</div>'
            : '';
        unset($params[$contentKey]);

        $buttonKey  = '{' . $letter . '-button-primary}';
        $buttonHtml = (string) ($params[$buttonKey] ?? '');
        unset($params[$buttonKey]);

        $itemsHtml .= str_replace(
            [
                '{X-header-secondary}',
                '{X-media}',
                '{X-content}',
                '{X-button-primary}',
            ],
            [
                '<h' . $itemLevel . ' class="art02little-cardTitle" data-lang="'
                    . $headerKey . '">' . $headerText . '</h' . $itemLevel . '>',
                $mediaHtml,
                $contentHtml,
                $buttonHtml,
            ],
            $itemTpl
        );
    }

    $headerKey = "art02little_{$pad}_headerPrimary";
    $introKey  = "art02little_{$pad}_intro_p";
    $p1Key     = "art02little_{$pad}_p1";
    $p2Key     = "art02little_{$pad}_p2";

    $headerObj = $getTemplateLang($headerKey);
    $introObj  = $getTemplateLang($introKey);
    $p1Obj     = $getTemplateLang($p1Key);
    $p2Obj     = $getTemplateLang($p2Key);

    $headerText = is_object($headerObj) ? ($headerObj->text ?? '') : '';
    $introText  = is_object($introObj) ? ($introObj->text ?? '') : '';
    $p1Text     = is_object($p1Obj) ? ($p1Obj->text ?? '') : '';
    $p2Text     = is_object($p2Obj) ? ($p2Obj->text ?? '') : '';

    $introHtml = $introText !== ''
        ? '<p class="art02little-intro" data-lang="' . $introKey . '">' . $introText . '</p>'
        : '';

    $copyHtml = '';
    foreach ([[$p1Key, $p1Text], [$p2Key, $p2Text]] as [$key, $text]) {
        if ($text === '') {
            continue;
        }

        $copyHtml .= '<p data-lang="' . $key . '">' . $text . '</p>';
    }

    if ($copyHtml !== '') {
        $copyHtml = '<div class="art02little-copy">' . $copyHtml . '</div>';
    }

    $vars = [
        '{classVar}'       => "art02little_{$pad}_classVar",
        '{variant-class}'  => 'art02little--' . $variant,
        '{items-class}'    => 'art02little--items-' . $itemsCount,
        '{header-primary}' => '<h' . $baseLevel . ' data-lang="' . $headerKey
            . '">' . $headerText . '</h' . $baseLevel . '>',
        '{intro}'          => $introHtml,
        '{copy}'           => $copyHtml,
        '{items}'          => $itemsHtml,
    ];

    return render(
        'App/templates/_art02little.html',
        array_replace($vars, $params)
    );
}
?>
