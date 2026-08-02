<?php

declare(strict_types=1);

namespace App\Core\Blog\Categories\Audit;

use App\Core\Blog\Categories\BlogCategoryException;
use App\Core\Blog\Categories\BlogCategoryInput;
use DateTimeImmutable;

/** Non-sensitive audit envelope: no names, slugs, locale or request data. */
final class BlogCategoryAuditEvent
{
    public const CREATE = 'create';
    public const ADD_LOCALE = 'add_locale';
    public const SAVE = 'save';
    public const ASSIGN = 'assign';

    private const OPERATIONS = [
        self::CREATE,
        self::ADD_LOCALE,
        self::SAVE,
        self::ASSIGN,
    ];

    private readonly string $actorPublicId;
    private readonly string $targetPublicId;
    private readonly DateTimeImmutable $occurredAt;

    public function __construct(
        private readonly string $operation,
        string $actorPublicId,
        string $targetPublicId,
        DateTimeImmutable $occurredAt
    ) {
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new BlogCategoryException(
                BlogCategoryException::INVALID_INPUT
            );
        }
        $this->actorPublicId = BlogCategoryInput::publicId($actorPublicId);
        $this->targetPublicId = BlogCategoryInput::publicId($targetPublicId);
        $this->occurredAt = BlogCategoryInput::utc($occurredAt);
    }

    public function operation(): string { return $this->operation; }
    public function actorPublicId(): string { return $this->actorPublicId; }
    public function targetPublicId(): string { return $this->targetPublicId; }
    public function occurredAt(): DateTimeImmutable { return $this->occurredAt; }
}
