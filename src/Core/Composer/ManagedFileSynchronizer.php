<?php

declare(strict_types=1);

namespace App\Core\Composer;

use Composer\IO\IOInterface;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class ManagedFileSynchronizer
{
    private const HISTORY_SCHEMA = 1;
    private const STATE_SCHEMA = 1;
    private const STATE_RELATIVE_PATH = '.liquidstack/core/managed-files.json';

    private Filesystem $filesystem;

    /**
     * @var array<string, array{
     *     source: string,
     *     target: string,
     *     source_id: string,
     *     target_id: string,
     *     policy: string,
     *     group: string|null,
     *     track_state: bool
     * }>
     */
    private array $queue = [];

    /** @var array<string, string> */
    private array $queuedTargets = [];

    /**
     * @var array<string, list<string>>
     */
    private array $history = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $stateFiles = [];

    private bool $stateWritable = true;

    /**
     * @var array<string, int>
     */
    private array $stats = [
        'added' => 0,
        'updated' => 0,
        'merged' => 0,
        'preserved' => 0,
        'protected' => 0,
        'unchanged' => 0,
        'errors' => 0,
    ];

    /**
     * @var array<string, string>
     */
    private array $preserved = [];

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $packageRoot,
        private readonly IOInterface $io,
        ?string $historyPath = null,
        ?string $statePath = null
    ) {
        $this->filesystem = new Filesystem();
        $this->loadHistory(
            $historyPath
                ?? $this->packageRoot
                    . '/manifests/managed-file-history.json'
        );
        $this->loadState(
            $statePath
                ?? $this->projectRoot
                    . '/'
                    . self::STATE_RELATIVE_PATH
        );
    }

    public function queueFile(
        string $source,
        string $target,
        string $sourceId,
        string $targetId,
        ?string $policy = null,
        ?string $group = null,
        bool $trackState = true
    ): void {
        $sourceId = ManagedFileRegistry::normalizePath($sourceId);
        $targetId = ManagedFileRegistry::normalizePath($targetId);
        $policy ??= ManagedFileRegistry::policyForSource($sourceId);

        if ($policy === ManagedFileRegistry::POLICY_IGNORE) {
            return;
        }

        $queueKey = $targetId . "\0" . $sourceId;
        $targetKey = Path::canonicalize(str_replace('\\', '/', $target));
        if (PHP_OS_FAMILY === 'Windows') {
            $targetKey = strtolower($targetKey);
        }

        if (
            isset($this->queuedTargets[$targetKey])
            && $this->queuedTargets[$targetKey] !== $queueKey
        ) {
            throw new \RuntimeException(sprintf(
                'Colisión de sincronización: más de un origen apunta a %s.',
                $target
            ));
        }
        $this->queuedTargets[$targetKey] = $queueKey;

        $this->queue[$queueKey] = [
            'source' => $source,
            'target' => $target,
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'policy' => $policy,
            'group' => $group
                ?? ManagedFileRegistry::groupForSource($sourceId),
            'track_state' => $trackState,
        ];
    }

    public function queueDirectory(
        string $source,
        string $target,
        string $sourceIdPrefix,
        string $targetIdPrefix,
        bool $trackState = true,
        ?string $policy = null,
        ?string $group = null
    ): void {
        if (!is_dir($source)) {
            $this->io->writeError(sprintf(
                '<warning>Directorio de CORE no encontrado: %s</warning>',
                $source
            ));
            ++$this->stats['errors'];
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $source,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }

            $relativePath = str_replace(
                '\\',
                '/',
                $iterator->getSubPathName()
            );

            $this->queueFile(
                $item->getPathname(),
                rtrim($target, '/\\')
                    . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $relativePath),
                rtrim($sourceIdPrefix, '/')
                    . '/'
                    . $relativePath,
                rtrim($targetIdPrefix, '/')
                    . '/'
                    . $relativePath,
                $policy,
                $group,
                $trackState
            );
        }
    }

    public function apply(): void
    {
        if ($this->queue === []) {
            $this->writeSummary();
            return;
        }

        ksort($this->queue, SORT_STRING);

        /**
         * @var array<string, array{
         *     action: string,
         *     reason: string,
         *     source_fingerprints: list<string>
         * }>
         */
        $plans = [];

        /**
         * @var array<string, true>
         */
        $blockedGroups = [];

        foreach ($this->queue as $queueKey => $item) {
            $plan = $this->planItem($item);
            $plans[$queueKey] = $plan;

            if (
                $item['policy'] === ManagedFileRegistry::POLICY_MANAGED
                && $item['group'] !== null
                && in_array(
                    $plan['action'],
                    ['preserve', 'error'],
                    true
                )
            ) {
                $blockedGroups[$item['group']] = true;
            }
        }

        foreach ($this->queue as $queueKey => $item) {
            $plan = $plans[$queueKey];

            if (
                $item['policy'] === ManagedFileRegistry::POLICY_MANAGED
                && $item['group'] !== null
                && isset($blockedGroups[$item['group']])
                && $plan['action'] !== 'error'
            ) {
                $plan['action'] = 'preserve_group';
                $plan['reason'] = sprintf(
                    'el grupo %s contiene personalizaciones locales',
                    $item['group']
                );
            }

            $this->applyPlan($item, $plan);
        }

        $this->writeState();
        $this->writeSummary();
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * @param array{
     *     source: string,
     *     target: string,
     *     source_id: string,
     *     target_id: string,
     *     policy: string,
     *     group: string|null,
     *     track_state: bool
     * } $item
     *
     * @return array{
     *     action: string,
     *     reason: string,
     *     source_fingerprints: list<string>
     * }
     */
    private function planItem(array $item): array
    {
        if (!is_file($item['source']) || is_link($item['source'])) {
            return [
                'action' => 'error',
                'reason' => 'el origen no es un fichero regular',
                'source_fingerprints' => [],
            ];
        }

        try {
            $sourceFingerprints = ManagedFileRegistry::fingerprintFile(
                $item['source']
            );
        } catch (\Throwable $exception) {
            return [
                'action' => 'error',
                'reason' => $exception->getMessage(),
                'source_fingerprints' => [],
            ];
        }

        if (!file_exists($item['target']) && !is_link($item['target'])) {
            return [
                'action' => 'add',
                'reason' => 'el fichero no existe en el proyecto',
                'source_fingerprints' => $sourceFingerprints,
            ];
        }

        if (!is_file($item['target']) || is_link($item['target'])) {
            return [
                'action' => 'preserve',
                'reason' => 'el destino no es un fichero regular',
                'source_fingerprints' => $sourceFingerprints,
            ];
        }

        if (
            $item['policy']
                === ManagedFileRegistry::POLICY_INSTALL_IF_MISSING
        ) {
            return [
                'action' => 'protect',
                'reason' => 'es una semilla personalizable',
                'source_fingerprints' => $sourceFingerprints,
            ];
        }

        if (
            $item['policy']
                === ManagedFileRegistry::POLICY_MERGE_JSON_ADDITIVE
        ) {
            return [
                'action' => 'merge_json',
                'reason' => 'catálogo aditivo',
                'source_fingerprints' => $sourceFingerprints,
            ];
        }

        try {
            $targetFingerprints = ManagedFileRegistry::fingerprintFile(
                $item['target']
            );
        } catch (\Throwable $exception) {
            return [
                'action' => 'error',
                'reason' => $exception->getMessage(),
                'source_fingerprints' => $sourceFingerprints,
            ];
        }

        if (
            $this->fingerprintsIntersect(
                $targetFingerprints,
                $sourceFingerprints
            )
        ) {
            return [
                'action' => 'unchanged',
                'reason' => 'ya coincide con CORE',
                'source_fingerprints' => $sourceFingerprints,
            ];
        }

        $stateEntry = $this->stateFiles[$item['target_id']] ?? null;

        if (
            is_array($stateEntry)
            && ($stateEntry['source'] ?? null) === $item['source_id']
        ) {
            $installedFingerprints = $this->readFingerprintList(
                $stateEntry['fingerprints'] ?? []
            );

            if (
                $this->fingerprintsIntersect(
                    $targetFingerprints,
                    $installedFingerprints
                )
            ) {
                return [
                    'action' => 'update',
                    'reason' => 'coincide con la última copia instalada por CORE',
                    'source_fingerprints' => $sourceFingerprints,
                ];
            }

            return [
                'action' => 'preserve',
                'reason' => 'se modificó después de instalarlo CORE',
                'source_fingerprints' => $sourceFingerprints,
            ];
        }

        $historicalFingerprints = $this->history[
            $item['source_id']
        ] ?? [];

        if (
            $this->fingerprintsIntersect(
                $targetFingerprints,
                $historicalFingerprints
            )
        ) {
            return [
                'action' => 'update',
                'reason' => 'coincide con una versión histórica de CORE',
                'source_fingerprints' => $sourceFingerprints,
            ];
        }

        return [
            'action' => 'preserve',
            'reason' => 'no coincide con ninguna versión gestionada conocida',
            'source_fingerprints' => $sourceFingerprints,
        ];
    }

    /**
     * @param array{
     *     source: string,
     *     target: string,
     *     source_id: string,
     *     target_id: string,
     *     policy: string,
     *     group: string|null,
     *     track_state: bool
     * } $item
     * @param array{
     *     action: string,
     *     reason: string,
     *     source_fingerprints: list<string>
     * } $plan
     */
    private function applyPlan(array $item, array $plan): void
    {
        try {
            switch ($plan['action']) {
                case 'add':
                    $this->copyFile($item['source'], $item['target']);
                    ++$this->stats['added'];
                    $this->recordState($item, $plan['source_fingerprints']);
                    return;

                case 'update':
                    $this->copyFile($item['source'], $item['target']);
                    ++$this->stats['updated'];
                    $this->recordState($item, $plan['source_fingerprints']);
                    return;

                case 'merge_json':
                    $changed = $this->mergeJsonAdditively(
                        $item['source'],
                        $item['target']
                    );

                    if ($changed) {
                        ++$this->stats['merged'];
                    } else {
                        ++$this->stats['unchanged'];
                    }
                    return;

                case 'unchanged':
                    ++$this->stats['unchanged'];
                    $this->recordState($item, $plan['source_fingerprints']);
                    return;

                case 'protect':
                    ++$this->stats['protected'];
                    return;

                case 'preserve':
                case 'preserve_group':
                    ++$this->stats['preserved'];
                    $this->preserved[$item['target_id']] = $plan['reason'];
                    return;

                default:
                    ++$this->stats['errors'];
                    $this->io->writeError(sprintf(
                        '<error>No se pudo sincronizar %s: %s</error>',
                        $item['target_id'],
                        $plan['reason']
                    ));
            }
        } catch (\Throwable $exception) {
            ++$this->stats['errors'];
            $this->io->writeError(sprintf(
                '<error>No se pudo sincronizar %s: %s</error>',
                $item['target_id'],
                $exception->getMessage()
            ));
        }
    }

    private function copyFile(string $source, string $target): void
    {
        if ($this->samePath($source, $target)) {
            return;
        }

        $this->filesystem->mkdir(dirname($target), 0775);
        $this->filesystem->copy($source, $target, true);
    }

    private function mergeJsonAdditively(
        string $source,
        string $target
    ): bool {
        $sourceRaw = @file_get_contents($source);
        $targetRaw = @file_get_contents($target);

        if ($sourceRaw === false || $targetRaw === false) {
            throw new \RuntimeException(
                'no se pudo leer uno de los catálogos JSON'
            );
        }

        try {
            $sourceData = json_decode(
                $this->stripUtf8Bom($sourceRaw),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $targetData = json_decode(
                $this->stripUtf8Bom($targetRaw),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'se preservó un catálogo JSON inválido: '
                    . $exception->getMessage(),
                0,
                $exception
            );
        }

        if (!is_array($sourceData) || !is_array($targetData)) {
            throw new \RuntimeException(
                'se preservó un catálogo cuyo nivel raíz no es un objeto'
            );
        }

        [$merged, $changed] = $this->mergeMissingJsonValues(
            $targetData,
            $sourceData
        );

        if (!$changed) {
            return false;
        }

        $patched = $this->patchJsonAdditively(
            $targetRaw,
            $targetData,
            $merged,
        );

        $this->filesystem->dumpFile($target, $patched);

        return true;
    }

    /**
     * @param array<mixed> $target
     * @param array<mixed> $source
     *
     * @return array{0: array<mixed>, 1: bool}
     */
    private function mergeMissingJsonValues(
        array $target,
        array $source
    ): array {
        $changed = false;

        foreach ($source as $key => $sourceValue) {
            if (!array_key_exists($key, $target)) {
                $target[$key] = $sourceValue;
                $changed = true;
                continue;
            }

            $targetValue = $target[$key];

            if (
                is_array($targetValue)
                && is_array($sourceValue)
                && !array_is_list($targetValue)
                && !array_is_list($sourceValue)
            ) {
                [$mergedValue, $nestedChanged] =
                    $this->mergeMissingJsonValues(
                        $targetValue,
                        $sourceValue
                    );

                if ($nestedChanged) {
                    $target[$key] = $mergedValue;
                    $changed = true;
                }
            }
        }

        return [$target, $changed];
    }

    /**
     * Conserva el formato del catálogo destino: solo reemplaza los objetos
     * concretos que reciben campos nuevos y agrega nuevas claves al final.
     *
     * @param array<mixed> $targetData
     * @param array<mixed> $mergedData
     */
    private function patchJsonAdditively(
        string $targetRaw,
        array $targetData,
        array $mergedData
    ): string {
        $bom = str_starts_with($targetRaw, "\xEF\xBB\xBF")
            ? "\xEF\xBB\xBF"
            : '';
        $json = $this->stripUtf8Bom($targetRaw);
        $lineEnding = str_contains($json, "\r\n")
            ? "\r\n"
            : "\n";
        $spans = $this->topLevelJsonValueSpans($json);
        $replacements = [];
        $missing = [];

        foreach ($mergedData as $key => $value) {
            $key = (string) $key;

            if (!array_key_exists($key, $targetData)) {
                $missing[$key] = $value;
                continue;
            }

            if ($targetData[$key] === $value) {
                continue;
            }

            if (!isset($spans[$key])) {
                throw new \RuntimeException(sprintf(
                    'no se pudo localizar la clave JSON existente %s',
                    $key
                ));
            }

            $span = $spans[$key];
            $replacements[] = [
                'start' => $span['value_start'],
                'end' => $span['value_end'],
                'value' => $value,
            ];
        }

        usort(
            $replacements,
            static fn (array $left, array $right): int =>
                $right['start'] <=> $left['start']
        );

        foreach ($replacements as $replacement) {
            $indent = $this->lineIndentAt(
                $json,
                $replacement['start']
            );
            $encodedValue = $this->encodeJsonValue(
                $replacement['value'],
                $indent,
                $lineEnding
            );
            $json = substr_replace(
                $json,
                $encodedValue,
                $replacement['start'],
                $replacement['end'] - $replacement['start']
            );
        }

        if ($missing !== []) {
            $json = $this->appendTopLevelJsonValues(
                $json,
                $missing,
                $lineEnding
            );
        }

        try {
            $verified = json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'la ampliación aditiva produjo JSON inválido: '
                    . $exception->getMessage(),
                0,
                $exception
            );
        }

        if ($verified !== $mergedData) {
            throw new \RuntimeException(
                'la ampliación aditiva no conservó el contrato JSON esperado'
            );
        }

        return $bom . $json;
    }

    /**
     * @param array<string, mixed> $missing
     */
    private function appendTopLevelJsonValues(
        string $json,
        array $missing,
        string $lineEnding
    ): string {
        $spans = $this->topLevelJsonValueSpans($json);
        $firstSpan = reset($spans);
        $indent = is_array($firstSpan)
            ? $this->lineIndentAt($json, $firstSpan['key_start'])
            : '    ';
        $entries = [];

        foreach ($missing as $key => $value) {
            $encodedKey = json_encode(
                (string) $key,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            );
            $entries[] = $indent
                . $encodedKey
                . ': '
                . $this->encodeJsonValue(
                    $value,
                    $indent,
                    $lineEnding
                );
        }

        $trimmed = rtrim($json);
        $closingOffset = strrpos($trimmed, '}');

        if ($closingOffset === false) {
            throw new \RuntimeException(
                'no se encontró el cierre del objeto JSON raíz'
            );
        }

        $beforeClosing = substr($json, 0, $closingOffset);
        $lastLf = strrpos($beforeClosing, "\n");
        $closingOnOwnLine = $lastLf !== false
            && trim(substr($beforeClosing, $lastLf + 1)) === '';

        if ($closingOnOwnLine) {
            $insertionOffset = $lastLf;
            if (
                $insertionOffset > 0
                && $json[$insertionOffset - 1] === "\r"
            ) {
                --$insertionOffset;
            }
            $suffixLineEnding = '';
        } else {
            $insertionOffset = $closingOffset;
            $suffixLineEnding = $lineEnding;
        }

        $insertion = ($spans !== [] ? ',' : '')
            . $lineEnding
            . implode(',' . $lineEnding, $entries)
            . $suffixLineEnding;

        return substr_replace(
            $json,
            $insertion,
            $insertionOffset,
            0
        );
    }

    private function encodeJsonValue(
        mixed $value,
        string $indent,
        string $lineEnding
    ): string {
        $encoded = json_encode(
            $value,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
        $encoded = str_replace(
            "\n",
            "\n" . $indent,
            $encoded
        );

        return str_replace("\n", $lineEnding, $encoded);
    }

    /**
     * @return array<string, array{
     *     key_start: int,
     *     value_start: int,
     *     value_end: int
     * }>
     */
    private function topLevelJsonValueSpans(string $json): array
    {
        $length = strlen($json);
        $index = $this->skipJsonWhitespace($json, 0);

        if ($index >= $length || $json[$index] !== '{') {
            throw new \RuntimeException(
                'el catálogo JSON raíz no es un objeto'
            );
        }

        ++$index;
        $spans = [];

        while (true) {
            $index = $this->skipJsonWhitespace($json, $index);

            if ($index >= $length) {
                throw new \RuntimeException(
                    'el objeto JSON raíz está incompleto'
                );
            }

            if ($json[$index] === '}') {
                break;
            }

            if ($json[$index] !== '"') {
                throw new \RuntimeException(
                    'se esperaba una clave JSON en el nivel raíz'
                );
            }

            $keyStart = $index;
            $keyEnd = $this->jsonStringEnd($json, $index);
            $key = json_decode(
                substr($json, $keyStart, $keyEnd - $keyStart),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (!is_string($key)) {
                throw new \RuntimeException(
                    'la clave JSON del nivel raíz no es válida'
                );
            }

            $index = $this->skipJsonWhitespace($json, $keyEnd);

            if ($index >= $length || $json[$index] !== ':') {
                throw new \RuntimeException(
                    'falta el separador de una clave JSON'
                );
            }

            $valueStart = $this->skipJsonWhitespace(
                $json,
                $index + 1
            );
            $valueEnd = $this->jsonValueEnd($json, $valueStart);
            $spans[$key] = [
                'key_start' => $keyStart,
                'value_start' => $valueStart,
                'value_end' => $valueEnd,
            ];

            $index = $this->skipJsonWhitespace($json, $valueEnd);

            if ($index < $length && $json[$index] === ',') {
                ++$index;
                continue;
            }

            if ($index < $length && $json[$index] === '}') {
                break;
            }

            throw new \RuntimeException(
                'el objeto JSON raíz contiene una separación inválida'
            );
        }

        return $spans;
    }

    private function jsonValueEnd(string $json, int $start): int
    {
        $length = strlen($json);

        if ($start >= $length) {
            throw new \RuntimeException('falta un valor JSON');
        }

        if ($json[$start] === '"') {
            return $this->jsonStringEnd($json, $start);
        }

        if ($json[$start] === '{' || $json[$start] === '[') {
            $depth = 0;
            $inString = false;
            $escaped = false;

            for ($index = $start; $index < $length; ++$index) {
                $character = $json[$index];

                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($character === '\\') {
                        $escaped = true;
                    } elseif ($character === '"') {
                        $inString = false;
                    }
                    continue;
                }

                if ($character === '"') {
                    $inString = true;
                } elseif ($character === '{' || $character === '[') {
                    ++$depth;
                } elseif ($character === '}' || $character === ']') {
                    --$depth;
                    if ($depth === 0) {
                        return $index + 1;
                    }
                }
            }

            throw new \RuntimeException(
                'un valor JSON compuesto está incompleto'
            );
        }

        $index = $start;
        while (
            $index < $length
            && $json[$index] !== ','
            && $json[$index] !== '}'
        ) {
            ++$index;
        }

        while (
            $index > $start
            && ctype_space($json[$index - 1])
        ) {
            --$index;
        }

        return $index;
    }

    private function jsonStringEnd(string $json, int $start): int
    {
        $length = strlen($json);
        $escaped = false;

        for ($index = $start + 1; $index < $length; ++$index) {
            $character = $json[$index];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($character === '\\') {
                $escaped = true;
                continue;
            }

            if ($character === '"') {
                return $index + 1;
            }
        }

        throw new \RuntimeException('una cadena JSON está incompleta');
    }

    private function skipJsonWhitespace(string $json, int $index): int
    {
        $length = strlen($json);

        while (
            $index < $length
            && ctype_space($json[$index])
        ) {
            ++$index;
        }

        return $index;
    }

    private function lineIndentAt(string $contents, int $offset): string
    {
        $lineStart = strrpos(
            substr($contents, 0, $offset),
            "\n"
        );
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        preg_match(
            '/\A[ \t]*/',
            substr($contents, $lineStart),
            $matches
        );

        return $matches[0] ?? '';
    }

    /**
     * @param array{
     *     source: string,
     *     target: string,
     *     source_id: string,
     *     target_id: string,
     *     policy: string,
     *     group: string|null,
     *     track_state: bool
     * } $item
     * @param list<string> $fingerprints
     */
    private function recordState(
        array $item,
        array $fingerprints
    ): void {
        if (
            !$item['track_state']
            || $item['policy'] !== ManagedFileRegistry::POLICY_MANAGED
        ) {
            return;
        }

        sort($fingerprints, SORT_STRING);

        $this->stateFiles[$item['target_id']] = [
            'source' => $item['source_id'],
            'fingerprints' => array_values(array_unique($fingerprints)),
            'group' => $item['group'],
        ];
    }

    private function loadHistory(string $historyPath): void
    {
        if (!is_file($historyPath)) {
            $this->io->writeError(sprintf(
                '<warning>No existe el historial de ficheros CORE: %s. '
                    . 'Los ficheros legacy desconocidos se preservarán.</warning>',
                $historyPath
            ));
            return;
        }

        $decoded = $this->decodeJsonFile($historyPath);

        if (
            !is_array($decoded)
            || ($decoded['schema'] ?? null) !== self::HISTORY_SCHEMA
            || !isset($decoded['files'])
            || !is_array($decoded['files'])
        ) {
            $this->io->writeError(sprintf(
                '<warning>Historial CORE inválido: %s. '
                    . 'Los ficheros legacy desconocidos se preservarán.</warning>',
                $historyPath
            ));
            return;
        }

        foreach ($decoded['files'] as $sourceId => $fingerprints) {
            if (!is_string($sourceId)) {
                continue;
            }

            $valid = $this->readFingerprintList($fingerprints);

            if ($valid !== []) {
                $this->history[
                    ManagedFileRegistry::normalizePath($sourceId)
                ] = $valid;
            }
        }
    }

    private function loadState(string $statePath): void
    {
        $this->statePath = $statePath;

        if (!file_exists($statePath) && !is_link($statePath)) {
            return;
        }

        if (!is_file($statePath) || is_link($statePath)) {
            $this->stateWritable = false;
            $this->io->writeError(sprintf(
                '<warning>Se preservó el manifiesto CORE no regular: %s</warning>',
                $statePath
            ));
            return;
        }

        $decoded = $this->decodeJsonFile($statePath);

        if (
            !is_array($decoded)
            || ($decoded['schema'] ?? null) !== self::STATE_SCHEMA
            || !isset($decoded['files'])
            || !is_array($decoded['files'])
        ) {
            $this->stateWritable = false;
            $this->io->writeError(sprintf(
                '<warning>Se preservó el manifiesto CORE inválido: %s</warning>',
                $statePath
            ));
            return;
        }

        foreach ($decoded['files'] as $targetId => $entry) {
            if (!is_string($targetId) || !is_array($entry)) {
                continue;
            }

            $this->stateFiles[
                ManagedFileRegistry::normalizePath($targetId)
            ] = $entry;
        }
    }

    private string $statePath;

    private function writeState(): void
    {
        if (!$this->stateWritable) {
            return;
        }

        ksort($this->stateFiles, SORT_STRING);

        $manifest = [
            'schema' => self::STATE_SCHEMA,
            'package' => 'liquidstack/core',
            'files' => $this->stateFiles,
        ];
        $encoded = json_encode(
            $manifest,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        if (
            is_file($this->statePath)
            && file_get_contents($this->statePath) === $encoded
        ) {
            return;
        }

        if ($this->hasLinkedPathComponent($this->statePath)) {
            $this->stateWritable = false;
            $this->io->writeError(sprintf(
                '<warning>No se escribió el manifiesto CORE porque su ruta '
                    . 'contiene un enlace: %s</warning>',
                $this->statePath
            ));
            return;
        }

        $this->filesystem->mkdir(dirname($this->statePath), 0775);
        $this->filesystem->dumpFile($this->statePath, $encoded);
    }

    private function writeSummary(): void
    {
        $this->io->write(sprintf(
            '<info>CORE sync seguro: %d nuevos, %d actualizados, '
                . '%d catálogos ampliados, %d preservados, '
                . '%d semillas protegidas, %d sin cambios, %d errores.</info>',
            $this->stats['added'],
            $this->stats['updated'],
            $this->stats['merged'],
            $this->stats['preserved'],
            $this->stats['protected'],
            $this->stats['unchanged'],
            $this->stats['errors']
        ));

        if ($this->preserved === []) {
            return;
        }

        ksort($this->preserved, SORT_STRING);

        foreach ($this->preserved as $targetId => $reason) {
            $this->io->writeError(sprintf(
                '<warning>Preservado %s: %s.</warning>',
                $targetId,
                $reason
            ));
        }
    }

    /**
     * @param mixed $fingerprints
     *
     * @return list<string>
     */
    private function readFingerprintList(mixed $fingerprints): array
    {
        if (!is_array($fingerprints)) {
            return [];
        }

        $valid = [];

        foreach ($fingerprints as $fingerprint) {
            if (
                is_string($fingerprint)
                && preg_match(
                    '/\Asha256:[a-f0-9]{64}\z/',
                    $fingerprint
                ) === 1
            ) {
                $valid[] = $fingerprint;
            }
        }

        return array_values(array_unique($valid));
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private function fingerprintsIntersect(
        array $left,
        array $right
    ): bool {
        return array_intersect($left, $right) !== [];
    }

    /**
     * @return array<mixed>|null
     */
    private function decodeJsonFile(string $path): ?array
    {
        $raw = @file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        try {
            $decoded = json_decode(
                $this->stripUtf8Bom($raw),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function stripUtf8Bom(string $contents): string
    {
        return str_starts_with($contents, "\xEF\xBB\xBF")
            ? substr($contents, 3)
            : $contents;
    }

    private function samePath(string $left, string $right): bool
    {
        $normalize = static function (string $path): string {
            $path = str_replace('\\', '/', $path);
            $path = rtrim($path, '/');

            return PHP_OS_FAMILY === 'Windows'
                ? strtolower($path)
                : $path;
        };

        return $normalize($left) === $normalize($right);
    }

    private function hasLinkedPathComponent(string $path): bool
    {
        $projectRoot = rtrim(
            str_replace('\\', '/', $this->projectRoot),
            '/'
        );
        $normalizedPath = str_replace('\\', '/', $path);

        if (
            $normalizedPath !== $projectRoot
            && !str_starts_with($normalizedPath, $projectRoot . '/')
        ) {
            return true;
        }

        $relative = ltrim(
            substr($normalizedPath, strlen($projectRoot)),
            '/'
        );
        $current = $this->projectRoot;

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '') {
                continue;
            }

            $current .= DIRECTORY_SEPARATOR . $segment;

            if (is_link($current)) {
                return true;
            }
        }

        return false;
    }
}
