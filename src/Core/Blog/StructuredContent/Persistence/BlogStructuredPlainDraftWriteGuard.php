<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Persistence;

use App\Core\Blog\BlogException;
use App\Core\Blog\Editing\BlogPlainDraftWriteGuardInterface;
use PDO;
use Throwable;

/** Prevents the compatibility editor from destroying structured content. */
final class BlogStructuredPlainDraftWriteGuard implements
    BlogPlainDraftWriteGuardInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BlogStructuredContentRepositoryInterface $repository
    ) {
    }

    public function assertPlainSaveAllowed(
        PDO $pdo,
        string $localizationPublicId
    ): void {
        if ($pdo !== $this->pdo || !$pdo->inTransaction()) {
            throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        }

        try {
            if ($this->repository->hasCurrent($localizationPublicId)) {
                throw new BlogException(BlogException::INVALID_STATE);
            }
        } catch (BlogException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        }
    }
}
