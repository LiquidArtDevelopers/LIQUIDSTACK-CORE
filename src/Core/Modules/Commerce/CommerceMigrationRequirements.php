<?php

declare(strict_types=1);

namespace App\Core\Modules\Commerce;

use App\Core\Modules\Migrations\MigrationFeatureRequirement;

final class CommerceMigrationRequirements
{
    public static function publicCatalog(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'commerce',
            'commerce.public_catalog',
            ['0001_commerce_catalog']
        );
    }

    public static function publicInquiry(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'commerce',
            'commerce.public_inquiry',
            ['0001_commerce_catalog', '0002_commerce_inquiries']
        );
    }

    public static function administration(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'commerce',
            'commerce.administration',
            [
                '0001_commerce_catalog',
                '0002_commerce_inquiries',
                '0003_commerce_capabilities',
            ]
        );
    }
}
