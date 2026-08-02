<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicDelivery;

/** Pure parser/builder for the fixed, non-configurable Blog media namespace. */
final class BlogPublicMediaRoute
{
    public const PREFIX = '/_liquidstack/blog-media';
    private const UUID_V4 =
        '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    /** @return null|array{public_id: string, width: int} */
    public static function match(string $path): ?array
    {
        if (preg_match(
            '#\A' . preg_quote(self::PREFIX, '#') . '/('
                . self::UUID_V4 . ')/([1-9][0-9]{0,3})\.avif\z#',
            $path,
            $matches
        ) !== 1) {
            return null;
        }

        $width = (int) $matches[2];
        if ($width < 1 || $width > 2_560) {
            return null;
        }

        return [
            'public_id' => $matches[1],
            'width' => $width,
        ];
    }

    public static function path(string $publicId, int $width): string
    {
        $path = self::PREFIX . '/' . $publicId . '/' . $width . '.avif';
        if (self::match($path) === null) {
            throw new BlogPublicMediaException();
        }

        return $path;
    }
}
