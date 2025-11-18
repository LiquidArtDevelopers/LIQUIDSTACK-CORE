<?php
/**
 * Directrices de copy para art06:
 * - Encabezado principal: 6-10 palabras con la propuesta de valor.
 * - Encabezados secundarios: 4-7 palabras alineadas con beneficios clave.
 * - Párrafos por bloque: 28-42 palabras desarrollando procesos y resultados.
 * - Cita: 14-22 palabras recogiendo testimonio o insight relevante.
 * - Atributos alt/title de imagen: 6-10 palabras describiendo escena o perfil.
 */
function controller_art06(int $i = 0, array $params = []): string
{
    $pad          = sprintf('%02d', $i);
    $letters      = range('a', 'z');
    $itemsCount   = (int)($params['items'] ?? 0);
    $itemsCount   = $itemsCount > 0 ? $itemsCount : 3;
    $itemsCount   = min($itemsCount, count($letters));

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];

    unset($params['items']);

    $citeAfter    = isset($params['cite-index']) ? (int)$params['cite-index'] : 2;
    unset($params['cite-index']);
    $citeAfter    = $citeAfter < 0 ? 0 : $citeAfter;

    $citeKey      = '{cite-block}';
    $defaultSizes = '(max-width: 899px) 900px, 1500px';

    $prefixAsset = static function (?string $value): string {
        if ($value === null || $value === '') {
            return '';
        }
        return rtrim($_ENV['RAIZ'], '/') . '/' . ltrim($value, '/');
    };

    $getText = static function (string $key): string {
        $value = $GLOBALS[$key] ?? null;
        return is_object($value) && isset($value->text) ? $value->text : '';
    };

    $getImageProp = static function (string $key, string $property): string {
        $value = $GLOBALS[$key] ?? null;
        return is_object($value) && isset($value->$property) ? $value->$property : '';
    };

    $buildSrcset = static function (array $values) use ($prefixAsset): string {
        $paths = array_filter(array_map($prefixAsset, $values));
        return implode(', ', $paths);
    };

    $citeHtmlDefault = <<<HTML
        <div class="art06-cite">
            <cite>
                <span>❝</span>
                <span data-lang="art06_{$pad}_cite">{$getText("art06_{$pad}_cite")}</span>
            </cite>
        </div>
    HTML;

    $citeHtml = $params[$citeKey] ?? $citeHtmlDefault;
    unset($params[$citeKey]);

    $itemTpl = <<<'HTML'
        <div class="art06-content{X-modifier}">
            <div>
                {X-header-secondary}
                <p data-lang="{X-p-dl}">{X-p-text}</p>
                {X-button-primary}
            </div>

            <picture>
                <source type="image/avif" srcset="{X-avif-srcset}" sizes="{X-avif-sizes}">
                <img data-lang="{X-img-dl}"
                     src="{X-img-src}" width="900" height="600"
                     alt="{X-img-alt}" title="{X-img-title}"
                     srcset="{X-img-srcset}"
                     sizes="{X-img-sizes}">
            </picture>
        </div>
    HTML;

    $itemsParts   = [];
    $citeInserted = false;

    if ($citeAfter === 0) {
        $itemsParts[] = $citeKey;
        $citeInserted = true;
    }

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter       = $letters[$j];
        $headerVar    = "art06_{$pad}_headerSecondary_{$letter}";
        $paragraphVar = "art06_{$pad}_{$letter}_p";
        $imgVar       = "art06_{$pad}_{$letter}_img";
        $avifSrc01Var = "art06_{$pad}_{$letter}_avif_src01";
        $avifSrc02Var = "art06_{$pad}_{$letter}_avif_src02";
        $imgSrc01Var  = "art06_{$pad}_{$letter}_img_src01";
        $imgSrc02Var  = "art06_{$pad}_{$letter}_img_src02";

        $itemReplacements = [
            '{X-modifier}'         => $j % 2 === 1 ? ' art06-content--reverse' : '',
            '{X-header-secondary}' => '<h' . $itemLevel . ' data-lang="' . $headerVar . '">' . $getText($headerVar) . '</h' . $itemLevel . '>',
            '{X-p-dl}'             => $paragraphVar,
            '{X-p-text}'           => $getText($paragraphVar),
            '{X-button-primary}'   => '',
            '{X-avif-srcset}'      => $buildSrcset([
                $GLOBALS[$avifSrc01Var] ?? '',
                $GLOBALS[$avifSrc02Var] ?? '',
            ]),
            '{X-avif-sizes}'       => $defaultSizes,
            '{X-img-dl}'           => $imgVar,
            '{X-img-src}'          => $prefixAsset($getImageProp($imgVar, 'src')),
            '{X-img-alt}'          => $getImageProp($imgVar, 'alt'),
            '{X-img-title}'        => $getImageProp($imgVar, 'title'),
            '{X-img-srcset}'       => $buildSrcset([
                $GLOBALS[$imgSrc01Var] ?? '',
                $GLOBALS[$imgSrc02Var] ?? '',
            ]),
            '{X-img-sizes}'        => $defaultSizes,
        ];

        $overrideMap = [
            '{X-header-secondary}' => '{' . $letter . '-header-secondary}',
            '{X-p-dl}'             => '{' . $letter . '-p-dl}',
            '{X-p-text}'           => '{' . $letter . '-p-text}',
            '{X-button-primary}'   => '{' . $letter . '-button-primary}',
            '{X-avif-srcset}'      => '{' . $letter . '-avif-srcset}',
            '{X-avif-sizes}'       => '{' . $letter . '-avif-sizes}',
            '{X-img-dl}'           => '{' . $letter . '-img-dl}',
            '{X-img-src}'          => '{' . $letter . '-img-src}',
            '{X-img-alt}'          => '{' . $letter . '-img-alt}',
            '{X-img-title}'        => '{' . $letter . '-img-title}',
            '{X-img-srcset}'       => '{' . $letter . '-img-srcset}',
            '{X-img-sizes}'        => '{' . $letter . '-img-sizes}',
        ];

        foreach ($overrideMap as $placeholder => $overrideKey) {
            if (array_key_exists($overrideKey, $params)) {
                $itemReplacements[$placeholder] = $params[$overrideKey];
                unset($params[$overrideKey]);
            }
        }

        $itemsParts[] = strtr($itemTpl, $itemReplacements);

        if (!$citeInserted && ($j + 1) === $citeAfter) {
            $itemsParts[] = $citeKey;
            $citeInserted = true;
        }
    }

    if (!$citeInserted) {
        $itemsParts[] = $citeKey;
    }

    $itemsHtml = str_replace($citeKey, $citeHtml, implode('', $itemsParts));

    $vars = [
        '{classVar}'       => "art06_{$pad}_classVar",
        '{header-primary}' => '<h' . $baseLevel . ' data-lang="art06_' . $pad . '_headerPrimary">' . $getText("art06_{$pad}_headerPrimary") . '</h' . $baseLevel . '>',
        '{items}'          => $itemsHtml,
        $citeKey           => $citeHtml,
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_art06.html', $vars);
}
?>
