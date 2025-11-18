<?php
/**
 * Directrices de copy para sect02:
 * - Encabezado principal: 6-10 palabras orientadas a servicio o mercado.
 * - Titulares de bloque (h3): 4-7 palabras destacando beneficio clave.
 * - Primer párrafo: 24-36 palabras explicando proceso y resultados.
 * - Segundo párrafo: 16-24 palabras con métricas, soporte o diferenciadores.
 * - CTA botón secundario: 2-4 palabras en tono de acción.
 * - Atributos alt/title de imagen: 6-9 palabras describiendo composición y objetivo.
 */
function controller_sect02(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $vars = [
        '{classVar}'       => "sect02_{$pad}_classVar",
        '{header-primary}' => '',
        '{items}'      => ''
    ];

    $tpl = <<<'HTML'
    <article>
        <div>
            <h3 data-lang="{X-h3-dl}">{X-h3-text}</h3>
            <p data-lang="{X-p-01-dl}">{X-p-01-text}</p>
            <p data-lang="{X-p-02-dl}">{X-p-02-text}</p>
            {X-button-secondary}
        </div>

        <div>
            <img data-lang="{X-img-dl}" src="{X-img-src}" srcset="{X-img-srcset}" sizes="{X-img-sizes}" width="1000" height="1000" alt="{X-img-alt}" title="{X-img-title}">
        </div>
    </article>
    HTML;

    $letters    = range('a', 'z');
    $itemsCount = $params['items'] ?? 0;
    $itemsHtml  = '';

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j];
        $pre    = "sect02_{$pad}_{$letter}";

        $buttonKey = '{' . $letter . '-button-secondary}';
        $buttonVal = $params[$buttonKey] ?? '';
        unset($params[$buttonKey]);

        $itemHtml = str_replace('{X', '{' . $letter, $tpl);
        $search = [
            '{' . $letter . '-h3-dl}', '{' . $letter . '-h3-text}',
            '{' . $letter . '-p-01-dl}', '{' . $letter . '-p-01-text}',
            '{' . $letter . '-p-02-dl}', '{' . $letter . '-p-02-text}',
            '{' . $letter . '-button-secondary}',            
            '{' . $letter . '-img-dl}', '{' . $letter . '-img-src}', '{' . $letter . '-img-alt}', '{' . $letter . '-img-title}',
            '{' . $letter . '-img-srcset}', '{' . $letter . '-img-sizes-dl}', '{' . $letter . '-img-sizes}',
        ];
        $replace = [
            $pre . '_h3_text',
            $GLOBALS[$pre . '_h3_text']->text ?? '',
            $pre . '_p01_text',
            $GLOBALS[$pre . '_p01_text']->text ?? '',
            $pre . '_p02_text',
            $GLOBALS[$pre . '_p02_text']->text ?? '',
            $buttonVal,
            $pre . '_img',
            $_ENV['RAIZ'] . '/' . ($GLOBALS[$pre . '_img']->src ?? ''),
            $GLOBALS[$pre . '_img']->alt ?? '',
            $GLOBALS[$pre . '_img']->title ?? '',
            $_ENV['RAIZ'] . '/' . (($GLOBALS[$pre . '_img_srcset01'] ?? '')) . ', ' . $_ENV['RAIZ'] . '/' . (($GLOBALS[$pre . '_img_srcset02'] ?? '')),
            $pre . '_img_sizes',
            $GLOBALS[$pre . '_img_sizes']->text ?? '',
        ];
        $itemsHtml .= str_replace($search, $replace, $itemHtml);
    }

    $vars['{items}'] = $itemsHtml;
    unset($params['items']);
    $vars = array_replace($vars, $params);
    return render('App/templates/_sect02.html', $vars);
}
?>
