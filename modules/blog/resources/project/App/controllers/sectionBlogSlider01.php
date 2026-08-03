<?php

declare(strict_types=1);

require_once __DIR__ . '/_moduleBlogResources.php';

/**
 * sectionBlogSlider01: carrusel progresivo; sin JS conserva scroll horizontal.
 * Títulos: 5-12 palabras. Extractos: 24-45 palabras.
 */
function controller_sectionBlogSlider01(
    int $i = 0,
    array $params = []
): string {
    $context = liquidstack_blog_resource_context(
        'sectionBlogSlider01',
        $i,
        $params,
        2,
        20
    );
    $items = '';
    foreach ($context['items'] as $item) {
        $items .= liquidstack_blog_resource_card($context, $item);
    }

    $previousLabel = trim((string) (
        $params['previous_label'] ?? 'Ver entradas anteriores'
    ));
    $nextLabel = trim((string) (
        $params['next_label'] ?? 'Ver entradas siguientes'
    ));

    return render('App/templates/_sectionBlogSlider01.html', [
        '{section-id}' => liquidstack_blog_resource_escape($context['id']),
        '{heading-id}' => liquidstack_blog_resource_escape(
            $context['heading_id']
        ),
        '{classVar}' => liquidstack_blog_resource_escape(
            $context['class_var']
        ),
        '{items-count}' => (string) count($context['items']),
        '{header-primary}' => liquidstack_blog_resource_heading($context),
        '{previous-label}' => liquidstack_blog_resource_escape($previousLabel),
        '{next-label}' => liquidstack_blog_resource_escape($nextLabel),
        '{items}' => $items,
    ]);
}
