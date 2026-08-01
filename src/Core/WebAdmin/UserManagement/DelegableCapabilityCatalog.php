<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\UserManagement;

use InvalidArgumentException;

final class DelegableCapabilityCatalog
{
    /** @var list<DelegableCapability> */
    private array $capabilities;

    /** @param list<DelegableCapability> $capabilities */
    public function __construct(array $capabilities)
    {
        $seen = [];
        foreach ($capabilities as $capability) {
            if (!$capability instanceof DelegableCapability) {
                throw new InvalidArgumentException(
                    'Invalid delegable capability catalog.'
                );
            }
            if (isset($seen[$capability->code()])) {
                throw new InvalidArgumentException(
                    'Duplicate delegable capability.'
                );
            }
            $seen[$capability->code()] = true;
        }
        $this->capabilities = array_values($capabilities);
    }

    /** @return list<DelegableCapability> */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_map(
            static fn (DelegableCapability $capability): string =>
                $capability->code(),
            $this->capabilities
        );
    }

    /** @return list<array{module_id: string, code: string, label_key: string}> */
    public function toSafeArray(): array
    {
        return array_map(
            static fn (DelegableCapability $capability): array =>
                $capability->toSafeArray(),
            $this->capabilities
        );
    }
}
