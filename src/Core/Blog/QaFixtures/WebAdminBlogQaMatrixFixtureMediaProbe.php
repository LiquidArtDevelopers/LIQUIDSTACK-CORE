<?php

declare(strict_types=1);

namespace App\Core\Blog\QaFixtures;

use App\Core\WebAdmin\Media\MediaCatalogRepositoryInterface;
use App\Core\WebAdmin\Media\MediaStorageInterface;
use Throwable;

/** Proves both the AVIF catalog row and its verified private-storage file. */
final class WebAdminBlogQaMatrixFixtureMediaProbe implements
    BlogQaMatrixFixtureMediaProbeInterface
{
    public function __construct(
        private readonly MediaCatalogRepositoryInterface $repository,
        private readonly MediaStorageInterface $storage
    ) {
    }

    public function assertUsable(string $mediaAssetPublicId): void
    {
        try {
            $assets = $this->repository->catalogAssetsByPublicIds([
                $mediaAssetPublicId,
            ]);
            if (
                count($assets) !== 1
                || $assets[0]->publicId() !== $mediaAssetPublicId
            ) {
                throw new BlogQaMatrixFixtureException(
                    'blog.qa_fixture.media_asset_not_found'
                );
            }
            $variant = $this->repository->findVariant(
                $mediaAssetPublicId,
                $assets[0]->thumbnailWidth()
            );
            if ($variant === null) {
                throw new BlogQaMatrixFixtureException(
                    'blog.qa_fixture.media_variant_not_found'
                );
            }
            $this->storage->probeVerified($variant);
        } catch (BlogQaMatrixFixtureException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogQaMatrixFixtureException(
                'blog.qa_fixture.media_file_unavailable'
            );
        }
    }
}
