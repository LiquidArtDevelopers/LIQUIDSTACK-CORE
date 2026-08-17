(function (windowRef, documentRef) {
    'use strict';

    var RUNTIME_KEY = 'LiquidStackBlogPublic';
    var ROOT_SELECTOR = '[data-blog-lite-youtube]';
    var TRIGGER_SELECTOR = '[data-blog-youtube-play]';
    var FRAME_SELECTOR = '[data-blog-youtube-frame]';
    var CONSENT_FRAME_SELECTOR = '[data-blog-consent-iframe]';
    var CONSENT_FRAME_ACTIVE_ATTRIBUTE =
        'data-blog-consent-iframe-active';
    var CONSENT_EVENT = 'cookielad:consent-change';
    var ANALYTICS_RUNTIME_KEY = 'LiquidStackBlogAnalytics';
    var ANALYTICS_SCRIPT_ID = 'liquidstack-blog-analytics';
    var ANALYTICS_SCRIPT = '/assets/modules/blog/blog-analytics.js';
    var ANALYTICS_MARKER = '[data-blog-analytics-enabled="true"]';
    var MAX_START_SECONDS = 86400;
    var VIDEO_ID = /^[A-Za-z0-9_-]{11}$/;
    var SAFE_FRAME_ALLOW = 'accelerometer; autoplay; clipboard-write; '
        + 'encrypted-media; fullscreen; gyroscope; picture-in-picture; '
        + 'web-share';
    var SAFE_FRAME_SANDBOX =
        'allow-scripts allow-same-origin allow-presentation';
    var SAFE_FRAME_ATTRIBUTES = [
        'allow',
        'allowfullscreen',
        'aria-describedby',
        'aria-hidden',
        'aria-label',
        'aria-labelledby',
        'class',
        'dir',
        'height',
        'id',
        'lang',
        'loading',
        'referrerpolicy',
        'role',
        'sandbox',
        'src',
        'title',
        'width'
    ];
    var SAFE_FRAME_SOURCES = [
        ['www.youtube-nocookie.com', '/embed/', true],
        ['youtube-nocookie.com', '/embed/', true],
        ['www.youtube.com', '/embed/', true],
        ['youtube.com', '/embed/', true],
        ['player.vimeo.com', '/video/', true],
        ['www.google.com', '/maps/embed', false],
        ['maps.google.com', '/maps/embed', false]
    ];

    var previousRuntime = windowRef[RUNTIME_KEY];
    if (
        previousRuntime
        && typeof previousRuntime.destroy === 'function'
    ) {
        previousRuntime.destroy();
    }

    var controller = null;
    var mounted = new Map();
    var consentFrames = new Map();
    var heroParallax = new Map();

    function mountHero00Parallax(hero) {
        if (heroParallax.has(hero)) {
            return;
        }
        var media = hero.querySelector('.hero00-media');
        if (!media || !media.style) {
            return;
        }
        var frame = 0;
        var reducedMotion = typeof windowRef.matchMedia === 'function'
            && windowRef.matchMedia(
                '(prefers-reduced-motion: reduce)'
            ).matches;

        function render() {
            frame = 0;
            if (reducedMotion) {
                return;
            }
            var rect = hero.getBoundingClientRect();
            var viewport = Math.max(1, windowRef.innerHeight || 1);
            var distance = Math.max(1, viewport + rect.height);
            var progress = Math.max(
                0,
                Math.min(1, (viewport - rect.top) / distance)
            );
            var shift = (0.5 - progress) * 20;
            media.style.transform = 'translate3d(0, '
                + shift.toFixed(3) + '%, 0) scale(1.2)';
            media.style.willChange = 'transform';
        }

        function schedule() {
            if (frame !== 0 || reducedMotion) {
                return;
            }
            if (typeof windowRef.requestAnimationFrame === 'function') {
                frame = windowRef.requestAnimationFrame(render);
                return;
            }
            render();
        }

        windowRef.addEventListener('scroll', schedule, {
            passive: true,
            signal: controller.signal
        });
        windowRef.addEventListener('resize', schedule, {
            signal: controller.signal
        });
        schedule();
        heroParallax.set(hero, function () {
            if (
                frame !== 0
                && typeof windowRef.cancelAnimationFrame === 'function'
            ) {
                windowRef.cancelAnimationFrame(frame);
            }
            media.style.removeProperty('transform');
            media.style.removeProperty('will-change');
        });
    }

    function mountHeroParallax() {
        documentRef.querySelectorAll('.hero00').forEach(
            mountHero00Parallax
        );
    }

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

    function safeFrameSource(raw) {
        if (
            typeof raw !== 'string'
            || raw.length === 0
            || raw.length > 2048
            || /[\u0000-\u0020\u007f\\]/.test(raw)
        ) {
            return null;
        }
        var sourceMatch = raw.match(
            /^https:\/\/([^/?#]+)([^?#]*)(?:\?[^#]*)?$/
        );
        if (!sourceMatch || sourceMatch[2].indexOf('%') !== -1) {
            return null;
        }
        var source;
        try {
            source = new URL(raw);
        } catch (error) {
            return null;
        }
        if (
            source.protocol !== 'https:'
            || source.username !== ''
            || source.password !== ''
            || source.port !== ''
            || source.hash !== ''
            || source.host !== source.hostname
            || source.hostname !== source.hostname.toLowerCase()
            || sourceMatch[1].toLowerCase() !== source.hostname
        ) {
            return null;
        }
        var segments = sourceMatch[2].split('/');
        if (segments.some(function (segment) {
            return segment === '.' || segment === '..';
        })) {
            return null;
        }
        var accepted = SAFE_FRAME_SOURCES.some(function (rule) {
            if (source.hostname !== rule[0]) {
                return false;
            }
            return rule[2]
                ? sourceMatch[2].indexOf(rule[1]) === 0
                    && sourceMatch[2].length > rule[1].length
                : sourceMatch[2] === rule[1];
        });

        return accepted ? raw : null;
    }

    function safePlainFrameAttribute(value, allowEmpty) {
        if (
            typeof value !== 'string'
            || value.length > 500
            || /[\u0000-\u001f\u007f]/.test(value)
            || value !== value.trim()
            || (!allowEmpty && value.trim() === '')
        ) {
            return null;
        }

        return value;
    }

    function safeConsentFrameConfig(placeholder) {
        var raw = String(
            placeholder.getAttribute('data-blog-consent-iframe') || ''
        );
        if (raw === '' || raw.length > 8192) {
            return null;
        }
        var config;
        try {
            config = JSON.parse(raw);
        } catch (error) {
            return null;
        }
        if (
            !config
            || config.v !== 1
            || !config.attributes
            || typeof config.attributes !== 'object'
            || Array.isArray(config.attributes)
        ) {
            return null;
        }
        var attributes = config.attributes;
        var names = Object.keys(attributes);
        if (names.length === 0 || names.length > 24) {
            return null;
        }
        var normalized = {};
        for (var index = 0; index < names.length; index += 1) {
            var name = names[index];
            var value = attributes[name];
            if (
                name !== name.toLowerCase()
                || (
                    SAFE_FRAME_ATTRIBUTES.indexOf(name) === -1
                    && !/^data-content-[a-z][a-z0-9_-]{0,47}$/.test(name)
                )
                || safePlainFrameAttribute(value, false) === null
            ) {
                return null;
            }
            normalized[name] = value;
        }
        var source = safeFrameSource(normalized.src);
        if (
            source === null
            || normalized.allow !== SAFE_FRAME_ALLOW
            || normalized.allowfullscreen !== 'allowfullscreen'
            || normalized.loading !== 'lazy'
            || normalized.referrerpolicy !== 'strict-origin-when-cross-origin'
            || normalized.sandbox !== SAFE_FRAME_SANDBOX
            || safePlainFrameAttribute(normalized.title, false) === null
        ) {
            return null;
        }
        normalized.src = source;
        var token = /^[A-Za-z_][A-Za-z0-9_-]{0,127}$/;
        var tokens = /^[A-Za-z_][A-Za-z0-9_-]{0,127}(?: [A-Za-z_][A-Za-z0-9_-]{0,127}){0,15}$/;
        var references = /^[A-Za-z_][A-Za-z0-9_-]{0,127}(?: [A-Za-z_][A-Za-z0-9_-]{0,127}){0,7}$/;
        if (
            Object.prototype.hasOwnProperty.call(normalized, 'class')
            && !tokens.test(normalized.class)
        ) {
            return null;
        }
        if (
            Object.prototype.hasOwnProperty.call(normalized, 'id')
            && !token.test(normalized.id)
        ) {
            return null;
        }
        for (var referenceIndex = 0; referenceIndex < 2; referenceIndex += 1) {
            var reference = referenceIndex === 0
                ? 'aria-describedby' : 'aria-labelledby';
            if (
                Object.prototype.hasOwnProperty.call(normalized, reference)
                && !references.test(normalized[reference])
            ) {
                return null;
            }
        }
        if (
            Object.prototype.hasOwnProperty.call(normalized, 'aria-hidden')
            && ['false', 'true'].indexOf(normalized['aria-hidden']) === -1
        ) {
            return null;
        }
        if (
            Object.prototype.hasOwnProperty.call(normalized, 'lang')
            && !/^[a-z]{2,8}(?:-[a-z0-9]{1,8}){0,3}$/.test(normalized.lang)
        ) {
            return null;
        }
        if (
            Object.prototype.hasOwnProperty.call(normalized, 'dir')
            && ['auto', 'ltr', 'rtl'].indexOf(normalized.dir) === -1
        ) {
            return null;
        }
        if (
            Object.prototype.hasOwnProperty.call(normalized, 'role')
            && [
                'group', 'list', 'listitem', 'none', 'note', 'presentation',
                'region'
            ].indexOf(normalized.role) === -1
        ) {
            return null;
        }
        for (var dataIndex = 0; dataIndex < names.length; dataIndex += 1) {
            if (names[dataIndex].indexOf('data-content-format-') === 0) {
                return null;
            }
        }
        for (var dimensionIndex = 0; dimensionIndex < 2; dimensionIndex += 1) {
            var dimension = dimensionIndex === 0 ? 'height' : 'width';
            if (
                Object.prototype.hasOwnProperty.call(normalized, dimension)
                && !/^[1-9][0-9]{0,3}$/.test(normalized[dimension])
            ) {
                return null;
            }
        }
        return normalized;
    }

    function createConsentFrame(attributes) {
        var iframe = documentRef.createElement('iframe');
        Object.keys(attributes).sort().forEach(function (name) {
            if (name === 'src') {
                return;
            }
            iframe.setAttribute(name, attributes[name]);
        });
        iframe.setAttribute(CONSENT_FRAME_ACTIVE_ATTRIBUTE, 'true');
        // Set the network-bearing attribute last, after the sandbox and every
        // other validated capability/metadata attribute are in place.
        iframe.setAttribute('src', attributes.src);

        return iframe;
    }

    function mountConsentFrame(placeholder) {
        if (!placeholder || !placeholder.parentNode) {
            return;
        }
        var attributes = safeConsentFrameConfig(placeholder);
        if (attributes === null) {
            return;
        }
        var iframe = createConsentFrame(attributes);
        placeholder.parentNode.replaceChild(iframe, placeholder);
        consentFrames.set(iframe, placeholder);
    }

    function unmountConsentFrame(iframe) {
        var placeholder = consentFrames.get(iframe);
        if (placeholder && iframe && iframe.parentNode) {
            iframe.parentNode.replaceChild(placeholder, iframe);
        }
        consentFrames.delete(iframe);
    }

    function syncConsentFrames() {
        if (hasSocialConsent()) {
            documentRef.querySelectorAll(CONSENT_FRAME_SELECTOR)
                .forEach(mountConsentFrame);
            return;
        }
        Array.from(consentFrames.keys()).forEach(unmountConsentFrame);
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

    function syncSocialConsent() {
        if (hasSocialConsent()) {
            syncConsentFrames();
            return;
        }

        Array.from(mounted.keys()).forEach(unmount);
        documentRef.querySelectorAll(
            ROOT_SELECTOR + '[data-blog-youtube-mounted="true"]'
        ).forEach(unmount);
        syncConsentFrames();
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
            syncSocialConsent();
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
            syncSocialConsent,
            listener
        );
        windowRef.addEventListener(
            CONSENT_EVENT,
            syncAnalyticsConsent,
            listener
        );
        windowRef.addEventListener(
            'focus',
            syncSocialConsent,
            listener
        );
        windowRef.addEventListener(
            'pageshow',
            syncSocialConsent,
            listener
        );
        windowRef.addEventListener('pagehide', function (event) {
            if (!event.persisted) {
                destroy();
            }
        }, listener);

        syncSocialConsent();
        syncAnalyticsConsent();
        mountHeroParallax();
        return destroy;
    }

    function destroy() {
        if (controller !== null) {
            controller.abort();
            controller = null;
        }
        Array.from(mounted.keys()).forEach(unmount);
        mounted.clear();
        Array.from(consentFrames.keys()).forEach(unmountConsentFrame);
        consentFrames.clear();
        Array.from(heroParallax.values()).forEach(function (cleanup) {
            cleanup();
        });
        heroParallax.clear();

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
            syncSocialConsent();
            syncAnalyticsConsent();
        }
    };
    windowRef[RUNTIME_KEY] = runtime;
    init();
}(window, document));
