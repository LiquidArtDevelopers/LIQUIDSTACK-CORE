<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Media;

use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogRenderingException;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImageCandidate;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use PDOStatement;
use Throwable;

/** Resolves authenticated editor previews through WebAdmin's file route. */
final class PdoBlogEditorImageResolver implements BlogImageResolverInterface
{
    private const UUID =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    private readonly string $assets;
    private readonly string $variants;
    private readonly string $filePath;

    public function __construct(
        private readonly PDO $pdo,
        MigrationScope $webAdminScope,
        string $webAdminBasePath
    ) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $basePath = rtrim($webAdminBasePath, '/');
            if (
                !is_string($driver)
                || !in_array($driver, ['mysql', 'sqlite'], true)
                || $webAdminScope->moduleId() !== 'webadmin'
                || $pdo->getAttribute(PDO::ATTR_ERRMODE)
                    !== PDO::ERRMODE_EXCEPTION
                || ($driver === 'mysql' && !in_array(
                    $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
                    [false, 0, '0'],
                    true
                ))
                || !$this->safeBasePath($basePath)
            ) {
                throw new \RuntimeException('invalid resolver');
            }
            $this->assets = $webAdminScope->quotedTable(
                'media_assets',
                $driver
            );
            $this->variants = $webAdminScope->quotedTable(
                'media_variants',
                $driver
            );
            $this->filePath = $basePath . '/media/file';
        } catch (Throwable) {
            throw new BlogRenderingException(
                BlogRenderingException::MEDIA_UNAVAILABLE
            );
        }
    }

    public function resolve(string $mediaAssetPublicId): ?BlogResolvedImage
    {
        if (preg_match(self::UUID, $mediaAssetPublicId) !== 1) {
            return null;
        }

        try {
            $statement = $this->pdo->prepare(
                'SELECT v.width, v.height FROM ' . $this->variants . ' v '
                . 'JOIN ' . $this->assets . ' a ON a.id = v.asset_id '
                . 'WHERE a.public_id = :public_id AND v.mime = :mime '
                . 'ORDER BY v.width ASC LIMIT 9'
            );
            if (!$statement instanceof PDOStatement || !$statement->execute([
                'public_id' => $mediaAssetPublicId,
                'mime' => 'image/avif',
            ])) {
                throw new \RuntimeException('query failed');
            }
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows) > BlogResolvedImage::MAX_CANDIDATES) {
                throw new \RuntimeException('invalid candidates');
            }
            if ($rows === []) {
                return null;
            }

            $candidates = [];
            $largestWidth = 0;
            $largestHeight = 0;
            foreach ($rows as $row) {
                $width = $this->positiveInt($row['width'] ?? null);
                $height = $this->positiveInt($row['height'] ?? null);
                $candidates[] = new BlogResolvedImageCandidate(
                    $this->filePath . '?' . http_build_query([
                        'asset' => $mediaAssetPublicId,
                        'width' => (string) $width,
                    ], '', '&', PHP_QUERY_RFC3986),
                    $width
                );
                $largestWidth = $width;
                $largestHeight = $height;
            }

            return new BlogResolvedImage(
                $mediaAssetPublicId,
                $candidates,
                $largestWidth,
                $largestHeight
            );
        } catch (BlogRenderingException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogRenderingException(
                BlogRenderingException::MEDIA_UNAVAILABLE
            );
        }
    }

    private function positiveInt(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (
            is_string($value)
            && preg_match('/\A[1-9][0-9]*\z/', $value) === 1
            && (string) (int) $value === $value
            && (int) $value <= BlogResolvedImageCandidate::MAX_WIDTH
        ) {
            return (int) $value;
        }

        throw new \RuntimeException('invalid dimensions');
    }

    private function safeBasePath(string $path): bool
    {
        if (
            $path === ''
            || strlen($path) > 512
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '//')
            || str_contains($path, '\\')
            || str_contains($path, '?')
            || str_contains($path, '#')
            || preg_match('/[\x00-\x20\x7F]/', $path) === 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1
        ) {
            return false;
        }

        $decoded = $path;
        $stable = false;
        for ($pass = 0; $pass < 8; ++$pass) {
            if (
                preg_match('/%(?:2f|5c)/i', $decoded) === 1
                || preg_match('/%(?![0-9A-Fa-f]{2})/', $decoded) === 1
            ) {
                return false;
            }
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                $stable = true;
                break;
            }
            $decoded = $next;
        }
        if (
            !$stable
            || preg_match('//u', $decoded) !== 1
            || preg_match('/[\x00-\x20\x7F]/u', $decoded) === 1
            || str_contains($decoded, '//')
            || str_contains($decoded, '\\')
            || str_contains($decoded, '?')
            || str_contains($decoded, '#')
        ) {
            return false;
        }
        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
