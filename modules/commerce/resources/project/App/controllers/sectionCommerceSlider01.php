<?php

declare(strict_types=1);

require_once __DIR__ . '/_moduleCommerceResources.php';

/**
 * sectionCommerceSlider01 copy ranges:
 * Heading: 2-7 words. Intro: 14-36 words. Product title: 2-8 words.
 * Product summary: 12-30 words. Action and control labels: 1-8 words.
 */
function controller_sectionCommerceSlider01(
    int $i = 0,
    array $params = []
): string {
    $pad = sprintf('%02d', max(0, $i));
    $levels = resolve_header_levels($params, '{header-primary}', 2);
    $escape = 'liquidstack_commerce_escape';
    $heading = liquidstack_commerce_text($params['header_text'] ?? null, 320);
    $intro = liquidstack_commerce_text($params['intro_text'] ?? null, 1_200);
    $labels = liquidstack_commerce_labels($params['labels'] ?? null);
    $inquiryPath = liquidstack_commerce_path($params['inquiry_path'] ?? null);
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
    if ($heading === '' || $inquiryPath === '') {
        return '';
    }

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

    $booleanOption = static function (mixed $value, bool $default): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    };
    $boundedFloat = static function (
        mixed $value,
        float $default,
        float $minimum,
        float $maximum
    ): float {
        $number = is_int($value) || is_float($value) || is_string($value)
            ? filter_var($value, FILTER_VALIDATE_FLOAT)
            : false;

        return $number === false
            ? $default
            : max($minimum, min($maximum, (float) $number));
    };
    $formatFloat = static fn (float $value): string => rtrim(
        rtrim(number_format($value, 2, '.', ''), '0'),
        '.'
    );
    $label = static function (
        array $source,
        string $key,
        string $fallback
    ): string {
        $value = trim((string) ($source[$key] ?? ''));

        return $value === '' ? $fallback : $value;
    };

    $rawItems = is_array($params['items_data'] ?? null)
        ? array_values($params['items_data'])
        : [];
    $limit = array_key_exists('items', $params)
        ? max(0, min(20, (int) $params['items']))
        : min(20, count($rawItems));
    $cards = '';
    $rendered = 0;
    $seenItems = [];
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
        if (
            $id === '' || isset($seenItems[$id]) || $path === ''
            || $title === '' || $reference === '' || $availability === ''
        ) {
            continue;
        }
        $seenItems[$id] = true;
        ++$rendered;

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
        $validVariants = [];
        $imageVariants = liquidstack_commerce_value(
            $rawItem,
            'imageVariants'
        );
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
            : '<figure class="sectionCommerceSlider01-media"><img src="'
                . $escape($imageSrc) . '" alt="' . $escape($imageAlt) . '"'
                . ($imageTitle === '' ? '' : ' title="'
                    . $escape($imageTitle) . '"')
                . ' width="' . $largestVariant['width'] . '" height="'
                . $largestVariant['height'] . '"'
                . ($srcset === '' ? '' : ' srcset="' . $escape($srcset) . '"')
                . ' sizes="(min-width: 64rem) 30vw, (min-width: 48rem) 42vw, 82vw"'
                . ' loading="lazy" decoding="async"></figure>';

        $acceptsInquiries = liquidstack_commerce_value(
            $rawItem,
            'acceptsInquiries'
        ) === true;
        $isSelected = isset($selectedItems[$id]);
        $addLabel = $label($labels, 'add', 'Añadir a la lista');
        $addedLabel = $label($labels, 'added', $addLabel);
        $canSubmit = $acceptsInquiries && $addPath !== ''
            && $removePath !== '' && $returnTo !== '' && $locale !== '';
        $fixtureAttribute = ($labels['development_fixture'] ?? '0') === '1'
            ? ' data-commerce-development-fixture'
            : '';
        $addControl = $canSubmit
            ? '<form method="post" action="'
                . $escape($isSelected ? $removePath : $addPath) . '"'
                . $fixtureAttribute . ' data-commerce-interest-form>'
                . '<input type="hidden" name="product" value="'
                . $escape($id) . '"><input type="hidden" name="locale" value="'
                . $escape($locale) . '"><input type="hidden" name="return_to" value="'
                . $escape($returnTo) . '"><button type="submit"'
                . ' data-commerce-interest-add data-item-id="' . $escape($id)
                . '" data-default-label="' . $escape($addLabel)
                . '" data-added-label="' . $escape($addedLabel)
                . '" aria-pressed="' . ($isSelected ? 'true' : 'false')
                . '" aria-label="' . $escape(
                    ($isSelected ? $addedLabel : $addLabel) . ': ' . $title
                ) . '">' . $escape($isSelected ? $addedLabel : $addLabel)
                . '</button></form>'
            : '<button type="button" disabled aria-disabled="true">'
                . $escape($label(
                    $labels,
                    'inquiry_unavailable',
                    'Consulta no disponible'
                )) . '</button>';
        $itemHeadingId = "sectionCommerceSlider01-{$pad}-{$id}-heading";
        $summaryHtml = $summary === '' ? '' : '<p class="sectionCommerceSlider01-summary">'
            . $escape($summary) . '</p>';
        $commercialHtml = $commercial === '' ? ''
            : '<div><dt>' . $escape($label($labels, 'commercial', 'Condición comercial'))
                . '</dt><dd>' . $escape($commercial) . '</dd></div>';
        $cards .= '<article class="sectionCommerceSlider01-item"'
            . ' data-commerce-product-card data-commerce-item-id="' . $escape($id)
            . '" aria-labelledby="' . $escape($itemHeadingId) . '">'
            . $media . '<div class="sectionCommerceSlider01-cardBody">'
            . '<p class="sectionCommerceSlider01-reference">'
            . $escape($label($labels, 'reference', 'Referencia')) . ': '
            . $escape($reference) . '</p><h' . $levels['child'] . ' id="'
            . $escape($itemHeadingId) . '"><a href="' . $escape($path) . '">'
            . $escape($title) . '</a></h' . $levels['child'] . '>'
            . $summaryHtml . '<dl><div><dt>'
            . $escape($label($labels, 'availability', 'Disponibilidad'))
            . '</dt><dd>' . $escape($availability) . '</dd></div>'
            . $commercialHtml . '</dl><div class="sectionCommerceSlider01-actions">'
            . '<a href="' . $escape($path) . '">'
            . $escape($label($labels, 'detail', 'Ver ficha')) . '</a>'
            . $addControl . '</div></div></article>';
    }

    if ($rendered === 0) {
        return '';
    }

    $rootId = "sectionCommerceSlider01-{$pad}";
    $autoplay = $booleanOption($params['autoplay'] ?? null, true);
    $autoplayDelay = $boundedFloat(
        $params['autoplay_delay'] ?? null,
        6.0,
        2.0,
        60.0
    );
    $transitionDuration = $boundedFloat(
        $params['transition_duration'] ?? null,
        1.0,
        0.1,
        5.0
    );

    return render('App/templates/_sectionCommerceSlider01.html', [
        '{root-id}' => $rootId,
        '{heading-id}' => $rootId . '-heading',
        '{viewport-id}' => $rootId . '-viewport',
        '{items-count}' => (string) $rendered,
        '{header-tag}' => 'h' . $levels['base'],
        '{header-text}' => $escape($heading),
        '{intro}' => $intro === '' ? '' : '<p>' . $escape($intro) . '</p>',
        '{items}' => $cards,
        '{viewport-tabindex}' => $rendered > 1 ? ' tabindex="0"' : '',
        '{previous-label}' => $escape($label($labels, 'previous', 'Ver productos anteriores')),
        '{next-label}' => $escape($label($labels, 'next', 'Ver productos siguientes')),
        '{pause-label}' => $escape($label($labels, 'pause', 'Pausar reproducción automática')),
        '{resume-label}' => $escape($label($labels, 'resume', 'Reanudar reproducción automática')),
        '{autoplay}' => $autoplay ? 'true' : 'false',
        '{autoplay-delay}' => $formatFloat($autoplayDelay),
        '{transition-duration}' => $formatFloat($transitionDuration),
        '{inquiry-path}' => $escape($inquiryPath),
        '{selected-items}' => $escape(implode(',', array_keys($selectedItems))),
        '{added-status}' => $escape($label($labels, 'added_status', 'Producto añadido a la lista.')),
        '{removed-status}' => $escape($label($labels, 'removed_status', 'Producto retirado de la lista.')),
        '{interest-count}' => (string) count($selectedItems),
        '{interest-label}' => $escape($label($labels, 'interest', 'Ver lista de interés')),
        '{interest-summary}' => $escape($label($labels, 'interest_summary', 'productos seleccionados')),
    ]);
}
