<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
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

        if (!$this->webAdminPolicy->acceptsFormPost($request, $keys)) {
            return false;
        }

        return $this->validIdentity($request)
            && $this->validMetadata($request)
            && is_string($request->form('document_json'))
            && strlen($request->form('document_json')) >= 2
            && strlen($request->form('document_json'))
                <= BlogDocument::MAX_JSON_BYTES;
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

    private function validMetadata(Request $request): bool
    {
        $limits = [
            'h1' => BlogDraft::MAX_H1_BYTES,
            'slug' => BlogDraft::MAX_SLUG_BYTES,
            'seo_title' => BlogDraft::MAX_SEO_TITLE_BYTES,
            'meta_description' => BlogDraft::MAX_META_DESCRIPTION_BYTES,
            'excerpt' => BlogDraft::MAX_EXCERPT_BYTES,
        ];
        foreach ($limits as $key => $limit) {
            $value = $request->form($key);
            if (!is_string($value) || strlen($value) > $limit) {
                return false;
            }
        }

        return trim((string) $request->form('h1')) !== '';
    }
}
