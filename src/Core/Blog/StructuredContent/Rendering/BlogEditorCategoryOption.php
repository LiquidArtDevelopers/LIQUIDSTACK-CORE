<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use InvalidArgumentException;

/** Safe presentation projection for one localized category choice. */
final class BlogEditorCategoryOption
{
    private const UUID_V4_PATTERN =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    public function __construct(
        private readonly string $publicId,
        private readonly string $name,
        private readonly bool $assigned
    ) {
        if (
            preg_match(self::UUID_V4_PATTERN, $publicId) !== 1
            || trim($name) !== $name
            || $name === ''
            || strlen($name) > 255
            || preg_match('//u', $name) !== 1
            || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog editor category option.'
            );
        }
    }

    public function publicId(): string
    {
        return $this->publicId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function assigned(): bool
    {
        return $this->assigned;
    }
}
