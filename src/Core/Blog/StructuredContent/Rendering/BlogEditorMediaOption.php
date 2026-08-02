<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use InvalidArgumentException;

/** Safe, presentation-only media choice for one image block. */
final class BlogEditorMediaOption
{
    private const UUID_V4_PATTERN =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    public function __construct(
        private readonly string $publicId,
        private readonly string $label
    ) {
        $characters = preg_match_all('/./us', $label, $matches);
        if (
            preg_match(self::UUID_V4_PATTERN, $publicId) !== 1
            || trim($label) !== $label
            || $label === ''
            || $characters === false
            || $characters > 120
            || preg_match('/[\x00-\x1F\x7F<>]/u', $label) === 1
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog editor media option.'
            );
        }
    }

    public function publicId(): string
    {
        return $this->publicId;
    }

    public function label(): string
    {
        return $this->label;
    }
}
