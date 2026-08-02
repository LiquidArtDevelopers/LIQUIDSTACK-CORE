<?php
/**
 * Directrices de copy para moduleFormAuthRecover01:
 * - Legend: 4-8 palabras e identificador del recurso en showroom.
 * - Intro: 22-38 palabras; label 1-3; ayuda 8-16.
 * - CTA principal: 2-5 palabras; acción secundaria: 3-8 palabras.
 * Recurso exclusivamente visual: no intercepta submit ni fija endpoint.
 */
function controller_moduleFormAuthRecover01(
    int $i = 0,
    array $params = []
): string {
    require_once __DIR__ . '/_moduleFormAuth.php';

    return render_module_form_auth(
        'moduleFormAuthRecover01',
        $i,
        $params,
        '_moduleFormAuthRecover01.html'
    );
}
