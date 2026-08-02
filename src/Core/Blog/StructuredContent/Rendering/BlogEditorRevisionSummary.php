<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Small presentation projection for one immutable structured revision. */
final class BlogEditorRevisionSummary
{
    private const UUID_V4_PATTERN =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    private readonly DateTimeImmutable $createdAt;

    public function __construct(
        private readonly string $revisionPublicId,
        private readonly int $revisionNumber,
        private readonly int $variantLockVersion,
        DateTimeImmutable $createdAt
    ) {
        if (
            preg_match(self::UUID_V4_PATTERN, $revisionPublicId) !== 1
            || $revisionNumber < 1
            || $variantLockVersion < 1
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog editor revision summary.'
            );
        }

        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function revisionPublicId(): string
    {
        return $this->revisionPublicId;
    }

    public function revisionNumber(): int
    {
        return $this->revisionNumber;
    }

    public function variantLockVersion(): int
    {
        return $this->variantLockVersion;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
