<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Mail;

use RuntimeException;

final class WebAdminMailConfigurationException extends RuntimeException
{
    public function __construct(
        private readonly string $issueCode,
        private readonly string $environmentName
    ) {
        parent::__construct('WebAdmin mail configuration is invalid.');
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }

    public function environmentName(): string
    {
        return $this->environmentName;
    }
}
