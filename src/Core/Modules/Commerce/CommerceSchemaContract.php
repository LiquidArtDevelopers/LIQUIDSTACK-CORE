<?php

declare(strict_types=1);

namespace App\Core\Modules\Commerce;

final class CommerceSchemaContract
{
    /** @return array<string, list<string>> */
    public static function catalogTables(): array
    {
        return [
            'categories' => [
                'id', 'public_id', 'parent_id', 'sort_order', 'lock_version', 'created_at',
                'updated_at',
            ],
            'category_localizations' => [
                'id', 'category_id', 'locale', 'name', 'slug',
                'translation_status', 'lock_version', 'created_at',
                'updated_at',
            ],
            'products' => [
                'id', 'public_id', 'sku', 'editorial_status',
                'availability_status', 'price_minor', 'currency',
                'canonical_category_id', 'lock_version', 'created_at',
                'updated_at',
            ],
            'product_localizations' => [
                'id', 'product_id', 'locale', 'title', 'slug', 'summary',
                'description', 'seo_title', 'seo_description',
                'translation_status', 'public_path', 'lock_version',
                'created_at', 'updated_at',
            ],
            'product_categories' => ['product_id', 'category_id'],
            'tags' => ['id', 'public_id', 'created_at', 'updated_at'],
            'tag_localizations' => [
                'id', 'tag_id', 'locale', 'name', 'slug',
                'translation_status', 'created_at', 'updated_at',
            ],
            'product_tags' => ['product_id', 'tag_id'],
            'attributes' => [
                'id', 'public_id', 'code', 'type', 'category_id', 'unit',
                'is_filterable', 'sort_order', 'created_at', 'updated_at',
            ],
            'attribute_localizations' => [
                'id', 'attribute_id', 'locale', 'name',
                'translation_status', 'created_at', 'updated_at',
            ],
            'attribute_options' => [
                'id', 'public_id', 'attribute_id', 'code', 'sort_order',
                'created_at', 'updated_at',
            ],
            'attribute_option_localizations' => [
                'id', 'option_id', 'locale', 'label',
                'translation_status', 'created_at', 'updated_at',
            ],
            'product_attribute_values' => [
                'id', 'product_id', 'attribute_id', 'locale', 'text_value',
                'number_value', 'boolean_value', 'date_value', 'created_at',
                'updated_at',
            ],
            'product_attribute_value_options' => ['value_id', 'option_id'],
            'product_media' => [
                'id', 'product_id', 'media_asset_public_id', 'role',
                'sort_order', 'created_at', 'updated_at',
            ],
            'product_media_localizations' => [
                'id', 'media_id', 'locale', 'alt_text', 'caption',
                'translation_status', 'created_at', 'updated_at',
            ],
            'url_history' => [
                'id', 'product_localization_id', 'old_path', 'new_path',
                'created_at',
            ],
        ];
    }

    /** @return array<string, list<string>> */
    public static function engagementTables(): array
    {
        return [
            'baskets' => [
                'id', 'public_id', 'token_sha256', 'locale', 'status',
                'expires_at', 'created_at', 'updated_at',
            ],
            'basket_items' => [
                'basket_id', 'product_id', 'quantity', 'created_at',
                'updated_at',
            ],
            'inquiries' => [
                'id', 'public_id', 'operation_id', 'payload_sha256',
                'basket_id', 'locale', 'contact_name', 'email', 'phone',
                'message', 'privacy_version', 'created_at',
            ],
            'inquiry_lines' => [
                'id', 'inquiry_id', 'product_public_id', 'sku',
                'requested_locale', 'resolved_locale', 'title',
                'public_path', 'cover_media_public_id', 'quantity',
                'unit_price_minor', 'currency', 'availability_status',
                'created_at',
            ],
            'inquiry_rate_limits' => [
                'action', 'subject_hash', 'attempts', 'window_started_at',
                'updated_at',
            ],
            'inquiry_outbox' => [
                'id', 'public_id', 'inquiry_id', 'audience',
                'recipient_email', 'template_key', 'payload_json', 'status',
                'attempts', 'available_at', 'locked_at', 'lock_token',
                'sent_at', 'created_at', 'updated_at',
            ],
            'product_inquiry_stats' => [
                'product_id', 'inquiry_count', 'updated_at',
            ],
        ];
    }

    /** @return array<string, list<string>> */
    public static function allTables(): array
    {
        return self::catalogTables() + self::engagementTables();
    }
}
