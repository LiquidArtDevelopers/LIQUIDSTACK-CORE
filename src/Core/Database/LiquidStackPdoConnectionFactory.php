<?php

declare(strict_types=1);

namespace App\Core\Database;

use PDO;

final class LiquidStackPdoConnectionFactory implements
    PdoConnectionFactoryInterface
{
    private readonly StrictPdoConnectionFactory $delegate;

    /**
     * @param array<string, mixed> $environment
     * @param null|callable(string, string, string, array<int, mixed>): PDO $connector
     */
    public function __construct(
        #[\SensitiveParameter] array $environment,
        ?callable $connector = null,
        ?LiquidStackDatabaseEnvironmentValidator $environmentValidator = null
    ) {
        $this->delegate = new StrictPdoConnectionFactory(
            $environment,
            $environmentValidator
                ?? new LiquidStackDatabaseEnvironmentValidator(),
            $connector
        );
    }

    public function connect(): PDO
    {
        return $this->delegate->connect();
    }
}
