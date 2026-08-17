<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Http\Request;
use App\Core\WebAdmin\Http\WebAdminHttpRequestPolicy;

/** Exact transport contract for the structured Blog editor. */
final class BlogStructuredEditorRequestPolicy
{
    private const UUID =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
    private const LOCALE = '/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/';
    private const METADATA_KEYS = [
        'h1',
        'slug',
        'seo_title',
        'meta_description',
        'excerpt',
    ];
    private const ROBOTS_KEYS = ['robots_index', 'robots_follow'];

    public function __construct(
        private readonly WebAdminHttpRequestPolicy $webAdminPolicy =
            new WebAdminHttpRequestPolicy()
    ) {
    }

    public function acceptsEditor(Request $request): bool
    {
        return $this->acceptsVariantNavigation($request, false);
    }

    public function acceptsPreview(Request $request): bool
    {
        return $this->acceptsVariantNavigation($request, false);
    }

    public function acceptsRevisions(Request $request): bool
    {
        return $this->acceptsVariantNavigation($request, true);
    }

    public function acceptsSave(Request $request): bool
    {
        $keys = array_merge([
            'csrf',
            'post',
            'locale',
            'lock_version',
            'document_json',
        ], self::METADATA_KEYS);

        $form = $request->formParams();
        $hasIndex = array_key_exists('robots_index', $form);
        $hasFollow = array_key_exists('robots_follow', $form);
        if ($hasIndex !== $hasFollow) {
            return false;
        }
        if ($hasIndex) {
            $keys = array_merge($keys, self::ROBOTS_KEYS);
        }

        if (!$this->webAdminPolicy->acceptsFormPost($request, $keys)) {
            return false;
        }

        return $this->validIdentity($request)
            && $this->hasEditorPayload($request)
            && is_string($request->form('document_json'))
            && (!$hasIndex
                || ($this->validBooleanFlag($request->form('robots_index'))
                    && $this->validBooleanFlag(
                        $request->form('robots_follow')
                    )));
    }

    public function acceptsSeoAnalysis(Request $request): bool
    {
        return $this->acceptsSave($request);
    }

    public function acceptsPublish(Request $request): bool
    {
        if (!$this->webAdminPolicy->acceptsFormPost($request, [
            'csrf',
            'post',
            'locale',
            'lock_version',
            'category_workspace_version',
        ])) {
            return false;
        }

        return $this->validIdentity($request)
            && $this->validNonNegativeVersion(
                $request->form('category_workspace_version')
            );
    }

    public function acceptsRestore(Request $request): bool
    {
        if (!$this->webAdminPolicy->acceptsFormPost($request, [
            'csrf',
            'post',
            'locale',
            'lock_version',
            'revision',
        ])) {
            return false;
        }

        return $this->validIdentity($request)
            && is_string($request->form('revision'))
            && preg_match(self::UUID, $request->form('revision')) === 1;
    }

    private function acceptsVariantNavigation(
        Request $request,
        bool $allowRevision
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
        $keys = array_keys($query);
        sort($keys, SORT_STRING);
        $expected = ['locale', 'post'];
        if ($allowRevision && array_key_exists('revision', $query)) {
            $expected[] = 'revision';
            sort($expected, SORT_STRING);
        }

        return $keys === $expected
            && is_string($query['post'] ?? null)
            && preg_match(self::UUID, $query['post']) === 1
            && is_string($query['locale'] ?? null)
            && preg_match(self::LOCALE, $query['locale']) === 1
            && (!array_key_exists('revision', $query)
                || (is_string($query['revision'])
                    && preg_match(self::UUID, $query['revision']) === 1));
    }

    private function validIdentity(Request $request): bool
    {
        $post = $request->form('post');
        $locale = $request->form('locale');
        $version = $request->form('lock_version');

        return is_string($post)
            && preg_match(self::UUID, $post) === 1
            && is_string($locale)
            && preg_match(self::LOCALE, $locale) === 1
            && is_string($version)
            && preg_match('/\A[1-9][0-9]{0,18}\z/', $version) === 1
            && (string) (int) $version === $version;
    }

    private function hasEditorPayload(Request $request): bool
    {
        foreach (self::METADATA_KEYS as $key) {
            if (!is_string($request->form($key))) {
                return false;
            }
        }

        return true;
    }

    private function validBooleanFlag(mixed $value): bool
    {
        return $value === '0' || $value === '1';
    }

    private function validNonNegativeVersion(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', $value) === 1
            && (string) (int) $value === $value;
    }
}
