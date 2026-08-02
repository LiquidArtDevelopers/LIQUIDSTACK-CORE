<?php
/**
 * Directrices de copy para moduleFormAuthPassword01:
 * - Legend: 4-8 palabras e identificador del recurso en showroom.
 * - Intro: 22-38 palabras; labels 2-5; ayudas 6-14.
 * - Requisito de longitud: 5-10 palabras; CTA principal: 2-5 palabras.
 * Recurso exclusivamente visual: no valida ni envía; el backend conserva el contrato.
 */
function controller_moduleFormAuthPassword01(
    int $i = 0,
    array $params = []
): string {
    require_once __DIR__ . '/_moduleFormAuth.php';

    return render_module_form_auth(
        'moduleFormAuthPassword01',
        $i,
        $params,
        '_moduleFormAuthPassword01.html'
    );
}
