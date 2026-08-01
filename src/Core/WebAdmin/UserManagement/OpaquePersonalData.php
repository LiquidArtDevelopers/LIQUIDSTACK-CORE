<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\UserManagement;

use LogicException;
use WeakMap;

/** Keeps returned PII out of var_export/debug object properties. @internal */
final class OpaquePersonalData
{
    /** @var WeakMap<object, array{value: string|null}>|null */
    private static ?WeakMap $values = null;

    private function __construct()
    {
    }

    public static function fromNullable(
        #[\SensitiveParameter] ?string $value
    ): self {
        $data = new self();
        self::$values ??= new WeakMap();
        self::$values[$data] = ['value' => $value];

        return $data;
    }

    public function reveal(): ?string
    {
        $entry = self::$values[$this] ?? null;
        if (!is_array($entry) || !array_key_exists('value', $entry)) {
            throw new LogicException('WebAdmin personal data is unavailable.');
        }

        return $entry['value'];
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new LogicException('WebAdmin personal data cannot be serialized.');
    }

    private function __clone()
    {
    }
}
