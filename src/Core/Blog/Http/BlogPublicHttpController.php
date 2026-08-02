<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogPostVariant;
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
            [$alternatePaths, $xDefaultPath] = $this->articleAlternates(
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
                    $xDefaultPath
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
                    $xDefaultPath
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

    public function sitemap(): Response
    {
        try {
            $xml = $this->sitemapRenderer->render(
                $this->runtime->service()->sitemapEntries(),
                $this->runtime->config(),
                $this->runtime->origin()
            );

            return new Response(200, $xml, [
                'Content-Type' => 'application/xml; charset=utf-8',
                'Cache-Control' => 'no-cache, must-revalidate',
                'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
                'Referrer-Policy' => 'no-referrer',
                'X-Content-Type-Options' => 'nosniff',
                'Cross-Origin-Resource-Policy' => 'same-origin',
            ]);
        } catch (BlogPublicHttpRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPublicHttpRuntimeException();
        }
    }

    /** @return array<string, string> */
    private function articleHeaders(): array
    {
        return [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-cache, must-revalidate',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
            'Permissions-Policy' =>
                'camera=(), microphone=(), geolocation=()',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
    }

    /**
     * @return array{array<string, string>, string}
     */
    private function articleAlternates(BlogPostVariant $variant): array
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

        return [$paths, $xDefaultPath];
    }
}
