<?php

declare(strict_types=1);

/**
 * sectionCommerceCatalog01 copy ranges:
 * Heading: 2-7 words. Intro: 18-42 words. Card title: 2-8 words.
 * Card summary: 14-32 words. Action labels: 1-5 words.
 */
function controller_sectionCommerceCatalog01(
    int $i = 0,
    array $params = []
): string {
    require_once __DIR__ . '/_moduleCommerceResources.php';

    $pad = sprintf('%02d', max(0, $i));
    $levels = resolve_header_levels($params, '{header-primary}', 2);
    $heading = liquidstack_commerce_text(
        $params['header_text'] ?? null,
        320
    );
    $intro = liquidstack_commerce_text(
        $params['intro_text'] ?? null,
        1_200
    );
    $inquiryPath = liquidstack_commerce_path(
        $params['inquiry_path'] ?? null
    );
    $labels = liquidstack_commerce_labels($params['labels'] ?? null);
    $addPath = liquidstack_commerce_path(
        $params['basket_add_path'] ?? '/_liquidstack/commerce/basket/add'
    );
    $removePath = liquidstack_commerce_path(
        $params['basket_remove_path']
            ?? '/_liquidstack/commerce/basket/remove'
    );
    $returnTo = liquidstack_commerce_path(
        $params['return_to'] ?? $inquiryPath
    );
    $locale = liquidstack_commerce_token($params['locale'] ?? null);
    $catalogPath = liquidstack_commerce_path(
        $params['catalog_path'] ?? $returnTo
    );
    $selectedItems = [];
    foreach (
        is_array($params['selected_items'] ?? null)
            ? $params['selected_items']
            : []
        as $selectedItem
    ) {
        $selectedItem = liquidstack_commerce_token($selectedItem);
        if ($selectedItem !== '') {
            $selectedItems[$selectedItem] = true;
        }
    }
    $query = $params['query'] ?? null;
    $search = liquidstack_commerce_text(
        liquidstack_commerce_value($query, 'search'),
        100
    );
    $selectedCategory = liquidstack_commerce_token(
        liquidstack_commerce_value($query, 'category')
    );
    $selectedTag = liquidstack_commerce_token(
        liquidstack_commerce_value($query, 'tag')
    );
    $buildOptions = static function (
        mixed $rawOptions,
        string $selected,
        string $allLabel
    ): string {
        $options = '<option value="">'
            . liquidstack_commerce_escape($allLabel) . '</option>';
        if (!is_array($rawOptions)) {
            return $options;
        }
        foreach (array_slice(array_values($rawOptions), 0, 100) as $option) {
            $value = liquidstack_commerce_token(
                liquidstack_commerce_value($option, 'value')
            );
            $label = liquidstack_commerce_text(
                liquidstack_commerce_value($option, 'label'),
                160
            );
            if ($value === '' || $label === '') {
                continue;
            }
            $options .= '<option value="'
                . liquidstack_commerce_escape($value) . '"'
                . ($value === $selected ? ' selected' : '') . '>'
                . liquidstack_commerce_escape($label) . '</option>';
        }

        return $options;
    };
    $categoryData = is_array($params['category_options'] ?? null)
        ? $params['category_options']
        : [];
    $tagData = is_array($params['tag_options'] ?? null)
        ? $params['tag_options']
        : [];
    $categoryOptions = $buildOptions(
        $categoryData,
        $selectedCategory,
        $labels['all'] ?? ''
    );
    $tagOptions = $buildOptions(
        $tagData,
        $selectedTag,
        $labels['all'] ?? ''
    );
    if (
        $heading === '' || $intro === '' || $inquiryPath === ''
        || $catalogPath === ''
    ) {
        return '';
    }

    $rawItems = is_array($params['items_data'] ?? null)
        ? array_values($params['items_data'])
        : [];
    $limit = array_key_exists('items', $params)
        ? max(0, min(12, (int) $params['items']))
        : min(12, count($rawItems));
    $cards = '';
    $rendered = 0;
    foreach (array_slice($rawItems, 0, $limit) as $rawItem) {
        $id = liquidstack_commerce_token(
            liquidstack_commerce_value($rawItem, 'id')
        );
        $path = liquidstack_commerce_path(
            liquidstack_commerce_value($rawItem, 'path')
        );
        $title = liquidstack_commerce_text(
            liquidstack_commerce_value($rawItem, 'title'),
            320
        );
        $summary = liquidstack_commerce_text(
            liquidstack_commerce_value($rawItem, 'summary'),
            1_200
        );
        $reference = liquidstack_commerce_text(
            liquidstack_commerce_value($rawItem, 'reference'),
            120
        );
        $availability = liquidstack_commerce_text(
            liquidstack_commerce_value($rawItem, 'availability'),
            160
        );
        $commercial = liquidstack_commerce_text(
            liquidstack_commerce_value($rawItem, 'commercialLabel'),
            160
        );
        $imageSrc = liquidstack_commerce_path(
            liquidstack_commerce_value($rawItem, 'imageSrc')
        );
        $imageAlt = liquidstack_commerce_text(
            liquidstack_commerce_value($rawItem, 'imageAlt'),
            500
        );
        $imageTitle = liquidstack_commerce_text(
            liquidstack_commerce_value($rawItem, 'imageTitle'),
            500
        );
        $imageVariants = liquidstack_commerce_value(
            $rawItem,
            'imageVariants'
        );
        $acceptsInquiries = liquidstack_commerce_value(
            $rawItem,
            'acceptsInquiries'
        ) === true;
        if (
            $id === '' || $path === '' || $title === ''
            || $reference === '' || $availability === ''
            || $addPath === '' || $returnTo === '' || $locale === ''
        ) {
            continue;
        }

        ++$rendered;
        $headingId = "sectionCommerceCatalog01-{$pad}-{$id}-heading";
        $detailLabel = $labels['detail'] ?? '';
        $addLabel = $labels['add'] ?? '';
        $addedLabel = $labels['added'] ?? $addLabel;
        $isSelected = isset($selectedItems[$id]);
        $validVariants = [];
        foreach (is_array($imageVariants) ? $imageVariants : [] as $variant) {
            $variantPath = liquidstack_commerce_path($variant['path'] ?? null);
            $variantWidth = is_int($variant['width'] ?? null)
                ? $variant['width']
                : 0;
            $variantHeight = is_int($variant['height'] ?? null)
                ? $variant['height']
                : 0;
            if (
                $variantPath !== '' && $variantWidth > 0
                && $variantHeight > 0
            ) {
                $validVariants[] = [
                    'path' => $variantPath,
                    'width' => $variantWidth,
                    'height' => $variantHeight,
                ];
            }
        }
        $largestVariant = $validVariants === []
            ? ['width' => 1200, 'height' => 800]
            : $validVariants[array_key_last($validVariants)];
        $srcset = implode(', ', array_map(
            static fn (array $variant): string =>
                $variant['path'] . ' ' . $variant['width'] . 'w',
            $validVariants
        ));
        $media = $imageSrc === '' ? ''
            : '<figure class="sectionCommerceCatalog01-media"><img src="'
                . liquidstack_commerce_escape($imageSrc) . '" alt="'
                . liquidstack_commerce_escape($imageAlt) . '"'
                . ($imageTitle === '' ? '' : ' title="'
                    . liquidstack_commerce_escape($imageTitle) . '"')
                . ' width="' . $largestVariant['width'] . '" height="'
                . $largestVariant['height'] . '"'
                . ($srcset === '' ? '' : ' srcset="'
                    . liquidstack_commerce_escape($srcset) . '"')
                . ' sizes="(min-width: 64rem) 33vw, (min-width: 48rem) 50vw, 100vw"'
                . ' loading="lazy"'
                . ' decoding="async"></figure>';
        $summaryHtml = $summary === '' ? ''
            : '<p>' . liquidstack_commerce_escape($summary) . '</p>';
        $commercialHtml = $commercial === '' ? ''
            : '<div><dt class="sectionCommerceCatalog01-srOnly">'
                . liquidstack_commerce_escape($labels['commercial'] ?? '')
                . '</dt><dd>' . liquidstack_commerce_escape($commercial)
                . '</dd></div>';
        $fixtureAttribute = ($labels['development_fixture'] ?? '0') === '1'
            ? ' data-commerce-development-fixture'
            : '';
        $addControl = $acceptsInquiries
            ? '<form method="post" action="'
                . liquidstack_commerce_escape(
                    $isSelected ? $removePath : $addPath
                ) . '"'
                . $fixtureAttribute . ' data-commerce-interest-form>'
                . '<input type="hidden" name="product" value="'
                . liquidstack_commerce_escape($id) . '">'
                . '<input type="hidden" name="locale" value="'
                . liquidstack_commerce_escape($locale) . '">'
                . '<input type="hidden" name="return_to" value="'
                . liquidstack_commerce_escape($returnTo) . '">'
                . '<button type="submit" data-commerce-interest-add'
                . ' data-item-id="' . liquidstack_commerce_escape($id) . '"'
                . ' data-default-label="'
                . liquidstack_commerce_escape($addLabel) . '"'
                . ' data-added-label="'
                . liquidstack_commerce_escape($addedLabel) . '"'
                . ' aria-pressed="' . ($isSelected ? 'true' : 'false')
                . '" aria-label="'
                . liquidstack_commerce_escape(
                    ($isSelected ? $addedLabel : $addLabel) . ': ' . $title
                ) . '">' . liquidstack_commerce_escape(
                    $isSelected ? $addedLabel : $addLabel
                )
                . '</button></form>'
            : '<button type="button" disabled aria-disabled="true">'
                . liquidstack_commerce_escape(
                    $labels['inquiry_unavailable'] ?? ''
                ) . '</button>';
        $cards .= '<article class="sectionCommerceCatalog01-card"'
            . ' data-commerce-product-card data-commerce-item-id="'
            . liquidstack_commerce_escape($id) . '" aria-labelledby="'
            . liquidstack_commerce_escape($headingId) . '">'
            . $media . '<div class="sectionCommerceCatalog01-cardBody">'
            . '<p class="sectionCommerceCatalog01-reference">'
            . liquidstack_commerce_escape($labels['reference'] ?? '')
            . ': ' . liquidstack_commerce_escape($reference) . '</p>'
            . '<h' . $levels['child'] . ' id="'
            . liquidstack_commerce_escape($headingId) . '"><a href="'
            . liquidstack_commerce_escape($path) . '">'
            . liquidstack_commerce_escape($title) . '</a></h'
            . $levels['child'] . '>' . $summaryHtml
            . '<dl><div><dt>'
            . liquidstack_commerce_escape($labels['availability'] ?? '')
            . '</dt><dd>'
            . liquidstack_commerce_escape($availability)
            . '</dd></div>' . $commercialHtml
            . '</dl><div class="sectionCommerceCatalog01-actions">'
            . '<a class="sectionCommerceCatalog01-detail" href="'
            . liquidstack_commerce_escape($path) . '">'
            . liquidstack_commerce_escape($detailLabel) . '</a>'
            . $addControl . '</div></div></article>';
    }

    $rootId = "sectionCommerceCatalog01-{$pad}";
    $headingId = $rootId . '-heading';
    $emptyHidden = $rendered === 0 ? '' : ' hidden';

    return render('App/templates/_sectionCommerceCatalog01.html', [
        '{root-id}' => $rootId,
        '{heading-id}' => $headingId,
        '{item-count}' => (string) $rendered,
        '{header-tag}' => 'h' . $levels['base'],
        '{header-text}' => liquidstack_commerce_escape($heading),
        '{intro-text}' => liquidstack_commerce_escape($intro),
        '{items}' => $cards,
        '{empty-hidden}' => $emptyHidden,
        '{empty-text}' => liquidstack_commerce_escape(
            $labels['empty'] ?? ''
        ),
        '{inquiry-path}' => liquidstack_commerce_escape($inquiryPath),
        '{interest-label}' => liquidstack_commerce_escape(
            $labels['interest'] ?? ''
        ),
        '{interest-summary}' => liquidstack_commerce_escape(
            $labels['interest_summary'] ?? ''
        ),
        '{added-status}' => liquidstack_commerce_escape(
            $labels['added_status'] ?? ''
        ),
        '{removed-status}' => liquidstack_commerce_escape(
            $labels['removed_status'] ?? ''
        ),
        '{catalog-path}' => liquidstack_commerce_escape($catalogPath),
        '{search-label}' => liquidstack_commerce_escape(
            $labels['search_label'] ?? ''
        ),
        '{search-placeholder}' => liquidstack_commerce_escape(
            $labels['search_placeholder'] ?? ''
        ),
        '{search-value}' => liquidstack_commerce_escape($search),
        '{category-label}' => liquidstack_commerce_escape(
            $labels['category_label'] ?? ''
        ),
        '{tag-label}' => liquidstack_commerce_escape(
            $labels['tag_label'] ?? ''
        ),
        '{category-options}' => $categoryOptions,
        '{tag-options}' => $tagOptions,
        '{selected-items}' => liquidstack_commerce_escape(
            implode(',', array_keys($selectedItems))
        ),
        '{interest-count}' => (string) count($selectedItems),
        '{category-disabled}' => $categoryData === [] ? ' disabled' : '',
        '{tag-disabled}' => $tagData === [] ? ' disabled' : '',
        '{filter-submit}' => liquidstack_commerce_escape(
            $labels['filter_submit'] ?? ''
        ),
        '{filter-clear}' => liquidstack_commerce_escape(
            $labels['filter_clear'] ?? ''
        ),
    ]);
}
