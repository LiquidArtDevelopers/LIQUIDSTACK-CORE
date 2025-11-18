<?php
/**
 * Directrices de copy para art08:
 * - Encabezado principal: 10-14 palabras combinando servicio, ventaja y localización.
 * - Encabezados secundarios: 6-9 palabras cada uno, orientados a procesos o retornos.
 * - Párrafos de bloque: 30-36 palabras con detalles técnicos, financieros y métricas.
 * - Atributos alt/title de imágenes: 8-12 palabras describiendo escena y propósito.
 */
function controller_art08(int $i = 0, array $params = []): string
{
    $pad        = sprintf('%02d', $i);
    $letters    = range('a', 'z');
    $itemsCount = (int)($params['items'] ?? 0);
    $itemsHtml  = '';

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];

    $itemTpl = <<<'HTML'
        <div class="art08-block">
            <div class="art08-img">
                <img class="{X-img-dl}" data-lang="{X-img-dl}" src="{X-img-src}" alt="{X-img-alt}" title="{X-img-title}">
            </div>

            <div class="art08-card">
                {X-header-secondary}
                <p data-lang="{X-p-dl}">{X-p-text}</p>
                {X-button-primary}
            </div>
        </div>
    HTML;

    for ($j = 0; $j < $itemsCount && $j < count($letters); $j++) {
        $letter       = $letters[$j];
        $headerVar    = "art08_{$pad}_headerSecondary_{$letter}";
        $imgVar       = "art08_{$pad}_{$letter}_img";
        $paragraphVar = "art08_{$pad}_{$letter}_p";

        $buttonKey = '{' . $letter . '-button-primary}';
        $buttonVal = $params[$buttonKey] ?? '';
        unset($params[$buttonKey]);

        $itemsHtml .= str_replace(
            [
                '{X-img-dl}',
                '{X-img-src}',
                '{X-img-alt}',
                '{X-img-title}',
                '{X-header-secondary}',
                '{X-p-dl}',
                '{X-p-text}',
                '{X-button-primary}',
            ],
            [
                $imgVar,
                $_ENV['RAIZ'] . '/' . ($GLOBALS[$imgVar]->src ?? ''),
                $GLOBALS[$imgVar]->alt ?? '',
                $GLOBALS[$imgVar]->title ?? '',
                '<h' . $itemLevel . ' data-lang="' . $headerVar . '">' . ($GLOBALS[$headerVar]->text ?? '') . '</h' . $itemLevel . '>',
                $paragraphVar,
                $GLOBALS[$paragraphVar]->text ?? '',
                $buttonVal,
            ],
            $itemTpl
        );
    }

    unset($params['items']);

    $vars = [
        '{classVar}'       => "art08_{$pad}_classVar",
        '{header-primary}' => '<h' . $baseLevel . ' data-lang="art08_' . $pad . '_headerPrimary">' . ($GLOBALS["art08_{$pad}_headerPrimary"]->text ?? '') . '</h' . $baseLevel . '>',
        '{items}'          => $itemsHtml,
    ];

    $vars = array_replace($vars, $params);
    return render('App/templates/_art08.html', $vars);
}
?>
