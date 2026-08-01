<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\UserManagement;

final class EditorInviteResult
{
    public const INVITED = 'invited';
    public const DENIED = 'denied';
    public const INVALID = 'invalid';
    public const CONFLICT = 'conflict';

    private function __construct(
        private readonly string $status,
        private readonly ?EditorDetail $editor
    ) {
    }

    public static function invited(EditorDetail $editor): self
    {
        return new self(self::INVITED, $editor);
    }

    public static function failed(string $status): self
    {
        if (!in_array($status, [self::DENIED, self::INVALID, self::CONFLICT], true)) {
            throw new \InvalidArgumentException('Invalid invite result status.');
        }

        return new self($status, null);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function changed(): bool
    {
        return $this->status === self::INVITED;
    }

    public function editor(): ?EditorDetail
    {
        return $this->editor;
    }
}
