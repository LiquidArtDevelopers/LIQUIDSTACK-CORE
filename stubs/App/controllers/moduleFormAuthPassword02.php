<?php
/**
 * Directrices de copy para moduleFormAuthPassword02:
 * - Legend: 4-8 palabras e identificador del recurso en showroom.
 * - Intro: 22-38 palabras; labels 2-5; ayudas 6-14.
 * - Cada requisito: 3-8 palabras; CTA principal: 2-5 palabras.
 * El checklist aporta feedback progresivo, pero el backend es autoritativo.
 */
function controller_moduleFormAuthPassword02(
    int $i = 0,
    array $params = []
): string {
    require_once __DIR__ . '/_moduleFormAuth02.php';

    return render_module_form_auth02(
        'moduleFormAuthPassword02',
        $i,
        $params,
        '_moduleFormAuthPassword02.html'
    );
}
