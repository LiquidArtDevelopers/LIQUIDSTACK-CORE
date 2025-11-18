<?php
/**
 * Directrices de copy para artSlider02:
 * - Encabezado principal: 5-8 palabras para introducir la galería.
 * - Controles prev/next: 1-2 palabras accionables.
 * - Titulares de cada slide: 5-8 palabras con foco en beneficio o momento.
 * - Atributos alt/title: 6-10 palabras que describan la escena.
 */
function controller_artSlider02(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];
    $vars = [
        '{classVar}'       => "artSlider02_{$pad}_classVar",
        '{header-primary}' => '<h' . $baseLevel . ' data-lang="artSlider02_' . $pad . '_headerPrimary">' . $GLOBALS["artSlider02_{$pad}_headerPrimary"]->text . '</h' . $baseLevel . '>',
        '{prev-dl}'        => "artSlider02_{$pad}_prev",
        '{prev-text}'      => $GLOBALS["artSlider02_{$pad}_prev"]->text,
        '{next-dl}'        => "artSlider02_{$pad}_next",
        '{next-text}'      => $GLOBALS["artSlider02_{$pad}_next"]->text,
        '{artSlider02-slides}' => ''
    ];
    $tpl = <<<HTML
<div class="slide">
<picture>
    <source type="image/avif" srcset="{X-avif-srcset}" sizes="{X-avif-sizes}">
    <img data-lang="{X-img-dl}" src="{X-img-src}" width="1500" height="900" alt="{X-img-alt}" title="{X-img-title}" srcset="{X-img-srcset}" sizes="{X-img-sizes}">
</picture>
{X-header-secondary}
</div>
HTML;
    $letters     = range('a','z');
    $itemsCount  = $params['items'] ?? 0;
    $slidesHtml = '';
    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j];
        $pre = "artSlider02_{$pad}_slide{$letter}";
        $slideHtml = str_replace('{X', '{'.$letter, $tpl);
        $search = [
            '{'.$letter.'-avif-srcset}','{'.$letter.'-avif-sizes}',
            '{'.$letter.'-img-dl}','{'.$letter.'-img-src}',
            '{'.$letter.'-img-alt}','{'.$letter.'-img-title}',
            '{'.$letter.'-img-srcset}','{'.$letter.'-img-sizes}',
            '{'.$letter.'-header-secondary}'
        ];
        $replace = [
            $_ENV['RAIZ'].'/'.$GLOBALS[$pre.'_avif_src01'].', '.$_ENV['RAIZ'].'/'.$GLOBALS[$pre.'_avif_src02'],
            $GLOBALS[$pre.'_avif_sizes'],
            $pre.'_img',
            $_ENV['RAIZ'].'/'.$GLOBALS[$pre.'_img']->src,
            $GLOBALS[$pre.'_img']->alt,
            $GLOBALS[$pre.'_img']->title,
            $_ENV['RAIZ'].'/'.$GLOBALS[$pre.'_img_src01'].', '.$_ENV['RAIZ'].'/'.$GLOBALS[$pre.'_img_src02'],
            $GLOBALS[$pre.'_img_sizes'],
            '<h' . $itemLevel . ' class="titulo"><span data-lang="' . $pre . '_h4">' . $GLOBALS[$pre.'_h4']->text . '</span></h' . $itemLevel . '>'
        ];
        $slidesHtml .= str_replace($search, $replace, $slideHtml);
    }
    $vars['{artSlider02-slides}'] = $slidesHtml;
    unset($params['items']);
    $vars = array_replace($vars, $params);
    return render('App/templates/_artSlider02.html', $vars);
}
?>
