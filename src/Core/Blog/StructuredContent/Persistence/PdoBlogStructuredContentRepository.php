<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Persistence;

use App\Core\Blog\BlogInput;
use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredMediaReference;
use App\Core\Modules\Migrations\MigrationScope;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use Throwable;

/** PDO persistence for immutable structured snapshots and their media links. */
final class PdoBlogStructuredContentRepository implements
    BlogStructuredContentRepositoryInterface
{
    private const UTC_FORMAT = 'Y-m-d H:i:s.u';
    private const SUPPORTED_DRIVERS = ['mysql', 'sqlite'];

    private readonly string $driver;
    private readonly string $localizations;
    private readonly string $documents;
    private readonly string $revisions;
    private readonly string $documentMedia;
    private readonly string $revisionMedia;
    private readonly BlogDocumentCodec $codec;

    public function __construct(
        private readonly PDO $pdo,
        MigrationScope $blogScope,
        ?BlogDocumentCodec $codec = null
    ) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (
                !is_string($driver)
                || !in_array($driver, self::SUPPORTED_DRIVERS, true)
                || $blogScope->moduleId() !== 'blog'
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
            if ($driver === 'sqlite') {
                $foreignKeys = $pdo->query('PRAGMA foreign_keys');
                if (
                    !$foreignKeys instanceof PDOStatement
                    || !in_array($foreignKeys->fetchColumn(), [1, '1'], true)
                ) {
                    throw $this->storageUnavailable();
                }
            }

            $this->driver = $driver;
            $this->localizations = $blogScope->quotedTable(
                'post_localizations',
                $driver
            );
            $this->documents = $blogScope->quotedTable(
                'content_docs',
                $driver
            );
            $this->revisions = $blogScope->quotedTable(
                'content_revisions',
                $driver
            );
            $this->documentMedia = $blogScope->quotedTable(
                'content_media',
                $driver
            );
            $this->revisionMedia = $blogScope->quotedTable(
                'revision_media',
                $driver
            );
            $this->codec = $codec ?? new BlogDocumentCodec();
        } catch (BlogStructuredContentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->storageUnavailable();
        }
    }

    public function hasCurrent(string $localizationPublicId): bool
    {
        $localizationPublicId = $this->publicId($localizationPublicId);

        return $this->one(
            'SELECT d.id FROM ' . $this->documents . ' d '
            . 'JOIN ' . $this->localizations . ' l '
            . 'ON l.id = d.localization_id '
            . 'WHERE l.public_id = :localization_public_id',
            ['localization_public_id' => $localizationPublicId]
        ) !== null;
    }

    public function current(
        string $localizationPublicId
    ): ?BlogStructuredDocumentRecord {
        $localizationPublicId = $this->publicId($localizationPublicId);
        $row = $this->one(
            'SELECT d.id AS internal_id, '
            . 'd.public_id AS document_public_id, '
            . 'l.public_id AS localization_public_id, '
            . 'd.schema_version, d.template_key, d.document_json, '
            . 'd.document_bytes, d.document_sha256, d.body_text_sha256, '
            . 'd.snapshot_sha256, d.created_by_user_public_id, '
            . 'd.updated_by_user_public_id, d.created_at, d.updated_at, '
            . 'l.h1, l.slug, l.seo_title, l.meta_description, l.excerpt, '
            . 'l.body_text FROM ' . $this->documents . ' d '
            . 'JOIN ' . $this->localizations . ' l '
            . 'ON l.id = d.localization_id '
            . 'WHERE l.public_id = :localization_public_id',
            ['localization_public_id' => $localizationPublicId]
        );
        if ($row === null) {
            return null;
        }

        $record = $this->documentRecord($row);
        $this->assertPersistedMedia(
            $this->documentMedia,
            'document_id',
            $record->internalId(),
            $record->snapshot()->mediaReferences()
        );

        return $record;
    }

    public function revision(
        string $revisionPublicId
    ): ?BlogStructuredRevisionRecord {
        $revisionPublicId = $this->publicId($revisionPublicId);
        $row = $this->one(
            'SELECT r.id AS internal_id, '
            . 'r.public_id AS revision_public_id, '
            . 'l.public_id AS localization_public_id, '
            . 'r.revision_number, r.variant_lock_version, '
            . 'r.schema_version, r.template_key, r.document_json, '
            . 'r.document_bytes, r.document_sha256, r.body_text_sha256, '
            . 'r.snapshot_sha256, r.h1, r.slug, r.seo_title, '
            . 'r.meta_description, r.excerpt, r.body_text, '
            . 'r.created_by_user_public_id, r.created_at '
            . 'FROM ' . $this->revisions . ' r '
            . 'JOIN ' . $this->localizations . ' l '
            . 'ON l.id = r.localization_id '
            . 'WHERE r.public_id = :revision_public_id',
            ['revision_public_id' => $revisionPublicId]
        );
        if ($row === null) {
            return null;
        }

        $record = $this->revisionRecord($row);
        $this->assertPersistedMedia(
            $this->revisionMedia,
            'revision_id',
            $record->internalId(),
            $record->snapshot()->mediaReferences()
        );

        return $record;
    }

    public function listRevisions(
        string $localizationPublicId,
        int $limit,
        int $offset
    ): array {
        $localizationPublicId = $this->publicId($localizationPublicId);
        try {
            BlogInput::listLimit($limit);
            BlogInput::listOffset($offset);
        } catch (Throwable) {
            throw $this->invalidInput();
        }

        $statement = $this->prepare(
            'SELECT r.public_id AS revision_public_id, '
            . 'l.public_id AS localization_public_id, '
            . 'r.revision_number, r.variant_lock_version, '
            . 'r.schema_version, r.template_key, r.document_bytes, '
            . 'r.created_at, (SELECT COUNT(*) FROM '
            . $this->revisionMedia . ' rm WHERE rm.revision_id = r.id) '
            . 'AS media_count FROM ' . $this->revisions . ' r '
            . 'JOIN ' . $this->localizations . ' l '
            . 'ON l.id = r.localization_id '
            . 'WHERE l.public_id = :localization_public_id '
            . 'ORDER BY r.revision_number DESC, r.id DESC '
            . 'LIMIT :list_limit OFFSET :list_offset'
        );
        try {
            if (
                !$statement->bindValue(
                    ':localization_public_id',
                    $localizationPublicId,
                    PDO::PARAM_STR
                )
                || !$statement->bindValue(
                    ':list_limit',
                    $limit,
                    PDO::PARAM_INT
                )
                || !$statement->bindValue(
                    ':list_offset',
                    $offset,
                    PDO::PARAM_INT
                )
                || !$statement->execute()
            ) {
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

        $summaries = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw $this->storageUnavailable();
            }
            $summaries[] = $this->revisionSummary($row);
        }

        return $summaries;
    }

    public function upsertCurrent(
        string $localizationPublicId,
        string $documentPublicId,
        BlogStructuredDraft $draft,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void {
        $this->assertWriteTransaction();
        $localizationPublicId = $this->publicId($localizationPublicId);
        $documentPublicId = $this->generatedPublicId($documentPublicId);
        $actorPublicId = $this->publicId($actorPublicId);
        $timestamp = $this->format($now);
        $localization = $this->lockLocalization($localizationPublicId);
        $this->assertDraftMatchesLocalization($draft, $localization);

        $existing = $this->one(
            'SELECT id FROM ' . $this->documents
            . ' WHERE localization_id = :localization_id'
            . $this->forUpdate(),
            ['localization_id' => $localization['id']]
        );
        $parameters = $this->documentParameters(
            $draft,
            $actorPublicId,
            $timestamp
        );

        if ($existing === null) {
            $statement = $this->prepare(
                'INSERT INTO ' . $this->documents . ' '
                . '(public_id, localization_id, schema_version, '
                . 'template_key, document_json, document_bytes, '
                . 'document_sha256, body_text_sha256, snapshot_sha256, '
                . 'created_by_user_public_id, updated_by_user_public_id, '
                . 'created_at, updated_at) VALUES '
                . '(:public_id, :localization_id, :schema_version, '
                . ':template_key, :document_json, :document_bytes, '
                . ':document_sha256, :body_text_sha256, :snapshot_sha256, '
                . ':created_actor, :updated_actor, :created_at, :updated_at)'
            );
            $parameters['public_id'] = $documentPublicId;
            $parameters['localization_id'] = $localization['id'];
            $parameters['created_actor'] = $actorPublicId;
            $parameters['created_at'] = $timestamp;
            $this->execute($statement, $parameters);
            if ($statement->rowCount() !== 1) {
                throw $this->storageUnavailable();
            }

            return;
        }

        $statement = $this->prepare(
            'UPDATE ' . $this->documents . ' SET '
            . 'schema_version = :schema_version, '
            . 'template_key = :template_key, document_json = :document_json, '
            . 'document_bytes = :document_bytes, '
            . 'document_sha256 = :document_sha256, '
            . 'body_text_sha256 = :body_text_sha256, '
            . 'snapshot_sha256 = :snapshot_sha256, '
            . 'updated_by_user_public_id = :updated_actor, '
            . 'updated_at = :updated_at WHERE id = :document_id'
        );
        $parameters['document_id'] = $this->positiveInteger(
            $existing['id'] ?? null
        );
        $this->execute($statement, $parameters);
        if ($statement->rowCount() > 1) {
            throw $this->storageUnavailable();
        }
    }

    public function replaceCurrentMedia(
        string $localizationPublicId,
        array $references,
        DateTimeImmutable $now
    ): void {
        $this->assertWriteTransaction();
        $localizationPublicId = $this->publicId($localizationPublicId);
        $references = $this->references($references);
        $timestamp = $this->format($now);
        $localization = $this->lockLocalization($localizationPublicId);
        $row = $this->one(
            'SELECT id, document_json FROM ' . $this->documents
            . ' WHERE localization_id = :localization_id'
            . $this->forUpdate(),
            ['localization_id' => $localization['id']]
        );
        if ($row === null) {
            throw $this->invalidInput();
        }
        $documentId = $this->positiveInteger($row['id'] ?? null);
        $this->assertReferenceSetsEqual(
            $references,
            $this->referencesFromJson($this->requiredString(
                $row,
                'document_json'
            ))
        );

        $delete = $this->prepare(
            'DELETE FROM ' . $this->documentMedia
            . ' WHERE document_id = :document_id'
        );
        $this->execute($delete, ['document_id' => $documentId]);
        $this->insertMediaRows(
            $this->documentMedia,
            'document_id',
            $documentId,
            $references,
            $timestamp
        );
    }

    public function appendRevision(
        string $localizationPublicId,
        string $revisionPublicId,
        int $variantLockVersion,
        BlogStructuredDraft $draft,
        string $actorPublicId,
        DateTimeImmutable $now
    ): int {
        $this->assertWriteTransaction();
        $localizationPublicId = $this->publicId($localizationPublicId);
        $revisionPublicId = $this->generatedPublicId($revisionPublicId);
        $actorPublicId = $this->publicId($actorPublicId);
        try {
            BlogInput::lockVersion($variantLockVersion);
        } catch (Throwable) {
            throw $this->invalidInput();
        }
        $timestamp = $this->format($now);
        $localization = $this->lockLocalization($localizationPublicId);
        if ($localization['lock_version'] !== $variantLockVersion) {
            throw $this->invalidInput();
        }
        $this->assertDraftMatchesLocalization($draft, $localization);

        $row = $this->one(
            'SELECT MAX(revision_number) AS latest_revision_number FROM '
            . $this->revisions . ' WHERE localization_id = :localization_id',
            ['localization_id' => $localization['id']]
        );
        if ($row === null) {
            throw $this->storageUnavailable();
        }
        $latest = $this->nonNegativeInteger(
            $row['latest_revision_number'] ?? null,
            true
        );
        if ($latest >= PHP_INT_MAX) {
            throw $this->storageUnavailable();
        }
        $revisionNumber = $latest + 1;

        $statement = $this->prepare(
            'INSERT INTO ' . $this->revisions . ' '
            . '(public_id, localization_id, revision_number, '
            . 'variant_lock_version, schema_version, template_key, '
            . 'document_json, document_bytes, document_sha256, '
            . 'body_text_sha256, snapshot_sha256, h1, slug, seo_title, '
            . 'meta_description, excerpt, body_text, '
            . 'created_by_user_public_id, created_at) VALUES '
            . '(:public_id, :localization_id, :revision_number, '
            . ':variant_lock_version, :schema_version, :template_key, '
            . ':document_json, :document_bytes, :document_sha256, '
            . ':body_text_sha256, :snapshot_sha256, :h1, :slug, '
            . ':seo_title, :meta_description, :excerpt, :body_text, '
            . ':created_actor, :created_at)'
        );
        $compatibility = $draft->compatibilityDraft();
        $this->execute($statement, [
            'public_id' => $revisionPublicId,
            'localization_id' => $localization['id'],
            'revision_number' => $revisionNumber,
            'variant_lock_version' => $variantLockVersion,
            'schema_version' => $draft->schemaVersion(),
            'template_key' => $draft->templateKey(),
            'document_json' => $draft->canonicalJson(),
            'document_bytes' => $draft->documentBytes(),
            'document_sha256' => $draft->documentSha256(),
            'body_text_sha256' => $draft->bodyTextSha256(),
            'snapshot_sha256' => $draft->snapshotSha256(),
            'h1' => $compatibility->h1(),
            'slug' => $compatibility->slug(),
            'seo_title' => $compatibility->seoTitle(),
            'meta_description' => $compatibility->metaDescription(),
            'excerpt' => $compatibility->excerpt(),
            'body_text' => $compatibility->bodyText(),
            'created_actor' => $actorPublicId,
            'created_at' => $timestamp,
        ]);
        if ($statement->rowCount() !== 1) {
            throw $this->storageUnavailable();
        }

        return $revisionNumber;
    }

    public function appendRevisionMedia(
        string $revisionPublicId,
        array $references,
        DateTimeImmutable $now
    ): void {
        $this->assertWriteTransaction();
        $revisionPublicId = $this->publicId($revisionPublicId);
        $references = $this->references($references);
        $timestamp = $this->format($now);
        $row = $this->one(
            'SELECT id, document_json FROM ' . $this->revisions
            . ' WHERE public_id = :revision_public_id'
            . $this->forUpdate(),
            ['revision_public_id' => $revisionPublicId]
        );
        if ($row === null) {
            throw $this->invalidInput();
        }
        $revisionId = $this->positiveInteger($row['id'] ?? null);
        $this->assertReferenceSetsEqual(
            $references,
            $this->referencesFromJson($this->requiredString(
                $row,
                'document_json'
            ))
        );
        $existing = $this->one(
            'SELECT COUNT(*) AS media_count FROM ' . $this->revisionMedia
            . ' WHERE revision_id = :revision_id',
            ['revision_id' => $revisionId]
        );
        if (
            $existing === null
            || $this->nonNegativeInteger(
                $existing['media_count'] ?? null
            ) !== 0
        ) {
            throw $this->invalidInput();
        }
        $this->insertMediaRows(
            $this->revisionMedia,
            'revision_id',
            $revisionId,
            $references,
            $timestamp
        );
    }

    /** @return array<string, mixed> */
    private function lockLocalization(string $publicId): array
    {
        $row = $this->one(
            'SELECT id, lock_version, h1, slug, seo_title, '
            . 'meta_description, excerpt, body_text FROM '
            . $this->localizations . ' WHERE public_id = :public_id'
            . $this->forUpdate(),
            ['public_id' => $publicId]
        );
        if ($row === null) {
            throw $this->invalidInput();
        }
        $row['id'] = $this->positiveInteger($row['id'] ?? null);
        $row['lock_version'] = $this->positiveInteger(
            $row['lock_version'] ?? null
        );

        return $row;
    }

    /** @param array<string, mixed> $localization */
    private function assertDraftMatchesLocalization(
        BlogStructuredDraft $draft,
        array $localization
    ): void {
        $compatibility = $draft->compatibilityDraft();
        if (
            $this->requiredString($localization, 'h1')
                !== $compatibility->h1()
            || $this->nullableString($localization, 'slug')
                !== $compatibility->slug()
            || $this->nullableString($localization, 'seo_title')
                !== $compatibility->seoTitle()
            || $this->nullableString($localization, 'meta_description')
                !== $compatibility->metaDescription()
            || $this->nullableString($localization, 'excerpt')
                !== $compatibility->excerpt()
            || $this->requiredString($localization, 'body_text')
                !== $compatibility->bodyText()
        ) {
            throw $this->invalidInput();
        }
    }

    /** @return array<string, int|string> */
    private function documentParameters(
        BlogStructuredDraft $draft,
        string $actorPublicId,
        string $timestamp
    ): array {
        return [
            'schema_version' => $draft->schemaVersion(),
            'template_key' => $draft->templateKey(),
            'document_json' => $draft->canonicalJson(),
            'document_bytes' => $draft->documentBytes(),
            'document_sha256' => $draft->documentSha256(),
            'body_text_sha256' => $draft->bodyTextSha256(),
            'snapshot_sha256' => $draft->snapshotSha256(),
            'updated_actor' => $actorPublicId,
            'updated_at' => $timestamp,
        ];
    }

    /** @param array<string, mixed> $row */
    private function documentRecord(array $row): BlogStructuredDocumentRecord
    {
        try {
            $draft = $this->draft($row);
            return new BlogStructuredDocumentRecord(
                $this->positiveInteger($row['internal_id'] ?? null),
                $this->requiredString($row, 'document_public_id'),
                $this->requiredString($row, 'localization_public_id'),
                $draft,
                $this->positiveInteger($row['schema_version'] ?? null),
                $this->requiredString($row, 'template_key'),
                $this->positiveInteger($row['document_bytes'] ?? null),
                $this->requiredString($row, 'document_sha256'),
                $this->requiredString($row, 'body_text_sha256'),
                $this->requiredString($row, 'snapshot_sha256'),
                $this->requiredString($row, 'created_by_user_public_id'),
                $this->requiredString($row, 'updated_by_user_public_id'),
                $this->timestamp($row['created_at'] ?? null),
                $this->timestamp($row['updated_at'] ?? null)
            );
        } catch (BlogStructuredContentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->corruptDocument();
        }
    }

    /** @param array<string, mixed> $row */
    private function revisionRecord(array $row): BlogStructuredRevisionRecord
    {
        try {
            $draft = $this->draft($row);
            return new BlogStructuredRevisionRecord(
                $this->positiveInteger($row['internal_id'] ?? null),
                $this->requiredString($row, 'revision_public_id'),
                $this->requiredString($row, 'localization_public_id'),
                $this->positiveInteger($row['revision_number'] ?? null),
                $this->positiveInteger($row['variant_lock_version'] ?? null),
                $draft,
                $this->positiveInteger($row['schema_version'] ?? null),
                $this->requiredString($row, 'template_key'),
                $this->positiveInteger($row['document_bytes'] ?? null),
                $this->requiredString($row, 'document_sha256'),
                $this->requiredString($row, 'body_text_sha256'),
                $this->requiredString($row, 'snapshot_sha256'),
                $this->requiredString($row, 'created_by_user_public_id'),
                $this->timestamp($row['created_at'] ?? null)
            );
        } catch (BlogStructuredContentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->corruptDocument();
        }
    }

    /** @param array<string, mixed> $row */
    private function revisionSummary(array $row): BlogStructuredRevisionSummary
    {
        try {
            return new BlogStructuredRevisionSummary(
                $this->requiredString($row, 'revision_public_id'),
                $this->requiredString($row, 'localization_public_id'),
                $this->positiveInteger($row['revision_number'] ?? null),
                $this->positiveInteger($row['variant_lock_version'] ?? null),
                $this->positiveInteger($row['schema_version'] ?? null),
                $this->requiredString($row, 'template_key'),
                $this->positiveInteger($row['document_bytes'] ?? null),
                $this->nonNegativeInteger($row['media_count'] ?? null),
                $this->timestamp($row['created_at'] ?? null)
            );
        } catch (BlogStructuredContentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->corruptDocument();
        }
    }

    /** @param array<string, mixed> $row */
    private function draft(array $row): BlogStructuredDraft
    {
        try {
            $document = $this->codec->decode(
                $this->requiredString($row, 'document_json')
            );
            $draft = new BlogStructuredDraft(
                $this->requiredString($row, 'h1'),
                $document,
                $this->nullableString($row, 'slug'),
                $this->nullableString($row, 'seo_title'),
                $this->nullableString($row, 'meta_description'),
                $this->nullableString($row, 'excerpt'),
                $this->codec
            );
            if (!hash_equals(
                $draft->compatibilityDraft()->bodyText(),
                $this->requiredString($row, 'body_text')
            )) {
                throw $this->corruptDocument();
            }

            return $draft;
        } catch (BlogStructuredContentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->corruptDocument();
        }
    }

    /**
     * @param list<BlogStructuredMediaReference> $expected
     */
    private function assertPersistedMedia(
        string $table,
        string $foreignKey,
        int $ownerId,
        array $expected
    ): void {
        $statement = $this->prepare(
            'SELECT block_public_id, media_asset_public_id, role FROM '
            . $table . ' WHERE ' . $foreignKey . ' = :owner_id '
            . 'ORDER BY block_public_id ASC'
        );
        $this->execute($statement, ['owner_id' => $ownerId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            throw $this->storageUnavailable();
        }
        try {
            $actual = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw $this->corruptDocument();
                }
                $reference = new BlogStructuredMediaReference(
                    $this->requiredString($row, 'block_public_id'),
                    $this->requiredString($row, 'media_asset_public_id'),
                    $this->requiredString($row, 'role')
                );
                if (isset($actual[$reference->blockPublicId()])) {
                    throw $this->corruptDocument();
                }
                $actual[$reference->blockPublicId()] = $reference->toArray();
            }
            $expectedMap = $this->referenceMap($expected);
            ksort($actual);
            ksort($expectedMap);
            if ($actual !== $expectedMap) {
                throw $this->corruptDocument();
            }
        } catch (BlogStructuredContentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->corruptDocument();
        }
    }

    /**
     * @param list<BlogStructuredMediaReference> $references
     */
    private function insertMediaRows(
        string $table,
        string $foreignKey,
        int $ownerId,
        array $references,
        string $timestamp
    ): void {
        if ($references === []) {
            return;
        }
        $statement = $this->prepare(
            'INSERT INTO ' . $table . ' (' . $foreignKey . ', '
            . 'block_public_id, media_asset_public_id, role, created_at) '
            . 'VALUES (:owner_id, :block_public_id, '
            . ':media_asset_public_id, :role, :created_at)'
        );
        foreach ($references as $reference) {
            $this->execute($statement, [
                'owner_id' => $ownerId,
                'block_public_id' => $reference->blockPublicId(),
                'media_asset_public_id' => $reference->mediaAssetPublicId(),
                'role' => $reference->role(),
                'created_at' => $timestamp,
            ]);
            if ($statement->rowCount() !== 1) {
                throw $this->storageUnavailable();
            }
        }
    }

    /** @return list<BlogStructuredMediaReference> */
    private function referencesFromJson(string $json): array
    {
        try {
            $references = [];
            foreach ($this->codec->decode($json)->blocks() as $block) {
                if (($block['type'] ?? null) !== 'image') {
                    continue;
                }
                $references[] = new BlogStructuredMediaReference(
                    (string) $block['id'],
                    (string) $block['media_asset_public_id'],
                    ($block['display'] ?? null) === 'cover'
                        ? BlogStructuredMediaReference::COVER
                        : BlogStructuredMediaReference::IMAGE
                );
            }

            return $references;
        } catch (Throwable) {
            throw $this->corruptDocument();
        }
    }

    /**
     * @param list<BlogStructuredMediaReference> $first
     * @param list<BlogStructuredMediaReference> $second
     */
    private function assertReferenceSetsEqual(array $first, array $second): void
    {
        $first = $this->referenceMap($first);
        $second = $this->referenceMap($second);
        ksort($first);
        ksort($second);
        if ($first !== $second) {
            throw $this->invalidInput();
        }
    }

    /**
     * @param list<BlogStructuredMediaReference> $references
     * @return array<string, array{block_public_id: string, media_asset_public_id: string, role: string}>
     */
    private function referenceMap(array $references): array
    {
        $map = [];
        foreach ($references as $reference) {
            if (isset($map[$reference->blockPublicId()])) {
                throw $this->invalidInput();
            }
            $map[$reference->blockPublicId()] = $reference->toArray();
        }

        return $map;
    }

    /** @param array<mixed> $references @return list<BlogStructuredMediaReference> */
    private function references(array $references): array
    {
        if (
            !array_is_list($references)
            || count($references) > BlogDocument::MAX_BLOCKS
        ) {
            throw $this->invalidInput();
        }
        foreach ($references as $reference) {
            if (!$reference instanceof BlogStructuredMediaReference) {
                throw $this->invalidInput();
            }
        }
        $this->referenceMap($references);

        /** @var list<BlogStructuredMediaReference> $references */
        return $references;
    }

    /** @param array<string, mixed> $parameters */
    private function one(string $sql, array $parameters = []): ?array
    {
        $statement = $this->prepare($sql);
        $this->execute($statement, $parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw $this->storageUnavailable();
        }

        return $row;
    }

    private function prepare(string $sql): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
        } catch (Throwable) {
            throw $this->storageUnavailable();
        }
        if (!$statement instanceof PDOStatement) {
            throw $this->storageUnavailable();
        }

        return $statement;
    }

    /** @param array<string, mixed> $parameters */
    private function execute(PDOStatement $statement, array $parameters): void
    {
        try {
            if (!$statement->execute($parameters)) {
                throw $this->storageUnavailable();
            }
        } catch (BlogStructuredContentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->storageUnavailable();
        }
    }

    private function publicId(string $value): string
    {
        try {
            return BlogInput::publicId($value);
        } catch (Throwable) {
            throw $this->invalidInput();
        }
    }

    private function generatedPublicId(string $value): string
    {
        try {
            return BlogInput::generatedPublicId($value);
        } catch (Throwable) {
            throw $this->invalidInput();
        }
    }

    private function format(DateTimeImmutable $value): string
    {
        try {
            return BlogInput::utc($value)->format(self::UTC_FORMAT);
        } catch (Throwable) {
            throw $this->invalidInput();
        }
    }

    private function timestamp(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw $this->corruptDocument();
        }
        $parsed = DateTimeImmutable::createFromFormat(
            '!' . self::UTC_FORMAT,
            $value,
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (
            !$parsed instanceof DateTimeImmutable
            || (
                $errors !== false
                && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)
            )
            || $parsed->format(self::UTC_FORMAT) !== $value
        ) {
            throw $this->corruptDocument();
        }

        return $parsed;
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw $this->corruptDocument();
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw $this->corruptDocument();
        }

        return $value;
    }

    private function positiveInteger(mixed $value): int
    {
        $integer = $this->integer($value);
        if ($integer < 1) {
            throw $this->corruptDocument();
        }

        return $integer;
    }

    private function nonNegativeInteger(
        mixed $value,
        bool $nullableAsZero = false
    ): int {
        if ($value === null && $nullableAsZero) {
            return 0;
        }
        $integer = $this->integer($value);
        if ($integer < 0) {
            throw $this->corruptDocument();
        }

        return $integer;
    }

    private function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (
            is_string($value)
            && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1
            && (string) (int) $value === $value
        ) {
            return (int) $value;
        }

        throw $this->corruptDocument();
    }

    private function forUpdate(): string
    {
        return $this->driver === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function assertWriteTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw $this->storageUnavailable();
        }
    }

    private function invalidInput(): BlogStructuredContentException
    {
        return new BlogStructuredContentException(
            BlogStructuredContentException::INVALID_INPUT
        );
    }

    private function storageUnavailable(): BlogStructuredContentException
    {
        return new BlogStructuredContentException(
            BlogStructuredContentException::STORAGE_UNAVAILABLE
        );
    }

    private function corruptDocument(): BlogStructuredContentException
    {
        return new BlogStructuredContentException(
            BlogStructuredContentException::CORRUPT_DOCUMENT
        );
    }
}
