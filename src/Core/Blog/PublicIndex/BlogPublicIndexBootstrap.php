<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicIndex;

use App\Core\Http\Response;
use RuntimeException;

/** Resolves the index and prepares all non-HTML HTTP/security state. */
final class BlogPublicIndexBootstrap
{
    public function __construct(private readonly BlogPublicIndex $index)
    {
    }

    public static function current(): self
    {
        return new self(BlogPublicIndex::current());
    }

    public function resolve(
        BlogPublicIndexInput $input,
        BlogPublicIndexTextCatalog $copy,
        BlogPublicIndexBootstrapOptions $options
    ): BlogPublicIndexBootstrapResult {
        $resolution = $this->index->resolve(
            $input,
            $copy,
            $options->preview()
        );
        $sharedSecurity = $options->securityPolicy()->context();
        $security = $sharedSecurity instanceof BlogPublicIndexSecurityContext
            ? $sharedSecurity
            : new BlogPublicIndexSecurityContext(
                $sharedSecurity->nonce(),
                $sharedSecurity->headers()
            );
        $redirect = $resolution->redirectResponse();
        if ($redirect instanceof Response) {
            return new BlogPublicIndexBootstrapResult(
                new Response(
                    $redirect->status(),
                    '',
                    array_replace(
                        $redirect->headers(),
                        $security->headers()
                    )
                ),
                null,
                $security,
                true
            );
        }

        $page = $resolution->page()
            ?? throw new RuntimeException(
                'Blog public-index resolution has no page.'
            );
        $headers = array_replace(
            $resolution->headers(),
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Language' => $page->locale(),
            ],
            $security->headers()
        );

        return new BlogPublicIndexBootstrapResult(
            new Response($resolution->statusCode(), '', $headers),
            $page,
            $security,
            $page->isHeadRequest()
        );
    }
}
