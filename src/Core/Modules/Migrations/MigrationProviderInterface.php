<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use App\Core\Modules\ModuleProviderInterface;

interface MigrationProviderInterface extends ModuleProviderInterface
{
    /**
     * Definitions may be catalog-only metadata or executable declarative SQL.
     * Database planning rejects metadata-only definitions before mutation.
     *
     * @return iterable<MigrationDefinition>
     */
    public static function migrations(): iterable;
}
