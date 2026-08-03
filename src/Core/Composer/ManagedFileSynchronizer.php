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
    private const TRANSACTION_RELATIVE_PATH =
        '.liquidstack/core/sync-transactions';

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
        ?string $statePath = null,
        ?Filesystem $filesystem = null
    ) {
        $this->filesystem = $filesystem ?? new Filesystem();
        $this->loadHistory(
            $historyPath
                ?? $this->packageRoot
                    . '/manifests/managed-file-history.json'
        );
        $this->statePath = $statePath
            ?? $this->projectRoot . '/' . self::STATE_RELATIVE_PATH;
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
        $lock = $this->acquireProjectLock();

        try {
            $this->recoverInterruptedTransactions();
            $this->reloadStateUnderLock();
            if ($this->queue === []) {
                $this->writeSummary();
                return;
            }
            $this->applyUnderLock();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function applyUnderLock(): void
    {
        ksort($this->queue, SORT_STRING);

        /** @var array<string, array<string, mixed>> $plans */
        $plans = [];

        /** @var array<string, true> $blockedGroups */
        $blockedGroups = [];

        foreach ($this->queue as $queueKey => $item) {
            $plan = $this->planItem($item);
            if (
                $item['policy'] === ManagedFileRegistry::POLICY_MANAGED
                && $item['group'] !== null
            ) {
                $targetExists = file_exists($item['target'])
                    || is_link($item['target']);
                $plan['target_exists'] = $targetExists;
                $plan['target_hash'] = $targetExists
                    && is_file($item['target'])
                    && !is_link($item['target'])
                    ? $this->rawFileHash($item['target'])
                    : null;
            }
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

        /** @var array<string, array<string, array<string, mixed>>> $managedGroups */
        $managedGroups = [];

        foreach ($this->queue as $queueKey => $item) {
            if (
                $item['policy'] === ManagedFileRegistry::POLICY_MANAGED
                && $item['group'] !== null
            ) {
                $managedGroups[$item['group']][$queueKey] = [
                    'item' => $item,
                    'plan' => $plans[$queueKey],
                ];
            }
        }

        /** @var array<string, true> $appliedGroups */
        $appliedGroups = [];

        foreach ($this->queue as $queueKey => $item) {
            if (
                $item['policy'] !== ManagedFileRegistry::POLICY_MANAGED
                || $item['group'] === null
            ) {
                $this->applyPlan($item, $plans[$queueKey]);
                continue;
            }

            $group = $item['group'];
            if (isset($appliedGroups[$group])) {
                continue;
            }
            $appliedGroups[$group] = true;

            $entries = $managedGroups[$group];
            if (isset($blockedGroups[$group])) {
                foreach ($entries as $entry) {
                    $plan = $entry['plan'];
                    if ($plan['action'] !== 'error') {
                        $plan['action'] = 'preserve_group';
                        $plan['reason'] = sprintf(
                            'el grupo %s contiene personalizaciones locales',
                            $group
                        );
                    }

                    $this->applyPlan($entry['item'], $plan);
                }
                continue;
            }

            $this->applyManagedGroup($group, $entries);
        }

        $this->writeState();
        $this->writeSummary();
    }

    /** @param array<string, array<string, mixed>> $entries */
    private function applyManagedGroup(string $group, array $entries): void
    {
        $mutations = array_filter(
            $entries,
            static fn (array $entry): bool => in_array(
                $entry['plan']['action'],
                ['add', 'update'],
                true
            )
        );

        if ($mutations === []) {
            foreach ($entries as $entry) {
                $this->applyPlan($entry['item'], $entry['plan']);
            }
            return;
        }

        $transactionRoot = '';
        $stateBefore = $this->stateFiles;
        $statsBefore = $this->stats;

        try {
            $transactionRoot = $this->createTransactionRoot($group);
            $files = [];
            $index = 0;

            foreach ($mutations as $queueKey => $entry) {
                ++$index;
                $item = $entry['item'];
                $plan = $entry['plan'];
                $target = $this->projectTargetForId($item['target_id']);
                if (!$this->samePath($target, $item['target'])) {
                    throw new \RuntimeException(sprintf(
                        'el destino %s no pertenece a su ruta de proyecto',
                        $item['target_id']
                    ));
                }
                $staged = $transactionRoot
                    . DIRECTORY_SEPARATOR
                    . 'staged'
                    . DIRECTORY_SEPARATOR
                    . $index;
                $backup = $transactionRoot
                    . DIRECTORY_SEPARATOR
                    . 'backup'
                    . DIRECTORY_SEPARATOR
                    . $index;

                $this->filesystem->copy($item['source'], $staged, true);
                $expectedHash = $this->rawFileHash($item['source']);
                if (
                    !is_file($staged)
                    || is_link($staged)
                    || $this->rawFileHash($staged) !== $expectedHash
                ) {
                    throw new \RuntimeException(sprintf(
                        'el staging de %s no coincide con el origen',
                        $item['target_id']
                    ));
                }

                $files[$queueKey] = [
                    'target' => $target,
                    'target_id' => $item['target_id'],
                    'staged' => $staged,
                    'backup' => $backup,
                    'had_target' => $plan['action'] === 'update',
                    'original_hash' => $plan['target_hash'] ?? null,
                    'expected_hash' => $expectedHash,
                    'slot' => $index,
                ];

                if (
                    $files[$queueKey]['had_target']
                    && !is_string($files[$queueKey]['original_hash'])
                ) {
                    throw new \RuntimeException(sprintf(
                        'no se pudo fijar la huella original de %s',
                        $item['target_id']
                    ));
                }
            }

            // Revalidar justo antes de la primera escritura evita sustituir
            // una personalización concurrente usando un plan ya obsoleto.
            foreach ($entries as $queueKey => $entry) {
                if (
                    !$this->entryMatchesPlanSnapshot($entry)
                    || isset($files[$queueKey])
                        && $this->rawFileHash($entry['item']['source'])
                            !== $files[$queueKey]['expected_hash']
                ) {
                    throw new \RuntimeException(sprintf(
                        'el destino %s cambió durante la sincronización',
                        $entry['item']['target_id']
                    ));
                }

                if (isset($files[$queueKey])) {
                    $this->filesystem->mkdir(
                        dirname($files[$queueKey]['target']),
                        0775
                    );
                }
            }

            $this->writeTransactionJournal(
                $transactionRoot,
                $group,
                'prepared',
                $files
            );

            // En Windows no se puede confiar en sustituir un fichero abierto
            // mediante rename. Apartar primero todos los originales deja cada
            // destino libre y conserva una copia recuperable en el mismo
            // volumen que el proyecto.
            foreach ($mutations as $queueKey => $entry) {
                if (!$files[$queueKey]['had_target']) {
                    continue;
                }

                $this->filesystem->rename(
                    $files[$queueKey]['target'],
                    $files[$queueKey]['backup']
                );
                if (
                    !is_file($files[$queueKey]['backup'])
                    || is_link($files[$queueKey]['backup'])
                    || $this->rawFileHash($files[$queueKey]['backup'])
                        !== $files[$queueKey]['original_hash']
                ) {
                    throw new \RuntimeException(sprintf(
                        'el original %s cambio al crear su backup',
                        $entry['item']['target_id']
                    ));
                }
            }

            foreach ($mutations as $queueKey => $entry) {
                if (
                    file_exists($files[$queueKey]['target'])
                    || is_link($files[$queueKey]['target'])
                ) {
                    throw new \RuntimeException(sprintf(
                        'el destino %s reaparecio antes de instalarlo',
                        $entry['item']['target_id']
                    ));
                }
                $this->filesystem->rename(
                    $files[$queueKey]['staged'],
                    $files[$queueKey]['target']
                );
            }

            foreach ($entries as $queueKey => $entry) {
                if (isset($files[$queueKey])) {
                    continue;
                }
                if (!$this->entryMatchesPlanSnapshot($entry)) {
                    throw new \RuntimeException(sprintf(
                        'el destino %s cambio antes del commit del grupo',
                        $entry['item']['target_id']
                    ));
                }
            }

            foreach ($files as $file) {
                if (
                    !is_file($file['target'])
                    || is_link($file['target'])
                    || $this->rawFileHash($file['target'])
                        !== $file['expected_hash']
                    || $file['had_target']
                        && (
                            !is_file($file['backup'])
                            || is_link($file['backup'])
                            || $this->rawFileHash($file['backup'])
                                !== $file['original_hash']
                        )
                ) {
                    throw new \RuntimeException(sprintf(
                        'la transaccion de %s cambio antes del commit',
                        $file['target_id']
                    ));
                }
            }

            $this->writeTransactionJournal(
                $transactionRoot,
                $group,
                'committed',
                $files
            );

            foreach ($entries as $entry) {
                $this->recordSuccessfulManagedPlan(
                    $entry['item'],
                    $entry['plan']
                );
            }
            $this->removeTransactionRoot($transactionRoot);
        } catch (\Throwable $exception) {
            $this->stateFiles = $stateBefore;
            $this->stats = $statsBefore;

            if ($transactionRoot !== '') {
                try {
                    $this->recoverTransactionRoot($transactionRoot);
                } catch (\Throwable $rollbackException) {
                    ++$this->stats['errors'];
                    $message = sprintf(
                        'No se pudo sincronizar ni restaurar por completo el grupo %s. Copia recuperable: %s. Fallo inicial: %s. Fallo de restauración: %s',
                        $group,
                        $transactionRoot,
                        $exception->getMessage(),
                        $rollbackException->getMessage()
                    );
                    $this->io->writeError(sprintf(
                        '<error>%s</error>',
                        $message
                    ));
                    throw new \RuntimeException(
                        $message,
                        0,
                        $rollbackException
                    );
                }
            }

            ++$this->stats['errors'];
            $this->io->writeError(sprintf(
                '<error>No se pudo sincronizar el grupo %s; se restauraron todos sus ficheros: %s</error>',
                $group,
                $exception->getMessage()
            ));
        }
    }

    /** @param array<string, mixed> $entry */
    private function entryMatchesPlanSnapshot(array $entry): bool
    {
        $currentPlan = $this->planItem($entry['item']);
        $targetExists = file_exists($entry['item']['target'])
            || is_link($entry['item']['target']);
        $targetHash = $targetExists
            && is_file($entry['item']['target'])
            && !is_link($entry['item']['target'])
            ? $this->rawFileHash($entry['item']['target'])
            : null;

        return $currentPlan['action'] === $entry['plan']['action']
            && $targetExists === ($entry['plan']['target_exists'] ?? null)
            && $targetHash === ($entry['plan']['target_hash'] ?? null)
            && $this->sameFileHashSet(
                $currentPlan['source_fingerprints'],
                $entry['plan']['source_fingerprints']
            );
    }

    /** @return resource */
    private function acquireProjectLock()
    {
        $canonicalRoot = realpath($this->projectRoot);
        if ($canonicalRoot === false) {
            throw new \RuntimeException(
                'no se pudo resolver el proyecto para bloquear la sincronización'
            );
        }
        $canonicalRoot = str_replace('\\', '/', $canonicalRoot);
        if (PHP_OS_FAMILY === 'Windows') {
            $canonicalRoot = strtolower($canonicalRoot);
        }

        $lockPath = rtrim(sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR
            . 'liquidstack-core-sync-'
            . hash('sha256', $canonicalRoot)
            . '.lock';
        if (
            is_link($lockPath)
            || (file_exists($lockPath) && !is_file($lockPath))
        ) {
            throw new \RuntimeException(
                'el lock de sincronización no es un fichero regular'
            );
        }

        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new \RuntimeException(
                'no se pudo adquirir el lock de sincronización del proyecto'
            );
        }

        return $lock;
    }

    private function reloadStateUnderLock(): void
    {
        $this->stateFiles = [];
        $this->stateWritable = true;
        $this->loadState($this->statePath);
    }

    private function recoverInterruptedTransactions(): void
    {
        $root = $this->transactionBasePath();
        if ($this->hasLinkedPathComponent($root)) {
            throw new \RuntimeException(
                'la ruta de transacciones contiene un enlace'
            );
        }
        if (!file_exists($root) && !is_link($root)) {
            return;
        }
        if (!is_dir($root) || is_link($root)) {
            throw new \RuntimeException(
                'la ruta de transacciones pendientes no es un directorio regular'
            );
        }
        $this->ensureTransactionGitIgnore($root);

        $entries = scandir($root);
        if ($entries === false) {
            throw new \RuntimeException(
                'no se pudieron inspeccionar las transacciones pendientes'
            );
        }

        foreach ($entries as $entry) {
            if (
                $entry === '.'
                || $entry === '..'
                || $entry === '.gitignore'
            ) {
                continue;
            }
            $transactionRoot = $root . DIRECTORY_SEPARATOR . $entry;
            if (
                preg_match('/\A[a-f0-9]{24}\z/', $entry) !== 1
                || !is_dir($transactionRoot)
                || is_link($transactionRoot)
            ) {
                throw new \RuntimeException(sprintf(
                    'entrada de transacción no reconocida: %s',
                    $entry
                ));
            }

            $this->recoverTransactionRoot($transactionRoot);
        }
    }

    private function createTransactionRoot(string $group): string
    {
        $root = $this->transactionBasePath();
        if ($this->hasLinkedPathComponent($root)) {
            throw new \RuntimeException(
                'la ruta de transacciones contiene un enlace'
            );
        }
        $this->filesystem->mkdir($root, 0775);
        $this->ensureTransactionGitIgnore($root);

        do {
            $transactionRoot = $root
                . DIRECTORY_SEPARATOR
                . bin2hex(random_bytes(12));
        } while (file_exists($transactionRoot) || is_link($transactionRoot));

        $this->filesystem->mkdir($transactionRoot, 0775);
        $this->writeTransactionJournal(
            $transactionRoot,
            $group,
            'staging',
            []
        );
        $this->filesystem->mkdir([
            $transactionRoot . DIRECTORY_SEPARATOR . 'staged',
            $transactionRoot . DIRECTORY_SEPARATOR . 'backup',
        ], 0775);

        return $transactionRoot;
    }

    private function transactionBasePath(): string
    {
        return rtrim($this->projectRoot, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                self::TRANSACTION_RELATIVE_PATH
            );
    }

    private function ensureTransactionGitIgnore(string $root): void
    {
        $path = $root . DIRECTORY_SEPARATOR . '.gitignore';
        $contents = "*\n";
        if (is_file($path) && !is_link($path)) {
            if (file_get_contents($path) !== $contents) {
                throw new \RuntimeException(
                    'el .gitignore de transacciones contiene cambios locales'
                );
            }
            return;
        }
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException(
                'el .gitignore de transacciones no es regular'
            );
        }
        $this->filesystem->dumpFile($path, $contents);
    }

    /** @param array<string, array<string, mixed>> $files */
    private function writeTransactionJournal(
        string $transactionRoot,
        string $group,
        string $status,
        array $files
    ): void {
        $journalFiles = [];
        foreach ($files as $file) {
            $journalFiles[] = [
                'target_id' => $file['target_id'],
                'slot' => $file['slot'],
                'had_target' => $file['had_target'],
                'original_hash' => $file['original_hash'],
                'expected_hash' => $file['expected_hash'],
            ];
        }

        $encoded = json_encode([
            'schema' => 1,
            'group' => $group,
            'status' => $status,
            'files' => $journalFiles,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            . PHP_EOL;
        $this->filesystem->dumpFile(
            $transactionRoot . DIRECTORY_SEPARATOR . 'journal.json',
            $encoded
        );
    }

    private function recoverTransactionRoot(string $transactionRoot): void
    {
        $journalPath = $transactionRoot
            . DIRECTORY_SEPARATOR
            . 'journal.json';
        if (!is_file($journalPath) || is_link($journalPath)) {
            $entries = !is_link($transactionRoot) && is_dir($transactionRoot)
                ? scandir($transactionRoot)
                : false;
            if ($entries !== false && count($entries) === 2) {
                $this->removeTransactionRoot($transactionRoot, true);
                return;
            }
            throw new \RuntimeException(
                'la transacción pendiente no contiene un journal regular'
            );
        }
        $journal = $this->decodeJsonFile($journalPath);
        if (
            !is_array($journal)
            || ($journal['schema'] ?? null) !== 1
            || !is_string($journal['group'] ?? null)
            || !in_array(
                $journal['status'] ?? null,
                ['staging', 'prepared', 'committed'],
                true
            )
            || !is_array($journal['files'] ?? null)
            || !array_is_list($journal['files'])
        ) {
            throw new \RuntimeException(
                'el journal de la transacción pendiente es inválido'
            );
        }

        if ($journal['status'] === 'staging') {
            $this->removeTransactionRoot($transactionRoot, true);
            return;
        }

        $files = $this->transactionFilesFromJournal(
            $transactionRoot,
            $journal['files']
        );
        if ($journal['status'] === 'committed') {
            foreach ($files as $file) {
                if (
                    !is_file($file['target'])
                    || is_link($file['target'])
                    || $this->rawFileHash($file['target'])
                        !== $file['expected_hash']
                ) {
                    throw new \RuntimeException(sprintf(
                        'el destino confirmado %s ya no coincide con el journal',
                        $file['target_id']
                    ));
                }

                $backupExists = file_exists($file['backup'])
                    || is_link($file['backup']);
                if (
                    $backupExists
                    && (
                        !$file['had_target']
                        || !is_file($file['backup'])
                        || is_link($file['backup'])
                        || $this->rawFileHash($file['backup'])
                            !== $file['original_hash']
                    )
                ) {
                    throw new \RuntimeException(sprintf(
                        'el backup confirmado de %s no coincide con el journal',
                        $file['target_id']
                    ));
                }
            }
            $this->removeTransactionRoot($transactionRoot, true);
            return;
        }

        $errors = $this->rollbackTransactionFiles($files);
        if ($errors !== []) {
            throw new \RuntimeException(implode('; ', $errors));
        }
        $this->removeTransactionRoot($transactionRoot, true);
    }

    /** @return list<array<string, mixed>> */
    private function transactionFilesFromJournal(
        string $transactionRoot,
        array $journalFiles
    ): array {
        $files = [];
        $targets = [];
        $slots = [];

        foreach ($journalFiles as $entry) {
            if (
                !is_array($entry)
                || !is_string($entry['target_id'] ?? null)
                || !is_int($entry['slot'] ?? null)
                || $entry['slot'] < 1
                || !is_bool($entry['had_target'] ?? null)
                || !array_key_exists('original_hash', $entry)
                || (
                    $entry['had_target']
                        ? !$this->isRawFileHash($entry['original_hash'])
                        : $entry['original_hash'] !== null
                )
                || !$this->isRawFileHash($entry['expected_hash'] ?? null)
            ) {
                throw new \RuntimeException(
                    'el journal contiene una entrada de fichero inválida'
                );
            }

            if (isset($slots[$entry['slot']])) {
                throw new \RuntimeException(
                    'el journal repite un slot de transaccion'
                );
            }
            $slots[$entry['slot']] = true;

            $targetId = ManagedFileRegistry::normalizePath(
                $entry['target_id']
            );
            $target = $this->projectTargetForId($targetId);
            $targetKey = str_replace('\\', '/', $target);
            if (PHP_OS_FAMILY === 'Windows') {
                $targetKey = strtolower($targetKey);
            }
            if (isset($targets[$targetKey])) {
                throw new \RuntimeException(
                    'el journal repite un destino de proyecto'
                );
            }
            $targets[$targetKey] = true;

            $files[] = [
                'target' => $target,
                'target_id' => $targetId,
                'staged' => $transactionRoot
                    . DIRECTORY_SEPARATOR
                    . 'staged'
                    . DIRECTORY_SEPARATOR
                    . $entry['slot'],
                'backup' => $transactionRoot
                    . DIRECTORY_SEPARATOR
                    . 'backup'
                    . DIRECTORY_SEPARATOR
                    . $entry['slot'],
                'had_target' => $entry['had_target'],
                'original_hash' => $entry['original_hash'],
                'expected_hash' => $entry['expected_hash'],
                'slot' => $entry['slot'],
            ];
        }

        return $files;
    }

    /**
     * @param list<array<string, mixed>> $files
     * @return list<string>
     */
    private function rollbackTransactionFiles(array $files): array
    {
        $errors = [];

        foreach (array_reverse($files) as $file) {
            $targetExists = file_exists($file['target'])
                || is_link($file['target']);
            $backupExists = file_exists($file['backup'])
                || is_link($file['backup']);
            $targetHash = $targetExists
                && is_file($file['target'])
                && !is_link($file['target'])
                ? $this->rawFileHash($file['target'])
                : null;
            $targetIsInstalled = $targetHash === $file['expected_hash'];
            $targetIsOriginal = $file['had_target']
                && $targetHash === $file['original_hash'];

            if ($backupExists) {
                if (
                    !$file['had_target']
                    || !is_file($file['backup'])
                    || is_link($file['backup'])
                    || $this->rawFileHash($file['backup'])
                        !== $file['original_hash']
                ) {
                    $errors[] = sprintf(
                        'el backup de %s no coincide con el original',
                        $file['target_id']
                    );
                    continue;
                }
                if ($targetIsOriginal) {
                    continue;
                }
                if ($targetExists && !$targetIsInstalled) {
                    $errors[] = sprintf(
                        'se preservó contenido concurrente en %s',
                        $file['target_id']
                    );
                    continue;
                }

                try {
                    if ($targetIsInstalled) {
                        $this->filesystem->remove($file['target']);
                    }
                    $this->filesystem->rename(
                        $file['backup'],
                        $file['target']
                    );
                    if (
                        !is_file($file['target'])
                        || is_link($file['target'])
                        || $this->rawFileHash($file['target'])
                            !== $file['original_hash']
                    ) {
                        throw new \RuntimeException(sprintf(
                            'el original restaurado de %s no coincide',
                            $file['target_id']
                        ));
                    }
                } catch (\Throwable $exception) {
                    $errors[] = $exception->getMessage();
                }
                continue;
            }

            if ($file['had_target']) {
                if ($targetIsOriginal) {
                    continue;
                }
                if (!$targetExists || $targetIsInstalled) {
                    $errors[] = sprintf(
                        'no se encontró el original recuperable de %s',
                        $file['target_id']
                    );
                } else {
                    $errors[] = sprintf(
                        'se preservo contenido concurrente en %s',
                        $file['target_id']
                    );
                }
                continue;
            }

            if (!$targetExists) {
                continue;
            }
            if (!$targetIsInstalled) {
                $errors[] = sprintf(
                    'se preservó contenido concurrente en %s',
                    $file['target_id']
                );
                continue;
            }

            try {
                $this->filesystem->remove($file['target']);
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        return $errors;
    }

    private function projectTargetForId(string $targetId): string
    {
        $targetId = ManagedFileRegistry::normalizePath($targetId);
        $segments = explode('/', $targetId);
        if (
            $targetId === ''
            || preg_match('/\A[A-Za-z]:\//', $targetId) === 1
            || preg_match('/[\x00-\x1F\x7F:]/', $targetId) === 1
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            throw new \RuntimeException(
                'el journal contiene un destino fuera del proyecto'
            );
        }

        $target = rtrim($this->projectRoot, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $targetId);
        if ($this->hasLinkedPathComponent($target)) {
            throw new \RuntimeException(
                'el journal contiene una ruta enlazada o externa'
            );
        }

        return $target;
    }

    private function rawFileHash(string $path): string
    {
        $hash = @hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new \RuntimeException(sprintf(
                'no se pudo calcular la huella exacta de %s',
                $path
            ));
        }

        return 'sha256:' . $hash;
    }

    private function isRawFileHash(mixed $hash): bool
    {
        return is_string($hash)
            && preg_match('/\Asha256:[a-f0-9]{64}\z/', $hash) === 1;
    }

    private function removeTransactionRoot(
        string $transactionRoot,
        bool $strict = false
    ): void {
        try {
            // El journal se retira al final. Si PHP se interrumpe durante el
            // cleanup, la siguiente ejecución conserva todavía el mapa de
            // recuperación o encuentra un scaffold completamente vacío.
            $this->filesystem->remove([
                $transactionRoot . DIRECTORY_SEPARATOR . 'staged',
                $transactionRoot . DIRECTORY_SEPARATOR . 'backup',
            ]);
            $this->filesystem->remove(
                $transactionRoot . DIRECTORY_SEPARATOR . 'journal.json'
            );
            $this->filesystem->remove($transactionRoot);
        } catch (\Throwable $exception) {
            if ($strict) {
                throw $exception;
            }
            ++$this->stats['errors'];
            $this->io->writeError(sprintf(
                '<warning>No se pudo retirar el staging de CORE %s: %s</warning>',
                $transactionRoot,
                $exception->getMessage()
            ));
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $plan
     */
    private function recordSuccessfulManagedPlan(
        array $item,
        array $plan
    ): void {
        if ($plan['action'] === 'add') {
            ++$this->stats['added'];
        } elseif ($plan['action'] === 'update') {
            ++$this->stats['updated'];
        } else {
            ++$this->stats['unchanged'];
        }

        $this->recordState($item, $plan['source_fingerprints']);
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private function sameFileHashSet(array $left, array $right): bool
    {
        sort($left, SORT_STRING);
        sort($right, SORT_STRING);

        return $left === $right;
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
        $resolvedRoot = realpath($current);
        if (
            $resolvedRoot !== false
            && !$this->samePath($current, $resolvedRoot)
        ) {
            return true;
        }

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '') {
                continue;
            }

            $current .= DIRECTORY_SEPARATOR . $segment;

            $resolved = realpath($current);
            if (
                is_link($current)
                || $resolved !== false
                    && !$this->samePath($current, $resolved)
            ) {
                return true;
            }
        }

        return false;
    }
}
