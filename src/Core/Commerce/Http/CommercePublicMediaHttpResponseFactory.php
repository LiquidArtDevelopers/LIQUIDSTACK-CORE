<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use App\Core\Commerce\Media\CommercePublicMediaFile;
use App\Core\Http\Response;

final class CommercePublicMediaHttpResponseFactory
{
    public function success(
        CommercePublicMediaFile $file,
        bool $head
    ): Response {
        return new Response(
            200,
            $head ? '' : $file->contents(),
            [
                'Content-Type' => 'image/avif',
                'Content-Length' => (string) $file->bytes(),
                'ETag' => $file->etag(),
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'Content-Security-Policy' =>
                    "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
                'Referrer-Policy' => 'no-referrer',
                'X-Content-Type-Options' => 'nosniff',
                'Cross-Origin-Resource-Policy' => 'same-origin',
            ]
        );
    }

    public function notFound(bool $head): Response
    {
        $body = 'Not found';

        return new Response(
            404,
            $head ? '' : $body,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Length' => (string) strlen($body),
                'Cache-Control' => 'no-store',
                'Content-Security-Policy' =>
                    "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
                'Referrer-Policy' => 'no-referrer',
                'X-Content-Type-Options' => 'nosniff',
                'Cross-Origin-Resource-Policy' => 'same-origin',
            ]
        );
    }
}
