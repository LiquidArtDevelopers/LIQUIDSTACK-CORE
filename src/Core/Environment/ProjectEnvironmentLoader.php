<?php

declare(strict_types=1);

namespace App\Core\Environment;

use Dotenv\Dotenv;
use Dotenv\Loader\Loader;
use Dotenv\Parser\Parser;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Dotenv\Store\StringStore;
use Throwable;

final class ProjectEnvironmentLoader
{
    /**
     * Existing process variables take precedence over values declared in
     * the project file, matching immutable runtime loading.
     *
     * @param array<string, mixed>|null $baseEnvironment
     */
    public function load(
        string $projectRoot,
        ?array $baseEnvironment = null
    ): ProjectEnvironmentLoadResult {
        $environment = $baseEnvironment ?? $this->processEnvironment();
        $path = rtrim($projectRoot, '/\\') . '/.env';

        if (!file_exists($path) && !is_link($path)) {
            return new ProjectEnvironmentLoadResult(
                $environment,
                ProjectEnvironmentLoadResult::MISSING
            );
        }

        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            return new ProjectEnvironmentLoadResult(
                $environment,
                ProjectEnvironmentLoadResult::FILE_INVALID
            );
        }

        try {
            $contents = file_get_contents($path);
            if (!is_string($contents)) {
                throw new \RuntimeException('Environment read failed.');
            }

            $environment = array_replace(
                $this->parseAgainstEnvironment($contents, $environment),
                $environment
            );
        } catch (Throwable) {
            return new ProjectEnvironmentLoadResult(
                $environment,
                ProjectEnvironmentLoadResult::PARSE_FAILED
            );
        }

        return new ProjectEnvironmentLoadResult(
            $environment,
            ProjectEnvironmentLoadResult::VALID
        );
    }

    /** @return array<string, mixed> */
    private function processEnvironment(): array
    {
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }

        return array_replace($environment, $_ENV);
    }

    /**
     * Resolve nested values against an isolated, immutable copy of the
     * process environment. This preserves phpdotenv's createImmutable()
     * semantics without mutating globals and still lets file entries refer to
     * variables injected by the host process.
     *
     * @param array<string, mixed> $environment
     * @return array<string, string|null>
     */
    private function parseAgainstEnvironment(
        string $contents,
        array $environment
    ): array {
        $adapter = ArrayAdapter::create()->get();
        foreach ($environment as $name => $value) {
            if (
                is_string($name)
                && $name !== ''
                && is_string($value)
            ) {
                $adapter->write($name, $value);
            }
        }

        $repository = RepositoryBuilder::createWithNoAdapters()
            ->addAdapter($adapter)
            ->immutable()
            ->make();

        return (new Dotenv(
            new StringStore($contents),
            new Parser(),
            new Loader(),
            $repository
        ))->load();
    }
}
