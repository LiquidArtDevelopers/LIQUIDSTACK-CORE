<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\UserManagement;

use InvalidArgumentException;

final class DelegableCapability
{
    public function __construct(
        private readonly string $moduleId,
        private readonly string $code,
        private readonly string $labelKey
    ) {
        if (
            preg_match('/\A[a-z][a-z0-9-]{0,62}\z/', $moduleId) !== 1
            || preg_match('/\A[a-z][a-z0-9_.-]{2,127}\z/', $code) !== 1
            || preg_match('/\A[a-z][a-z0-9_.-]{2,159}\z/', $labelKey) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid delegable capability.'
            );
        }
    }

    public function moduleId(): string
    {
        return $this->moduleId;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function labelKey(): string
    {
        return $this->labelKey;
    }

    /** @return array{module_id: string, code: string, label_key: string} */
    public function toSafeArray(): array
    {
        return [
            'module_id' => $this->moduleId,
            'code' => $this->code,
            'label_key' => $this->labelKey,
        ];
    }
}
