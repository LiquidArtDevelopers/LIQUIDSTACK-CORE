<?php

declare(strict_types=1);

namespace App\Core\Blog\QaFixtures;

use RuntimeException;

final class BlogQaMatrixFixtureException extends RuntimeException
{
    public function __construct(private readonly string $issueCode)
    {
        parent::__construct('Blog QA Matrix fixture operation failed.');
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
