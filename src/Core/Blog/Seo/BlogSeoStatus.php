<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

/** Closed editorial states; deliberately not a numeric SEO score. */
final class BlogSeoStatus
{
    public const GOOD = 'good';
    public const REVIEW = 'review';
    public const PENDING = 'pending';

    public static function label(string $status): string
    {
        return match ($status) {
            self::GOOD => 'Bien',
            self::REVIEW => 'Revisar',
            self::PENDING => 'Pendiente',
            default => throw new \InvalidArgumentException(
                'Invalid Blog SEO status.'
            ),
        };
    }

    public static function assert(string $status): string
    {
        self::label($status);

        return $status;
    }
}
