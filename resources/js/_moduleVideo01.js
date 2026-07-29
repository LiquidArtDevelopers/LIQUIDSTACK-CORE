const ROOT_SELECTOR = '.moduleVideo01';
const SLOT_SELECTOR = '[data-module-video-youtube]';
const LOCAL_SELECTOR = '[data-module-video-local]';
const CONSENT_SELECTOR = '[data-module-video-consent]';
const PLAY_SELECTOR = '[data-module-video-play]';
const THUMBNAIL_SELECTOR = '[data-module-video-thumbnail]';
const CONSENT_EVENT = 'cookielad:consent-change';
const LANGUAGE_EVENT = 'app:languagechange';
const TRACK_KINDS = [
  'captions',
  'subtitles',
  'descriptions',
  'chapters',
  'metadata',
];

const activeInstances = new Map();
let consentController = null;
let languageRequestController = null;

function readCookie(name) {
  const prefix = `${encodeURIComponent(name)}=`;
  const cookie = document.cookie
    .split(';')
    .map((part) => part.trim())
    .find((part) => part.startsWith(prefix));

  if (!cookie) {
    return '';
  }

  return decodeURIComponent(cookie.slice(prefix.length));
}

function hasSocialConsent() {
  return readCookie('cookie_social') === 'true';
}

function normalizeYoutubeUrl(input = '') {
  const value = String(input).trim();
  if (!value) {
    return '';
  }

  let videoId = /^[A-Za-z0-9_-]{11}$/.test(value) ? value : '';

  if (!videoId) {
    let candidate = value;
    if (candidate.startsWith('//')) {
      candidate = `https:${candidate}`;
    } else if (!/^https?:\/\//i.test(candidate)) {
      candidate = `https://${candidate.replace(/^\/+/, '')}`;
    }

    let url;
    try {
      url = new URL(candidate);
    } catch {
      return '';
    }

    if (
      url.protocol !== 'https:'
      || url.username
      || url.password
      || url.port
    ) {
      return '';
    }

    let host = url.hostname.toLowerCase().replace(/\.$/, '');
    host = host.replace(/^(www|m)\./, '');

    const segments = url.pathname.split('/').filter(Boolean);

    if (host === 'youtu.be') {
      [videoId = ''] = segments;
    } else if (['youtube.com', 'youtube-nocookie.com'].includes(host)) {
      if (segments[0] === 'watch') {
        videoId = url.searchParams.get('v') || '';
      } else if (['embed', 'shorts', 'live'].includes(segments[0])) {
        videoId = segments[1] || '';
      }
    }
  }

  if (!/^[A-Za-z0-9_-]{11}$/.test(videoId)) {
    return '';
  }

  return `https://www.youtube-nocookie.com/embed/${videoId}`;
}

function normalizeLocalAssetUrl(input = '', allowedExtensions = []) {
  const value = String(input).trim();
  if (!value) {
    return '';
  }

  let decoded = value;
  try {
    for (let pass = 0; pass < 5; pass += 1) {
      const next = decodeURIComponent(decoded);
      if (next === decoded) {
        break;
      }
      decoded = next;
    }
  } catch {
    return '';
  }

  if (
    /[\u0000-\u001F\u007F\\]/.test(decoded)
    || decoded.includes('..')
    || decoded.startsWith('//')
    || /^[a-z][a-z0-9+.-]*:/i.test(decoded)
  ) {
    return '';
  }

  const path = decoded.replace(/^\/+/, '');
  const extension = path.split(/[?#]/, 1)[0].split('.').pop()?.toLowerCase() || '';
  if (!allowedExtensions.includes(extension)) {
    return '';
  }

  return `${window.location.origin}/${path}`;
}

function createIframe(src, title) {
  const playbackUrl = new URL(src);
  playbackUrl.searchParams.set('autoplay', '1');

  const iframe = document.createElement('iframe');
  iframe.src = playbackUrl.toString();
  iframe.title = title;
  iframe.allow = 'autoplay; encrypted-media; picture-in-picture; web-share';
  iframe.referrerPolicy = 'strict-origin-when-cross-origin';
  iframe.setAttribute('allowfullscreen', '');
  iframe.setAttribute('data-module-video-iframe', '');
  iframe.setAttribute('data-module-video-source', src);
  return iframe;
}

function youtubeThumbnailUrl(src) {
  const normalized = normalizeYoutubeUrl(src);
  const videoId = normalized.split('/').pop() || '';

  return /^[A-Za-z0-9_-]{11}$/.test(videoId)
    ? `https://i.ytimg.com/vi/${videoId}/hqdefault.jpg`
    : '';
}

function collectRoots(scope) {
  const roots = [];

  if (scope?.matches?.(ROOT_SELECTOR)) {
    roots.push(scope);
  }

  scope?.querySelectorAll?.(ROOT_SELECTOR).forEach((root) => roots.push(root));
  return roots;
}

function syncInstance(instance) {
  const {
    root,
    slot,
    consent,
    localVideo,
    playButton,
    thumbnail,
  } = instance;
  const type = root.dataset.videoType === 'local' ? 'local' : 'youtube';
  const youtubeIsActive = type === 'youtube';

  if (root.dataset.videoType !== type) {
    root.dataset.videoType = type;
  }
  root.classList.toggle('moduleVideo01--youtube', youtubeIsActive);
  root.classList.toggle('moduleVideo01--local', !youtubeIsActive);

  if (slot) {
    slot.hidden = !youtubeIsActive;
  }

  if (localVideo) {
    const wasHidden = localVideo.hidden;
    localVideo.hidden = youtubeIsActive;

    if (youtubeIsActive) {
      localVideo.pause();
      localVideo.preload = 'none';
    } else if (wasHidden || localVideo.preload !== 'metadata') {
      localVideo.preload = 'metadata';
      localVideo.load();
    }
  }

  const currentIframe = slot?.querySelector('[data-module-video-iframe]');
  if (!youtubeIsActive || !slot) {
    currentIframe?.remove();
    if (playButton) {
      playButton.hidden = true;
    }
    thumbnail?.removeAttribute('src');
    delete root.dataset.videoMounted;
    return;
  }

  const src = normalizeYoutubeUrl(slot.dataset.videoSrc || '');
  const title = String(slot.getAttribute('title') || '').trim();
  const playLabel = String(slot.dataset.videoPlayLabel || '').trim();
  const canPreview = hasSocialConsent()
    && src !== ''
    && title !== ''
    && playLabel !== '';

  if (!canPreview) {
    currentIframe?.remove();
    if (consent) {
      consent.hidden = false;
    }
    if (playButton) {
      playButton.hidden = true;
    }
    thumbnail?.removeAttribute('src');
    delete root.dataset.videoMounted;
    return;
  }

  if (
    currentIframe
    && currentIframe.dataset.moduleVideoSource === src
    && currentIframe.getAttribute('title') === title
  ) {
    if (consent) {
      consent.hidden = true;
    }
    if (playButton) {
      playButton.hidden = true;
    }
    thumbnail?.removeAttribute('src');
    root.dataset.videoMounted = 'true';
    return;
  }

  currentIframe?.remove();
  if (consent) {
    consent.hidden = true;
  }
  if (playButton) {
    playButton.hidden = false;
    playButton.setAttribute('aria-label', `${playLabel}: ${title}`);
  }
  if (thumbnail) {
    setOptionalAttribute(thumbnail, 'src', youtubeThumbnailUrl(src));
  }
  delete root.dataset.videoMounted;
}

function mountYoutubeIframe(instance) {
  const {
    root,
    slot,
    consent,
    playButton,
    thumbnail,
  } = instance;

  if (
    !slot
    || root.dataset.videoType !== 'youtube'
    || !hasSocialConsent()
  ) {
    syncInstance(instance);
    return;
  }

  const src = normalizeYoutubeUrl(slot.dataset.videoSrc || '');
  const title = String(slot.getAttribute('title') || '').trim();
  const playLabel = String(slot.dataset.videoPlayLabel || '').trim();
  if (!src || !title || !playLabel) {
    syncInstance(instance);
    return;
  }

  slot.querySelector('[data-module-video-iframe]')?.remove();
  slot.appendChild(createIframe(src, title));
  if (consent) {
    consent.hidden = true;
  }
  if (playButton) {
    playButton.hidden = true;
  }
  thumbnail?.removeAttribute('src');
  root.dataset.videoMounted = 'true';
}

function initInstance(root) {
  const existing = activeInstances.get(root);
  if (existing) {
    return existing;
  }

  const slot = root.querySelector(SLOT_SELECTOR);

  const instance = {
    root,
    slot,
    consent: slot?.querySelector(CONSENT_SELECTOR) || null,
    playButton: slot?.querySelector(PLAY_SELECTOR) || null,
    thumbnail: slot?.querySelector(THUMBNAIL_SELECTOR) || null,
    localVideo: root.querySelector(LOCAL_SELECTOR),
    observer: null,
    interactionController: new AbortController(),
    destroy() {
      this.observer?.disconnect();
      this.interactionController.abort();
      this.slot?.querySelector('[data-module-video-iframe]')?.remove();
      this.thumbnail?.removeAttribute('src');
      delete this.root.dataset.videoMounted;
      activeInstances.delete(this.root);
    },
  };

  instance.playButton?.addEventListener(
    'click',
    (event) => {
      if (!event.ctrlKey) {
        mountYoutubeIframe(instance);
      }
    },
    { signal: instance.interactionController.signal },
  );
  instance.observer = new MutationObserver(() => syncInstance(instance));
  instance.observer.observe(root, {
    attributes: true,
    subtree: true,
    attributeFilter: [
      'data-video-type',
      'data-video-src',
      'data-video-play-label',
      'title',
    ],
  });

  activeInstances.set(root, instance);
  syncInstance(instance);
  return instance;
}

function syncAllInstances() {
  activeInstances.forEach(syncInstance);
}

function readCatalogEntry(catalog, element) {
  const key = element?.getAttribute?.('data-lang') || '';
  const entry = key && catalog && typeof catalog === 'object'
    ? catalog[key]
    : null;

  return entry && typeof entry === 'object' && !Array.isArray(entry)
    ? entry
    : null;
}

function setOptionalAttribute(element, name, value) {
  if (!element) {
    return;
  }

  const normalized = String(value ?? '').trim();
  if (normalized) {
    element.setAttribute(name, normalized);
  } else {
    element.removeAttribute(name);
  }
}

function applyLanguageCatalog(instance, catalog) {
  const {
    root,
    slot,
    consent,
    localVideo,
  } = instance;
  const localWasVisible = Boolean(
    localVideo
    && root.dataset.videoType === 'local'
    && !localVideo.hidden,
  );

  const settings = readCatalogEntry(catalog, root);
  const nextType = String(settings?.type ?? '').toLowerCase();
  if (['youtube', 'local'].includes(nextType)) {
    root.dataset.videoType = nextType;
  }

  const youtube = readCatalogEntry(catalog, slot);
  if (youtube) {
    slot.setAttribute('data-video-src', normalizeYoutubeUrl(youtube.src));
    setOptionalAttribute(slot, 'data-video-play-label', youtube.playLabel);
    setOptionalAttribute(slot, 'title', youtube.title);
  }

  const consentCopy = readCatalogEntry(catalog, consent?.querySelector('[data-lang]'));
  if (consentCopy && Object.prototype.hasOwnProperty.call(consentCopy, 'text')) {
    consent.querySelector('[data-lang]').innerHTML = String(consentCopy.text ?? '');
  }

  const video = readCatalogEntry(catalog, localVideo);
  if (video) {
    setOptionalAttribute(
      localVideo,
      'poster',
      normalizeLocalAssetUrl(
        video.poster,
        ['avif', 'gif', 'jpeg', 'jpg', 'png', 'webp'],
      ),
    );
    setOptionalAttribute(localVideo, 'title', video.title);
  }

  localVideo?.querySelectorAll('source[data-lang]').forEach((source) => {
    const sourceEntry = readCatalogEntry(catalog, source);
    if (!sourceEntry) {
      return;
    }

    const extensions = source.type === 'video/webm' ? ['webm'] : ['mp4'];
    setOptionalAttribute(
      source,
      'src',
      normalizeLocalAssetUrl(sourceEntry.src, extensions),
    );
  });

  localVideo?.querySelectorAll('track[data-lang]').forEach((track) => {
    const trackEntry = readCatalogEntry(catalog, track);
    if (!trackEntry) {
      return;
    }

    const trackKind = String(trackEntry.kind ?? '').toLowerCase();
    const trackLang = String(trackEntry.srclang ?? '').trim();
    setOptionalAttribute(
      track,
      'src',
      normalizeLocalAssetUrl(trackEntry.src, ['vtt']),
    );
    track.setAttribute(
      'kind',
      TRACK_KINDS.includes(trackKind) ? trackKind : 'captions',
    );
    track.setAttribute(
      'srclang',
      /^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/.test(trackLang)
        ? trackLang
        : (document.documentElement.lang || 'es'),
    );
    setOptionalAttribute(track, 'label', trackEntry.label);
  });

  syncInstance(instance);
  if (root.dataset.videoType === 'local' && localWasVisible) {
    localVideo.load();
  }
}

async function syncLanguage(event) {
  const detail = event?.detail && typeof event.detail === 'object'
    ? event.detail
    : {};
  const lang = String(
    detail.lang
    || window.__APP_CONFIG__?.lang
    || document.documentElement.lang
    || '',
  ).trim();
  const route = String(
    detail.route
    || window.__APP_CONFIG__?.route
    || '',
  ).trim();

  if (
    !/^[A-Za-z0-9_-]+$/.test(lang)
    || !/^[A-Za-z0-9_-]+$/.test(route)
  ) {
    return;
  }

  languageRequestController?.abort();
  const requestController = new AbortController();
  languageRequestController = requestController;

  try {
    const response = await fetch('/languages', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8',
      },
      body: new URLSearchParams({ route, lang }),
      signal: requestController.signal,
    });

    if (!response.ok) {
      throw new Error(`No se pudo cargar el idioma del vídeo (${response.status}).`);
    }

    const catalog = await response.json();
    if (
      requestController.signal.aborted
      || !catalog
      || typeof catalog !== 'object'
      || Array.isArray(catalog)
    ) {
      return;
    }

    activeInstances.forEach((instance) => {
      applyLanguageCatalog(instance, catalog);
    });
  } catch (error) {
    if (error?.name !== 'AbortError') {
      console.error(error);
    }
  } finally {
    if (languageRequestController === requestController) {
      languageRequestController = null;
    }
  }
}

function ensureConsentListener() {
  if (consentController) {
    return;
  }

  consentController = new AbortController();
  const options = { signal: consentController.signal };
  document.addEventListener(CONSENT_EVENT, syncAllInstances, options);
  window.addEventListener(CONSENT_EVENT, syncAllInstances, options);
  window.addEventListener(LANGUAGE_EVENT, syncLanguage, options);
}

export default function initModuleVideo01(scope = document) {
  ensureConsentListener();
  return collectRoots(scope)
    .map(initInstance)
    .filter(Boolean);
}

if (import.meta.hot) {
  import.meta.hot.dispose(() => {
    activeInstances.forEach((instance) => instance.destroy());
    activeInstances.clear();
    consentController?.abort();
    consentController = null;
    languageRequestController?.abort();
    languageRequestController = null;
  });
}
