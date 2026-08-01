<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\UserManagement;

use App\Core\Modules\ModuleRegistry;
use InvalidArgumentException;

/** Immutable runtime view of modules enabled for the consuming project. */
final class ActiveModuleSet
{
    /** @var array<string, true> */
    private array $ids;

    /** @param list<string> $ids */
    public function __construct(array $ids)
    {
        $normalized = [];
        foreach ($ids as $id) {
            if (
                !is_string($id)
                || preg_match('/\A[a-z][a-z0-9-]{0,62}\z/', $id) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Invalid active WebAdmin module identifier.'
                );
            }
            $normalized[$id] = true;
        }
        if (!isset($normalized['webadmin'])) {
            throw new InvalidArgumentException(
                'WebAdmin must be active for user management.'
            );
        }
        ksort($normalized, SORT_STRING);
        $this->ids = $normalized;
    }

    public static function fromRegistry(ModuleRegistry $registry): self
    {
        return new self($registry->selection()->enabledIds());
    }

    public function contains(string $moduleId): bool
    {
        return isset($this->ids[$moduleId]);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->ids);
    }
}
