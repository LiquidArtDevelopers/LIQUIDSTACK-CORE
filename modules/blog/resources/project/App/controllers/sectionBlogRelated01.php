<?php

declare(strict_types=1);

require_once __DIR__ . '/_moduleBlogResources.php';

/**
 * sectionBlogRelated01: entradas relacionadas con el contenido actual.
 * Encabezado: 3-7 palabras. Títulos: 5-12 palabras.
 * Extractos: 18-35 palabras. Sin entradas válidas no se renderiza la sección.
 */
function controller_sectionBlogRelated01(
    int $i = 0,
    array $params = []
): string {
    $context = liquidstack_blog_resource_context(
        'sectionBlogRelated01',
        $i,
        $params,
        2,
        12
    );
    if ($context['items'] === []) {
        return '';
    }

    $items = '';
    foreach ($context['items'] as $item) {
        $items .= liquidstack_blog_resource_card($context, $item);
    }

    return render('App/templates/_sectionBlogRelated01.html', [
        '{section-id}' => liquidstack_blog_resource_escape($context['id']),
        '{heading-id}' => liquidstack_blog_resource_escape(
            $context['heading_id']
        ),
        '{classVar}' => liquidstack_blog_resource_escape(
            $context['class_var']
        ),
        '{items-count}' => (string) count($context['items']),
        '{header-primary}' => liquidstack_blog_resource_heading($context),
        '{items}' => $items,
    ]);
}
