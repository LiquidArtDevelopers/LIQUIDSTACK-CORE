(function (windowRef, documentRef) {
    'use strict';

    var RUNTIME_KEY = 'LiquidStackBlogPublic';
    var ROOT_SELECTOR = '[data-blog-lite-youtube]';
    var TRIGGER_SELECTOR = '[data-blog-youtube-play]';
    var FRAME_SELECTOR = '[data-blog-youtube-frame]';
    var CONSENT_EVENT = 'cookielad:consent-change';
    var ANALYTICS_RUNTIME_KEY = 'LiquidStackBlogAnalytics';
    var ANALYTICS_SCRIPT_ID = 'liquidstack-blog-analytics';
    var ANALYTICS_SCRIPT = '/assets/modules/blog/blog-analytics.js';
    var ANALYTICS_MARKER = '[data-blog-analytics-enabled="true"]';
    var MAX_START_SECONDS = 86400;
    var VIDEO_ID = /^[A-Za-z0-9_-]{11}$/;

    var previousRuntime = windowRef[RUNTIME_KEY];
    if (
        previousRuntime
        && typeof previousRuntime.destroy === 'function'
    ) {
        previousRuntime.destroy();
    }

    var controller = null;
    var mounted = new Map();

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

    function hasSocialConsent() {
        return readCookie('cookie_social') === 'true';
    }

    function hasAnalyticsConsent() {
        return readCookie('cookie_analytics') === 'true';
    }

    function clearAnalyticsIdentityCookies() {
        var suffix = '; Path=/; Max-Age=0; SameSite=Lax'
            + (windowRef.location.protocol === 'https:' ? '; Secure' : '');
        documentRef.cookie = 'LS_BLOG_AV=' + suffix;
        documentRef.cookie = 'LS_BLOG_AS=' + suffix;
    }

    function removeAnalyticsScript() {
        var script = documentRef.getElementById(ANALYTICS_SCRIPT_ID);
        if (script && typeof script.remove === 'function') {
            script.remove();
        }
    }

    function syncAnalyticsConsent() {
        var marker = documentRef.querySelector(ANALYTICS_MARKER);
        var runtime = windowRef[ANALYTICS_RUNTIME_KEY];
        var pageGrant = marker
            ? String(marker.getAttribute(
                'data-blog-analytics-page-grant'
            ) || '').trim()
            : '';
        var hasPageGrant = pageGrant.length <= 1024
            && /^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}$/.test(pageGrant);
        if (!marker || !hasPageGrant || !hasAnalyticsConsent()) {
            if (runtime && typeof runtime.revoke === 'function') {
                runtime.revoke();
            }
            clearAnalyticsIdentityCookies();
            removeAnalyticsScript();
            return;
        }
        if (runtime && typeof runtime.syncConsent === 'function') {
            runtime.syncConsent();
            return;
        }
        if (documentRef.getElementById(ANALYTICS_SCRIPT_ID)) {
            return;
        }
        var script = documentRef.createElement('script');
        script.id = ANALYTICS_SCRIPT_ID;
        script.src = ANALYTICS_SCRIPT;
        script.async = true;
        script.setAttribute('data-liquidstack-blog-analytics', '');
        (documentRef.head || documentRef.documentElement).appendChild(script);
    }

    function eventTargetElement(event) {
        var target = event && event.target;
        if (target && typeof target.closest === 'function') {
            return target;
        }

        return target && target.parentElement
            && typeof target.parentElement.closest === 'function'
            ? target.parentElement
            : null;
    }

    function isNativeNavigation(event) {
        return Boolean(
            event.defaultPrevented
            || event.button !== 0
            || event.metaKey
            || event.ctrlKey
            || event.shiftKey
            || event.altKey
        );
    }

    function youtubeConfig(root, trigger) {
        var videoId = String(root.dataset.videoId || '').trim();
        var rawStart = String(root.dataset.startSeconds || '0').trim();
        var startSeconds = Number(rawStart);
        var captionId = String(
            trigger.getAttribute('aria-labelledby') || ''
        ).trim();
        var caption = captionId === ''
            ? null
            : documentRef.getElementById(captionId);
        var title = String(caption && caption.textContent || '').trim();

        if (
            !VIDEO_ID.test(videoId)
            || !Number.isInteger(startSeconds)
            || startSeconds < 0
            || startSeconds > MAX_START_SECONDS
            || title === ''
        ) {
            return null;
        }

        return {
            videoId: videoId,
            startSeconds: startSeconds,
            title: title
        };
    }

    function createYoutubeFrame(config) {
        var source = new URL(
            'https://www.youtube-nocookie.com/embed/' + config.videoId
        );
        source.searchParams.set('autoplay', '1');
        source.searchParams.set('playsinline', '1');
        if (config.startSeconds > 0) {
            source.searchParams.set('start', String(config.startSeconds));
        }

        var iframe = documentRef.createElement('iframe');
        iframe.className = 'blogDocument__youtubeFrame';
        iframe.src = source.toString();
        iframe.title = config.title;
        iframe.allow = [
            'accelerometer',
            'autoplay',
            'encrypted-media',
            'gyroscope',
            'picture-in-picture',
            'web-share'
        ].join('; ');
        iframe.referrerPolicy = 'strict-origin-when-cross-origin';
        iframe.setAttribute('allowfullscreen', '');
        iframe.setAttribute('data-blog-youtube-frame', '');
        iframe.setAttribute('tabindex', '0');

        return iframe;
    }

    function unmount(root) {
        var instance = mounted.get(root);
        var iframe = instance && instance.iframe
            ? instance.iframe
            : root.querySelector(FRAME_SELECTOR);
        var trigger = instance && instance.trigger
            ? instance.trigger
            : root.querySelector(TRIGGER_SELECTOR);

        if (iframe && typeof iframe.remove === 'function') {
            iframe.remove();
        }
        if (trigger) {
            trigger.hidden = false;
        }
        delete root.dataset.blogYoutubeMounted;
        mounted.delete(root);
    }

    function mount(root, trigger, config) {
        unmount(root);

        var iframe = createYoutubeFrame(config);
        trigger.hidden = true;
        root.appendChild(iframe);
        root.dataset.blogYoutubeMounted = 'true';
        mounted.set(root, { iframe: iframe, trigger: trigger });

        if (typeof iframe.focus === 'function') {
            try {
                iframe.focus({ preventScroll: true });
            } catch (error) {
                iframe.focus();
            }
        }
    }

    function removeFramesWithoutConsent() {
        if (hasSocialConsent()) {
            return;
        }

        Array.from(mounted.keys()).forEach(unmount);
        documentRef.querySelectorAll(
            ROOT_SELECTOR + '[data-blog-youtube-mounted="true"]'
        ).forEach(unmount);
    }

    function handleClick(event) {
        var target = eventTargetElement(event);
        var trigger = target && target.closest(TRIGGER_SELECTOR);
        var root = trigger && trigger.closest(ROOT_SELECTOR);

        if (
            !trigger
            || !root
            || isNativeNavigation(event)
            || !hasSocialConsent()
        ) {
            return;
        }

        var config = youtubeConfig(root, trigger);
        if (config === null) {
            return;
        }

        event.preventDefault();
        mount(root, trigger, config);
    }

    function handleVisibilityChange() {
        if (documentRef.visibilityState === 'visible') {
            removeFramesWithoutConsent();
        }
    }

    function init() {
        if (controller !== null) {
            return destroy;
        }

        controller = new AbortController();
        var listener = { signal: controller.signal };
        documentRef.addEventListener('click', handleClick, listener);
        documentRef.addEventListener(
            'visibilitychange',
            handleVisibilityChange,
            listener
        );
        windowRef.addEventListener(
            CONSENT_EVENT,
            removeFramesWithoutConsent,
            listener
        );
        windowRef.addEventListener(
            CONSENT_EVENT,
            syncAnalyticsConsent,
            listener
        );
        windowRef.addEventListener(
            'focus',
            removeFramesWithoutConsent,
            listener
        );
        windowRef.addEventListener(
            'pageshow',
            removeFramesWithoutConsent,
            listener
        );
        windowRef.addEventListener('pagehide', function (event) {
            if (!event.persisted) {
                destroy();
            }
        }, listener);

        removeFramesWithoutConsent();
        syncAnalyticsConsent();
        return destroy;
    }

    function destroy() {
        if (controller !== null) {
            controller.abort();
            controller = null;
        }
        Array.from(mounted.keys()).forEach(unmount);
        mounted.clear();

        var analytics = windowRef[ANALYTICS_RUNTIME_KEY];
        if (analytics && typeof analytics.destroy === 'function') {
            analytics.destroy();
        }
        removeAnalyticsScript();

        if (windowRef[RUNTIME_KEY] === runtime) {
            delete windowRef[RUNTIME_KEY];
        }
    }

    var runtime = {
        init: init,
        destroy: destroy,
        syncConsent: function () {
            removeFramesWithoutConsent();
            syncAnalyticsConsent();
        }
    };
    windowRef[RUNTIME_KEY] = runtime;
    init();
}(window, document));
