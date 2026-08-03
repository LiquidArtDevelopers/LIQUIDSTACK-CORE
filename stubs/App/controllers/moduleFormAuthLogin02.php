<?php
/**
 * Directrices de copy para moduleFormAuthLogin02:
 * - Legend: 4-8 palabras e identificador del recurso en showroom.
 * - Intro: 18-32 palabras; labels 1-3; ayudas 6-14.
 * - CTA principal: 1-4 palabras; acción secundaria: 5-12 palabras.
 * El recurso conserva el submit HTML y no fija endpoint ni backend.
 */
function controller_moduleFormAuthLogin02(
    int $i = 0,
    array $params = []
): string {
    require_once __DIR__ . '/_moduleFormAuth02.php';

    return render_module_form_auth02(
        'moduleFormAuthLogin02',
        $i,
        $params,
        '_moduleFormAuthLogin02.html'
    );
}
