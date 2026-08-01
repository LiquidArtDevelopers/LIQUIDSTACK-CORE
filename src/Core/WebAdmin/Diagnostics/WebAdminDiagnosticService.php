<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Diagnostics;

use App\Core\Database\SharedDatabaseEnvironmentValidator;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Configuration\WebAdminConfigException;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use App\Core\WebAdmin\Mail\WebAdminMailConfiguration;
use App\Core\WebAdmin\Mail\WebAdminMailConfigurationLoader;
use App\Core\WebAdmin\Security\EmailAddress;
use App\Core\WebAdmin\Security\ExceptionTraceGuard;
use App\Core\WebAdmin\Security\InvalidEmailAddress;
use App\Core\WebAdmin\Security\InvalidSecurityKey;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecurityKey;

final class WebAdminDiagnosticService
{
    public function __construct(
        private readonly WebAdminConfigLoader $configLoader = new WebAdminConfigLoader(),
        private readonly SharedDatabaseEnvironmentValidator $databaseEnvironmentValidator =
            new SharedDatabaseEnvironmentValidator(),
        private readonly WebAdminMailConfigurationLoader $mailConfigurationLoader =
            new WebAdminMailConfigurationLoader()
    ) {
    }

    /**
     * This service never loads dotenv files, opens a database connection or
     * mutates the project. The command boundary may inject the secret-free
     * result of its separate read-only database probe.
     *
     * @param array<string, mixed> $environment already-loaded environment
     * @param list<string> $requiredAssets project-relative module assets
     */
    public function inspect(
        string $projectRoot,
        #[\SensitiveParameter] array $environment,
        array $requiredAssets = [],
        ?WebAdminDatabaseDiagnostic $databaseDiagnostic = null
    ): WebAdminDiagnosticReport {
        $databaseDiagnostic ??= WebAdminDatabaseDiagnostic::notChecked();
        $config = null;
        $configIssue = null;

        try {
            $config = $this->configLoader->load($projectRoot);
        } catch (WebAdminConfigException $exception) {
            $configIssue = [
                'code' => $exception->issueCode(),
                'key' => $exception->configKey(),
            ];
        }

        $databaseEnvironment = $this->databaseEnvironmentValidator->inspect(
            $environment
        );
        $missingDatabaseEnv = $databaseEnvironment['missing'];
        $invalidDatabaseEnv = $databaseEnvironment['invalid'];
        [$missingBootstrapEnv, $invalidBootstrapEnv]
            = $this->inspectBootstrapEmails($environment);
        [$missingSecurityEnv, $invalidSecurityEnv]
            = $this->inspectSecurityKey($environment);
        [$missingMailEnv, $invalidMailEnv]
            = $this->mailConfigurationLoader->inspect($environment);
        $exceptionTraceReady = $this->exceptionTraceIsSafe();
        $passwordPolicyReady = PasswordHasher::runtimeSupportsArgon2id();
        $assets = $this->inspectAssets($projectRoot, $requiredAssets);
        $operational = $databaseDiagnostic->toArray();

        $sharedBlockers = [];
        if ($configIssue !== null) {
            $sharedBlockers[] = 'configuration.invalid';
        }
        if ($missingDatabaseEnv !== []) {
            $sharedBlockers[] = 'environment.database_missing';
        }
        if ($invalidDatabaseEnv !== []) {
            $sharedBlockers[] = 'environment.database_invalid';
        }
        if (!$assets['ready']) {
            $sharedBlockers[] = 'assets.missing_or_invalid';
        }
        if (!$databaseDiagnostic->connectionReady()) {
            $sharedBlockers[] = 'database.connection_not_ready';
        }
        if (!$databaseDiagnostic->migrationsReady()) {
            $sharedBlockers[] = 'database.schema_not_ready';
        }

        $runtimeBlockers = $sharedBlockers;
        if ($missingSecurityEnv !== []) {
            $runtimeBlockers[] = 'environment.security_key_missing';
        }
        if ($invalidSecurityEnv !== []) {
            $runtimeBlockers[] = 'environment.security_key_invalid';
        }
        if (!$exceptionTraceReady) {
            $runtimeBlockers[] = 'runtime.exception_trace_unsafe';
        }
        if (!$passwordPolicyReady) {
            $runtimeBlockers[] = 'runtime.password_policy_unsupported';
        }

        $bootstrapBlockers = $sharedBlockers;
        if ($missingBootstrapEnv !== []) {
            $bootstrapBlockers[] = 'environment.bootstrap_missing';
        }
        if ($invalidBootstrapEnv !== []) {
            $bootstrapBlockers[] = 'environment.bootstrap_invalid';
        }

        $runtimeBlockers = array_values(array_unique($runtimeBlockers));
        $bootstrapBlockers = array_values(array_unique($bootstrapBlockers));
        $runtimeReady = $runtimeBlockers === [];
        $bootstrapReady = $bootstrapBlockers === [];
        $mailBlockers = [];
        if ($missingMailEnv !== []) {
            $mailBlockers[] = 'environment.mail_missing';
        }
        if ($invalidMailEnv !== []) {
            $mailBlockers[] = 'environment.mail_invalid';
        }
        $mailReady = $mailBlockers === [];

        return new WebAdminDiagnosticReport([
            'module' => 'webadmin',
            'configuration' => [
                'ready' => $configIssue === null,
                'project_file' => WebAdminConfig::PROJECT_CONFIG_PATH,
                'effective' => $config?->toSafeArray(),
                'issues' => $configIssue === null ? [] : [$configIssue],
            ],
            'environment' => [
                'database' => [
                    'connection' => 'shared',
                    'required' => WebAdminConfig::SHARED_DATABASE_ENV,
                    'missing' => $missingDatabaseEnv,
                    'invalid' => $invalidDatabaseEnv,
                    'ready' => $databaseEnvironment['ready'],
                ],
                'bootstrap' => [
                    'required' => array_values(
                        WebAdminConfig::BOOTSTRAP_EMAIL_ENV
                    ),
                    'missing' => $missingBootstrapEnv,
                    'invalid' => $invalidBootstrapEnv,
                    'ready' => $missingBootstrapEnv === []
                        && $invalidBootstrapEnv === [],
                ],
                'security_key' => [
                    'required' => [WebAdminConfig::SECURITY_KEY_ENV],
                    'missing' => $missingSecurityEnv,
                    'invalid' => $invalidSecurityEnv,
                    'ready' => $missingSecurityEnv === []
                        && $invalidSecurityEnv === [],
                ],
                'mail' => [
                    'transport' => 'smtp',
                    'required' => WebAdminMailConfiguration::REQUIRED_ENV,
                    'missing' => $missingMailEnv,
                    'invalid' => $invalidMailEnv,
                    'ready' => $mailReady,
                ],
                'php_security' => [
                    'directive' => 'zend.exception_ignore_args',
                    'required' => 'On',
                    'ready' => $exceptionTraceReady,
                ],
                'password_policy' => [
                    'id' => PasswordHasher::PRODUCTIVE_POLICY_ID,
                    'algorithm' => 'argon2id',
                    'ready' => $passwordPolicyReady,
                ],
            ],
            'missing_env' => array_values(array_unique(array_merge(
                $missingDatabaseEnv,
                $missingBootstrapEnv,
                $missingSecurityEnv
            ))),
            'assets' => $assets,
            'database' => $operational,
            'readiness' => [
                'scope' => $databaseDiagnostic->connectionStatus()
                    === 'not_checked'
                        ? 'preflight'
                        : 'operational',
                'ready' => $runtimeReady,
                'runtime_ready' => $runtimeReady,
                'bootstrap_ready' => $bootstrapReady,
                'mail_ready' => $mailReady,
                'database_connection' =>
                    $databaseDiagnostic->connectionStatus(),
                'migrations' => $databaseDiagnostic->migrationStatus(),
                'blockers' => $runtimeBlockers,
                'bootstrap_blockers' => $bootstrapBlockers,
                'mail_blockers' => $mailBlockers,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $environment
     * @return array{0: list<string>, 1: list<string>}
     */
    private function inspectSecurityKey(
        #[\SensitiveParameter] array $environment
    ): array
    {
        $name = WebAdminConfig::SECURITY_KEY_ENV;
        if (
            !array_key_exists($name, $environment)
            || $environment[$name] === null
            || $environment[$name] === ''
        ) {
            return [[$name], []];
        }
        if (!is_string($environment[$name])) {
            return [[], [$name]];
        }

        try {
            SecurityKey::fromBase64Url($environment[$name]);
        } catch (InvalidSecurityKey) {
            return [[], [$name]];
        }

        return [[], []];
    }

    private function exceptionTraceIsSafe(): bool
    {
        try {
            ExceptionTraceGuard::assertEnabled();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $environment
     * @return array{0: list<string>, 1: list<string>}
     */
    private function inspectBootstrapEmails(
        #[\SensitiveParameter] array $environment
    ): array
    {
        $missing = [];
        $invalid = [];
        $addresses = [];

        foreach (WebAdminConfig::BOOTSTRAP_EMAIL_ENV as $role => $name) {
            if (
                !array_key_exists($name, $environment)
                || $environment[$name] === null
                || $environment[$name] === ''
            ) {
                $missing[] = $name;
                continue;
            }

            if (!is_string($environment[$name])) {
                $invalid[] = $name;
                continue;
            }

            try {
                $addresses[$role] = EmailAddress::fromString(
                    $environment[$name]
                );
            } catch (InvalidEmailAddress) {
                $invalid[] = $name;
            }
        }

        if (
            isset($addresses['system_superadmin'], $addresses['site_admin'])
            && $addresses['system_superadmin']->equals(
                $addresses['site_admin']
            )
        ) {
            $invalid = array_merge(
                $invalid,
                array_values(WebAdminConfig::BOOTSTRAP_EMAIL_ENV)
            );
        }

        return [
            array_values(array_unique($missing)),
            array_values(array_unique($invalid)),
        ];
    }

    /**
     * @param list<string> $requiredAssets
     * @return array{ready: bool, required: list<string>, missing: list<string>, invalid: list<string>}
     */
    private function inspectAssets(
        string $projectRoot,
        array $requiredAssets
    ): array {
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
            if (!$this->assetExistsInsideProject($projectRoot, $normalized)) {
                $missing[] = $normalized;
            }
        }

        $required = array_values(array_unique($required));
        $missing = array_values(array_unique($missing));
        $invalid = array_values(array_unique($invalid));

        return [
            'ready' => $missing === [] && $invalid === [],
            'required' => $required,
            'missing' => $missing,
            'invalid' => $invalid,
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

    private function assetExistsInsideProject(
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

        $rootPrefix = rtrim(
            str_replace('\\', '/', $root),
            '/'
        ) . '/';
        $realNormalized = str_replace('\\', '/', $real);
        $candidate = $realNormalized . (is_dir($real) ? '/' : '');

        if (DIRECTORY_SEPARATOR === '\\') {
            $rootPrefix = strtolower($rootPrefix);
            $candidate = strtolower($candidate);
        }

        return str_starts_with($candidate, $rootPrefix);
    }
}
