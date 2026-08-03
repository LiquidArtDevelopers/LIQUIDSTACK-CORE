<?php

declare(strict_types=1);

namespace App\Core\Blog\Sitemap\Delivery;

use App\Core\Blog\Http\BlogPublicHttpRuntimeException;
use App\Core\Blog\Sitemap\Cache\BlogSitemapCacheIdentity;
use App\Core\Blog\Sitemap\Cache\PrivateBlogSitemapCacheStorage;
use Closure;
use Throwable;

final class BlogSitemapDeliveryService
{
    /** @var Closure(): BlogSitemapDeliveryDocument */
    private readonly Closure $freshSource;

    /** @param callable(): BlogSitemapDeliveryDocument $freshSource */
    public function __construct(
        callable $freshSource,
        private readonly ?PrivateBlogSitemapCacheStorage $storage = null,
        private readonly ?BlogSitemapCacheIdentity $identity = null
    ) {
        $this->freshSource = Closure::fromCallable($freshSource);
    }

    public function document(): BlogSitemapDeliveryDocument
    {
        try {
            $document = ($this->freshSource)();
            if (!$document instanceof BlogSitemapDeliveryDocument) {
                throw new BlogPublicHttpRuntimeException();
            }

            return $document;
        } catch (BlogSitemapSourceUnavailable) {
            if ($this->storage === null || $this->identity === null) {
                throw new BlogPublicHttpRuntimeException();
            }
            try {
                $snapshot = $this->storage->readValid(
                    $this->identity,
                    time()
                );
                if ($snapshot === null) {
                    throw new BlogPublicHttpRuntimeException();
                }

                return new BlogSitemapDeliveryDocument(
                    $snapshot->xml(),
                    $snapshot->etag(),
                    true
                );
            } catch (BlogPublicHttpRuntimeException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new BlogPublicHttpRuntimeException();
            }
        } catch (BlogPublicHttpRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPublicHttpRuntimeException();
        }
    }
}
