<?php

declare(strict_types=1);

namespace App\Core\Modules\Diagnostics;

/** Resolves diagnostic paths only; Composer targets retain their public/ prefix. */
final class ProjectPublicRootResolver
{
    public function relativeDirectory(string $projectRoot): ?string
    {
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) {
            return null;
        }

        // An existing public path always wins, even when invalid. Never hide a
        // broken public deployment by inspecting a different www directory.
        $public = $root . DIRECTORY_SEPARATOR . 'public';
        $directory = file_exists($public) || is_link($public) ? 'public' : 'www';
        $candidate = $root . DIRECTORY_SEPARATOR . $directory;
        $real = realpath($candidate);
        if (!is_dir($candidate) || is_link($candidate) || $real === false) {
            return null;
        }

        $candidate = str_replace('\\', '/', $candidate);
        $real = str_replace('\\', '/', $real);
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidate = strtolower($candidate);
            $real = strtolower($real);
        }

        // Also rejects junctions or other redirections not reported as links.
        return $real === $candidate ? $directory : null;
    }
}
