<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Categories;

use App\Core\Blog\Categories\BlogCategoryException;
use App\Core\Blog\Categories\BlogCategoryInput;
use App\Core\Blog\Categories\BlogCategoryLocalization;
use App\Core\Blog\Categories\BlogCategoryService;
use App\Core\Blog\StructuredContent\Rendering\BlogEditorCategoryOption;

/** Projects the category service without exposing persistence to the editor. */
final class BlogCategoryEditorCatalogAdapter implements
    BlogEditorCategoryCatalogInterface
{
    public function __construct(
        private readonly BlogCategoryService $service
    ) {
    }

    public function forPost(string $postPublicId, string $locale): array
    {
        $assigned = [];
        foreach ($this->service->assignedToPost($postPublicId) as $publicId) {
            if (!is_string($publicId)) {
                throw new BlogCategoryException(
                    BlogCategoryException::STORAGE_UNAVAILABLE
                );
            }
            $publicId = BlogCategoryInput::publicId($publicId);
            if (isset($assigned[$publicId])) {
                throw new BlogCategoryException(
                    BlogCategoryException::STORAGE_UNAVAILABLE
                );
            }
            $assigned[$publicId] = true;
        }

        $result = [];
        $seen = [];
        $categories = $this->service->list(
            BlogCategoryService::MAX_ASSIGNMENTS,
            0,
            $locale
        );
        foreach ($categories as $category) {
            if (
                !$category instanceof BlogCategoryLocalization
                || $category->locale() !== $locale
                || isset($seen[$category->categoryPublicId()])
            ) {
                throw new BlogCategoryException(
                    BlogCategoryException::STORAGE_UNAVAILABLE
                );
            }
            $seen[$category->categoryPublicId()] = true;
            $result[] = new BlogEditorCategoryOption(
                $category->categoryPublicId(),
                $category->draft()->name(),
                isset($assigned[$category->categoryPublicId()])
            );
        }

        // Never render a partial assignment set: submitting it could remove a
        // category that was outside the bounded localized catalogue page.
        if (array_diff_key($assigned, $seen) !== []) {
            throw new BlogCategoryException(
                BlogCategoryException::STORAGE_UNAVAILABLE
            );
        }

        return $result;
    }
}
