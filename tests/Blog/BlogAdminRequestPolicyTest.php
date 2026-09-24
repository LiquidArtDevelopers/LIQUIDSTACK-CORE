<?php

declare(strict_types=1);

use App\Core\Blog\Admin\BlogAdminCatalogQuery;
use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogService;
use App\Core\Blog\Http\BlogAdminRequestPolicy;
use App\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class BlogAdminRequestPolicyTest extends TestCase
{
    private BlogAdminRequestPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new BlogAdminRequestPolicy();
    }

    public function testSafeRoutesAcceptOnlyTheirExactQueries(): void
    {
        self::assertTrue($this->policy->acceptsIndex($this->get('/admin/blog')));
        foreach (['0', '20', (string) BlogService::MAX_LIST_OFFSET] as $offset) {
            self::assertTrue($this->policy->acceptsIndex($this->get(
                '/admin/blog',
                ['offset' => $offset]
            )), $offset);
        }
        foreach (['7', '30', '90'] as $period) {
            self::assertTrue($this->policy->acceptsIndex($this->get(
                '/admin/blog',
                ['period' => $period]
            )), $period);
            self::assertTrue($this->policy->acceptsIndex($this->get(
                '/admin/blog',
                ['offset' => '20', 'period' => $period]
            )), $period);
        }
        self::assertTrue($this->policy->acceptsIndex($this->get(
            '/admin/blog',
            [
                'q' => 'Matrix',
                'status' => 'published',
                'locale' => 'eu',
                'period' => '90',
                'offset' => '50',
                'per_page' => '50',
                'sort' => BlogAdminCatalogQuery::SORT_AUTHOR,
                'dir' => BlogAdminCatalogQuery::DIRECTION_ASC,
            ]
        )));
        foreach ([10, 20, 50] as $pageSize) {
            self::assertTrue($this->policy->acceptsIndex($this->get(
                '/admin/blog',
                [
                    'per_page' => (string) $pageSize,
                    'offset' => (string) $pageSize,
                ]
            )), (string) $pageSize);
        }
        foreach ([
            BlogAdminCatalogQuery::SORT_TITLE,
            BlogAdminCatalogQuery::SORT_LOCALE,
            BlogAdminCatalogQuery::SORT_STATUS,
            BlogAdminCatalogQuery::SORT_AUTHOR,
            BlogAdminCatalogQuery::SORT_ROBOTS,
            BlogAdminCatalogQuery::SORT_UPDATED,
        ] as $sort) {
            foreach (['asc', 'desc'] as $direction) {
                self::assertTrue($this->policy->acceptsIndex($this->get(
                    '/admin/blog',
                    ['sort' => $sort, 'dir' => $direction]
                )), $sort . ':' . $direction);
            }
        }
        self::assertTrue($this->policy->acceptsIndex($this->get(
            '/admin/blog',
            ['q' => '', 'status' => '', 'locale' => '']
        )));
        foreach ([
            ['status' => 'archived'],
            ['locale' => 'ES'],
            ['q' => str_repeat(
                'x',
                BlogAdminCatalogQuery::MAX_SEARCH_INPUT_BYTES + 1
            )],
        ] as $invalidFilter) {
            self::assertFalse($this->policy->acceptsIndex($this->get(
                '/admin/blog',
                $invalidFilter
            )));
        }
        foreach (['', '0', '07', '14', '365'] as $period) {
            self::assertFalse($this->policy->acceptsIndex($this->get(
                '/admin/blog',
                ['period' => $period]
            )), $period);
        }
        foreach (
            [
                '',
                '00',
                '+1',
                '-1',
                '1',
                '49',
                '50',
                '51',
                '1.0',
                (string) (BlogService::MAX_LIST_OFFSET + 1),
            ]
            as $offset
        ) {
            self::assertFalse($this->policy->acceptsIndex($this->get(
                '/admin/blog',
                ['offset' => $offset]
            )), $offset);
        }
        self::assertFalse($this->policy->acceptsIndex($this->get(
            '/admin/blog',
            ['offset' => '20', 'extra' => 'x']
        )));
        foreach (['', '0', '15', '020', '100'] as $pageSize) {
            self::assertFalse($this->policy->acceptsIndex($this->get(
                '/admin/blog',
                ['per_page' => $pageSize]
            )), $pageSize);
        }
        foreach (['categories', 'updated_at DESC', 'UPDATED'] as $sort) {
            self::assertFalse($this->policy->acceptsIndex($this->get(
                '/admin/blog',
                ['sort' => $sort]
            )), $sort);
        }
        foreach (['', 'ASC', 'sideways'] as $direction) {
            self::assertFalse($this->policy->acceptsIndex($this->get(
                '/admin/blog',
                ['dir' => $direction]
            )), $direction);
        }
        self::assertTrue($this->policy->acceptsUpdated($this->get(
            '/admin/blog/posts/updated'
        )));
        self::assertFalse($this->policy->acceptsUpdated($this->get(
            '/admin/blog/posts/updated',
            ['offset' => '50']
        )));
        self::assertTrue($this->policy->acceptsNew($this->get(
            '/admin/blog/posts/new',
            ['post' => $this->uuid()]
        )));
        self::assertTrue($this->policy->acceptsEdit($this->get(
            '/admin/blog/posts/edit',
            ['post' => $this->uuid(), 'locale' => 'es']
        )));
        self::assertFalse($this->policy->acceptsEdit($this->get(
            '/admin/blog/posts/edit',
            ['post' => $this->uuid(), 'locale' => 'ES', 'extra' => 'x']
        )));
        self::assertTrue($this->policy->acceptsTrashIndex($this->get(
            '/admin/blog/trash',
            ['offset' => '50']
        )));
        self::assertFalse($this->policy->acceptsTrashIndex($this->get(
            '/admin/blog/trash',
            ['period' => '30']
        )));
    }

    public function testCreateAndSaveAcceptExactBoundedFormContracts(): void
    {
        $create = $this->post('/admin/blog/posts/create', [
            'csrf' => 'csrf',
            'post' => '',
            'locale' => 'es',
        ] + $this->editorial());
        self::assertTrue($this->policy->acceptsCreate($create));

        $save = $this->post('/admin/blog/posts/save', [
            'csrf' => 'csrf',
            'post' => $this->uuid(),
            'locale' => 'es',
            'lock_version' => '2',
        ] + $this->editorial());
        self::assertTrue($this->policy->acceptsSave($save));

        $largeBody = $this->editorial();
        $largeBody['body_text'] = str_repeat('a', 20_000);
        self::assertTrue($this->policy->acceptsSave($this->post(
            '/admin/blog/posts/save',
            [
                'csrf' => 'csrf',
                'post' => $this->uuid(),
                'locale' => 'es',
                'lock_version' => '3',
            ] + $largeBody
        )));
    }

    public function testPreviewAcceptsOnlyExactStoredVariantNavigation(): void
    {
        $query = ['post' => $this->uuid(), 'locale' => 'es'];
        self::assertTrue($this->policy->acceptsPreview($this->get(
            '/admin/blog/posts/preview',
            $query
        )));
        self::assertTrue($this->policy->acceptsPreview(Request::fromInput([
            'REQUEST_METHOD' => 'HEAD',
            'REQUEST_URI' => '/admin/blog/posts/preview',
            'HTTPS' => 'on',
        ], query: $query)));

        self::assertFalse($this->policy->acceptsPreview($this->get(
            '/admin/blog/posts/preview',
            $query + ['extra' => 'x']
        )));
        self::assertFalse($this->policy->acceptsPreview($this->get(
            '/admin/blog/posts/preview',
            ['post' => $this->uuid(), 'locale' => 'ES']
        )));
        self::assertFalse($this->policy->acceptsPreview(Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/blog/posts/preview',
            'HTTPS' => 'on',
        ], query: $query, form: ['unexpected' => 'body'])));
    }

    public function testWrongContentTypeKeysVersionsAndLimitsFailClosed(): void
    {
        $form = [
            'csrf' => 'csrf',
            'post' => $this->uuid(),
            'locale' => 'es',
            'lock_version' => '01',
        ] + $this->editorial();
        self::assertFalse($this->policy->acceptsSave($this->post(
            '/admin/blog/posts/save',
            $form
        )));

        $form['lock_version'] = '1';
        $form['extra'] = 'not-allowed';
        self::assertFalse($this->policy->acceptsSave($this->post(
            '/admin/blog/posts/save',
            $form
        )));

        unset($form['extra']);
        self::assertLessThan(
            Request::MAX_BODY_BYTES,
            BlogDraft::MAX_BODY_BYTES
        );
        $form['body_text'] = str_repeat('a', BlogDraft::MAX_BODY_BYTES);
        self::assertTrue($this->policy->acceptsSave($this->post(
            '/admin/blog/posts/save',
            $form
        )));
        $form['body_text'] = str_repeat('a', BlogDraft::MAX_BODY_BYTES + 1);
        self::assertFalse($this->policy->acceptsSave($this->post(
            '/admin/blog/posts/save',
            $form
        )));

        self::assertFalse($this->policy->acceptsSave(Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/blog/posts/save',
                'CONTENT_TYPE' => 'application/json',
                'HTTPS' => 'on',
            ],
            form: [
                'csrf' => 'csrf',
                'post' => $this->uuid(),
                'locale' => 'es',
                'lock_version' => '1',
            ] + $this->editorial()
        )));
    }

    public function testTransitionHasNoEditorialPayload(): void
    {
        self::assertTrue($this->policy->acceptsTransition($this->post(
            '/admin/blog/posts/publish',
            [
                'csrf' => 'csrf',
                'post' => $this->uuid(),
                'locale' => 'en',
                'lock_version' => '9',
            ]
        )));

        $action = $this->post('/admin/blog/posts/duplicate', [
            'csrf' => 'csrf',
            'post' => $this->uuid(),
            'locale' => 'en',
            'destination_locale' => 'en',
            'lock_version' => '9',
            'operation_id' => $this->uuid(),
        ]);
        self::assertTrue($this->policy->acceptsDuplicate($action));
        self::assertFalse($this->policy->acceptsTrash($action));
        self::assertFalse($this->policy->acceptsRestoreFromTrash($action));

        $editorialAction = $this->post('/admin/blog/posts/trash', [
            'csrf' => 'csrf',
            'post' => $this->uuid(),
            'locale' => 'en',
            'lock_version' => '9',
        ]);
        self::assertTrue($this->policy->acceptsTrash($editorialAction));
        self::assertTrue(
            $this->policy->acceptsRestoreFromTrash($editorialAction)
        );

        $invalid = $this->post('/admin/blog/posts/trash', [
            'csrf' => 'csrf',
            'post' => $this->uuid(),
            'locale' => 'en',
            'lock_version' => '09',
        ]);
        self::assertFalse($this->policy->acceptsDuplicate($invalid));
        self::assertFalse($this->policy->acceptsTrash($invalid));
        self::assertFalse($this->policy->acceptsRestoreFromTrash($invalid));

        foreach ([
            [],
            ['destination_locale' => 'EN'],
            ['destination_locale' => ''],
        ] as $destination) {
            self::assertFalse($this->policy->acceptsDuplicate($this->post(
                '/admin/blog/posts/duplicate',
                [
                    'csrf' => 'csrf',
                    'post' => $this->uuid(),
                    'locale' => 'en',
                    'lock_version' => '9',
                    'operation_id' => $this->uuid(),
                ] + $destination
            )));
        }
        self::assertFalse($this->policy->acceptsDuplicate($this->post(
            '/admin/blog/posts/duplicate',
            [
                'csrf' => 'csrf',
                'post' => $this->uuid(),
                'locale' => 'en',
                'destination_locale' => 'en',
                'lock_version' => '9',
                'operation_id' => '11111111-1111-1111-8111-111111111111',
            ]
        )));
    }

    public function testBulkContractIsStrictBoundedAndUsesPerItemOperations(): void
    {
        $item = $this->uuid()
            . '|es|3|22222222-2222-4222-8222-222222222222';
        $form = [
            'action' => BlogAdminRequestPolicy::BULK_ROBOTS,
            'csrf' => 'csrf',
            'destination_locale' => 'eu',
            'items' => [$item],
            'robots_follow' => '0',
            'robots_index' => '1',
        ];

        self::assertTrue($this->policy->acceptsBulk($this->post(
            '/admin/blog/posts/bulk',
            $form
        )));
        self::assertFalse($this->policy->acceptsBulk($this->post(
            '/admin/blog/posts/bulk',
            $form + ['unexpected' => 'no']
        )));
        self::assertFalse($this->policy->acceptsBulk($this->post(
            '/admin/blog/posts/bulk',
            array_replace($form, ['items' => [$item, $item]])
        )));

        $tooMany = [];
        for ($index = 1; $index <= 51; ++$index) {
            $tooMany[] = sprintf(
                '%08x-1111-4111-8111-111111111111|es|3|%08x-2222-4222-8222-222222222222',
                $index,
                $index
            );
        }
        self::assertFalse($this->policy->acceptsBulk($this->post(
            '/admin/blog/posts/bulk',
            array_replace($form, ['items' => $tooMany])
        )));
    }

    public function testUrlResolutionIsAnExactPostOnlyContract(): void
    {
        self::assertTrue($this->policy->acceptsUrlManager($this->get(
            '/admin/blog/posts/url',
            ['post' => $this->uuid(), 'locale' => 'es']
        )));
        self::assertFalse($this->policy->acceptsUrlManager($this->get(
            '/admin/blog/posts/url',
            ['post' => $this->uuid(), 'locale' => 'es', 'extra' => 'no']
        )));
        $base = [
            'csrf' => 'csrf',
            'post' => $this->uuid(),
            'locale' => 'es',
            'lock_version' => '3',
            'historical_slug' => 'url-antigua',
            'resolution' => 'gone',
            'replacement_post' => '',
        ];
        self::assertTrue($this->policy->acceptsUrlResolution($this->post(
            '/admin/blog/posts/url-resolution',
            $base
        )));
        $base['resolution'] = 'redirect';
        $base['replacement_post'] = $this->uuid();
        self::assertTrue($this->policy->acceptsUrlResolution($this->post(
            '/admin/blog/posts/url-resolution',
            $base
        )));
        self::assertFalse($this->policy->acceptsUrlResolution($this->get(
            '/admin/blog/posts/url-resolution',
            $base
        )));
        $base['extra'] = 'forbidden';
        self::assertFalse($this->policy->acceptsUrlResolution($this->post(
            '/admin/blog/posts/url-resolution',
            $base
        )));
    }

    public function testMaximumLegalEditorialPayloadFitsTheRealHttpBoundary(): void
    {
        $form = [
            'csrf' => str_repeat('C', 43),
            'post' => $this->uuid(),
            'locale' => 'es',
            'lock_version' => '1',
            'h1' => str_repeat('€', 85),
            'slug' => str_repeat('a', BlogDraft::MAX_SLUG_BYTES),
            'seo_title' => str_repeat('€', 85),
            'meta_description' => str_repeat('€', 106) . 'aa',
            'excerpt' => str_repeat('€', 1365) . 'a',
            'body_text' => str_repeat(
                '€',
                intdiv(BlogDraft::MAX_BODY_BYTES, 3)
            ),
        ];
        $body = http_build_query($form, '', '&', PHP_QUERY_RFC3986);

        self::assertLessThan(Request::MAX_BODY_BYTES, strlen($body));
        $request = Request::fromInput([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/blog/posts/save',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH' => (string) strlen($body),
            'HTTPS' => 'on',
        ], form: $form, body: $body);

        self::assertTrue($request->isValid());
        self::assertTrue($this->policy->acceptsSave($request));
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): Request
    {
        return Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'HTTPS' => 'on',
        ], query: $query);
    }

    /** @param array<string, mixed> $form */
    private function post(string $path, array $form): Request
    {
        return Request::fromInput([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => $path,
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTPS' => 'on',
        ], form: $form);
    }

    /** @return array<string, string> */
    private function editorial(): array
    {
        return [
            'h1' => 'Matrix',
            'slug' => 'matrix',
            'seo_title' => 'Matrix title',
            'meta_description' => 'Matrix description',
            'excerpt' => 'Matrix excerpt',
            'body_text' => 'Matrix body',
        ];
    }

    private function uuid(): string
    {
        return '11111111-1111-4111-8111-111111111111';
    }
}
