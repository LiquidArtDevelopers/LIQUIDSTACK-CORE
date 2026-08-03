<?php
/**
 * Directrices de copy para moduleFormAuthRecover02:
 * - Legend: 4-8 palabras e identificador del recurso en showroom.
 * - Intro: 22-38 palabras; label 1-3; ayuda 8-16.
 * - CTA principal: 2-5 palabras; acción secundaria: 3-8 palabras.
 * El recurso conserva el submit HTML y no fija endpoint ni backend.
 */
function controller_moduleFormAuthRecover02(
    int $i = 0,
    array $params = []
): string {
    require_once __DIR__ . '/_moduleFormAuth02.php';

    return render_module_form_auth02(
        'moduleFormAuthRecover02',
        $i,
        $params,
        '_moduleFormAuthRecover02.html'
    );
}
