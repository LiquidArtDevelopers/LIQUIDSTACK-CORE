<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Navigation;

use InvalidArgumentException;

final class WebAdminNavigationCatalog
{
    /** @var list<WebAdminNavigationItem> */
    private readonly array $items;

    /**
     * @param list<WebAdminNavigationItem> $items
     */
    public function __construct(array $items = [])
    {
        if (!array_is_list($items)) {
            throw new InvalidArgumentException(
                'WebAdmin navigation items must be a list.'
            );
        }

        $bySuffix = [];
        foreach ($items as $item) {
            if (!$item instanceof WebAdminNavigationItem) {
                throw new InvalidArgumentException(
                    'Invalid WebAdmin navigation item.'
                );
            }

            $existing = $bySuffix[$item->suffix()] ?? null;
            if ($existing instanceof WebAdminNavigationItem) {
                if ($existing->equals($item)) {
                    continue;
                }

                throw new InvalidArgumentException(
                    'Conflicting WebAdmin navigation suffix.'
                );
            }

            $bySuffix[$item->suffix()] = $item;
        }

        $items = array_values($bySuffix);
        usort(
            $items,
            static fn (
                WebAdminNavigationItem $left,
                WebAdminNavigationItem $right
            ): int => [
                $left->module(),
                $left->suffix(),
                $left->label(),
                $left->requiredCapability(),
            ] <=> [
                $right->module(),
                $right->suffix(),
                $right->label(),
                $right->requiredCapability(),
            ]
        );

        $this->items = $items;
    }

    /** @return list<WebAdminNavigationItem> */
    public function items(): array
    {
        return $this->items;
    }
}
