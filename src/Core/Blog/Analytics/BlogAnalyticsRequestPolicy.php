<?php

declare(strict_types=1);

namespace App\Core\Blog\Analytics;

use App\Core\Http\Request;
use JsonException;

final class BlogAnalyticsRequestPolicy
{
    public const MAX_BODY_BYTES = 2048;
    public const MAX_PATH_BYTES = 2048;
    public const MAX_SEQUENCE = 1_000_000;
    public const MAX_ENGAGEMENT_MILLISECONDS = 86_400_000;

    public function acceptsJsonPost(
        Request $request,
        string $expectedOrigin
    ): bool {
        if (
            $request->method() !== 'POST'
            || !$request->isValid()
            || $request->queryParams() !== []
            || $request->formParams() !== []
            || $request->uploadedFiles() !== []
            || $request->bodySize() < 2
            || $request->bodySize() > self::MAX_BODY_BYTES
            || !$this->isJsonContentType($request->header('content-type'))
            || $request->header('origin') !== $expectedOrigin
        ) {
            return false;
        }
        $fetchSite = $request->header('sec-fetch-site');

        return $fetchSite === null || $fetchSite === 'same-origin';
    }

    /** @return array{page_grant: string}|null */
    public function startPayload(Request $request): ?array
    {
        $payload = $this->jsonObject($request);
        if (
            $payload === null
            || !$this->hasExactKeys($payload, ['page_grant'])
            || !is_string($payload['page_grant'])
            || !$this->isPageGrantToken($payload['page_grant'])
        ) {
            return null;
        }

        return ['page_grant' => $payload['page_grant']];
    }

    /**
     * @return array{
     *     engagement_msec: int,
     *     sequence: int,
     *     page_grant: string
     * }|null
     */
    public function engagementPayload(Request $request): ?array
    {
        $payload = $this->jsonObject($request);
        if (
            $payload === null
            || !$this->hasExactKeys($payload, [
                'engagement_msec',
                'page_grant',
                'sequence',
            ])
            || !is_int($payload['engagement_msec'])
            || !is_int($payload['sequence'])
            || !is_string($payload['page_grant'])
            || $payload['engagement_msec'] < 0
            || $payload['engagement_msec']
                > self::MAX_ENGAGEMENT_MILLISECONDS
            || $payload['sequence'] < 1
            || $payload['sequence'] > self::MAX_SEQUENCE
            || !$this->isPageGrantToken($payload['page_grant'])
        ) {
            return null;
        }

        return [
            'engagement_msec' => $payload['engagement_msec'],
            'page_grant' => $payload['page_grant'],
            'sequence' => $payload['sequence'],
        ];
    }

    private function isJsonContentType(?string $value): bool
    {
        return is_string($value)
            && preg_match(
                '/\Aapplication\/json(?:\s*;\s*charset=utf-8)?\z/i',
                trim($value)
            ) === 1;
    }

    private function isPageGrantToken(string $token): bool
    {
        return strlen($token) <= BlogAnalyticsPageGrantCodec::MAX_TOKEN_BYTES
            && preg_match(
                '/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}\z/D',
                $token
            ) === 1;
    }

    /** @return array<string, mixed>|null */
    private function jsonObject(Request $request): ?array
    {
        try {
            $payload = json_decode(
                $request->body(),
                true,
                8,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) && !array_is_list($payload)
            ? $payload
            : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $expected
     */
    private function hasExactKeys(array $payload, array $expected): bool
    {
        $actual = array_keys($payload);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }
}
