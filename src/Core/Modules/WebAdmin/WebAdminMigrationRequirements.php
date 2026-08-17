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

    public static function mediaAvifSource(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'webadmin',
            'webadmin.media.avif_source',
            [
                '0001_webadmin_identity_and_access',
                '0002_webadmin_media_library',
                '0003_webadmin_media_avif_source',
            ]
        );
    }

    public static function profilePreferences(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'webadmin',
            'webadmin.profile_preferences',
            [
                '0001_webadmin_identity_and_access',
                '0004_webadmin_profile_preferences',
            ]
        );
    }

    public static function mediaQuarantine(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'webadmin',
            'webadmin.media.quarantine',
            [
                '0001_webadmin_identity_and_access',
                '0002_webadmin_media_library',
                '0003_webadmin_media_avif_source',
                '0004_webadmin_profile_preferences',
                '0005_webadmin_media_quarantine',
            ]
        );
    }
}
