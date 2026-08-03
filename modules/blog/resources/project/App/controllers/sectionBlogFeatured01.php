<?php

declare(strict_types=1);

require_once __DIR__ . '/_moduleBlogResources.php';

/**
 * sectionBlogFeatured01: primera entrada destacada y resto secundarias.
 * Títulos: 5-12 palabras. Extractos: 24-45 palabras.
 */
function controller_sectionBlogFeatured01(
    int $i = 0,
    array $params = []
): string {
    $context = liquidstack_blog_resource_context(
        'sectionBlogFeatured01',
        $i,
        $params,
        2,
        12
    );
    $featured = '';
    $secondary = '';
    foreach ($context['items'] as $position => $item) {
        $markup = liquidstack_blog_resource_card(
            $context,
            $item,
            $position === 0
                ? 'sectionBlogFeatured01-item--featured'
                : 'sectionBlogFeatured01-item--secondary'
        );
        if ($position === 0) {
            $featured = $markup;
        } else {
            $secondary .= $markup;
        }
    }

    return render('App/templates/_sectionBlogFeatured01.html', [
        '{section-id}' => liquidstack_blog_resource_escape($context['id']),
        '{heading-id}' => liquidstack_blog_resource_escape(
            $context['heading_id']
        ),
        '{classVar}' => liquidstack_blog_resource_escape(
            $context['class_var']
        ),
        '{items-count}' => (string) count($context['items']),
        '{header-primary}' => liquidstack_blog_resource_heading($context),
        '{featured-item}' => $featured,
        '{secondary-items}' => $secondary,
    ]);
}
