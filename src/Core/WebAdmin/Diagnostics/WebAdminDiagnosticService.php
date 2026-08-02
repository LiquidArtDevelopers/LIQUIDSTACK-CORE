<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Diagnostics;

use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Database\DatabaseConnectionProfile;
use App\Core\Http\Request;
use App\Core\Modules\Diagnostics\ProjectAssetInspector;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Configuration\WebAdminConfigException;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use App\Core\WebAdmin\Mail\WebAdminMailConfigurationLoader;
use App\Core\WebAdmin\Media\ImagickAvifImageProcessor;
use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\PrivateMediaStorage;
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
        private readonly ConfiguredPdoConnectionFactoryResolver $databaseResolver =
            new ConfiguredPdoConnectionFactoryResolver(),
        private readonly WebAdminMailConfigurationLoader $mailConfigurationLoader =
            new WebAdminMailConfigurationLoader(),
        private readonly ProjectAssetInspector $assetInspector =
            new ProjectAssetInspector()
    ) {
    }

    /**
     * This service never loads dotenv files, opens a database connection or
     * mutates the project. The command boundary may inject the secret-free
     * result of its separate read-only database probe.
     *
     * @param array<string, mixed> $environment already-loaded environment
     * @param list<string> $requiredAssets project-relative module assets
     * @param ?list<string> $knownMediaPublicIds IDs obtained through a
     *        separate read-only DB probe; null leaves orphan scan unchecked
     */
    public function inspect(
        string $projectRoot,
        #[\SensitiveParameter] array $environment,
        array $requiredAssets = [],
        ?WebAdminDatabaseDiagnostic $databaseDiagnostic = null,
        ?array $knownMediaPublicIds = null
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

        $databaseConnection = $config?->databaseConnection()
            ?? DatabaseConnectionProfile::SHARED;
        $databaseEnvironment = $this->databaseResolver
            ->environmentValidator($databaseConnection)
            ->inspect($environment);
        $missingDatabaseEnv = $databaseEnvironment['missing'];
        $invalidDatabaseEnv = $databaseEnvironment['invalid'];
        [$missingBootstrapEnv, $invalidBootstrapEnv]
            = $this->inspectBootstrapEmails($environment);
        [$missingSecurityEnv, $invalidSecurityEnv]
            = $this->inspectSecurityKey($environment);
        [$missingMailEnv, $invalidMailEnv]
            = $this->mailConfigurationLoader->inspect($environment);
        $mailTransport = $this->mailConfigurationLoader
            ->safeTransportName($environment);
        $requiredMailEnv = $this->mailConfigurationLoader
            ->requiredEnvironmentNames($environment);
        $exceptionTraceReady = $this->exceptionTraceIsSafe();
        $passwordPolicyReady = PasswordHasher::runtimeSupportsArgon2id();
        $assets = $this->assetInspector->inspect(
            $projectRoot,
            $requiredAssets
        );
        $operational = $databaseDiagnostic->toArray();
        $media = $this->inspectMedia(
            $projectRoot,
            $environment,
            $databaseDiagnostic,
            $knownMediaPublicIds
        );

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
                    'connection' => $databaseConnection,
                    'required' => DatabaseConnectionProfile::environmentNames(
                        $databaseConnection
                    ),
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
                    'transport' => $mailTransport,
                    'required' => $requiredMailEnv,
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
            'media' => $media,
            'readiness' => [
                'scope' => $databaseDiagnostic->connectionStatus()
                    === 'not_checked'
                        ? 'preflight'
                        : 'operational',
                'ready' => $runtimeReady,
                'runtime_ready' => $runtimeReady,
                'bootstrap_ready' => $bootstrapReady,
                'mail_ready' => $mailReady,
                'media_ready' => $media['ready'],
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
     * @param ?list<string> $knownPublicIds
     * @return array<string, mixed>
     */
    private function inspectMedia(
        string $projectRoot,
        #[\SensitiveParameter] array $environment,
        WebAdminDatabaseDiagnostic $databaseDiagnostic,
        ?array $knownPublicIds
    ): array {
        $codec = ImagickAvifImageProcessor::runtimeDiagnostic();
        $fileUploads = filter_var(
            ini_get('file_uploads'),
            FILTER_VALIDATE_BOOLEAN
        );
        $uploadLimit = $this->iniByteLimit(
            ini_get('upload_max_filesize'),
            false
        );
        $postLimit = $this->iniByteLimit(
            ini_get('post_max_size'),
            true
        );
        $limitsReady = $uploadLimit !== null && $postLimit !== null
            && $uploadLimit >= Request::MAX_UPLOAD_FILE_BYTES
            && $postLimit >= Request::MAX_MULTIPART_BODY_BYTES;

        $configuredValue = $environment[PrivateMediaStorage::ROOT_ENV] ?? null;
        $explicitStorage = is_string($configuredValue)
            && trim($configuredValue) !== '';
        $storage = [
            'required' => [PrivateMediaStorage::ROOT_ENV],
            'configured' => false,
            'explicit' => $explicitStorage,
            'ready' => false,
            'status' => 'configuration_missing_or_invalid',
            'orphan_count' => null,
            'orphan_scan_status' => 'not_checked',
            'staging_count' => 0,
        ];
        try {
            $probe = PrivateMediaStorage::forProject(
                $projectRoot,
                $environment
            )->diagnostic($knownPublicIds);
            $storage = [
                'required' => [PrivateMediaStorage::ROOT_ENV],
                'configured' => true,
                'explicit' => $explicitStorage,
                'ready' => $probe['ready'],
                'status' => $probe['status'],
                'orphan_count' => $probe['orphan_count'],
                'orphan_scan_status' => $probe['orphan_scan_status'],
                'staging_count' => $probe['staging_count'],
            ];
        } catch (MediaException $exception) {
            $storage['status'] = $exception->issueCode();
        } catch (\Throwable) {
            $storage['status'] = 'webadmin.media.storage_invalid';
        }

        $schema = $databaseDiagnostic->mediaMigrations();
        $blockers = [];
        if (($schema['ready'] ?? false) !== true) {
            $blockers[] = 'database.media_schema_not_ready';
        }
        if (($codec['ready'] ?? false) !== true) {
            $blockers[] = 'runtime.media_codec_not_ready';
        }
        if (!$fileUploads) {
            $blockers[] = 'runtime.file_uploads_disabled';
        }
        if (!$limitsReady) {
            $blockers[] = 'runtime.upload_limits_too_low';
        }
        if ($storage['ready'] !== true) {
            $blockers[] = 'storage.media_not_ready';
        }

        return [
            'ready' => $blockers === [],
            'schema' => $schema,
            'runtime' => $codec,
            'uploads' => [
                'file_uploads' => $fileUploads,
                'upload_max_filesize_bytes' => $uploadLimit,
                'post_max_size_bytes' => $postLimit,
                'required_file_bytes' => Request::MAX_UPLOAD_FILE_BYTES,
                'required_post_bytes' => Request::MAX_MULTIPART_BODY_BYTES,
                'ready' => $fileUploads && $limitsReady,
            ],
            'storage' => $storage,
            'blockers' => $blockers,
        ];
    }

    private function iniByteLimit(string|false $value, bool $zeroUnlimited): ?int
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/\A([0-9]+)\s*([KMG])?\z/i', $value, $match) !== 1) {
            return null;
        }
        $number = (int) $match[1];
        if ($number === 0 && $zeroUnlimited) {
            return PHP_INT_MAX;
        }
        $power = match (strtoupper($match[2] ?? '')) {
            'K' => 1,
            'M' => 2,
            'G' => 3,
            default => 0,
        };
        for ($index = 0; $index < $power; ++$index) {
            if ($number > intdiv(PHP_INT_MAX, 1024)) {
                return PHP_INT_MAX;
            }
            $number *= 1024;
        }

        return $number;
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

}
