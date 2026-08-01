<?php

declare(strict_types=1);

namespace App\Core\Modules\WebAdmin;

use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Bounded canonical-data check suitable for both migrations and HTTP gates. */
final class WebAdminCanonicalSeedVerifier
{
    public function verify(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        try {
            return $this->validateMetadata(
                $this->collectMetadata($pdo, $scope, $driver)
            );
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function collectMetadata(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): array {
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new \InvalidArgumentException('Unsupported seed driver.');
        }
        [$roleIn, $roleBindings] = $this->inClause(
            array_keys(WebAdminInitialSchemaContract::roles()),
            'role'
        );
        $roles = $pdo->prepare(
            'SELECT code, label_key, is_protected, is_delegable FROM '
            . $scope->quotedTable('roles', $driver)
            . ' WHERE code IN (' . $roleIn . ')'
        );
        $roles->execute($roleBindings);

        [$capabilityIn, $capabilityBindings] = $this->inClause(
            array_keys(WebAdminInitialSchemaContract::capabilities()),
            'capability'
        );
        $capabilities = $pdo->prepare(
            'SELECT code, module_id, label_key, is_delegable FROM '
            . $scope->quotedTable('capabilities', $driver)
            . ' WHERE code IN (' . $capabilityIn . ')'
        );
        $capabilities->execute($capabilityBindings);

        $edges = $pdo->prepare(
            'SELECT r.code AS role_code, c.code AS capability_code FROM '
            . $scope->quotedTable('role_capabilities', $driver) . ' AS rc '
            . 'JOIN ' . $scope->quotedTable('roles', $driver)
            . ' AS r ON r.id = rc.role_id '
            . 'JOIN ' . $scope->quotedTable('capabilities', $driver)
            . ' AS c ON c.id = rc.capability_id '
            . 'WHERE r.code IN (' . $roleIn . ') '
            . 'AND c.code IN (' . $capabilityIn . ')'
        );
        $edges->execute($roleBindings + $capabilityBindings);

        $state = $pdo->prepare(
            'SELECT value_text FROM ' . $scope->quotedTable('state', $driver)
            . ' WHERE state_key = :state_key'
        );
        $state->execute(['state_key' => 'bootstrap.initial_accounts']);

        return [
            'roles' => $roles->fetchAll(PDO::FETCH_ASSOC),
            'capabilities' => $capabilities->fetchAll(PDO::FETCH_ASSOC),
            'role_capabilities' => $edges->fetchAll(PDO::FETCH_ASSOC),
            'bootstrap_state_values' => $state->fetchAll(PDO::FETCH_COLUMN),
        ];
    }

    /** @param array<string, mixed> $seeds */
    public function validateMetadata(array $seeds): bool
    {
        $roles = [];
        foreach (($seeds['roles'] ?? []) as $row) {
            if (!is_array($row)) {
                return false;
            }
            $row = array_change_key_case($row, CASE_LOWER);
            $code = (string) ($row['code'] ?? '');
            if ($code === '' || isset($roles[$code])) {
                return false;
            }
            $isProtected = $this->binaryFlag(
                $row['is_protected'] ?? null
            );
            $isDelegable = $this->binaryFlag(
                $row['is_delegable'] ?? null
            );
            if ($isProtected === null || $isDelegable === null) {
                return false;
            }
            $roles[$code] = [
                'label_key' => (string) ($row['label_key'] ?? ''),
                'is_protected' => $isProtected,
                'is_delegable' => $isDelegable,
            ];
        }
        foreach (WebAdminInitialSchemaContract::roles() as $code => $expected) {
            if (($roles[$code] ?? null) !== $expected) {
                return false;
            }
        }

        $capabilities = [];
        foreach (($seeds['capabilities'] ?? []) as $row) {
            if (!is_array($row)) {
                return false;
            }
            $row = array_change_key_case($row, CASE_LOWER);
            $code = (string) ($row['code'] ?? '');
            if ($code === '' || isset($capabilities[$code])) {
                return false;
            }
            $isDelegable = $this->binaryFlag(
                $row['is_delegable'] ?? null
            );
            if ($isDelegable === null) {
                return false;
            }
            $capabilities[$code] = [
                'module_id' => (string) ($row['module_id'] ?? ''),
                'label_key' => (string) ($row['label_key'] ?? ''),
                'is_delegable' => $isDelegable,
            ];
        }
        foreach (
            WebAdminInitialSchemaContract::capabilities()
            as $code => $expected
        ) {
            if (($capabilities[$code] ?? null) !== $expected) {
                return false;
            }
        }

        $edges = [];
        $seenEdges = [];
        foreach (($seeds['role_capabilities'] ?? []) as $row) {
            if (!is_array($row)) {
                return false;
            }
            $row = array_change_key_case($row, CASE_LOWER);
            $role = (string) ($row['role_code'] ?? '');
            $capability = (string) ($row['capability_code'] ?? '');
            if ($role === '' || $capability === '') {
                return false;
            }
            $edgeKey = $role . "\0" . $capability;
            if (isset($seenEdges[$edgeKey])) {
                return false;
            }
            $seenEdges[$edgeKey] = true;
            $edges[$role][$capability] = true;
        }
        foreach (
            WebAdminInitialSchemaContract::roleCapabilities()
            as $role => $codes
        ) {
            $actualCodes = array_keys($edges[$role] ?? []);
            sort($actualCodes, SORT_STRING);
            sort($codes, SORT_STRING);
            if ($actualCodes !== $codes) {
                return false;
            }
        }

        $stateValues = $seeds['bootstrap_state_values'] ?? null;
        if ($stateValues !== null) {
            if (
                !is_array($stateValues)
                || !array_is_list($stateValues)
                || count($stateValues) !== 1
            ) {
                return false;
            }
            $state = $stateValues[0];
        } else {
            // Compatibility for pure metadata fixtures predating the bounded
            // collector. Database reads always take the exact-row path above.
            $state = $seeds['bootstrap_state'] ?? null;
        }

        return in_array($state, ['pending', 'completed'], true);
    }

    private function binaryFlag(mixed $value): ?int
    {
        return match (true) {
            $value === 0 || $value === '0' => 0,
            $value === 1 || $value === '1' => 1,
            default => null,
        };
    }

    /** @param list<string> $values @return array{string, array<string, string>} */
    private function inClause(array $values, string $prefix): array
    {
        $placeholders = [];
        $bindings = [];
        foreach (array_values($values) as $index => $value) {
            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $value;
        }

        return [implode(', ', $placeholders), $bindings];
    }
}
