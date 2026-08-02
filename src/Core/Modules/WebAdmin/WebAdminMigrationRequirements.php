<?php

declare(strict_types=1);

namespace App\Core\Modules\WebAdmin;

use App\Core\Modules\Migrations\MigrationFeatureRequirement;

final class WebAdminMigrationRequirements
{
    public static function runtime(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'webadmin',
            'webadmin.runtime',
            ['0001_webadmin_identity_and_access']
        );
    }

    public static function media(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'webadmin',
            'webadmin.media',
            [
                '0001_webadmin_identity_and_access',
                '0002_webadmin_media_library',
            ]
        );
    }
}
