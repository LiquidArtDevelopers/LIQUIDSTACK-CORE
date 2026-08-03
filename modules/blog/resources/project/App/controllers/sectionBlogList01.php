<?php

declare(strict_types=1);

require_once __DIR__ . '/_moduleBlogResources.php';

/**
 * sectionBlogList01: listado editorial de entradas.
 * Títulos: 5-12 palabras. Extractos: 24-45 palabras.
 */
function controller_sectionBlogList01(
    int $i = 0,
    array $params = []
): string {
    $context = liquidstack_blog_resource_context(
        'sectionBlogList01',
        $i,
        $params
    );
    $items = '';
    foreach ($context['items'] as $item) {
        $items .= liquidstack_blog_resource_card($context, $item);
    }

    return render('App/templates/_sectionBlogList01.html', [
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
