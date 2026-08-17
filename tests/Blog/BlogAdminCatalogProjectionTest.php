<?php

declare(strict_types=1);

use App\Core\Blog\Admin\BlogAdminCatalogQuery;
use App\Core\Blog\Categories\BlogReservedCategoryPolicy;
use App\Core\Blog\Persistence\BlogPersistenceException;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\Seo\BlogRobotsPreferences;
use App\Core\Modules\Migrations\MigrationScope;
use PHPUnit\Framework\TestCase;

final class BlogAdminCatalogProjectionTest extends TestCase
{
    public function testCatalogProjectsAuthorCategoriesAndRobotsWithoutPii(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $this->schema($pdo);

        $actor = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $post = '11111111-1111-4111-8111-111111111111';
        $localization = '22222222-2222-4222-8222-222222222222';
        $pdo->exec("INSERT INTO ls_webadmin_users "
            . "(public_id, email_canonical, display_name) VALUES "
            . "('{$actor}', 'private@example.test', 'Trinity & Neo')");
        $pdo->exec("INSERT INTO ls_blog_posts "
            . "(public_id, created_by_user_public_id) VALUES "
            . "('{$post}', '{$actor}')");
        $pdo->exec("INSERT INTO ls_blog_post_localizations "
            . "(public_id, post_id, locale, slug, h1, status, published_at, "
            . "lock_version, updated_at) VALUES ('{$localization}', 1, 'es', "
            . "'matrix', 'Matrix', 'published', "
            . "'2030-01-01 10:00:00.000000', 2, "
            . "'2030-01-02 10:00:00.000000')");
        foreach ([
            ['33333333-3333-4333-8333-333333333333', 'Noticias'],
            ['44444444-4444-4444-8444-444444444444', 'Tecnología'],
        ] as $index => [$category, $name]) {
            $id = $index + 1;
            $pdo->exec("INSERT INTO ls_blog_categories (id, public_id) "
                . "VALUES ({$id}, '{$category}')");
            $quotedName = $pdo->quote($name);
            $pdo->exec("INSERT INTO ls_blog_category_locales "
                . "(category_id, locale, name) VALUES "
                . "({$id}, 'es', {$quotedName})");
            $pdo->exec("INSERT INTO ls_blog_post_categories "
                . "(post_id, category_id) VALUES (1, {$id})");
        }
        $robots = BlogRobotsPreferences::noIndexNoFollow();
        $pdo->exec("INSERT INTO ls_blog_robots_settings "
            . "(localization_id, allow_index, allow_follow, settings_sha256) "
            . "VALUES (1, 0, 0, '" . $robots->integrityHash() . "')");

        $repository = new PdoBlogRepository(
            $pdo,
            MigrationScope::forTablePrefix('blog', 'ls_blog_'),
            robotsSettingsEnabled: true,
            adminUserScope: MigrationScope::forTablePrefix(
                'webadmin',
                'ls_webadmin_'
            ),
            adminCategoryProjectionEnabled: true,
            reservedCategoryPolicyEnabled: true
        );
        $rows = $repository->searchSummaries(new BlogAdminCatalogQuery());

        self::assertCount(1, $rows);
        self::assertSame('Trinity & Neo', $rows[0]->authorName());
        self::assertSame(
            ['Noticias', 'Tecnología'],
            $rows[0]->categoryNames()
        );
        self::assertSame(
            'noindex,nofollow',
            $rows[0]->robotsPreferences()->directive()
        );
        self::assertStringNotContainsString(
            'private@example.test',
            serialize($rows[0]->toArray())
        );
    }

    public function testEveryAllowedSortRunsBeforeThePageLimit(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $this->schema($pdo);
        $records = [];
        $locales = ['es', 'en', 'eu'];
        for ($index = 1; $index <= 12; ++$index) {
            $post = sprintf(
                '1%07d-0000-4000-8000-%012d',
                $index,
                $index
            );
            $actor = sprintf(
                '2%07d-0000-4000-8000-%012d',
                $index,
                $index
            );
            $localization = sprintf(
                '3%07d-0000-4000-8000-%012d',
                $index,
                $index
            );
            $title = sprintf('Title %02d', 13 - $index);
            $author = sprintf('Author %02d', 13 - $index);
            $locale = $locales[$index % count($locales)];
            $status = $index % 2 === 0 ? 'published' : 'draft';
            $updated = sprintf('2030-01-%02d 10:00:00.000000', 13 - $index);
            $robotsIndex = $index % 2;
            $robotsFollow = intdiv($index, 2) % 2;
            $pdo->exec('INSERT INTO ls_webadmin_users '
                . '(public_id, email_canonical, display_name) VALUES ('
                . $pdo->quote($actor) . ', '
                . $pdo->quote('private-' . $index . '@example.test') . ', '
                . $pdo->quote($author) . ')');
            $pdo->exec('INSERT INTO ls_blog_posts '
                . '(public_id, created_by_user_public_id) VALUES ('
                . $pdo->quote($post) . ', ' . $pdo->quote($actor) . ')');
            $pdo->exec('INSERT INTO ls_blog_post_localizations '
                . '(public_id, post_id, locale, slug, h1, status, '
                . 'published_at, lock_version, updated_at) VALUES ('
                . $pdo->quote($localization) . ', ' . $index . ', '
                . $pdo->quote($locale) . ', '
                . $pdo->quote('title-' . $index) . ', '
                . $pdo->quote($title) . ', ' . $pdo->quote($status) . ', '
                . ($status === 'published' ? $pdo->quote($updated) : 'NULL')
                . ', 1, ' . $pdo->quote($updated) . ')');
            $robots = new BlogRobotsPreferences(
                $robotsIndex === 1,
                $robotsFollow === 1
            );
            $pdo->exec('INSERT INTO ls_blog_robots_settings '
                . '(localization_id, allow_index, allow_follow, '
                . 'settings_sha256) VALUES (' . $index . ', '
                . $robotsIndex . ', ' . $robotsFollow . ', '
                . $pdo->quote($robots->integrityHash()) . ')');
            $records[] = [
                'post' => $post,
                'title' => $title,
                'locale' => $locale,
                'status' => $status,
                'author' => $author,
                'robots' => [$robotsIndex, $robotsFollow],
                'updated' => $updated,
            ];
        }

        $repository = new PdoBlogRepository(
            $pdo,
            MigrationScope::forTablePrefix('blog', 'ls_blog_'),
            robotsSettingsEnabled: true,
            adminUserScope: MigrationScope::forTablePrefix(
                'webadmin',
                'ls_webadmin_'
            ),
            reservedCategoryPolicyEnabled: true
        );
        foreach ([
            BlogAdminCatalogQuery::SORT_TITLE,
            BlogAdminCatalogQuery::SORT_LOCALE,
            BlogAdminCatalogQuery::SORT_STATUS,
            BlogAdminCatalogQuery::SORT_AUTHOR,
            BlogAdminCatalogQuery::SORT_ROBOTS,
            BlogAdminCatalogQuery::SORT_UPDATED,
        ] as $sort) {
            foreach (['asc', 'desc'] as $direction) {
                $expected = $records;
                usort($expected, static function (
                    array $left,
                    array $right
                ) use ($sort, $direction): int {
                    $leftValue = match ($sort) {
                        BlogAdminCatalogQuery::SORT_TITLE =>
                            strtolower($left['title']),
                        BlogAdminCatalogQuery::SORT_LOCALE => $left['locale'],
                        BlogAdminCatalogQuery::SORT_STATUS =>
                            $left['status'] === 'draft' ? 0 : 1,
                        BlogAdminCatalogQuery::SORT_AUTHOR =>
                            strtolower($left['author']),
                        BlogAdminCatalogQuery::SORT_ROBOTS => $left['robots'],
                        BlogAdminCatalogQuery::SORT_UPDATED => $left['updated'],
                    };
                    $rightValue = match ($sort) {
                        BlogAdminCatalogQuery::SORT_TITLE =>
                            strtolower($right['title']),
                        BlogAdminCatalogQuery::SORT_LOCALE => $right['locale'],
                        BlogAdminCatalogQuery::SORT_STATUS =>
                            $right['status'] === 'draft' ? 0 : 1,
                        BlogAdminCatalogQuery::SORT_AUTHOR =>
                            strtolower($right['author']),
                        BlogAdminCatalogQuery::SORT_ROBOTS => $right['robots'],
                        BlogAdminCatalogQuery::SORT_UPDATED => $right['updated'],
                    };
                    $comparison = $leftValue <=> $rightValue;
                    if ($comparison !== 0 && $direction === 'desc') {
                        $comparison *= -1;
                    }

                    return $comparison !== 0
                        ? $comparison
                        : $left['post'] <=> $right['post'];
                });
                $rows = $repository->searchSummaries(
                    new BlogAdminCatalogQuery(
                        pageSize: 10,
                        sort: $sort,
                        direction: $direction
                    )
                );
                self::assertSame(
                    array_column(array_slice($expected, 0, 11), 'title'),
                    array_map(
                        static fn ($summary): string => $summary->h1(),
                        $rows
                    ),
                    $sort . ':' . $direction
                );
            }
        }
    }

    public function testCanonicalDummyIsExcludedBeforeFiltersSortAndPaging(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $this->schema($pdo);

        $pdo->exec('INSERT INTO ls_blog_categories (id, public_id) VALUES '
            . '(1, ' . $pdo->quote(
                BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID
            ) . "), (2, '99999999-9999-4999-8999-999999999999')");
        $pdo->exec('INSERT INTO ls_blog_category_locales '
            . '(category_id, locale, name, slug) VALUES '
            . "(1, 'und', 'Dummy (interno)', 'dummy'), "
            . "(2, 'es', 'Dummy (interno)', 'dummy')");

        $locales = ['es', 'en', 'eu'];
        $expectedTitles = [];
        for ($postNumber = 1; $postNumber <= 6; ++$postNumber) {
            foreach ([true, false] as $isDummy) {
                $postPublicId = sprintf(
                    '%d%07d-0000-4000-8000-%012d',
                    $isDummy ? 6 : 7,
                    $postNumber,
                    $postNumber
                );
                $pdo->exec('INSERT INTO ls_blog_posts '
                    . '(public_id, created_by_user_public_id) VALUES ('
                    . $pdo->quote($postPublicId) . ', '
                    . $pdo->quote(
                        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
                    ) . ')');
                $postId = (int) $pdo->lastInsertId();
                $categoryId = $isDummy ? 1 : ($postNumber === 1 ? 2 : null);
                if ($categoryId !== null) {
                    $pdo->exec('INSERT INTO ls_blog_post_categories '
                        . '(post_id, category_id) VALUES (' . $postId . ', '
                        . $categoryId . ')');
                }

                foreach ($locales as $localeIndex => $locale) {
                    $rank = (($postNumber - 1) * count($locales)
                        + $localeIndex) * 2 + ($isDummy ? 1 : 2);
                    $title = sprintf('Entry %02d', $rank);
                    $status = $rank % 4 === 0 ? 'published' : 'draft';
                    $updated = sprintf(
                        '2030-01-01 10:%02d:00.000000',
                        $rank
                    );
                    $localizationPublicId = sprintf(
                        '%d%07d-0000-4000-8000-%012d',
                        $isDummy ? 8 : 9,
                        ($postNumber * 10) + $localeIndex,
                        ($postNumber * 10) + $localeIndex
                    );
                    $pdo->exec('INSERT INTO ls_blog_post_localizations '
                        . '(public_id, post_id, locale, slug, h1, status, '
                        . 'published_at, lock_version, updated_at) VALUES ('
                        . $pdo->quote($localizationPublicId) . ', '
                        . $postId . ', ' . $pdo->quote($locale) . ', '
                        . $pdo->quote(strtolower(str_replace(' ', '-', $title)))
                        . ', ' . $pdo->quote($title) . ', '
                        . $pdo->quote($status) . ', '
                        . ($status === 'published'
                            ? $pdo->quote($updated) : 'NULL')
                        . ', 1, ' . $pdo->quote($updated) . ')');
                    if (!$isDummy) {
                        $expectedTitles[] = $title;
                    }
                }
            }
        }

        $repository = new PdoBlogRepository(
            $pdo,
            MigrationScope::forTablePrefix('blog', 'ls_blog_'),
            adminCategoryProjectionEnabled: true,
            reservedCategoryPolicyEnabled: true
        );
        $titles = static fn (array $rows): array => array_map(
            static fn ($summary): string => $summary->h1(),
            $rows
        );

        $firstPage = $repository->searchSummaries(new BlogAdminCatalogQuery(
            pageSize: 10,
            sort: BlogAdminCatalogQuery::SORT_TITLE,
            direction: BlogAdminCatalogQuery::DIRECTION_ASC
        ));
        self::assertCount(11, $firstPage, 'The sentinel follows exclusion.');
        self::assertSame(array_slice($expectedTitles, 0, 11), $titles(
            $firstPage
        ));
        self::assertSame(
            ['Dummy (interno)'],
            $firstPage[0]->categoryNames(),
            'A same-name/slug category with another UUID remains visible.'
        );

        $secondPage = $repository->searchSummaries(new BlogAdminCatalogQuery(
            offset: 10,
            pageSize: 10,
            sort: BlogAdminCatalogQuery::SORT_TITLE,
            direction: BlogAdminCatalogQuery::DIRECTION_ASC
        ));
        self::assertCount(8, $secondPage);
        self::assertSame(array_slice($expectedTitles, 10), $titles(
            $secondPage
        ));

        $widePage = $repository->searchSummaries(new BlogAdminCatalogQuery(
            pageSize: 20,
            sort: BlogAdminCatalogQuery::SORT_TITLE,
            direction: BlogAdminCatalogQuery::DIRECTION_DESC
        ));
        self::assertCount(18, $widePage);
        self::assertSame(array_reverse($expectedTitles), $titles($widePage));

        $spanish = $repository->searchSummaries(new BlogAdminCatalogQuery(
            locale: 'es',
            pageSize: 10,
            sort: BlogAdminCatalogQuery::SORT_TITLE,
            direction: BlogAdminCatalogQuery::DIRECTION_ASC
        ));
        self::assertCount(6, $spanish);
        self::assertSame(
            ['Entry 02', 'Entry 08', 'Entry 14', 'Entry 20', 'Entry 26',
                'Entry 32'],
            $titles($spanish)
        );
        self::assertSame([], $repository->searchSummaries(
            new BlogAdminCatalogQuery(search: 'Entry 01')
        ));
    }

    public function testAdminCatalogFailsClosedWithoutReservedCategorySchema(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $this->schema($pdo);
        $repository = new PdoBlogRepository(
            $pdo,
            MigrationScope::forTablePrefix('blog', 'ls_blog_')
        );

        $this->expectException(BlogPersistenceException::class);
        $repository->searchSummaries(new BlogAdminCatalogQuery());
    }

    public function testLocalesForPostsReturnsOneBoundedBatchProjection(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $this->schema($pdo);
        $firstPost = '11111111-1111-4111-8111-111111111111';
        $secondPost = '22222222-2222-4222-8222-222222222222';
        foreach ([$firstPost, $secondPost] as $post) {
            $pdo->exec('INSERT INTO ls_blog_posts '
                . '(public_id, created_by_user_public_id) VALUES ('
                . $pdo->quote($post) . ', '
                . $pdo->quote('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa') . ')');
        }
        foreach ([
            [
                '33333333-3333-4333-8333-333333333333',
                1,
                'es',
            ],
            [
                '44444444-4444-4444-8444-444444444444',
                1,
                'eu',
            ],
            [
                '55555555-5555-4555-8555-555555555555',
                2,
                'en',
            ],
        ] as $index => [$localization, $postId, $locale]) {
            $pdo->exec('INSERT INTO ls_blog_post_localizations '
                . '(public_id, post_id, locale, slug, h1, status, '
                . 'published_at, lock_version, updated_at) VALUES ('
                . $pdo->quote($localization) . ', ' . $postId . ', '
                . $pdo->quote($locale) . ', '
                . $pdo->quote('slug-' . $index) . ', '
                . $pdo->quote('Title ' . $index) . ", 'draft', NULL, 1, "
                . $pdo->quote('2030-01-01 10:00:00.000000') . ')');
        }

        $repository = new PdoBlogRepository(
            $pdo,
            MigrationScope::forTablePrefix('blog', 'ls_blog_')
        );

        self::assertSame([
            $secondPost => ['en'],
            $firstPost => ['es', 'eu'],
        ], $repository->localesForPosts(
            [$secondPost, $firstPost],
            5
        ));
    }

    private function schema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE ls_webadmin_users ('
            . 'id INTEGER PRIMARY KEY, public_id TEXT UNIQUE NOT NULL, '
            . 'email_canonical TEXT NOT NULL, display_name TEXT NULL)');
        $pdo->exec('CREATE TABLE ls_blog_posts ('
            . 'id INTEGER PRIMARY KEY, public_id TEXT UNIQUE NOT NULL, '
            . 'created_by_user_public_id TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE ls_blog_post_localizations ('
            . 'id INTEGER PRIMARY KEY, public_id TEXT UNIQUE NOT NULL, '
            . 'post_id INTEGER NOT NULL, locale TEXT NOT NULL, slug TEXT NULL, '
            . 'h1 TEXT NOT NULL, status TEXT NOT NULL, published_at TEXT NULL, '
            . 'lock_version INTEGER NOT NULL, updated_at TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE ls_blog_categories ('
            . 'id INTEGER PRIMARY KEY, public_id TEXT UNIQUE NOT NULL)');
        $pdo->exec('CREATE TABLE ls_blog_category_locales ('
            . 'id INTEGER PRIMARY KEY, category_id INTEGER NOT NULL, '
            . "locale TEXT NOT NULL, name TEXT NOT NULL, slug TEXT NOT NULL "
            . "DEFAULT '')");
        $pdo->exec('CREATE TABLE ls_blog_post_categories ('
            . 'id INTEGER PRIMARY KEY, post_id INTEGER NOT NULL, '
            . 'category_id INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE ls_blog_robots_settings ('
            . 'localization_id INTEGER PRIMARY KEY, allow_index INTEGER NOT NULL, '
            . 'allow_follow INTEGER NOT NULL, settings_sha256 TEXT NOT NULL)');
    }
}
