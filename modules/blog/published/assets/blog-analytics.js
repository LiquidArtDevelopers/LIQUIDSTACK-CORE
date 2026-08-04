(function (windowRef, documentRef) {
    'use strict';

    var RUNTIME_KEY = 'LiquidStackBlogAnalytics';
    var MARKER_SELECTOR = '[data-blog-analytics-enabled="true"]';
    var CONSENT_EVENT = 'cookielad:consent-change';
    var CONSENT_COOKIE = 'cookie_analytics';
    var VISITOR_COOKIE = 'LS_BLOG_AV';
    var SESSION_COOKIE = 'LS_BLOG_AS';
    var START_ENDPOINT = '/_liquidstack/blog-analytics/start';
    var ENGAGEMENT_ENDPOINT = '/_liquidstack/blog-analytics/engagement';
    var REVOKE_ENDPOINT = '/_liquidstack/blog-analytics/revoke';
    var HEARTBEAT_MILLISECONDS = 10000;
    var MAX_ENGAGEMENT_MILLISECONDS = 86400000;

    var previousRuntime = windowRef[RUNTIME_KEY];
    if (previousRuntime && typeof previousRuntime.syncConsent === 'function') {
        previousRuntime.syncConsent();
        return;
    }

    var marker = documentRef.querySelector(MARKER_SELECTOR);
    if (!marker || readCookie(CONSENT_COOKIE) !== 'true') {
        deleteCookie(VISITOR_COOKIE);
        deleteCookie(SESSION_COOKIE);
        return;
    }
    var pageGrant = String(
        marker.getAttribute('data-blog-analytics-page-grant') || ''
    ).trim();
    if (!validPageGrant(pageGrant)) {
        deleteCookie(VISITOR_COOKIE);
        deleteCookie(SESSION_COOKIE);
        return;
    }

    var retentionDays = boundedInteger(
        marker.getAttribute('data-blog-analytics-retention-days'),
        30,
        400,
        90
    );
    var sessionTimeoutSeconds = boundedInteger(
        marker.getAttribute('data-blog-analytics-session-timeout'),
        300,
        28800,
        1800
    );
    var visitorToken = cookieUuid(VISITOR_COOKIE, retentionDays * 86400);
    var sessionToken = cookieUuid(SESSION_COOKIE, sessionTimeoutSeconds);
    if (visitorToken === null || sessionToken === null) {
        revoke();
        return;
    }

    var controller = new AbortController();
    var heartbeat = null;
    var activeSince = null;
    var engagementMilliseconds = 0;
    var lastSentEngagement = -1;
    var sequence = 0;
    var destroyed = false;

    function readCookie(name) {
        var prefix = encodeURIComponent(name) + '=';
        var parts = String(documentRef.cookie || '').split(';');
        for (var index = 0; index < parts.length; index += 1) {
            var part = parts[index].trim();
            if (part.indexOf(prefix) !== 0) {
                continue;
            }
            try {
                return decodeURIComponent(part.slice(prefix.length));
            } catch (error) {
                return '';
            }
        }
        return '';
    }

    function boundedInteger(raw, minimum, maximum, fallback) {
        var value = Number(String(raw || '').trim());
        return Number.isInteger(value) && value >= minimum && value <= maximum
            ? value
            : fallback;
    }

    function uuidV4() {
        if (windowRef.crypto && typeof windowRef.crypto.randomUUID === 'function') {
            return windowRef.crypto.randomUUID().toLowerCase();
        }
        if (!windowRef.crypto || typeof windowRef.crypto.getRandomValues !== 'function') {
            return null;
        }
        var bytes = new Uint8Array(16);
        windowRef.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        var hex = Array.from(bytes, function (byte) {
            return byte.toString(16).padStart(2, '0');
        }).join('');
        return [
            hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16),
            hex.slice(16, 20), hex.slice(20)
        ].join('-');
    }

    function validUuid(value) {
        return /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(value);
    }

    function validPageGrant(value) {
        return value.length <= 1024
            && /^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}$/.test(value);
    }

    function cookieSuffix(maxAge) {
        return '; Path=/; Max-Age=' + String(maxAge) + '; SameSite=Lax'
            + (windowRef.location.protocol === 'https:' ? '; Secure' : '');
    }

    function writeCookie(name, value, maxAge) {
        documentRef.cookie = encodeURIComponent(name) + '='
            + encodeURIComponent(value) + cookieSuffix(maxAge);
        return readCookie(name) === value;
    }

    function deleteCookie(name) {
        documentRef.cookie = encodeURIComponent(name)
            + '=; Path=/; Max-Age=0; SameSite=Lax'
            + (windowRef.location.protocol === 'https:' ? '; Secure' : '');
    }

    function cookieUuid(name, maxAge) {
        var existing = readCookie(name);
        if (validUuid(existing)) {
            return writeCookie(name, existing, maxAge) ? existing : null;
        }
        var generated = uuidV4();
        return generated !== null && writeCookie(name, generated, maxAge)
            ? generated
            : null;
    }

    function analyticsConsentGranted() {
        return readCookie(CONSENT_COOKIE) === 'true'
            && documentRef.querySelector(MARKER_SELECTOR) !== null;
    }

    function postJson(endpoint, payload, keepalive) {
        if (!analyticsConsentGranted()) {
            return;
        }
        windowRef.fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            keepalive: keepalive === true,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).catch(function () {});
    }

    function pageIsActive() {
        return documentRef.visibilityState === 'visible'
            && typeof documentRef.hasFocus === 'function'
            && documentRef.hasFocus();
    }

    function updateActiveClock() {
        var now = windowRef.performance.now();
        if (activeSince !== null) {
            engagementMilliseconds = Math.min(
                MAX_ENGAGEMENT_MILLISECONDS,
                engagementMilliseconds + Math.max(0, now - activeSince)
            );
        }
        activeSince = pageIsActive() ? now : null;
    }

    function currentEngagement() {
        var total = engagementMilliseconds;
        if (activeSince !== null) {
            total += Math.max(0, windowRef.performance.now() - activeSince);
        }
        return Math.min(MAX_ENGAGEMENT_MILLISECONDS, Math.floor(total));
    }

    function flush(keepalive) {
        if (destroyed || !analyticsConsentGranted()) {
            return;
        }
        var total = currentEngagement();
        if (total === lastSentEngagement) {
            return;
        }
        if (!writeCookie(
            SESSION_COOKIE,
            sessionToken,
            sessionTimeoutSeconds
        )) {
            return;
        }
        sequence += 1;
        lastSentEngagement = total;
        postJson(ENGAGEMENT_ENDPOINT, {
            engagement_msec: total,
            page_grant: pageGrant,
            sequence: sequence
        }, keepalive);
    }

    function syncVisibility() {
        updateActiveClock();
        flush(false);
    }

    function revoke() {
        deleteCookie(VISITOR_COOKIE);
        deleteCookie(SESSION_COOKIE);
        windowRef.fetch(REVOKE_ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            keepalive: true
        }).catch(function () {});
    }

    function syncConsent() {
        if (!analyticsConsentGranted()) {
            revoke();
            destroy(false);
        }
    }

    function handlePageHide(event) {
        updateActiveClock();
        flush(true);
        if (!event.persisted) {
            destroy(false);
        }
    }

    function handlePageShow() {
        if (analyticsConsentGranted()) {
            updateActiveClock();
        } else {
            syncConsent();
        }
    }

    function destroy(flushFirst) {
        if (destroyed) {
            return;
        }
        if (flushFirst === true) {
            updateActiveClock();
            flush(true);
        }
        destroyed = true;
        controller.abort();
        if (heartbeat !== null) {
            windowRef.clearInterval(heartbeat);
            heartbeat = null;
        }
        activeSince = null;
        if (windowRef[RUNTIME_KEY] === runtime) {
            delete windowRef[RUNTIME_KEY];
        }
    }

    var listener = { signal: controller.signal };
    documentRef.addEventListener('visibilitychange', syncVisibility, listener);
    windowRef.addEventListener('focus', syncVisibility, listener);
    windowRef.addEventListener('blur', syncVisibility, listener);
    windowRef.addEventListener(CONSENT_EVENT, syncConsent, listener);
    windowRef.addEventListener('pagehide', handlePageHide, listener);
    windowRef.addEventListener('pageshow', handlePageShow, listener);
    heartbeat = windowRef.setInterval(function () {
        updateActiveClock();
        flush(false);
    }, HEARTBEAT_MILLISECONDS);

    var runtime = {
        destroy: function () { destroy(true); },
        revoke: function () { revoke(); destroy(false); },
        syncConsent: syncConsent
    };
    windowRef[RUNTIME_KEY] = runtime;
    activeSince = pageIsActive() ? windowRef.performance.now() : null;
    postJson(START_ENDPOINT, {
        page_grant: pageGrant
    }, false);
}(window, document));
