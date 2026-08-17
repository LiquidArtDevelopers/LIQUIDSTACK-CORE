<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicIndex;

use App\Core\Blog\PublicShell\BlogPublicShellDefaultSecurityPolicy;
use App\Core\Blog\PublicShell\BlogPublicShellSecurityConfig;
use App\Core\Blog\PublicShell\BlogPublicShellSecurityPolicyInterface;
use RuntimeException;
use Throwable;

/** Optional project-owned preview and shared security seam for the hook. */
final class BlogPublicIndexBootstrapOptions
{
    public const PROJECT_FILE = 'App/config/modules/blog-public-index.php';

    public function __construct(
        private readonly ?BlogPublicIndexPreviewSourceInterface $preview,
        private readonly BlogPublicShellSecurityPolicyInterface
            $securityPolicy
    ) {
    }

    public static function defaults(
        ?BlogPublicShellSecurityPolicyInterface $securityPolicy = null
    ): self {
        return new self(
            null,
            $securityPolicy ?? new BlogPublicIndexDefaultSecurityPolicy()
        );
    }

    public static function fromProject(
        string $projectRoot,
        ?BlogPublicShellSecurityPolicyInterface $defaultSecurityPolicy = null
    ): self {
        $root = rtrim($projectRoot, '/\\');
        if ($root === '' || !is_dir($root)) {
            throw new RuntimeException(
                'Blog public-index project root is unavailable.'
            );
        }
        $sharedSecurity = BlogPublicShellSecurityConfig::fromProject(
            $root,
            $defaultSecurityPolicy
                ?? new BlogPublicIndexDefaultSecurityPolicy()
        );
        $defaults = self::defaults($sharedSecurity->securityPolicy());
        $path = $root . '/' . self::PROJECT_FILE;
        if (!file_exists($path) && !is_link($path)) {
            return $defaults;
        }
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new RuntimeException(
                'Blog public-index options are unavailable.'
            );
        }

        $bufferLevel = ob_get_level();
        ob_start();
        try {
            $value = (static function (string $file): mixed {
                return require $file;
            })($path);
            $output = ob_get_clean();
        } catch (Throwable) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw new RuntimeException(
                'Blog public-index options could not be loaded.'
            );
        }
        if ($output !== '') {
            throw new RuntimeException(
                'Blog public-index options emitted output.'
            );
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException(
                'Blog public-index options must return an object-like array.'
            );
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, [
                'preview',
                'security_policy',
                'security_sources',
            ], true)) {
                throw new RuntimeException(
                    'Blog public-index options contain an unknown key.'
                );
            }
        }
        $preview = $value['preview'] ?? null;
        $security = $value['security_policy']
            ?? $defaults->securityPolicy();
        $securitySources = $value['security_sources'] ?? null;
        if (
            $sharedSecurity->isConfigured()
            && (
                array_key_exists('security_policy', $value)
                || array_key_exists('security_sources', $value)
            )
        ) {
            throw new RuntimeException(
                'Blog public-index security options conflict with the shared config.'
            );
        }
        if (
            array_key_exists('security_policy', $value)
            && array_key_exists('security_sources', $value)
        ) {
            throw new RuntimeException(
                'Blog public-index security options conflict.'
            );
        }
        if (
            $preview !== null
            && !$preview instanceof BlogPublicIndexPreviewSourceInterface
        ) {
            throw new RuntimeException(
                'Blog public-index preview source is invalid.'
            );
        }
        if (!$security instanceof BlogPublicShellSecurityPolicyInterface) {
            throw new RuntimeException(
                'Blog public-index security policy is invalid.'
            );
        }
        if ($securitySources !== null) {
            if (
                !is_array($securitySources)
                || ($securitySources !== [] && array_is_list($securitySources))
                || !$security instanceof BlogPublicShellDefaultSecurityPolicy
            ) {
                throw new RuntimeException(
                    'Blog public-index security sources are invalid.'
                );
            }
            try {
                $security = $security->withAdditionalSources(
                    $securitySources
                );
            } catch (Throwable) {
                throw new RuntimeException(
                    'Blog public-index security sources are invalid.'
                );
            }
        }

        return new self($preview, $security);
    }

    public function preview(): ?BlogPublicIndexPreviewSourceInterface
    {
        return $this->preview;
    }

    public function securityPolicy(): BlogPublicShellSecurityPolicyInterface
    {
        return $this->securityPolicy;
    }
}
