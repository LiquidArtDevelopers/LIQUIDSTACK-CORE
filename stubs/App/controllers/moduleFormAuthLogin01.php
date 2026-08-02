<?php
/**
 * Directrices de copy para moduleFormAuthLogin01:
 * - Legend: 4-8 palabras e identificador del recurso en showroom.
 * - Intro: 18-32 palabras; labels 1-3; ayudas 6-14.
 * - CTA principal: 1-4 palabras; acción secundaria: 5-12 palabras.
 * Recurso exclusivamente visual: no intercepta submit ni fija endpoint.
 */
function controller_moduleFormAuthLogin01(
    int $i = 0,
    array $params = []
): string {
    require_once __DIR__ . '/_moduleFormAuth.php';

    return render_module_form_auth(
        'moduleFormAuthLogin01',
        $i,
        $params,
        '_moduleFormAuthLogin01.html'
    );
}
