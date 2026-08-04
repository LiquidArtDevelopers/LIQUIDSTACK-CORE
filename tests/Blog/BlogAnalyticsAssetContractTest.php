<?php

declare(strict_types=1);

namespace Tests\Blog;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BlogAnalyticsAssetContractTest extends TestCase
{
    private string $publicAsset;
    private string $analyticsAsset;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->publicAsset = $root
            . '/modules/blog/published/assets/blog-public.js';
        $this->analyticsAsset = $root
            . '/modules/blog/published/assets/blog-analytics.js';
    }

    public function testTrackerIsConsentGatedAndContainsNoRawFingerprinting(): void
    {
        $script = (string) file_get_contents($this->analyticsAsset);

        self::assertStringContainsString(
            "readCookie(CONSENT_COOKIE) !== 'true'",
            $script
        );
        self::assertStringContainsString('SESSION_COOKIE,', $script);
        self::assertStringContainsString('sessionTimeoutSeconds', $script);
        self::assertStringContainsString('documentRef.visibilityState', $script);
        self::assertStringContainsString('documentRef.hasFocus()', $script);
        self::assertStringContainsString('engagement_msec', $script);
        self::assertStringContainsString(
            "marker.getAttribute('data-blog-analytics-page-grant')",
            $script
        );
        self::assertStringContainsString('page_grant: pageGrant', $script);
        self::assertStringNotContainsString(
            'path: windowRef.location.pathname',
            $script
        );
        self::assertStringNotContainsString('view_id:', $script);
        self::assertStringNotContainsString('localStorage', $script);
        self::assertStringNotContainsString('sessionStorage', $script);
        self::assertStringNotContainsString('userAgent', $script);
        self::assertStringNotContainsString('clientIp', $script);
        self::assertStringNotContainsString('referrer', $script);
    }

    public function testBridgeLoadsTrackerOnlyFromExplicitMarkerAndConsent(): void
    {
        $script = (string) file_get_contents($this->publicAsset);

        self::assertStringContainsString(
            '[data-blog-analytics-enabled="true"]',
            $script
        );
        self::assertStringContainsString(
            "readCookie('cookie_analytics') === 'true'",
            $script
        );
        self::assertStringContainsString(
            '/assets/modules/blog/blog-analytics.js',
            $script
        );
        self::assertStringContainsString(
            'data-blog-analytics-page-grant',
            $script
        );
        self::assertStringContainsString(
            'clearAnalyticsIdentityCookies();',
            $script
        );
    }

    public function testDirectTrackerAlsoClearsIdentityWithoutConsent(): void
    {
        $harness = <<<'JS'
const fs = require('fs');
const writes = [];
const documentMock = {
    cookie: 'LS_BLOG_AV=old-visitor; LS_BLOG_AS=old-session',
    querySelector: () => ({})
};
Object.defineProperty(documentMock, 'cookie', {
    get() { return 'LS_BLOG_AV=old-visitor; LS_BLOG_AS=old-session'; },
    set(value) { writes.push(String(value)); }
});
global.window = { location: { protocol: 'https:' } };
global.document = documentMock;
eval(fs.readFileSync(process.argv[1], 'utf8'));
process.stdout.write(JSON.stringify(writes));
JS;
        $process = new Process([
            'node',
            '-e',
            $harness,
            $this->analyticsAsset,
        ]);
        $process->mustRun();
        $writes = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertContains(
            'LS_BLOG_AV=; Path=/; Max-Age=0; SameSite=Lax; Secure',
            $writes
        );
        self::assertContains(
            'LS_BLOG_AS=; Path=/; Max-Age=0; SameSite=Lax; Secure',
            $writes
        );
    }

    public function testStaleIdentityCookiesAreClearedWithoutMarker(): void
    {
        if (!is_file($this->publicAsset)) {
            self::fail('Missing Blog public asset.');
        }
        $harness = <<<'JS'
const fs = require('fs');
const writes = [];
let cookieValue = 'LS_BLOG_AV=old-visitor; LS_BLOG_AS=old-session';
const documentMock = {
    get cookie() { return cookieValue; },
    set cookie(value) { writes.push(String(value)); },
    visibilityState: 'visible',
    querySelector: () => null,
    querySelectorAll: () => [],
    getElementById: () => null,
    addEventListener: () => {},
    createElement: () => ({ setAttribute() {} }),
    head: { appendChild() {} },
    documentElement: { appendChild() {} }
};
const windowMock = {
    location: { protocol: 'https:' },
    addEventListener: () => {}
};
global.window = windowMock;
global.document = documentMock;
eval(fs.readFileSync(process.argv[1], 'utf8'));
process.stdout.write(JSON.stringify(writes));
JS;
        $process = new Process(['node', '-e', $harness, $this->publicAsset]);
        $process->mustRun();
        $writes = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertContains(
            'LS_BLOG_AV=; Path=/; Max-Age=0; SameSite=Lax; Secure',
            $writes
        );
        self::assertContains(
            'LS_BLOG_AS=; Path=/; Max-Age=0; SameSite=Lax; Secure',
            $writes
        );
    }
}
