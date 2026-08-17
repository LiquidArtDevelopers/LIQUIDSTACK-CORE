<?php

declare(strict_types=1);

namespace App\Core\Blog\Preview;

use App\Core\Blog\StructuredContent\Rendering\BlogRenderingException;
use Throwable;

/** Loads the explicitly configured project adapter without leaking failures. */
final class BlogPreviewAssetAdapterLoader
{
    public function resolve(
        ?string $adapterPath,
        BlogPreviewAssetContext $context
    ): BlogPreviewAssetSet {
        if ($adapterPath === null) {
            return BlogPreviewAssetSet::standalone($context);
        }

        $bufferLevel = ob_get_level();
        ob_start();
        try {
            $adapter = (static function (string $path): mixed {
                return require $path;
            })($adapterPath);
            if (!$adapter instanceof BlogPreviewAssetAdapterInterface) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_PREVIEW_ASSETS
                );
            }
            $assets = $adapter->resolve($context);
            $output = ob_get_clean();
            if ($output !== '' || !$assets->belongsTo($context)) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_PREVIEW_ASSETS
                );
            }

            return $assets;
        } catch (Throwable $exception) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            if (
                $exception instanceof BlogRenderingException
                && $exception->issueCode()
                    === BlogRenderingException::INVALID_PREVIEW_ASSETS
            ) {
                throw $exception;
            }

            throw new BlogRenderingException(
                BlogRenderingException::INVALID_PREVIEW_ASSETS
            );
        }
    }
}
