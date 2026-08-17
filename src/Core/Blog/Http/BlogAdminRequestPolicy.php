<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\Admin\BlogAdminCatalogQuery;
use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogService;
use App\Core\Http\Request;
use App\Core\WebAdmin\Http\WebAdminHttpRequestPolicy;

final class BlogAdminRequestPolicy
{
    private const UUID =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
    private const UUID_V4 =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
    private const LOCALE = '/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/';
    private const EDITORIAL_KEYS = [
        'h1',
        'slug',
        'seo_title',
        'meta_description',
        'excerpt',
        'body_text',
    ];

    public function __construct(
        private readonly WebAdminHttpRequestPolicy $webAdminPolicy =
            new WebAdminHttpRequestPolicy()
    ) {
    }

    public function acceptsIndex(Request $request): bool
    {
        if (!$this->safeGet($request)) {
            return false;
        }
        $query = $request->queryParams();
        foreach ($query as $key => $value) {
            if (
                !is_string($key)
                || !is_string($value)
                || !in_array(
                    $key,
                    [
                        'dir',
                        'locale',
                        'offset',
                        'per_page',
                        'period',
                        'q',
                        'sort',
                        'status',
                    ],
                    true
                )
            ) {
                return false;
            }
        }

        $pageSize = array_key_exists('per_page', $query)
            && $this->validPageSize($query['per_page'])
            ? (int) $query['per_page']
            : BlogAdminCatalogQuery::DEFAULT_PAGE_SIZE;

        return (!array_key_exists('per_page', $query)
                || $this->validPageSize($query['per_page']))
            && (!array_key_exists('offset', $query)
                || $this->validOffset($query['offset'], $pageSize))
            && (!array_key_exists('period', $query)
                || in_array($query['period'], ['7', '30', '90'], true))
            && (!array_key_exists('q', $query)
                || strlen($query['q'])
                    <= BlogAdminCatalogQuery::MAX_SEARCH_INPUT_BYTES)
            && (!array_key_exists('status', $query)
                || in_array(
                    $query['status'],
                    ['', BlogPostVariant::DRAFT, BlogPostVariant::PUBLISHED],
                    true
                ))
            && (!array_key_exists('locale', $query)
                || $query['locale'] === ''
                || $this->validLocale($query['locale']))
            && (!array_key_exists('sort', $query)
                || BlogAdminCatalogQuery::supportsSort($query['sort']))
            && (!array_key_exists('dir', $query)
                || BlogAdminCatalogQuery::supportsDirection($query['dir']));
    }

    public function acceptsTrashIndex(Request $request): bool
    {
        if (!$this->safeGet($request)) {
            return false;
        }
        $query = $request->queryParams();

        return $query === []
            || (
                array_keys($query) === ['offset']
                && is_string($query['offset'])
                && $this->validOffset(
                    $query['offset'],
                    BlogService::DEFAULT_LIST_LIMIT
                )
            );
    }

    public function acceptsUpdated(Request $request): bool
    {
        return $this->webAdminPolicy->acceptsSafeNavigation($request);
    }

    public function acceptsNew(Request $request): bool
    {
        if (!$this->safeGet($request)) {
            return false;
        }
        $query = $request->queryParams();

        return $query === []
            || (
                array_keys($query) === ['post']
                && is_string($query['post'])
                && preg_match(self::UUID, $query['post']) === 1
            );
    }

    public function acceptsEdit(Request $request): bool
    {
        return $this->acceptsVariantQuery($request);
    }

    public function acceptsPreview(Request $request): bool
    {
        return $this->acceptsVariantQuery($request);
    }

    public function acceptsUrlManager(Request $request): bool
    {
        return $this->acceptsVariantQuery($request);
    }

    private function acceptsVariantQuery(Request $request): bool
    {
        if (!$this->safeGet($request)) {
            return false;
        }
        $query = $request->queryParams();
        $keys = array_keys($query);
        sort($keys, SORT_STRING);

        return $keys === ['locale', 'post']
            && is_string($query['post'] ?? null)
            && preg_match(self::UUID, $query['post']) === 1
            && is_string($query['locale'] ?? null)
            && preg_match(self::LOCALE, $query['locale']) === 1;
    }

    public function acceptsCreate(Request $request): bool
    {
        $keys = array_merge(
            ['csrf', 'post', 'locale'],
            self::EDITORIAL_KEYS
        );

        return $this->webAdminPolicy->acceptsFormPost($request, $keys)
            && $this->validPost((string) $request->form('post'), true)
            && $this->validLocale((string) $request->form('locale'))
            && $this->validEditorialFields($request);
    }

    public function acceptsSave(Request $request): bool
    {
        $keys = array_merge(
            ['csrf', 'post', 'locale', 'lock_version'],
            self::EDITORIAL_KEYS
        );

        return $this->webAdminPolicy->acceptsFormPost($request, $keys)
            && $this->validPost((string) $request->form('post'), false)
            && $this->validLocale((string) $request->form('locale'))
            && $this->validLockVersion(
                (string) $request->form('lock_version')
            )
            && $this->validEditorialFields($request);
    }

    public function acceptsTransition(Request $request): bool
    {
        return $this->webAdminPolicy->acceptsFormPost($request, [
            'csrf',
            'post',
            'locale',
            'lock_version',
        ])
            && $this->validPost((string) $request->form('post'), false)
            && $this->validLocale((string) $request->form('locale'))
            && $this->validLockVersion(
                (string) $request->form('lock_version')
            );
    }

    public function acceptsUrlResolution(Request $request): bool
    {
        return $this->webAdminPolicy->acceptsFormPost($request, [
            'csrf',
            'post',
            'locale',
            'lock_version',
            'historical_slug',
            'resolution',
            'replacement_post',
        ])
            && $this->validPost((string) $request->form('post'), false)
            && $this->validLocale((string) $request->form('locale'))
            && $this->validLockVersion((string) $request->form('lock_version'))
            && is_string($request->form('historical_slug'))
            && strlen((string) $request->form('historical_slug')) <= BlogDraft::MAX_SLUG_BYTES
            && preg_match(
                '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                (string) $request->form('historical_slug')
            ) === 1
            && in_array(
                $request->form('resolution'),
                ['gone', 'redirect'],
                true
            )
            && (
                $request->form('resolution') === 'gone'
                    ? $request->form('replacement_post') === ''
                    : $this->validPost(
                        (string) $request->form('replacement_post'),
                        false
                    )
            );
    }

    public function acceptsDuplicate(Request $request): bool
    {
        return $this->webAdminPolicy->acceptsFormPost($request, [
            'csrf',
            'post',
            'locale',
            'destination_locale',
            'lock_version',
            'operation_id',
        ])
            && $this->validPost((string) $request->form('post'), false)
            && $this->validLocale((string) $request->form('locale'))
            && $this->validLocale(
                (string) $request->form('destination_locale')
            )
            && $this->validLockVersion(
                (string) $request->form('lock_version')
            )
            && is_string($request->form('operation_id'))
            && preg_match(
                self::UUID_V4,
                (string) $request->form('operation_id')
            ) === 1;
    }

    public function acceptsTrash(Request $request): bool
    {
        return $this->acceptsEditorialAction($request);
    }

    public function acceptsRestoreFromTrash(Request $request): bool
    {
        return $this->acceptsEditorialAction($request);
    }

    private function acceptsEditorialAction(Request $request): bool
    {
        return $this->webAdminPolicy->acceptsFormPost($request, [
            'csrf',
            'post',
            'locale',
            'lock_version',
        ])
            && $this->validPost((string) $request->form('post'), false)
            && $this->validLocale((string) $request->form('locale'))
            && $this->validLockVersion(
                (string) $request->form('lock_version')
            );
    }

    private function safeGet(Request $request): bool
    {
        return $request->isValid()
            && in_array($request->method(), ['GET', 'HEAD'], true)
            && $request->formParams() === []
            && $request->bodySize() === 0;
    }

    private function validPost(string $value, bool $mayBeEmpty): bool
    {
        return ($mayBeEmpty && $value === '')
            || preg_match(self::UUID, $value) === 1;
    }

    private function validLocale(string $value): bool
    {
        return preg_match(self::LOCALE, $value) === 1;
    }

    private function validLockVersion(string $value): bool
    {
        return preg_match('/\A[1-9][0-9]{0,18}\z/', $value) === 1
            && (string) (int) $value === $value;
    }

    private function validOffset(string $value, int $pageSize): bool
    {
        return preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1
            && (string) (int) $value === $value
            && (int) $value <= BlogService::MAX_LIST_OFFSET
            && $pageSize > 0
            && (int) $value % $pageSize === 0;
    }

    private function validPageSize(string $value): bool
    {
        return preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1
            && (string) (int) $value === $value
            && BlogAdminCatalogQuery::supportsPageSize((int) $value);
    }

    private function validEditorialFields(Request $request): bool
    {
        $limits = [
            'h1' => BlogDraft::MAX_H1_BYTES,
            'slug' => BlogDraft::MAX_SLUG_BYTES,
            'seo_title' => BlogDraft::MAX_SEO_TITLE_BYTES,
            'meta_description' => BlogDraft::MAX_META_DESCRIPTION_BYTES,
            'excerpt' => BlogDraft::MAX_EXCERPT_BYTES,
            'body_text' => BlogDraft::MAX_BODY_BYTES,
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
