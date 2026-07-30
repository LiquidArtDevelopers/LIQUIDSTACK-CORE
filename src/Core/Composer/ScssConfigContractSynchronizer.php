<?php

declare(strict_types=1);

namespace App\Core\Composer;

use Composer\IO\IOInterface;
use Symfony\Component\Filesystem\Filesystem;

final class ScssConfigContractSynchronizer
{
    private const OPEN_MARKER =
        '// <liquidstack-core:scss-color-contract>';
    private const CLOSE_MARKER =
        '// </liquidstack-core:scss-color-contract>';

    private Filesystem $filesystem;
    private bool $successful = false;

    public function __construct(
        private readonly IOInterface $io
    ) {
        $this->filesystem = new Filesystem();
    }

    public function sync(string $configPath, string $contractPath): int
    {
        $this->successful = false;

        if (!$this->isRegularFile($configPath)) {
            $this->warn(sprintf(
                'Se preservó el config SCSS porque no es un fichero '
                    . 'regular o es un enlace simbólico: %s',
                $configPath
            ));

            return 0;
        }

        if (!$this->isRegularFile($contractPath)) {
            $this->warn(sprintf(
                'Se preservó el config SCSS porque el contrato no es un '
                    . 'fichero regular o es un enlace simbólico: %s',
                $contractPath
            ));

            return 0;
        }

        $config = @file_get_contents($configPath);
        $contractRaw = @file_get_contents($contractPath);

        if ($config === false || $contractRaw === false) {
            $this->warn(
                'Se preservó el config SCSS porque no se pudo leer '
                    . 'el config o su contrato.'
            );

            return 0;
        }

        $variables = $this->decodeContract(
            $contractRaw,
            $contractPath
        );

        if ($variables === null) {
            return 0;
        }

        $declaredVariables = $this->declaredVariables($config);
        $missing = [];

        foreach ($variables as $variable) {
            if (isset($declaredVariables[$variable['name']])) {
                continue;
            }

            $value = $variable['value'];
            $legacyAlias = $variable['legacy_alias'];

            if (
                $legacyAlias !== null
                && isset($declaredVariables[$legacyAlias])
                && $this->canReferenceLegacyAlias(
                    $config,
                    $legacyAlias
                )
            ) {
                $value = '$' . $legacyAlias;
            }

            $missing[] = sprintf(
                '$%s: %s !default;',
                $variable['name'],
                $value
            );
        }

        if ($missing === []) {
            $this->successful = true;

            return 0;
        }

        $lineEnding = str_contains($config, "\r\n")
            ? "\r\n"
            : "\n";
        $updated = $this->insertVariables(
            $config,
            $missing,
            $lineEnding,
            $configPath
        );

        if ($updated === null) {
            return 0;
        }

        try {
            $this->filesystem->dumpFile($configPath, $updated);
        } catch (\Throwable $exception) {
            $this->warn(sprintf(
                'Se preservó el config SCSS porque no se pudo escribir: %s (%s)',
                $configPath,
                $exception->getMessage()
            ));

            return 0;
        }

        $this->successful = true;

        return count($missing);
    }

    public function wasSuccessful(): bool
    {
        return $this->successful;
    }

    private function isRegularFile(string $path): bool
    {
        return is_file($path) && !is_link($path);
    }

    /**
     * @return list<array{
     *     name: string,
     *     value: string,
     *     legacy_alias: string|null
     * }>|null
     */
    private function decodeContract(
        string $contractRaw,
        string $contractPath
    ): ?array {
        try {
            $contract = json_decode(
                $this->stripUtf8Bom($contractRaw),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            $this->warn(sprintf(
                'Se preservó el config SCSS porque el contrato JSON es '
                    . 'inválido (%s): %s',
                $contractPath,
                $exception->getMessage()
            ));

            return null;
        }

        if (
            !is_array($contract)
            || ($contract['schema'] ?? null) !== 2
            || !isset($contract['additive_variables'])
            || !is_array($contract['additive_variables'])
            || !array_is_list($contract['additive_variables'])
        ) {
            $this->warn(sprintf(
                'Se preservó el config SCSS porque el contrato no cumple '
                    . 'el schema 2 esperado: %s',
                $contractPath
            ));

            return null;
        }

        $variables = [];
        $seen = [];

        foreach ($contract['additive_variables'] as $index => $entry) {
            if (!is_array($entry)) {
                $this->warnInvalidEntry($contractPath, $index);

                return null;
            }

            $name = $entry['name'] ?? null;
            $value = $entry['value'] ?? null;
            $legacyAlias = $entry['legacy_alias'] ?? null;

            if (
                !is_string($name)
                || !$this->isVariableName($name)
                || isset($seen[$name])
                || !is_string($value)
                || !$this->isSingleScssValue($value)
                || (
                    $legacyAlias !== null
                    && (
                        !is_string($legacyAlias)
                        || !$this->isVariableName($legacyAlias)
                    )
                )
            ) {
                $this->warnInvalidEntry($contractPath, $index);

                return null;
            }

            $seen[$name] = true;
            $variables[] = [
                'name' => $name,
                'value' => $this->normalizeValue($value),
                'legacy_alias' => $legacyAlias,
            ];
        }

        return $variables;
    }

    /**
     * @return array<string, true>
     */
    private function declaredVariables(string $config): array
    {
        $configForMatching = $this->stripCommentsPreservingOffsets(
            $config
        );

        preg_match_all(
            '/^[ \t]*\$([A-Za-z_][A-Za-z0-9_-]*)[ \t]*:/m',
            $configForMatching,
            $matches
        );

        $declared = [];

        foreach ($matches[1] ?? [] as $name) {
            $declared[$name] = true;
        }

        return $declared;
    }

    private function canReferenceLegacyAlias(
        string $config,
        string $legacyAlias
    ): bool {
        $configForMatching = $this->stripCommentsPreservingOffsets(
            $config
        );
        $matched = preg_match(
            '/^[ \t]*\$'
                . preg_quote($legacyAlias, '/')
                . '[ \t]*:/m',
            $configForMatching,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        if ($matched !== 1) {
            return false;
        }

        $declarationOffset = $matches[0][1] ?? null;

        if (!is_int($declarationOffset)) {
            return false;
        }

        $closePosition = strpos($config, self::CLOSE_MARKER);

        return $closePosition === false
            || $declarationOffset < $closePosition;
    }

    /**
     * @param list<string> $missing
     */
    private function insertVariables(
        string $config,
        array $missing,
        string $lineEnding,
        string $configPath
    ): ?string {
        $openPosition = strpos($config, self::OPEN_MARKER);
        $closePosition = strpos($config, self::CLOSE_MARKER);

        if (
            ($openPosition === false) !== ($closePosition === false)
            || (
                $openPosition !== false
                && $closePosition !== false
                && $closePosition < $openPosition
            )
        ) {
            $this->warn(sprintf(
                'Se preservó el config SCSS porque el bloque gestionado '
                    . 'tiene marcadores incompletos o desordenados: %s',
                $configPath
            ));

            return null;
        }

        $lines = implode($lineEnding, $missing);

        if ($openPosition === false && $closePosition === false) {
            $separator = '';

            if ($config !== '' && !$this->endsWithLineEnding($config)) {
                $separator = $lineEnding;
            }

            if ($config !== '') {
                $separator .= $lineEnding;
            }

            return $config
                . $separator
                . self::OPEN_MARKER
                . $lineEnding
                . $lines
                . $lineEnding
                . self::CLOSE_MARKER
                . $lineEnding;
        }

        if ($closePosition === false) {
            return null;
        }

        $prefix = substr($config, 0, $closePosition);
        $separator = $prefix === '' || $this->endsWithLineEnding($prefix)
            ? ''
            : $lineEnding;

        return substr_replace(
            $config,
            $separator . $lines . $lineEnding,
            $closePosition,
            0
        );
    }

    private function isVariableName(string $name): bool
    {
        return preg_match(
            '/\A[A-Za-z_][A-Za-z0-9_-]*\z/',
            $name
        ) === 1;
    }

    private function isSingleScssValue(string $value): bool
    {
        $normalized = $this->normalizeValue($value);

        return $normalized !== ''
            && !str_contains($value, "\r")
            && !str_contains($value, "\n")
            && !str_contains($normalized, ';');
    }

    private function normalizeValue(string $value): string
    {
        $value = trim($value);
        $value = preg_replace(
            '/[ \t]+!default[ \t]*\z/i',
            '',
            $value
        ) ?? $value;

        return rtrim($value);
    }

    private function endsWithLineEnding(string $contents): bool
    {
        return str_ends_with($contents, "\n")
            || str_ends_with($contents, "\r");
    }

    private function stripUtf8Bom(string $contents): string
    {
        return str_starts_with($contents, "\xEF\xBB\xBF")
            ? substr($contents, 3)
            : $contents;
    }

    private function stripCommentsPreservingOffsets(
        string $contents
    ): string {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = '   ' . substr($contents, 3);
        }

        $replaceWithWhitespace = static function (array $match): string {
            return preg_replace(
                '/[^\r\n]/',
                ' ',
                $match[0]
            ) ?? $match[0];
        };

        $withoutBlocks = preg_replace_callback(
            '~/\*.*?\*/~s',
            $replaceWithWhitespace,
            $contents
        ) ?? $contents;

        return preg_replace_callback(
            '~//[^\r\n]*~',
            $replaceWithWhitespace,
            $withoutBlocks
        ) ?? $withoutBlocks;
    }

    private function warnInvalidEntry(
        string $contractPath,
        int $index
    ): void {
        $this->warn(sprintf(
            'Se preservó el config SCSS porque la variable %d del '
                . 'contrato es inválida: %s',
            $index,
            $contractPath
        ));
    }

    private function warn(string $message): void
    {
        $this->io->writeError('<warning>' . $message . '</warning>');
    }
}
