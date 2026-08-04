<?php

declare(strict_types=1);

namespace App\Core\Composer;

use RuntimeException;

final class BlogAnalyticsPurgeCommandRuntimeException extends RuntimeException
{
    public function __construct(private readonly string $issueCode)
    {
        parent::__construct('Blog analytics purge unavailable.');
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
