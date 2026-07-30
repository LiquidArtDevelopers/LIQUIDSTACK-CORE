<?php
/**
 * Directrices de copy para moduleFormContact01:
 * - Legend: 4-8 palabras e identificador del recurso en showroom.
 * - Intro: 22-38 palabras que expliquen el siguiente paso.
 * - Labels: 1-4 palabras; placeholders: 2-6 palabras.
 * - Consentimiento: 8-16 palabras; CTA: 2-4 palabras.
 * - Confirmación: título de 3-6 palabras y texto de 12-24 palabras.
 * El módulo no incorpora encabezados de documento ni datos de contacto.
 */
function controller_moduleFormContact01(
    int $i = 0,
    array $params = []
): string {
    require_once __DIR__ . '/_moduleFormContact.php';

    return render_module_form_contact(
        'moduleFormContact01',
        $i,
        $params,
        '_moduleFormContact01.html'
    );
}
