<?php

declare(strict_types=1);

namespace Tests\Commerce;

use App\Core\Commerce\Http\CommerceBasketCookie;
use App\Core\Commerce\Http\CommerceInquiryEnvironment;
use PHPUnit\Framework\TestCase;

final class CommerceBasketCookieTest extends TestCase
{
    public function testLocalIdentityIsIndependentOfTheSelectedPort(): void
    {
        $first = CommerceBasketCookie::forProject(
            __DIR__,
            [
                'RAIZ' => 'http://localhost:1309',
                'DEV_MODE' => 'true',
                'LIQUIDSTACK_DEV_PROJECT_ID' => 'base',
            ],
            3600
        );
        $second = CommerceBasketCookie::forProject(
            __DIR__,
            [
                'RAIZ' => 'http://localhost:1317',
                'DEV_MODE' => 'true',
                'LIQUIDSTACK_DEV_PROJECT_ID' => 'base',
            ],
            3600
        );
        $otherProject = CommerceBasketCookie::forProject(
            __DIR__,
            [
                'RAIZ' => 'http://localhost:1309',
                'DEV_MODE' => 'true',
                'LIQUIDSTACK_DEV_PROJECT_ID' => 'aiwa',
            ],
            3600
        );

        self::assertSame($first->name(), $second->name());
        self::assertNotSame($first->name(), $otherProject->name());
        self::assertStringNotContainsString(
            'Secure',
            $first->issue(str_repeat('a', 43))
        );
    }

    public function testProductionCookieReliesOnDomainIsolationAndIsSecure(): void
    {
        $cookie = CommerceBasketCookie::forProject(
            __DIR__,
            ['RAIZ' => 'https://shop.example.test', 'DEV_MODE' => 'false'],
            3600
        );

        self::assertSame('ls_commerce_basket', $cookie->name());
        self::assertStringContainsString(
            '; HttpOnly; SameSite=Lax; Secure',
            $cookie->issue(str_repeat('b', 43))
        );
    }

    public function testInquiryRecipientIsExplicitWithLegacyFormFallback(): void
    {
        $explicit = CommerceInquiryEnvironment::fromEnvironment([
            'LIQUIDSTACK_COMMERCE_INQUIRY_RECIPIENT' => 'sales@example.test',
            'MAIL_ADMIN' => 'legacy@example.test',
            'LIQUIDSTACK_COMMERCE_PRIVACY_VERSION' => 'privacy-2026-09',
        ]);
        $legacy = CommerceInquiryEnvironment::fromEnvironment([
            'MAIL_ADMIN' => 'legacy@example.test',
        ]);

        self::assertSame('sales@example.test', $explicit->recipientEmail());
        self::assertSame('privacy-2026-09', $explicit->privacyVersion());
        self::assertSame('legacy@example.test', $legacy->recipientEmail());
        self::assertSame('v1', $legacy->privacyVersion());
    }
}
