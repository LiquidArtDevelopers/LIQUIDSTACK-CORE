<?php

declare(strict_types=1);

use App\Core\Blog\Http\BlogPublicationDateFormatter;
use App\Core\WebAdmin\Profile\WebAdminPublicProfile;
use App\Core\WebAdmin\Profile\WebAdminTimeZone;
use PHPUnit\Framework\TestCase;

final class BlogPublicationDateFormatterTest extends TestCase
{
    public function testUsesProfileIanaZoneAndArticleLocale(): void
    {
        $profile = new WebAdminPublicProfile(
            '11111111-1111-4111-8111-111111111111',
            'Proyecto de ejemplo',
            'site_admin',
            'Administrador',
            WebAdminTimeZone::fromIana('Europe/Madrid'),
            true,
            1
        );
        self::assertSame(
            '11:35 · 7 de agosto de 2026',
            (new BlogPublicationDateFormatter())->format(
                new DateTimeImmutable('2026-08-07T09:35:00Z'),
                'es',
                $profile
            )
        );
        self::assertSame(
            '11:35 · 07/08/2026',
            (new BlogPublicationDateFormatter())->formatCompactDate(
                new DateTimeImmutable('2026-08-07T09:35:00Z'),
                'es',
                $profile
            )
        );
        self::assertSame(
            '10:35 · 07/01/2026',
            (new BlogPublicationDateFormatter())->formatCompactDate(
                new DateTimeImmutable('2026-01-07T09:35:00Z'),
                'es',
                $profile
            )
        );
    }

    public function testUnsetProfileZoneUsesVisibleUtcFallback(): void
    {
        $profile = new WebAdminPublicProfile(
            '11111111-1111-4111-8111-111111111111',
            'Proyecto de ejemplo',
            'site_admin',
            'Administrador',
            WebAdminTimeZone::utc(),
            false,
            0
        );
        self::assertSame(
            '9:35 · 7 de agosto de 2026 · UTC',
            (new BlogPublicationDateFormatter())->format(
                new DateTimeImmutable('2026-08-07T09:35:00Z'),
                'es',
                $profile
            )
        );
        self::assertSame(
            '9:35 · 07/08/2026 · UTC',
            (new BlogPublicationDateFormatter())->formatCompactDate(
                new DateTimeImmutable('2026-08-07T09:35:00Z'),
                'es',
                $profile
            )
        );
    }
}
