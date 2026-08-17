<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Presentation;

/** Code-owned allowlist of LiquidStack H1 modules, independent of the hero. */
final class BlogH1ModuleCatalog
{
    public const TYPE01 = 'moduleH1Type01';
    public const TYPE03 = 'moduleH1Type03';
    public const TYPE04 = 'moduleH1Type04';

    /** @var array<string, array{resource: string, label: string}> */
    private const ITEMS = [
        self::TYPE01 => [
            'resource' => 'moduleH1Type01',
            'label' => 'Módulo H1 tipo 01',
        ],
        self::TYPE03 => [
            'resource' => 'moduleH1Type03',
            'label' => 'Módulo H1 tipo 03',
        ],
        self::TYPE04 => [
            'resource' => 'moduleH1Type04',
            'label' => 'Módulo H1 tipo 04',
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
            throw new \InvalidArgumentException(
                'Unsupported Blog H1 module.'
            );
        }

        return self::ITEMS[$key];
    }
}
