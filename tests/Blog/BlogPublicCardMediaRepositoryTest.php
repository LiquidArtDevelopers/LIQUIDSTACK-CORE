<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Core\Blog\BlogException;
use App\Core\Blog\Persistence\BlogPersistenceException;
use App\Core\Blog\PublicDelivery\BlogPublicMediaRoute;
use App\Core\Blog\PublicFeed\BlogPublicCardMediaQuery;
use App\Core\Blog\PublicFeed\BlogPublicCardThumbnail;
use App\Core\Blog\PublicFeed\BlogPublicCardThumbnailCandidate;
use App\Core\Blog\PublicFeed\PdoBlogPublicCardMediaRepository;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Modules\Migrations\MigrationScope;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class BlogPublicCardMediaCountingPdo extends PDO
{
    public int $prepareCount = 0;

    public function prepare(
        string $query,
        array $options = []
    ): PDOStatement|false {
        ++$this->prepareCount;

        return parent::prepare($query, $options);
    }
}

final class BlogPublicCardMediaRepositoryTest extends TestCase
{
    private BlogPublicCardMediaCountingPdo $pdo;
    private PdoBlogPublicCardMediaRepository $repository;
    private int $identity = 100;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->pdo = new BlogPublicCardMediaCountingPdo('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->installSchema();
        $this->repository = new PdoBlogPublicCardMediaRepository(
            $this->pdo,
            MigrationScope::forTablePrefix('blog', 'ls_blog_'),
            MigrationScope::forTablePrefix('webadmin', 'ls_webadmin_')
        );
        $this->pdo->prepareCount = 0;
    }

    public function testEmptyBatchDoesNotQueryAndInputIsUniqueAndBounded(): void
    {
        self::assertSame([], $this->repository->thumbnailsForCards(
            new BlogPublicCardMediaQuery('es', [])
        ));
        self::assertSame(0, $this->pdo->prepareCount);
        self::assertSame(
            ['uno', 'dos'],
            (new BlogPublicCardMediaQuery(
                'es',
                ['uno', 'dos', 'uno']
            ))->cardSlugs()
        );

        $slugs = [];
        for ($index = 0; $index <= BlogPublicCardMediaQuery::MAX_CARDS;
            ++$index) {
            $slugs[] = 'entrada-' . $index;
        }
        try {
            new BlogPublicCardMediaQuery('es', $slugs);
            self::fail('An oversized media batch was accepted.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::INVALID_INPUT,
                $exception->issueCode()
            );
        }
    }

    public function testCoverAndFirstImageUseTwoBatchQueriesAndStableOrder(): void
    {
        $firstAsset = $this->uuid(901);
        $secondAsset = $this->uuid(902);
        $coverAsset = $this->uuid(903);
        $afterCoverAsset = $this->uuid(904);
        $firstBlock = $this->uuid(890);
        $secondBlock = $this->uuid(110);
        $coverBlock = $this->uuid(120);
        $afterCoverBlock = $this->uuid(121);

        $this->insertCurrent('ordered', [
            $this->image($firstBlock, $firstAsset, 'Primera editorial'),
            $this->image($secondBlock, $secondAsset, 'Segunda editorial'),
        ], referenceOrder: [$secondBlock, $firstBlock]);
        $this->insertCurrent('cover', [
            $this->image($coverBlock, $coverAsset, 'Portada', 'cover'),
            $this->image(
                $afterCoverBlock,
                $afterCoverAsset,
                'Imagen posterior'
            ),
        ], referenceOrder: [$afterCoverBlock, $coverBlock]);
        $this->insertCurrent('shared', [
            $this->image($this->uuid(122), $firstAsset, 'Activo compartido'),
        ]);
        foreach ([$firstAsset, $secondAsset, $coverAsset, $afterCoverAsset]
            as $asset) {
            $this->insertVariant($asset, 480, 320);
            $this->insertVariant($asset, 900, 600);
            $this->insertVariant($asset, 1_800, 1_200);
        }
        $this->pdo->prepareCount = 0;

        $thumbnails = $this->repository->thumbnailsForCards(
            new BlogPublicCardMediaQuery(
                'es',
                ['shared', 'ordered', 'cover', 'ordered']
            )
        );

        self::assertSame(['shared', 'ordered', 'cover'], array_keys($thumbnails));
        self::assertSame(2, $this->pdo->prepareCount);
        self::assertSame([
            'src' => BlogPublicMediaRoute::path($firstAsset, 900),
            'srcset' => BlogPublicMediaRoute::path($firstAsset, 480) . ' 480w, '
                . BlogPublicMediaRoute::path($firstAsset, 900) . ' 900w, '
                . BlogPublicMediaRoute::path($firstAsset, 1_800) . ' 1800w',
            'alt' => 'Primera editorial',
            'width' => 900,
            'height' => 600,
        ], $thumbnails['ordered']->toResourceData());
        self::assertSame(
            BlogPublicMediaRoute::path($coverAsset, 900),
            $thumbnails['cover']->toResourceData()['src']
        );
        self::assertSame(
            BlogPublicMediaRoute::path($firstAsset, 900),
            $thumbnails['shared']->toResourceData()['src']
        );
        foreach ($thumbnails as $thumbnail) {
            self::assertArrayNotHasKey(
                'media_asset_public_id',
                $thumbnail->toResourceData()
            );
            self::assertArrayNotHasKey('sizes', $thumbnail->toResourceData());
        }
    }

    public function testDraftUnpublishedAndRevisionOnlyReferencesNeverCount(): void
    {
        $draftAsset = $this->uuid(920);
        $revisionAsset = $this->uuid(921);
        $unpublishedAsset = $this->uuid(922);
        $this->insertCurrent('draft-card', [
            $this->image($this->uuid(220), $draftAsset, 'Draft'),
        ], status: 'draft');
        $revisionDocument = $this->insertCurrent('revision-only', [
            $this->image($this->uuid(221), $revisionAsset, 'Revision'),
        ], persistReferences: false);
        $this->pdo->prepare(
            'INSERT INTO ls_blog_revision_media '
            . '(revision_id, block_public_id, media_asset_public_id, role) '
            . 'VALUES (1, ?, ?, ?)'
        )->execute([$this->uuid(221), $revisionAsset, 'image']);
        self::assertGreaterThan(0, $revisionDocument);
        $this->insertCurrent('not-published-at', [
            $this->image($this->uuid(222), $unpublishedAsset, 'Sin fecha'),
        ], publishedAt: false);
        foreach ([$draftAsset, $revisionAsset, $unpublishedAsset] as $asset) {
            $this->insertVariant($asset, 480, 320);
        }
        $this->pdo->prepareCount = 0;

        self::assertSame([], $this->repository->thumbnailsForCards(
            new BlogPublicCardMediaQuery('es', [
                'draft-card',
                'revision-only',
                'not-published-at',
            ])
        ));
        self::assertSame(
            1,
            $this->pdo->prepareCount,
            'No selected current use means no WebAdmin variant query.'
        );
    }

    public function testReservedDummyCategoryIsExcludedByTheMediaAdapterItself(): void
    {
        $regular = $this->cardWithOneImage('regular-card', 230, 923);
        $dummy = $this->cardWithOneImage('dummy-card', 231, 924);
        $this->assignCategory('dummy-card', 'dummy');
        $this->insertVariant($regular, 480, 320);
        $this->insertVariant($dummy, 480, 320);
        $this->pdo->prepareCount = 0;

        $thumbnails = $this->repository->thumbnailsForCards(
            new BlogPublicCardMediaQuery(
                'es',
                ['dummy-card', 'regular-card']
            )
        );

        self::assertSame(['regular-card'], array_keys($thumbnails));
        self::assertSame(2, $this->pdo->prepareCount);
    }

    public function testDocumentReferenceOverflowFailsBeforeRowProcessing(): void
    {
        $asset = $this->cardWithOneImage('reference-overflow', 240, 925);
        $documentId = (int) $this->pdo->query(
            'SELECT d.id FROM ls_blog_content_docs d JOIN '
            . 'ls_blog_post_localizations l ON l.id = d.localization_id '
            . "WHERE l.slug = 'reference-overflow'"
        )->fetchColumn();
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_content_media '
            . '(document_id, block_public_id, media_asset_public_id, role) '
            . 'VALUES (?, ?, ?, ?)'
        );
        for ($position = 1; $position <= 10_050; ++$position) {
            $statement->execute([
                $documentId,
                sprintf(
                    '10000000-0000-4000-8000-%012d',
                    $position
                ),
                $asset,
                'image',
            ]);
        }
        $this->pdo->prepareCount = 0;

        try {
            $this->repository->thumbnailsForCards(
                new BlogPublicCardMediaQuery('es', ['reference-overflow'])
            );
            self::fail('An oversized current-reference result was accepted.');
        } catch (BlogPersistenceException) {
            self::assertSame(1, $this->pdo->prepareCount);
        }
    }

    public function testVariantOverflowFailsBeforePerAssetProcessing(): void
    {
        $asset = $this->cardWithOneImage('variant-overflow', 241, 926);
        $assetId = $this->insertAsset($asset);
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_media_variants '
            . '(asset_id, width, height, bytes, sha256, storage_key, mime) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        for ($width = 1; $width <= 401; ++$width) {
            $statement->execute([
                $assetId,
                $width,
                $width,
                100,
                hash('sha256', $asset . ':' . $width),
                substr($asset, 0, 2) . '/' . $asset . '/'
                    . $width . '.avif',
                'image/avif',
            ]);
        }
        $this->pdo->prepareCount = 0;

        try {
            $this->repository->thumbnailsForCards(
                new BlogPublicCardMediaQuery('es', ['variant-overflow'])
            );
            self::fail('An oversized variant result was accepted.');
        } catch (BlogPersistenceException) {
            self::assertSame(2, $this->pdo->prepareCount);
        }
    }

    public function testInvalidMediaFailsClosedPerCardWithoutPoisoningValidCards(): void
    {
        $valid = $this->cardWithOneImage('valid', 301, 931);
        $missing = $this->cardWithOneImage('missing', 302, 932);
        $corrupt = $this->cardWithOneImage('corrupt', 303, 933);
        $duplicates = $this->cardWithOneImage('duplicates', 304, 934);
        $largeOnly = $this->cardWithOneImage('large-only', 305, 935);
        $badMime = $this->cardWithOneImage('bad-mime', 306, 936);
        $badDocument = $this->cardWithOneImage('bad-document', 307, 937);

        $this->insertVariant($valid, 480, 320);
        $this->insertVariant($valid, 900, 600);
        $this->insertAsset($missing);
        $this->insertVariant($corrupt, 480, 320, sha256: 'not-a-hash');
        $this->insertVariant($duplicates, 480, 320);
        $this->insertVariant($duplicates, 480, 320);
        $this->insertVariant($largeOnly, 1_800, 1_200);
        $this->insertVariant($largeOnly, 2_560, 1_707);
        $this->insertVariant($badMime, 480, 320, mime: 'image/png');
        $this->insertVariant($badDocument, 480, 320);
        $this->pdo->exec(
            "UPDATE ls_blog_content_docs SET document_sha256 = '"
            . str_repeat('0', 64) . "' WHERE localization_id = ("
            . "SELECT id FROM ls_blog_post_localizations "
            . "WHERE slug = 'bad-document')"
        );
        $this->pdo->prepareCount = 0;

        $thumbnails = $this->repository->thumbnailsForCards(
            new BlogPublicCardMediaQuery('es', [
                'missing',
                'valid',
                'corrupt',
                'duplicates',
                'large-only',
                'bad-mime',
                'bad-document',
            ])
        );

        self::assertSame(['valid'], array_keys($thumbnails));
        self::assertSame(2, $this->pdo->prepareCount);
        self::assertSame(
            BlogPublicMediaRoute::path($valid, 900),
            $thumbnails['valid']->toResourceData()['src']
        );
    }

    public function testDuplicateCurrentReferenceFailsClosedForThatCard(): void
    {
        $asset = $this->cardWithOneImage('duplicate-ref', 401, 941);
        $this->pdo->prepare(
            'INSERT INTO ls_blog_content_media '
            . '(document_id, block_public_id, media_asset_public_id, role) '
            . 'SELECT document_id, block_public_id, media_asset_public_id, role '
            . 'FROM ls_blog_content_media LIMIT 1'
        )->execute();
        $this->insertVariant($asset, 480, 320);
        $this->pdo->prepareCount = 0;

        self::assertSame([], $this->repository->thumbnailsForCards(
            new BlogPublicCardMediaQuery('es', ['duplicate-ref'])
        ));
        self::assertSame(1, $this->pdo->prepareCount);
    }

    public function testQueryFailureIsExplicitForTheOptionalFeedBoundaryToCatch(): void
    {
        $asset = $this->cardWithOneImage('missing-webadmin', 501, 951);
        self::assertNotSame('', $asset);
        $this->pdo->exec('DROP TABLE ls_webadmin_media_variants');
        $this->pdo->prepareCount = 0;

        $this->expectException(BlogPersistenceException::class);
        $this->repository->thumbnailsForCards(
            new BlogPublicCardMediaQuery('es', ['missing-webadmin'])
        );
    }

    public function testThumbnailValuesRejectArbitraryOrHeavyFallbacks(): void
    {
        $asset = $this->uuid(980);
        $valid480 = new BlogPublicCardThumbnailCandidate(
            BlogPublicMediaRoute::path($asset, 480),
            480,
            320
        );
        $valid900 = new BlogPublicCardThumbnailCandidate(
            BlogPublicMediaRoute::path($asset, 900),
            900,
            600
        );
        $invalidCases = [
            static fn (): BlogPublicCardThumbnail =>
                new BlogPublicCardThumbnail('Alt', [$valid900, $valid480]),
            static fn (): BlogPublicCardThumbnail =>
                new BlogPublicCardThumbnail('Alt', [
                    new BlogPublicCardThumbnailCandidate(
                        BlogPublicMediaRoute::path($asset, 1_800),
                        1_800,
                        1_200
                    ),
                ]),
            static fn (): BlogPublicCardThumbnailCandidate =>
                new BlogPublicCardThumbnailCandidate(
                    BlogPublicMediaRoute::path($asset, 480),
                    900,
                    600
                ),
            fn (): BlogPublicCardThumbnail => new BlogPublicCardThumbnail(
                'Alt',
                [$valid480, new BlogPublicCardThumbnailCandidate(
                    BlogPublicMediaRoute::path($this->uuid(981), 900),
                    900,
                    600
                )]
            ),
        ];
        foreach ($invalidCases as $position => $case) {
            try {
                $case();
                self::fail('Invalid thumbnail case ' . $position . ' passed.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    private function installSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE ls_blog_post_localizations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL,
    post_id INTEGER NOT NULL,
    locale TEXT NOT NULL,
    slug TEXT NULL,
    status TEXT NOT NULL,
    published_at TEXT NULL
);
CREATE TABLE ls_blog_posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT
);
CREATE TABLE ls_blog_post_categories (
    post_id INTEGER NOT NULL,
    category_id INTEGER NOT NULL
);
CREATE TABLE ls_blog_category_locales (
    category_id INTEGER NOT NULL,
    slug TEXT NOT NULL
);
CREATE TABLE ls_blog_content_docs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    localization_id INTEGER NOT NULL,
    document_json TEXT NOT NULL,
    document_bytes INTEGER NOT NULL,
    document_sha256 TEXT NOT NULL
);
CREATE TABLE ls_blog_content_media (
    document_id INTEGER NOT NULL,
    block_public_id TEXT NOT NULL,
    media_asset_public_id TEXT NOT NULL,
    role TEXT NOT NULL
);
CREATE TABLE ls_blog_revision_media (
    revision_id INTEGER NOT NULL,
    block_public_id TEXT NOT NULL,
    media_asset_public_id TEXT NOT NULL,
    role TEXT NOT NULL
);
CREATE TABLE ls_webadmin_media_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE
);
CREATE TABLE ls_webadmin_media_variants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    asset_id INTEGER NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,
    bytes INTEGER NOT NULL,
    sha256 TEXT NOT NULL,
    storage_key TEXT NOT NULL,
    mime TEXT NOT NULL
);
SQL);
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @param null|list<string> $referenceOrder
     */
    private function insertCurrent(
        string $slug,
        array $blocks,
        ?array $referenceOrder = null,
        string $status = 'published',
        bool $publishedAt = true,
        bool $persistReferences = true
    ): int {
        $template = ($blocks[0]['display'] ?? null) === 'cover'
            ? 'article-cover-01'
            : 'article-basic-01';
        $document = BlogDocument::fromArray([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => $template,
            'blocks' => $blocks,
        ]);
        $json = (new BlogDocumentCodec())->encode($document);
        $this->pdo->exec('INSERT INTO ls_blog_posts DEFAULT VALUES');
        $postId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO ls_blog_post_localizations '
            . '(public_id, post_id, locale, slug, status, published_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->uuid(++$this->identity),
            $postId,
            'es',
            $slug,
            $status,
            $publishedAt ? '2030-01-01 00:00:00.000000' : null,
        ]);
        $localizationId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO ls_blog_content_docs '
            . '(localization_id, document_json, document_bytes, '
            . 'document_sha256) VALUES (?, ?, ?, ?)'
        )->execute([
            $localizationId,
            $json,
            strlen($json),
            hash('sha256', $json),
        ]);
        $documentId = (int) $this->pdo->lastInsertId();
        if (!$persistReferences) {
            return $documentId;
        }
        $byId = [];
        foreach ($blocks as $block) {
            $byId[$block['id']] = $block;
        }
        foreach ($referenceOrder ?? array_keys($byId) as $blockId) {
            $block = $byId[$blockId];
            $this->pdo->prepare(
                'INSERT INTO ls_blog_content_media '
                . '(document_id, block_public_id, '
                . 'media_asset_public_id, role) VALUES (?, ?, ?, ?)'
            )->execute([
                $documentId,
                $block['id'],
                $block['media_asset_public_id'],
                ($block['display'] ?? null) === 'cover' ? 'cover' : 'image',
            ]);
        }

        return $documentId;
    }

    private function cardWithOneImage(
        string $slug,
        int $blockIdentity,
        int $assetIdentity
    ): string {
        $asset = $this->uuid($assetIdentity);
        $this->insertCurrent($slug, [
            $this->image(
                $this->uuid($blockIdentity),
                $asset,
                'Imagen ' . $slug
            ),
        ]);

        return $asset;
    }

    /** @return array<string,mixed> */
    private function image(
        string $block,
        string $asset,
        string $alt,
        string $display = 'content'
    ): array {
        return [
            'id' => $block,
            'type' => 'image',
            'media_asset_public_id' => $asset,
            'alt' => $alt,
            'title' => null,
            'caption' => null,
            'decorative' => false,
            'display' => $display,
        ];
    }

    private function insertAsset(string $asset): int
    {
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO ls_webadmin_media_assets (public_id) '
            . 'VALUES (?)'
        );
        $statement->execute([$asset]);

        return (int) $this->pdo->query(
            'SELECT id FROM ls_webadmin_media_assets WHERE public_id = '
            . $this->pdo->quote($asset)
        )->fetchColumn();
    }

    private function assignCategory(string $slug, string $categorySlug): void
    {
        $postId = (int) $this->pdo->query(
            'SELECT post_id FROM ls_blog_post_localizations WHERE slug = '
            . $this->pdo->quote($slug)
        )->fetchColumn();
        $categoryId = ++$this->identity;
        $this->pdo->prepare(
            'INSERT INTO ls_blog_category_locales (category_id, slug) '
            . 'VALUES (?, ?)'
        )->execute([$categoryId, $categorySlug]);
        $this->pdo->prepare(
            'INSERT INTO ls_blog_post_categories (post_id, category_id) '
            . 'VALUES (?, ?)'
        )->execute([$postId, $categoryId]);
    }

    private function insertVariant(
        string $asset,
        int $width,
        int $height,
        ?string $sha256 = null,
        string $mime = 'image/avif'
    ): void {
        $assetId = $this->insertAsset($asset);
        $this->pdo->prepare(
            'INSERT INTO ls_webadmin_media_variants '
            . '(asset_id, width, height, bytes, sha256, storage_key, mime) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $assetId,
            $width,
            $height,
            100,
            $sha256 ?? hash('sha256', $asset . ':' . $width),
            substr($asset, 0, 2) . '/' . $asset . '/' . $width . '.avif',
            $mime,
        ]);
    }

    private function uuid(int $identity): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $identity);
    }
}
