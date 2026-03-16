<?php
/**
 * Directrices de copy para sectionParallax01:
 * - Eyebrow: 1-3 palabras en mayusculas suaves.
 * - Titular: 3-6 palabras con tono accionable.
 * - Texto: 12-20 palabras con enfoque en beneficio.
 * - Lista: 2-4 items de 2-4 palabras.
 * - CTA: 2-4 palabras; title 4-7 palabras.
 */
function controller_sectionParallax01(int $i = 0, array $params = []): string
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

    $templateEyebrowPool = [];
    $templateTitlePool   = [];
    $templateTextPool    = [];
    $templateCtaPool     = [];
    $templateListPools   = [];

    foreach ($letters as $letter) {
        $eyebrowKey = "sectionParallax01_{$pad}_{$letter}_eyebrow";
        $titleKey   = "sectionParallax01_{$pad}_{$letter}_title";
        $textKey    = "sectionParallax01_{$pad}_{$letter}_text";
        $ctaKey     = "sectionParallax01_{$pad}_{$letter}_cta";

        $eyebrowObj = $GLOBALS[$eyebrowKey] ?? $getTemplateLang($eyebrowKey);
        $titleObj   = $GLOBALS[$titleKey] ?? $getTemplateLang($titleKey);
        $textObj    = $GLOBALS[$textKey] ?? $getTemplateLang($textKey);
        $ctaObj     = $GLOBALS[$ctaKey] ?? $getTemplateLang($ctaKey);

        if (is_object($eyebrowObj)) {
            $templateEyebrowPool[] = $eyebrowObj;
        }

        if (is_object($titleObj)) {
            $templateTitlePool[] = $titleObj;
        }

        if (is_object($textObj)) {
            $templateTextPool[] = $textObj;
        }

        if (is_object($ctaObj)) {
            $templateCtaPool[] = $ctaObj;
        }

        $listLetters = range('a', 'z');
        foreach ($listLetters as $listLetter) {
            $listKey    = "sectionParallax01_{$pad}_{$letter}_list_{$listLetter}";
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
        $itemsCount = count($templateTitlePool);
        $itemsCount = $itemsCount > 0 ? $itemsCount : 3;
    }

    $listItemsParam = $params['list_items'] ?? 0;
    $defaultList    = is_numeric($listItemsParam) ? (int) $listItemsParam : 0;
    unset($params['items'], $params['list_items']);

    $listTpl = <<<'HTML'
        <li data-lang="{X-li-dl}">{X-li-text}</li>
    HTML;

    $itemTpl = <<<'HTML'
        <article class="sectionParallax01-card" data-parallax-item>
            <div class="sectionParallax01-card-head">
                <span class="sectionParallax01-card-eyebrow" data-lang="{X-eyebrow-dl}">{X-eyebrow-text}</span>
                <h3 class="sectionParallax01-card-title" data-lang="{X-title-dl}">{X-title-text}</h3>
            </div>
            <p class="sectionParallax01-card-text" data-lang="{X-text-dl}">{X-text-text}</p>
            <ul class="sectionParallax01-card-list" role="list">
                {X-list-items}
            </ul>
            <a class="sectionParallax01-card-cta" data-lang="{X-cta-dl}" href="{X-cta-href}" title="{X-cta-title}">{X-cta-text}</a>
        </article>
    HTML;

    $itemsHtml = '';

    for ($j = 0; $j < $itemsCount && $j < count($letters); $j++) {
        $letter     = $letters[$j];
        $eyebrowKey = "sectionParallax01_{$pad}_{$letter}_eyebrow";
        $titleKey   = "sectionParallax01_{$pad}_{$letter}_title";
        $textKey    = "sectionParallax01_{$pad}_{$letter}_text";
        $ctaKey     = "sectionParallax01_{$pad}_{$letter}_cta";

        $eyebrowObj = $GLOBALS[$eyebrowKey] ?? $getTemplateLang($eyebrowKey);
        if (!is_object($eyebrowObj) && count($templateEyebrowPool) > 0) {
            $eyebrowObj = $templateEyebrowPool[$j % count($templateEyebrowPool)];
        }
        if (!is_object($eyebrowObj)) {
            $eyebrowObj = (object) ['text' => ''];
        }

        $titleObj = $GLOBALS[$titleKey] ?? $getTemplateLang($titleKey);
        if (!is_object($titleObj) && count($templateTitlePool) > 0) {
            $titleObj = $templateTitlePool[$j % count($templateTitlePool)];
        }
        if (!is_object($titleObj)) {
            $titleObj = (object) ['text' => ''];
        }

        $textObj = $GLOBALS[$textKey] ?? $getTemplateLang($textKey);
        if (!is_object($textObj) && count($templateTextPool) > 0) {
            $textObj = $templateTextPool[$j % count($templateTextPool)];
        }
        if (!is_object($textObj)) {
            $textObj = (object) ['text' => ''];
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
                $listKey    = "sectionParallax01_{$pad}_{$letter}_list_{$listLetter}";
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
            '{' . $letter . '-eyebrow-dl}',
            '{' . $letter . '-eyebrow-text}',
            '{' . $letter . '-title-dl}',
            '{' . $letter . '-title-text}',
            '{' . $letter . '-text-dl}',
            '{' . $letter . '-text-text}',
            '{' . $letter . '-list-items}',
            '{' . $letter . '-cta-dl}',
            '{' . $letter . '-cta-href}',
            '{' . $letter . '-cta-title}',
            '{' . $letter . '-cta-text}',
        ];
        $replace  = [
            $eyebrowKey,
            $eyebrowObj->text ?? '',
            $titleKey,
            $titleObj->text ?? '',
            $textKey,
            $textObj->text ?? '',
            $listItemsHtml,
            $ctaKey,
            $ctaHref,
            $ctaTitle,
            $ctaText,
        ];

        $itemsHtml .= str_replace($search, $replace, $itemHtml);
    }

    $vars = [
        '{classVar}' => "sectionParallax01_{$pad}_classVar",
        '{items}'    => $itemsHtml,
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_sectionParallax01.html', $vars);
}
?>
