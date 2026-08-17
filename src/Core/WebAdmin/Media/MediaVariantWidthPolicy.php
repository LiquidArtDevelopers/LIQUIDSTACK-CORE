<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use InvalidArgumentException;

/** Canonical responsive widths applied to an already bounded real master. */
final class MediaVariantWidthPolicy
{
    public const MASTER_LIMIT = 2560;
    public const STANDARD_WIDTHS = [480, 900, 1800];

    /** @return list<int> */
    public function widthsForMaster(int $masterWidth): array
    {
        if ($masterWidth < 1 || $masterWidth > self::MASTER_LIMIT) {
            throw new InvalidArgumentException('Invalid media master width.');
        }

        $widths = [];
        foreach ([...self::STANDARD_WIDTHS, $masterWidth] as $target) {
            $widths[min($target, $masterWidth)] = true;
        }
        $widths = array_keys($widths);
        sort($widths, SORT_NUMERIC);

        return $widths;
    }
}
