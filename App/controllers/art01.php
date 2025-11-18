<?php
/**
 * Directrices de copy para art01:
 * - Encabezado principal: 50‑70 caracteres con foco en servicios.
 * - Intro: 35‑55 palabras conectando sectores y zonas clave.
 * - Párrafos de cards: 25‑40 palabras cada uno, con beneficios accionables.
 * Usa <b> o <br> con moderación para resaltar puntos críticos.
 */
function controller_art01(int $i = 0, array $params = []): string
{
    $pad        = sprintf('%02d', $i);
    $letters    = range('a', 'z');
    $itemsCount = (int)($params['items'] ?? 0);
    $itemsHtml  = '';

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];

    $itemTpl = <<<'HTML'
        <div class="art01-card">
            <img data-lang="{X-img-dl}" src="{X-img-src}" alt="{X-img-alt}" title="{X-img-title}" width="40" height="40">
            {X-header-secondary}
            <p data-lang="{X-p-dl}">{X-p-text}</p>
            {X-button-secondary}
        </div>
    HTML;

    for ($j = 0; $j < $itemsCount && $j < count($letters); $j++) {
        $letter    = $letters[$j];
        $headerVar = "art01_{$pad}_headerSecondary_{$letter}";
        $imgVar    = "art01_{$pad}_{$letter}_img";
        $pVar      = "art01_{$pad}_{$letter}_p";

        $buttonKey = '{' . $letter . '-button-secondary}';
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
                '{X-button-secondary}',
            ],
            [
                $imgVar,
                $_ENV['RAIZ'] . '/' . ($GLOBALS[$imgVar]->src ?? ''),
                $GLOBALS[$imgVar]->alt ?? '',
                $GLOBALS[$imgVar]->title ?? '',
                '<h' . $itemLevel . ' data-lang="' . $headerVar . '">' . ($GLOBALS[$headerVar]->text ?? '') . '</h' . $itemLevel . '>',
                $pVar,
                $GLOBALS[$pVar]->text ?? '',
                $buttonVal,
            ],
            $itemTpl
        );
    }

    $vars = [
        '{classVar}'       => "art01_{$pad}_classVar",
        '{header-primary}' => '<h' . $baseLevel . ' data-lang="art01_' . $pad . '_headerPrimary">' . ($GLOBALS["art01_{$pad}_headerPrimary"]->text ?? '') . '</h' . $baseLevel . '>',
        '{intro-p-dl}'     => "art01_{$pad}_intro_p",
        '{intro-p-text}'   => $GLOBALS["art01_{$pad}_intro_p"]->text ?? '',
        '{items}'          => $itemsHtml,
        '{button-primary}' => '',
        '{hero-img-dl}'    => "art01_{$pad}_hero_img",
        '{hero-img-src}'   => $_ENV['RAIZ'] . '/' . ($GLOBALS["art01_{$pad}_hero_img"]->src ?? ''),
        '{hero-img-alt}'   => $GLOBALS["art01_{$pad}_hero_img"]->alt ?? '',
        '{hero-img-title}' => $GLOBALS["art01_{$pad}_hero_img"]->title ?? '',
    ];

    unset($params['items']);

    $vars = array_replace($vars, $params);
    return render('App/templates/_art01.html', $vars);
}
?>
