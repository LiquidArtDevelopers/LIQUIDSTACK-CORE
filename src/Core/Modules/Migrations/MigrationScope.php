<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use InvalidArgumentException;

final class MigrationScope
{
    private function __construct(
        private readonly string $moduleId,
        private readonly string $tablePrefix,
        private readonly string $hash
    ) {
    }

    public static function forTablePrefix(
        string $moduleId,
        string $tablePrefix
    ): self {
        if (preg_match('/\A[a-z][a-z0-9-]{0,62}\z/', $moduleId) !== 1) {
            throw new InvalidArgumentException('Módulo de scope inválido.');
        }
        if (preg_match('/\A[a-z][a-z0-9_]{1,62}_\z/', $tablePrefix) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'El scope de migraciones del módulo %s necesita un prefijo válido.',
                $moduleId
            ));
        }

        return new self(
            $moduleId,
            $tablePrefix,
            hash(
                'sha256',
                "liquidstack-migration-scope-v1\0"
                    . $moduleId . "\0" . $tablePrefix
            )
        );
    }

    public function moduleId(): string
    {
        return $this->moduleId;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    public function tablePrefix(): string
    {
        return $this->tablePrefix;
    }

    public function quotedTable(string $suffix, string $driver): string
    {
        $table = $this->tableName($suffix);

        return match ($driver) {
            'mysql' => '`' . str_replace('`', '``', $table) . '`',
            'sqlite' => '"' . str_replace('"', '""', $table) . '"',
            default => throw new InvalidArgumentException(
                'Driver de migración no soportado.'
            ),
        };
    }

    public function tableName(string $suffix): string
    {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $suffix) !== 1) {
            throw new InvalidArgumentException('Sufijo de tabla inválido.');
        }

        $table = $this->tablePrefix . $suffix;
        if (strlen($table) > 64) {
            throw new InvalidArgumentException(sprintf(
                'Una tabla del módulo %s supera el límite de 64 caracteres.',
                $this->moduleId
            ));
        }

        return $table;
    }
}
