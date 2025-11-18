<?php

function controller_moduleImgType01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $basePath = rtrim($_ENV['RAIZ'] ?? '', '/');
    $pathPrefix = $basePath !== '' ? $basePath . '/' : '';

    $srcsetEntries = [];
    for ($index = 1; $index <= 5; $index++) {
        $suffix = sprintf('%02d', $index);
        $globalKey = "moduleImgType01_{$pad}_img_srcset{$suffix}";
        $value = $GLOBALS[$globalKey] ?? '';

        if (!is_string($value)) {
            continue;
        }

        $value = trim($value);
        if ($value === '') {
            continue;
        }

        if (preg_match('/^https?:\/\//', $value) === 1) {
            $srcsetEntries[] = $value;
            continue;
        }

        $srcsetEntries[] = $pathPrefix . ltrim($value, '/');
    }

    $sizesKey = "moduleImgType01_{$pad}_img_sizes";
    $sizesVal = $GLOBALS[$sizesKey] ?? '';
    if (is_object($sizesVal)) {
        $sizesVal = $sizesVal->text ?? '';
    }

    $imgObj = $GLOBALS["moduleImgType01_{$pad}_img"] ?? null;
    $imgSrc = '';
    $imgAlt = '';
    $imgTitle = '';
    if (is_object($imgObj)) {
        $imgSrc = $imgObj->src ?? '';
        $imgAlt = $imgObj->alt ?? '';
        $imgTitle = $imgObj->title ?? '';
    }

    $vars = [
        '{classVar}'   => "moduleImgType01_{$pad}_classVar",
        '{img-dl}'     => "moduleImgType01_{$pad}_img",
        '{img-src}'    => $imgSrc !== '' ? $pathPrefix . ltrim($imgSrc, '/') : '',
        '{img-alt}'    => $imgAlt,
        '{img-title}'  => $imgTitle,
        '{img-width}'  => $GLOBALS["moduleImgType01_{$pad}_img_width"] ?? '',
        '{img-height}' => $GLOBALS["moduleImgType01_{$pad}_img_height"] ?? '',
        '{img-loading}' => $GLOBALS["moduleImgType01_{$pad}_img_loading"] ?? '',
        '{img-srcset}' => implode(', ', $srcsetEntries),
        '{img-sizes}'  => is_string($sizesVal) ? $sizesVal : '',
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_moduleImgType01.html', $vars);
}
