<?php
/**
 * Directrices de copy para artPricingGlass01:
 * - Texto de fondo: 1 palabra muy grande (ej. "Matrix").
 * - Encabezados secundarios por tarjeta: 1-3 palabras con el nombre del plan.
 * - Precio: formato moneda con periodicidad opcional (ej. "$19.99/mes").
 * - Subtitulo por tarjeta: 2-5 palabras (ej. "Pro Plan").
 * - Items de lista: 3-8 palabras enfocadas en beneficios concretos.
 * - CTA: 2-4 palabras con verbo de accion claro.
 * - Title del CTA: 4-7 palabras que refuercen la accion.
 */
function controller_artPricingGlass01(int $i = 0, array $params = []): string
{
    global $lang;

    $pad     = sprintf('%02d', $i);
    $letters = range('a', 'z');

    $getTemplateLang = static function (string $key) {
        static $templateLang = null;

        if ($templateLang === null) {
            $lang         = $_ENV['LANG_DEFAULT'] ?? 'es';
            $file         = __DIR__ . '/../config/languages/templates/' . $lang . '.json';
            $json         = is_readable($file) ? file_get_contents($file) : '{}';
            $decoded      = json_decode($json);
            $templateLang = is_object($decoded) ? $decoded : new stdClass();
        }

        return $templateLang->{$key} ?? null;
    };

    $templateHeaderPool = [];
    $templatePricePool  = [];
    $templateSubPool    = [];
    $templateCtaPool    = [];
    $templateListPools  = [];

    foreach ($letters as $letter) {
        $headerKey = "artPricingGlass01_{$pad}_headerSecondary_{$letter}";
        $priceKey  = "artPricingGlass01_{$pad}_{$letter}_price";
        $subKey    = "artPricingGlass01_{$pad}_{$letter}_sub";
        $ctaKey    = "artPricingGlass01_{$pad}_{$letter}_cta";

        $defaultHeader = $GLOBALS[$headerKey] ?? $getTemplateLang($headerKey);
        $defaultPrice  = $GLOBALS[$priceKey] ?? $getTemplateLang($priceKey);
        $defaultSub    = $GLOBALS[$subKey] ?? $getTemplateLang($subKey);
        $defaultCta    = $GLOBALS[$ctaKey] ?? $getTemplateLang($ctaKey);

        if (is_object($defaultHeader)) {
            $templateHeaderPool[] = $defaultHeader;
        }

        if (is_object($defaultPrice)) {
            $templatePricePool[] = $defaultPrice;
        }

        if (is_object($defaultSub)) {
            $templateSubPool[] = $defaultSub;
        }

        if (is_object($defaultCta)) {
            $templateCtaPool[] = $defaultCta;
        }

        $listLetters = range('a', 'z');
        foreach ($listLetters as $listLetter) {
            $listKey    = "artPricingGlass01_{$pad}_{$letter}_list_{$listLetter}";
            $defaultObj = $GLOBALS[$listKey] ?? $getTemplateLang($listKey);

            if (!is_object($defaultObj)) {
                break;
            }

            $templateListPools[$letter][] = $defaultObj;
        }
    }

    $itemsCount = isset($params['items']) ? (int) $params['items'] : 0;
    $itemsCount = max(0, min($itemsCount, count($letters)));

    if ($itemsCount === 0) {
        $itemsCount = count($templateHeaderPool);
        $itemsCount = $itemsCount > 0 ? $itemsCount : 3;
    }

    $listItemsParam = $params['list_items'] ?? 0;
    $defaultList    = is_numeric($listItemsParam) ? (int) $listItemsParam : 0;
    unset($params['items'], $params['list_items']);

    $itemLevel = 3;

    $listTpl = <<<'HTML'
        <li data-lang="{X-li-dl}">{X-li-text}</li>
    HTML;

    $itemTpl = <<<'HTML'
        <article class="artPricingGlass01-card" data-glass-card>
            <span class="artPricingGlass01-glass" aria-hidden="true"></span>
            <div class="artPricingGlass01-cardBody">
                {X-header-secondary}
                <p class="artPricingGlass01-price" data-lang="{X-price-dl}">{X-price-text}</p>
                <p class="artPricingGlass01-sub" data-lang="{X-sub-dl}">{X-sub-text}</p>
                <ul role="list" class="artPricingGlass01-list">
                    {X-list-items}
                </ul>
                <a class="artPricingGlass01-cta" data-lang="{X-cta-dl}" href="{X-cta-href}" title="{X-cta-title}">{X-cta-text}</a>
            </div>
        </article>
    HTML;

    $itemsHtml = '';

    for ($j = 0; $j < $itemsCount && $j < count($letters); $j++) {
        $letter     = $letters[$j];
        $headerKey  = "artPricingGlass01_{$pad}_headerSecondary_{$letter}";
        $priceKey   = "artPricingGlass01_{$pad}_{$letter}_price";
        $subKey     = "artPricingGlass01_{$pad}_{$letter}_sub";
        $ctaKey     = "artPricingGlass01_{$pad}_{$letter}_cta";

        $headerObj = $GLOBALS[$headerKey] ?? $getTemplateLang($headerKey);
        if (!is_object($headerObj) && count($templateHeaderPool) > 0) {
            $headerObj = $templateHeaderPool[$j % count($templateHeaderPool)];
        }
        if (!is_object($headerObj)) {
            $headerObj = (object) ['text' => ''];
        }

        $priceObj = $GLOBALS[$priceKey] ?? $getTemplateLang($priceKey);
        if (!is_object($priceObj) && count($templatePricePool) > 0) {
            $priceObj = $templatePricePool[$j % count($templatePricePool)];
        }
        if (!is_object($priceObj)) {
            $priceObj = (object) ['text' => ''];
        }

        $subObj = $GLOBALS[$subKey] ?? $getTemplateLang($subKey);
        if (!is_object($subObj) && count($templateSubPool) > 0) {
            $subObj = $templateSubPool[$j % count($templateSubPool)];
        }
        if (!is_object($subObj)) {
            $subObj = (object) ['text' => ''];
        }

        $ctaObj = $GLOBALS[$ctaKey] ?? $getTemplateLang($ctaKey);
        if (!is_object($ctaObj) && count($templateCtaPool) > 0) {
            $ctaObj = $templateCtaPool[$j % count($templateCtaPool)];
        }
        if (!is_object($ctaObj)) {
            $ctaObj = (object) ['text' => '', 'href' => '', 'title' => ''];
        }

        $ctaHrefValue = (is_object($ctaObj) && isset($ctaObj->href)) ? $ctaObj->href : '#';
        $ctaHref      = resolve_localized_href($ctaHrefValue, ['lang' => $lang]);
        $ctaTitle     = (is_object($ctaObj) && isset($ctaObj->title)) ? $ctaObj->title : '';
        $ctaText      = (is_object($ctaObj) && isset($ctaObj->text)) ? $ctaObj->text : '';

        $listOverride = '{' . $letter . '-list-items}';
        if (isset($params[$listOverride])) {
            $listItemsHtml = (string) $params[$listOverride];
            unset($params[$listOverride]);
        } else {
            $listLetters = range('a', 'z');

            $poolLetter       = isset($templateListPools[$letter]) ? $letter : array_key_first($templateListPools);
            $defaultListPool  = $poolLetter !== null ? $templateListPools[$poolLetter] : [];
            $defaultListCount = count($defaultListPool);
            $listCount        = $defaultList > 0 ? $defaultList : ($defaultListCount > 0 ? $defaultListCount : 2);

            if (is_array($listItemsParam)) {
                if (array_key_exists($letter, $listItemsParam)) {
                    $listCount = (int) $listItemsParam[$letter];
                } elseif (array_key_exists($j, $listItemsParam)) {
                    $listCount = (int) $listItemsParam[$j];
                }
            }

            $listCount     = max(0, min($listCount, count($listLetters)));
            $listItemsHtml = '';

            for ($k = 0; $k < $listCount; $k++) {
                $listLetter = $listLetters[$k];
                $listKey    = "artPricingGlass01_{$pad}_{$letter}_list_{$listLetter}";
                $listObj    = $GLOBALS[$listKey] ?? $getTemplateLang($listKey);

                if (!is_object($listObj)) {
                    $poolLetter = isset($templateListPools[$letter]) && count($templateListPools[$letter]) > 0 ? $letter : (array_key_first($templateListPools) ?? null);
                    if ($poolLetter !== null) {
                        $pool    = $templateListPools[$poolLetter];
                        $listObj = $pool[$k % count($pool)];
                    }
                }

                if (!is_object($listObj)) {
                    $listObj = (object) ['text' => ''];
                }

                $listItemsHtml .= str_replace(
                    ['{X-li-dl}', '{X-li-text}'],
                    [$listKey, $listObj->text ?? ''],
                    $listTpl
                );
            }
        }

        $itemHtml = str_replace('{X', '{' . $letter, $itemTpl);
        $search   = [
            '{' . $letter . '-header-secondary}',
            '{' . $letter . '-price-dl}',
            '{' . $letter . '-price-text}',
            '{' . $letter . '-sub-dl}',
            '{' . $letter . '-sub-text}',
            '{' . $letter . '-list-items}',
            '{' . $letter . '-cta-dl}',
            '{' . $letter . '-cta-href}',
            '{' . $letter . '-cta-title}',
            '{' . $letter . '-cta-text}',
        ];
        $replace  = [
            '<h' . $itemLevel . ' class="artPricingGlass01-title" data-lang="' . $headerKey . '">' . ($headerObj->text ?? '') . '</h' . $itemLevel . '>',
            $priceKey,
            $priceObj->text ?? '',
            $subKey,
            $subObj->text ?? '',
            $listItemsHtml,
            $ctaKey,
            $ctaHref,
            $ctaTitle,
            $ctaText,
        ];

        $itemsHtml .= str_replace($search, $replace, $itemHtml);
    }

    $bgObj  = $GLOBALS["artPricingGlass01_{$pad}_bg_text"] ?? $getTemplateLang("artPricingGlass01_{$pad}_bg_text");
    $bgText = (is_object($bgObj) && isset($bgObj->text)) ? $bgObj->text : '';

    $vars = [
        '{classVar}'         => "artPricingGlass01_{$pad}_classVar",
        '{bg-text-dl}'       => "artPricingGlass01_{$pad}_bg_text",
        '{bg-text}'          => $bgText,
        '{items}'            => $itemsHtml,
        '{glass-strength}'   => '30',
        '{glass-noise}'      => '0.008',
        '{glass-blur}'       => '3',
        '{glass-alpha}'      => '1',
        '{glass-text-scale}' => '1',
        '{glass-chroma}'     => '1.1',
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_artPricingGlass01.html', $vars);
}
?>
