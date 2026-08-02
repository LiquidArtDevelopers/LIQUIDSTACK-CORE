<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Persistence;

use App\Core\Blog\BlogInput;
use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use DateTimeImmutable;
use Throwable;

/** Content-free projection for bounded revision lists. */
final class BlogStructuredRevisionSummary
{
    private readonly string $revisionPublicId;
    private readonly string $localizationPublicId;
    private readonly DateTimeImmutable $createdAt;

    public function __construct(
        string $revisionPublicId,
        string $localizationPublicId,
        private readonly int $revisionNumber,
        private readonly int $variantLockVersion,
        private readonly int $schemaVersion,
        private readonly string $templateKey,
        private readonly int $documentBytes,
        private readonly int $mediaCount,
        DateTimeImmutable $createdAt
    ) {
        try {
            if (
                $revisionNumber < 1
                || $schemaVersion !== BlogDocument::VERSION
                || $documentBytes < 1
                || $documentBytes > BlogDocument::MAX_JSON_BYTES
                || $mediaCount < 0
                || $mediaCount > BlogDocument::MAX_BLOCKS
            ) {
                throw new BlogStructuredContentException(
                    BlogStructuredContentException::CORRUPT_DOCUMENT
                );
            }
            BlogInput::lockVersion($variantLockVersion);
            (new BlogDocumentTemplateRegistry())->assertSupported($templateKey);
            $this->revisionPublicId = BlogInput::publicId($revisionPublicId);
            $this->localizationPublicId = BlogInput::publicId(
                $localizationPublicId
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

    public function revisionPublicId(): string { return $this->revisionPublicId; }
    public function localizationPublicId(): string { return $this->localizationPublicId; }
    public function revisionNumber(): int { return $this->revisionNumber; }
    public function variantLockVersion(): int { return $this->variantLockVersion; }
    public function schemaVersion(): int { return $this->schemaVersion; }
    public function templateKey(): string { return $this->templateKey; }
    public function documentBytes(): int { return $this->documentBytes; }
    public function mediaCount(): int { return $this->mediaCount; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'revision_public_id' => $this->revisionPublicId,
            'localization_public_id' => $this->localizationPublicId,
            'revision_number' => $this->revisionNumber,
            'variant_lock_version' => $this->variantLockVersion,
            'schema_version' => $this->schemaVersion,
            'template_key' => $this->templateKey,
            'document_bytes' => $this->documentBytes,
            'media_count' => $this->mediaCount,
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
