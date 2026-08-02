<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use App\Core\Environment\ProjectRuntimeProfile;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use Throwable;

/** Project-owned private filesystem boundary for generated WebAdmin media. */
final class PrivateMediaStorage implements MediaStorageInterface
{
    public const ROOT_ENV = 'LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT';
    public const INITIALIZATION_MARKER = '.liquidstack-webadmin-media';
    private const INITIALIZATION_LOCK = '.liquidstack-webadmin-media.lock';
    private const GIT_IGNORE_FILE = '.gitignore';
    private const GIT_IGNORE_CONTENT = "*\n";
    private const INITIALIZATION_MARKER_CONTENT =
        "liquidstack-webadmin-media-storage:v1\n";
    private const UUID_PATTERN =
        '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    private readonly string $projectRoot;
    private readonly string $root;

    /** @param array<string, mixed> $environment */
    public static function forProject(
        string $projectRoot,
        array $environment
    ): self {
        $configured = $environment[self::ROOT_ENV] ?? null;
        if ($configured !== null && !is_string($configured)) {
            throw new MediaException('webadmin.media.storage_configuration_invalid');
        }
        $configured = is_string($configured) ? trim($configured) : '';
        if ($configured === '') {
            if (!self::isLocalDevelopment($environment)) {
                throw new MediaException('webadmin.media.storage_configuration_missing');
            }
            $configured = rtrim($projectRoot, '\\/')
                . DIRECTORY_SEPARATOR . 'storage'
                . DIRECTORY_SEPARATOR . 'liquidstack'
                . DIRECTORY_SEPARATOR . 'webadmin'
                . DIRECTORY_SEPARATOR . 'media';
        }

        $storage = new self($projectRoot, $configured);
        if (!self::isLocalDevelopment($environment)
            && $storage->isInsideProject()) {
            throw new MediaException('webadmin.media.storage_root_dangerous');
        }

        return $storage;
    }

    public function __construct(string $projectRoot, string $root)
    {
        $project = realpath($projectRoot);
        if ($project === false || !is_dir($project) || is_link($project)) {
            throw new MediaException('webadmin.media.project_root_invalid');
        }
        if (!$this->isAbsolutePath($root)) {
            throw new MediaException('webadmin.media.storage_root_not_absolute');
        }
        $root = $this->normalizeAbsolutePath($root);
        $this->projectRoot = $this->normalizeAbsolutePath($project);
        $this->root = $root;
        $this->assertRootIsSafe();
    }

    /** Initializes an absent/empty root without adopting existing contents. */
    public function initialize(): MediaStorageInitializationResult
    {
        if ((file_exists($this->root) || is_link($this->root))
            && !is_dir($this->root)) {
            throw new MediaException('webadmin.media.storage_root_invalid');
        }

        $this->ensureDirectory($this->root);
        $marker = $this->root . DIRECTORY_SEPARATOR
            . self::INITIALIZATION_MARKER;
        $lockPath = $this->root . DIRECTORY_SEPARATOR
            . self::INITIALIZATION_LOCK;
        if (!file_exists($marker) && !is_link($marker)) {
            // Do not create even our lock inside an unowned non-empty root.
            // An existing canonical lock means another initializer may be
            // between its validation and atomic marker publication; wait for
            // that lock and repeat the validation underneath it.
            if (file_exists($lockPath) || is_link($lockPath)) {
                $this->assertNoLinks($lockPath);
                if (!is_file($lockPath)) {
                    throw new MediaException(
                        'webadmin.media.storage_lock_failed'
                    );
                }
            } else {
                $this->assertUninitializedRootIsEmpty();
            }
        }
        $this->assertNoLinks($lockPath);
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false || is_link($lockPath) || !is_file($lockPath)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new MediaException('webadmin.media.storage_lock_failed');
        }
        @chmod($lockPath, 0600);
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new MediaException('webadmin.media.storage_lock_failed');
        }

        try {
            $this->assertRootIsSafe();
            if (file_exists($marker) || is_link($marker)) {
                $this->assertValidInitializationMarker($marker);
                $stagingRoot = $this->root . DIRECTORY_SEPARATOR . '.staging';
                $stagingExisted = is_dir($stagingRoot)
                    && !is_link($stagingRoot);
                $this->ensureDirectory($stagingRoot);
                $ignoreExisted = $this->ensureCanonicalGitIgnore();

                return $stagingExisted && $ignoreExisted
                    ? MediaStorageInitializationResult::alreadyInitialized()
                    : MediaStorageInitializationResult::initialized();
            }

            $this->assertUninitializedRootIsEmpty();
            $this->ensureDirectory(
                $this->root . DIRECTORY_SEPARATOR . '.staging'
            );
            $this->ensureCanonicalGitIgnore();
            $this->writeInitializationMarker($marker);

            return MediaStorageInitializationResult::initialized();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Marks an exact DB-backed v1.13 layout without moving or rewriting media.
     * The caller must hold the matching database quota lock for the full call.
     */
    public function adoptLegacy(
        LegacyMediaStorageManifest $manifest
    ): MediaStorageInitializationResult {
        $marker = $this->root . DIRECTORY_SEPARATOR
            . self::INITIALIZATION_MARKER;
        if (file_exists($marker) || is_link($marker)) {
            return $this->initialize();
        }

        if (!file_exists($this->root) && !is_link($this->root)) {
            if ($manifest->variants() === []) {
                throw new MediaException(
                    'webadmin.media.storage_adoption_not_required'
                );
            }
            throw new MediaException(
                'webadmin.media.storage_adoption_mismatch'
            );
        }
        if (!is_dir($this->root) || is_link($this->root)) {
            throw new MediaException(
                'webadmin.media.storage_adoption_mismatch'
            );
        }
        $this->assertRootIsSafe();
        if (!is_writable($this->root)) {
            throw new MediaException('webadmin.media.storage_not_writable');
        }

        $expected = $this->legacyExpectedVariants($manifest);
        $found = $this->scanLegacyLayout($expected);
        if ($expected === [] && $found === []) {
            throw new MediaException(
                'webadmin.media.storage_adoption_not_required'
            );
        }
        if (count($expected) !== count($found)
            || array_diff_key($expected, $found) !== []) {
            throw new MediaException(
                'webadmin.media.storage_adoption_mismatch'
            );
        }

        // No filesystem mutation happens before the complete DB <-> FS match.
        $lockPath = $this->root . DIRECTORY_SEPARATOR
            . self::INITIALIZATION_LOCK;
        $this->assertNoLinks($lockPath);
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false || is_link($lockPath) || !is_file($lockPath)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new MediaException('webadmin.media.storage_lock_failed');
        }
        @chmod($lockPath, 0600);
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new MediaException('webadmin.media.storage_lock_failed');
        }

        try {
            if (file_exists($marker) || is_link($marker)) {
                $this->assertInitialized();

                return MediaStorageInitializationResult::alreadyInitialized();
            }
            $this->ensureDirectory(
                $this->root . DIRECTORY_SEPARATOR . '.staging'
            );
            $this->ensureCanonicalGitIgnore();
            $this->writeInitializationMarker($marker);

            return MediaStorageInitializationResult::adoptedExisting();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function createStagingDirectory(): string
    {
        $this->assertInitialized();
        $stagingRoot = $this->root . DIRECTORY_SEPARATOR . '.staging';
        $this->assertNoLinks($stagingRoot);

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $directory = $stagingRoot . DIRECTORY_SEPARATOR
                . bin2hex(random_bytes(16));
            if (@mkdir($directory, 0700)) {
                return $directory;
            }
        }

        throw new MediaException('webadmin.media.storage_staging_failed');
    }

    public function promote(string $stagingDirectory, string $publicId): void
    {
        $this->assertInitialized();
        $this->assertUuid($publicId);
        $expectedParent = $this->root . DIRECTORY_SEPARATOR . '.staging';
        $realStaging = realpath($stagingDirectory);
        $realParent = realpath($expectedParent);
        if (
            $realStaging === false || $realParent === false
            || !is_dir($realStaging) || is_link($realStaging)
            || dirname($realStaging) !== $realParent
        ) {
            throw new MediaException('webadmin.media.storage_staging_invalid');
        }
        $this->assertNoLinks($realStaging);

        $shard = substr($publicId, 0, 2);
        $shardDirectory = $this->root . DIRECTORY_SEPARATOR . $shard;
        $this->ensureDirectory($shardDirectory);
        $target = $shardDirectory . DIRECTORY_SEPARATOR . $publicId;
        if (file_exists($target) || is_link($target) || !@rename($realStaging, $target)) {
            throw new MediaException('webadmin.media.storage_promote_failed');
        }
        $this->assertNoLinks($target);
    }

    public function removeStaging(string $stagingDirectory): void
    {
        $this->assertInitialized();
        $this->removeOwnedTree($stagingDirectory, true);
    }

    public function removeAsset(string $publicId): void
    {
        $this->assertInitialized();
        $this->assertUuid($publicId);
        $path = $this->root . DIRECTORY_SEPARATOR . substr($publicId, 0, 2)
            . DIRECTORY_SEPARATOR . $publicId;
        $this->removeOwnedTree($path, false);
    }

    public function storageKey(string $publicId, int $width): string
    {
        $this->assertUuid($publicId);
        if ($width < 1 || $width > 2560) {
            throw new MediaException('webadmin.media.storage_key_invalid');
        }

        return substr($publicId, 0, 2) . '/' . $publicId . '/'
            . $width . '.avif';
    }

    public function readVerified(MediaStoredVariant $variant): MediaFilePayload
    {
        $this->assertInitialized();
        $path = $this->verifiedPath($variant);
        $contents = file_get_contents($path);
        if (!is_string($contents)
            || strlen($contents) !== $variant->bytes()
            || !hash_equals(
                $variant->sha256(),
                hash('sha256', $contents)
            )) {
            throw new MediaException('webadmin.media.file_integrity_failed');
        }

        return new MediaFilePayload(
            $contents,
            $variant->width(),
            $variant->height()
        );
    }

    public function probeVerified(MediaStoredVariant $variant): MediaFileMetadata
    {
        $this->assertInitialized();
        $this->verifiedPath($variant);

        return new MediaFileMetadata(
            $variant->width(),
            $variant->height(),
            $variant->bytes()
        );
    }

    private function verifiedPath(MediaStoredVariant $variant): string
    {
        $key = $variant->storageKey();
        if (preg_match(
            '#\A([0-9a-f]{2})/(' . self::UUID_PATTERN
                . ')/([1-9][0-9]{0,3})\.avif\z#',
            $key,
            $matches
        ) !== 1 || $matches[1] !== substr($matches[2], 0, 2)
            || (int) $matches[3] !== $variant->width()) {
            throw new MediaException('webadmin.media.storage_key_invalid');
        }
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace(
            '/', DIRECTORY_SEPARATOR, $key
        );
        $this->assertNoLinks($path);
        if (!is_file($path) || !is_readable($path)) {
            throw new MediaException('webadmin.media.file_unavailable');
        }
        $size = filesize($path);
        if (!is_int($size) || $size !== $variant->bytes()) {
            throw new MediaException('webadmin.media.file_integrity_failed');
        }
        $sha256 = hash_file('sha256', $path);
        if (!is_string($sha256)
            || !hash_equals($variant->sha256(), $sha256)) {
            throw new MediaException('webadmin.media.file_integrity_failed');
        }

        return $path;
    }

    public function diagnostic(?array $knownPublicIds = null): array
    {
        try {
            if (!file_exists($this->root) && !is_link($this->root)) {
                return [
                    'ready' => false,
                    'status' => 'not_initialized',
                    'orphan_count' => null,
                    'orphan_scan_status' => 'not_checked',
                    'staging_count' => 0,
                ];
            }
            if (!is_dir($this->root) || is_link($this->root)) {
                throw new MediaException('webadmin.media.storage_root_invalid');
            }
            $this->assertRootIsSafe();
            $marker = $this->root . DIRECTORY_SEPARATOR
                . self::INITIALIZATION_MARKER;
            if (!file_exists($marker) && !is_link($marker)) {
                return [
                    'ready' => false,
                    'status' => 'not_initialized',
                    'orphan_count' => null,
                    'orphan_scan_status' => 'not_checked',
                    'staging_count' => 0,
                ];
            }
            $this->assertInitialized();
            $scanOrphans = $knownPublicIds !== null;
            $known = array_fill_keys(array_slice(array_values(array_filter(
                $knownPublicIds ?? [],
                static fn (mixed $id): bool => is_string($id)
                    && preg_match('/\A' . self::UUID_PATTERN . '\z/', $id) === 1
            )), 0, 10_000), true);
            $orphans = 0;
            $staging = 0;
            $entries = scandir($this->root);
            if (!is_array($entries)) {
                throw new MediaException('webadmin.media.storage_unreadable');
            }
            foreach (array_slice($entries, 0, 263) as $entry) {
                if ($entry === '.staging') {
                    $entryPath = $this->root . DIRECTORY_SEPARATOR . $entry;
                    $this->assertNoLinks($entryPath);
                    $children = @scandir($entryPath);
                    $staging = is_array($children)
                        ? max(0, min(10_000, count($children) - 2)) : 0;
                    continue;
                }
                if (preg_match('/\A[0-9a-f]{2}\z/', $entry) !== 1) {
                    continue;
                }
                $entryPath = $this->root . DIRECTORY_SEPARATOR . $entry;
                $this->assertNoLinks($entryPath);
                $children = @scandir($entryPath);
                if (!is_array($children)) {
                    continue;
                }
                if (!$scanOrphans) {
                    continue;
                }
                foreach (array_slice($children, 0, 10_002) as $child) {
                    if (preg_match('/\A' . self::UUID_PATTERN . '\z/', $child) === 1
                        && !isset($known[$child])) {
                        ++$orphans;
                    }
                }
            }

            return [
                'ready' => true,
                'status' => 'ready',
                'orphan_count' => $scanOrphans
                    ? min($orphans, 10_000) : null,
                'orphan_scan_status' => $scanOrphans
                    ? 'checked' : 'not_checked',
                'staging_count' => $staging,
            ];
        } catch (\Throwable) {
            return [
                'ready' => false,
                'status' => 'invalid',
                'orphan_count' => null,
                'orphan_scan_status' => 'not_checked',
                'staging_count' => 0,
            ];
        }
    }

    private function assertRootIsSafe(): void
    {
        $root = $this->normalizedForComparison($this->root);
        $project = $this->normalizedForComparison($this->projectRoot);
        $isFilesystemRoot = $root === '/'
            || preg_match('/\A[a-z]:\z/i', $root) === 1;
        if ($root === '' || $isFilesystemRoot || $root === $project) {
            throw new MediaException('webadmin.media.storage_root_dangerous');
        }
        if (str_starts_with($project . '/', $root . '/')) {
            throw new MediaException('webadmin.media.storage_root_dangerous');
        }
        foreach (['public', 'vendor', '.git'] as $forbidden) {
            $path = $this->normalizedForComparison(
                $this->projectRoot . DIRECTORY_SEPARATOR . $forbidden
            );
            if ($root === $path || str_starts_with($root . '/', $path . '/')) {
                throw new MediaException('webadmin.media.storage_root_dangerous');
            }
        }
        if ($this->isInsideProject()) {
            $localDefault = $this->normalizedForComparison(
                $this->projectRoot . DIRECTORY_SEPARATOR . 'storage'
                . DIRECTORY_SEPARATOR . 'liquidstack'
                . DIRECTORY_SEPARATOR . 'webadmin'
                . DIRECTORY_SEPARATOR . 'media'
            );
            if ($root !== $localDefault) {
                throw new MediaException('webadmin.media.storage_root_dangerous');
            }
        }
        $this->assertNoLinks($this->root);
    }

    private function assertUninitializedRootIsEmpty(): void
    {
        $entries = scandir($this->root);
        if (!is_array($entries) || count($entries) > 5) {
            throw new MediaException('webadmin.media.storage_layout_invalid');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if ($entry === self::INITIALIZATION_LOCK) {
                continue;
            }
            if ($entry === self::GIT_IGNORE_FILE) {
                $this->assertCanonicalGitIgnore(
                    $this->root . DIRECTORY_SEPARATOR . $entry
                );
                continue;
            }
            if ($entry === '.staging') {
                $path = $this->root . DIRECTORY_SEPARATOR . $entry;
                $this->assertNoLinks($path);
                $children = scandir($path);
                if (is_dir($path) && is_array($children)
                    && count($children) === 2) {
                    continue;
                }
                throw new MediaException(
                    'webadmin.media.storage_requires_explicit_adoption'
                );
            }
            if ($entry === self::INITIALIZATION_MARKER) {
                $this->assertValidInitializationMarker(
                    $this->root . DIRECTORY_SEPARATOR . $entry
                );
                continue;
            }

            throw new MediaException(
                'webadmin.media.storage_requires_explicit_adoption'
            );
        }
    }

    /**
     * @return array<string, MediaStoredVariant>
     */
    private function legacyExpectedVariants(
        LegacyMediaStorageManifest $manifest
    ): array {
        $expected = [];
        foreach ($manifest->variants() as $entry) {
            $variant = $entry->variant();
            $key = $this->storageKey(
                $entry->publicId(),
                $variant->width()
            );
            if (!hash_equals($key, $variant->storageKey())
                || isset($expected[$key])) {
                throw new MediaException(
                    'webadmin.media.storage_adoption_mismatch'
                );
            }
            $expected[$key] = $variant;
        }

        return $expected;
    }

    /**
     * @param array<string, MediaStoredVariant> $expected
     * @return array<string, true>
     */
    private function scanLegacyLayout(array $expected): array
    {
        $entries = @scandir($this->root);
        if (!is_array($entries)) {
            throw new MediaException(
                'webadmin.media.storage_adoption_mismatch'
            );
        }

        $found = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->root . DIRECTORY_SEPARATOR . $entry;
            if ($entry === '.staging') {
                $this->assertNoLinks($path);
                $children = @scandir($path);
                if (!is_dir($path) || !is_array($children)
                    || count($children) !== 2) {
                    throw new MediaException(
                        'webadmin.media.storage_adoption_mismatch'
                    );
                }
                continue;
            }
            if ($entry === self::GIT_IGNORE_FILE) {
                $this->assertCanonicalGitIgnore($path);
                continue;
            }
            if ($entry === self::INITIALIZATION_LOCK) {
                $this->assertNoLinks($path);
                if (!is_file($path) || filesize($path) !== 0) {
                    throw new MediaException(
                        'webadmin.media.storage_adoption_mismatch'
                    );
                }
                continue;
            }
            if (preg_match('/\A[0-9a-f]{2}\z/', $entry) !== 1) {
                throw new MediaException(
                    'webadmin.media.storage_adoption_mismatch'
                );
            }

            $this->scanLegacyShard($entry, $expected, $found);
        }

        return $found;
    }

    /**
     * @param array<string, MediaStoredVariant> $expected
     * @param array<string, true> $found
     */
    private function scanLegacyShard(
        string $shard,
        array $expected,
        array &$found
    ): void {
        $shardPath = $this->root . DIRECTORY_SEPARATOR . $shard;
        $this->assertNoLinks($shardPath);
        $assets = @scandir($shardPath);
        if (!is_dir($shardPath) || !is_array($assets)) {
            throw new MediaException(
                'webadmin.media.storage_adoption_mismatch'
            );
        }

        $assetCount = 0;
        foreach ($assets as $publicId) {
            if ($publicId === '.' || $publicId === '..') {
                continue;
            }
            if (preg_match('/\A' . self::UUID_PATTERN . '\z/', $publicId) !== 1
                || substr($publicId, 0, 2) !== $shard) {
                throw new MediaException(
                    'webadmin.media.storage_adoption_mismatch'
                );
            }
            ++$assetCount;
            $assetPath = $shardPath . DIRECTORY_SEPARATOR . $publicId;
            $this->assertNoLinks($assetPath);
            $files = @scandir($assetPath);
            if (!is_dir($assetPath) || !is_array($files)) {
                throw new MediaException(
                    'webadmin.media.storage_adoption_mismatch'
                );
            }

            $fileCount = 0;
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                if (preg_match('/\A[1-9][0-9]{0,3}\.avif\z/', $file) !== 1) {
                    throw new MediaException(
                        'webadmin.media.storage_adoption_mismatch'
                    );
                }
                ++$fileCount;
                $key = $shard . '/' . $publicId . '/' . $file;
                $variant = $expected[$key] ?? null;
                if (!$variant instanceof MediaStoredVariant
                    || isset($found[$key])) {
                    throw new MediaException(
                        'webadmin.media.storage_adoption_mismatch'
                    );
                }
                try {
                    $this->verifiedPath($variant);
                } catch (Throwable) {
                    throw new MediaException(
                        'webadmin.media.storage_adoption_mismatch'
                    );
                }
                $found[$key] = true;
            }
            if ($fileCount === 0) {
                throw new MediaException(
                    'webadmin.media.storage_adoption_mismatch'
                );
            }
        }
        if ($assetCount === 0) {
            throw new MediaException(
                'webadmin.media.storage_adoption_mismatch'
            );
        }
    }

    private function assertInitialized(): void
    {
        if (!is_dir($this->root) || is_link($this->root)
            || !is_writable($this->root)) {
            throw new MediaException('webadmin.media.storage_not_writable');
        }
        $this->assertRootIsSafe();
        $this->assertValidInitializationMarker(
            $this->root . DIRECTORY_SEPARATOR . self::INITIALIZATION_MARKER
        );
        $this->assertCanonicalGitIgnore(
            $this->root . DIRECTORY_SEPARATOR . self::GIT_IGNORE_FILE
        );
        $staging = $this->root . DIRECTORY_SEPARATOR . '.staging';
        $this->assertNoLinks($staging);
        if (!is_dir($staging) || !is_writable($staging)) {
            throw new MediaException('webadmin.media.storage_not_writable');
        }
    }

    private function assertValidInitializationMarker(string $marker): void
    {
        $this->assertNoLinks($marker);
        if (!is_file($marker) || !is_readable($marker)) {
            throw new MediaException('webadmin.media.storage_marker_invalid');
        }
        $contents = file_get_contents($marker);
        if (!is_string($contents)
            || !hash_equals(self::INITIALIZATION_MARKER_CONTENT, $contents)) {
            throw new MediaException('webadmin.media.storage_marker_invalid');
        }
    }

    private function writeInitializationMarker(string $marker): void
    {
        $temporary = $this->root . DIRECTORY_SEPARATOR
            . '.liquidstack-media-init-' . bin2hex(random_bytes(16));
        $handle = @fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new MediaException('webadmin.media.storage_marker_create_failed');
        }

        $complete = false;
        try {
            $remaining = self::INITIALIZATION_MARKER_CONTENT;
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);
                if (!is_int($written) || $written < 1) {
                    throw new MediaException(
                        'webadmin.media.storage_marker_write_failed'
                    );
                }
                $remaining = substr($remaining, $written);
            }
            if (!fflush($handle)) {
                throw new MediaException(
                    'webadmin.media.storage_marker_write_failed'
                );
            }
            fclose($handle);
            $handle = null;
            @chmod($temporary, 0600);
            $this->assertNoLinks($temporary);
            if (!@rename($temporary, $marker)) {
                throw new MediaException(
                    'webadmin.media.storage_marker_create_failed'
                );
            }
            $complete = true;
            $this->assertValidInitializationMarker($marker);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (!$complete && (file_exists($temporary) || is_link($temporary))
                && !@unlink($temporary)) {
                throw new MediaException(
                    'webadmin.media.storage_marker_cleanup_failed'
                );
            }
        }
    }

    /** Returns true when the canonical file already existed. */
    private function ensureCanonicalGitIgnore(): bool
    {
        $path = $this->root . DIRECTORY_SEPARATOR . self::GIT_IGNORE_FILE;
        if (file_exists($path) || is_link($path)) {
            $this->assertCanonicalGitIgnore($path);

            return true;
        }

        $temporary = $this->root . DIRECTORY_SEPARATOR
            . '.liquidstack-media-ignore-' . bin2hex(random_bytes(16));
        $handle = @fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new MediaException('webadmin.media.storage_ignore_create_failed');
        }

        $complete = false;
        try {
            $remaining = self::GIT_IGNORE_CONTENT;
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);
                if (!is_int($written) || $written < 1) {
                    throw new MediaException(
                        'webadmin.media.storage_ignore_write_failed'
                    );
                }
                $remaining = substr($remaining, $written);
            }
            if (!fflush($handle)) {
                throw new MediaException(
                    'webadmin.media.storage_ignore_write_failed'
                );
            }
            fclose($handle);
            $handle = null;
            @chmod($temporary, 0600);
            $this->assertNoLinks($temporary);
            if (!@rename($temporary, $path)) {
                throw new MediaException(
                    'webadmin.media.storage_ignore_create_failed'
                );
            }
            $complete = true;
            $this->assertCanonicalGitIgnore($path);

            return false;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (!$complete && (file_exists($temporary) || is_link($temporary))
                && !@unlink($temporary)) {
                throw new MediaException(
                    'webadmin.media.storage_ignore_cleanup_failed'
                );
            }
        }
    }

    private function assertCanonicalGitIgnore(string $path): void
    {
        $this->assertNoLinks($path);
        if (!is_file($path) || !is_readable($path)) {
            throw new MediaException('webadmin.media.storage_ignore_invalid');
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)
            || !hash_equals(self::GIT_IGNORE_CONTENT, $contents)) {
            throw new MediaException('webadmin.media.storage_ignore_invalid');
        }
    }

    private function isInsideProject(): bool
    {
        $root = $this->normalizedForComparison($this->root);
        $project = $this->normalizedForComparison($this->projectRoot);

        return str_starts_with($root . '/', $project . '/');
    }

    private function ensureDirectory(string $path): void
    {
        $this->assertNoLinks(dirname($path));
        if (!is_dir($path) && !@mkdir($path, 0700, true)) {
            throw new MediaException('webadmin.media.storage_create_failed');
        }
        if (!is_dir($path) || is_link($path) || !is_writable($path)) {
            throw new MediaException('webadmin.media.storage_not_writable');
        }
        $this->assertNoLinks($path);
    }

    private function assertNoLinks(string $path): void
    {
        $path = $this->normalizeAbsolutePath($path);
        $cursor = $path;
        while (!file_exists($cursor) && !is_link($cursor)) {
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }
        while (true) {
            clearstatcache(true, $cursor);
            if (is_link($cursor)) {
                throw new MediaException('webadmin.media.storage_symlink_rejected');
            }
            if (DIRECTORY_SEPARATOR === '\\' && file_exists($cursor)) {
                $stat = @lstat($cursor);
                if (is_dir($cursor) && is_array($stat)
                    && (($stat['mode'] & 0xF000) !== 0x4000)) {
                    throw new MediaException(
                        'webadmin.media.storage_symlink_rejected'
                    );
                }
                $resolved = realpath($cursor);
                if ($resolved === false
                    || $this->normalizedForComparison($resolved)
                        !== $this->normalizedForComparison($cursor)) {
                    throw new MediaException(
                        'webadmin.media.storage_symlink_rejected'
                    );
                }
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }
    }

    private function removeOwnedTree(string $path, bool $staging): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        // Inspect the unresolved path first. Calling realpath() before this
        // check would hide a symlinked ancestor and could turn cleanup into a
        // write outside the owned storage tree.
        $this->assertNoLinks($path);
        $real = realpath($path);
        $parent = realpath(dirname($path));
        $root = realpath($this->root);
        $expectedParent = $staging
            ? realpath($this->root . DIRECTORY_SEPARATOR . '.staging')
            : null;
        if (
            $real === false || $parent === false || $root === false
            || ($staging && ($expectedParent === false
                || $parent !== $expectedParent))
            || (!$staging && !$this->isCanonicalAssetDirectory($real, $root))
            || is_link($path)
            || !is_dir($real)
        ) {
            throw new MediaException('webadmin.media.storage_cleanup_rejected');
        }
        $this->assertNoLinks($real);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $real,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();
            if ($item->isLink()) {
                throw new MediaException('webadmin.media.storage_symlink_rejected');
            }
            $ok = $item->isDir() ? @rmdir($itemPath) : @unlink($itemPath);
            if (!$ok) {
                throw new MediaException('webadmin.media.storage_cleanup_failed');
            }
        }
        if (!@rmdir($real)) {
            throw new MediaException('webadmin.media.storage_cleanup_failed');
        }
    }

    private function isCanonicalAssetDirectory(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (!str_starts_with($path, $root . '/')) {
            return false;
        }
        $relative = substr($path, strlen($root) + 1);
        if (preg_match(
            '#\A([0-9a-f]{2})/(' . self::UUID_PATTERN . ')\z#',
            $relative,
            $matches
        ) !== 1) {
            return false;
        }

        return $matches[1] === substr($matches[2], 0, 2);
    }

    private function assertUuid(string $publicId): void
    {
        if (preg_match('/\A' . self::UUID_PATTERN . '\z/', $publicId) !== 1) {
            throw new MediaException('webadmin.media.public_id_invalid');
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
        }

        return str_starts_with($path, '/')
            && !str_starts_with($path, '//');
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path));
        $segments = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..' || str_contains($segment, "\0")) {
                throw new MediaException('webadmin.media.storage_path_invalid');
            }
            if (DIRECTORY_SEPARATOR === '\\'
                && (str_ends_with($segment, '.')
                    || str_ends_with($segment, ' ')
                    || (str_contains($segment, ':')
                        && preg_match('/\A[A-Za-z]:\z/', $segment) !== 1))) {
                throw new MediaException('webadmin.media.storage_path_invalid');
            }
            $segments[] = $segment;
        }
        if (preg_match('/\A[A-Za-z]:/', $path) === 1) {
            $drive = array_shift($segments);
            return $drive . DIRECTORY_SEPARATOR
                . implode(DIRECTORY_SEPARATOR, $segments);
        }

        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    }

    private function normalizedForComparison(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }

    /** @param array<string, mixed> $environment */
    private static function isLocalDevelopment(array $environment): bool
    {
        try {
            return ProjectRuntimeProfile::fromEnvironment(
                $environment
            )->isDevelopmentLoopbackHttp();
        } catch (Throwable) {
            return false;
        }
    }
}
