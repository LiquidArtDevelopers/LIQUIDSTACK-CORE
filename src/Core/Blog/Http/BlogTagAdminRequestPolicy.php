<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\Tags\BlogTagInput;
use App\Core\Blog\Tags\BlogTagService;
use App\Core\Http\Request;

/** Closed request contract for the single tag-assignment mutation. */
final class BlogTagAdminRequestPolicy
{
    /**
     * Native maxlength counts characters, while the domain limit counts
     * UTF-8 bytes. Keep enough transport headroom for 4096 four-byte scalars
     * so the domain can return a recoverable validation response.
     */
    public const MAX_TRANSPORT_CSV_BYTES =
        BlogTagService::MAX_CSV_BYTES * 4;

    private const UUID =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
    private const LOCALE = '/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/';

    public function acceptsAssignmentSave(Request $request): bool
    {
        if (!$this->formPost($request)) {
            return false;
        }
        $form = $request->formParams();
        $keys = array_keys($form);
        $expected = [
            'csrf',
            'post',
            'locale',
            'lock_version',
            'tag_workspace_version',
            'tags',
        ];
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            return false;
        }
        foreach ($form as $value) {
            if (!is_string($value)) {
                return false;
            }
        }
        $tags = $form['tags'];

        return $form['csrf'] !== ''
            && $this->uuid($form['post'])
            && $this->locale($form['locale'])
            && $this->positiveVersion($form['lock_version'])
            && $this->nonNegativeVersion($form['tag_workspace_version'])
            && strlen($tags) <= self::MAX_TRANSPORT_CSV_BYTES
            && BlogTagInput::hasSafeTextCharacters($tags);
    }

    private function formPost(Request $request): bool
    {
        return $request->isValid()
            && $request->method() === 'POST'
            && $request->queryParams() === []
            && strtolower(trim((string) strtok(
                $request->header('content-type', ''),
                ';'
            ))) === 'application/x-www-form-urlencoded';
    }

    private function uuid(string $value): bool
    {
        return preg_match(self::UUID, $value) === 1;
    }

    private function locale(string $value): bool
    {
        return preg_match(self::LOCALE, $value) === 1;
    }

    private function positiveVersion(string $value): bool
    {
        return preg_match('/\A[1-9][0-9]{0,18}\z/', $value) === 1
            && (string) (int) $value === $value;
    }

    private function nonNegativeVersion(string $value): bool
    {
        return preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', $value) === 1
            && (string) (int) $value === $value;
    }
}
