<?php

declare(strict_types=1);

namespace App\Core\Modules\Diagnostics;

use InvalidArgumentException;

final class DiagnosticCheck
{
    private const STATUSES = ['ok', 'warning', 'error'];

    private function __construct(
        private readonly string $id,
        private readonly string $status,
        private readonly string $message
    ) {
        if (
            preg_match('/\A[a-z][a-z0-9_.-]*\z/', $id) !== 1
            || !in_array($status, self::STATUSES, true)
            || trim($message) === ''
        ) {
            throw new InvalidArgumentException(
                'Comprobación de diagnóstico inválida.'
            );
        }
    }

    public static function ok(string $id, string $message): self
    {
        return new self($id, 'ok', $message);
    }

    public static function warning(string $id, string $message): self
    {
        return new self($id, 'warning', $message);
    }

    public static function error(string $id, string $message): self
    {
        return new self($id, 'error', $message);
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array{id: string, status: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'message' => $this->message,
        ];
    }
}
