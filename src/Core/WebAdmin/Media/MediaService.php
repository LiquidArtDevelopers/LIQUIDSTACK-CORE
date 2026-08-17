<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use App\Core\Http\UploadedFile;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\SystemClock;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use App\Core\WebAdmin\Media\Usage\MediaUsageProviderRegistry;
use App\Core\WebAdmin\Media\Usage\MediaUsageStatus;
use App\Core\WebAdmin\Media\Usage\MediaDeletionReferenceGate;
use Throwable;

final class MediaService
{
    public const VIEW_CAPABILITY = 'webadmin.media.view';
    public const UPLOAD_CAPABILITY = 'webadmin.media.upload';
    public const DELETE_CAPABILITY = 'webadmin.media.delete';
    public const DEFAULT_QUOTA_BYTES = 2_147_483_648;
    public const USER_UPLOADS_PER_HOUR = 20;
    public const IP_UPLOADS_PER_HOUR = 40;
    private readonly MediaUsageProviderRegistry $usageProviders;
    private readonly MediaDeletionReferenceGate $deletionReferenceGate;

    public function __construct(
        private readonly MediaRepositoryInterface $repository,
        private readonly MediaStorageInterface $storage,
        private readonly MediaImageProcessorInterface $processor,
        private readonly WebAdminMutationActorGate $mutationActorGate,
        private readonly SecurityKey $securityKey,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly UuidGeneratorInterface $uuidGenerator =
            new RandomUuidV4Generator(),
        private readonly int $quotaBytes = self::DEFAULT_QUOTA_BYTES,
        ?MediaUsageProviderRegistry $usageProviders = null
    ) {
        $this->usageProviders = $usageProviders
            ?? new MediaUsageProviderRegistry();
        $this->deletionReferenceGate = new MediaDeletionReferenceGate(
            $this->usageProviders
        );
        if ($quotaBytes < 12_582_912) {
            throw new MediaException('webadmin.media.quota_configuration_invalid');
        }
    }

    public function list(int $page, int $pageSize = 24): MediaAssetPage
    {
        $assets = $this->repository->listPage($page, $pageSize);
        $items = $assets->items();
        $publicIds = array_map(
            static fn (array $item): string => (string) ($item['public_id'] ?? ''),
            $items
        );
        $statuses = $this->usageProviders->statuses($publicIds);
        foreach ($items as &$item) {
            $publicId = (string) ($item['public_id'] ?? '');
            $item['usage_status'] = ($statuses[$publicId]
                ?? MediaUsageStatus::Unknown)->value;
        }
        unset($item);

        return new MediaAssetPage(
            $items,
            $assets->page(),
            $assets->hasNext()
        );
    }

    /** Private reusable picker catalog; authorization remains at HTTP edge. */
    public function picker(MediaPickerQuery $query): MediaPickerPage
    {
        if (
            !$this->repository
                instanceof MediaPickerCatalogRepositoryInterface
        ) {
            throw new MediaException(
                'webadmin.media.picker_catalog_unavailable'
            );
        }

        return $this->repository->pickerPage($query);
    }

    public function file(string $publicId, int $width): ?MediaFilePayload
    {
        $variant = $this->repository->findVariant($publicId, $width);

        return $variant === null ? null : $this->storage->readVerified($variant);
    }

    public function fileMetadata(
        string $publicId,
        int $width
    ): ?MediaFileMetadata {
        $variant = $this->repository->findVariant($publicId, $width);

        return $variant === null
            ? null
            : $this->storage->probeVerified($variant);
    }

    public function upload(
        UploadedFile $upload,
        string $label,
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        ?string $clientIp,
        string $idempotencyKey = ''
    ): string {
        $label = $this->validLabel($label);
        $staging = $this->storage->createStagingDirectory();
        $publicId = null;
        $promoted = false;
        $stagingReleased = false;

        try {
            $processed = $this->processor->process($upload, $staging);
            $publicId = $this->uuidGenerator->generateV4();
            $now = $this->clock->now();
            $requestId = $idempotencyKey === ''
                ? $this->uuidGenerator->generateV4()
                : $this->validIdempotencyKey($idempotencyKey);

            $resolvedPublicId = $this->repository->transaction(function () use (
                $processed,
                $label,
                $sessionToken,
                $csrfToken,
                $clientIp,
                $staging,
                $publicId,
                $requestId,
                $now,
                &$promoted,
                &$stagingReleased
            ): string {
                $actor = $this->mutationActorGate->authorizeAll(
                    $sessionToken,
                    $csrfToken,
                    [self::VIEW_CAPABILITY, self::UPLOAD_CAPABILITY]
                );
                if ($actor === null) {
                    throw new MediaException('webadmin.media.upload_forbidden');
                }

                // One canonical row serializes every quota and rate-limit
                // decision. Besides preventing aggregate quota races, this
                // closes the absent-row race in the rate-limit UPSERT on
                // MySQL without introducing per-subject advisory locks.
                $this->repository->lockQuota();

                $existingPublicId = $this->repository
                    ->createdPublicIdForRequest(
                        $actor,
                        $requestId,
                        $label,
                        $processed->sourceSha256()
                    );
                if ($existingPublicId !== null) {
                    $this->storage->removeStaging($staging);
                    $stagingReleased = true;

                    return $existingPublicId;
                }

                $userHash = $this->securityKey->subjectHash(
                    'media.user',
                    $actor->userPublicId()
                );
                if (!$this->repository->consumeRateLimit(
                    'media.upload.user',
                    $userHash,
                    $now,
                    3600,
                    self::USER_UPLOADS_PER_HOUR
                )) {
                    throw new MediaException('webadmin.media.upload_rate_limited');
                }
                $ipHash = null;
                if ($clientIp !== null) {
                    $ipHash = $this->securityKey->subjectHash(
                        'media.ip',
                        $clientIp
                    );
                    if (!$this->repository->consumeRateLimit(
                        'media.upload.ip',
                        $ipHash,
                        $now,
                        3600,
                        self::IP_UPLOADS_PER_HOUR
                    )) {
                        throw new MediaException('webadmin.media.upload_rate_limited');
                    }
                }
                $used = $this->repository->totalVariantBytes();
                if ($processed->variantBytes() > $this->quotaBytes - $used) {
                    throw new MediaException('webadmin.media.storage_quota_exceeded');
                }

                $this->storage->promote($staging, $publicId);
                $promoted = true;
                $assetId = $this->repository->insertAsset(
                    $publicId,
                    $label,
                    $processed,
                    $actor->userId(),
                    $now
                );
                foreach ($processed->variants() as $variant) {
                    $this->repository->insertVariant(
                        $assetId,
                        $variant,
                        $this->storage->storageKey(
                            $publicId,
                            $variant->width()
                        ),
                        $now
                    );
                }
                $this->repository->auditCreated(
                    $actor,
                    $requestId,
                    $publicId,
                    $ipHash,
                    $now
                );

                return $publicId;
            });

            return $resolvedPublicId;
        } catch (MediaException $exception) {
            $this->compensate(
                $staging,
                $publicId,
                $promoted,
                $stagingReleased
            );
            throw $exception;
        } catch (Throwable) {
            $this->compensate(
                $staging,
                $publicId,
                $promoted,
                $stagingReleased
            );
            throw new MediaException('webadmin.media.upload_failed');
        }
    }

    public function delete(
        string $publicId,
        string $assetVersion,
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        ?string $clientIp,
        string $idempotencyKey
    ): string {
        $publicId = $this->validPublicId($publicId);
        $assetVersion = $this->validAssetVersion($assetVersion);
        $requestId = $this->validIdempotencyKey($idempotencyKey);
        $lease = null;

        try {
            $resolvedPublicId = $this->repository->transaction(function () use (
                $publicId,
                $assetVersion,
                $sessionToken,
                $csrfToken,
                $clientIp,
                $requestId,
                &$lease
            ): string {
                $actor = $this->mutationActorGate->authorizeAll(
                    $sessionToken,
                    $csrfToken,
                    [self::VIEW_CAPABILITY, self::DELETE_CAPABILITY]
                );
                if ($actor === null) {
                    throw new MediaException(
                        'webadmin.media.delete_forbidden'
                    );
                }

                // Uploads and quarantines share one global DB lock. The
                // storage lease then remains held until this transaction has
                // committed or its compensating restore has completed.
                $this->repository->lockQuota();
                $existing = $this->repository
                    ->quarantinedPublicIdForRequest(
                        $actor,
                        $requestId,
                        $publicId,
                        $assetVersion
                    );
                if ($existing !== null) {
                    return $existing;
                }

                $candidate = $this->repository
                    ->lockDeletionCandidate($publicId);
                if ($candidate === null) {
                    throw new MediaException(
                        'webadmin.media.delete_not_found'
                    );
                }
                if (!hash_equals(
                    $candidate->versionToken(),
                    $assetVersion
                )) {
                    throw new MediaException(
                        'webadmin.media.delete_stale'
                    );
                }
                $this->deletionReferenceGate->assertUnreferenced($publicId);

                $ipHash = $clientIp === null ? null
                    : $this->securityKey->subjectHash('media.ip', $clientIp);
                $lease = $this->storage->quarantine(
                    $candidate,
                    $requestId
                );
                $this->repository->recordQuarantine(
                    $actor,
                    $requestId,
                    $candidate,
                    $lease->manifest(),
                    $ipHash,
                    $this->clock->now()
                );

                return $publicId;
            });
            if ($lease instanceof MediaQuarantineLease) {
                $lease->release();
            }

            return $resolvedPublicId;
        } catch (MediaException $exception) {
            $this->restoreQuarantine($lease);
            throw $exception;
        } catch (Throwable) {
            $this->restoreQuarantine($lease);
            throw new MediaException('webadmin.media.delete_failed');
        }
    }

    /** @return list<string> */
    public function knownPublicIds(int $limit = 10_000): array
    {
        return $this->repository->publicIds($limit);
    }

    /**
     * Returns the bounded selector projection for one already-authorized use.
     *
     * Upload and catalog presentation remain owned by WebAdmin: feature
     * modules never need access to the media repository or storage internals.
     */
    public function catalogAsset(string $publicId): ?MediaCatalogAsset
    {
        if (!$this->repository instanceof MediaCatalogRepositoryInterface) {
            throw new MediaException('webadmin.media.catalog_unavailable');
        }

        $assets = $this->repository->catalogAssetsByPublicIds([$publicId]);
        if ($assets === []) {
            return null;
        }
        if (
            count($assets) !== 1
            || !$assets[0] instanceof MediaCatalogAsset
            || !hash_equals($publicId, $assets[0]->publicId())
        ) {
            throw new MediaException('webadmin.media.catalog_lookup_failed');
        }

        return $assets[0];
    }

    private function validLabel(string $label): string
    {
        $label = trim($label);
        $characters = preg_match_all('/./us', $label, $matches);
        if (
            $label === ''
            || $characters === false
            || $characters > 120
            || preg_match('/[\x00-\x1F\x7F<>]/u', $label) === 1
        ) {
            throw new MediaException('webadmin.media.label_invalid');
        }

        return $label;
    }

    private function validIdempotencyKey(string $value): string
    {
        if (preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $value
        ) !== 1) {
            throw new MediaException('webadmin.media.idempotency_invalid');
        }

        return $value;
    }

    private function validPublicId(string $value): string
    {
        if (preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $value
        ) !== 1) {
            throw new MediaException('webadmin.media.public_id_invalid');
        }

        return $value;
    }

    private function validAssetVersion(string $value): string
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $value) !== 1) {
            throw new MediaException(
                'webadmin.media.delete_version_invalid'
            );
        }

        return $value;
    }

    private function restoreQuarantine(
        ?MediaQuarantineLease $lease
    ): void {
        if (!$lease instanceof MediaQuarantineLease) {
            return;
        }
        try {
            $lease->restore();
        } catch (Throwable) {
            throw new MediaException(
                'webadmin.media.rollback_cleanup_failed'
            );
        }
    }

    private function compensate(
        string $staging,
        ?string $publicId,
        bool $promoted,
        bool $stagingReleased = false
    ): void {
        try {
            if ($promoted && $publicId !== null) {
                $this->storage->removeAsset($publicId);
            } elseif (!$stagingReleased) {
                $this->storage->removeStaging($staging);
            }
        } catch (Throwable) {
            throw new MediaException('webadmin.media.rollback_cleanup_failed');
        }
    }
}
