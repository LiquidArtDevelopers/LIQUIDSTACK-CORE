<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Presentation;

/** Closed, public-safe presentation contract shared by images and future heroes. */
final class BlogImagePresentationPolicy
{
    public const DEFAULT_OBJECT_FIT = 'cover';
    public const DEFAULT_OBJECT_POSITION_Y = 'center';

    public const MIN_HEIGHT_DVH = 5;
    public const MAX_HEIGHT_DVH = 100;
    public const MIN_RADIUS_PERCENT = 0;
    public const MAX_RADIUS_PERCENT = 50;
    public const MIN_OVERLAY_OPACITY = 1;
    public const MAX_OVERLAY_OPACITY = 100;

    private const OBJECT_FITS = [
        'cover' => true,
        'contain' => true,
    ];
    private const OBJECT_POSITIONS_Y = [
        'top' => true,
        'center' => true,
        'bottom' => true,
    ];
    private const OVERLAY_MODES = [
        'normal' => true,
        'multiply' => true,
        'screen' => true,
        'overlay' => true,
    ];
    private const THEME_COLORS = [
        'color00',
        'color01',
        'color02',
        'color03',
        'color04',
        'color05',
    ];

    public static function supportsHeightDvh(mixed $value): bool
    {
        return is_int($value)
            && $value >= self::MIN_HEIGHT_DVH
            && $value <= self::MAX_HEIGHT_DVH;
    }

    public static function supportsObjectFit(mixed $value): bool
    {
        return is_string($value) && isset(self::OBJECT_FITS[$value]);
    }

    public static function supportsObjectPositionY(mixed $value): bool
    {
        return is_string($value) && isset(self::OBJECT_POSITIONS_Y[$value]);
    }

    public static function supportsRadiusPercent(mixed $value): bool
    {
        return is_int($value)
            && $value >= self::MIN_RADIUS_PERCENT
            && $value <= self::MAX_RADIUS_PERCENT;
    }

    public static function supportsOverlayMode(mixed $value): bool
    {
        return is_string($value) && isset(self::OVERLAY_MODES[$value]);
    }

    public static function supportsOverlayOpacity(mixed $value): bool
    {
        return is_int($value)
            && $value >= self::MIN_OVERLAY_OPACITY
            && $value <= self::MAX_OVERLAY_OPACITY;
    }

    /** @return list<string> */
    public static function objectFits(): array
    {
        return array_keys(self::OBJECT_FITS);
    }

    /** @return list<string> */
    public static function objectPositionsY(): array
    {
        return array_keys(self::OBJECT_POSITIONS_Y);
    }

    /** @return list<string> */
    public static function overlayModes(): array
    {
        return array_keys(self::OVERLAY_MODES);
    }

    /** @return list<string> */
    public static function themeColors(): array
    {
        return self::THEME_COLORS;
    }

    /** @return array<string, mixed> */
    public static function toSafeArray(): array
    {
        return [
            'height_dvh' => [
                'min' => self::MIN_HEIGHT_DVH,
                'max' => self::MAX_HEIGHT_DVH,
                'step' => 1,
            ],
            'object_fit' => [
                'default' => self::DEFAULT_OBJECT_FIT,
                'values' => self::objectFits(),
            ],
            'object_position_y' => [
                'default' => self::DEFAULT_OBJECT_POSITION_Y,
                'values' => self::objectPositionsY(),
            ],
            'radius_percent' => [
                'min' => self::MIN_RADIUS_PERCENT,
                'max' => self::MAX_RADIUS_PERCENT,
                'step' => 1,
            ],
            'legacy_radius' => [
                'values' => BlogImageRadiusPreset::values(),
            ],
            'overlay' => [
                'modes' => self::overlayModes(),
                'colors' => self::themeColors(),
                'opacity' => [
                    'min' => self::MIN_OVERLAY_OPACITY,
                    'max' => self::MAX_OVERLAY_OPACITY,
                    'step' => 1,
                ],
            ],
        ];
    }
}
