<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorPreferences\Audit;

use App\Core\Blog\BlogInput;
use DateTimeImmutable;

/** Content-free audit envelope for a global preference mutation. */
final class BlogEditorPreferencesAuditEvent
{
    private readonly DateTimeImmutable $occurredAt;

    public function __construct(
        private readonly string $actorPublicId,
        DateTimeImmutable $occurredAt
    ) {
        BlogInput::publicId($actorPublicId);
        $this->occurredAt = BlogInput::utc($occurredAt);
    }

    public function actorPublicId(): string
    {
        return $this->actorPublicId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** @return array{actor_public_id: string, occurred_at: string} */
    public function toArray(): array
    {
        return [
            'actor_public_id' => $this->actorPublicId,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s.u'),
        ];
    }
}
