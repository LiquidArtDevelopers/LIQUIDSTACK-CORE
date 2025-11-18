<?php
/**
 * Directrices de copy para sect01:
 * - Encabezado principal: 6-10 palabras que sinteticen logro o propuesta.
 * - Titulares de bloque (h3): 4-7 palabras con beneficio accionable.
 * - Primer párrafo: 20-32 palabras narrando proceso y resultados clave.
 * - Segundo párrafo: 14-22 palabras con métricas o soporte adicional.
 * - CTA botón secundario: 2-4 palabras en imperativo claro.
 * - Atributos alt/title de iconos: 3-5 palabras describiendo representación.
 * - Atributos alt/title de visuales: 5-8 palabras combinando escena y propósito.
 */
function controller_sect01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $vars = [
        '{classVar}'       => "sect01_{$pad}_classVar",
        '{header-primary}' => '',
        '{items}'      => ''
    ];

    $tpl = <<<'HTML'
    <article>
        <div class="sect01-text">
            <div class="sect01-title">
                <img data-lang="{X-img01-dl}" src="{X-img01-src}" alt="{X-img01-alt}" title="{X-img01-title}">
                <h3 data-lang="{X-h3-dl}">{X-h3-text}</h3>
            </div>
            <div class="sect01-content">
                <p data-lang="{X-p-01-dl}">{X-p-01-text}</p>
                <p data-lang="{X-p-02-dl}">{X-p-02-text}</p>
            </div>
            <div class="sect01-cta">
                {X-button-secondary}
            </div>
        </div>
        <div class="sect01-images">
            <div class="sect01-logos">
                <div>
                    <img data-lang="{X-img02-dl}" src="{X-img02-src}" alt="{X-img02-alt}" title="{X-img02-title}">
                </div>
                <div>
                    <img data-lang="{X-img03-dl}" src="{X-img03-src}" alt="{X-img03-alt}" title="{X-img03-title}">
                </div>
            </div>
            <div class="sect01-visual">
                <img data-lang="{X-img04-dl}" src="{X-img04-src}" alt="{X-img04-alt}" title="{X-img04-title}">
            </div>
        </div>
    </article>
    HTML;

    $letters   = range('a', 'z');
    $itemsCount = $params['items'] ?? 0;
    $itemsHtml = '';

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j];
        $pre    = "sect01_{$pad}_{$letter}";

        $buttonKey = '{' . $letter . '-button-secondary}';
        $buttonVal = $params[$buttonKey] ?? '';
        unset($params[$buttonKey]);

        $itemHtml = str_replace('{X', '{' . $letter, $tpl);
        $search = [
            '{' . $letter . '-img01-dl}', '{' . $letter . '-img01-src}', '{' . $letter . '-img01-alt}', '{' . $letter . '-img01-title}',
            '{' . $letter . '-h3-dl}', '{' . $letter . '-h3-text}',
            '{' . $letter . '-p-01-dl}', '{' . $letter . '-p-01-text}',
            '{' . $letter . '-p-02-dl}', '{' . $letter . '-p-02-text}',
            '{' . $letter . '-button-secondary}',
            '{' . $letter . '-img02-dl}', '{' . $letter . '-img02-src}', '{' . $letter . '-img02-alt}', '{' . $letter . '-img02-title}',
            '{' . $letter . '-img03-dl}', '{' . $letter . '-img03-src}', '{' . $letter . '-img03-alt}', '{' . $letter . '-img03-title}',
            '{' . $letter . '-img04-dl}', '{' . $letter . '-img04-src}', '{' . $letter . '-img04-alt}', '{' . $letter . '-img04-title}',
        ];
        $replace = [
            $pre . '_img01',
            $_ENV['RAIZ'] . '/' . ($GLOBALS[$pre . '_img01']->src ?? ''),
            $GLOBALS[$pre . '_img01']->alt ?? '',
            $GLOBALS[$pre . '_img01']->title ?? '',
            $pre . '_h3_text',
            $GLOBALS[$pre . '_h3_text']->text ?? '',
            $pre . '_p01_text',
            $GLOBALS[$pre . '_p01_text']->text ?? '',
            $pre . '_p02_text',
            $GLOBALS[$pre . '_p02_text']->text ?? '',
            $buttonVal,
            $pre . '_img02',
            $_ENV['RAIZ'] . '/' . ($GLOBALS[$pre . '_img02']->src ?? ''),
            $GLOBALS[$pre . '_img02']->alt ?? '',
            $GLOBALS[$pre . '_img02']->title ?? '',
            $pre . '_img03',
            $_ENV['RAIZ'] . '/' . ($GLOBALS[$pre . '_img03']->src ?? ''),
            $GLOBALS[$pre . '_img03']->alt ?? '',
            $GLOBALS[$pre . '_img03']->title ?? '',
            $pre . '_img04',
            $_ENV['RAIZ'] . '/' . ($GLOBALS[$pre . '_img04']->src ?? ''),
            $GLOBALS[$pre . '_img04']->alt ?? '',
            $GLOBALS[$pre . '_img04']->title ?? '',
        ];
        $itemsHtml .= str_replace($search, $replace, $itemHtml);
    }

    $vars['{items}'] = $itemsHtml;
    unset($params['items']);
    $vars = array_replace($vars, $params);
    return render('App/templates/_sect01.html', $vars);
}
?>
