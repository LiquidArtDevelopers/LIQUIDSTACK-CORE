<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/** Project-owned private filesystem boundary for generated WebAdmin media. */
final class PrivateMediaStorage implements MediaStorageInterface
{
    public const ROOT_ENV = 'LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT';
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

    public function createStagingDirectory(): string
    {
        $this->ensureDirectory($this->root);
        $stagingRoot = $this->root . DIRECTORY_SEPARATOR . '.staging';
        $this->ensureDirectory($stagingRoot);
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
        $this->removeOwnedTree($stagingDirectory, true);
    }

    public function removeAsset(string $publicId): void
    {
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
            if (!is_dir($this->root)) {
                return [
                    'ready' => false,
                    'status' => 'not_initialized',
                    'orphan_count' => null,
                    'orphan_scan_status' => 'not_checked',
                    'staging_count' => 0,
                ];
            }
            $this->assertRootIsSafe();
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
            foreach (array_slice($entries, 0, 260) as $entry) {
                if ($entry === '.staging') {
                    $children = @scandir($this->root . DIRECTORY_SEPARATOR . $entry);
                    $staging = is_array($children)
                        ? max(0, min(10_000, count($children) - 2)) : 0;
                    continue;
                }
                if (preg_match('/\A[0-9a-f]{2}\z/', $entry) !== 1) {
                    continue;
                }
                $children = @scandir($this->root . DIRECTORY_SEPARATOR . $entry);
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
            if (is_link($cursor)) {
                throw new MediaException('webadmin.media.storage_symlink_rejected');
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
        return str_starts_with($path, '/')
            || (
                strlen($path) >= 3
                && ctype_alpha($path[0])
                && $path[1] === ':'
                && in_array($path[2], ['/', '\\'], true)
            );
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
        if (($environment['DEV_MODE'] ?? null) !== '1'
            && ($environment['DEV_MODE'] ?? null) !== 1) {
            return false;
        }
        $root = $environment['RAIZ'] ?? null;
        if (!is_string($root)) {
            return false;
        }
        $host = parse_url($root, PHP_URL_HOST);

        return is_string($host)
            && in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    }
}
