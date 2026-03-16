<?php
/**
 * Directrices de copy para art18:
 * - Encabezado principal: 3-6 palabras que introduzcan la tabla de precios.
 * - Encabezados secundarios por tarjeta: 1-3 palabras con el nombre del plan.
 * - Precio: formato moneda con periodicidad opcional (ej. "$19.99/mes").
 * - Items de lista: 3-8 palabras enfocadas en beneficios concretos.
 * - CTA: 2-4 palabras con verbo de accion claro.
 * - Title del CTA: 4-7 palabras que refuercen la accion.
 */
function controller_art18(int $i = 0, array $params = []): string
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
    $templateCtaPool    = [];
    $templateListPools  = [];

    foreach ($letters as $letter) {
        $headerKey = "art18_{$pad}_headerSecondary_{$letter}";
        $priceKey  = "art18_{$pad}_{$letter}_price";
        $ctaKey    = "art18_{$pad}_{$letter}_cta";

        $defaultHeader = $GLOBALS[$headerKey] ?? $getTemplateLang($headerKey);
        $defaultPrice  = $GLOBALS[$priceKey] ?? $getTemplateLang($priceKey);
        $defaultCta    = $GLOBALS[$ctaKey] ?? $getTemplateLang($ctaKey);

        if (is_object($defaultHeader)) {
            $templateHeaderPool[] = $defaultHeader;
        }

        if (is_object($defaultPrice)) {
            $templatePricePool[] = $defaultPrice;
        }

        if (is_object($defaultCta)) {
            $templateCtaPool[] = $defaultCta;
        }

        $listLetters = range('a', 'z');
        foreach ($listLetters as $listLetter) {
            $listKey    = "art18_{$pad}_{$letter}_list_{$listLetter}";
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

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];

    $listTpl = <<<'HTML'
        <li data-lang="{X-li-dl}">{X-li-text}</li>
    HTML;

    $itemTpl = <<<'HTML'
        <div class="card">
            {X-header-secondary}
            <p class="card-price" data-lang="{X-price-dl}">{X-price-text}</p>
            <ul role="list" class="card-bullets flow">
                {X-list-items}
            </ul>
            <a class="cta" data-lang="{X-cta-dl}" href="{X-cta-href}" title="{X-cta-title}">{X-cta-text}</a>
        </div>
    HTML;

    $itemsHtml = '';

    for ($j = 0; $j < $itemsCount && $j < count($letters); $j++) {
        $letter     = $letters[$j];
        $headerKey  = "art18_{$pad}_headerSecondary_{$letter}";
        $priceKey   = "art18_{$pad}_{$letter}_price";
        $ctaKey     = "art18_{$pad}_{$letter}_cta";

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
                $listKey    = "art18_{$pad}_{$letter}_list_{$listLetter}";
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
            '{' . $letter . '-list-items}',
            '{' . $letter . '-cta-dl}',
            '{' . $letter . '-cta-href}',
            '{' . $letter . '-cta-title}',
            '{' . $letter . '-cta-text}',
        ];
        $replace = [
            '<h' . $itemLevel . ' class="card-heading" data-lang="' . $headerKey . '">' . ($headerObj->text ?? '') . '</h' . $itemLevel . '>',
            $priceKey,
            $priceObj->text ?? '',
            $listItemsHtml,
            $ctaKey,
            $ctaHref,
            $ctaTitle,
            $ctaText,
        ];

        $itemsHtml .= str_replace($search, $replace, $itemHtml);
    }

    $headerObj  = $GLOBALS["art18_{$pad}_headerPrimary"] ?? $getTemplateLang("art18_{$pad}_headerPrimary");
    $headerText = (is_object($headerObj) && isset($headerObj->text)) ? $headerObj->text : '';

    $vars = [
        '{classVar}'       => "art18_{$pad}_classVar",
        '{header-primary}' => '<h' . $baseLevel . ' class="heading" data-lang="art18_' . $pad . '_headerPrimary">' . $headerText . '</h' . $baseLevel . '>',
        '{items}'          => $itemsHtml,
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_art18.html', $vars);
}
?>
