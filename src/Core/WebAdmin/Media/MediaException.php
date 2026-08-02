<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use RuntimeException;

final class MediaException extends RuntimeException
{
    public function __construct(private readonly string $issueCode)
    {
        parent::__construct('WebAdmin media operation failed.');
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
