<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\Categories\BlogCategoryDraft;
use App\Core\Http\Request;

final class BlogCategoryAdminRequestPolicy
{
    private const UUID =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
    private const LOCALE = '/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/';

    public function acceptsIndex(Request $request): bool
    {
        return $this->safeGet($request) && $request->queryParams() === [];
    }

    public function acceptsNew(Request $request): bool
    {
        if (!$this->safeGet($request)) {
            return false;
        }
        $query = $request->queryParams();

        return $query === [] || (
            array_keys($query) === ['category']
            && $this->uuid($query['category'] ?? null)
        );
    }

    public function acceptsEdit(Request $request): bool
    {
        return $this->categoryLocaleQuery($request);
    }

    public function acceptsAssign(Request $request): bool
    {
        if (!$this->safeGet($request)) {
            return false;
        }
        $query = $request->queryParams();
        $keys = array_keys($query);
        sort($keys, SORT_STRING);

        return $keys === ['locale', 'post']
            && $this->uuid($query['post'] ?? null)
            && $this->locale($query['locale'] ?? null);
    }

    public function acceptsUpdated(Request $request): bool
    {
        return $this->safeGet($request) && $request->queryParams() === [];
    }

    public function acceptsCreate(Request $request): bool
    {
        return $this->scalarPost($request, [
            'csrf', 'category', 'locale', 'name', 'slug',
        ])
            && ($request->form('category') === ''
                || $this->uuid($request->form('category')))
            && $this->locale($request->form('locale'))
            && $this->draftFields($request);
    }

    public function acceptsSave(Request $request): bool
    {
        return $this->scalarPost($request, [
            'csrf', 'category', 'locale', 'lock_version', 'name', 'slug',
        ])
            && $this->uuid($request->form('category'))
            && $this->locale($request->form('locale'))
            && $this->lockVersion($request->form('lock_version'))
            && $this->draftFields($request);
    }

    public function acceptsAssignmentSave(Request $request): bool
    {
        if (!$this->formPost($request)) {
            return false;
        }
        $form = $request->formParams();
        $keys = array_keys($form);
        $expected = ['csrf', 'post'];
        if (array_key_exists('categories', $form)) {
            $expected[] = 'categories';
        }
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected || !$this->uuid($form['post'] ?? null)) {
            return false;
        }
        if (!is_string($form['csrf'] ?? null)) {
            return false;
        }
        if (!isset($form['categories'])) {
            return true;
        }
        $categories = $form['categories'];
        if (
            !is_array($categories)
            || !array_is_list($categories)
            || count($categories) > 100
        ) {
            return false;
        }
        $seen = [];
        foreach ($categories as $category) {
            if (!$this->uuid($category) || isset($seen[$category])) {
                return false;
            }
            $seen[$category] = true;
        }

        return true;
    }

    private function categoryLocaleQuery(Request $request): bool
    {
        if (!$this->safeGet($request)) {
            return false;
        }
        $query = $request->queryParams();
        $keys = array_keys($query);
        sort($keys, SORT_STRING);

        return $keys === ['category', 'locale']
            && $this->uuid($query['category'] ?? null)
            && $this->locale($query['locale'] ?? null);
    }

    /** @param list<string> $expectedKeys */
    private function scalarPost(Request $request, array $expectedKeys): bool
    {
        if (!$this->formPost($request)) {
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

    private function draftFields(Request $request): bool
    {
        $name = $request->form('name');
        $slug = $request->form('slug');

        return is_string($name)
            && trim($name) !== ''
            && strlen($name) <= BlogCategoryDraft::MAX_NAME_BYTES
            && is_string($slug)
            && strlen($slug) <= BlogCategoryDraft::MAX_SLUG_BYTES
            && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) === 1;
    }

    private function safeGet(Request $request): bool
    {
        return $request->isValid()
            && in_array($request->method(), ['GET', 'HEAD'], true)
            && $request->formParams() === []
            && $request->bodySize() === 0;
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

    private function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::UUID, $value) === 1;
    }

    private function locale(mixed $value): bool
    {
        return is_string($value) && preg_match(self::LOCALE, $value) === 1;
    }

    private function lockVersion(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[1-9][0-9]{0,18}\z/', $value) === 1
            && (string) (int) $value === $value;
    }
}
