<?php

declare(strict_types=1);

/**
 * sectionBlogCatalog02: compositor Blog con filtros laterales.
 * Encabezado: 3-10 palabras. Slots: 0-1 buscador, 0-1 categorias y
 * exactamente 1 region moduleBlogResults01 ya renderizada.
 *
 * Los slots reciben exclusivamente HTML de confianza producido por otros
 * controladores LiquidStack. La seccion y su heading pertenecen a este
 * compositor; por ello los hijos deben conservar una raiz neutra.
 */
function controller_sectionBlogCatalog02(
    int $i = 0,
    array $params = []
): string {
    // El catalogo contiene el unico #blog-results reactivo del documento.
    if ($i !== 0) {
        return '';
    }

    $headerText = is_string($params['header_text'] ?? null)
        ? trim($params['header_text'])
        : '';
    if (
        $headerText === ''
        || strlen($headerText) > 320
        || preg_match('//u', $headerText) !== 1
        || preg_match('/[\p{Cc}\p{Cf}]/u', $headerText) === 1
    ) {
        return '';
    }

    $headerLevel = filter_var(
        $params['header_level'] ?? 2,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 2, 'max_range' => 5]]
    );
    if ($headerLevel === false) {
        $headerLevel = 2;
    }

    $headerLang = is_string($params['header_lang'] ?? null)
        ? trim($params['header_lang'])
        : '';
    if (
        $headerLang !== ''
        && preg_match('/\A[A-Za-z0-9_.-]+\z/', $headerLang) !== 1
    ) {
        $headerLang = '';
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

    $search = $trustedSlot($params['{search-slot}'] ?? null);
    $categories = $trustedSlot($params['{categories-slot}'] ?? null);
    $results = $trustedSlot($params['{results-slot}'] ?? null);
    if (
        $results === ''
        || preg_match(
            '/\A<div\b(?=[^>]*\bid="blog-results")'
                . '(?=[^>]*\bclass="[^"]*\bmoduleBlogResults01\b[^"]*")'
                . '(?=[^>]*\bdata-blog-results(?:\s|>))[^>]*>/i',
            $results
        ) !== 1
        || preg_match('/\A<div\b[^>]*\brole\s*=/i', $results) === 1
        || preg_match(
            '/<(?:section|nav)\b/i',
            implode("\n", [$search, $categories, $results])
        ) === 1
    ) {
        return '';
    }

    $escape = static fn (string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    $headingId = 'sectionBlogCatalog02-heading';
    $languageAttribute = $headerLang === ''
        ? ''
        : ' data-lang="' . $escape($headerLang) . '"';

    return render('App/templates/_sectionBlogCatalog02.html', [
        '{heading-id}' => $headingId,
        '{header-primary}' => '<h' . $headerLevel . ' id="' . $headingId
            . '"' . $languageAttribute . '>' . $escape($headerText)
            . '</h' . $headerLevel . '>',
        '{search-slot}' => $search,
        '{categories-slot}' => $categories,
        '{results-slot}' => $results,
    ]);
}
