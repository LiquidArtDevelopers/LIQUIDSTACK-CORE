<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Persistence;

use App\Core\Blog\BlogInput;
use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use DateTimeImmutable;
use Throwable;

/** Integrity-checked projection of the current document row. */
final class BlogStructuredDocumentRecord
{
    private readonly string $documentPublicId;
    private readonly string $localizationPublicId;
    private readonly string $createdByUserPublicId;
    private readonly string $updatedByUserPublicId;
    private readonly DateTimeImmutable $createdAt;
    private readonly DateTimeImmutable $updatedAt;

    public function __construct(
        private readonly int $internalId,
        string $documentPublicId,
        string $localizationPublicId,
        private readonly BlogStructuredDraft $snapshot,
        int $persistedSchemaVersion,
        string $persistedTemplateKey,
        int $persistedDocumentBytes,
        string $persistedDocumentSha256,
        string $persistedBodyTextSha256,
        string $persistedSnapshotSha256,
        string $createdByUserPublicId,
        string $updatedByUserPublicId,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt
    ) {
        try {
            if (
                $internalId < 1
                || $persistedSchemaVersion !== $snapshot->schemaVersion()
                || !hash_equals(
                    $snapshot->templateKey(),
                    $persistedTemplateKey
                )
                || $persistedDocumentBytes !== $snapshot->documentBytes()
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
            $this->documentPublicId = BlogInput::publicId($documentPublicId);
            $this->localizationPublicId = BlogInput::publicId(
                $localizationPublicId
            );
            $this->createdByUserPublicId = BlogInput::publicId(
                $createdByUserPublicId
            );
            $this->updatedByUserPublicId = BlogInput::publicId(
                $updatedByUserPublicId
            );
            $this->createdAt = BlogInput::utc($createdAt);
            $this->updatedAt = BlogInput::utc($updatedAt);
        } catch (BlogStructuredContentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::CORRUPT_DOCUMENT
            );
        }
    }

    public function internalId(): int
    {
        return $this->internalId;
    }

    public function documentPublicId(): string
    {
        return $this->documentPublicId;
    }

    public function localizationPublicId(): string
    {
        return $this->localizationPublicId;
    }

    public function snapshot(): BlogStructuredDraft
    {
        return $this->snapshot;
    }

    public function createdByUserPublicId(): string
    {
        return $this->createdByUserPublicId;
    }

    public function updatedByUserPublicId(): string
    {
        return $this->updatedByUserPublicId;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'document_public_id' => $this->documentPublicId,
            'localization_public_id' => $this->localizationPublicId,
            'schema_version' => $this->snapshot->schemaVersion(),
            'template_key' => $this->snapshot->templateKey(),
            'snapshot_sha256' => $this->snapshot->snapshotSha256(),
            'created_by_user_public_id' => $this->createdByUserPublicId,
            'updated_by_user_public_id' => $this->updatedByUserPublicId,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
            'content' => '[redacted]',
        ];
    }
}
