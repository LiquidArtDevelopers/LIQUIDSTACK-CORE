<?php

declare(strict_types=1);

namespace App\Core\Blog\Audit;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;
use DateTimeImmutable;

/**
 * Minimal, non-sensitive audit envelope for a successful Blog mutation.
 *
 * Content, metadata, locale, request data and credentials deliberately do not
 * belong to this contract. The WebAdmin adapter may map the operation to its
 * own event code, but it must not enrich this value with HTTP data.
 */
final class BlogMutationAuditEvent
{
    public const CREATE = 'create';
    public const ADD_LOCALE = 'add_locale';
    public const SAVE = 'save';
    public const PUBLISH = 'publish';
    public const UNPUBLISH = 'unpublish';

    private const OPERATIONS = [
        self::CREATE,
        self::ADD_LOCALE,
        self::SAVE,
        self::PUBLISH,
        self::UNPUBLISH,
    ];

    private readonly DateTimeImmutable $occurredAt;

    public function __construct(
        private readonly string $operation,
        private readonly string $actorPublicId,
        private readonly string $postPublicId,
        DateTimeImmutable $occurredAt
    ) {
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        BlogInput::publicId($actorPublicId);
        BlogInput::publicId($postPublicId);
        $this->occurredAt = BlogInput::utc($occurredAt);
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function actorPublicId(): string
    {
        return $this->actorPublicId;
    }

    public function postPublicId(): string
    {
        return $this->postPublicId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** @return array{operation: string, actor_public_id: string, post_public_id: string, occurred_at: string} */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'actor_public_id' => $this->actorPublicId,
            'post_public_id' => $this->postPublicId,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s.u'),
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}
