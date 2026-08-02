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
use Throwable;

final class MediaService
{
    public const VIEW_CAPABILITY = 'webadmin.media.view';
    public const UPLOAD_CAPABILITY = 'webadmin.media.upload';
    public const DEFAULT_QUOTA_BYTES = 2_147_483_648;
    public const USER_UPLOADS_PER_HOUR = 20;
    public const IP_UPLOADS_PER_HOUR = 40;

    public function __construct(
        private readonly MediaRepositoryInterface $repository,
        private readonly MediaStorageInterface $storage,
        private readonly MediaImageProcessorInterface $processor,
        private readonly WebAdminMutationActorGate $mutationActorGate,
        private readonly SecurityKey $securityKey,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly UuidGeneratorInterface $uuidGenerator =
            new RandomUuidV4Generator(),
        private readonly int $quotaBytes = self::DEFAULT_QUOTA_BYTES
    ) {
        if ($quotaBytes < 12_582_912) {
            throw new MediaException('webadmin.media.quota_configuration_invalid');
        }
    }

    public function list(int $page, int $pageSize = 24): MediaAssetPage
    {
        return $this->repository->listPage($page, $pageSize);
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
        ?string $clientIp
    ): string {
        $label = $this->validLabel($label);
        $staging = $this->storage->createStagingDirectory();
        $publicId = null;
        $promoted = false;

        try {
            $processed = $this->processor->process($upload, $staging);
            $publicId = $this->uuidGenerator->generateV4();
            $now = $this->clock->now();
            $requestId = $this->uuidGenerator->generateV4();

            $this->repository->transaction(function () use (
                $processed,
                $label,
                $sessionToken,
                $csrfToken,
                $clientIp,
                $staging,
                $publicId,
                $requestId,
                $now,
                &$promoted
            ): void {
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
            });

            return $publicId;
        } catch (MediaException $exception) {
            $this->compensate($staging, $publicId, $promoted);
            throw $exception;
        } catch (Throwable) {
            $this->compensate($staging, $publicId, $promoted);
            throw new MediaException('webadmin.media.upload_failed');
        }
    }

    /** @return list<string> */
    public function knownPublicIds(int $limit = 10_000): array
    {
        return $this->repository->publicIds($limit);
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

    private function compensate(
        string $staging,
        ?string $publicId,
        bool $promoted
    ): void {
        try {
            if ($promoted && $publicId !== null) {
                $this->storage->removeAsset($publicId);
            } else {
                $this->storage->removeStaging($staging);
            }
        } catch (Throwable) {
            throw new MediaException('webadmin.media.rollback_cleanup_failed');
        }
    }
}
