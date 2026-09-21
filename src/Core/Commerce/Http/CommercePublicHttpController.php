<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use App\Core\Commerce\LocalizedProduct;
use App\Core\Http\Request;
use App\Core\Http\Response;
use Throwable;

final class CommercePublicHttpController
{
    public function __construct(
        private readonly CommercePublicHttpRuntime $runtime,
        private readonly CommercePublicItemRenderer $renderer
    ) {
    }

    public function item(
        string $locale,
        string $path,
        ?string $basketToken = null
    ): ?Response
    {
        try {
            $resolution = $this->runtime->catalog()->resolvePublicPath(
                $path,
                $locale,
                $this->runtime->config()->defaultLocale()
            );
            if ($resolution === null) {
                return null;
            }
            if ($resolution->isRedirect()) {
                return new Response(301, '', [
                    'Location' => $resolution->currentPath(),
                    'Cache-Control' => 'public, max-age=300, must-revalidate',
                    'X-Robots-Tag' => 'noindex, follow',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }

            $product = $resolution->product();
            $detail = $this->runtime->product(
                $product->publicId(),
                $locale
            );
            if (
                $detail === null
                || $detail->product()->publicId() !== $product->publicId()
                || $detail->product()->publicPath() !== $product->publicPath()
            ) {
                throw new CommercePublicHttpRuntimeException(
                    'commerce.public_product_projection_unavailable'
                );
            }
            $canonicalProduct = $product;
            if ($product->isFallback()) {
                $canonicalProduct = $this->runtime->catalog()
                    ->localizedProduct(
                        $product->publicId(),
                        $this->runtime->config()->defaultLocale(),
                        $this->runtime->config()->defaultLocale()
                    );
                if (!$canonicalProduct instanceof LocalizedProduct) {
                    throw new CommercePublicHttpRuntimeException(
                        'commerce.primary_localization_unavailable'
                    );
                }
            }
            $canonicalPath = $canonicalProduct->publicPath();
            if ($canonicalPath === null) {
                throw new CommercePublicHttpRuntimeException(
                    'commerce.public_path_unavailable'
                );
            }
            $alternates = $this->translatedAlternates($product->publicId());
            $config = $this->runtime->config();
            $count = null;
            if ($config->socialProofEnabled()) {
                $measured = $this->runtime->catalog()->inquiryCount(
                    $product->publicId()
                );
                if ($measured >= $config->socialProofMinimumCount()) {
                    $count = $measured;
                }
            }
            $catalogPath = $config->publicPath($locale);
            if ($catalogPath === null) {
                return null;
            }
            $basketProductIds = [];
            try {
                $basket = $this->runtime->basket($basketToken, new \DateTimeImmutable(
                    'now',
                    new \DateTimeZone('UTC')
                ));
                if ($basket !== null && $basket->status() === 'open') {
                    foreach ($basket->lines() as $line) {
                        $basketProductIds[] = $line->product()->publicId();
                    }
                }
            } catch (\App\Core\Commerce\CommerceValidationException) {
                // A malformed opaque cookie is treated as an empty basket.
            }
            $page = new CommercePublicItemPage(
                $detail,
                $this->runtime->absoluteUrl($canonicalPath),
                $product->isFallback()
                    ? 'noindex, follow'
                    : 'index, follow',
                $alternates,
                $catalogPath,
                $config->inquiryPath($locale) ?? $catalogPath . '/inquiry',
                $count,
                $basketProductIds
            );

            return new Response(
                200,
                $this->renderer->render($page),
                [
                    'Content-Type' => 'text/html; charset=utf-8',
                    'Cache-Control' => $basketToken === null
                        ? 'public, max-age=60, must-revalidate'
                        : 'private, no-cache, must-revalidate',
                    'X-Robots-Tag' => $page->robotsDirective(),
                    'X-Content-Type-Options' => 'nosniff',
                    'Referrer-Policy' => 'strict-origin-when-cross-origin',
                ]
            );
        } catch (CommercePublicHttpRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new CommercePublicHttpRuntimeException(
                'commerce.public_item_unavailable'
            );
        }
    }

    public function sitemap(Request $request): Response
    {
        try {
            $urls = [];
            $config = $this->runtime->config();
            foreach ($config->publicPaths() as $locale => $basePath) {
                $urls[$this->runtime->absoluteUrl($basePath)] = true;
                for ($offset = 0; $offset <= 10_000; $offset += 100) {
                    $products = $this->runtime->catalog()->listPublished(
                        $locale,
                        $config->defaultLocale(),
                        100,
                        $offset
                    );
                    foreach ($products as $product) {
                        if ($product->isFallback()) {
                            continue;
                        }
                        $path = $product->publicPath();
                        if ($path !== null) {
                            $urls[$this->runtime->absoluteUrl($path)] = true;
                        }
                    }
                    if (count($products) < 100) {
                        break;
                    }
                }
            }
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'
                . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach (array_keys($urls) as $url) {
                $xml .= '<url><loc>' . htmlspecialchars(
                    $url,
                    ENT_XML1 | ENT_QUOTES,
                    'UTF-8'
                ) . '</loc></url>';
            }
            $xml .= '</urlset>';
            $etag = '"' . hash('sha256', $xml) . '"';
            $ifNoneMatch = $request->header('If-None-Match');
            if (
                is_string($ifNoneMatch)
                && in_array($etag, array_map(
                    'trim',
                    explode(',', $ifNoneMatch)
                ), true)
            ) {
                return new Response(304, '', $this->sitemapHeaders($etag));
            }

            return new Response(
                200,
                $xml,
                $this->sitemapHeaders($etag) + [
                    'Content-Length' => (string) strlen($xml),
                ]
            );
        } catch (Throwable) {
            throw new CommercePublicHttpRuntimeException(
                'commerce.sitemap_unavailable'
            );
        }
    }

    public function media(
        string $mediaAssetPublicId,
        int $width,
        bool $head
    ): Response {
        $responses = new CommercePublicMediaHttpResponseFactory();
        try {
            $file = $this->runtime->mediaFile(
                $mediaAssetPublicId,
                $width,
                $head
            );

            return $file === null
                ? $responses->notFound($head)
                : $responses->success($file, $head);
        } catch (Throwable) {
            // Unreferenced, draft, corrupt and unavailable media are
            // intentionally indistinguishable at the public boundary.
            return $responses->notFound($head);
        }
    }

    /** @return array<string, string> */
    private function translatedAlternates(string $productPublicId): array
    {
        $config = $this->runtime->config();
        $alternates = [];
        foreach (array_keys($config->publicPaths()) as $locale) {
            $localized = $this->runtime->catalog()->localizedProduct(
                $productPublicId,
                $locale,
                $config->defaultLocale()
            );
            if (
                $localized instanceof LocalizedProduct
                && !$localized->isFallback()
                && $localized->publicPath() !== null
            ) {
                $alternates[$locale] = $localized->publicPath();
            }
        }

        return $alternates;
    }

    /** @return array<string, string> */
    private function sitemapHeaders(string $etag): array
    {
        return [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Cache-Control' => 'public, no-cache, must-revalidate',
            'ETag' => $etag,
            'Content-Security-Policy' =>
                "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, follow',
        ];
    }
}
