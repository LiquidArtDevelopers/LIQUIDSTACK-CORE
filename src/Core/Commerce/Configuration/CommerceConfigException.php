<?php

declare(strict_types=1);

namespace App\Core\Commerce\Configuration;

use RuntimeException;

final class CommerceConfigException extends RuntimeException
{
    public function __construct(
        private readonly string $issueCode,
        private readonly ?string $configKey = null
    ) {
        parent::__construct($configKey === null
            ? $issueCode
            : $issueCode . ' (' . $configKey . ')');
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }

    public function configKey(): ?string
    {
        return $this->configKey;
    }
}
