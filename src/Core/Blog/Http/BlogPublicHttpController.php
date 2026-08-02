<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogException;
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
            $structured = $this->runtime->structuredDocument(
                $variant->localizationPublicId()
            );
            $html = $structured === null
                ? $this->articleRenderer->renderFromOrigin(
                    $variant,
                    $this->runtime->origin(),
                    $base . '/' . $slug
                )
                : $this->articleRenderer->renderStructuredFromOrigin(
                    $variant,
                    $this->runtime->origin(),
                    $base . '/' . $slug,
                    $structured->snapshot()->document(),
                    $this->runtime->imageResolver(
                        $variant->localizationPublicId()
                    )
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
}
