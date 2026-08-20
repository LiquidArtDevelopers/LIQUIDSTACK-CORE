<?php

declare(strict_types=1);

namespace App\Core\Composer;

use Composer\IO\IOInterface;
use Symfony\Component\Filesystem\Filesystem;

/** Safely adopts the dynamic Vite origin in a project-owned global head. */
final class DevelopmentViteHeadIntegrator
{
    public const TARGET = 'App/includes/_globalHead.php';

    private const LEGACY_CLIENT =
        'src="http://localhost:5173/@vite/client"';
    private const LEGACY_ENTRY =
        'src="http://localhost:5173/src/js/';
    private const DYNAMIC_CLIENT =
        'src="<?= liquidstack_dev_vite_origin() ?>/@vite/client"';
    private const DYNAMIC_ENTRY =
        'src="<?= liquidstack_dev_vite_origin() ?>/src/js/';

    public static function integrate(
        string $projectRoot,
        Filesystem $filesystem,
        IOInterface $io
    ): bool {
        $path = self::safeTargetPath($projectRoot);
        if ($path === null) {
            $io->writeError(sprintf(
                '<warning>Preserved development frontend integration because '
                . '%s is missing, linked, outside the project, or not a '
                . 'regular file.</warning>',
                rtrim($projectRoot, '/\\') . '/' . self::TARGET
            ));
            return false;
        }

        $contents = @file_get_contents($path);
        if (!is_string($contents) || strlen($contents) > 1048576) {
            $io->writeError(sprintf(
                '<warning>Preserved development frontend integration because %s could not be read safely.</warning>',
                $path
            ));
            return false;
        }

        if (self::isIntegratedContents($contents)) {
            $io->write(
                '<info>Dynamic Vite origin already integrated in '
                . self::TARGET
                . '</info>'
            );
            return true;
        }

        if (!self::isCanonicalLegacyContents($contents)) {
            $io->writeError(sprintf(
                '<warning>Preserved custom %s; replace the two canonical '
                . 'localhost:5173 development asset origins with '
                . 'liquidstack_dev_vite_origin() before adopting the managed '
                . 'development launcher.</warning>',
                $path
            ));
            return false;
        }

        $updated = str_replace(
            [self::LEGACY_CLIENT, self::LEGACY_ENTRY],
            [self::DYNAMIC_CLIENT, self::DYNAMIC_ENTRY],
            $contents,
            $replacements
        );
        if (!is_string($updated) || $replacements !== 2) {
            return false;
        }
        if (!self::hasValidPhpSyntax($updated)) {
            $io->writeError(sprintf(
                '<warning>Preserved custom %s because the dynamic Vite '
                . 'integration would not be valid PHP.</warning>',
                $path
            ));
            return false;
        }

        $verifiedPath = self::safeTargetPath($projectRoot);
        $currentContents = $verifiedPath === null
            ? false
            : @file_get_contents($verifiedPath);
        if ($verifiedPath === null
            || $verifiedPath !== $path
            || !is_string($currentContents)
            || !hash_equals($contents, $currentContents)
        ) {
            $io->writeError(sprintf(
                '<warning>Preserved development frontend integration because '
                . '%s changed while it was being inspected.</warning>',
                $path
            ));
            return false;
        }

        try {
            $filesystem->dumpFile($path, $updated);
        } catch (\Throwable $exception) {
            $io->writeError(sprintf(
                '<warning>Could not integrate the dynamic Vite origin in %s: %s</warning>',
                $path,
                $exception->getMessage()
            ));
            return false;
        }

        $io->write(sprintf(
            '<info>Integrated the dynamic Vite origin in %s</info>',
            self::TARGET
        ));

        return true;
    }

    public static function isIntegrated(string $projectRoot): bool
    {
        $path = self::safeTargetPath($projectRoot);
        if ($path === null) {
            return false;
        }

        $contents = @file_get_contents($path);

        return is_string($contents)
            && strlen($contents) <= 1048576
            && self::isIntegratedContents($contents);
    }

    private static function isCanonicalLegacyContents(string $contents): bool
    {
        return substr_count($contents, self::LEGACY_CLIENT) === 1
            && substr_count($contents, self::LEGACY_ENTRY) === 1
            && substr_count($contents, 'liquidstack_dev_vite_origin') === 0
            && substr_count($contents, 'http://localhost:5173') === 2
            && self::isCanonicalScriptLine(
                $contents,
                self::LEGACY_CLIENT,
                true
            )
            && self::isCanonicalScriptLine(
                $contents,
                self::LEGACY_ENTRY,
                true
            )
            && self::hasValidPhpSyntax($contents);
    }

    private static function isIntegratedContents(string $contents): bool
    {
        return substr_count($contents, self::DYNAMIC_CLIENT) === 1
            && substr_count($contents, self::DYNAMIC_ENTRY) === 1
            && substr_count($contents, 'liquidstack_dev_vite_origin') === 2
            && substr_count($contents, 'http://localhost:5173') === 0
            && self::isCanonicalScriptLine(
                $contents,
                self::DYNAMIC_CLIENT,
                false
            )
            && self::isCanonicalScriptLine(
                $contents,
                self::DYNAMIC_ENTRY,
                false
            )
            && self::hasValidPhpSyntax($contents);
    }

    private static function safeTargetPath(string $projectRoot): ?string
    {
        if ($projectRoot === '' || is_link($projectRoot)) {
            return null;
        }

        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) {
            return null;
        }

        $cursor = $root;
        foreach (['App', 'includes', '_globalHead.php'] as $component) {
            $cursor .= DIRECTORY_SEPARATOR . $component;
            if (is_link($cursor)) {
                return null;
            }

            $resolved = realpath($cursor);
            if ($resolved === false
                || !self::pathIsWithinRoot($root, $resolved)
            ) {
                return null;
            }
        }

        return is_file($cursor) ? $cursor : null;
    }

    private static function pathIsWithinRoot(
        string $root,
        string $path
    ): bool {
        $normalize = static function (string $value): string {
            $value = str_replace('\\', '/', rtrim($value, '/\\'));

            return DIRECTORY_SEPARATOR === '\\' ? strtolower($value) : $value;
        };
        $root = $normalize($root);
        $path = $normalize($path);

        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function isCanonicalScriptLine(
        string $contents,
        string $needle,
        bool $mustBeInlineHtml
    ): bool {
        $offset = strpos($contents, $needle);
        if ($offset === false || self::isInsideHtmlComment($contents, $offset)) {
            return false;
        }

        $lineStart = strrpos(substr($contents, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineEnd = strpos($contents, "\n", $offset);
        $lineEnd = $lineEnd === false ? strlen($contents) : $lineEnd;
        $line = rtrim(
            substr($contents, $lineStart, $lineEnd - $lineStart),
            "\r"
        );

        if (preg_match(
            '~^[\\t ]*<script\\b[^\\r\\n]*'
                . preg_quote($needle, '~')
                . '[^\\r\\n]*></script>[\\t ]*$~D',
            $line
        ) !== 1
            || substr_count(strtolower($line), '<script') !== 1
            || substr_count(strtolower($line), '</script>') !== 1
        ) {
            return false;
        }

        $needleOffset = strpos($line, $needle);
        $startTagEnd = self::scriptStartTagEnd($line);
        if ($needleOffset === false
            || $needleOffset === 0
            || !in_array($line[$needleOffset - 1], [" ", "\t"], true)
            || $startTagEnd === null
            || $needleOffset + strlen($needle) > $startTagEnd
        ) {
            return false;
        }

        return !$mustBeInlineHtml
            || self::rangeBelongsToInlineHtml(
                $contents,
                $offset,
                strlen($needle)
            );
    }

    private static function scriptStartTagEnd(string $line): ?int
    {
        $script = stripos($line, '<script');
        if ($script === false) {
            return null;
        }

        $length = strlen($line);
        $quote = null;
        for ($cursor = $script + 7; $cursor < $length; $cursor++) {
            if (substr($line, $cursor, 2) === '<?') {
                $phpEnd = strpos($line, '?>', $cursor + 2);
                if ($phpEnd === false) {
                    return null;
                }
                $cursor = $phpEnd + 1;
                continue;
            }

            $character = $line[$cursor];
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
                continue;
            }
            if ($character === '>') {
                return $cursor;
            }
        }

        return null;
    }

    private static function isInsideHtmlComment(
        string $contents,
        int $offset
    ): bool {
        $open = strrpos(substr($contents, 0, $offset + 1), '<!--');
        if ($open === false) {
            return false;
        }

        $close = strrpos(substr($contents, 0, $offset + 1), '-->');

        return $close === false || $close < $open;
    }

    private static function rangeBelongsToInlineHtml(
        string $contents,
        int $offset,
        int $length
    ): bool {
        $cursor = 0;
        try {
            $tokens = token_get_all($contents, TOKEN_PARSE);
        } catch (\ParseError) {
            return false;
        }

        foreach ($tokens as $token) {
            $value = is_array($token) ? $token[1] : $token;
            $end = $cursor + strlen($value);
            if ($offset >= $cursor && $offset + $length <= $end) {
                return is_array($token) && $token[0] === T_INLINE_HTML;
            }
            $cursor = $end;
        }

        return false;
    }

    private static function hasValidPhpSyntax(string $contents): bool
    {
        try {
            token_get_all($contents, TOKEN_PARSE);
        } catch (\ParseError) {
            return false;
        }

        return true;
    }
}
