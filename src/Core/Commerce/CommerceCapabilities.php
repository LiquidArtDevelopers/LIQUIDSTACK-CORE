<?php

declare(strict_types=1);

namespace App\Core\Commerce;

final class CommerceCapabilities
{
    public const PRODUCTS_VIEW = 'commerce.products.view';
    public const PRODUCTS_EDIT = 'commerce.products.edit';
    public const PRODUCTS_PUBLISH = 'commerce.products.publish';
    public const PRODUCTS_ARCHIVE = 'commerce.products.archive';
    public const TAXONOMIES_VIEW = 'commerce.taxonomies.view';
    public const TAXONOMIES_EDIT = 'commerce.taxonomies.edit';
    public const INQUIRIES_VIEW = 'commerce.inquiries.view';
    public const INQUIRIES_MANAGE = 'commerce.inquiries.manage';
    public const SETTINGS_MANAGE = 'commerce.settings.manage';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PRODUCTS_VIEW,
            self::PRODUCTS_EDIT,
            self::PRODUCTS_PUBLISH,
            self::PRODUCTS_ARCHIVE,
            self::TAXONOMIES_VIEW,
            self::TAXONOMIES_EDIT,
            self::INQUIRIES_VIEW,
            self::INQUIRIES_MANAGE,
            self::SETTINGS_MANAGE,
        ];
    }
}
