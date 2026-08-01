<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use App\Core\Http\Request;

final class WebAdminHttpRequestPolicy
{
    public function acceptsSafeNavigation(Request $request): bool
    {
        return $request->isValid()
            && in_array($request->method(), ['GET', 'HEAD'], true)
            && $request->queryParams() === []
            && $request->formParams() === []
            && $request->bodySize() === 0;
    }

    /**
     * Credential links may carry exactly one opaque token on their first GET.
     * Token validity remains a domain concern so malformed and expired links
     * receive the same non-enumerating public outcome.
     */
    public function acceptsCredentialActionNavigation(
        Request $request
    ): bool {
        if (
            !$request->isValid()
            || !in_array($request->method(), ['GET', 'HEAD'], true)
            || $request->formParams() !== []
            || $request->bodySize() !== 0
        ) {
            return false;
        }

        $query = $request->queryParams();
        if ($query === []) {
            return true;
        }

        return array_keys($query) === ['token']
            && is_string($query['token']);
    }

    /** User listings may carry exactly one opaque pagination cursor. */
    public function acceptsUserListNavigation(Request $request): bool
    {
        if (
            !$request->isValid()
            || !in_array($request->method(), ['GET', 'HEAD'], true)
            || $request->formParams() !== []
            || $request->bodySize() !== 0
        ) {
            return false;
        }

        $query = $request->queryParams();
        if ($query === []) {
            return true;
        }

        return array_keys($query) === ['after']
            && is_string($query['after']);
    }

    /** User details require exactly one opaque target identifier. */
    public function acceptsUserDetailNavigation(Request $request): bool
    {
        if (
            !$request->isValid()
            || !in_array($request->method(), ['GET', 'HEAD'], true)
            || $request->formParams() !== []
            || $request->bodySize() !== 0
        ) {
            return false;
        }

        $query = $request->queryParams();

        return array_keys($query) === ['user']
            && is_string($query['user']);
    }

    /** @param list<string> $expectedKeys */
    public function acceptsFormPost(
        Request $request,
        array $expectedKeys
    ): bool {
        if (
            !$request->isValid()
            || $request->method() !== 'POST'
            || $request->queryParams() !== []
        ) {
            return false;
        }
        $contentType = strtolower(trim((string) strtok(
            $request->header('content-type', ''),
            ';'
        )));
        if ($contentType !== 'application/x-www-form-urlencoded') {
            return false;
        }
        if (!array_is_list($expectedKeys)) {
            return false;
        }

        $form = $request->formParams();
        $keys = array_keys($form);
        sort($keys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys) {
            return false;
        }
        foreach ($form as $value) {
            if (!is_string($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $expectedScalarKeys
     */
    public function acceptsCapabilitiesFormPost(
        Request $request,
        array $expectedScalarKeys
    ): bool {
        if (
            !$request->isValid()
            || $request->method() !== 'POST'
            || $request->queryParams() !== []
        ) {
            return false;
        }
        $contentType = strtolower(trim((string) strtok(
            $request->header('content-type', ''),
            ';'
        )));
        if ($contentType !== 'application/x-www-form-urlencoded') {
            return false;
        }
        if (
            !array_is_list($expectedScalarKeys)
            || !$this->validExpectedScalarKeys($expectedScalarKeys)
        ) {
            return false;
        }

        $form = $request->formParams();
        $actualKeys = array_keys($form);
        $expectedKeys = $expectedScalarKeys;
        if (array_key_exists('capabilities', $form)) {
            $expectedKeys[] = 'capabilities';
        }
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            return false;
        }

        foreach ($expectedScalarKeys as $key) {
            if (!is_string($form[$key] ?? null)) {
                return false;
            }
        }

        if (!array_key_exists('capabilities', $form)) {
            return true;
        }

        $capabilities = $form['capabilities'];
        if (
            !is_array($capabilities)
            || !array_is_list($capabilities)
            || count($capabilities) > 64
        ) {
            return false;
        }

        $seen = [];
        foreach ($capabilities as $capability) {
            if (
                !is_string($capability)
                || strlen($capability) > 128
                || preg_match(
                    '/\A[a-z][a-z0-9_.-]{2,127}\z/',
                    $capability
                ) !== 1
                || isset($seen[$capability])
            ) {
                return false;
            }
            $seen[$capability] = true;
        }

        return true;
    }

    /** @param list<string> $keys */
    private function validExpectedScalarKeys(array $keys): bool
    {
        $seen = [];
        foreach ($keys as $key) {
            if (
                !is_string($key)
                || $key === 'capabilities'
                || preg_match('/\A[a-z][a-z0-9_]{0,127}\z/', $key) !== 1
                || isset($seen[$key])
            ) {
                return false;
            }
            $seen[$key] = true;
        }

        return true;
    }
}
