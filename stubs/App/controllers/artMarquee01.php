<?php
/**
 * Directrices de copy para artMarquee01:
 * - Items por fila: 2-3 palabras en tono imperativo o declarativo.
 * - Cada item admite icono + texto (icono opcional).
 * - Usa frases cortas y repetibles para lectura en loop.
 */
function controller_artMarquee01(int $i = 0, array $params = []): string
{
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

    $getLangObj = static function (string $key) use ($getTemplateLang) {
        return $GLOBALS[$key] ?? $getTemplateLang($key);
    };

    $withImages = isset($params['with_images']) ? filter_var($params['with_images'], FILTER_VALIDATE_BOOL) : false;
    unset($params['with_images']);

    $buildPool = static function (string $row) use ($letters, $pad, $getLangObj, $withImages) {
        $pool = [];
        foreach ($letters as $letter) {
            $textKey = "artMarquee01_{$pad}_{$row}_item_text_{$letter}";

            $textObj = $getLangObj($textKey);
            if (!is_object($textObj)) {
                continue;
            }

            $imgObj = null;
            if ($withImages) {
                $imgObj = $getLangObj("artMarquee01_{$pad}_{$row}_item_img_{$letter}");
                if (!is_object($imgObj)) {
                    $imgObj = (object) ['src' => '', 'alt' => '', 'title' => ''];
                }
            }

            $pool[] = [
                'text' => $textObj,
                'img'  => $imgObj,
            ];
        }

        return $pool;
    };

    $row1Pool = $buildPool('row1');
    $row2Pool = $buildPool('row2');

    $row1Count = isset($params['items_row1']) ? (int) $params['items_row1'] : (int) ($params['items'] ?? 0);
    $row2Count = isset($params['items_row2']) ? (int) $params['items_row2'] : 0;

    if ($row1Count === 0) {
        $row1Count = count($row1Pool);
        $row1Count = $row1Count > 0 ? $row1Count : 4;
    }

    if ($row2Count === 0) {
        $row2Count = count($row2Pool);
        $row2Count = $row2Count > 0 ? $row2Count : $row1Count;
    }

    $maxItems  = count($letters);
    $row1Count = max(0, min($row1Count, $maxItems));
    $row2Count = max(0, min($row2Count, $maxItems));

    unset($params['items'], $params['items_row1'], $params['items_row2']);

    $imgTpl = '<img class="marquee-icon" data-lang="{X-img-dl}" src="{X-img-src}" alt="{X-img-alt}" title="{X-img-title}" width="24" height="24">';

    $itemTpl = <<<'HTML'
        <span class="marquee-item">
            {X-img}
            <span class="marquee-text" data-lang="{X-text-dl}">{X-text}</span>
        </span>
    HTML;

    $renderRow = static function (string $row, int $count, array $pool) use ($letters, $pad, $getLangObj, $itemTpl, $imgTpl, $withImages) {
        $items     = '';
        $poolCount = count($pool);

        for ($index = 0; $index < $count; $index++) {
            $letter  = $letters[$index];
            $textKey = "artMarquee01_{$pad}_{$row}_item_text_{$letter}";

            $textObj = $getLangObj($textKey);
            $imgObj  = null;
            $imgKey  = '';

            if ($withImages) {
                $imgKey = "artMarquee01_{$pad}_{$row}_item_img_{$letter}";
                $imgObj = $getLangObj($imgKey);
            }

            if (!is_object($textObj)) {
                if ($poolCount > 0) {
                    $fallback = $pool[$index % $poolCount];
                    $textObj  = $fallback['text'] ?? (object) ['text' => ''];
                    if ($withImages) {
                        $imgObj = $fallback['img'] ?? $imgObj;
                    }
                } else {
                    $textObj = (object) ['text' => ''];
                }
            }

            $imgMarkup = '';
            if ($withImages) {
                if (!is_object($imgObj)) {
                    $imgObj = (object) ['src' => '', 'alt' => '', 'title' => ''];
                }
                $imgMarkup = str_replace(
                    ['{X-img-dl}', '{X-img-src}', '{X-img-alt}', '{X-img-title}'],
                    [
                        $imgKey,
                        $_ENV['RAIZ'] . '/' . ($imgObj->src ?? ''),
                        $imgObj->alt ?? '',
                        $imgObj->title ?? '',
                    ],
                    $imgTpl
                );
            }

            $items .= str_replace(
                ['{X-img}', '{X-text-dl}', '{X-text}'],
                [
                    $imgMarkup,
                    $textKey,
                    $textObj->text ?? '',
                ],
                $itemTpl
            );
        }

        return $items;
    };

    $row1Items = $renderRow('row1', $row1Count, $row1Pool);
    $row2Items = $renderRow('row2', $row2Count, $row2Pool);

    $vars = [
        '{classVar}'   => "artMarquee01_{$pad}_classVar",
        '{row1-items}' => $row1Items,
        '{row2-items}' => $row2Items,
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_artMarquee01.html', $vars);
}
?>
