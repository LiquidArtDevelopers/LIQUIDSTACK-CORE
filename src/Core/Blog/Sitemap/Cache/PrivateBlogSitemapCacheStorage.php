<?php

declare(strict_types=1);

namespace App\Core\Blog\Sitemap\Cache;

use App\Core\Environment\ProjectRuntimeProfile;
use JsonException;
use Throwable;

/** Private, project-owned and explicitly initialized LKG sitemap storage. */
final class PrivateBlogSitemapCacheStorage
{
    public const ROOT_ENV = 'LIQUIDSTACK_BLOG_SITEMAP_CACHE_ROOT';
    public const MARKER = '.liquidstack-blog-sitemap-cache';
    private const LOCK = '.liquidstack-blog-sitemap-cache.lock';
    private const BLOCKED = '.blocked';
    private const CURRENT = 'current.json';
    private const GITIGNORE = '.gitignore';
    private const MAX_BYTES = 50 * 1024 * 1024;
    private const UUID =
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
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.storage_configuration_invalid'
            );
        }
        $configured = is_string($configured) ? trim($configured) : '';
        $local = self::isLocalDevelopment($environment);
        if ($configured === '') {
            if (!$local) {
                throw new BlogSitemapCacheException(
                    'blog.sitemap_cache.storage_configuration_missing'
                );
            }
            $configured = rtrim($projectRoot, '\\/')
                . DIRECTORY_SEPARATOR . 'storage'
                . DIRECTORY_SEPARATOR . 'liquidstack'
                . DIRECTORY_SEPARATOR . 'blog'
                . DIRECTORY_SEPARATOR . 'sitemap-cache';
        }

        $storage = new self($projectRoot, $configured);
        if (!$local && $storage->isInsideProject()) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.storage_root_dangerous'
            );
        }

        return $storage;
    }

    public function __construct(string $projectRoot, string $root)
    {
        $project = realpath($projectRoot);
        if ($project === false || !is_dir($project) || is_link($project)) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.project_root_invalid'
            );
        }
        if (!$this->isAbsolutePath($root)) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.storage_root_not_absolute'
            );
        }
        $this->projectRoot = $this->normalizeAbsolutePath($project);
        $this->root = $this->normalizeAbsolutePath($root);
        $this->assertRootIsSafe();
    }

    public function initialize(): BlogSitemapCacheInitializationResult
    {
        if ((file_exists($this->root) || is_link($this->root))
            && !is_dir($this->root)) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.storage_root_invalid'
            );
        }
        $this->ensureDirectory($this->root);
        $marker = $this->path(self::MARKER);
        $lockPath = $this->path(self::LOCK);
        if (!file_exists($marker) && !is_link($marker)) {
            $this->assertUninitializedRootIsEmpty();
        }

        $lease = $this->openLease(true, false);
        try {
            $this->assertRootIsSafe();
            if (file_exists($marker) || is_link($marker)) {
                $generation = $this->markerGeneration();
                $this->ensureDirectory($this->path('.staging'));
                $this->ensureDirectory($this->path('snapshots'));
                $this->cleanupStaging();
                $this->ensureGitIgnore();

                return new BlogSitemapCacheInitializationResult(
                    $generation,
                    false
                );
            }
            // The lock is the only allowed file created before ownership.
            $this->assertUninitializedRootIsEmpty();
            $generation = self::uuidV4();
            $this->ensureDirectory($this->path('.staging'));
            $this->ensureDirectory($this->path('snapshots'));
            $this->cleanupStaging();
            $this->ensureGitIgnore();
            $this->writeAtomicJson($marker, [
                'schema' => 1,
                'generation' => $generation,
            ]);

            return new BlogSitemapCacheInitializationResult($generation, true);
        } finally {
            $lease->release();
        }
    }

    public function markerGeneration(): string
    {
        $this->assertInitialized();
        $payload = $this->readJson($this->path(self::MARKER));
        if (($payload['schema'] ?? null) !== 1
            || !is_string($payload['generation'] ?? null)
            || !self::isUuid((string) $payload['generation'])
            || array_keys($payload) !== ['schema', 'generation']) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.storage_marker_invalid'
            );
        }

        return $payload['generation'];
    }

    public function acquireExclusive(int $timeoutMilliseconds = 2000): BlogSitemapCacheLease
    {
        $this->assertInitialized();

        return $this->openLease(true, true, $timeoutMilliseconds);
    }

    public function acquireShared(int $timeoutMilliseconds = 2000): BlogSitemapCacheLease
    {
        $this->assertInitialized();

        return $this->openLease(false, true, $timeoutMilliseconds);
    }

    public function block(
        BlogSitemapCacheLease $lease,
        string $generation,
        int $publicRevision
    ): void {
        $this->assertExclusive($lease);
        if (!hash_equals($this->markerGeneration(), $generation)
            || $publicRevision < 1) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.generation_mismatch'
            );
        }
        $blocked = $this->path(self::BLOCKED);
        if (file_exists($blocked) || is_link($blocked)) {
            $payload = $this->readJson($blocked);
            if (
                array_keys($payload) !== [
                    'schema', 'generation', 'revision', 'fence',
                ]
                || ($payload['schema'] ?? null) !== 1
                || !is_string($payload['generation'] ?? null)
                || !hash_equals($generation, $payload['generation'])
                || !is_int($payload['revision'] ?? null)
                || $payload['revision'] < 1
                || !is_string($payload['fence'] ?? null)
                || !self::isUuid($payload['fence'])
            ) {
                throw new BlogSitemapCacheException(
                    'blog.sitemap_cache.blocked_marker_invalid'
                );
            }

            // An older durable fence is intentionally stronger than the new
            // one. Replacing it would create an unlink/rename crash window in
            // which an obsolete snapshot could briefly become eligible.
            return;
        }
        $this->writeAtomicJson($blocked, [
            'schema' => 1,
            'generation' => $generation,
            'revision' => $publicRevision,
            'fence' => self::uuidV4(),
        ]);
    }

    public function promote(
        BlogSitemapCacheLease $lease,
        BlogSitemapCacheSnapshot $snapshot
    ): void {
        $this->assertExclusive($lease);
        $generation = $this->markerGeneration();
        if (!hash_equals($generation, $snapshot->generation())) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.generation_mismatch'
            );
        }
        $this->cleanupStaging();
        $snapshotId = self::uuidV4();
        $staging = $this->path('.staging/' . $snapshotId);
        $destination = $this->path('snapshots/' . $snapshotId);
        $this->ensureDirectory($staging);
        try {
            $this->writeNewFile($staging . DIRECTORY_SEPARATOR . 'sitemap.xml', $snapshot->xml());
            $this->writeNewJson(
                $staging . DIRECTORY_SEPARATOR . 'manifest.json',
                [
                    'schema' => 1,
                    'snapshot_id' => $snapshotId,
                    'generation' => $snapshot->generation(),
                    'public_revision' => $snapshot->publicRevision(),
                    'identity_sha256' => $snapshot->identityHash(),
                    'created_at' => $snapshot->createdAt(),
                    'expires_at' => $snapshot->expiresAt(),
                    'bytes' => strlen($snapshot->xml()),
                    'sha256' => hash('sha256', $snapshot->xml()),
                    'etag' => $snapshot->etag(),
                ]
            );
            if (!@rename($staging, $destination)) {
                throw new BlogSitemapCacheException(
                    'blog.sitemap_cache.snapshot_promote_failed'
                );
            }
            $this->writeAtomicJson($this->path(self::CURRENT), [
                'schema' => 1,
                'snapshot_id' => $snapshotId,
            ]);
            $blocked = $this->path(self::BLOCKED);
            if ((file_exists($blocked) || is_link($blocked))) {
                $this->assertNoLinks($blocked);
                if (!is_file($blocked) || !@unlink($blocked)) {
                    throw new BlogSitemapCacheException(
                        'blog.sitemap_cache.unblock_failed'
                    );
                }
            }
            $this->cleanupOtherSnapshots($snapshotId);
        } catch (Throwable $exception) {
            if ($exception instanceof BlogSitemapCacheException) {
                throw $exception;
            }
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.snapshot_promote_failed'
            );
        }
    }

    public function readValid(
        BlogSitemapCacheIdentity $identity,
        int $now,
        ?int $expectedRevision = null,
        ?string $expectedGeneration = null
    ): ?BlogSitemapCacheSnapshot {
        $lease = $this->acquireShared();
        try {
            if (file_exists($this->path(self::BLOCKED))
                || is_link($this->path(self::BLOCKED))) {
                return null;
            }
            $currentPath = $this->path(self::CURRENT);
            if (!file_exists($currentPath) && !is_link($currentPath)) {
                return null;
            }
            $current = $this->readJson($currentPath);
            $snapshotId = $current['snapshot_id'] ?? null;
            if (($current['schema'] ?? null) !== 1
                || !is_string($snapshotId)
                || !self::isUuid($snapshotId)
                || array_keys($current) !== ['schema', 'snapshot_id']) {
                throw new BlogSitemapCacheException(
                    'blog.sitemap_cache.pointer_invalid'
                );
            }
            $directory = $this->path('snapshots/' . $snapshotId);
            $this->assertNoLinks($directory);
            if (!is_dir($directory)) {
                throw new BlogSitemapCacheException(
                    'blog.sitemap_cache.snapshot_invalid'
                );
            }
            $manifest = $this->readJson(
                $directory . DIRECTORY_SEPARATOR . 'manifest.json'
            );
            $xmlPath = $directory . DIRECTORY_SEPARATOR . 'sitemap.xml';
            $this->assertNoLinks($xmlPath);
            $xml = is_file($xmlPath) ? file_get_contents($xmlPath) : false;
            if (!is_string($xml) || strlen($xml) > self::MAX_BYTES) {
                throw new BlogSitemapCacheException(
                    'blog.sitemap_cache.snapshot_invalid'
                );
            }
            $this->assertManifestKeys($manifest);
            $snapshot = new BlogSitemapCacheSnapshot(
                $xml,
                (string) $manifest['etag'],
                (int) $manifest['public_revision'],
                (string) $manifest['generation'],
                (string) $manifest['identity_sha256'],
                (int) $manifest['created_at'],
                (int) $manifest['expires_at']
            );
            if (!hash_equals($snapshotId, (string) $manifest['snapshot_id'])
                || (int) $manifest['bytes'] !== strlen($xml)
                || !hash_equals(
                    (string) $manifest['sha256'],
                    hash('sha256', $xml)
                )
                || !hash_equals($snapshot->etag(), '"' . hash('sha256', $xml) . '"')
                || !hash_equals($identity->hash(), $snapshot->identityHash())
                || !hash_equals($this->markerGeneration(), $snapshot->generation())
                || $snapshot->expiresAt() <= $now
                || ($expectedRevision !== null
                    && $snapshot->publicRevision() !== $expectedRevision)
                || ($expectedGeneration !== null
                    && !hash_equals($expectedGeneration, $snapshot->generation()))) {
                return null;
            }

            return $snapshot;
        } finally {
            $lease->release();
        }
    }

    /** @return array{ready: bool, status: string, blocked: bool, snapshot: string} */
    public function diagnostic(): array
    {
        try {
            $this->assertInitialized();
            $blocked = file_exists($this->path(self::BLOCKED))
                || is_link($this->path(self::BLOCKED));
            $snapshot = file_exists($this->path(self::CURRENT))
                ? 'present' : 'missing';

            return [
                'ready' => true,
                'status' => $blocked ? 'blocked' : 'ready',
                'blocked' => $blocked,
                'snapshot' => $snapshot,
            ];
        } catch (Throwable) {
            return [
                'ready' => false,
                'status' => 'invalid',
                'blocked' => false,
                'snapshot' => 'unknown',
            ];
        }
    }

    private function openLease(
        bool $exclusive,
        bool $requireMarker,
        int $timeoutMilliseconds = 2000
    ): BlogSitemapCacheLease {
        if ($timeoutMilliseconds < 0 || $timeoutMilliseconds > 10000) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.lock_timeout_invalid'
            );
        }
        if ($requireMarker) {
            $this->assertInitialized();
        }
        $lockPath = $this->path(self::LOCK);
        $this->assertNoLinks($lockPath);
        $handle = @fopen($lockPath, 'c+b');
        if ($handle === false || is_link($lockPath) || !is_file($lockPath)) {
            if (is_resource($handle)) { fclose($handle); }
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.lock_failed'
            );
        }
        @chmod($lockPath, 0600);
        $operation = ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB;
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);
        do {
            if (@flock($handle, $operation)) {
                return new BlogSitemapCacheLease($handle, $exclusive);
            }
            usleep(20_000);
        } while (microtime(true) <= $deadline);
        fclose($handle);
        throw new BlogSitemapCacheException(
            'blog.sitemap_cache.lock_timeout'
        );
    }

    private function assertExclusive(BlogSitemapCacheLease $lease): void
    {
        if (!$lease->isActive() || !$lease->isExclusive()) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.exclusive_lease_required'
            );
        }
        $this->assertInitialized();
    }

    private function assertInitialized(): void
    {
        if (!is_dir($this->root) || is_link($this->root)
            || !is_writable($this->root)) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.storage_not_writable'
            );
        }
        $this->assertRootIsSafe();
        $marker = $this->path(self::MARKER);
        $this->assertNoLinks($marker);
        if (!is_file($marker) || !is_readable($marker)) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.storage_marker_invalid'
            );
        }
        foreach (['.staging', 'snapshots'] as $directory) {
            $path = $this->path($directory);
            $this->assertNoLinks($path);
            if (!is_dir($path) || !is_writable($path)) {
                throw new BlogSitemapCacheException(
                    'blog.sitemap_cache.storage_layout_invalid'
                );
            }
        }
        $this->ensureGitIgnore(false);
    }

    private function assertRootIsSafe(): void
    {
        $root = $this->compare($this->root);
        $project = $this->compare($this->projectRoot);
        if ($root === '' || $root === '/'
            || preg_match('/\A[a-z]:\z/i', $root) === 1
            || $root === $project
            || str_starts_with($project . '/', $root . '/')) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.storage_root_dangerous'
            );
        }
        foreach (['public', 'vendor', '.git'] as $forbidden) {
            $path = $this->compare(
                $this->projectRoot . DIRECTORY_SEPARATOR . $forbidden
            );
            if ($root === $path || str_starts_with($root . '/', $path . '/')) {
                throw new BlogSitemapCacheException(
                    'blog.sitemap_cache.storage_root_dangerous'
                );
            }
        }
        if ($this->isInsideProject()) {
            $allowed = $this->compare(
                $this->projectRoot . DIRECTORY_SEPARATOR . 'storage'
                . DIRECTORY_SEPARATOR . 'liquidstack'
                . DIRECTORY_SEPARATOR . 'blog'
                . DIRECTORY_SEPARATOR . 'sitemap-cache'
            );
            if ($root !== $allowed) {
                throw new BlogSitemapCacheException(
                    'blog.sitemap_cache.storage_root_dangerous'
                );
            }
        }
        $this->assertNoLinks($this->root);
    }

    private function assertUninitializedRootIsEmpty(): void
    {
        $entries = scandir($this->root);
        if (!is_array($entries)) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.storage_layout_invalid'
            );
        }
        foreach ($entries as $entry) {
            if (in_array($entry, ['.', '..', self::LOCK], true)) {
                continue;
            }
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.storage_requires_empty_root'
            );
        }
    }

    private function ensureGitIgnore(bool $create = true): void
    {
        $path = $this->path(self::GITIGNORE);
        if (!file_exists($path) && !is_link($path)) {
            if (!$create) {
                throw new BlogSitemapCacheException(
                    'blog.sitemap_cache.storage_ignore_invalid'
                );
            }
            $this->writeNewFile($path, "*\n");
            return;
        }
        $this->assertNoLinks($path);
        $contents = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($contents) || !hash_equals("*\n", $contents)) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.storage_ignore_invalid'
            );
        }
    }

    /** @param array<string, mixed> $payload */
    private function writeAtomicJson(string $path, array $payload): void
    {
        $temporary = dirname($path) . DIRECTORY_SEPARATOR
            . '.tmp-' . bin2hex(random_bytes(16));
        $this->writeNewJson($temporary, $payload);
        $this->assertNoLinks($path);
        if ((file_exists($path) || is_link($path))
            && (!is_file($path) || !@unlink($path))) {
            @unlink($temporary);
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.atomic_replace_failed'
            );
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.atomic_replace_failed'
            );
        }
    }

    /** @param array<string, mixed> $payload */
    private function writeNewJson(string $path, array $payload): void
    {
        try {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n";
        } catch (JsonException) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.json_invalid'
            );
        }
        $this->writeNewFile($path, $json);
    }

    private function writeNewFile(string $path, string $contents): void
    {
        $this->assertNoLinks(dirname($path));
        $handle = @fopen($path, 'x+b');
        if ($handle === false || is_link($path)) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.write_failed'
            );
        }
        $complete = false;
        try {
            $remaining = $contents;
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);
                if (!is_int($written) || $written < 1) {
                    throw new BlogSitemapCacheException(
                        'blog.sitemap_cache.write_failed'
                    );
                }
                $remaining = substr($remaining, $written);
            }
            if (!fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) {
                throw new BlogSitemapCacheException(
                    'blog.sitemap_cache.write_failed'
                );
            }
            $complete = true;
        } finally {
            fclose($handle);
            @chmod($path, 0600);
            if (!$complete) { @unlink($path); }
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $this->assertNoLinks($path);
        $contents = is_file($path) && is_readable($path)
            ? file_get_contents($path) : false;
        if (!is_string($contents) || strlen($contents) > 16_384) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.json_invalid'
            );
        }
        try {
            $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.json_invalid'
            );
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.json_invalid'
            );
        }

        return $decoded;
    }

    /** @param array<string, mixed> $manifest */
    private function assertManifestKeys(array $manifest): void
    {
        $expected = [
            'schema', 'snapshot_id', 'generation', 'public_revision',
            'identity_sha256', 'created_at', 'expires_at', 'bytes', 'sha256',
            'etag',
        ];
        if (array_keys($manifest) !== $expected
            || ($manifest['schema'] ?? null) !== 1
            || !is_int($manifest['public_revision'] ?? null)
            || !is_int($manifest['created_at'] ?? null)
            || !is_int($manifest['expires_at'] ?? null)
            || !is_int($manifest['bytes'] ?? null)
            || !is_string($manifest['snapshot_id'] ?? null)
            || !is_string($manifest['generation'] ?? null)
            || !is_string($manifest['identity_sha256'] ?? null)
            || !is_string($manifest['sha256'] ?? null)
            || !is_string($manifest['etag'] ?? null)) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.snapshot_invalid'
            );
        }
    }

    private function cleanupOtherSnapshots(string $keep): void
    {
        $root = $this->path('snapshots');
        $entries = scandir($root);
        if (!is_array($entries)) { return; }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === $keep) {
                continue;
            }
            if (!self::isUuid($entry)) { continue; }
            $directory = $root . DIRECTORY_SEPARATOR . $entry;
            try {
                $this->assertNoLinks($directory);
                foreach (['manifest.json', 'sitemap.xml'] as $file) {
                    $path = $directory . DIRECTORY_SEPARATOR . $file;
                    $this->assertNoLinks($path);
                    if (is_file($path)) { @unlink($path); }
                }
                @rmdir($directory);
            } catch (Throwable) {
                // Orphan cleanup is bounded and never invalidates current.
            }
        }
    }

    /** Removes only the two known files from bounded UUID staging folders. */
    private function cleanupStaging(): void
    {
        $root = $this->path('.staging');
        $this->assertNoLinks($root);
        if (!is_dir($root)) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.staging_invalid'
            );
        }
        $entries = scandir($root);
        if (!is_array($entries) || count($entries) > 258) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.staging_invalid'
            );
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!self::isUuid($entry)) {
                throw new BlogSitemapCacheException(
                    'blog.sitemap_cache.staging_invalid'
                );
            }
            $directory = $root . DIRECTORY_SEPARATOR . $entry;
            $this->assertNoLinks($directory);
            if (!is_dir($directory)) {
                throw new BlogSitemapCacheException(
                    'blog.sitemap_cache.staging_invalid'
                );
            }
            $children = scandir($directory);
            if (!is_array($children) || count($children) > 4) {
                throw new BlogSitemapCacheException(
                    'blog.sitemap_cache.staging_invalid'
                );
            }
            foreach ($children as $child) {
                if ($child === '.' || $child === '..') {
                    continue;
                }
                if (!in_array(
                    $child,
                    ['sitemap.xml', 'manifest.json'],
                    true
                )) {
                    throw new BlogSitemapCacheException(
                        'blog.sitemap_cache.staging_invalid'
                    );
                }
                $path = $directory . DIRECTORY_SEPARATOR . $child;
                $this->assertNoLinks($path);
                if (!is_file($path) || !@unlink($path)) {
                    throw new BlogSitemapCacheException(
                        'blog.sitemap_cache.staging_cleanup_failed'
                    );
                }
            }
            if (!@rmdir($directory)) {
                throw new BlogSitemapCacheException(
                    'blog.sitemap_cache.staging_cleanup_failed'
                );
            }
        }
    }

    private function ensureDirectory(string $path): void
    {
        $this->assertNoLinks(dirname($path));
        if (!is_dir($path) && !@mkdir($path, 0700, true)) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.storage_create_failed'
            );
        }
        if (!is_dir($path) || is_link($path) || !is_writable($path)) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.storage_not_writable'
            );
        }
        $this->assertNoLinks($path);
    }

    private function assertNoLinks(string $path): void
    {
        $cursor = $this->normalizeAbsolutePath($path);
        while (!file_exists($cursor) && !is_link($cursor)) {
            $parent = dirname($cursor);
            if ($parent === $cursor) { break; }
            $cursor = $parent;
        }
        while (true) {
            clearstatcache(true, $cursor);
            if (is_link($cursor)) {
                throw new BlogSitemapCacheException(
                    'blog.sitemap_cache.storage_link_rejected'
                );
            }
            if (DIRECTORY_SEPARATOR === '\\' && file_exists($cursor)) {
                $resolved = realpath($cursor);
                if ($resolved === false
                    || $this->compare($resolved) !== $this->compare($cursor)) {
                    throw new BlogSitemapCacheException(
                        'blog.sitemap_cache.storage_link_rejected'
                    );
                }
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) { break; }
            $cursor = $parent;
        }
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path));
        $segments = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
            if ($segment === '' || $segment === '.') { continue; }
            if ($segment === '..' || str_contains($segment, "\0")) {
                throw new BlogSitemapCacheException(
                    'blog.sitemap_cache.storage_path_invalid'
                );
            }
            if (DIRECTORY_SEPARATOR === '\\'
                && (str_ends_with($segment, '.')
                    || str_ends_with($segment, ' ')
                    || (str_contains($segment, ':')
                        && preg_match('/\A[A-Za-z]:\z/', $segment) !== 1))) {
                throw new BlogSitemapCacheException(
                    'blog.sitemap_cache.storage_path_invalid'
                );
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

    private function isAbsolutePath(string $path): bool
    {
        return DIRECTORY_SEPARATOR === '\\'
            ? preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1
            : str_starts_with($path, '/') && !str_starts_with($path, '//');
    }

    private function isInsideProject(): bool
    {
        return str_starts_with(
            $this->compare($this->root) . '/',
            $this->compare($this->projectRoot) . '/'
        );
    }

    private function compare(string $path): string
    {
        $value = rtrim(str_replace('\\', '/', $path), '/');
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($value) : $value;
    }

    private function path(string $relative): string
    {
        return $this->root . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/\A' . self::UUID . '\z/D', $value) === 1;
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    /** @param array<string, mixed> $environment */
    private static function isLocalDevelopment(array $environment): bool
    {
        try {
            return ProjectRuntimeProfile::fromEnvironment($environment)
                ->isDevelopmentLoopbackHttp();
        } catch (Throwable) {
            return false;
        }
    }
}
