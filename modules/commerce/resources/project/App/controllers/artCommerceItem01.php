<?php

declare(strict_types=1);

/**
 * artCommerceItem01 copy ranges:
 * Product heading: 2-8 words. Summary: 14-32 words.
 * Description: 35-90 words. Feature values: 1-12 words.
 */
function controller_artCommerceItem01(
    int $i = 0,
    array $params = []
): string {
    require_once __DIR__ . '/_moduleCommerceResources.php';

    $pad = sprintf('%02d', max(0, $i));
    $levels = resolve_header_levels($params, '{header-primary}', 3);
    $item = $params['item_data'] ?? null;
    $labels = liquidstack_commerce_labels($params['labels'] ?? null);
    $inquiryPath = liquidstack_commerce_path(
        $params['inquiry_path'] ?? null
    );
    $catalogPath = liquidstack_commerce_path(
        $params['catalog_path'] ?? null
    );
    $addPath = liquidstack_commerce_path(
        $params['basket_add_path'] ?? '/_liquidstack/commerce/basket/add'
    );
    $removePath = liquidstack_commerce_path(
        $params['basket_remove_path']
            ?? '/_liquidstack/commerce/basket/remove'
    );
    $returnTo = liquidstack_commerce_path(
        $params['return_to'] ?? liquidstack_commerce_value($item, 'path')
    );
    $locale = liquidstack_commerce_token($params['locale'] ?? null);
    $id = liquidstack_commerce_token(
        liquidstack_commerce_value($item, 'id')
    );
    $title = liquidstack_commerce_text(
        liquidstack_commerce_value($item, 'title'),
        320
    );
    $summary = liquidstack_commerce_text(
        liquidstack_commerce_value($item, 'summary'),
        1_200
    );
    $description = liquidstack_commerce_text(
        liquidstack_commerce_value($item, 'description'),
        4_000
    );
    $reference = liquidstack_commerce_text(
        liquidstack_commerce_value($item, 'reference'),
        120
    );
    $availability = liquidstack_commerce_text(
        liquidstack_commerce_value($item, 'availability'),
        160
    );
    $commercial = liquidstack_commerce_text(
        liquidstack_commerce_value($item, 'commercialLabel'),
        160
    );
    $imageSrc = liquidstack_commerce_path(
        liquidstack_commerce_value($item, 'imageSrc')
    );
    $imageAlt = liquidstack_commerce_text(
        liquidstack_commerce_value($item, 'imageAlt'),
        500
    );
    $imageTitle = liquidstack_commerce_text(
        liquidstack_commerce_value($item, 'imageTitle'),
        500
    );
    $imageVariants = liquidstack_commerce_value($item, 'imageVariants');
    $acceptsInquiries = liquidstack_commerce_value(
        $item,
        'acceptsInquiries'
    ) === true;
    $features = liquidstack_commerce_features(
        liquidstack_commerce_value($item, 'features')
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
    if (
        $id === '' || $title === '' || $reference === ''
        || $availability === '' || $inquiryPath === ''
        || $catalogPath === '' || $addPath === '' || $returnTo === ''
        || $locale === ''
    ) {
        return '';
    }

    $featureItems = '';
    foreach ($features as $feature) {
        $featureItems .= '<div><dt>'
            . liquidstack_commerce_escape($feature['label'])
            . '</dt><dd>'
            . liquidstack_commerce_escape($feature['value'])
            . '</dd></div>';
    }
    $rootId = "artCommerceItem01-{$pad}";
    $headingId = $rootId . '-heading';
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
        if ($variantPath !== '' && $variantWidth > 0 && $variantHeight > 0) {
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
        : '<figure class="artCommerceItem01-media"><img src="'
            . liquidstack_commerce_escape($imageSrc) . '" alt="'
            . liquidstack_commerce_escape($imageAlt) . '"'
            . ($imageTitle === '' ? '' : ' title="'
                . liquidstack_commerce_escape($imageTitle) . '"')
            . ' width="' . $largestVariant['width'] . '" height="'
            . $largestVariant['height'] . '"'
            . ($srcset === '' ? '' : ' srcset="'
                . liquidstack_commerce_escape($srcset) . '"')
            . ' sizes="(min-width: 64rem) 50vw, 100vw"'
            . ' decoding="async"></figure>';
    $summaryHtml = $summary === '' ? ''
        : '<p class="artCommerceItem01-summary">'
            . liquidstack_commerce_escape($summary) . '</p>';
    $descriptionHtml = $description === '' ? ''
        : '<p>' . liquidstack_commerce_escape($description) . '</p>';
    $commercialHtml = $commercial === '' ? ''
        : '<div><dt class="artCommerceItem01-srOnly">'
            . liquidstack_commerce_escape($labels['commercial'] ?? '')
            . '</dt><dd>' . liquidstack_commerce_escape($commercial)
            . '</dd></div>';
    $featuresBlock = $featureItems === '' ? ''
        : '<div class="artCommerceItem01-features"><h'
            . $levels['child'] . '>'
            . liquidstack_commerce_escape(
                $labels['features_heading'] ?? ''
            ) . '</h' . $levels['child'] . '><dl>' . $featureItems
            . '</dl></div>';
    $fixtureAttribute = ($labels['development_fixture'] ?? '0') === '1'
        ? ' data-commerce-development-fixture'
        : '';
    $addControl = $acceptsInquiries
        ? '<form action="' . liquidstack_commerce_escape(
            $isSelected ? $removePath : $addPath
        )
            . '" method="post" data-commerce-interest-form'
            . $fixtureAttribute . '>'
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
            . liquidstack_commerce_escape(
                $addedLabel
            ) . '" aria-pressed="' . ($isSelected ? 'true' : 'false')
            . '">' . liquidstack_commerce_escape(
                $isSelected ? $addedLabel : $addLabel
            ) . '</button></form>'
        : '<button type="button" disabled aria-disabled="true">'
            . liquidstack_commerce_escape(
                $labels['inquiry_unavailable'] ?? ''
            ) . '</button>';
    $inquiryCount = is_int($params['inquiry_count'] ?? null)
        && $params['inquiry_count'] > 0
            ? $params['inquiry_count']
            : null;
    $socialProof = $inquiryCount === null ? ''
        : '<p class="artCommerceItem01-socialProof">'
            . liquidstack_commerce_escape(str_replace(
                '{count}',
                (string) $inquiryCount,
                $labels['social_proof'] ?? ''
            )) . '</p>';

    return render('App/templates/_artCommerceItem01.html', [
        '{root-id}' => $rootId,
        '{heading-id}' => $headingId,
        '{header-tag}' => 'h' . $levels['base'],
        '{title}' => liquidstack_commerce_escape($title),
        '{media}' => $media,
        '{summary-html}' => $summaryHtml,
        '{description-html}' => $descriptionHtml,
        '{reference-label}' => liquidstack_commerce_escape(
            $labels['reference'] ?? ''
        ),
        '{reference}' => liquidstack_commerce_escape($reference),
        '{availability-label}' => liquidstack_commerce_escape(
            $labels['availability'] ?? ''
        ),
        '{availability}' => liquidstack_commerce_escape($availability),
        '{commercial-html}' => $commercialHtml,
        '{features-block}' => $featuresBlock,
        '{item-id}' => liquidstack_commerce_escape($id),
        '{inquiry-path}' => liquidstack_commerce_escape($inquiryPath),
        '{catalog-path}' => liquidstack_commerce_escape($catalogPath),
        '{add-label}' => liquidstack_commerce_escape($addLabel),
        '{added-label}' => liquidstack_commerce_escape(
            $labels['added'] ?? $addLabel
        ),
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
        '{back-label}' => liquidstack_commerce_escape(
            $labels['back'] ?? ''
        ),
        '{basket-add-path}' => liquidstack_commerce_escape($addPath),
        '{return-to}' => liquidstack_commerce_escape($returnTo),
        '{locale}' => liquidstack_commerce_escape($locale),
        '{fixture-attribute}' => $fixtureAttribute,
        '{add-control}' => $addControl,
        '{social-proof}' => $socialProof,
        '{selected-items}' => liquidstack_commerce_escape(
            implode(',', array_keys($selectedItems))
        ),
        '{interest-count}' => (string) count($selectedItems),
    ]);
}
