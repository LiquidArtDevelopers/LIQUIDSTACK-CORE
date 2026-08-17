<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

use App\Core\Blog\BlogException;

final class BlogUrlResolution
{
    public const ACTIVE = 'active';
    public const TEMPORARY_NOT_FOUND = 'temporary_not_found';
    public const GONE = 'gone';
    public const REDIRECT = 'redirect';

    public function __construct(
        private readonly string $state,
        private readonly ?string $redirectLocale = null,
        private readonly ?string $redirectSlug = null
    ) {
        if (
            !in_array($state, [
                self::ACTIVE,
                self::TEMPORARY_NOT_FOUND,
                self::GONE,
                self::REDIRECT,
            ], true)
            || ($state === self::REDIRECT)
                !== ($redirectLocale !== null && $redirectSlug !== null)
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
    }

    public function state(): string
    {
        return $this->state;
    }

    public function redirectLocale(): ?string
    {
        return $this->redirectLocale;
    }

    public function redirectSlug(): ?string
    {
        return $this->redirectSlug;
    }
}
