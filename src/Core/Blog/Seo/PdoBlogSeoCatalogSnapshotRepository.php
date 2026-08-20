<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

use App\Core\Blog\BlogInput;
use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV2CompatibilityCanonicalizer;
use App\Core\Blog\StructuredContent\Document\BlogLegacyDocumentFactory;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredSnapshotHasher;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use PDOStatement;
use Throwable;

/** One-query, bounded projection of the saved snapshot shown by the editor. */
final class PdoBlogSeoCatalogSnapshotRepository implements
    BlogSeoCatalogSnapshotRepositoryInterface
{
    public const MAX_SNAPSHOTS = 50;

    private const SUPPORTED_DRIVERS = ['mysql', 'sqlite'];

    private readonly string $driver;
    private readonly string $localizations;
    private readonly string $documents;
    private readonly ?string $workspaces;
    private readonly ?string $revisions;
    private readonly ?string $layoutDocuments;
    private readonly ?string $layoutRevisions;
    private readonly BlogDocumentCodec $codec;
    private readonly BlogLegacyDocumentFactory $legacyFactory;

    public function __construct(
        private readonly PDO $pdo,
        MigrationScope $scope,
        bool $privateDraftPublicationReady = false,
        bool $layoutEditorReady = false,
        ?BlogDocumentCodec $codec = null,
        ?BlogLegacyDocumentFactory $legacyFactory = null
    ) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (
                !is_string($driver)
                || !in_array($driver, self::SUPPORTED_DRIVERS, true)
                || $scope->moduleId() !== 'blog'
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
                throw $this->storageUnavailable();
            }

            $this->driver = $driver;
            $this->localizations = $scope->quotedTable(
                'post_localizations',
                $driver
            );
            $this->documents = $scope->quotedTable('content_docs', $driver);
            $this->workspaces = $privateDraftPublicationReady
                ? $scope->quotedTable('editorial_workspaces', $driver)
                : null;
            $this->revisions = $privateDraftPublicationReady
                ? $scope->quotedTable('content_revisions', $driver)
                : null;
            $this->layoutDocuments = $layoutEditorReady
                ? $scope->quotedTable('content_layout_docs', $driver)
                : null;
            $this->layoutRevisions = $layoutEditorReady
                && $privateDraftPublicationReady
                    ? $scope->quotedTable(
                        'content_layout_revisions',
                        $driver
                    )
                    : null;
            $this->codec = $codec ?? new BlogDocumentCodec();
            $this->legacyFactory = $legacyFactory
                ?? new BlogLegacyDocumentFactory();
        } catch (BlogStructuredContentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->storageUnavailable();
        }
    }

    public function snapshots(array $localizationPublicIds): array
    {
        $localizationPublicIds = $this->localizationPublicIds(
            $localizationPublicIds
        );
        if ($localizationPublicIds === []) {
            return [];
        }

        $parameters = [];
        $placeholders = [];
        foreach ($localizationPublicIds as $index => $publicId) {
            $name = 'catalog_localization_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $publicId;
        }

        try {
            $statement = $this->pdo->prepare(
                $this->selectSql($placeholders)
            );
            if (!$statement instanceof PDOStatement) {
                throw $this->storageUnavailable();
            }
            foreach ($parameters as $name => $value) {
                if (!$statement->bindValue(':' . $name, $value, PDO::PARAM_STR)) {
                    throw $this->storageUnavailable();
                }
            }
            if (!$statement->execute()) {
                throw $this->storageUnavailable();
            }
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) {
                throw $this->storageUnavailable();
            }
        } catch (BlogStructuredContentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->storageUnavailable();
        }

        $snapshots = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            try {
                $publicId = BlogInput::publicId(
                    $this->string($row, 'localization_public_id')
                );
                $snapshot = $this->snapshot($row);
                if (isset($snapshots[$publicId])) {
                    throw new \UnexpectedValueException(
                        'Duplicate Blog SEO catalog snapshot.'
                    );
                }
                $snapshots[$publicId] = $snapshot;
            } catch (Throwable) {
                // SEO is additive. One corrupt row must not hide valid peers.
                continue;
            }
        }

        return $snapshots;
    }

    /** @param list<string> $placeholders */
    private function selectSql(array $placeholders): string
    {
        $private = $this->workspaces !== null && $this->revisions !== null;
        $effective = static fn (
            string $revision,
            string $current
        ): string => $private
            ? 'CASE WHEN w.draft_revision_id IS NOT NULL THEN '
                . $revision . ' ELSE ' . $current . ' END'
            : $current;

        $sourceKind = $private
            ? "CASE WHEN w.draft_revision_id IS NOT NULL THEN 'revision' "
                . "WHEN d.id IS NULL THEN 'legacy' ELSE 'document' END"
            : "CASE WHEN d.id IS NULL THEN 'legacy' ELSE 'document' END";

        $layoutColumns = [
            'layout_schema_version',
            'layout_template_key',
            'layout_document_json',
            'layout_document_bytes',
            'layout_document_sha256',
            'layout_snapshot_sha256',
        ];
        $layoutProjection = [];
        foreach ($layoutColumns as $alias) {
            if ($this->layoutDocuments === null) {
                $layoutProjection[] = 'NULL AS ' . $alias;
                continue;
            }
            $column = substr($alias, strlen('layout_'));
            $layoutProjection[] = $effective(
                $this->layoutRevisions === null
                    ? 'NULL'
                    : 'lr.' . $column,
                'ld.' . $column
            ) . ' AS ' . $alias;
        }

        $sql = 'SELECT l.public_id AS localization_public_id, '
            . $sourceKind . ' AS source_kind, '
            . $effective('r.h1', 'l.h1') . ' AS h1, '
            . $effective('r.slug', 'l.slug') . ' AS slug, '
            . $effective('r.seo_title', 'l.seo_title') . ' AS seo_title, '
            . $effective('r.meta_description', 'l.meta_description')
            . ' AS meta_description, '
            . $effective('r.excerpt', 'l.excerpt') . ' AS excerpt, '
            . $effective('r.body_text', 'l.body_text') . ' AS body_text, '
            . $effective('r.schema_version', 'd.schema_version')
            . ' AS schema_version, '
            . $effective('r.template_key', 'd.template_key')
            . ' AS template_key, '
            . $effective('r.document_json', 'd.document_json')
            . ' AS document_json, '
            . $effective('r.document_bytes', 'd.document_bytes')
            . ' AS document_bytes, '
            . $effective('r.document_sha256', 'd.document_sha256')
            . ' AS document_sha256, '
            . $effective('r.body_text_sha256', 'd.body_text_sha256')
            . ' AS body_text_sha256, '
            . $effective('r.snapshot_sha256', 'd.snapshot_sha256')
            . ' AS snapshot_sha256, '
            . implode(', ', $layoutProjection)
            . ' FROM ' . $this->localizations . ' l LEFT JOIN '
            . $this->documents . ' d ON d.localization_id = l.id';

        if ($private) {
            $sql .= ' LEFT JOIN ' . $this->workspaces
                . ' w ON w.localization_id = l.id LEFT JOIN '
                . $this->revisions
                . ' r ON r.id = w.draft_revision_id '
                . 'AND r.localization_id = l.id';
        }
        if ($this->layoutDocuments !== null) {
            $sql .= ' LEFT JOIN ' . $this->layoutDocuments
                . ' ld ON ld.document_id = d.id';
        }
        if ($this->layoutRevisions !== null) {
            $sql .= ' LEFT JOIN ' . $this->layoutRevisions
                . ' lr ON lr.revision_id = r.id';
        }

        return $sql . ' WHERE l.public_id IN ('
            . implode(', ', $placeholders) . ') ORDER BY l.public_id ASC';
    }

    /** @param array<string, mixed> $row */
    private function snapshot(array $row): BlogStructuredDraft
    {
        $kind = $this->string($row, 'source_kind');
        $h1 = $this->string($row, 'h1');
        $slug = $this->nullableString($row, 'slug');
        $seoTitle = $this->nullableString($row, 'seo_title');
        $description = $this->nullableString($row, 'meta_description');
        $excerpt = $this->nullableString($row, 'excerpt');
        $body = $this->string($row, 'body_text');

        if ($kind === 'legacy') {
            return new BlogStructuredDraft(
                $h1,
                $this->legacyFactory->create($body),
                $slug,
                $seoTitle,
                $description,
                $excerpt,
                $this->codec
            );
        }
        if (!in_array($kind, ['document', 'revision'], true)) {
            throw new \UnexpectedValueException(
                'Invalid Blog SEO catalog source.'
            );
        }

        $baseJson = $this->string($row, 'document_json');
        $layoutJson = $this->nullableString($row, 'layout_document_json');
        $document = $layoutJson === null
            ? $this->codec->decode($baseJson)
            : $this->codec->decodeDraft($layoutJson);
        $draft = new BlogStructuredDraft(
            $h1,
            $document,
            $slug,
            $seoTitle,
            $description,
            $excerpt,
            $this->codec
        );

        if (
            !hash_equals($body, $draft->compatibilityDraft()->bodyText())
            || $this->integer($row, 'schema_version')
                !== $draft->compatibilitySchemaVersion()
            || !hash_equals(
                $this->string($row, 'template_key'),
                $draft->compatibilityTemplateKey()
            )
            || !hash_equals($baseJson, $draft->compatibilityCanonicalJson())
            || $this->integer($row, 'document_bytes')
                !== $draft->compatibilityDocumentBytes()
            || !hash_equals(
                $this->hash($row, 'document_sha256'),
                $draft->compatibilityDocumentSha256()
            )
            || !hash_equals(
                $this->hash($row, 'body_text_sha256'),
                $draft->bodyTextSha256()
            )
            || !hash_equals(
                $this->hash($row, 'snapshot_sha256'),
                $draft->compatibilitySnapshotSha256()
            )
        ) {
            throw new \UnexpectedValueException(
                'Corrupt Blog SEO catalog snapshot.'
            );
        }

        if ($layoutJson !== null) {
            if (
                $draft->schemaVersion() !== BlogDocument::LAYOUT_VERSION
                || $this->integer($row, 'layout_schema_version')
                    !== $draft->schemaVersion()
                || !hash_equals(
                    $this->string($row, 'layout_template_key'),
                    $draft->templateKey()
                )
                || !$this->layoutMetadataMatches($draft, $layoutJson, $row)
            ) {
                throw new \UnexpectedValueException(
                    'Corrupt Blog SEO catalog layout snapshot.'
                );
            }
        } elseif ($this->hasAnyLayoutMetadata($row)) {
            throw new \UnexpectedValueException(
                'Partial Blog SEO catalog layout snapshot.'
            );
        }

        return $draft;
    }

    /** @param array<string, mixed> $row */
    private function layoutMetadataMatches(
        BlogStructuredDraft $draft,
        string $persistedJson,
        array $row
    ): bool {
        $persistedBytes = $this->integer($row, 'layout_document_bytes');
        $persistedSha256 = $this->hash($row, 'layout_document_sha256');
        $persistedSnapshotSha256 = $this->hash(
            $row,
            'layout_snapshot_sha256'
        );
        if (
            hash_equals($draft->canonicalJson(), $persistedJson)
            && $persistedBytes === $draft->documentBytes()
            && hash_equals($draft->documentSha256(), $persistedSha256)
            && hash_equals(
                $draft->snapshotSha256(),
                $persistedSnapshotSha256
            )
        ) {
            return true;
        }

        $legacyJson = null;
        foreach ((new BlogDocumentV2CompatibilityCanonicalizer())
            ->candidates($draft->document()) as $candidate) {
            if (hash_equals($candidate, $persistedJson)) {
                $legacyJson = $candidate;
                break;
            }
        }
        if ($legacyJson === null) {
            return false;
        }
        $legacySha256 = hash('sha256', $legacyJson);
        $legacySnapshotSha256 = (new BlogStructuredSnapshotHasher())->hash(
            $draft->compatibilityDraft(),
            $legacySha256
        );

        return $persistedBytes === strlen($legacyJson)
            && hash_equals($legacySha256, $persistedSha256)
            && hash_equals(
                $legacySnapshotSha256,
                $persistedSnapshotSha256
            );
    }

    /** @param array<string, mixed> $row */
    private function hasAnyLayoutMetadata(array $row): bool
    {
        foreach ([
            'layout_schema_version',
            'layout_template_key',
            'layout_document_bytes',
            'layout_document_sha256',
            'layout_snapshot_sha256',
        ] as $key) {
            if (($row[$key] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @param list<mixed> $values @return list<string> */
    private function localizationPublicIds(array $values): array
    {
        if (!array_is_list($values) || count($values) > self::MAX_SNAPSHOTS) {
            throw new \InvalidArgumentException(
                'Invalid Blog SEO catalog snapshot request.'
            );
        }

        $validated = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException(
                    'Invalid Blog SEO catalog snapshot request.'
                );
            }
            $publicId = BlogInput::publicId($value);
            if (isset($validated[$publicId])) {
                throw new \InvalidArgumentException(
                    'Duplicate Blog SEO catalog snapshot request.'
                );
            }
            $validated[$publicId] = $publicId;
        }

        return array_values($validated);
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            throw new \UnexpectedValueException(
                'Invalid Blog SEO catalog value.'
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }

        return $this->string($row, $key);
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (
            is_string($value)
            && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1
            && (string) (int) $value === $value
        ) {
            return (int) $value;
        }

        throw new \UnexpectedValueException(
            'Invalid Blog SEO catalog integer.'
        );
    }

    /** @param array<string, mixed> $row */
    private function hash(array $row, string $key): string
    {
        $value = $this->string($row, $key);
        if (preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                'Invalid Blog SEO catalog hash.'
            );
        }

        return $value;
    }

    private function storageUnavailable(): BlogStructuredContentException
    {
        return new BlogStructuredContentException(
            BlogStructuredContentException::STORAGE_UNAVAILABLE
        );
    }
}
