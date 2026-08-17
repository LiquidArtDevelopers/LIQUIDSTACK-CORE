<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Http;

use App\Core\Http\Request;
use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\MediaPickerQuery;

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

    public function acceptsCatalog(Request $request): bool
    {
        if (
            !$this->safe($request)
            || !$this->onlyKeys(
                $request->queryParams(),
                ['q', 'page', 'per_page']
            )
            || strtolower(trim((string) $request->header(
                'x-liquidstack-media-picker'
            ))) !== 'async'
            || preg_match(
                '/(?:^|,)\s*application\/json(?:\s*;[^,]*)?(?:,|$)/i',
                (string) $request->header('accept')
            ) !== 1
        ) {
            return false;
        }
        $search = $request->query('q');
        $page = $request->query('page');
        $pageSize = $request->query('per_page');
        if (
            $search !== null && !is_string($search)
            || $page !== null && (
                !is_string($page)
                || preg_match('/\A[1-9][0-9]{0,5}\z/', $page) !== 1
            )
            || $pageSize !== null && (
                !is_string($pageSize)
                || preg_match('/\A(?:12|24|48)\z/', $pageSize) !== 1
            )
        ) {
            return false;
        }
        try {
            new MediaPickerQuery(
                $search,
                $page === null ? 1 : (int) $page,
                $pageSize === null
                    ? MediaPickerQuery::DEFAULT_PAGE_SIZE
                    : (int) $pageSize
            );

            return true;
        } catch (MediaException) {
            return false;
        }
    }

    public function acceptsDeleted(Request $request): bool
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
            && $this->onlyKeys(
                $request->formParams(),
                ['csrf', 'label', 'idempotency_key'],
                true
            )
            && is_string($request->form('csrf'))
            && is_string($request->form('label'))
            && is_string($request->form('idempotency_key'))
            && preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $request->form('idempotency_key')
            ) === 1
            && array_keys($request->uploadedFiles()) === ['image']
            && $request->uploadedFile('image') !== null;
    }

    public function acceptsDelete(Request $request): bool
    {
        return $request->method() === 'POST'
            && $request->isValid()
            && !$request->isMultipartFormData()
            && $request->queryParams() === []
            && $request->uploadedFiles() === []
            && $this->onlyKeys(
                $request->formParams(),
                [
                    'csrf',
                    'asset',
                    'asset_version',
                    'idempotency_key',
                    'page',
                ],
                true
            )
            && is_string($request->form('csrf'))
            && is_string($request->form('asset'))
            && preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $request->form('asset')
            ) === 1
            && is_string($request->form('asset_version'))
            && preg_match(
                '/\A[0-9a-f]{64}\z/',
                $request->form('asset_version')
            ) === 1
            && is_string($request->form('idempotency_key'))
            && preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $request->form('idempotency_key')
            ) === 1
            && is_string($request->form('page'))
            && preg_match('/\A[1-9][0-9]{0,5}\z/',
                $request->form('page')) === 1;
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
