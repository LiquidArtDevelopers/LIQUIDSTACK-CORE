<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Presentation;

/** Code-owned, extensible allowlist of LiquidStack heroes available to Blog. */
final class BlogHeroCatalog
{
    public const HERO00 = 'hero00';
    public const HERO06 = 'hero06';
    public const HERO07 = 'hero07';

    /** @var array<string, array{resource: string, label: string}> */
    private const ITEMS = [
        self::HERO00 => [
            'resource' => 'hero00',
            'label' => 'Hero 00',
        ],
        self::HERO06 => [
            'resource' => 'hero06',
            'label' => 'Hero 06',
        ],
        self::HERO07 => [
            'resource' => 'hero07',
            'label' => 'Hero 07',
        ],
    ];

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::ITEMS);
    }

    public function supports(string $key): bool
    {
        return isset(self::ITEMS[$key]);
    }

    public function resource(string $key): string
    {
        return $this->item($key)['resource'];
    }

    /** @return list<array{key: string, resource: string, label: string}> */
    public function toSafeArray(): array
    {
        $items = [];
        foreach (self::ITEMS as $key => $item) {
            $items[] = ['key' => $key] + $item;
        }

        return $items;
    }

    /** @return array{resource: string, label: string} */
    private function item(string $key): array
    {
        if (!$this->supports($key)) {
            throw new \InvalidArgumentException('Unsupported Blog hero.');
        }

        return self::ITEMS[$key];
    }
}
