<?php

declare(strict_types=1);

namespace App\Core\Commerce\Admin;

use App\Core\Commerce\Persistence\CommercePersistenceException;
use App\Core\Commerce\Persistence\CommerceTableNames;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use PDO;
use PDOStatement;
use Throwable;

/** Bounded read model for the private Commerce screens. */
final class CommerceAdminReadRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CommerceTableNames $tables,
        private readonly ?WebAdminTableNames $webAdminTables = null,
        private readonly bool $mediaQuarantineEnabled = false
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function products(string $locale, string $primaryLocale): array
    {
        return $this->all(
            'SELECT p.public_id, p.sku, l.locale, p.editorial_status, '
            . 'p.availability_status, p.price_minor, p.currency, '
            . 'p.lock_version, COALESCE(NULLIF(l.title, \'\'), s.title) AS title, '
            . 'l.translation_status, p.updated_at FROM '
            . $this->tables->table('products') . ' p INNER JOIN '
            . $this->tables->table('product_localizations') . ' l '
            . 'ON l.product_id = p.id AND l.locale = :locale INNER JOIN '
            . $this->tables->table('product_localizations') . ' s '
            . 'ON s.product_id = p.id AND s.locale = :primary_locale '
            . 'ORDER BY p.updated_at DESC, p.id DESC LIMIT 200',
            ['locale' => $locale, 'primary_locale' => $primaryLocale]
        );
    }

    /** @return list<array<string, mixed>> */
    public function categories(string $locale, string $primaryLocale): array
    {
        return $this->all(
            'SELECT c.public_id, parent.public_id AS parent_public_id, l.locale, '
            . 'c.sort_order, c.lock_version, COALESCE(NULLIF(l.name, \'\'), s.name) AS name, '
            . 'COALESCE(NULLIF(l.slug, \'\'), s.slug) AS slug, '
            . 'l.translation_status FROM '
            . $this->tables->table('categories') . ' c LEFT JOIN '
            . $this->tables->table('categories') . ' parent '
            . 'ON parent.id = c.parent_id INNER JOIN '
            . $this->tables->table('category_localizations') . ' l '
            . 'ON l.category_id = c.id AND l.locale = :locale INNER JOIN '
            . $this->tables->table('category_localizations') . ' s '
            . 'ON s.category_id = c.id AND s.locale = :primary_locale '
            . 'ORDER BY c.sort_order ASC, COALESCE(NULLIF(l.name, \'\'), s.name) ASC, c.id ASC '
            . 'LIMIT 500',
            ['locale' => $locale, 'primary_locale' => $primaryLocale]
        );
    }

    /** @return list<array<string, mixed>> */
    public function tags(string $locale, string $primaryLocale): array
    {
        return $this->all(
            'SELECT t.public_id, l.locale, COALESCE(NULLIF(l.name, \'\'), s.name) AS name, '
            . 'COALESCE(NULLIF(l.slug, \'\'), s.slug) AS slug, '
            . 'l.translation_status FROM '
            . $this->tables->table('tags') . ' t INNER JOIN '
            . $this->tables->table('tag_localizations') . ' l '
            . 'ON l.tag_id = t.id AND l.locale = :locale INNER JOIN '
            . $this->tables->table('tag_localizations') . ' s '
            . 'ON s.tag_id = t.id AND s.locale = :primary_locale '
            . 'ORDER BY COALESCE(NULLIF(l.name, \'\'), s.name) ASC, t.id ASC '
            . 'LIMIT 500',
            ['locale' => $locale, 'primary_locale' => $primaryLocale]
        );
    }

    /** @return list<array<string, mixed>> */
    public function inquiries(): array
    {
        return $this->all(
            'SELECT i.public_id, i.locale, i.contact_name, i.email, i.phone, '
            . 'i.message, i.created_at, COUNT(l.id) AS line_count FROM '
            . $this->tables->table('inquiries') . ' i LEFT JOIN '
            . $this->tables->table('inquiry_lines') . ' l '
            . 'ON l.inquiry_id = i.id GROUP BY i.id, i.public_id, i.locale, '
            . 'i.contact_name, i.email, i.phone, i.message, i.created_at '
            . 'ORDER BY i.created_at DESC, i.id DESC LIMIT 200'
        );
    }

    /** @return array<string, mixed>|null */
    public function inquiry(string $publicId): ?array
    {
        $inquiries = $this->all(
            'SELECT public_id, locale, contact_name, email, phone, message, '
            . 'privacy_version, created_at FROM '
            . $this->tables->table('inquiries')
            . ' WHERE public_id = :public_id LIMIT 1',
            ['public_id' => $publicId]
        );
        if ($inquiries === []) {
            return null;
        }
        $inquiry = $inquiries[0];
        $lines = $this->all(
            'SELECT l.product_public_id, l.sku, l.requested_locale, '
            . 'l.resolved_locale, l.title, l.public_path, '
            . 'l.cover_media_public_id, l.quantity, l.unit_price_minor, '
            . 'l.currency, l.availability_status FROM '
            . $this->tables->table('inquiry_lines') . ' l INNER JOIN '
            . $this->tables->table('inquiries')
            . ' i ON i.id = l.inquiry_id WHERE i.public_id = :public_id '
            . 'ORDER BY l.id ASC LIMIT 100',
            ['public_id' => $publicId]
        );
        foreach ($lines as &$line) {
            $media = $line['cover_media_public_id'] ?? null;
            $line['cover_thumbnail_width'] = is_string($media)
                ? $this->mediaThumbnailWidth($media)
                : null;
        }
        unset($line);
        $inquiry['lines'] = $lines;

        return $inquiry;
    }

    /** @return list<string> */
    public function productCategoryPublicIds(string $productPublicId): array
    {
        return $this->column(
            'SELECT c.public_id FROM ' . $this->tables->table('product_categories')
            . ' pc INNER JOIN ' . $this->tables->table('products')
            . ' p ON p.id = pc.product_id INNER JOIN '
            . $this->tables->table('categories')
            . ' c ON c.id = pc.category_id WHERE p.public_id = :product '
            . 'ORDER BY c.id ASC',
            ['product' => $productPublicId]
        );
    }

    public function productCanonicalCategoryPublicId(string $productPublicId): ?string
    {
        $rows = $this->all(
            'SELECT c.public_id FROM ' . $this->tables->table('products')
            . ' p LEFT JOIN ' . $this->tables->table('categories')
            . ' c ON c.id = p.canonical_category_id '
            . 'WHERE p.public_id = :product LIMIT 1',
            ['product' => $productPublicId]
        );
        if ($rows === []) {
            return null;
        }
        $value = $rows[0]['public_id'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    public function productTagPublicIds(string $productPublicId): array
    {
        return $this->column(
            'SELECT t.public_id FROM ' . $this->tables->table('product_tags')
            . ' pt INNER JOIN ' . $this->tables->table('products')
            . ' p ON p.id = pt.product_id INNER JOIN '
            . $this->tables->table('tags')
            . ' t ON t.id = pt.tag_id WHERE p.public_id = :product '
            . 'ORDER BY t.id ASC',
            ['product' => $productPublicId]
        );
    }

    /**
     * @return list<array{
     *   public_id:string,code:string,type:string,category_public_id:?string,
     *   unit:?string,is_filterable:mixed,sort_order:mixed,name:mixed,
     *   options:list<array{public_id:string,code:string,label:mixed,sort_order:mixed}>
     * }>
     */
    public function attributes(string $locale, string $primaryLocale): array
    {
        $attributes = $this->all(
            'SELECT a.public_id, a.code, a.type, c.public_id AS category_public_id, '
            . 'a.unit, a.is_filterable, a.sort_order, '
            . 'COALESCE(NULLIF(l.name, \'\'), s.name) AS name FROM '
            . $this->tables->table('attributes') . ' a LEFT JOIN '
            . $this->tables->table('categories') . ' c ON c.id = a.category_id '
            . 'INNER JOIN ' . $this->tables->table('attribute_localizations')
            . ' l ON l.attribute_id = a.id AND l.locale = :locale INNER JOIN '
            . $this->tables->table('attribute_localizations')
            . ' s ON s.attribute_id = a.id AND s.locale = :primary_locale '
            . 'ORDER BY a.sort_order ASC, a.id ASC LIMIT 500',
            ['locale' => $locale, 'primary_locale' => $primaryLocale]
        );
        $options = $this->all(
            'SELECT a.public_id AS attribute_public_id, o.public_id, o.code, '
            . 'o.sort_order, COALESCE(NULLIF(l.label, \'\'), s.label) AS label '
            . 'FROM ' . $this->tables->table('attribute_options') . ' o '
            . 'INNER JOIN ' . $this->tables->table('attributes')
            . ' a ON a.id = o.attribute_id INNER JOIN '
            . $this->tables->table('attribute_option_localizations')
            . ' l ON l.option_id = o.id AND l.locale = :locale INNER JOIN '
            . $this->tables->table('attribute_option_localizations')
            . ' s ON s.option_id = o.id AND s.locale = :primary_locale '
            . 'ORDER BY a.id ASC, o.sort_order ASC, o.id ASC LIMIT 5000',
            ['locale' => $locale, 'primary_locale' => $primaryLocale]
        );
        $byAttribute = [];
        foreach ($options as $option) {
            $attribute = $option['attribute_public_id'] ?? null;
            if (!is_string($attribute)) {
                throw new CommercePersistenceException();
            }
            $byAttribute[$attribute][] = [
                'public_id' => (string) ($option['public_id'] ?? ''),
                'code' => (string) ($option['code'] ?? ''),
                'label' => $option['label'] ?? null,
                'sort_order' => $option['sort_order'] ?? 0,
            ];
        }
        foreach ($attributes as &$attribute) {
            $publicId = $attribute['public_id'] ?? null;
            if (!is_string($publicId)) {
                throw new CommercePersistenceException();
            }
            $attribute['options'] = $byAttribute[$publicId] ?? [];
        }
        unset($attribute);

        /** @var list<array{public_id:string,code:string,type:string,category_public_id:?string,unit:?string,is_filterable:mixed,sort_order:mixed,name:mixed,options:list<array{public_id:string,code:string,label:mixed,sort_order:mixed}>}> $attributes */
        return $attributes;
    }

    /** @return array<string, array<string, mixed>> */
    public function productAttributeValues(string $productPublicId): array
    {
        $rows = $this->all(
            'SELECT a.public_id, v.id AS value_id, v.locale, v.text_value, '
            . 'v.number_value, v.boolean_value, v.date_value FROM '
            . $this->tables->table('product_attribute_values') . ' v '
            . 'INNER JOIN ' . $this->tables->table('products')
            . ' p ON p.id = v.product_id INNER JOIN '
            . $this->tables->table('attributes')
            . ' a ON a.id = v.attribute_id WHERE p.public_id = :product '
            . 'ORDER BY a.id ASC, v.locale ASC',
            ['product' => $productPublicId]
        );
        $result = [];
        foreach ($rows as $row) {
            $publicId = $row['public_id'] ?? null;
            $valueId = $row['value_id'] ?? null;
            if (!is_string($publicId) || (!is_int($valueId) && !is_string($valueId))) {
                throw new CommercePersistenceException();
            }
            $row['option_public_ids'] = $this->column(
                'SELECT o.public_id FROM '
                . $this->tables->table('product_attribute_value_options')
                . ' vo INNER JOIN ' . $this->tables->table('attribute_options')
                . ' o ON o.id = vo.option_id WHERE vo.value_id = :value '
                . 'ORDER BY o.sort_order ASC, o.id ASC',
                ['value' => (string) $valueId]
            );
            $result[$publicId . ':' . (string) ($row['locale'] ?? '')] = $row;
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function productMedia(
        string $productPublicId,
        string $locale,
        string $primaryLocale
    ): array
    {
        return $this->all(
            'SELECT pm.media_asset_public_id AS public_id, pm.role, pm.sort_order, '
            . 'COALESCE(NULLIF(l.alt_text, \'\'), s.alt_text) AS alt_text, '
            . 'CASE WHEN l.translation_status = \'fallback\' THEN s.caption '
            . 'ELSE l.caption END AS caption, l.translation_status '
            . 'FROM ' . $this->tables->table('product_media') . ' pm '
            . 'INNER JOIN ' . $this->tables->table('products')
            . ' p ON p.id = pm.product_id LEFT JOIN '
            . $this->tables->table('product_media_localizations')
            . ' l ON l.media_id = pm.id AND l.locale = :locale LEFT JOIN '
            . $this->tables->table('product_media_localizations')
            . ' s ON s.media_id = pm.id AND s.locale = :primary_locale '
            . 'WHERE p.public_id = :product '
            . 'ORDER BY CASE pm.role WHEN \'cover\' THEN 0 ELSE 1 END, '
            . 'pm.sort_order ASC, pm.id ASC',
            [
                'product' => $productPublicId,
                'locale' => $locale,
                'primary_locale' => $primaryLocale,
            ]
        );
    }

    /** @param list<string> $locales */
    public function productMediaHasAltForLocales(
        string $productPublicId,
        array $locales,
        string $primaryLocale
    ): bool {
        foreach ($locales as $locale) {
            if (!is_string($locale)) {
                throw new CommercePersistenceException();
            }
            foreach ($this->productMedia($productPublicId, $locale, $primaryLocale) as $media) {
                if (!is_string($media['alt_text'] ?? null)
                    || trim($media['alt_text']) === ''
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function mediaAssets(): array
    {
        if (!$this->webAdminTables instanceof WebAdminTableNames) {
            return [];
        }
        $quarantineJoin = $this->mediaQuarantineEnabled
            ? ' LEFT JOIN ' . $this->webAdminTables->table('media_quarantines')
                . ' q ON q.asset_id = a.id '
            : ' ';
        $quarantineWhere = $this->mediaQuarantineEnabled
            ? 'AND q.asset_id IS NULL '
            : '';

        return $this->all(
            'SELECT a.public_id, a.label, MIN(v.width) AS thumbnail_width '
            . 'FROM ' . $this->webAdminTables->table('media_assets') . ' a '
            . 'INNER JOIN ' . $this->webAdminTables->table('media_variants')
            . ' v ON v.asset_id = a.id' . $quarantineJoin
            . "WHERE v.mime = 'image/avif' " . $quarantineWhere
            . 'GROUP BY a.id, a.public_id, a.label, a.created_at '
            . 'ORDER BY a.created_at DESC, a.id DESC LIMIT 200'
        );
    }

    /** @param array<string, string> $parameters @return list<array<string, mixed>> */
    private function all(string $sql, array $parameters = []): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            if (!$statement instanceof PDOStatement) {
                throw new CommercePersistenceException();
            }
            if (!$statement->execute($parameters)) {
                throw new CommercePersistenceException();
            }
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) {
                throw new CommercePersistenceException();
            }

            /** @var list<array<string, mixed>> $rows */
            return array_values($rows);
        } catch (CommercePersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new CommercePersistenceException();
        }
    }

    /** @param array<string, string> $parameters @return list<string> */
    private function column(string $sql, array $parameters = []): array
    {
        $rows = $this->all($sql, $parameters);
        $result = [];
        foreach ($rows as $row) {
            $value = array_values($row)[0] ?? null;
            if (!is_string($value)) {
                throw new CommercePersistenceException();
            }
            $result[] = $value;
        }

        return $result;
    }

    private function mediaThumbnailWidth(string $publicId): ?int
    {
        if (!$this->webAdminTables instanceof WebAdminTableNames) {
            return null;
        }
        $quarantineJoin = $this->mediaQuarantineEnabled
            ? ' LEFT JOIN ' . $this->webAdminTables->table('media_quarantines')
                . ' q ON q.asset_id = a.id '
            : ' ';
        $quarantineFilter = $this->mediaQuarantineEnabled
            ? 'AND q.asset_id IS NULL '
            : '';
        $rows = $this->all(
            'SELECT MIN(v.width) AS width FROM '
            . $this->webAdminTables->table('media_assets') . ' a INNER JOIN '
            . $this->webAdminTables->table('media_variants')
            . ' v ON v.asset_id = a.id' . $quarantineJoin
            . 'WHERE a.public_id = :public_id AND v.mime = :mime '
            . $quarantineFilter . 'GROUP BY a.id LIMIT 1',
            ['public_id' => $publicId, 'mime' => 'image/avif']
        );
        if ($rows === []) {
            return null;
        }
        $value = $rows[0]['width'] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            return (int) $value;
        }

        throw new CommercePersistenceException();
    }
}
