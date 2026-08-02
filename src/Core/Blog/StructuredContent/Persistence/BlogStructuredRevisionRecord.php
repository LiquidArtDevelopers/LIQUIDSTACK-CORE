<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Persistence;

use App\Core\Blog\BlogInput;
use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use DateTimeImmutable;
use Throwable;

/** Immutable, integrity-checked editorial revision. */
final class BlogStructuredRevisionRecord
{
    private readonly string $revisionPublicId;
    private readonly string $localizationPublicId;
    private readonly string $createdByUserPublicId;
    private readonly DateTimeImmutable $createdAt;

    public function __construct(
        private readonly int $internalId,
        string $revisionPublicId,
        string $localizationPublicId,
        private readonly int $revisionNumber,
        private readonly int $variantLockVersion,
        private readonly BlogStructuredDraft $snapshot,
        int $persistedSchemaVersion,
        string $persistedTemplateKey,
        int $persistedDocumentBytes,
        string $persistedDocumentSha256,
        string $persistedBodyTextSha256,
        string $persistedSnapshotSha256,
        string $createdByUserPublicId,
        DateTimeImmutable $createdAt
    ) {
        try {
            if (
                $internalId < 1
                || $revisionNumber < 1
                || $persistedSchemaVersion !== $snapshot->schemaVersion()
                || $persistedDocumentBytes !== $snapshot->documentBytes()
                || !hash_equals($snapshot->templateKey(), $persistedTemplateKey)
                || !hash_equals(
                    $snapshot->documentSha256(),
                    $persistedDocumentSha256
                )
                || !hash_equals(
                    $snapshot->bodyTextSha256(),
                    $persistedBodyTextSha256
                )
                || !hash_equals(
                    $snapshot->snapshotSha256(),
                    $persistedSnapshotSha256
                )
            ) {
                throw new BlogStructuredContentException(
                    BlogStructuredContentException::CORRUPT_DOCUMENT
                );
            }
            BlogInput::lockVersion($variantLockVersion);
            $this->revisionPublicId = BlogInput::publicId($revisionPublicId);
            $this->localizationPublicId = BlogInput::publicId(
                $localizationPublicId
            );
            $this->createdByUserPublicId = BlogInput::publicId(
                $createdByUserPublicId
            );
            $this->createdAt = BlogInput::utc($createdAt);
        } catch (BlogStructuredContentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::CORRUPT_DOCUMENT
            );
        }
    }

    public function internalId(): int { return $this->internalId; }
    public function revisionPublicId(): string { return $this->revisionPublicId; }
    public function localizationPublicId(): string { return $this->localizationPublicId; }
    public function revisionNumber(): int { return $this->revisionNumber; }
    public function variantLockVersion(): int { return $this->variantLockVersion; }
    public function snapshot(): BlogStructuredDraft { return $this->snapshot; }
    public function createdByUserPublicId(): string { return $this->createdByUserPublicId; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'revision_public_id' => $this->revisionPublicId,
            'localization_public_id' => $this->localizationPublicId,
            'revision_number' => $this->revisionNumber,
            'variant_lock_version' => $this->variantLockVersion,
            'snapshot_sha256' => $this->snapshot->snapshotSha256(),
            'created_by_user_public_id' => $this->createdByUserPublicId,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'content' => '[redacted]',
        ];
    }
}
