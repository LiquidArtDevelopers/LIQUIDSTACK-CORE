<?php

declare(strict_types=1);

/**
 * moduleBlogResults01: compositor reactivo sin copy propio.
 * Slots opcionales: 0-1 resultado principal, 0-1 paginador y 0-1 archivo.
 *
 * Los tres slots reciben exclusivamente HTML de confianza ya renderizado por
 * controladores LiquidStack. Valores no string se descartan por completo y
 * ninguna otra clave publica puede sustituir la estructura del compositor.
 * Cada hijo debe ser un modulo de raiz neutra: este compositor rechaza
 * `section`/`nav` y sectionBlogCatalog01 repite la defensa al componerlo.
 */
function controller_moduleBlogResults01(
    int $i = 0,
    array $params = []
): string {
    // El runtime publico actual identifica una unica region canonica por
    // documento. No se publica una API multi-ID que los formularios y la
    // paginacion todavia no puedan coordinar de extremo a extremo.
    if ($i !== 0) {
        return '';
    }

    $trustedSlot = static function (mixed $value): string {
        if (!is_string($value)) {
            return '';
        }

        $html = trim($value);
        if (
            $html === ''
            || strlen($html) > 2_000_000
            || preg_match('//u', $html) !== 1
        ) {
            return '';
        }

        return $html;
    };

    $results = $trustedSlot($params['{results-slot}'] ?? null);
    $pagination = $trustedSlot($params['{pagination-slot}'] ?? null);
    $archive = $trustedSlot($params['{archive-slot}'] ?? null);
    foreach ([$results, $pagination, $archive] as $slot) {
        if (
            $slot !== ''
            && (
                preg_match('/\A<div(?:\s|>)/i', $slot) !== 1
                || preg_match('/\A<div\b[^>]*\brole\s*=/i', $slot) === 1
                || preg_match('/<(?:section|nav)\b/i', $slot) === 1
            )
        ) {
            return '';
        }
    }
    if ($results === '' && $pagination === '' && $archive === '') {
        return '';
    }

    return render('App/templates/_moduleBlogResults01.html', [
        '{results-slot}' => $results,
        '{pagination-slot}' => $pagination,
        '{archive-slot}' => $archive,
    ]);
}
