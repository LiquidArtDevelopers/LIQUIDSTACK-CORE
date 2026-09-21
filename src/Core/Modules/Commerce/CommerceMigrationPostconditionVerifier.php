<?php

declare(strict_types=1);

namespace App\Core\Modules\Commerce;

use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationRuntimeTableShapeProbe;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;

final class CommerceMigrationPostconditionVerifier implements
    MigrationPostconditionVerifierInterface
{
    public function __construct(
        private readonly bool $includeEngagement = false,
        private readonly MigrationRuntimeTableShapeProbe $shapeProbe =
            new MigrationRuntimeTableShapeProbe()
    ) {
    }

    public function contractVersion(): string
    {
        return $this->includeEngagement
            ? 'commerce-schema-v1-engagement'
            : 'commerce-schema-v1-catalog';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        if ($scope->moduleId() !== 'commerce') {
            return false;
        }

        return $this->shapeProbe->hasColumns(
            $pdo,
            $scope,
            $this->includeEngagement
                ? CommerceSchemaContract::allTables()
                : CommerceSchemaContract::catalogTables()
        );
    }
}
