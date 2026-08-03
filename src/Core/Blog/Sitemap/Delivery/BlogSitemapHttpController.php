<?php

declare(strict_types=1);

namespace App\Core\Blog\Sitemap\Delivery;

use App\Core\Http\Request;
use App\Core\Http\Response;

final class BlogSitemapHttpController
{
    public function __construct(
        private readonly BlogSitemapDeliveryService $delivery
    ) {
    }

    public function handle(Request $request): Response
    {
        $document = $this->delivery->document();
        $headers = $this->headers(
            $document->etag(),
            strlen($document->xml()),
            $document->stale()
        );
        if ($this->matchesIfNoneMatch($request, $document->etag())) {
            unset($headers['Content-Type'], $headers['Content-Length']);
            return new Response(304, '', $headers);
        }

        return new Response(200, $document->xml(), $headers);
    }

    /** @return array<string, string> */
    private function headers(string $etag, int $bytes, bool $stale): array
    {
        $headers = [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Length' => (string) $bytes,
            'Cache-Control' => 'public, no-cache, must-revalidate',
            'ETag' => $etag,
            'Content-Security-Policy' =>
                "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-LiquidStack-Sitemap-Source' => $stale ? 'stale-cache' : 'database',
        ];
        if ($stale) {
            $headers['Warning'] = '110 - "Response is stale"';
        }

        return $headers;
    }

    private function matchesIfNoneMatch(Request $request, string $etag): bool
    {
        if (!$request->hasValidHeaders()
            || !in_array($request->method(), ['GET', 'HEAD'], true)) {
            return false;
        }
        $value = $request->header('If-None-Match');
        if ($value === null) { return false; }
        if (trim($value) === '*') { return true; }

        return preg_match(
            '/(?:\A|,)[\x20\x09]*(?:W\/)?'
                . preg_quote($etag, '/')
                . '[\x20\x09]*(?=,|\z)/D',
            $value
        ) === 1;
    }
}
