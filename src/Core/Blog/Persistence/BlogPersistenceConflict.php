<?php

declare(strict_types=1);

namespace App\Core\Blog\Persistence;

use RuntimeException;

/** Internal classification of a DB-enforced editorial uniqueness conflict. */
final class BlogPersistenceConflict extends RuntimeException
{
    public const LOCALE = 'locale';
    public const SLUG = 'slug';

    public function __construct(private readonly string $kind)
    {
        if (!in_array($kind, [self::LOCALE, self::SLUG], true)) {
            throw new \LogicException('Unknown Blog persistence conflict.');
        }

        parent::__construct('Blog persistence conflict.');
    }

    public function kind(): string
    {
        return $this->kind;
    }
}
