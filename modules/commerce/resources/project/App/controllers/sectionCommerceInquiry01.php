<?php

declare(strict_types=1);

/**
 * sectionCommerceInquiry01 copy ranges:
 * Heading: 2-7 words. Intro: 18-42 words. Labels: 1-5 words.
 * Privacy help: 10-28 words. Status messages: 8-24 words.
 */
function controller_sectionCommerceInquiry01(
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
    $action = liquidstack_commerce_path($params['action'] ?? null);
    $removePath = liquidstack_commerce_path(
        $params['basket_remove_path']
            ?? '/_liquidstack/commerce/basket/remove'
    );
    $returnTo = liquidstack_commerce_path(
        $params['return_to'] ?? null
    );
    $locale = liquidstack_commerce_token($params['locale'] ?? null);
    $labels = liquidstack_commerce_labels($params['labels'] ?? null);
    $fixtureAttribute = ($labels['development_fixture'] ?? '0') === '1'
        ? ' data-commerce-development-fixture'
        : '';
    if (
        $heading === '' || $intro === '' || $action === ''
        || $removePath === '' || $returnTo === '' || $locale === ''
    ) {
        return '';
    }

    $rawItems = is_array($params['items_data'] ?? null)
        ? array_values($params['items_data'])
        : [];
    $limit = array_key_exists('items', $params)
        ? max(0, min(20, (int) $params['items']))
        : min(20, count($rawItems));
    $items = '';
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
        $reference = liquidstack_commerce_text(
            liquidstack_commerce_value($rawItem, 'reference'),
            120
        );
        if ($id === '' || $path === '' || $title === '' || $reference === '') {
            continue;
        }
        ++$rendered;
        $itemHeadingId = "sectionCommerceInquiry01-{$pad}-{$id}-heading";
        $items .= '<li data-commerce-interest-row data-item-id="'
            . liquidstack_commerce_escape($id) . '"><div><h'
            . $levels['child'] . ' id="'
            . liquidstack_commerce_escape($itemHeadingId) . '"><a href="'
            . liquidstack_commerce_escape($path) . '">'
            . liquidstack_commerce_escape($title) . '</a></h'
            . $levels['child'] . '><p>'
            . liquidstack_commerce_escape($reference)
            . '</p></div><form action="'
            . liquidstack_commerce_escape($removePath)
            . '" method="post"' . $fixtureAttribute
            . '><input type="hidden" name="product" value="'
            . liquidstack_commerce_escape($id)
            . '"><input type="hidden" name="locale" value="'
            . liquidstack_commerce_escape($locale)
            . '"><input type="hidden" name="return_to" value="'
            . liquidstack_commerce_escape($returnTo)
            . '"><button type="submit" data-commerce-interest-remove'
            . ' aria-label="'
            . liquidstack_commerce_escape(
                ($labels['remove'] ?? '') . ': ' . $title
            ) . '">' . liquidstack_commerce_escape(
                $labels['remove'] ?? ''
            ) . '</button></form></li>';
    }

    $rootId = "sectionCommerceInquiry01-{$pad}";
    $headingId = $rootId . '-heading';
    $formId = $rootId . '-form';
    $privacyHelpId = $formId . '-privacy-help';
    $operationBytes = random_bytes(16);
    $operationBytes[6] = chr((ord($operationBytes[6]) & 0x0f) | 0x40);
    $operationBytes[8] = chr((ord($operationBytes[8]) & 0x3f) | 0x80);
    $operationHex = bin2hex($operationBytes);
    $operationId = substr($operationHex, 0, 8) . '-'
        . substr($operationHex, 8, 4) . '-'
        . substr($operationHex, 12, 4) . '-'
        . substr($operationHex, 16, 4) . '-'
        . substr($operationHex, 20);
    return render('App/templates/_sectionCommerceInquiry01.html', [
        '{root-id}' => $rootId,
        '{heading-id}' => $headingId,
        '{header-tag}' => 'h' . $levels['base'],
        '{child-tag}' => 'h' . $levels['child'],
        '{header-text}' => liquidstack_commerce_escape($heading),
        '{intro-text}' => liquidstack_commerce_escape($intro),
        '{list-heading}' => liquidstack_commerce_escape(
            $labels['list_heading'] ?? ''
        ),
        '{items}' => $items,
        '{empty-hidden}' => $rendered === 0 ? '' : ' hidden',
        '{empty-text}' => liquidstack_commerce_escape(
            $labels['empty'] ?? ''
        ),
        '{form-heading}' => liquidstack_commerce_escape(
            $labels['form_heading'] ?? ''
        ),
        '{form-id}' => $formId,
        '{action}' => liquidstack_commerce_escape($action),
        '{name-label}' => liquidstack_commerce_escape(
            $labels['name'] ?? ''
        ),
        '{email-label}' => liquidstack_commerce_escape(
            $labels['email'] ?? ''
        ),
        '{phone-label}' => liquidstack_commerce_escape(
            $labels['phone'] ?? ''
        ),
        '{message-label}' => liquidstack_commerce_escape(
            $labels['message'] ?? ''
        ),
        '{privacy-label}' => liquidstack_commerce_escape(
            $labels['privacy'] ?? ''
        ),
        '{privacy-help-id}' => $privacyHelpId,
        '{privacy-help}' => liquidstack_commerce_escape(
            $labels['privacy_help'] ?? ''
        ),
        '{submit-label}' => liquidstack_commerce_escape(
            $labels['submit'] ?? ''
        ),
        '{reset-label}' => liquidstack_commerce_escape(
            $labels['reset'] ?? ''
        ),
        '{notice}' => liquidstack_commerce_escape(
            $labels['notice'] ?? ''
        ),
        '{success-message}' => liquidstack_commerce_escape(
            $labels['success'] ?? ''
        ),
        '{invalid-message}' => liquidstack_commerce_escape(
            $labels['invalid'] ?? ''
        ),
        '{empty-error}' => liquidstack_commerce_escape(
            $labels['empty_error'] ?? ''
        ),
        '{locale}' => liquidstack_commerce_escape($locale),
        '{return-to}' => liquidstack_commerce_escape($returnTo),
        '{operation-id}' => liquidstack_commerce_escape($operationId),
        '{fixture-attribute}' => $fixtureAttribute,
    ]);
}
