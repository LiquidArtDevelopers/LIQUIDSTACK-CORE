<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\WebAdmin\Profile\WebAdminPublicProfile;
use DateTimeImmutable;
use IntlDateFormatter;

/** Locale-aware display without mutating PHP's process-wide timezone. */
final class BlogPublicationDateFormatter
{
    public function format(
        DateTimeImmutable $publishedAt,
        string $locale,
        WebAdminPublicProfile $profile
    ): string {
        $zone = $profile->timeZone()->value();
        if (class_exists(IntlDateFormatter::class)) {
            $date = new IntlDateFormatter(
                $locale,
                IntlDateFormatter::LONG,
                IntlDateFormatter::NONE,
                $zone
            );
            $time = new IntlDateFormatter(
                $locale,
                IntlDateFormatter::NONE,
                IntlDateFormatter::SHORT,
                $zone
            );
            $dateText = $date->format($publishedAt);
            $timeText = $time->format($publishedAt);
            if (is_string($dateText) && is_string($timeText)) {
                return $timeText . ' · ' . $dateText
                    . ($profile->timeZoneConfigured() ? '' : ' · UTC');
            }
        }
        $fallback = $publishedAt->setTimezone(
            new \DateTimeZone($zone)
        )->format('Y-m-d H:i');
        return $fallback
            . ($profile->timeZoneConfigured() ? '' : ' UTC');
    }

    /** Compact administrative presentation; public article dates stay long. */
    public function formatCompactDate(
        DateTimeImmutable $publishedAt,
        string $locale,
        WebAdminPublicProfile $profile
    ): string {
        $zone = $profile->timeZone()->value();
        $localized = $publishedAt->setTimezone(new \DateTimeZone($zone));
        $timeText = $localized->format('H:i');
        if (class_exists(IntlDateFormatter::class)) {
            $time = new IntlDateFormatter(
                $locale,
                IntlDateFormatter::NONE,
                IntlDateFormatter::SHORT,
                $zone
            );
            $formattedTime = $time->format($publishedAt);
            if (is_string($formattedTime)) {
                $timeText = $formattedTime;
            }
        }

        return $timeText . ' · ' . $localized->format('d/m/Y')
            . ($profile->timeZoneConfigured() ? '' : ' · UTC');
    }
}
