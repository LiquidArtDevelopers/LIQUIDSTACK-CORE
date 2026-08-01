<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use InvalidArgumentException;

final class MigrationDefinition
{
    private const SQL_SCHEMA = 1;
    private const SQL_WITH_POSTCONDITION_SCHEMA = 2;
    private const SQL_WITH_POSTCONDITION_SUPERSESSION_SCHEMA = 3;
    private const SQL_WITH_PRECONDITION_SCHEMA = 4;
    private const SQL_WITH_TARGET_SCOPE_SCHEMA = 5;
    private const SUPPORTED_DRIVERS = ['mysql', 'sqlite'];

    /** @var array<string, list<string>> */
    private array $statementsByDriver = [];

    /** @var list<string> */
    private array $transactionalDrivers = [];

    private bool $retrySafe = false;

    private ?MigrationPostconditionVerifierInterface $postconditionVerifier = null;

    private ?MigrationPreconditionVerifierInterface $preconditionVerifier = null;

    /** @var list<string> */
    private array $supersededPostconditionIds = [];

    public function __construct(
        private readonly string $id,
        private readonly string $description,
        private readonly string $checksum,
        private readonly bool $destructive = false,
        private readonly ?string $targetScopeModuleId = null
    ) {
        if (
            strlen($id) > 190
            || preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $id) !== 1
        ) {
            throw new InvalidArgumentException(sprintf(
                'El identificador de migración %s no es válido.',
                $id
            ));
        }

        if (
            trim($description) === ''
            || preg_match('//u', $description) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $description) === 1
        ) {
            throw new InvalidArgumentException(sprintf(
                'La migración %s necesita una descripción válida.',
                $id
            ));
        }

        if (preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'La migración %s necesita un checksum SHA-256 en minúsculas.',
                $id
            ));
        }

        self::assertTargetScopeModuleId($id, $targetScopeModuleId);
    }

    /**
     * Builds an executable migration from an immutable SQL contract. The
     * checksum is derived from the canonical contract and cannot be supplied
     * by the caller.
     *
     * Statements are complete PDO::exec units; the runner never splits SQL.
     * The only supported identifier token is {{table:suffix}}.
     * A target scope is opt-in and is authorized later against the owning
     * module's active transitive dependencies by MigrationCatalog.
     *
     * @param array{mysql: list<string>, sqlite: list<string>} $statementsByDriver
     * @param list<'mysql'|'sqlite'> $transactionalDrivers
     */
    public static function sql(
        string $id,
        string $description,
        array $statementsByDriver,
        bool $destructive,
        array $transactionalDrivers,
        bool $retrySafe,
        ?MigrationPostconditionVerifierInterface $postconditionVerifier = null,
        array $supersedesPostconditions = [],
        ?MigrationPreconditionVerifierInterface $preconditionVerifier = null,
        ?string $targetScopeModuleId = null
    ): self {
        self::assertSqlContract(
            $id,
            $statementsByDriver,
            $destructive,
            $transactionalDrivers,
            $retrySafe
        );
        $postconditionContract = self::postconditionContract(
            $id,
            $postconditionVerifier
        );
        $preconditionContract = self::preconditionContract(
            $id,
            $preconditionVerifier
        );
        $normalizedSupersessions = self::normalizeSupersessions(
            $id,
            $postconditionVerifier,
            $supersedesPostconditions
        );
        self::assertTargetScopeModuleId($id, $targetScopeModuleId);

        $normalizedTransactions = array_values(array_unique(
            $transactionalDrivers
        ));
        sort($normalizedTransactions, SORT_STRING);
        $normalizedStatements = [
            'mysql' => array_values($statementsByDriver['mysql']),
            'sqlite' => array_values($statementsByDriver['sqlite']),
        ];
        // Preserve schemas 1-4 byte-for-byte for historical definitions.
        // Cross-scope is an explicit schema-5 contract and deliberately
        // excludes operator-facing copy so a description edit cannot drift.
        if ($targetScopeModuleId !== null) {
            $canonicalContract = [
                'schema' => self::SQL_WITH_TARGET_SCOPE_SCHEMA,
                'id' => $id,
                'target_scope_module_id' => $targetScopeModuleId,
                'destructive' => $destructive,
                'retry_safe' => $retrySafe,
                'transactional_drivers' => $normalizedTransactions,
                'statements' => $normalizedStatements,
            ];
            if ($preconditionContract !== null) {
                $canonicalContract['precondition'] = $preconditionContract;
            }
            if ($postconditionContract !== null) {
                $canonicalContract['postcondition'] = $postconditionContract;
            }
            if ($normalizedSupersessions !== []) {
                $canonicalContract['supersedes_postconditions'] =
                    $normalizedSupersessions;
            }
        } elseif ($preconditionContract !== null) {
            $canonicalContract = [
                'schema' => self::SQL_WITH_PRECONDITION_SCHEMA,
                'id' => $id,
                'destructive' => $destructive,
                'retry_safe' => $retrySafe,
                'transactional_drivers' => $normalizedTransactions,
                'statements' => $normalizedStatements,
                'precondition' => $preconditionContract,
            ];
            if ($postconditionContract !== null) {
                $canonicalContract['postcondition'] = $postconditionContract;
            }
            if ($normalizedSupersessions !== []) {
                $canonicalContract['supersedes_postconditions'] =
                    $normalizedSupersessions;
            }
        } elseif ($postconditionContract === null) {
            $canonicalContract = [
                'schema' => self::SQL_SCHEMA,
                'id' => $id,
                'description' => $description,
                'destructive' => $destructive,
                'retry_safe' => $retrySafe,
                'transactional_drivers' => $normalizedTransactions,
                'statements' => $normalizedStatements,
            ];
        } elseif ($normalizedSupersessions === []) {
            $canonicalContract = [
                'schema' => self::SQL_WITH_POSTCONDITION_SCHEMA,
                'id' => $id,
                'destructive' => $destructive,
                'retry_safe' => $retrySafe,
                'transactional_drivers' => $normalizedTransactions,
                'statements' => $normalizedStatements,
                'postcondition' => $postconditionContract,
            ];
        } else {
            $canonicalContract = [
                'schema' => self::SQL_WITH_POSTCONDITION_SUPERSESSION_SCHEMA,
                'id' => $id,
                'destructive' => $destructive,
                'retry_safe' => $retrySafe,
                'transactional_drivers' => $normalizedTransactions,
                'statements' => $normalizedStatements,
                'postcondition' => $postconditionContract,
                'supersedes_postconditions' => $normalizedSupersessions,
            ];
        }
        $canonical = json_encode(
            $canonicalContract,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        );

        $definition = new self(
            $id,
            $description,
            hash('sha256', $canonical),
            $destructive,
            $targetScopeModuleId
        );
        $definition->statementsByDriver = $normalizedStatements;
        $definition->transactionalDrivers = $normalizedTransactions;
        $definition->retrySafe = $retrySafe;
        $definition->postconditionVerifier = $postconditionVerifier;
        $definition->preconditionVerifier = $preconditionVerifier;
        $definition->supersededPostconditionIds = $normalizedSupersessions;

        return $definition;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function checksum(): string
    {
        return $this->checksum;
    }

    public function isDestructive(): bool
    {
        return $this->destructive;
    }

    /**
     * Returns the explicitly declared target. Null preserves the historical
     * contract in which a migration uses the scope of its owning module.
     */
    public function targetScopeModuleId(): ?string
    {
        return $this->targetScopeModuleId;
    }

    /**
     * Resolves the effective scope. Authorization of a cross-module target is
     * deliberately enforced by MigrationCatalog before planning or applying.
     */
    public function targetScope(
        string $ownerModuleId,
        MigrationScopeCollection $scopes
    ): ?MigrationScope {
        return $scopes->get($this->targetScopeModuleId ?? $ownerModuleId);
    }

    public function isExecutableFor(string $driver): bool
    {
        return isset($this->statementsByDriver[$driver]);
    }

    public function isTransactionalFor(string $driver): bool
    {
        return in_array($driver, $this->transactionalDrivers, true);
    }

    public function isRetrySafe(): bool
    {
        return $this->retrySafe;
    }

    public function postconditionVerifier(): ?MigrationPostconditionVerifierInterface
    {
        return $this->postconditionVerifier;
    }

    public function preconditionVerifier(): ?MigrationPreconditionVerifierInterface
    {
        return $this->preconditionVerifier;
    }

    /** @return list<string> */
    public function supersededPostconditionIds(): array
    {
        return $this->supersededPostconditionIds;
    }

    /**
     * @return list<string>
     */
    public function statementsFor(
        string $driver,
        MigrationScope $scope
    ): array {
        if (!$this->isExecutableFor($driver)) {
            throw new InvalidArgumentException(sprintf(
                'La migración %s no contiene SQL para %s.',
                $this->id,
                $driver
            ));
        }

        return array_map(
            static fn (string $statement): string => preg_replace_callback(
                '/\{\{table:([a-z][a-z0-9_]*)\}\}/',
                static fn (array $match): string => $scope->quotedTable(
                    $match[1],
                    $driver
                ),
                $statement
            ) ?? throw new InvalidArgumentException(
                'No se pudo resolver una plantilla SQL de migración.'
            ),
            $this->statementsByDriver[$driver]
        );
    }

    /**
     * @return array{
     *     id: string,
     *     description: string,
     *     checksum: string,
     *     destructive: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'checksum' => $this->checksum,
            'destructive' => $this->destructive,
        ];
    }

    /**
     * @param array<string, mixed> $statementsByDriver
     * @param list<mixed> $transactionalDrivers
     */
    private static function assertSqlContract(
        string $id,
        array $statementsByDriver,
        bool $destructive,
        array $transactionalDrivers,
        bool $retrySafe
    ): void {
        $driverKeys = array_keys($statementsByDriver);
        sort($driverKeys, SORT_STRING);
        $supportedKeys = self::SUPPORTED_DRIVERS;
        sort($supportedKeys, SORT_STRING);
        if (
            $driverKeys !== $supportedKeys
            || !array_is_list($statementsByDriver['mysql'])
            || !array_is_list($statementsByDriver['sqlite'])
            || $statementsByDriver['mysql'] === []
            || $statementsByDriver['sqlite'] === []
        ) {
            throw new InvalidArgumentException(sprintf(
                'La migración %s debe declarar listas SQL mysql y sqlite.',
                $id
            ));
        }

        foreach ($statementsByDriver as $driver => $statements) {
            foreach ($statements as $statement) {
                if (
                    !is_string($statement)
                    || trim($statement) === ''
                    || preg_match('//u', $statement) !== 1
                    || str_contains($statement, "\0")
                ) {
                    throw new InvalidArgumentException(sprintf(
                        'La migración %s contiene una sentencia SQL inválida.',
                        $id
                    ));
                }

                preg_match_all('/\{\{([^}]+)\}\}/', $statement, $tokens);
                foreach ($tokens[1] as $token) {
                    if (preg_match('/\Atable:[a-z][a-z0-9_]*\z/', $token) !== 1) {
                        throw new InvalidArgumentException(sprintf(
                            'La migración %s contiene un token SQL no permitido.',
                            $id
                        ));
                    }
                }
                $withoutAllowedTokens = preg_replace(
                    '/\{\{table:[a-z][a-z0-9_]*\}\}/',
                    '',
                    $statement
                );
                if (
                    !is_string($withoutAllowedTokens)
                    || str_contains($withoutAllowedTokens, '{{')
                    || str_contains($withoutAllowedTokens, '}}')
                ) {
                    throw new InvalidArgumentException(sprintf(
                        'La migración %s contiene una plantilla SQL inválida.',
                        $id
                    ));
                }

                if (
                    !$destructive
                    && (
                        self::isDestructiveSql($statement)
                        || !self::isConservativeNonDestructiveSql(
                            $statement,
                            (string) $driver
                        )
                    )
                ) {
                    throw new InvalidArgumentException(sprintf(
                        'La migración %s contiene SQL fuera del contrato no destructivo.',
                        $id
                    ));
                }
            }
        }

        foreach ($transactionalDrivers as $driver) {
            if (
                !is_string($driver)
                || !in_array($driver, self::SUPPORTED_DRIVERS, true)
            ) {
                throw new InvalidArgumentException(sprintf(
                    'La migración %s declara un driver transaccional inválido.',
                    $id
                ));
            }
        }

        if (in_array('mysql', $transactionalDrivers, true)) {
            throw new InvalidArgumentException(sprintf(
                'La migracion %s no puede declarar MySQL como transaccional.',
                $id
            ));
        }
        if (!$retrySafe) {
            throw new InvalidArgumentException(sprintf(
                'La migracion %s debe declarar un contrato MySQL reintentable.',
                $id
            ));
        }

        foreach ($statementsByDriver['mysql'] as $statement) {
            if (!self::isConservativeRetrySafeMySql($statement)) {
                throw new InvalidArgumentException(sprintf(
                    'La migracion %s contiene SQL MySQL fuera del contrato reintentable.',
                    $id
                ));
            }
        }
    }

    private static function assertTargetScopeModuleId(
        string $id,
        ?string $targetScopeModuleId
    ): void {
        if (
            $targetScopeModuleId !== null
            && preg_match(
                '/\A[a-z][a-z0-9-]{0,62}\z/',
                $targetScopeModuleId
            ) !== 1
        ) {
            throw new InvalidArgumentException(sprintf(
                'La migraci\u00f3n %s declara un m\u00f3dulo de scope inv\u00e1lido.',
                $id
            ));
        }
    }

    /** @return ?array{class: class-string, contract_version: string} */
    private static function postconditionContract(
        string $id,
        ?MigrationPostconditionVerifierInterface $verifier
    ): ?array {
        return self::conditionContract($id, $verifier, 'postcondición');
    }

    /** @return ?array{class: class-string, contract_version: string} */
    private static function preconditionContract(
        string $id,
        ?MigrationPreconditionVerifierInterface $verifier
    ): ?array {
        return self::conditionContract($id, $verifier, 'precondición');
    }

    /** @return ?array{class: class-string, contract_version: string} */
    private static function conditionContract(
        string $id,
        ?MigrationConditionVerifierInterface $verifier,
        string $conditionLabel
    ): ?array {
        if ($verifier === null) {
            return null;
        }

        try {
            $version = $verifier->contractVersion();
        } catch (\Throwable) {
            throw new InvalidArgumentException(sprintf(
                'La migración %s declara una %s inválida.',
                $id,
                $conditionLabel
            ));
        }
        if (
            preg_match('/\A[a-z0-9][a-z0-9._-]{0,127}\z/', $version) !== 1
        ) {
            throw new InvalidArgumentException(sprintf(
                'La migración %s declara una versión de %s inválida.',
                $id,
                $conditionLabel
            ));
        }

        return [
            'class' => $verifier::class,
            'contract_version' => $version,
        ];
    }

    /**
     * @param list<mixed> $supersessions
     * @return list<string>
     */
    private static function normalizeSupersessions(
        string $id,
        ?MigrationPostconditionVerifierInterface $verifier,
        array $supersessions
    ): array {
        if (!array_is_list($supersessions)) {
            throw new InvalidArgumentException(sprintf(
                'La migracion %s declara supersesiones invalidas.',
                $id
            ));
        }

        $normalized = [];
        foreach ($supersessions as $target) {
            if (
                !is_string($target)
                || strlen($target) > 190
                || preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $target) !== 1
                || $target === $id
                || isset($normalized[$target])
            ) {
                throw new InvalidArgumentException(sprintf(
                    'La migracion %s declara una supersesion invalida.',
                    $id
                ));
            }
            $normalized[$target] = true;
        }
        $targets = array_keys($normalized);
        sort($targets, SORT_STRING);
        if ($targets !== [] && $verifier === null) {
            throw new InvalidArgumentException(sprintf(
                'La migracion %s necesita postcondicion para superseder otra.',
                $id
            ));
        }

        return $targets;
    }

    /**
     * This is deliberately a syntactic allow-list, not a general SQL parser.
     * Providers must still make keys and ON DUPLICATE assignments idempotent.
     */
    private static function isConservativeRetrySafeMySql(
        string $statement
    ): bool {
        $sql = trim($statement);
        if (str_ends_with($sql, ';')) {
            $sql = rtrim(substr($sql, 0, -1));
        }
        if ($sql === '' || str_contains($sql, ';')) {
            return false;
        }

        foreach ([
            '/\ACREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\b/is',
            '/\AINSERT\s+IGNORE\b/is',
            '/\AINSERT\s+INTO\b[\s\S]*\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i',
            '/\ADROP\s+(?:TABLE|VIEW|INDEX|TRIGGER|PROCEDURE|FUNCTION|EVENT)\s+IF\s+EXISTS\b/is',
        ] as $pattern) {
            if (preg_match($pattern, $sql) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * This is intentionally conservative. A provider may always declare a
     * migration destructive even when the operation is operationally safe;
     * it must never be able to hide an obvious destructive verb and bypass
     * the backup/confirmation gates.
     */
    private static function isDestructiveSql(string $statement): bool
    {
        $sql = ltrim($statement);

        return preg_match(
            '/\A(?:ALTER|DELETE|DROP|RENAME|REPLACE|TRUNCATE|UPDATE|VACUUM)\b/i',
            $sql
        ) === 1
            || preg_match(
                '/\ACREATE\s+OR\s+REPLACE\b/i',
                $sql
            ) === 1
            || preg_match(
                '/\APRAGMA\s+[^=()\s]+\s*(?:=|\()/i',
                $sql
            ) === 1;
    }

    /**
     * A non-destructive migration deliberately has a very small SQL surface.
     * This prevents comments, CTEs or less obvious write forms from hiding a
     * destructive operation from the explicit backup gates. More complex SQL
     * remains available when the provider truthfully marks the migration as
     * destructive.
     */
    private static function isConservativeNonDestructiveSql(
        string $statement,
        string $driver
    ): bool {
        $sql = trim($statement);
        if ($sql === '' || str_contains($sql, ';')) {
            return false;
        }

        if ($driver === 'mysql') {
            return self::isConservativeRetrySafeMySql($sql)
                && preg_match('/\ADROP\b/i', $sql) !== 1;
        }
        if ($driver !== 'sqlite') {
            return false;
        }

        foreach ([
            '/\ACREATE\s+TABLE\b/is',
            '/\ACREATE\s+(?:UNIQUE\s+)?INDEX\b/is',
            '/\AINSERT\s+(?:OR\s+(?:ABORT|FAIL|ROLLBACK)\s+)?INTO\b/is',
            '/\ASELECT\b/is',
        ] as $pattern) {
            if (preg_match($pattern, $sql) === 1) {
                return true;
            }
        }

        return false;
    }
}
