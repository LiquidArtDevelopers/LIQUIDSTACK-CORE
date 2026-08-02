<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use RuntimeException;

/** Payload-free failure boundary for structured Blog HTML rendering. */
final class BlogRenderingException extends RuntimeException
{
    public const INVALID_IMAGE_PRESENTATION =
        'blog.rendering.image_presentation_invalid';
    public const MEDIA_UNAVAILABLE = 'blog.rendering.media_unavailable';
    public const INVALID_RENDER_STATE = 'blog.rendering.state_invalid';

    public function __construct(private readonly string $issueCode)
    {
        parent::__construct('Unable to render structured Blog content.');
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
