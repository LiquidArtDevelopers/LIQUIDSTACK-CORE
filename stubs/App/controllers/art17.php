<?php
/**
 * Directrices de copy para art17:
 * - Encabezado principal: 8-12 palabras que introduzcan la propuesta de valor conjunta.
 * - Encabezados de ficha: 5-9 palabras con beneficio o enfoque concreto.
 * - Ítems de lista: 4-10 palabras combinando acción, garantía o proceso.
 * - CTA opcional: 2-4 palabras en tono imperativo o de invitación.
 */
function controller_art17(int $i = 0, array $params = []): string
{
    $pad     = sprintf('%02d', $i);
    $letters = range('a', 'z');

    $getTemplateLang = static function (string $key) {
        static $templateLang = null;

        if ($templateLang === null) {
            $lang      = $_ENV['LANG_DEFAULT'] ?? 'es';
            $file      = __DIR__ . '/../config/languages/templates/' . $lang . '.json';
            $json      = is_readable($file) ? file_get_contents($file) : '{}';
            $decoded   = json_decode($json);
            $templateLang = is_object($decoded) ? $decoded : new stdClass();
        }

        return $templateLang->{$key} ?? null;
    };

    $templateHeaderPool = [];
    $templateIconPool   = [];
    $templateListPools  = [];

    foreach ($letters as $letter) {
        $headerKey = "art17_{$pad}_headerSecondary_{$letter}";
        $iconKey   = "art17_{$pad}_{$letter}_icon";

        $defaultHeader = $GLOBALS[$headerKey] ?? $getTemplateLang($headerKey);
        $defaultIcon   = $GLOBALS[$iconKey] ?? $getTemplateLang($iconKey);

        if (is_object($defaultHeader)) {
            $templateHeaderPool[] = $defaultHeader;
        }

        if (is_object($defaultIcon)) {
            $templateIconPool[] = $defaultIcon;
        }

        $listLetters = range('a', 'z');
        foreach ($listLetters as $listLetter) {
            $defaultListObj = $GLOBALS["art17_{$pad}_{$letter}_list_{$listLetter}"] ?? $getTemplateLang("art17_{$pad}_{$letter}_list_{$listLetter}");

            if (!is_object($defaultListObj)) {
                break;
            }

            $templateListPools[$letter][] = $defaultListObj;
        }
    }

    $itemsCount = isset($params['items']) ? (int) $params['items'] : 0;
    $itemsCount = max(0, min($itemsCount, count($letters)));

    if ($itemsCount === 0) {
        $itemsCount = count($templateHeaderPool);
        $itemsCount = $itemsCount > 0 ? $itemsCount : 2;
    }

    $listItemsParam = $params['list_items'] ?? 0;
    $defaultList    = is_numeric($listItemsParam) ? (int) $listItemsParam : 0;
    unset($params['items'], $params['list_items']);

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];

    $listTpl = <<<'HTML'
        <li class="art17-list-item">
            {X-list-icon}
            <span data-lang="{X-li-dl}">{X-li-text}</span>
        </li>
    HTML;

    $itemTpl = <<<'HTML'
        <div class="art17-card">
            {X-header-secondary}
            <ul class="art17-list">
                {X-list-items}
            </ul>
            {X-button-primary}
        </div>
    HTML;

    $itemsHtml = '';

    for ($j = 0; $j < $itemsCount && $j < count($letters); $j++) {
        $letter       = $letters[$j];
        $headerVar    = "art17_{$pad}_headerSecondary_{$letter}";
        $listOverride = '{' . $letter . '-list-items}';
        $listIconKey  = '{' . $letter . '-list-icon}';

        $headerObj = $GLOBALS[$headerVar] ?? $getTemplateLang($headerVar);
        if (!is_object($headerObj) && count($templateHeaderPool) > 0) {
            $headerObj = $templateHeaderPool[$j % count($templateHeaderPool)];
        }
        if (!is_object($headerObj)) {
            $headerObj = (object) ['text' => ''];
        }

        $iconVar      = "art17_{$pad}_{$letter}_icon";
        $iconObj      = $GLOBALS[$iconVar] ?? $getTemplateLang($iconVar);
        if (!is_object($iconObj) && count($templateIconPool) > 0) {
            $iconObj = $templateIconPool[$j % count($templateIconPool)];
        }
        $iconSrcValue = (is_object($iconObj) && isset($iconObj->src)) ? $iconObj->src : 'assets/img/system/shield-checkmark-outline.svg';
        $iconSrc      = $iconSrcValue !== '' ? $_ENV['RAIZ'] . '/' . ltrim($iconSrcValue, '/') : '';
        $iconAlt      = (is_object($iconObj) && isset($iconObj->alt)) ? $iconObj->alt : '';
        $iconTitle    = (is_object($iconObj) && isset($iconObj->title)) ? $iconObj->title : '';

        $buttonKey = '{' . $letter . '-button-primary}';
        $buttonVal = $params[$buttonKey] ?? '';
        unset($params[$buttonKey]);

        $listIcon = '<span class="art17-list-icon">'
            . '<img data-lang="' . $iconVar . '" src="' . $iconSrc . '" alt="' . $iconAlt . '" title="' . $iconTitle
            . '" width="24" height="24" loading="lazy">'
            . '</span>';

        $listIcon = $params[$listIconKey] ?? ($params['list_icon'] ?? $listIcon);
        unset($params[$listIconKey]);

        if (isset($params[$listOverride])) {
            $listItemsHtml = (string) $params[$listOverride];
            unset($params[$listOverride]);
        } else {
            $listLetters = range('a', 'z');

            $poolLetter       = isset($templateListPools[$letter]) ? $letter : array_key_first($templateListPools);
            $defaultListPool  = $poolLetter !== null ? $templateListPools[$poolLetter] : [];
            $defaultListCount = count($defaultListPool);
            $listCount        = $defaultList > 0 ? $defaultList : ($defaultListCount > 0 ? $defaultListCount : 4);

            if (is_array($listItemsParam)) {
                if (array_key_exists($letter, $listItemsParam)) {
                    $listCount = (int) $listItemsParam[$letter];
                } elseif (array_key_exists($j, $listItemsParam)) {
                    $listCount = (int) $listItemsParam[$j];
                }
            }

            $listCount   = max(0, min($listCount, count($listLetters)));
            $listItemsHtml = '';

            for ($k = 0; $k < $listCount; $k++) {
                $listLetter = $listLetters[$k];
                $listKey    = "art17_{$pad}_{$letter}_list_{$listLetter}";
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
                    ['{X-list-icon}', '{X-li-dl}', '{X-li-text}'],
                    [
                        $listIcon,
                        $listKey,
                        $listObj->text ?? '',
                    ],
                    $listTpl
                );
            }
        }

        $itemsHtml .= str_replace(
            ['{X-header-secondary}', '{X-list-items}', '{X-button-primary}'],
            [
                '<h' . $itemLevel . ' data-lang="' . $headerVar . '">' . ($headerObj->text ?? '') . '</h' . $itemLevel . '>',
                $listItemsHtml,
                $buttonVal,
            ],
            $itemTpl
        );
    }

    unset($params['list_icon']);

    $vars = [
        '{classVar}'       => "art17_{$pad}_classVar",
        '{header-primary}' => '<h' . $baseLevel . ' data-lang="art17_' . $pad . '_headerPrimary">' . (($GLOBALS["art17_{$pad}_headerPrimary"] ?? $getTemplateLang("art17_{$pad}_headerPrimary"))->text ?? '') . '</h' . $baseLevel . '>',
        '{items}'          => $itemsHtml,
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_art17.html', $vars);
}
?>
