<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Mail;

use RuntimeException;

final class WebAdminMailMessageFactoryException extends RuntimeException
{
    public function __construct(private readonly string $issueCode)
    {
        parent::__construct('WebAdmin mail message cannot be created.');
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
