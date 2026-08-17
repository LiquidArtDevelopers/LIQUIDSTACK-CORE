<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use JsonException;

/** Durable recovery manifest shared by the DB record and private storage. */
final class MediaQuarantineManifest
{
    private const UUID_PATTERN =
        '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    private function __construct(
        private readonly string $publicId,
        private readonly string $requestId,
        private readonly string $assetVersion,
        private readonly string $originalStoragePrefix,
        private readonly string $quarantineStoragePrefix,
        private readonly string $manifestStorageKey,
        private readonly string $json,
        private readonly string $sha256
    ) {
    }

    /** @param array<string, mixed> $assetPayload */
    public static function create(
        string $publicId,
        string $requestId,
        string $assetVersion,
        string $originalStoragePrefix,
        string $quarantineStoragePrefix,
        string $manifestStorageKey,
        array $assetPayload
    ): self {
        $uuid = self::UUID_PATTERN;
        if (
            preg_match('/\A' . $uuid . '\z/', $publicId) !== 1
            || preg_match('/\A' . $uuid . '\z/', $requestId) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $assetVersion) !== 1
            || preg_match('#\A[0-9a-f]{2}/' . $uuid . '\z#',
                $originalStoragePrefix) !== 1
            || preg_match('#\A\.quarantine/assets/[0-9a-f]{2}/'
                . $uuid . '/' . $uuid . '\z#',
                $quarantineStoragePrefix) !== 1
            || preg_match('#\A\.quarantine/manifests/' . $uuid
                . '\.json\z#', $manifestStorageKey) !== 1
        ) {
            throw new MediaException('webadmin.media.quarantine_manifest_invalid');
        }

        $payload = [
            'schema' => 'liquidstack.webadmin.media-quarantine',
            'version' => 1,
            'state' => 'quarantined',
            'request_id' => $requestId,
            'asset_version' => $assetVersion,
            'original_storage_prefix' => $originalStoragePrefix,
            'quarantine_storage_prefix' => $quarantineStoragePrefix,
            'manifest_storage_key' => $manifestStorageKey,
        ] + $assetPayload;
        try {
            $json = json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException) {
            throw new MediaException('webadmin.media.quarantine_manifest_invalid');
        }
        if (strlen($json) < 2 || strlen($json) > 65_535) {
            throw new MediaException('webadmin.media.quarantine_manifest_invalid');
        }

        return new self(
            $publicId,
            $requestId,
            $assetVersion,
            $originalStoragePrefix,
            $quarantineStoragePrefix,
            $manifestStorageKey,
            $json,
            hash('sha256', $json)
        );
    }

    public function publicId(): string { return $this->publicId; }
    public function requestId(): string { return $this->requestId; }
    public function assetVersion(): string { return $this->assetVersion; }
    public function originalStoragePrefix(): string
    {
        return $this->originalStoragePrefix;
    }
    public function quarantineStoragePrefix(): string
    {
        return $this->quarantineStoragePrefix;
    }
    public function manifestStorageKey(): string
    {
        return $this->manifestStorageKey;
    }
    public function json(): string { return $this->json; }
    public function sha256(): string { return $this->sha256; }
}
