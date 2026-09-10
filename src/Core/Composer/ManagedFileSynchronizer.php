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
    private const TRANSACTION_CLEANUP_ATTEMPTS = 5;
    private const TRANSACTION_CLEANUP_DELAY_US = 25000;
    public const SYNC_PLAN_PROTOCOL = 'managed-sync-v1';

    private Filesystem $filesystem;

    /**
     * @var array<string, array{
     *     source: string,
     *     target: string,
     *     source_id: string,
     *     target_id: string,
     *     policy: string,
     *     group: string|null,
     *     track_state: bool,
     *     target_root: string,
     *     target_scope: string
     * }>
     */
    private array $queue = [];

    /** @var array<string, string> */
    private array $queuedTargets = [];

    /** @var array<string, string> */
    private array $queuedTargetIds = [];

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

    /** @var array<string, true> */
    private array $blockers = [];

    /** @var array<string, string> */
    private array $planInputs = [];

    private ?string $stateBlocker = null;

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $packageRoot,
        private readonly IOInterface $io,
        ?string $historyPath = null,
        ?string $statePath = null,
        ?Filesystem $filesystem = null,
        private readonly ?\Closure $filesystemDeviceResolver = null,
        private readonly ?\Closure $transactionUnlinkObserver = null
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
        bool $trackState = true,
        ?string $authorizedTargetRoot = null
    ): void {
        $sourceId = ManagedFileRegistry::normalizePath($sourceId);
        $targetId = ManagedFileRegistry::normalizePath($targetId);
        $policy ??= ManagedFileRegistry::policyForSource($sourceId);

        if ($policy === ManagedFileRegistry::POLICY_IGNORE) {
            return;
        }

        $queueKey = $targetId . "\0" . $sourceId;
        $candidate = [
            'source' => $source,
            'target' => $target,
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'policy' => $policy,
            'group' => $group
                ?? ManagedFileRegistry::groupForSource($sourceId),
            'track_state' => $trackState,
            'target_root' => $authorizedTargetRoot ?? $this->projectRoot,
            'target_scope' => $authorizedTargetRoot === null
                ? 'project'
                : 'external',
        ];

        if ($candidate['target_scope'] === 'project') {
            $canonicalTarget = $this->projectTargetPathForId($targetId);
            if (
                $this->collisionPathKey($target)
                    !== $this->collisionPathKey($canonicalTarget)
            ) {
                throw new \RuntimeException(sprintf(
                    'Colisión de sincronización: %s no corresponde a su destino de proyecto.',
                    $targetId
                ));
            }
            if (
                $this->hasLinkedPathComponent($target)
                || $this->hasLinkedPathComponent($canonicalTarget)
            ) {
                throw new \RuntimeException(sprintf(
                    'Colisión de sincronización: %s usa una ruta de proyecto enlazada.',
                    $targetId
                ));
            }
            // El target_id es la autoridad de casing y segmentos para rutas
            // project-owned. La comparación casefold de Windows no debe hacer
            // que un caller con casing divergente escriba otro fichero dentro
            // de un directorio NTFS con case sensitivity habilitado.
            $candidate['target'] = $canonicalTarget;
        }

        if (isset($this->queue[$queueKey])) {
            if ($this->sameQueueContract($this->queue[$queueKey], $candidate)) {
                return;
            }

            throw new \RuntimeException(sprintf(
                'Colisión de sincronización: %s se encoló con contratos distintos.',
                $targetId
            ));
        }

        if (isset($this->queuedTargetIds[$targetId])) {
            throw new \RuntimeException(sprintf(
                'Colisión de sincronización: %s se encoló más de una vez.',
                $targetId
            ));
        }

        $targetKey = $this->collisionPathKey($candidate['target']);

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
        $this->queuedTargetIds[$targetId] = $queueKey;

        $this->queue[$queueKey] = $candidate;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function sameQueueContract(array $left, array $right): bool
    {
        foreach (['source', 'target', 'target_root'] as $pathKey) {
            if (
                $this->normalizeBindingPath((string) $left[$pathKey])
                    !== $this->normalizeBindingPath((string) $right[$pathKey])
            ) {
                return false;
            }
        }

        foreach ([
            'source_id',
            'target_id',
            'policy',
            'group',
            'track_state',
            'target_scope',
        ] as $key) {
            if ($left[$key] !== $right[$key]) {
                return false;
            }
        }

        return true;
    }

    public function queueDirectory(
        string $source,
        string $target,
        string $sourceIdPrefix,
        string $targetIdPrefix,
        bool $trackState = true,
        ?string $policy = null,
        ?string $group = null,
        ?string $authorizedTargetRoot = null
    ): void {
        if (!is_dir($source)) {
            $this->io->writeError(sprintf(
                '<warning>Directorio de CORE no encontrado: %s</warning>',
                $source
            ));
            ++$this->stats['errors'];
            $this->block('sync.canonical_source_missing');
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
                $trackState,
                $authorizedTargetRoot
            );
        }
    }

    /**
     * @return array{
     *     schema: int,
     *     package: string,
     *     catalog_hash: string,
     *     entries: list<array{
     *         source: string,
     *         target: string,
     *         policy: string,
     *         group: string|null,
     *         track_state: bool
     *     }>
     * }
     */
    public function catalog(): array
    {
        $entries = [];
        $queue = $this->queue;
        ksort($queue, SORT_STRING);

        foreach ($queue as $item) {
            $entries[] = [
                'source' => $item['source_id'],
                'target' => $item['target_id'],
                'policy' => $item['policy'],
                'group' => $item['group'],
                'track_state' => $item['track_state'],
                'scope' => $item['target_scope'],
            ];
        }

        $blockers = $this->blockers();
        $status = $this->stats['errors'] === 0 && $blockers === []
            ? 'ready'
            : 'blocked';
        $payload = [
            'schema' => 1,
            'package' => 'liquidstack/core',
            'protocol' => self::SYNC_PLAN_PROTOCOL,
            'status' => $status,
            'queue_errors' => $this->stats['errors'],
            'blockers' => $blockers,
            'entries' => $entries,
        ];

        return $payload + [
            'catalog_hash' => $this->hashPayload($payload),
        ];
    }

    public function block(string $code): void
    {
        if (preg_match('/\Async\.[a-z0-9_.-]+\z/', $code) !== 1) {
            throw new \InvalidArgumentException('sync.blocker_code_invalid');
        }

        $this->blockers[$code] = true;
    }

    /** @return list<string> */
    public function blockers(): array
    {
        $blockers = array_keys($this->blockers);
        if ($this->stateBlocker !== null) {
            $blockers[] = $this->stateBlocker;
        }
        $transactionBlocker = $this->transactionMetadataBlocker();
        if ($transactionBlocker !== null) {
            $blockers[] = $transactionBlocker;
        }
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);

        return $blockers;
    }

    public function bindPlanInput(string $id, string $path): void
    {
        if (preg_match('/\A[a-z0-9_.-]+\z/', $id) !== 1) {
            throw new \InvalidArgumentException('sync.plan_input_id_invalid');
        }

        $this->planInputs[$id] = $path;
    }

    /**
     * Construye una instantanea estrictamente de solo lectura. No adquiere el
     * lock, no recupera journals y no escribe el estado del consumidor.
     *
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        $this->reloadStateUnderLock();
        $evaluated = $this->evaluateQueue();
        $snapshot = $this->evaluatedPlanSnapshot($evaluated);
        $counts = [];

        foreach ($snapshot['entries'] as $entry) {
            $action = (string) $entry['action'];
            $counts[$action] = ($counts[$action] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        return [
            'schema' => 1,
            'package' => 'liquidstack/core',
            'protocol' => self::SYNC_PLAN_PROTOCOL,
            'status' => $snapshot['status'],
            'plan_hash' => $this->hashPayload($snapshot['hash_payload']),
            'pending_transactions' => $snapshot['pending_transactions'],
            'queue_errors' => $snapshot['queue_errors'],
            'blockers' => $snapshot['blockers'],
            'counts' => $counts,
            'entries' => $snapshot['entries'],
        ];
    }

    public function apply(?string $expectedPlanHash = null): void
    {
        $lock = $this->acquireProjectLock();

        try {
            $transactionBlocker = $this->transactionMetadataBlocker();
            if ($transactionBlocker !== null) {
                if ($expectedPlanHash !== null) {
                    throw new ManagedFilePlanBlockedException(
                        'sync.plan_blocked'
                    );
                }
                $this->io->writeError(sprintf(
                    '<warning>CORE preservó los destinos: %s.</warning>',
                    $transactionBlocker
                ));
                $this->writeSummary();
                return;
            }
            $this->recoverInterruptedTransactions();
            $this->reloadStateUnderLock();
            $evaluated = $this->evaluateQueue();
            $snapshot = $this->evaluatedPlanSnapshot($evaluated);
            if (
                $expectedPlanHash !== null
                && $snapshot['status'] !== 'ready'
            ) {
                throw new ManagedFilePlanBlockedException(
                    'sync.plan_blocked'
                );
            }
            if ($expectedPlanHash !== null) {
                $currentPlanHash = $this->hashPayload(
                    $snapshot['hash_payload']
                );
                if (!hash_equals($expectedPlanHash, $currentPlanHash)) {
                    throw new ManagedFilePlanChangedException(
                        'sync.plan_changed'
                    );
                }
            }
            if ($this->stateBlocker !== null) {
                $this->io->writeError(sprintf(
                    '<warning>CORE preservó los destinos: %s.</warning>',
                    $this->stateBlocker
                ));
                $this->writeSummary();
                return;
            }
            if ($this->queue === []) {
                $this->writeSummary();
                return;
            }
            $this->applyUnderLock($evaluated);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, array<string, mixed>> $evaluated */
    private function applyUnderLock(array $evaluated): void
    {
        /** @var array<string, array<string, array<string, mixed>>> $managedGroups */
        $managedGroups = [];

        foreach ($evaluated as $queueKey => $entry) {
            $item = $entry['item'];
            if (
                $item['policy'] === ManagedFileRegistry::POLICY_MANAGED
                && $item['group'] !== null
            ) {
                $managedGroups[$item['group']][$queueKey] = $entry;
            }
        }

        /** @var array<string, true> $appliedGroups */
        $appliedGroups = [];

        foreach ($evaluated as $queueKey => $entry) {
            $item = $entry['item'];
            $plan = $entry['plan'];
            if (
                $item['policy'] !== ManagedFileRegistry::POLICY_MANAGED
                || $item['group'] === null
            ) {
                if (
                    in_array($plan['action'], ['add', 'update', 'merge_json'], true)
                    && !$this->entryMatchesPlanSnapshot($entry)
                ) {
                    $plan['action'] = 'error';
                    $plan['code'] = 'sync.plan_changed';
                    $plan['reason'] = 'el destino cambio durante la sincronizacion';
                }
                if (in_array(
                    $plan['action'],
                    ['add', 'update', 'merge_json'],
                    true
                )) {
                    $this->applyManagedGroup(
                        'standalone:' . hash('sha256', (string) $item['target_id']),
                        [$queueKey => $entry]
                    );
                } else {
                    $this->applyPlan(
                        $item,
                        $plan
                    );
                }
                continue;
            }

            $group = $item['group'];
            if (isset($appliedGroups[$group])) {
                continue;
            }
            $appliedGroups[$group] = true;

            $entries = $managedGroups[$group];
            $groupBlocked = false;
            foreach ($entries as $groupEntry) {
                if (in_array(
                    $groupEntry['plan']['action'],
                    ['preserve_group', 'error'],
                    true
                )) {
                    $groupBlocked = true;
                    break;
                }
            }
            if ($groupBlocked) {
                foreach ($entries as $entry) {
                    $this->applyPlan(
                        $entry['item'],
                        $entry['plan']
                    );
                }
                continue;
            }

            $this->applyManagedGroup($group, $entries);
        }

        $this->writeState();
        $this->writeSummary();
    }

    /**
     * @return array<string, array{item: array<string, mixed>, plan: array<string, mixed>}>
     */
    private function evaluateQueue(): array
    {
        $queue = $this->queue;
        ksort($queue, SORT_STRING);
        $evaluated = [];
        $blockedGroups = [];

        foreach ($queue as $queueKey => $item) {
            $plan = $this->planItem($item);
            $plan['source_hash'] = $plan['source_fingerprints'][0] ?? null;
            $physicalTarget = $this->resolvePhysicalPath($item['target']);
            $physicalTargetRoot = $this->resolvePhysicalPath(
                $item['target_root']
            );
            $physicalTargetKey = $this->normalizeBindingPath($physicalTarget);
            $physicalTargetRootKey = $this->normalizeBindingPath(
                $physicalTargetRoot
            );
            $physicalTargetCollisionKey = $this->collisionPathKey(
                $physicalTarget
            );
            $physicalTargetBinding = $this->physicalTargetBinding(
                $physicalTargetKey,
                $physicalTargetRootKey
            );
            if (
                !$this->isTargetAuthorized($item)
                || !$this->normalizedPathIsWithinRoot(
                    $physicalTargetKey,
                    $physicalTargetRootKey
                )
            ) {
                $plan['action'] = 'error';
                $plan['code'] = 'sync.target_outside_authorized_root';
                $plan['reason'] = 'el destino fisico sale de su raiz autorizada';
            } elseif (
                $item['target_scope'] === 'external'
                && in_array(
                    $plan['action'],
                    ['add', 'update', 'merge_json'],
                    true
                )
                && !$this->sharesTransactionFilesystem($physicalTarget)
            ) {
                $plan['action'] = 'error';
                $plan['code'] = 'sync.external_target_cross_device_unsupported';
                $plan['reason'] = 'el destino atomico externo esta en otro volumen';
            }
            $plan['code'] ??= 'sync.action.' . $plan['action'];
            $snapshot = $this->targetSnapshot($item['target']);
            $plan['target_exists'] = $snapshot['kind'] !== 'missing';
            $plan['target_kind'] = $snapshot['kind'];
            $plan['target_hash'] = $snapshot['hash'];

            if ($snapshot['kind'] === 'unreadable') {
                $plan['action'] = 'error';
                $plan['code'] = 'sync.target_unreadable';
                $plan['reason'] = 'no se pudo fijar la huella del destino';
            }

            $evaluated[$queueKey] = [
                'item' => $item,
                'plan' => $plan,
                'target_binding' => $this->targetAuthorizationBinding($item),
                'physical_target' => $physicalTarget,
                'physical_target_root' => $physicalTargetRoot,
                'physical_target_key' => $physicalTargetKey,
                'physical_target_root_key' => $physicalTargetRootKey,
                'physical_target_collision_key' =>
                    $physicalTargetCollisionKey,
                'physical_target_binding' => $physicalTargetBinding,
            ];

            if (
                $item['policy'] === ManagedFileRegistry::POLICY_MANAGED
                && $item['group'] !== null
                && in_array($plan['action'], ['preserve', 'error'], true)
            ) {
                $blockedGroups[$item['group']] = true;
            }
        }

        /** @var array<string, list<string>> $physicalTargets */
        $physicalTargets = [];
        foreach ($evaluated as $queueKey => $entry) {
            $physicalTargets[$entry['physical_target_collision_key']][] =
                $queueKey;
        }
        foreach ($physicalTargets as $queueKeys) {
            if (count($queueKeys) < 2) {
                continue;
            }
            foreach ($queueKeys as $queueKey) {
                $evaluated[$queueKey]['plan']['action'] = 'error';
                $evaluated[$queueKey]['plan']['code'] =
                    'sync.physical_target_collision';
                $evaluated[$queueKey]['plan']['reason'] =
                    'varios origenes convergen en el mismo destino fisico';
                $item = $evaluated[$queueKey]['item'];
                if (
                    $item['policy'] === ManagedFileRegistry::POLICY_MANAGED
                    && $item['group'] !== null
                ) {
                    $blockedGroups[$item['group']] = true;
                }
            }
        }

        foreach ($evaluated as &$entry) {
            $item = $entry['item'];
            if (
                $item['policy'] !== ManagedFileRegistry::POLICY_MANAGED
                || $item['group'] === null
                || !isset($blockedGroups[$item['group']])
                || $entry['plan']['action'] === 'error'
            ) {
                continue;
            }

            $entry['plan']['action'] = 'preserve_group';
            $entry['plan']['code'] = 'sync.group_preserved';
            $entry['plan']['reason'] = sprintf(
                'el grupo %s contiene personalizaciones locales',
                $item['group']
            );
        }
        unset($entry);

        return $evaluated;
    }

    /**
     * @param array<string, array<string, mixed>> $evaluated
     * @return array{
     *     status: string,
     *     pending_transactions: int,
     *     queue_errors: int,
     *     blockers: list<string>,
     *     entries: list<array<string, mixed>>,
     *     hash_payload: array<string, mixed>
     * }
     */
    private function evaluatedPlanSnapshot(array $evaluated): array
    {
        $entries = [];
        foreach ($evaluated as $entry) {
            $item = $entry['item'];
            $plan = $entry['plan'];
            $entries[] = [
                'source' => $item['source_id'],
                'target' => $item['target_id'],
                'policy' => $item['policy'],
                'group' => $item['group'],
                'track_state' => $item['track_state'],
                'scope' => $item['target_scope'],
                'action' => $plan['action'],
                'code' => $plan['code'] ?? 'sync.unknown',
                'source_fingerprints' => $plan['source_fingerprints'],
                'target_kind' => $plan['target_kind'],
                'target_hash' => $plan['target_hash'],
            ];
        }

        $blockers = $this->blockers();
        foreach ($entries as $entry) {
            if (($entry['action'] ?? null) !== 'error') {
                continue;
            }
            $blockers[] = (string) ($entry['code'] ?? 'sync.action.error');
        }
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);

        $pendingTransactions = $this->pendingTransactionCount();
        $queueErrors = $this->stats['errors'];
        $status = $pendingTransactions === 0
            && $queueErrors === 0
            && $blockers === []
                ? 'ready'
                : 'blocked';
        $hashPayload = [
            'schema' => 1,
            'package' => 'liquidstack/core',
            'protocol' => self::SYNC_PLAN_PROTOCOL,
            'status' => $status,
            'pending_transactions' => $pendingTransactions,
            'queue_errors' => $queueErrors,
            'blockers' => $blockers,
            'state' => $this->stateSnapshot(),
            'destination_binding' => $this->destinationBindingHash(),
            'entries' => $entries,
        ];

        return [
            'status' => $status,
            'pending_transactions' => $pendingTransactions,
            'queue_errors' => $queueErrors,
            'blockers' => $blockers,
            'entries' => $entries,
            'hash_payload' => $hashPayload,
        ];
    }

    /**
     * Liga el plan al proyecto y a los destinos fisicos sin publicar rutas.
     * Tambien cubre overrides externos cuyos IDs logicos son deliberadamente
     * estables, por ejemplo @custom-resources.
     */
    private function destinationBindingHash(): string
    {
        $queue = $this->queue;
        ksort($queue, SORT_STRING);
        $entries = [];

        foreach ($queue as $item) {
            $entries[] = [
                'source' => $this->normalizeBindingPath($item['source']),
                'target' => $this->normalizeBindingPath($item['target']),
                'target_root' => $this->normalizeBindingPath(
                    $item['target_root']
                ),
                'target_scope' => $item['target_scope'],
                'track_state' => $item['track_state'],
            ];
        }

        $planInputs = $this->planInputs;
        ksort($planInputs, SORT_STRING);
        $inputs = [];
        foreach ($planInputs as $id => $path) {
            $inputs[] = [
                'id' => $id,
                'path' => $this->normalizeBindingPath($path),
                'snapshot' => $this->targetSnapshot($path),
            ];
        }

        return $this->hashPayload([
            'schema' => 1,
            'protocol' => self::SYNC_PLAN_PROTOCOL,
            'project_root' => $this->normalizeBindingPath($this->projectRoot),
            'package_root' => $this->normalizeBindingPath($this->packageRoot),
            'state_path' => $this->normalizeBindingPath($this->statePath),
            'entries' => $entries,
            'inputs' => $inputs,
        ]);
    }

    private function normalizeBindingPath(string $path): string
    {
        return $this->resolvePhysicalPath($path);
    }

    private function collisionPathKey(string $path): string
    {
        $resolved = $this->resolvePhysicalPath($path);

        return PHP_OS_FAMILY === 'Windows'
            ? strtolower($resolved)
            : $resolved;
    }

    private function resolvePhysicalPath(string $path): string
    {
        clearstatcache(true);
        $originalDevice = PHP_OS_FAMILY === 'Windows'
            ? $this->windowsPathDeviceIdentity($path)
            : null;
        $resolved = realpath($path);
        if ($resolved === false) {
            $tail = [];
            $ancestor = $path;
            while (!file_exists($ancestor) && !is_link($ancestor)) {
                $parent = dirname($ancestor);
                if (
                    $parent === $ancestor
                    || $originalDevice !== null
                        && $this->windowsPathDeviceIdentity($parent)
                            !== $originalDevice
                ) {
                    break;
                }
                array_unshift($tail, basename($ancestor));
                $ancestor = $parent;
            }

            $resolvedAncestor = realpath($ancestor);
            if ($resolvedAncestor !== false) {
                $resolved = rtrim($resolvedAncestor, '/\\');
                foreach ($tail as $segment) {
                    $resolved .= DIRECTORY_SEPARATOR . $segment;
                }
            }
        }
        $candidate = str_replace(
            '\\',
            '/',
            $resolved !== false ? $resolved : $path
        );
        if (PHP_OS_FAMILY === 'Windows') {
            $candidate = preg_replace(
                '#\A//\?/UNC/#i',
                '//',
                $candidate
            ) ?? $candidate;
            $candidate = preg_replace(
                '#\A//\?/([a-z]:/)#i',
                '$1',
                $candidate
            ) ?? $candidate;
        }
        $isUnc = PHP_OS_FAMILY === 'Windows'
            && str_starts_with($candidate, '//');
        $normalized = Path::canonicalize($candidate);
        if ($isUnc) {
            $normalized = '//' . ltrim($normalized, '/');
        }

        return $normalized;
    }

    /** @param array<string, mixed> $item */
    private function isTargetAuthorized(array $item): bool
    {
        return $this->isPathWithinAuthorizedRoot(
            (string) $item['target'],
            (string) $item['target_root']
        );
    }

    private function isPathWithinAuthorizedRoot(
        string $path,
        string $root
    ): bool {
        $normalizedRoot = rtrim($this->normalizeBindingPath($root), '/');
        $normalizedPath = $this->normalizeBindingPath($path);

        return $normalizedPath === $normalizedRoot
            || str_starts_with($normalizedPath, $normalizedRoot . '/');
    }

    /** @param array<string, mixed> $item */
    private function assertTargetAuthorized(array $item): void
    {
        if (!$this->isTargetAuthorized($item)) {
            throw new \RuntimeException(
                'sync.target_outside_authorized_root'
            );
        }
    }

    /** @param array<string, mixed> $item */
    private function assertTargetBinding(array $item, string $expected): void
    {
        $this->assertTargetAuthorized($item);
        if (
            !hash_equals($expected, $this->targetAuthorizationBinding($item))
            || $item['target_scope'] === 'project'
                && (
                    $this->hasLinkedPathComponent((string) $item['target'])
                    || $this->hasLinkedPathComponent(
                        $this->projectTargetPathForId(
                            (string) $item['target_id']
                        )
                    )
                )
        ) {
            throw new \RuntimeException('sync.target_binding_changed');
        }
    }

    /** @param array<string, mixed> $item */
    private function targetAuthorizationBinding(array $item): string
    {
        return $this->hashPayload([
            'protocol' => self::SYNC_PLAN_PROTOCOL,
            'scope' => $item['target_scope'],
            'root' => $this->normalizeBindingPath($item['target_root']),
            'target' => $this->normalizeBindingPath($item['target']),
        ]);
    }

    private function physicalTargetBinding(
        string $target,
        string $root
    ): string {
        return $this->hashPayload([
            'protocol' => self::SYNC_PLAN_PROTOCOL,
            'scope' => 'physical',
            'root' => $root,
            'target' => $target,
        ]);
    }

    /** @param array<string, mixed> $entry */
    private function assertEntryBindings(array $entry): void
    {
        $this->assertTargetBinding(
            $entry['item'],
            $entry['target_binding']
        );
        $this->assertPhysicalTargetBinding($entry);
    }

    /** @param array<string, mixed> $entry */
    private function assertPhysicalTargetBinding(array $entry): void
    {
        $target = $entry['physical_target'] ?? null;
        $root = $entry['physical_target_root'] ?? null;
        $targetKey = $entry['physical_target_key'] ?? null;
        $rootKey = $entry['physical_target_root_key'] ?? null;
        $binding = $entry['physical_target_binding'] ?? null;
        if (
            !is_string($target)
            || !is_string($root)
            || !is_string($targetKey)
            || !is_string($rootKey)
            || !is_string($binding)
            || !$this->normalizedPathIsWithinRoot($targetKey, $rootKey)
            || $this->normalizeBindingPath($target) !== $targetKey
            || $this->normalizeBindingPath($root) !== $rootKey
            || !hash_equals(
                $binding,
                $this->physicalTargetBinding($targetKey, $rootKey)
            )
        ) {
            throw new \RuntimeException('sync.target_binding_changed');
        }
    }

    private function normalizedPathIsWithinRoot(
        string $path,
        string $root
    ): bool {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);

        return $path === $root || str_starts_with($path, $root . '/');
    }

    private function sharesTransactionFilesystem(string $target): bool
    {
        $transactionDevice = $this->filesystemDeviceIdentity(
            $this->transactionBasePath()
        );
        $targetDevice = $this->filesystemDeviceIdentity($target);

        return $transactionDevice !== null
            && $targetDevice !== null
            && hash_equals($transactionDevice, $targetDevice);
    }

    private function filesystemDeviceIdentity(string $path): ?string
    {
        if ($this->filesystemDeviceResolver !== null) {
            $identity = ($this->filesystemDeviceResolver)($path);

            return is_string($identity) && $identity !== ''
                ? $identity
                : null;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $rawIdentity = $this->windowsPathDeviceIdentity($path);
            if (str_starts_with((string) $rawIdentity, 'unc:')) {
                return $rawIdentity;
            }
            clearstatcache(true);
            $candidate = realpath($path);
            if ($candidate === false) {
                $ancestor = $path;
                while (!file_exists($ancestor) && !is_link($ancestor)) {
                    $parent = dirname($ancestor);
                    if (
                        $parent === $ancestor
                        || $rawIdentity !== null
                            && $this->windowsPathDeviceIdentity($parent)
                                !== $rawIdentity
                    ) {
                        break;
                    }
                    $ancestor = $parent;
                }
                $candidate = realpath($ancestor);
            }

            return $candidate !== false
                ? $this->windowsPathDeviceIdentity($candidate) ?? $rawIdentity
                : $rawIdentity;
        }

        $ancestor = $path;
        while (!file_exists($ancestor) && !is_link($ancestor)) {
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                break;
            }
            $ancestor = $parent;
        }
        $stat = @stat($ancestor);

        return is_array($stat) && isset($stat['dev'])
            ? 'dev:' . (string) $stat['dev']
            : null;
    }

    private function windowsPathDeviceIdentity(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#\A//\?/UNC/#i', '//', $path) ?? $path;
        $path = preg_replace('#\A//\?/([a-z]:/)#i', '$1', $path) ?? $path;
        if (preg_match('/\A([a-z]):(?:\/|\z)/i', $path, $match) === 1) {
            return 'drive:' . strtolower($match[1]);
        }
        if (preg_match(
            '#\A//([^/]+)/([^/]+)(?:/|\z)#',
            $path,
            $match
        ) === 1) {
            return 'unc:' . strtolower($match[1] . '/' . $match[2]);
        }

        return null;
    }

    /** @return array{kind: string, hash: string|null} */
    private function targetSnapshot(string $path): array
    {
        if (is_link($path)) {
            return ['kind' => 'link', 'hash' => null];
        }
        if (!file_exists($path)) {
            return ['kind' => 'missing', 'hash' => null];
        }
        if (!is_file($path)) {
            return ['kind' => 'other', 'hash' => null];
        }

        try {
            return ['kind' => 'file', 'hash' => $this->rawFileHash($path)];
        } catch (\Throwable) {
            return ['kind' => 'unreadable', 'hash' => null];
        }
    }

    /** @return array{kind: string, hash: string|null} */
    private function stateSnapshot(): array
    {
        return $this->targetSnapshot($this->statePath);
    }

    private function pendingTransactionCount(): int
    {
        $root = $this->transactionBasePath();
        if ($this->hasLinkedPathComponent($root)) {
            return 1;
        }
        if (!$this->filesystemEntryExists($root)) {
            return 0;
        }
        if (!is_dir($root) || is_link($root)) {
            return 1;
        }

        $entries = @scandir($root);
        if (!is_array($entries)) {
            return 1;
        }

        return count(array_filter(
            $entries,
            static fn (string $entry): bool => !in_array(
                $entry,
                ['.', '..', '.gitignore'],
                true
            )
        ));
    }

    private function transactionMetadataBlocker(): ?string
    {
        $root = $this->transactionBasePath();
        if ($this->hasLinkedPathComponent($root)) {
            return 'sync.transaction_root_invalid';
        }
        if (!$this->filesystemEntryExists($root)) {
            return null;
        }
        if (!is_dir($root) || is_link($root)) {
            return 'sync.transaction_root_invalid';
        }

        $gitIgnore = $root . DIRECTORY_SEPARATOR . '.gitignore';
        if (!$this->filesystemEntryExists($gitIgnore)) {
            return null;
        }
        if (
            !is_file($gitIgnore)
            || is_link($gitIgnore)
            || @file_get_contents($gitIgnore) !== "*\n"
        ) {
            return 'sync.transaction_gitignore_invalid';
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function hashPayload(array $payload): string
    {
        return 'sha256:' . hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        ));
    }

    /** @param array<string, array<string, mixed>> $entries */
    private function applyManagedGroup(string $group, array $entries): void
    {
        $mutations = array_filter(
            $entries,
            static fn (array $entry): bool => in_array(
                $entry['plan']['action'],
                ['add', 'update', 'merge_json'],
                true
            )
        );

        if ($mutations === []) {
            foreach ($entries as $entry) {
                $this->applyPlan(
                    $entry['item'],
                    $entry['plan']
                );
            }
            return;
        }

        $transactionRoot = '';
        $files = [];
        $stateBefore = $this->stateFiles;
        $statsBefore = $this->stats;

        try {
            $transactionRoot = $this->createTransactionRoot($group);
            $index = 0;

            foreach ($mutations as $queueKey => $entry) {
                ++$index;
                $item = $entry['item'];
                $plan = $entry['plan'];
                $this->assertTargetAuthorized($item);
                $this->assertEntryBindings($entry);
                $target = (string) $entry['physical_target'];
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

                if ($plan['action'] === 'merge_json') {
                    [$patched, $changed] = $this->prepareJsonAdditiveMerge(
                        (string) $item['source'],
                        $target
                    );
                    if (!$changed) {
                        throw new \RuntimeException('sync.plan_changed');
                    }
                    $this->filesystem->dumpFile($staged, $patched);
                    $expectedHash = 'sha256:' . hash('sha256', $patched);
                } else {
                    $expectedHash = $plan['source_hash'] ?? null;
                    if (!is_string($expectedHash)) {
                        throw new \RuntimeException('sync.canonical_source_changed');
                    }
                    $this->filesystem->copy($item['source'], $staged, true);
                }
                if (
                    !is_file($staged)
                    || is_link($staged)
                    || $this->rawFileHash($staged) !== $expectedHash
                    || !$this->sameFileHashSet(
                        ManagedFileRegistry::fingerprintFile(
                            (string) $item['source']
                        ),
                        $plan['source_fingerprints']
                    )
                ) {
                    throw new \RuntimeException(sprintf(
                        'el staging de %s no coincide con el plan',
                        $item['target_id']
                    ));
                }

                $files[$queueKey] = [
                    'target' => $target,
                    'logical_target' => $item['target'],
                    'target_id' => $item['target_id'],
                    'staged' => $staged,
                    'backup' => $backup,
                    'had_target' => in_array(
                        $plan['action'],
                        ['update', 'merge_json'],
                        true
                    ),
                    'original_hash' => $plan['target_hash'] ?? null,
                    'expected_hash' => $expectedHash,
                    'approved_source_hash' => $plan['source_hash'] ?? null,
                    'slot' => $index,
                    'target_scope' => $item['target_scope'],
                    'target_root' => $item['target_root'],
                    'authorization_binding' => $entry['target_binding'],
                    'physical_target_root' => $entry['physical_target_root'],
                    'physical_target_key' => $entry['physical_target_key'],
                    'physical_target_root_key' =>
                        $entry['physical_target_root_key'],
                    'physical_authorization_binding' =>
                        $entry['physical_target_binding'],
                    'target_binding' => $item['target_scope'] === 'external'
                        ? $entry['target_binding']
                        : null,
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
                            !== $files[$queueKey]['approved_source_hash']
                ) {
                    throw new \RuntimeException(sprintf(
                        'el destino %s cambió durante la sincronización',
                        $entry['item']['target_id']
                    ));
                }

                if (isset($files[$queueKey])) {
                    $this->assertEntryBindings($entry);
                    $this->filesystem->mkdir(
                        dirname($files[$queueKey]['target']),
                        0775
                    );
                    $this->assertEntryMutationSnapshot($entry);
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

                $this->assertEntryMutationSnapshot($entry);
                $this->filesystem->rename(
                    $files[$queueKey]['target'],
                    $files[$queueKey]['backup']
                );
                $this->assertEntryBindings($entry);
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
                $this->assertEntryBindings($entry);
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
                $this->assertEntryBindings($entry);
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

            foreach ($files as $queueKey => $file) {
                $this->assertEntryBindings($entries[$queueKey]);
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
        } catch (\Throwable $exception) {
            $this->stateFiles = $stateBefore;
            $this->stats = $statsBefore;

            if ($transactionRoot !== '') {
                try {
                    $this->recoverTransactionRoot(
                        $transactionRoot,
                        $files !== [] ? array_values($files) : null
                    );
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
            return;
        }

        // Desde que el journal `committed` es durable, los destinos nuevos son
        // la versión autoritativa. El cleanup no puede convertir ese commit en
        // un falso rollback ni impedir que se persista su estado gestionado.
        foreach ($entries as $entry) {
            $this->recordSuccessfulManagedPlan(
                $entry['item'],
                $entry['plan']
            );
        }

        try {
            $this->writeTransactionJournal(
                $transactionRoot,
                $group,
                'cleanup_pending',
                $files
            );
            $this->removeTransactionRoot($transactionRoot);
        } catch (\Throwable $cleanupException) {
            $this->io->writeError(sprintf(
                '<warning>CORE confirmó el grupo %s, pero aplazó su limpieza transaccional: %s</warning>',
                $group,
                $cleanupException->getMessage()
            ));
        }
    }

    /** @param array<string, mixed> $entry */
    private function entryMatchesPlanSnapshot(array $entry): bool
    {
        if (
            !$this->isTargetAuthorized($entry['item'])
            || !is_string($entry['target_binding'] ?? null)
            || !hash_equals(
                $entry['target_binding'],
                $this->targetAuthorizationBinding($entry['item'])
            )
        ) {
            return false;
        }
        try {
            $this->assertPhysicalTargetBinding($entry);
        } catch (\Throwable) {
            return false;
        }
        $currentPlan = $this->planItem($entry['item']);
        $target = (string) $entry['physical_target'];
        $targetExists = file_exists($target) || is_link($target);
        $targetHash = $targetExists
            && is_file($target)
            && !is_link($target)
            ? $this->rawFileHash($target)
            : null;

        return $currentPlan['action'] === $entry['plan']['action']
            && $targetExists === ($entry['plan']['target_exists'] ?? null)
            && $targetHash === ($entry['plan']['target_hash'] ?? null)
            && $this->sameFileHashSet(
                $currentPlan['source_fingerprints'],
                $entry['plan']['source_fingerprints']
            );
    }

    /** @param array<string, mixed> $entry */
    private function assertEntryMutationSnapshot(array $entry): void
    {
        $this->assertEntryBindings($entry);
        $snapshot = $this->targetSnapshot(
            (string) $entry['physical_target']
        );
        if (
            $snapshot['kind'] !== ($entry['plan']['target_kind'] ?? null)
            || $snapshot['hash'] !== ($entry['plan']['target_hash'] ?? null)
            || !$this->sameFileHashSet(
                ManagedFileRegistry::fingerprintFile(
                    (string) $entry['item']['source']
                ),
                $entry['plan']['source_fingerprints']
            )
        ) {
            throw new \RuntimeException('sync.plan_changed');
        }
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
        $this->stateBlocker = null;
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
        if (!$this->filesystemEntryExists($root)) {
            return;
        }
        if (!is_dir($root) || is_link($root)) {
            throw new \RuntimeException(
                'la ruta de transacciones pendientes no es un directorio regular'
            );
        }
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
        } while ($this->filesystemEntryExists($transactionRoot));

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
        if ($this->filesystemEntryExists($path)) {
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
                'target_scope' => $file['target_scope'] ?? 'project',
                'target_binding' => $file['target_binding'] ?? null,
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

    /** @param list<array<string, mixed>>|null $runtimeFiles */
    private function recoverTransactionRoot(
        string $transactionRoot,
        ?array $runtimeFiles = null
    ): void {
        $journalPath = $transactionRoot
            . DIRECTORY_SEPARATOR
            . 'journal.json';
        if (!is_file($journalPath) || is_link($journalPath)) {
            $entries = !is_link($transactionRoot) && is_dir($transactionRoot)
                ? scandir($transactionRoot)
                : false;
            if ($entries !== false && count($entries) === 2) {
                $this->removeTransactionRoot($transactionRoot);
                return;
            }
            throw new \RuntimeException(
                'la transacción pendiente no contiene un journal regular'
            );
        }
        $this->assertTransactionCleanupLayout($transactionRoot);
        $journal = $this->decodeJsonFile($journalPath);
        if (
            !is_array($journal)
            || ($journal['schema'] ?? null) !== 1
            || !is_string($journal['group'] ?? null)
            || !in_array(
                $journal['status'] ?? null,
                ['staging', 'prepared', 'committed', 'cleanup_pending'],
                true
            )
            || !is_array($journal['files'] ?? null)
            || !array_is_list($journal['files'])
        ) {
            throw new \RuntimeException(
                'el journal de la transacción pendiente es inválido'
            );
        }
        $journalFiles = $this->validatedJournalFilesForCleanup(
            $journal['files']
        );

        if ($journal['status'] === 'staging') {
            $this->removeTransactionRoot($transactionRoot, true);
            return;
        }
        if ($journal['status'] === 'cleanup_pending') {
            $this->removeTransactionRoot($transactionRoot);
            return;
        }
        if ($journal['status'] === 'committed') {
            // `committed` es terminal: el destino ya pertenece al consumidor y
            // puede haberse personalizado o eliminado antes del siguiente run.
            // La recuperación sólo debe cerrar el scaffold transaccional.
            $this->writeTransactionJournal(
                $transactionRoot,
                $journal['group'],
                'cleanup_pending',
                $journalFiles
            );
            $this->removeTransactionRoot($transactionRoot);
            return;
        }

        $files = $runtimeFiles ?? $this->transactionFilesFromJournal(
            $transactionRoot,
            $journalFiles
        );

        $this->assertTransactionCleanupLayout($transactionRoot);
        $errors = $this->rollbackTransactionFiles($files);
        if ($errors !== []) {
            throw new \RuntimeException(implode('; ', $errors));
        }
        $this->removeTransactionRoot($transactionRoot, true);
    }

    /** @return list<array<string, mixed>> */
    private function validatedJournalFilesForCleanup(array $journalFiles): array
    {
        $validated = [];
        $slots = [];
        $targetIds = [];

        foreach ($journalFiles as $entry) {
            if (
                !is_array($entry)
                || !is_string($entry['target_id'] ?? null)
                || !is_int($entry['slot'] ?? null)
                || $entry['slot'] < 1
                || !is_bool($entry['had_target'] ?? null)
                || !in_array(
                    $entry['target_scope'] ?? 'project',
                    ['project', 'external'],
                    true
                )
                || (
                    ($entry['target_scope'] ?? 'project') === 'external'
                        ? !$this->isRawFileHash(
                            $entry['target_binding'] ?? null
                        )
                        : array_key_exists('target_binding', $entry)
                            && $entry['target_binding'] !== null
                )
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

            $targetId = ManagedFileRegistry::normalizePath(
                $entry['target_id']
            );
            if (($entry['target_scope'] ?? 'project') === 'project') {
                $this->projectTargetPathForId($targetId);
            }
            if (
                isset($slots[$entry['slot']])
                || isset($targetIds[$targetId])
            ) {
                throw new \RuntimeException(
                    'el journal repite un slot o destino de transacción'
                );
            }
            $slots[$entry['slot']] = true;
            $targetIds[$targetId] = true;
            $entry['target_id'] = $targetId;
            $entry['target_scope'] ??= 'project';
            $entry['target_binding'] ??= null;
            $validated[] = $entry;
        }

        return $validated;
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
                || !in_array(
                    $entry['target_scope'] ?? 'project',
                    ['project', 'external'],
                    true
                )
                || (
                    array_key_exists('target_binding', $entry)
                    && $entry['target_binding'] !== null
                    && !$this->isRawFileHash($entry['target_binding'])
                )
                || (
                    ($entry['target_scope'] ?? 'project') === 'external'
                    && !$this->isRawFileHash(
                        $entry['target_binding'] ?? null
                    )
                )
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
            $targetScope = $entry['target_scope'] ?? 'project';
            $targetBinding = $entry['target_binding'] ?? null;
            if (is_string($targetBinding)) {
                $item = $this->queuedItemForTargetId($targetId);
                if (
                    $item['target_scope'] !== $targetScope
                    || !$this->isTargetAuthorized($item)
                    || !hash_equals(
                        $targetBinding,
                        $this->targetAuthorizationBinding($item)
                    )
                ) {
                    throw new \RuntimeException(
                        'el destino del journal ya no coincide con la cola'
                    );
                }
                $logicalTarget = $item['target'];
                $targetRoot = $item['target_root'];
                $authorizationBinding = $targetBinding;
            } else {
                $logicalTarget = $this->projectTargetForId($targetId);
                $targetRoot = $this->projectRoot;
                $authorizationBinding = $this->targetAuthorizationBinding([
                    'target' => $logicalTarget,
                    'target_id' => $targetId,
                    'target_root' => $targetRoot,
                    'target_scope' => 'project',
                ]);
            }
            $target = $this->resolvePhysicalPath($logicalTarget);
            $physicalTargetRoot = $this->resolvePhysicalPath($targetRoot);
            $physicalTargetKey = $this->normalizeBindingPath($target);
            $physicalTargetRootKey = $this->normalizeBindingPath(
                $physicalTargetRoot
            );
            if (!$this->normalizedPathIsWithinRoot(
                $physicalTargetKey,
                $physicalTargetRootKey
            )) {
                throw new \RuntimeException(
                    'el destino fisico del journal sale de su raiz autorizada'
                );
            }
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
                'logical_target' => $logicalTarget,
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
                'target_scope' => $targetScope,
                'target_root' => $targetRoot,
                'authorization_binding' => $authorizationBinding,
                'physical_target_root' => $physicalTargetRoot,
                'physical_target_key' => $physicalTargetKey,
                'physical_target_root_key' => $physicalTargetRootKey,
                'physical_authorization_binding' => $this->physicalTargetBinding(
                    $physicalTargetKey,
                    $physicalTargetRootKey
                ),
                'target_binding' => $targetBinding,
            ];
        }

        return $files;
    }

    /** @return array<string, mixed> */
    private function queuedItemForTargetId(string $targetId): array
    {
        $matches = array_values(array_filter(
            $this->queue,
            static fn (array $item): bool => $item['target_id'] === $targetId
        ));
        if (count($matches) !== 1) {
            throw new \RuntimeException(
                'el destino del journal no existe de forma univoca en la cola'
            );
        }

        return $matches[0];
    }

    /**
     * @param list<array<string, mixed>> $files
     * @return list<string>
     */
    private function rollbackTransactionFiles(array $files): array
    {
        $errors = [];

        foreach (array_reverse($files) as $file) {
            try {
                $this->assertTransactionFileTargetBinding($file);
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
                continue;
            }
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
                        $this->removeInstalledTransactionTarget($file);
                    }
                    $this->assertTransactionFileTargetBinding($file);
                    $this->filesystem->rename(
                        $file['backup'],
                        $file['target']
                    );
                    $this->assertTransactionFileTargetBinding($file);
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
                $this->removeInstalledTransactionTarget($file);
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        return $errors;
    }

    private function projectTargetForId(string $targetId): string
    {
        $target = $this->projectTargetPathForId($targetId);
        if ($this->hasLinkedPathComponent($target)) {
            throw new \RuntimeException(
                'el journal contiene una ruta enlazada o externa'
            );
        }

        return $target;
    }

    private function projectTargetPathForId(string $targetId): string
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
        $this->assertTransactionCleanupLayout($transactionRoot);
        $exception = null;

        for (
            $attempt = 1;
            $attempt <= self::TRANSACTION_CLEANUP_ATTEMPTS;
            ++$attempt
        ) {
            try {
                $this->assertTransactionCleanupLayout($transactionRoot);
                // El journal se retira al final. Si PHP se interrumpe durante
                // el cleanup, la siguiente ejecución conserva el marcador de
                // limpieza o encuentra un scaffold completamente vacío.
                foreach (['staged', 'backup'] as $directory) {
                    $this->removeTransactionSlotDirectory(
                        $transactionRoot . DIRECTORY_SEPARATOR . $directory
                    );
                }
                $journalPath = $transactionRoot
                    . DIRECTORY_SEPARATOR
                    . 'journal.json';
                $journalContents = null;
                if ($this->filesystemEntryExists($journalPath)) {
                    if (
                        !is_file($journalPath)
                        || $this->isRedirectingFilesystemEntry($journalPath)
                    ) {
                        throw new \UnexpectedValueException(
                            'sync.transaction_cleanup_layout_invalid'
                        );
                    }
                    $journalContents = @file_get_contents($journalPath);
                    if (!is_string($journalContents)) {
                        throw new \RuntimeException(
                            'no se pudo conservar el journal transaccional'
                        );
                    }
                    $this->removeRegularTransactionFile($journalPath);
                }
                if (is_dir($transactionRoot) && !@rmdir($transactionRoot)) {
                    if ($journalContents !== null) {
                        $this->restoreTransactionJournalMarker(
                            $journalPath,
                            $journalContents
                        );
                    }
                    throw new \RuntimeException(
                        'no se pudo retirar el directorio transaccional'
                    );
                }
                return;
            } catch (\UnexpectedValueException $unsafeLayout) {
                throw $unsafeLayout;
            } catch (\Throwable $cleanupException) {
                $exception = $cleanupException;
                clearstatcache();
                if ($attempt < self::TRANSACTION_CLEANUP_ATTEMPTS) {
                    usleep(
                        self::TRANSACTION_CLEANUP_DELAY_US
                        * (2 ** ($attempt - 1))
                    );
                }
            }
        }

        if ($strict && $exception !== null) {
            throw $exception;
        }
        if ($exception !== null) {
            $this->io->writeError(sprintf(
                '<warning>Limpieza aplazada de CORE %s: %s</warning>',
                $transactionRoot,
                $exception->getMessage()
            ));
        }
    }

    private function assertTransactionCleanupLayout(
        string $transactionRoot
    ): void {
        $base = $this->transactionBasePath();
        if (
            !$this->samePath(dirname($transactionRoot), $base)
            || preg_match('/\A[a-f0-9]{24}\z/', basename($transactionRoot)) !== 1
            || $this->hasLinkedPathComponent($transactionRoot)
        ) {
            throw new \UnexpectedValueException(
                'sync.transaction_cleanup_layout_invalid'
            );
        }
        if (!$this->filesystemEntryExists($transactionRoot)) {
            return;
        }
        if (
            !is_dir($transactionRoot)
            || $this->isRedirectingFilesystemEntry($transactionRoot)
        ) {
            throw new \UnexpectedValueException(
                'sync.transaction_cleanup_layout_invalid'
            );
        }

        $entries = @scandir($transactionRoot);
        if (!is_array($entries)) {
            throw new \UnexpectedValueException(
                'sync.transaction_cleanup_layout_invalid'
            );
        }
        foreach ($entries as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }
            $path = $transactionRoot . DIRECTORY_SEPARATOR . $entry;
            if ($entry === 'journal.json') {
                if (
                    !is_file($path)
                    || $this->isRedirectingFilesystemEntry($path)
                ) {
                    throw new \UnexpectedValueException(
                        'sync.transaction_cleanup_layout_invalid'
                    );
                }
                continue;
            }
            if (!in_array($entry, ['staged', 'backup'], true)) {
                throw new \UnexpectedValueException(
                    'sync.transaction_cleanup_layout_invalid'
                );
            }
            if (
                !is_dir($path)
                || $this->isRedirectingFilesystemEntry($path)
            ) {
                throw new \UnexpectedValueException(
                    'sync.transaction_cleanup_layout_invalid'
                );
            }
            $slots = @scandir($path);
            if (!is_array($slots)) {
                throw new \UnexpectedValueException(
                    'sync.transaction_cleanup_layout_invalid'
                );
            }
            foreach ($slots as $slot) {
                if (in_array($slot, ['.', '..'], true)) {
                    continue;
                }
                $slotPath = $path . DIRECTORY_SEPARATOR . $slot;
                if (
                    preg_match('/\A[1-9][0-9]*\z/', $slot) !== 1
                    || !is_file($slotPath)
                    || $this->isRedirectingFilesystemEntry($slotPath)
                ) {
                    throw new \UnexpectedValueException(
                        'sync.transaction_cleanup_layout_invalid'
                    );
                }
            }
        }
    }

    private function removeTransactionSlotDirectory(string $path): void
    {
        if (!$this->filesystemEntryExists($path)) {
            return;
        }
        if (!is_dir($path) || $this->isRedirectingFilesystemEntry($path)) {
            throw new \UnexpectedValueException(
                'sync.transaction_cleanup_layout_invalid'
            );
        }
        $entries = @scandir($path);
        if (!is_array($entries)) {
            throw new \UnexpectedValueException(
                'sync.transaction_cleanup_layout_invalid'
            );
        }
        foreach ($entries as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }
            $slotPath = $path . DIRECTORY_SEPARATOR . $entry;
            if (
                preg_match('/\A[1-9][0-9]*\z/', $entry) !== 1
                || !is_file($slotPath)
                || $this->isRedirectingFilesystemEntry($slotPath)
            ) {
                throw new \UnexpectedValueException(
                    'sync.transaction_cleanup_layout_invalid'
                );
            }
            $this->removeRegularTransactionFile($slotPath);
        }
        if (!@rmdir($path)) {
            throw new \RuntimeException(
                'no se pudo retirar un directorio transaccional'
            );
        }
    }

    private function restoreTransactionJournalMarker(
        string $journalPath,
        string $contents
    ): void {
        if ($this->filesystemEntryExists($journalPath)) {
            if (
                is_file($journalPath)
                && !$this->isRedirectingFilesystemEntry($journalPath)
                && @file_get_contents($journalPath) === $contents
            ) {
                return;
            }
            throw new \UnexpectedValueException(
                'sync.transaction_cleanup_layout_invalid'
            );
        }

        $handle = @fopen($journalPath, 'x+b');
        if (!is_resource($handle)) {
            if (
                is_file($journalPath)
                && !$this->isRedirectingFilesystemEntry($journalPath)
                && @file_get_contents($journalPath) === $contents
            ) {
                return;
            }
            throw new \RuntimeException(
                'no se pudo restaurar el journal transaccional'
            );
        }

        $written = 0;
        $length = strlen($contents);
        try {
            while ($written < $length) {
                $chunk = @fwrite($handle, substr($contents, $written));
                if (!is_int($chunk) || $chunk < 1) {
                    throw new \RuntimeException(
                        'no se pudo restaurar el journal transaccional'
                    );
                }
                $written += $chunk;
            }
            if (!@fflush($handle)) {
                throw new \RuntimeException(
                    'no se pudo confirmar el journal transaccional'
                );
            }
        } finally {
            fclose($handle);
        }
    }

    private function removeRegularTransactionFile(string $path): void
    {
        $this->unlinkRegularPath(
            $path,
            null,
            true,
            'no se pudo retirar un fichero transaccional'
        );
    }

    /** @param array<string, mixed> $file */
    private function removeInstalledTransactionTarget(array $file): void
    {
        $this->unlinkRegularPath(
            $file['target'],
            $file['expected_hash'],
            false,
            sprintf(
                'se preservó contenido concurrente en %s',
                $file['target_id']
            ),
            false,
            function () use ($file): void {
                $this->assertTransactionFileTargetBinding($file);
            }
        );
    }

    /** @param array<string, mixed> $file */
    private function assertTransactionFileTargetBinding(array $file): void
    {
        $this->assertPhysicalTargetBinding([
            'physical_target' => $file['target'],
            'physical_target_root' => $file['physical_target_root'],
            'physical_target_key' => $file['physical_target_key'],
            'physical_target_root_key' => $file['physical_target_root_key'],
            'physical_target_binding' =>
                $file['physical_authorization_binding'],
        ]);
    }

    private function unlinkRegularPath(
        string $path,
        ?string $expectedHash,
        bool $layoutViolation,
        string $failureMessage,
        bool $missingIsSuccess = true,
        ?\Closure $bindingValidator = null
    ): void {
        if (!$this->filesystemEntryExists($path)) {
            if ($missingIsSuccess) {
                return;
            }
            throw new \RuntimeException($failureMessage);
        }
        if ($bindingValidator !== null) {
            $bindingValidator();
        }
        $this->assertRegularUnlinkCandidate(
            $path,
            $expectedHash,
            $layoutViolation,
            $failureMessage
        );
        if ($this->transactionUnlinkObserver !== null) {
            ($this->transactionUnlinkObserver)('before', $path);
        }
        // Revalidar después del seam y justo antes de unlink cierra el cambio
        // de tipo reproducible. unlink nunca recorre un directorio o junction.
        if ($bindingValidator !== null) {
            $bindingValidator();
        }
        $this->assertRegularUnlinkCandidate(
            $path,
            $expectedHash,
            $layoutViolation,
            $failureMessage
        );
        if (!@unlink($path)) {
            throw new \RuntimeException($failureMessage);
        }
        if ($this->transactionUnlinkObserver !== null) {
            ($this->transactionUnlinkObserver)('after', $path);
        }
        if ($this->filesystemEntryExists($path)) {
            throw new \RuntimeException($failureMessage);
        }
    }

    private function assertRegularUnlinkCandidate(
        string $path,
        ?string $expectedHash,
        bool $layoutViolation,
        string $failureMessage
    ): void {
        $valid = is_file($path)
            && ($layoutViolation
                ? !$this->isRedirectingFilesystemEntry($path)
                : !is_link($path))
            && (
                $expectedHash === null
                || $this->rawFileHash($path) === $expectedHash
            );
        if ($valid) {
            return;
        }
        if ($layoutViolation) {
            throw new \UnexpectedValueException(
                'sync.transaction_cleanup_layout_invalid'
            );
        }
        throw new \RuntimeException($failureMessage);
    }

    private function filesystemEntryExists(string $path): bool
    {
        clearstatcache(true, $path);

        return @lstat($path) !== false;
    }

    private function isRedirectingFilesystemEntry(string $path): bool
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            return true;
        }
        $resolved = realpath($path);

        return $resolved !== false
            && !$this->sameExistingPathWithCanonicalCase($path, $resolved);
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
        } elseif ($plan['action'] === 'merge_json') {
            ++$this->stats['merged'];
            return;
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
                'code' => 'sync.canonical_source_missing',
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
                'code' => 'sync.canonical_source_unreadable',
                'reason' => $exception->getMessage(),
                'source_fingerprints' => [],
            ];
        }

        if (
            $item['policy']
                === ManagedFileRegistry::POLICY_MERGE_JSON_ADDITIVE
        ) {
            try {
                $this->readJsonObjectFile($item['source']);
            } catch (\Throwable $exception) {
                return [
                    'action' => 'error',
                    'code' => 'sync.merge_json_source_invalid',
                    'reason' => $exception->getMessage(),
                    'source_fingerprints' => $sourceFingerprints,
                ];
            }
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
            try {
                [, $changed] = $this->prepareJsonAdditiveMerge(
                    $item['source'],
                    $item['target']
                );
            } catch (\Throwable $exception) {
                return [
                    'action' => 'error',
                    'code' => 'sync.merge_json_target_invalid',
                    'reason' => $exception->getMessage(),
                    'source_fingerprints' => $sourceFingerprints,
                ];
            }

            return [
                'action' => $changed ? 'merge_json' : 'unchanged',
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

        $stateMatchesSource =
            is_array($stateEntry)
            && ($stateEntry['source'] ?? null) === $item['source_id'];

        if ($stateMatchesSource) {
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

        if ($stateMatchesSource) {
            return [
                'action' => 'preserve',
                'reason' => 'se modificó después de instalarlo CORE',
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
    private function applyPlan(
        array $item,
        array $plan
    ): void
    {
        try {
            switch ($plan['action']) {
                case 'add':
                case 'update':
                case 'merge_json':
                    throw new \RuntimeException('sync.transaction_required');

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

    /** @param array<string, mixed> $item */
    /** @return array{0: string, 1: bool} */
    private function prepareJsonAdditiveMerge(
        string $source,
        string $target
    ): array {
        [, $sourceData] = $this->readJsonObjectFile($source);
        [$targetRaw, $targetData] = $this->readJsonObjectFile($target);
        [$merged, $changed] = $this->mergeMissingJsonValues(
            $targetData,
            $sourceData
        );

        return [
            $changed
                ? $this->patchJsonAdditively(
                    $targetRaw,
                    $targetData,
                    $merged
                )
                : $targetRaw,
            $changed,
        ];
    }

    /** @return array{0: string, 1: array<mixed>} */
    private function readJsonObjectFile(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(
                'no se pudo leer uno de los catalogos JSON'
            );
        }
        $json = $this->stripUtf8Bom($raw);

        try {
            $root = json_decode(
                $json,
                false,
                512,
                JSON_THROW_ON_ERROR
            );
            $data = json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'se preservo un catalogo JSON invalido',
                0,
                $exception
            );
        }

        if (!$root instanceof \stdClass || !is_array($data)) {
            throw new \RuntimeException(
                'se preservo un catalogo cuyo nivel raiz no es un objeto'
            );
        }

        return [$raw, $data];
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
            $this->block('sync.history_invalid');
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
            $this->block('sync.history_invalid');
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

        if ($this->hasLinkedPathComponent($statePath)) {
            $this->stateWritable = false;
            $this->stateBlocker = 'sync.state_invalid';
            $this->io->writeError(sprintf(
                '<warning>Se preservó el manifiesto CORE porque su ruta '
                    . 'contiene un enlace: %s</warning>',
                $statePath
            ));
            return;
        }

        if (!$this->isPathWithinAuthorizedRoot($statePath, $this->projectRoot)) {
            $this->stateWritable = false;
            $this->stateBlocker = 'sync.state_unwritable';
            $this->io->writeError(
                '<warning>Se preservó el manifiesto CORE fuera de su raíz autorizada.</warning>'
            );
            return;
        }

        if (!file_exists($statePath) && !is_link($statePath)) {
            $ancestor = dirname($statePath);
            while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
                $ancestor = dirname($ancestor);
            }
            if (!is_dir($ancestor) || !is_writable($ancestor)) {
                $this->stateWritable = false;
                $this->stateBlocker = 'sync.state_unwritable';
            }
            return;
        }

        if (
            !is_file($statePath)
            || is_link($statePath)
            || !is_writable($statePath)
        ) {
            $this->stateWritable = false;
            $this->stateBlocker = !is_file($statePath) || is_link($statePath)
                ? 'sync.state_invalid'
                : 'sync.state_unwritable';
            $this->io->writeError(sprintf(
                '<warning>Se preservó el manifiesto CORE no regular o no '
                    . 'escribible: %s</warning>',
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
            $this->stateBlocker = 'sync.state_invalid';
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
            $this->stateBlocker = 'sync.state_invalid';
            throw new \RuntimeException(
                'sync.state_invalid'
            );
        }

        if (file_exists($this->statePath) || is_link($this->statePath)) {
            if (
                !is_file($this->statePath)
                || is_link($this->statePath)
                || !is_writable($this->statePath)
            ) {
                $this->stateWritable = false;
                $this->stateBlocker = !is_file($this->statePath)
                    || is_link($this->statePath)
                        ? 'sync.state_invalid'
                        : 'sync.state_unwritable';
                throw new \RuntimeException($this->stateBlocker);
            }
        } else {
            $ancestor = dirname($this->statePath);
            while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
                $ancestor = dirname($ancestor);
            }
            if (!is_dir($ancestor) || !is_writable($ancestor)) {
                $this->stateWritable = false;
                $this->stateBlocker = 'sync.state_unwritable';
                throw new \RuntimeException($this->stateBlocker);
            }
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
            return rtrim($path, '/');
        };

        return $normalize($left) === $normalize($right);
    }

    private function sameExistingPathWithCanonicalCase(
        string $left,
        string $right
    ): bool {
        if ($this->samePath($left, $right)) {
            return true;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        $normalize = static fn (string $path): string => rtrim(
            str_replace('\\', '/', $path),
            '/'
        );
        if (strcasecmp($normalize($left), $normalize($right)) !== 0) {
            return false;
        }

        $leftStat = @stat($left);
        $rightStat = @stat($right);

        return is_array($leftStat)
            && is_array($rightStat)
            && ($leftStat['dev'] ?? null) === ($rightStat['dev'] ?? null)
            && ($leftStat['ino'] ?? null) === ($rightStat['ino'] ?? null);
    }

    private function hasLinkedPathComponent(string $path): bool
    {
        clearstatcache(true);
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
            && !$this->sameExistingPathWithCanonicalCase(
                $current,
                $resolvedRoot
            )
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
                    && !$this->sameExistingPathWithCanonicalCase(
                        $current,
                        $resolved
                    )
            ) {
                return true;
            }
        }

        return false;
    }
}
