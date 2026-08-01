<?php

declare(strict_types=1);

namespace App\Core\Environment;

use InvalidArgumentException;

final class ProjectEnvironmentLoadResult
{
    public const VALID = 'valid';
    public const MISSING = 'missing';
    public const FILE_INVALID = 'file_invalid';
    public const PARSE_FAILED = 'parse_failed';

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        private readonly array $values,
        private readonly string $status
    ) {
        if (!in_array($status, self::statuses(), true)) {
            throw new InvalidArgumentException(
                'El estado de carga del entorno no es válido.'
            );
        }
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isUsable(): bool
    {
        return in_array($this->status, [self::VALID, self::MISSING], true);
    }

    /** @return list<string> */
    private static function statuses(): array
    {
        return [
            self::VALID,
            self::MISSING,
            self::FILE_INVALID,
            self::PARSE_FAILED,
        ];
    }
}
