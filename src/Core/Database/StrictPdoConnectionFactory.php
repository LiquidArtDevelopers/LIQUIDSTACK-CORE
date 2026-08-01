<?php

declare(strict_types=1);

namespace App\Core\Database;

use Closure;
use PDO;
use Throwable;

final class StrictPdoConnectionFactory implements
    PdoConnectionFactoryInterface
{
    /** @var Closure(string, string, string, array<int, mixed>): PDO */
    private readonly Closure $connector;

    /**
     * @param array<string, mixed> $environment
     * @param null|callable(string, string, string, array<int, mixed>): PDO $connector
     */
    public function __construct(
        #[\SensitiveParameter] private readonly array $environment,
        private readonly DatabaseEnvironmentValidatorInterface $validator,
        ?callable $connector = null
    ) {
        $this->connector = $connector === null
            ? static fn (
                string $dsn,
                string $username,
                string $password,
                array $options
            ): PDO => new PDO($dsn, $username, $password, $options)
            : Closure::fromCallable($connector);
    }

    public function connect(): PDO
    {
        [$dsn, $username, $password] = $this->validator
            ->connectionParameters($this->environment);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_TIMEOUT => 5,
        ];

        try {
            $pdo = ($this->connector)(
                $dsn,
                $username,
                $password,
                $options
            );
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                (new MySqlSessionContract())->enforce($pdo);
            }

            return $pdo;
        } catch (Throwable) {
            throw new DatabaseConnectionException(
                'database.connection_failed'
            );
        }
    }
}
