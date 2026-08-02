<?php

declare(strict_types=1);

namespace App\Core\Modules\Diagnostics;

final class ProjectAssetInspector
{
    /**
     * @param list<string> $requiredAssets
     * @return array{
     *     ready: bool,
     *     required: list<string>,
     *     missing: list<string>,
     *     invalid: list<string>
     * }
     */
    public function inspect(string $projectRoot, array $requiredAssets): array
    {
        $required = [];
        $missing = [];
        $invalid = [];

        foreach ($requiredAssets as $asset) {
            if (!is_string($asset) || !$this->isSafeRelativePath($asset)) {
                $invalid[] = is_string($asset) ? $asset : '[invalid-type]';
                continue;
            }

            $normalized = str_replace('\\', '/', $asset);
            $required[] = $normalized;
            if (!$this->existsInsideProject($projectRoot, $normalized)) {
                $missing[] = $normalized;
            }
        }

        return [
            'ready' => $missing === [] && $invalid === [],
            'required' => array_values(array_unique($required)),
            'missing' => array_values(array_unique($missing)),
            'invalid' => array_values(array_unique($invalid)),
        ];
    }

    private function isSafeRelativePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        $segments = explode('/', $normalized);

        return $normalized !== ''
            && !str_starts_with($normalized, '/')
            && preg_match('/\A[A-Za-z]:\//', $normalized) !== 1
            && preg_match('/[\x00-\x1F\x7F:]/', $normalized) !== 1
            && !in_array('', $segments, true)
            && !in_array('.', $segments, true)
            && !in_array('..', $segments, true);
    }

    private function existsInsideProject(
        string $projectRoot,
        string $asset
    ): bool {
        $root = realpath($projectRoot);
        if ($root === false) {
            return false;
        }

        $path = $root . DIRECTORY_SEPARATOR . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $asset
        );
        $real = realpath($path);
        if ($real === false || (!is_file($real) && !is_dir($real))) {
            return false;
        }

        $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $realNormalized = str_replace('\\', '/', $real);
        $candidate = $realNormalized . (is_dir($real) ? '/' : '');

        if (DIRECTORY_SEPARATOR === '\\') {
            $rootPrefix = strtolower($rootPrefix);
            $candidate = strtolower($candidate);
        }

        return str_starts_with($candidate, $rootPrefix);
    }
}
