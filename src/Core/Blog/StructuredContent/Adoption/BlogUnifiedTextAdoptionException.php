<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Adoption;

use RuntimeException;

final class BlogUnifiedTextAdoptionException extends RuntimeException
{
    public function __construct(private readonly string $issueCode)
    {
        parent::__construct('Unified Texto adoption failed.');
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
