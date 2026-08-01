<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Security;

use LogicException;
use WeakMap;

/**
 * Keeps a process-local secret outside exported object properties.
 *
 * @internal
 */
final class OpaqueSecret
{
    /** @var WeakMap<object, string>|null */
    private static ?WeakMap $values = null;

    private function __construct()
    {
    }

    public static function fromString(#[\SensitiveParameter] string $value): self
    {
        $secret = new self();
        self::$values ??= new WeakMap();
        self::$values[$secret] = $value;

        return $secret;
    }

    public function reveal(): string
    {
        $value = self::$values[$this] ?? null;
        if (!is_string($value)) {
            throw new LogicException('WebAdmin secret is unavailable.');
        }

        return $value;
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new LogicException('WebAdmin secrets cannot be serialized.');
    }

    private function __clone()
    {
    }
}
