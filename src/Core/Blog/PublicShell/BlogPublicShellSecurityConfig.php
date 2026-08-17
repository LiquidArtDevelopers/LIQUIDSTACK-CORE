<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicShell;

use RuntimeException;
use Throwable;

/** Project-owned public Blog security seam shared by index and articles. */
final class BlogPublicShellSecurityConfig
{
    public const PROJECT_FILE = 'App/config/modules/blog-public.php';

    private function __construct(
        private readonly BlogPublicShellSecurityPolicyInterface $securityPolicy,
        private readonly bool $configured
    ) {
    }

    public static function defaults(
        ?BlogPublicShellSecurityPolicyInterface $securityPolicy = null
    ): self {
        return new self(
            $securityPolicy ?? new BlogPublicShellDefaultSecurityPolicy(),
            false
        );
    }

    public static function fromProject(
        string $projectRoot,
        ?BlogPublicShellSecurityPolicyInterface $defaultSecurityPolicy = null
    ): self {
        $defaults = self::defaults($defaultSecurityPolicy);
        $root = rtrim($projectRoot, '/\\');
        if ($root === '' || !is_dir($root)) {
            throw new RuntimeException(
                'Blog public-shell project root is unavailable.'
            );
        }
        $path = $root . '/' . self::PROJECT_FILE;
        if (!file_exists($path) && !is_link($path)) {
            return $defaults;
        }
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new RuntimeException(
                'Blog public-shell security config is unavailable.'
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
                'Blog public-shell security config could not be loaded.'
            );
        }
        if ($output !== '') {
            throw new RuntimeException(
                'Blog public-shell security config emitted output.'
            );
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException(
                'Blog public-shell security config must return an object-like array.'
            );
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, [
                'security_policy',
                'security_sources',
            ], true)) {
                throw new RuntimeException(
                    'Blog public-shell security config contains an unknown key.'
                );
            }
        }

        $security = $value['security_policy']
            ?? $defaults->securityPolicy();
        $securitySources = $value['security_sources'] ?? null;
        if (
            array_key_exists('security_policy', $value)
            && array_key_exists('security_sources', $value)
        ) {
            throw new RuntimeException(
                'Blog public-shell security options conflict.'
            );
        }
        if (!$security instanceof BlogPublicShellSecurityPolicyInterface) {
            throw new RuntimeException(
                'Blog public-shell security policy is invalid.'
            );
        }
        if ($securitySources !== null) {
            if (
                !is_array($securitySources)
                || ($securitySources !== [] && array_is_list($securitySources))
                || !$security instanceof BlogPublicShellDefaultSecurityPolicy
            ) {
                throw new RuntimeException(
                    'Blog public-shell security sources are invalid.'
                );
            }
            try {
                $security = $security->withAdditionalSources($securitySources);
            } catch (Throwable) {
                throw new RuntimeException(
                    'Blog public-shell security sources are invalid.'
                );
            }
        }

        return new self($security, true);
    }

    public function securityPolicy(): BlogPublicShellSecurityPolicyInterface
    {
        return $this->securityPolicy;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }
}
