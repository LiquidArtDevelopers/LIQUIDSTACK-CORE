<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Configuration;

use App\Core\Database\DatabaseConnectionProfile;
use App\Core\Environment\ProjectRuntimeProfile;
use InvalidArgumentException;
use Throwable;

final class WebAdminConfigLoader
{
    private const ROOT_KEYS = ['path', 'database', 'session'];
    private const DATABASE_KEYS = ['connection', 'table_prefix'];
    private const SESSION_KEYS = [
        'cookie_name',
        'idle_ttl_seconds',
        'absolute_ttl_seconds',
    ];

    /** @param array<string, mixed>|null $environment */
    public function load(
        string $projectRoot,
        #[\SensitiveParameter] ?array $environment = null
    ): WebAdminConfig {
        $root = rtrim($projectRoot, '/\\');
        if ($root === '' || !is_dir($root)) {
            throw new WebAdminConfigException('project.root_missing');
        }

        $developmentProjectId = $this->developmentProjectId($environment);

        $path = $root . '/' . WebAdminConfig::PROJECT_CONFIG_PATH;
        if (!file_exists($path) && !is_link($path)) {
            return WebAdminConfig::defaults($developmentProjectId);
        }

        if (!is_file($path) || is_link($path)) {
            throw new WebAdminConfigException(
                'config.project_file_not_regular',
                WebAdminConfig::PROJECT_CONFIG_PATH
            );
        }

        $raw = $this->requireArray($path);
        $this->assertOnlyKeys($raw, self::ROOT_KEYS, 'config');

        $defaults = WebAdminConfig::defaults();
        $basePath = $raw['path'] ?? $defaults->basePath();
        $database = $raw['database'] ?? [];
        $session = $raw['session'] ?? [];

        if (
            !is_array($database)
            || ($database !== [] && array_is_list($database))
        ) {
            throw new WebAdminConfigException(
                'config.expected_object',
                'database'
            );
        }
        if (
            !is_array($session)
            || ($session !== [] && array_is_list($session))
        ) {
            throw new WebAdminConfigException(
                'config.expected_object',
                'session'
            );
        }

        $this->assertOnlyKeys($database, self::DATABASE_KEYS, 'database');
        $this->assertOnlyKeys($session, self::SESSION_KEYS, 'session');

        $connection = $database['connection']
            ?? DatabaseConnectionProfile::SHARED;
        if (!DatabaseConnectionProfile::isSupported($connection)) {
            throw new WebAdminConfigException(
                'config.unsupported_database_connection',
                'database.connection'
            );
        }

        $tablePrefix = $database['table_prefix']
            ?? $defaults->tablePrefix();
        $cookieName = $session['cookie_name']
            ?? $defaults->cookieName();
        $idleTtl = $session['idle_ttl_seconds']
            ?? $defaults->idleTtlSeconds();
        $absoluteTtl = $session['absolute_ttl_seconds']
            ?? $defaults->absoluteTtlSeconds();

        $this->validateBasePath($basePath);
        $this->validateTablePrefix($tablePrefix);
        $this->validateCookieName($cookieName);
        $this->validateTtls($idleTtl, $absoluteTtl);

        return new WebAdminConfig(
            $basePath,
            $tablePrefix,
            $cookieName,
            $idleTtl,
            $absoluteTtl,
            'project',
            $connection,
            $developmentProjectId
        );
    }

    /** @param array<string, mixed>|null $environment */
    private function developmentProjectId(
        #[\SensitiveParameter] ?array $environment
    ): ?string {
        if (
            $environment === null
            || !array_key_exists(
                WebAdminConfig::DEVELOPMENT_PROJECT_ID_ENV,
                $environment
            )
        ) {
            return null;
        }

        $projectId = $environment[
            WebAdminConfig::DEVELOPMENT_PROJECT_ID_ENV
        ];
        if (
            !is_string($projectId)
            || preg_match(
                '/\A[a-f0-9]{'
                    . WebAdminConfig::DEVELOPMENT_PROJECT_ID_LENGTH
                    . '}\z/',
                $projectId
            ) !== 1
        ) {
            throw $this->invalidDevelopmentProjectId();
        }

        try {
            $profile = ProjectRuntimeProfile::fromEnvironment($environment);
        } catch (InvalidArgumentException) {
            throw $this->invalidDevelopmentProjectId();
        }

        if (!$profile->isDevelopmentLoopbackHttp()) {
            throw $this->invalidDevelopmentProjectId();
        }

        return $projectId;
    }

    private function invalidDevelopmentProjectId(): WebAdminConfigException
    {
        return new WebAdminConfigException(
            'config.invalid_development_project_id',
            WebAdminConfig::DEVELOPMENT_PROJECT_ID_ENV
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requireArray(string $path): array
    {
        $bufferLevel = ob_get_level();
        ob_start();

        try {
            $value = (static function (string $configPath): mixed {
                return require $configPath;
            })($path);
            $output = ob_get_clean();
        } catch (Throwable) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }

            throw new WebAdminConfigException(
                'config.project_file_load_failed',
                WebAdminConfig::PROJECT_CONFIG_PATH
            );
        }

        if ($output !== '') {
            throw new WebAdminConfigException(
                'config.project_file_emitted_output',
                WebAdminConfig::PROJECT_CONFIG_PATH
            );
        }
        if (
            !is_array($value)
            || ($value !== [] && array_is_list($value))
        ) {
            throw new WebAdminConfigException(
                'config.expected_object',
                WebAdminConfig::PROJECT_CONFIG_PATH
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $allowed
     */
    private function assertOnlyKeys(
        array $values,
        array $allowed,
        string $prefix
    ): void {
        foreach (array_keys($values) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $safeKey = is_string($key) && preg_match(
                    '/\A[a-zA-Z0-9_.-]+\z/',
                    $key
                ) === 1
                    ? $prefix . '.' . $key
                    : $prefix;

                throw new WebAdminConfigException(
                    'config.unknown_key',
                    $safeKey
                );
            }
        }
    }

    private function validateBasePath(mixed $value): void
    {
        if (
            !is_string($value)
            || preg_match(
                '#\A/[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:/[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\z#',
                $value
            ) !== 1
        ) {
            throw new WebAdminConfigException(
                'config.invalid_base_path',
                'path'
            );
        }
    }

    private function validateTablePrefix(mixed $value): void
    {
        if (
            !is_string($value)
            || preg_match('/\A[a-z][a-z0-9_]+_\z/', $value) !== 1
            || strlen($value) > WebAdminConfig::MAX_TABLE_PREFIX_LENGTH
        ) {
            throw new WebAdminConfigException(
                'config.invalid_table_prefix',
                'database.table_prefix'
            );
        }
    }

    private function validateCookieName(mixed $value): void
    {
        if (
            !is_string($value)
            || preg_match('/\A[A-Za-z][A-Za-z0-9_-]{2,63}\z/', $value) !== 1
            || strcasecmp($value, 'PHPSESSID') === 0
            || strcasecmp($value, WebAdminConfig::PREAUTH_COOKIE_NAME) === 0
            || strcasecmp($value, WebAdminConfig::ACTION_COOKIE_NAME) === 0
        ) {
            throw new WebAdminConfigException(
                'config.invalid_cookie_name',
                'session.cookie_name'
            );
        }
    }

    private function validateTtls(mixed $idle, mixed $absolute): void
    {
        if (
            !is_int($idle)
            || $idle < 300
            || $idle > WebAdminConfig::MAX_SESSION_TTL_SECONDS
        ) {
            throw new WebAdminConfigException(
                'config.invalid_ttl',
                'session.idle_ttl_seconds'
            );
        }
        if (
            !is_int($absolute)
            || $absolute < $idle
            || $absolute > WebAdminConfig::MAX_SESSION_TTL_SECONDS
        ) {
            throw new WebAdminConfigException(
                'config.invalid_ttl',
                'session.absolute_ttl_seconds'
            );
        }
    }
}
