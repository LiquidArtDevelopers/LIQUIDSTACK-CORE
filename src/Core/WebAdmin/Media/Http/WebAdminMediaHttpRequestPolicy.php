<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Http;

use App\Core\Http\Request;

final class WebAdminMediaHttpRequestPolicy
{
    public function acceptsIndex(Request $request): bool
    {
        if (!$this->safe($request) || !$this->onlyKeys(
            $request->queryParams(),
            ['page']
        )) {
            return false;
        }
        $page = $request->query('page');

        return $page === null || (is_string($page)
            && preg_match('/\A[1-9][0-9]{0,5}\z/', $page) === 1);
    }

    public function acceptsUpdated(Request $request): bool
    {
        return $this->safe($request) && $request->queryParams() === [];
    }

    public function acceptsFile(Request $request): bool
    {
        return $this->safe($request)
            && $this->onlyKeys($request->queryParams(), ['asset', 'width'], true)
            && is_string($request->query('asset'))
            && preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $request->query('asset')
            ) === 1
            && is_string($request->query('width'))
            && preg_match('/\A[1-9][0-9]{0,3}\z/', $request->query('width')) === 1
            && (int) $request->query('width') <= 2560;
    }

    public function acceptsUpload(Request $request): bool
    {
        return $request->method() === 'POST'
            && $request->isValid()
            && $request->isMultipartFormData()
            && $request->queryParams() === []
            && $this->onlyKeys($request->formParams(), ['csrf', 'label'], true)
            && is_string($request->form('csrf'))
            && is_string($request->form('label'))
            && array_keys($request->uploadedFiles()) === ['image']
            && $request->uploadedFile('image') !== null;
    }

    private function safe(Request $request): bool
    {
        return in_array($request->method(), ['GET', 'HEAD'], true)
            && $request->isValid()
            && !$request->isMultipartFormData()
            && $request->formParams() === []
            && $request->uploadedFiles() === []
            && $request->body() === '';
    }

    /** @param array<string|int, mixed> $input @param list<string> $allowed */
    private function onlyKeys(
        array $input,
        array $allowed,
        bool $allRequired = false
    ): bool {
        foreach (array_keys($input) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                return false;
            }
        }

        return !$allRequired || count($input) === count($allowed);
    }
}
