<?php

declare(strict_types=1);

namespace App\Core\Commerce\Persistence;

use InvalidArgumentException;
use PDO;

final class CommerceTableNames
{
    public const MAX_PREFIX_LENGTH = 33;

    /** @var list<string> */
    private const SUFFIXES = [
        'products',
        'product_localizations',
        'categories',
        'category_localizations',
        'product_categories',
        'tags',
        'tag_localizations',
        'product_tags',
        'attributes',
        'attribute_localizations',
        'attribute_options',
        'attribute_option_localizations',
        'product_attribute_values',
        'product_attribute_value_options',
        'product_media',
        'product_media_localizations',
        'url_history',
        'baskets',
        'basket_items',
        'inquiries',
        'inquiry_lines',
        'inquiry_rate_limits',
        'inquiry_outbox',
        'product_inquiry_stats',
    ];

    private function __construct(
        private readonly string $driver,
        private readonly string $prefix
    ) {
    }

    public static function fromPdo(PDO $pdo, string $prefix): self
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!is_string($driver) || !in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new InvalidArgumentException('Unsupported Commerce database driver.');
        }
        if (
            preg_match('/\A[a-z][a-z0-9_]*_\z/', $prefix) !== 1
            || strlen($prefix) > self::MAX_PREFIX_LENGTH
        ) {
            throw new InvalidArgumentException('Invalid Commerce table namespace.');
        }

        return new self($driver, $prefix);
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function table(string $suffix): string
    {
        if (!in_array($suffix, self::SUFFIXES, true)) {
            throw new InvalidArgumentException('Unknown Commerce table.');
        }
        $name = $this->prefix . $suffix;
        if (strlen($name) > 64) {
            throw new InvalidArgumentException('Commerce table name is too long.');
        }

        return $this->driver === 'mysql' ? '`' . $name . '`' : '"' . $name . '"';
    }
}
