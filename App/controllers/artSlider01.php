<?php
/**
 * Directrices de copy para artSlider01:
 * - Encabezado principal: 6-9 palabras con propuesta editorial.
 * - CTA "ver más": 2-3 palabras orientadas a descubrir el archivo.
 * - Metadatos: fecha corta o descriptor de 1-3 palabras.
 * - Titulares de tarjeta: 6-9 palabras con enfoque SEO.
 * - Bloque de tags: 3-6 palabras clave combinadas.
 * - Texto del enlace: 2-3 palabras activas.
 * - Atributos alt/title: 6-10 palabras descriptivas.
 */
function controller_artSlider01(int $i = 0, array $params = []): string
{
    global $lang;
    $pad = sprintf('%02d', $i);
    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];
    $viewMoreObj   = $GLOBALS["artSlider01_{$pad}_viewmore"] ?? null;
    $viewMoreHref  = '';
    if (is_object($viewMoreObj) && isset($viewMoreObj->href)) {
        $viewMoreHref = $viewMoreObj->href;
    } elseif (is_array($viewMoreObj) && isset($viewMoreObj['href'])) {
        $viewMoreHref = (string) $viewMoreObj['href'];
    }
    $viewMoreText = is_object($viewMoreObj) && isset($viewMoreObj->text) ? $viewMoreObj->text : '';

    $vars = [
        '{classVar}'         => "artSlider01_{$pad}_classVar",
        '{header-primary}'   => '<h' . $baseLevel . ' data-lang="artSlider01_' . $pad . '_headerPrimary">' . $GLOBALS["artSlider01_{$pad}_headerPrimary"]->text . '</h' . $baseLevel . '>',
        '{viewmore-dl}'      => "artSlider01_{$pad}_viewmore",
        '{viewmore-text}'    => $viewMoreText,
        '{viewmore-href}'    => resolve_localized_href($viewMoreHref, ['lang' => $lang]),
        '{artSlider01-track}' => ''
    ];
    $letters     = range('a','z');
    $itemsCount  = $params['items'] ?? 0;
    $headingTpl = '<h' . $itemLevel . ' data-lang="{%L%-h4-dl}">{%L%-h4-text}</h' . $itemLevel . '>';
    $cardTpl  = '<div class="artSlider01-card">'
        .'<img  data-lang="{%L%-img-dl}"  src="{%L%-img-src}" alt="{%L%-img-alt}" title="{%L%-img-title}">'
        .'<span class="meta" data-lang="{%L%-meta-dl}">{%L%-meta-text}</span>'
        .$headingTpl
        .'<p    class="tags" data-lang="{%L%-tags-dl}">{%L%-tags-text}</p>'
        .'<a    class="read-more" data-lang="{%L%-read-dl}" href="{%L%-read-href}">{%L%-read-text}</a>'
        .'</div>';
    $trackHtml = '';
    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j];
        $pre = "artSlider01_{$pad}_card{$letter}";
        $card = str_replace(
            ['{%L%-img-dl}','{%L%-img-src}','{%L%-img-alt}','{%L%-img-title}',
             '{%L%-meta-dl}','{%L%-meta-text}',
             '{%L%-h4-dl}','{%L%-h4-text}',
             '{%L%-tags-dl}','{%L%-tags-text}',
             '{%L%-read-dl}','{%L%-read-href}','{%L%-read-text}'],
            [
                $pre.'_img',
                $_ENV['RAIZ'].'/'.$GLOBALS[$pre.'_img']->src,
                $GLOBALS[$pre.'_img']->alt,
                $GLOBALS[$pre.'_img']->title,
                $pre.'_meta',
                $GLOBALS[$pre.'_meta']->text,
                $pre.'_h4',
                $GLOBALS[$pre.'_h4']->text,
                $pre.'_tags',
                $GLOBALS[$pre.'_tags']->text,
                $pre.'_read',
                $GLOBALS[$pre.'_read']->href,
                $GLOBALS[$pre.'_read']->text
            ],
            $cardTpl
        );
        $trackHtml .= $card;
    }
    $vars['{artSlider01-track}'] = $trackHtml;
    unset($params['items']);
    $vars = array_replace($vars, $params);
    return render('App/templates/_artSlider01.html', $vars);
}
?>
