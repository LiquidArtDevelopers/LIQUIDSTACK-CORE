<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogPostVariant;
use App\Core\Http\Request;
use App\Core\Http\Response;
use Throwable;

final class BlogPublicHttpController
{
    public function __construct(
        private readonly BlogPublicHttpRuntime $runtime,
        private readonly BlogPublicHtmlRenderer $articleRenderer =
            new BlogPublicHtmlRenderer(),
        private readonly BlogSitemapRenderer $sitemapRenderer =
            new BlogSitemapRenderer(),
        private readonly BlogPublicMediaHttpResponseFactory
            $mediaResponseFactory = new BlogPublicMediaHttpResponseFactory()
    ) {
    }

    public function article(string $locale, string $slug): ?Response
    {
        try {
            $variant = $this->runtime->service()->resolvePublished(
                $locale,
                $slug
            );
            if ($variant === null) {
                return null;
            }
            $base = $this->runtime->config()->publicPath($locale);
            if ($base === null) {
                throw new BlogPublicHttpRuntimeException();
            }
            [
                $alternatePaths,
                $xDefaultPath,
                $languageNavigationPaths,
            ] = $this->articleLinks(
                $variant
            );
            $structured = $this->runtime->structuredDocument(
                $variant->localizationPublicId()
            );
            $canonicalSlug = $variant->draft()->slug();
            if ($canonicalSlug === null) {
                throw new BlogPublicHttpRuntimeException();
            }
            $html = $structured === null
                ? $this->articleRenderer->renderFromOrigin(
                    $variant,
                    $this->runtime->origin(),
                    $base . '/' . $canonicalSlug,
                    $alternatePaths,
                    $xDefaultPath,
                    $languageNavigationPaths
                )
                : $this->articleRenderer->renderStructuredFromOrigin(
                    $variant,
                    $this->runtime->origin(),
                    $base . '/' . $canonicalSlug,
                    $structured->snapshot()->document(),
                    $this->runtime->imageResolver(
                        $variant->localizationPublicId()
                    ),
                    $alternatePaths,
                    $xDefaultPath,
                    $languageNavigationPaths
                );

            return new Response(
                200,
                $html,
                $this->articleHeaders()
            );
        } catch (BlogException $exception) {
            if ($exception->issueCode() === BlogException::INVALID_INPUT) {
                return null;
            }

            throw new BlogPublicHttpRuntimeException();
        } catch (BlogPublicHttpRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPublicHttpRuntimeException();
        }
    }

    public function media(
        string $mediaAssetPublicId,
        int $width,
        bool $head
    ): Response {
        try {
            $file = $this->runtime->mediaFile(
                $mediaAssetPublicId,
                $width,
                $head
            );

            return $file === null
                ? $this->mediaResponseFactory->notFound($head)
                : $this->mediaResponseFactory->success($file, $head);
        } catch (Throwable) {
            // Missing, unreferenced, unpublished, corrupt and unavailable
            // media are deliberately indistinguishable at the public edge.
            return $this->mediaResponseFactory->notFound($head);
        }
    }

    public function sitemap(?Request $request = null): Response
    {
        try {
            $xml = $this->sitemapRenderer->render(
                $this->runtime->service()->sitemapEntries(),
                $this->runtime->config(),
                $this->runtime->origin()
            );
            $etag = '"' . hash('sha256', $xml) . '"';
            if (
                $request instanceof Request
                && $this->matchesIfNoneMatch($request, $etag)
            ) {
                return new Response(304, '', $this->sitemapHeaders($etag));
            }

            return new Response(
                200,
                $xml,
                $this->sitemapHeaders($etag, strlen($xml))
            );
        } catch (BlogPublicHttpRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPublicHttpRuntimeException();
        }
    }

    /** @return array<string, string> */
    private function sitemapHeaders(
        string $etag,
        ?int $contentLength = null
    ): array {
        $headers = [
            'Cache-Control' => 'public, no-cache, must-revalidate',
            'ETag' => $etag,
            'Content-Security-Policy' =>
                "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        if ($contentLength !== null) {
            $headers = [
                'Content-Type' => 'application/xml; charset=utf-8',
                'Content-Length' => (string) $contentLength,
            ] + $headers;
        }

        return $headers;
    }

    private function matchesIfNoneMatch(Request $request, string $etag): bool
    {
        if (
            !$request->hasValidHeaders()
            || !in_array($request->method(), ['GET', 'HEAD'], true)
        ) {
            return false;
        }
        $value = $request->header('If-None-Match');
        if ($value === null) {
            return false;
        }
        if (trim($value) === '*') {
            return true;
        }

        return preg_match(
            '/(?:\A|,)[\x20\x09]*(?:W\/)?'
                . preg_quote($etag, '/')
                . '[\x20\x09]*(?=,|\z)/D',
            $value
        ) === 1;
    }

    /** @return array<string, string> */
    private function articleHeaders(): array
    {
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-cache, must-revalidate',
            'Permissions-Policy' =>
                'camera=(), microphone=(), geolocation=()',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];

        if (!$this->articleRenderer->usesProjectArticleView()) {
            $headers['Content-Security-Policy'] =
                "default-src 'none'; style-src 'self'; "
                . "script-src 'self'; script-src-attr 'none'; "
                . "img-src 'self' data:; "
                . "frame-src https://www.youtube-nocookie.com; "
                . "frame-ancestors 'none'; base-uri 'none'; "
                . "form-action 'none'";
        }

        return $headers;
    }

    /**
     * @return array{
     *     array<string, string>,
     *     string,
     *     array<string, string>
     * }
     */
    private function articleLinks(BlogPostVariant $variant): array
    {
        $slugs = [];
        foreach (
            $this->runtime->service()->publishedSitemapEntriesForPost(
                $variant->postPublicId()
            ) as $entry
        ) {
            if (
                $entry->postPublicId() !== null
                && !hash_equals(
                    $variant->postPublicId(),
                    $entry->postPublicId()
                )
            ) {
                throw new BlogPublicHttpRuntimeException();
            }
            if (isset($slugs[$entry->locale()])) {
                throw new BlogPublicHttpRuntimeException();
            }
            $slugs[$entry->locale()] = $entry->slug();
        }
        $currentSlug = $variant->draft()->slug();
        if ($currentSlug === null) {
            throw new BlogPublicHttpRuntimeException();
        }
        $slugs[$variant->locale()] = $currentSlug;

        $paths = [];
        foreach ($this->runtime->config()->publicPaths() as $locale => $base) {
            if (isset($slugs[$locale])) {
                $paths[$locale] = $base . '/' . $slugs[$locale];
            }
        }
        $xDefaultPath = $paths[$this->runtime->config()->defaultLocale()]
            ?? reset($paths);
        if (!is_string($xDefaultPath)) {
            throw new BlogPublicHttpRuntimeException();
        }

        $languageNavigationPaths = [];
        foreach ($this->runtime->config()->publicPaths() as $locale => $base) {
            $languageNavigationPaths[$locale] = isset($slugs[$locale])
                ? $base . '/' . $slugs[$locale]
                : $base;
        }

        return [$paths, $xDefaultPath, $languageNavigationPaths];
    }
}
