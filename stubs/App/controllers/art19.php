<?php
/**
 * Directrices de copy para art19:
 * - Encabezado principal: 3-7 palabras para contextualizar el slider.
 * - Titulo por slide: 1-4 palabras descriptivas y memorables.
 * - Subtitulo por slide: 4-10 palabras con apoyo narrativo breve.
 * - Label de click: 2-5 palabras orientadas a la accion.
 * - Imagen por slide (alt/title): 5-10 palabras que describan la escena.
 */
function controller_art19(int $i = 0, array $params = []): string
{
    $pad     = sprintf('%02d', $i);
    $letters = range('a', 'z');

    // Claves semilla para update-languages.php (items de referencia en templates).
    $seedSlideAImg = $GLOBALS["art19_{$pad}_slidea_img"] ?? null;
    $seedSlideBImg = $GLOBALS["art19_{$pad}_slideb_img"] ?? null;
    $seedSlideCImg = $GLOBALS["art19_{$pad}_slidec_img"] ?? null;
    $seedSlideDImg = $GLOBALS["art19_{$pad}_slided_img"] ?? null;
    $seedSlideAH3  = $GLOBALS["art19_{$pad}_slidea_h3"] ?? null;
    $seedSlideBH3  = $GLOBALS["art19_{$pad}_slideb_h3"] ?? null;
    $seedSlideCH3  = $GLOBALS["art19_{$pad}_slidec_h3"] ?? null;
    $seedSlideDH3  = $GLOBALS["art19_{$pad}_slided_h3"] ?? null;
    $seedSlideAP   = $GLOBALS["art19_{$pad}_slidea_p"] ?? null;
    $seedSlideBP   = $GLOBALS["art19_{$pad}_slideb_p"] ?? null;
    $seedSlideCP   = $GLOBALS["art19_{$pad}_slidec_p"] ?? null;
    $seedSlideDP   = $GLOBALS["art19_{$pad}_slided_p"] ?? null;
    $seedSlideAImgSrc = is_object($seedSlideAImg) ? ($seedSlideAImg->src ?? '') : '';
    $seedSlideAImgAlt = is_object($seedSlideAImg) ? ($seedSlideAImg->alt ?? '') : '';
    $seedSlideAImgTtl = is_object($seedSlideAImg) ? ($seedSlideAImg->title ?? '') : '';
    $seedSlideBImgSrc = is_object($seedSlideBImg) ? ($seedSlideBImg->src ?? '') : '';
    $seedSlideBImgAlt = is_object($seedSlideBImg) ? ($seedSlideBImg->alt ?? '') : '';
    $seedSlideBImgTtl = is_object($seedSlideBImg) ? ($seedSlideBImg->title ?? '') : '';
    $seedSlideCImgSrc = is_object($seedSlideCImg) ? ($seedSlideCImg->src ?? '') : '';
    $seedSlideCImgAlt = is_object($seedSlideCImg) ? ($seedSlideCImg->alt ?? '') : '';
    $seedSlideCImgTtl = is_object($seedSlideCImg) ? ($seedSlideCImg->title ?? '') : '';
    $seedSlideDImgSrc = is_object($seedSlideDImg) ? ($seedSlideDImg->src ?? '') : '';
    $seedSlideDImgAlt = is_object($seedSlideDImg) ? ($seedSlideDImg->alt ?? '') : '';
    $seedSlideDImgTtl = is_object($seedSlideDImg) ? ($seedSlideDImg->title ?? '') : '';
    $seedSlideAH3Txt  = is_object($seedSlideAH3) ? ($seedSlideAH3->text ?? '') : '';
    $seedSlideBH3Txt  = is_object($seedSlideBH3) ? ($seedSlideBH3->text ?? '') : '';
    $seedSlideCH3Txt  = is_object($seedSlideCH3) ? ($seedSlideCH3->text ?? '') : '';
    $seedSlideDH3Txt  = is_object($seedSlideDH3) ? ($seedSlideDH3->text ?? '') : '';
    $seedSlideAPTxt   = is_object($seedSlideAP) ? ($seedSlideAP->text ?? '') : '';
    $seedSlideBPTxt   = is_object($seedSlideBP) ? ($seedSlideBP->text ?? '') : '';
    $seedSlideCPTxt   = is_object($seedSlideCP) ? ($seedSlideCP->text ?? '') : '';
    $seedSlideDPTxt   = is_object($seedSlideDP) ? ($seedSlideDP->text ?? '') : '';
    unset(
        $seedSlideAImg,
        $seedSlideBImg,
        $seedSlideCImg,
        $seedSlideDImg,
        $seedSlideAH3,
        $seedSlideBH3,
        $seedSlideCH3,
        $seedSlideDH3,
        $seedSlideAP,
        $seedSlideBP,
        $seedSlideCP,
        $seedSlideDP,
        $seedSlideAImgSrc,
        $seedSlideAImgAlt,
        $seedSlideAImgTtl,
        $seedSlideBImgSrc,
        $seedSlideBImgAlt,
        $seedSlideBImgTtl,
        $seedSlideCImgSrc,
        $seedSlideCImgAlt,
        $seedSlideCImgTtl,
        $seedSlideDImgSrc,
        $seedSlideDImgAlt,
        $seedSlideDImgTtl,
        $seedSlideAH3Txt,
        $seedSlideBH3Txt,
        $seedSlideCH3Txt,
        $seedSlideDH3Txt,
        $seedSlideAPTxt,
        $seedSlideBPTxt,
        $seedSlideCPTxt,
        $seedSlideDPTxt
    );

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

    $toAssetUrl = static function (?string $value): string {
        if ($value === null || $value === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $value) === 1 || str_starts_with($value, '/')) {
            return $value;
        }

        return rtrim($_ENV['RAIZ'] ?? '', '/') . '/' . ltrim($value, '/');
    };

    $escAttr = static function (?string $value): string {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    };

    $defaultSlides = [
        [
            'img'   => '/assets/img/dummy/dummy01.avif',
            'alt'   => 'Paisaje costero entre niebla y montanas',
            'title' => 'Paisaje costero entre niebla y montanas',
            'h3'    => 'Ocean Cliffs',
            'p'     => 'Click para abrir una nueva escena liquida.',
        ],
        [
            'img'   => '/assets/img/dummy/dummy02.avif',
            'alt'   => 'Roca volcanica y valle en horizonte abierto',
            'title' => 'Roca volcanica y valle en horizonte abierto',
            'h3'    => 'Silent Valley',
            'p'     => 'Cada onda despliega el siguiente paisaje.',
        ],
        [
            'img'   => '/assets/img/dummy/dummy03.avif',
            'alt'   => 'Luz suave sobre relieve natural y sendero',
            'title' => 'Luz suave sobre relieve natural y sendero',
            'h3'    => 'Lava Trails',
            'p'     => 'Transicion por clic con origen en tu cursor.',
        ],
        [
            'img'   => '/assets/img/dummy/dummy04.avif',
            'alt'   => 'Planicie oscura con montanas al fondo',
            'title' => 'Planicie oscura con montanas al fondo',
            'h3'    => 'Northern Dust',
            'p'     => 'Ondas de agua que cubren toda la superficie.',
        ],
    ];

    $itemsCount = isset($params['items']) ? (int) $params['items'] : 0;
    $itemsCount = max(1, min($itemsCount > 0 ? $itemsCount : 4, count($letters)));
    unset($params['items']);

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];

    $headerKey = "art19_{$pad}_headerPrimary";
    $headerObj = $GLOBALS[$headerKey] ?? $getTemplateLang($headerKey);
    $headerTxt = is_object($headerObj) && isset($headerObj->text) ? trim((string) $headerObj->text) : '';
    if ($headerTxt === '') {
        $headerTxt = 'Liquid Slider';
    }

    $clickKey = "art19_{$pad}_clickHint";
    $clickObj = $GLOBALS[$clickKey] ?? $getTemplateLang($clickKey);
    $clickTxt = is_object($clickObj) && isset($clickObj->text) ? trim((string) $clickObj->text) : '';
    if ($clickTxt === '') {
        $clickTxt = 'Cambiar imagen';
    }

    $itemTpl = <<<'HTML'
        <li class="art19-dataItem" data-art19-slide>
            <img class="art19-dataImage" data-lang="{X-img-dl}" src="{X-img-src}" alt="{X-img-alt}" title="{X-img-title}" width="1600" height="900" loading="lazy" decoding="async">
            <span class="art19-dataIndex">{X-index}</span>
            <span class="art19-dataTitle" data-lang="{X-title-dl}">{X-title-text}</span>
            <span class="art19-dataSub" data-lang="{X-sub-dl}">{X-sub-text}</span>
        </li>
    HTML;

    $itemsHtml = '';

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter        = $letters[$j];
        $fallbackSlide = $defaultSlides[$j % count($defaultSlides)];

        $imgKey = "art19_{$pad}_slide{$letter}_img";
        $h3Key  = "art19_{$pad}_slide{$letter}_h3";
        $subKey = "art19_{$pad}_slide{$letter}_p";

        $imgObj = $GLOBALS[$imgKey] ?? $getTemplateLang($imgKey);
        $h3Obj  = $GLOBALS[$h3Key] ?? $getTemplateLang($h3Key);
        $subObj = $GLOBALS[$subKey] ?? $getTemplateLang($subKey);

        $imgSrc = is_object($imgObj) && isset($imgObj->src) ? trim((string) $imgObj->src) : '';
        $imgAlt = is_object($imgObj) && isset($imgObj->alt) ? trim((string) $imgObj->alt) : '';
        $imgTtl = is_object($imgObj) && isset($imgObj->title) ? trim((string) $imgObj->title) : '';
        $h3Txt  = is_object($h3Obj) && isset($h3Obj->text) ? trim((string) $h3Obj->text) : '';
        $subTxt = is_object($subObj) && isset($subObj->text) ? trim((string) $subObj->text) : '';

        if ($imgSrc === '') {
            $imgSrc = $fallbackSlide['img'];
        }
        if ($imgAlt === '') {
            $imgAlt = $fallbackSlide['alt'];
        }
        if ($imgTtl === '') {
            $imgTtl = $fallbackSlide['title'];
        }
        if ($h3Txt === '') {
            $h3Txt = $fallbackSlide['h3'];
        }
        if ($subTxt === '') {
            $subTxt = $fallbackSlide['p'];
        }

        $slideHtml = str_replace('{X', '{' . $letter, $itemTpl);
        $search    = [
            '{' . $letter . '-img-dl}',
            '{' . $letter . '-img-src}',
            '{' . $letter . '-img-alt}',
            '{' . $letter . '-img-title}',
            '{' . $letter . '-index}',
            '{' . $letter . '-title-dl}',
            '{' . $letter . '-title-text}',
            '{' . $letter . '-sub-dl}',
            '{' . $letter . '-sub-text}',
        ];
        $replace   = [
            $imgKey,
            $escAttr($toAssetUrl($imgSrc)),
            $escAttr($imgAlt),
            $escAttr($imgTtl),
            str_pad((string) ($j + 1), 2, '0', STR_PAD_LEFT),
            $h3Key,
            $h3Txt,
            $subKey,
            $subTxt,
        ];

        $itemsHtml .= str_replace($search, $replace, $slideHtml);
    }

    $vars = [
        '{classVar}'         => "art19_{$pad}_classVar",
        '{header-primary}'   => '<h' . $baseLevel . ' class="art19-headerPrimary" data-lang="' . $headerKey . '">' . $headerTxt . '</h' . $baseLevel . '>',
        '{click-dl}'         => $clickKey,
        '{click-text}'       => $clickTxt,
        '{items}'            => $itemsHtml,
        '{wave-distortion}'  => '0.18',
        '{wave-chroma}'      => '1.45',
        '{wave-damping}'     => '0.997',
        '{wave-radius}'      => '0.09',
        '{wave-force}'       => '1.35',
        '{wave-duration}'    => '4.2',
        '{wave-sim}'         => '256',
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_art19.html', $vars);
}
?>
