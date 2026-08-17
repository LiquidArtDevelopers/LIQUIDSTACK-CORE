<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\Categories\BlogReservedCategoryPolicy;
use App\Core\Blog\Persistence\BlogPersistenceException;
use App\Core\Blog\PublicDelivery\BlogPublicMediaRoute;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentWalker;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Constant-query cross-scope projection of public Blog card thumbnails.
 *
 * Query one loads each CURRENT document once plus its small reference rows;
 * PHP then preserves document order while choosing cover/first image. Query
 * two loads bounded variant rows for at most fifty selected assets and only
 * valid AVIF metadata is accepted. Revisions and drafts are absent by
 * construction and no per-card query is ever issued.
 */
final class PdoBlogPublicCardMediaRepository implements
    BlogPublicCardMediaRepositoryInterface
{
    private const DOCUMENT_ROW = 0;
    private const REFERENCE_ROW = 1;
    private const MAX_CANDIDATES = 8;
    private const MAX_DOCUMENT_REFERENCE_ROWS =
        BlogPublicCardMediaQuery::MAX_CARDS * (BlogDocument::MAX_BLOCKS + 1);
    private const MAX_VARIANT_ROWS =
        BlogPublicCardMediaQuery::MAX_CARDS * self::MAX_CANDIDATES;
    private const UUID_V4 =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
    private const SUPPORTED_DRIVERS = ['mysql', 'sqlite'];

    private readonly string $localizations;
    private readonly string $posts;
    private readonly string $documents;
    private readonly string $documentMedia;
    private readonly string $postCategories;
    private readonly string $categoryLocalizations;
    private readonly string $mediaAssets;
    private readonly string $mediaVariants;
    private readonly BlogDocumentCodec $codec;
    private readonly BlogDocumentWalker $walker;

    public function __construct(
        private readonly PDO $pdo,
        MigrationScope $blogScope,
        MigrationScope $webAdminScope,
        ?BlogDocumentCodec $codec = null,
        ?BlogDocumentWalker $walker = null
    ) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (
                !is_string($driver)
                || !in_array($driver, self::SUPPORTED_DRIVERS, true)
                || $blogScope->moduleId() !== 'blog'
                || $webAdminScope->moduleId() !== 'webadmin'
                || $pdo->getAttribute(PDO::ATTR_ERRMODE)
                    !== PDO::ERRMODE_EXCEPTION
                || (
                    $driver === 'mysql'
                    && !in_array(
                        $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
                        [false, 0, '0'],
                        true
                    )
                )
            ) {
                throw new BlogPersistenceException();
            }
            if ($driver === 'sqlite') {
                $foreignKeys = $pdo->query('PRAGMA foreign_keys');
                if (
                    !$foreignKeys instanceof PDOStatement
                    || !in_array($foreignKeys->fetchColumn(), [1, '1'], true)
                ) {
                    throw new BlogPersistenceException();
                }
            }

            $this->localizations = $blogScope->quotedTable(
                'post_localizations',
                $driver
            );
            $this->posts = $blogScope->quotedTable('posts', $driver);
            $this->documents = $blogScope->quotedTable(
                'content_docs',
                $driver
            );
            $this->documentMedia = $blogScope->quotedTable(
                'content_media',
                $driver
            );
            $this->postCategories = $blogScope->quotedTable(
                'post_categories',
                $driver
            );
            $this->categoryLocalizations = $blogScope->quotedTable(
                'category_locales',
                $driver
            );
            $this->mediaAssets = $webAdminScope->quotedTable(
                'media_assets',
                $driver
            );
            $this->mediaVariants = $webAdminScope->quotedTable(
                'media_variants',
                $driver
            );
            $this->codec = $codec ?? new BlogDocumentCodec();
            $this->walker = $walker ?? new BlogDocumentWalker();
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    public function thumbnailsForCards(
        BlogPublicCardMediaQuery $query
    ): array {
        $slugs = $query->cardSlugs();
        if ($slugs === []) {
            return [];
        }

        $rows = $this->documentAndReferenceRows($query);
        /**
         * @var array<string, array{
         *   documents:list<array<string,mixed>>,
         *   references:list<array<string,mixed>>
         * }>
         */
        $bySlug = [];
        foreach ($slugs as $slug) {
            $bySlug[$slug] = ['documents' => [], 'references' => []];
        }
        foreach ($rows as $row) {
            $slug = $row['card_slug'] ?? null;
            if (!is_string($slug) || !isset($bySlug[$slug])) {
                continue;
            }
            try {
                $kind = $this->nonNegativeInteger($row['row_kind'] ?? null);
            } catch (Throwable) {
                continue;
            }
            if ($kind === self::DOCUMENT_ROW) {
                $bySlug[$slug]['documents'][] = $row;
            } elseif ($kind === self::REFERENCE_ROW) {
                $bySlug[$slug]['references'][] = $row;
            }
        }

        /** @var array<string, array{asset:string,alt:string}> $selected */
        $selected = [];
        foreach ($slugs as $slug) {
            try {
                $use = $this->selectedUse(
                    $bySlug[$slug]['documents'],
                    $bySlug[$slug]['references']
                );
                if ($use !== null) {
                    $selected[$slug] = $use;
                }
            } catch (Throwable) {
                // Invalid current content fails closed for this card only.
            }
        }
        if ($selected === []) {
            return [];
        }

        $assetIds = [];
        foreach ($selected as $use) {
            $assetIds[$use['asset']] = $use['asset'];
        }
        $variantRows = $this->variantRows(array_values($assetIds));
        /** @var array<string, list<array<string,mixed>>> $variantsByAsset */
        $variantsByAsset = array_fill_keys(array_keys($assetIds), []);
        foreach ($variantRows as $row) {
            $asset = $row['asset_public_id'] ?? null;
            if (!is_string($asset) || !isset($variantsByAsset[$asset])) {
                continue;
            }
            $variantsByAsset[$asset][] = $row;
        }

        $thumbnails = [];
        foreach ($slugs as $slug) {
            $use = $selected[$slug] ?? null;
            if ($use === null) {
                continue;
            }
            try {
                $thumbnail = $this->thumbnail(
                    $use['asset'],
                    $use['alt'],
                    $variantsByAsset[$use['asset']] ?? []
                );
                if ($thumbnail !== null) {
                    $thumbnails[$slug] = $thumbnail;
                }
            } catch (Throwable) {
                // Invalid WebAdmin metadata fails closed for this card only.
            }
        }

        return $thumbnails;
    }

    /** @return list<array<string,mixed>> */
    private function documentAndReferenceRows(
        BlogPublicCardMediaQuery $query
    ): array {
        [$documentPlaceholders, $documentParameters] = $this->parameters(
            'document_slug',
            $query->cardSlugs()
        );
        [$referencePlaceholders, $referenceParameters] = $this->parameters(
            'reference_slug',
            $query->cardSlugs()
        );
        $statement = $this->prepare(
            'SELECT 0 AS row_kind, l.slug AS card_slug, '
            . 'd.document_json, d.document_bytes, d.document_sha256, '
            . 'NULL AS block_public_id, '
            . 'NULL AS media_asset_public_id, NULL AS media_role FROM '
            . $this->localizations . ' l JOIN ' . $this->documents
            . ' d ON d.localization_id = l.id JOIN ' . $this->posts
            . ' p ON p.id = l.post_id '
            . 'WHERE l.locale = :document_locale '
            . 'AND l.status = :document_status '
            . 'AND l.published_at IS NOT NULL '
            . 'AND NOT EXISTS (SELECT 1 FROM ' . $this->postCategories
            . ' document_pc JOIN ' . $this->categoryLocalizations
            . ' document_cl ON document_cl.category_id = '
            . 'document_pc.category_id WHERE document_pc.post_id = p.id '
            . 'AND document_cl.slug = :document_reserved_slug) '
            . 'AND l.slug IN (' . implode(', ', $documentPlaceholders)
            . ') UNION ALL SELECT 1 AS row_kind, '
            . 'l.slug AS card_slug, NULL AS document_json, '
            . 'NULL AS document_bytes, NULL AS document_sha256, '
            . 'cm.block_public_id, cm.media_asset_public_id, '
            . 'cm.role AS media_role FROM ' . $this->localizations
            . ' l JOIN ' . $this->documents
            . ' d ON d.localization_id = l.id JOIN ' . $this->posts
            . ' p ON p.id = l.post_id JOIN '
            . $this->documentMedia . ' cm ON cm.document_id = d.id '
            . 'WHERE l.locale = :reference_locale '
            . 'AND l.status = :reference_status '
            . 'AND l.published_at IS NOT NULL '
            . 'AND NOT EXISTS (SELECT 1 FROM ' . $this->postCategories
            . ' reference_pc JOIN ' . $this->categoryLocalizations
            . ' reference_cl ON reference_cl.category_id = '
            . 'reference_pc.category_id WHERE reference_pc.post_id = p.id '
            . 'AND reference_cl.slug = :reference_reserved_slug) '
            . 'AND l.slug IN (' . implode(', ', $referencePlaceholders)
            . ') AND cm.role IN (:cover_role, :image_role) '
            . 'ORDER BY card_slug ASC, row_kind ASC, block_public_id ASC '
            . 'LIMIT :document_reference_limit'
        );
        $this->execute($statement, array_replace(
            [
                'document_locale' => [$query->locale(), PDO::PARAM_STR],
                'document_status' => ['published', PDO::PARAM_STR],
                'document_reserved_slug' => [
                    BlogReservedCategoryPolicy::DUMMY_SLUG,
                    PDO::PARAM_STR,
                ],
                'reference_locale' => [$query->locale(), PDO::PARAM_STR],
                'reference_status' => ['published', PDO::PARAM_STR],
                'reference_reserved_slug' => [
                    BlogReservedCategoryPolicy::DUMMY_SLUG,
                    PDO::PARAM_STR,
                ],
                'cover_role' => ['cover', PDO::PARAM_STR],
                'image_role' => ['image', PDO::PARAM_STR],
                'document_reference_limit' => [
                    self::MAX_DOCUMENT_REFERENCE_ROWS + 1,
                    PDO::PARAM_INT,
                ],
            ],
            $documentParameters,
            $referenceParameters
        ));

        return $this->rows(
            $statement,
            self::MAX_DOCUMENT_REFERENCE_ROWS
        );
    }

    /**
     * @param list<string> $assetPublicIds
     * @return list<array<string,mixed>>
     */
    private function variantRows(array $assetPublicIds): array
    {
        [$placeholders, $parameters] = $this->parameters(
            'asset_public_id',
            $assetPublicIds
        );
        $statement = $this->prepare(
            'SELECT a.public_id AS asset_public_id, '
            . 'v.width AS variant_width, v.height AS variant_height, '
            . 'v.bytes AS variant_bytes, v.sha256 AS variant_sha256, '
            . 'v.storage_key, v.mime AS variant_mime FROM '
            . $this->mediaAssets . ' a JOIN ' . $this->mediaVariants
            . ' v ON v.asset_id = a.id WHERE a.public_id IN ('
            . implode(', ', $placeholders) . ') '
            . 'ORDER BY a.public_id ASC, v.width ASC '
            . 'LIMIT :variant_limit'
        );
        $parameters['variant_limit'] = [
            self::MAX_VARIANT_ROWS + 1,
            PDO::PARAM_INT,
        ];
        $this->execute($statement, $parameters);

        return $this->rows($statement, self::MAX_VARIANT_ROWS);
    }

    /**
     * @param list<array<string,mixed>> $documentRows
     * @param list<array<string,mixed>> $referenceRows
     * @return null|array{asset:string,alt:string}
     */
    private function selectedUse(
        array $documentRows,
        array $referenceRows
    ): ?array {
        if (
            count($documentRows) !== 1
            || count($referenceRows) > BlogDocument::MAX_BLOCKS
        ) {
            return null;
        }
        $row = $documentRows[0];
        $json = $this->requiredString($row, 'document_json');
        $bytes = $this->positiveInteger($row['document_bytes'] ?? null);
        $sha256 = $this->sha256($row['document_sha256'] ?? null);
        if (
            $bytes !== strlen($json)
            || $bytes > BlogDocument::MAX_JSON_BYTES
            || !hash_equals($sha256, hash('sha256', $json))
        ) {
            return null;
        }
        $document = $this->codec->decode($json);
        if (!hash_equals($json, $this->codec->encode($document))) {
            return null;
        }
        $block = $this->selectedImage($document);
        if ($block === null) {
            return null;
        }
        $blockPublicId = $block['id'] ?? null;
        $assetPublicId = $block['media_asset_public_id'] ?? null;
        $alt = $block['alt'] ?? null;
        $decorative = $block['decorative'] ?? null;
        $role = ($block['display'] ?? null) === 'cover' ? 'cover' : 'image';
        if (
            !is_string($blockPublicId)
            || preg_match(self::UUID_V4, $blockPublicId) !== 1
            || !is_string($assetPublicId)
            || preg_match(self::UUID_V4, $assetPublicId) !== 1
            || !is_string($alt)
            || !is_bool($decorative)
            || ($decorative && $alt !== '')
            || (!$decorative && $alt === '')
        ) {
            return null;
        }

        $matches = [];
        foreach ($referenceRows as $reference) {
            if (
                ($reference['block_public_id'] ?? null) === $blockPublicId
                && ($reference['media_role'] ?? null) === $role
            ) {
                $matches[] = $reference;
            }
        }
        if (count($matches) !== 1
            || ($matches[0]['media_asset_public_id'] ?? null)
                !== $assetPublicId) {
            return null;
        }

        return ['asset' => $assetPublicId, 'alt' => $alt];
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function thumbnail(
        string $assetPublicId,
        string $alt,
        array $rows
    ): ?BlogPublicCardThumbnail {
        if ($rows === [] || count($rows) > self::MAX_CANDIDATES) {
            return null;
        }
        /** @var array<int, BlogPublicCardThumbnailCandidate> $candidates */
        $candidates = [];
        /** @var list<array{width:int,height:int}> $dimensions */
        $dimensions = [];
        foreach ($rows as $row) {
            if (
                ($row['asset_public_id'] ?? null) !== $assetPublicId
                || ($row['variant_mime'] ?? null) !== 'image/avif'
            ) {
                return null;
            }
            $width = $this->positiveInteger($row['variant_width'] ?? null);
            $height = $this->positiveInteger($row['variant_height'] ?? null);
            $this->positiveInteger($row['variant_bytes'] ?? null);
            $this->sha256($row['variant_sha256'] ?? null);
            if ($width > 2_560 || $height > 2_560 || isset($candidates[$width])) {
                return null;
            }
            $storageKey = $this->requiredString($row, 'storage_key');
            if ($storageKey !== substr($assetPublicId, 0, 2) . '/'
                . $assetPublicId . '/' . $width . '.avif') {
                return null;
            }
            $candidates[$width] = new BlogPublicCardThumbnailCandidate(
                BlogPublicMediaRoute::path($assetPublicId, $width),
                $width,
                $height
            );
            $dimensions[] = ['width' => $width, 'height' => $height];
        }
        ksort($candidates, SORT_NUMERIC);
        usort(
            $dimensions,
            static fn (array $left, array $right): int =>
                $left['width'] <=> $right['width']
        );
        if (!$this->hasConsistentAspectRatio($dimensions)) {
            return null;
        }

        return new BlogPublicCardThumbnail($alt, array_values($candidates));
    }

    /** @return ?array<string,mixed> */
    private function selectedImage(BlogDocument $document): ?array
    {
        $first = null;
        foreach ($this->walker->modules($document) as $module) {
            if (($module['type'] ?? null) !== 'image') {
                continue;
            }
            $first ??= $module;
            if (($module['display'] ?? null) === 'cover') {
                return $module;
            }
        }

        return $first;
    }

    /** @param list<array{width:int,height:int}> $dimensions */
    private function hasConsistentAspectRatio(array $dimensions): bool
    {
        $base = $dimensions[0] ?? null;
        if ($base === null) {
            return false;
        }
        foreach ($dimensions as $candidate) {
            $expected = $candidate['width'] * $base['height'];
            $actual = $base['width'] * $candidate['height'];
            if (abs($expected - $actual) * 100 > max(1, $expected)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $values
     * @return array{list<string>,array<string,array{mixed,int}>}
     */
    private function parameters(string $prefix, array $values): array
    {
        $placeholders = [];
        $parameters = [];
        foreach ($values as $position => $value) {
            $key = $prefix . '_' . $position;
            $placeholders[] = ':' . $key;
            $parameters[$key] = [$value, PDO::PARAM_STR];
        }

        return [$placeholders, $parameters];
    }

    private function prepare(string $sql): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
        if (!$statement instanceof PDOStatement) {
            throw new BlogPersistenceException();
        }

        return $statement;
    }

    /** @param array<string,array{mixed,int}> $parameters */
    private function execute(PDOStatement $statement, array $parameters): void
    {
        try {
            foreach ($parameters as $key => [$value, $type]) {
                if (!$statement->bindValue(':' . $key, $value, $type)) {
                    throw new BlogPersistenceException();
                }
            }
            if (!$statement->execute()) {
                throw new BlogPersistenceException();
            }
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    /** @return list<array<string,mixed>> */
    private function rows(PDOStatement $statement, int $maximum): array
    {
        try {
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
        if (!is_array($rows) || count($rows) > $maximum) {
            throw new BlogPersistenceException();
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new BlogPersistenceException();
            }
        }

        return array_values($rows);
    }

    /** @param array<string,mixed> $row */
    private function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new BlogPersistenceException();
        }

        return $value;
    }

    private function positiveInteger(mixed $value): int
    {
        $integer = $this->nonNegativeInteger($value);
        if ($integer < 1) {
            throw new BlogPersistenceException();
        }

        return $integer;
    }

    private function nonNegativeInteger(mixed $value): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (
            is_string($value)
            && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1
            && (string) (int) $value === $value
        ) {
            $integer = (int) $value;
        } else {
            throw new BlogPersistenceException();
        }
        if ($integer < 0) {
            throw new BlogPersistenceException();
        }

        return $integer;
    }

    private function sha256(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new BlogPersistenceException();
        }

        return $value;
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['persistence' => '[redacted]'];
    }
}
