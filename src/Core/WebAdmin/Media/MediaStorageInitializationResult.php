<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

/** Safe, path-free result returned by the explicit storage initializer. */
final class MediaStorageInitializationResult
{
    private const MARKER_SCHEMA = 1;

    private function __construct(
        private readonly string $status,
        private readonly bool $changed
    ) {
    }

    public static function initialized(): self
    {
        return new self('initialized', true);
    }

    public static function alreadyInitialized(): self
    {
        return new self('already_initialized', false);
    }

    public static function adoptedExisting(): self
    {
        return new self('adopted_existing', true);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function changed(): bool
    {
        return $this->changed;
    }

    /** @return array{status: string,changed: bool,marker_schema: int} */
    public function toSafeArray(): array
    {
        return [
            'status' => $this->status,
            'changed' => $this->changed,
            'marker_schema' => self::MARKER_SCHEMA,
        ];
    }
}
