const ROOT_SELECTOR = '[data-blog-collection]';
const ITEMS_SELECTOR = '[data-blog-collection-items]';
const NEXT_SELECTOR = '[data-blog-collection-next]';
const STATUS_SELECTOR = '[data-blog-collection-status]';
const APPENDED_EVENT = 'liquidstack:blog-collection-appended';
const RESULTS_UPDATED_EVENT = 'liquidstack:blog-results-updated';
const DEFAULT_PARTIAL = 'blog-results';
const DEFAULT_MAX_BATCHES = 20;
const DEFAULT_TIMEOUT_MS = 12_000;

let disposeActiveCollections = () => {};

const queryAllSafely = (scope, selector) => {
  if (!scope || typeof scope.querySelectorAll !== 'function') {
    return [];
  }

  try {
    const matches = Array.from(scope.querySelectorAll(selector));
    if (typeof scope.matches === 'function' && scope.matches(selector)) {
      matches.unshift(scope);
    }

    return [...new Set(matches)];
  } catch {
    return [];
  }
};

const querySafely = (scope, selector) => {
  if (!scope || typeof scope.querySelector !== 'function') {
    return null;
  }

  try {
    return scope.querySelector(selector);
  } catch {
    return null;
  }
};

const getView = (node) => node?.ownerDocument?.defaultView
  ?? globalThis.window
  ?? globalThis;

const validPartialName = (value) => (
  typeof value === 'string'
  && /^[A-Za-z0-9._-]{1,64}$/.test(value)
);

const positiveInteger = (value, fallback) => {
  const parsed = Number.parseInt(String(value ?? ''), 10);

  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : fallback;
};

const setBusy = (root, busy) => {
  if (busy) {
    root.setAttribute?.('aria-busy', 'true');
  } else {
    root.removeAttribute?.('aria-busy');
  }
};

const setStatus = (status, state, message = '') => {
  if (!status) {
    return;
  }
  if (status.dataset?.state !== state) {
    status.dataset.state = state;
  }
  if (status.textContent !== message) {
    status.textContent = message;
  }
  const hidden = message === '';
  if (status.hidden !== hidden) {
    status.hidden = hidden;
  }
};

const statusMessage = (status, state) => {
  if (!status?.dataset) {
    return '';
  }
  const key = `${state}Message`;

  return typeof status.dataset[key] === 'string' ? status.dataset[key] : '';
};

const cardKey = (node) => {
  const value = node?.dataset?.blogCardKey;

  return typeof value === 'string' && value !== '' ? value : null;
};

const isPlainClick = (event) => (
  event.button === 0
  && !event.altKey
  && !event.ctrlKey
  && !event.metaKey
  && !event.shiftKey
);

const installCollection = (root) => {
  const items = querySafely(root, ITEMS_SELECTOR);
  const next = querySafely(root, NEXT_SELECTOR);
  const status = querySafely(root, STATUS_SELECTOR);
  const documentRef = root.ownerDocument ?? globalThis.document;
  const view = getView(root);
  const Parser = view.DOMParser ?? globalThis.DOMParser;
  const AbortControllerConstructor = view.AbortController
    ?? globalThis.AbortController;
  const collectionName = root.dataset?.blogCollection ?? '';
  const partialName = validPartialName(root.dataset?.blogPartial)
    ? root.dataset.blogPartial
    : DEFAULT_PARTIAL;
  const mode = root.dataset?.blogLoadMode === 'near-end'
    ? 'near-end'
    : 'manual';
  const maxBatches = positiveInteger(
    root.dataset?.blogMaxBatches,
    DEFAULT_MAX_BATCHES,
  );
  const timeoutMs = Math.min(
    60_000,
    Math.max(
      3_000,
      positiveInteger(root.dataset?.blogTimeoutMs, DEFAULT_TIMEOUT_MS),
    ),
  );
  const canEnhance = Boolean(
    items
    && next
    && root.id
    && collectionName
    && typeof view.fetch === 'function'
    && typeof Parser === 'function'
    && typeof AbortControllerConstructor === 'function'
    && typeof view.URL === 'function',
  );

  if (!canEnhance) {
    return () => {};
  }

  const listeners = new AbortControllerConstructor();
  let requestController = null;
  let requestTimer = null;
  let generation = 0;
  let batchCount = 0;
  let intersectionObserver = null;
  let continuationTimer = null;
  let disposed = false;

  const normalizeContinuationHref = (rawHref, baseHref) => {
    if (typeof rawHref !== 'string' || rawHref.trim() === '') {
      return null;
    }

    try {
      const url = new view.URL(rawHref, baseHref);
      if (
        url.origin !== view.location?.origin
        || url.username !== ''
        || url.password !== ''
      ) {
        return null;
      }
      url.hash = '';

      return `${url.pathname}${url.search}`;
    } catch {
      return null;
    }
  };

  const nextUrl = () => {
    const rawHref = next.getAttribute?.('href') ?? next.href ?? '';
    if (!rawHref) {
      return null;
    }

    try {
      const url = new view.URL(rawHref, view.location?.href ?? '/');
      if (url.origin !== view.location?.origin) {
        return null;
      }
      url.hash = '';

      return url;
    } catch {
      return null;
    }
  };

  const idleLabel = next.textContent ?? '';
  const retryLabel = status?.dataset?.retryLabel
    || statusMessage(status, 'retry')
    || idleLabel;
  const finish = () => {
    const keepFocused = documentRef?.activeElement === next;
    next.removeAttribute?.('href');
    next.setAttribute?.('aria-disabled', 'true');
    next.textContent = statusMessage(status, 'end') || idleLabel;
    next.hidden = !keepFocused;
    if (keepFocused) {
      next.addEventListener?.('blur', () => {
        next.hidden = true;
      }, { once: true, signal: listeners.signal });
    }
    setStatus(status, 'end', statusMessage(status, 'end'));
    intersectionObserver?.disconnect();
    intersectionObserver = null;
  };

  const incomingRoot = (responseDocument) => {
    const byId = typeof responseDocument?.getElementById === 'function'
      ? responseDocument.getElementById(root.id)
      : null;
    if (byId?.dataset?.blogCollection === collectionName) {
      return byId;
    }

    return queryAllSafely(responseDocument, ROOT_SELECTOR).find((candidate) => (
      candidate?.dataset?.blogCollection === collectionName
      && candidate?.id === root.id
    )) ?? null;
  };

  const emitAppended = (appended, url) => {
    const CustomEventConstructor = view.CustomEvent ?? globalThis.CustomEvent;
    if (typeof documentRef?.dispatchEvent !== 'function'
        || typeof CustomEventConstructor !== 'function') {
      return;
    }

    documentRef.dispatchEvent(new CustomEventConstructor(APPENDED_EVENT, {
      bubbles: false,
      detail: {
        root,
        items: appended,
        url: url.href,
      },
    }));
  };

  const cancelContinuationCheck = () => {
    if (continuationTimer === null) {
      return;
    }
    const clearTimer = view.clearTimeout ?? globalThis.clearTimeout;
    clearTimer?.(continuationTimer);
    continuationTimer = null;
  };

  const nextRemainsNearViewport = () => {
    if (typeof next.getBoundingClientRect !== 'function') {
      return false;
    }
    const rect = next.getBoundingClientRect();
    const viewportHeight = Number(view.innerHeight) || 0;
    if (viewportHeight <= 0) {
      return false;
    }

    return rect.bottom >= -(viewportHeight * 0.5)
      && rect.top <= viewportHeight * 1.5;
  };

  const scheduleNearEndContinuation = () => {
    if (
      mode !== 'near-end'
      || disposed
      || requestController
      || !nextUrl()
      || batchCount >= maxBatches
    ) {
      return;
    }
    cancelContinuationCheck();
    const setTimer = view.setTimeout ?? globalThis.setTimeout;
    continuationTimer = setTimer?.(() => {
      continuationTimer = null;
      if (
        disposed
        || requestController
        || batchCount >= maxBatches
        || !nextUrl()
        || !nextRemainsNearViewport()
      ) {
        return;
      }
      void loadNext();
    }, 0) ?? null;
  };

  const loadNext = async () => {
    if (disposed || requestController || batchCount >= maxBatches) {
      if (batchCount >= maxBatches) {
        intersectionObserver?.disconnect();
        intersectionObserver = null;
      }
      return;
    }
    const url = nextUrl();
    if (!url) {
      return;
    }

    const ownGeneration = ++generation;
    let shouldContinueNearEnd = false;
    const controller = new AbortControllerConstructor();
    requestController = controller;
    next.textContent = idleLabel;
    setBusy(root, true);
    next.setAttribute?.('aria-disabled', 'true');
    setStatus(status, 'loading', statusMessage(status, 'loading'));
    const setTimer = view.setTimeout ?? globalThis.setTimeout;
    requestTimer = setTimer?.(() => {
      if (
        disposed
        || ownGeneration !== generation
        || requestController !== controller
      ) {
        return;
      }
      generation += 1;
      requestController = null;
      requestTimer = null;
      controller.abort();
      setBusy(root, false);
      next.removeAttribute?.('aria-disabled');
      next.textContent = retryLabel;
      setStatus(status, 'error', statusMessage(status, 'error'));
    }, timeoutMs) ?? null;

    try {
      const response = await view.fetch(url.href, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          Accept: 'text/html',
          'X-LiquidStack-Partial': partialName,
        },
        signal: controller.signal,
      });
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }
      const finalUrl = new view.URL(response.url || url.href, url.href);
      if (finalUrl.origin !== view.location.origin) {
        throw new Error('Cross-origin response rejected.');
      }

      const responseDocument = new Parser().parseFromString(
        await response.text(),
        'text/html',
      );
      if (
        disposed
        || ownGeneration !== generation
        || requestController !== controller
      ) {
        return;
      }

      const sourceRoot = incomingRoot(responseDocument);
      const sourceItems = querySafely(sourceRoot, ITEMS_SELECTOR);
      if (!sourceRoot || !sourceItems) {
        throw new Error('Invalid Blog collection fragment.');
      }

      const knownKeys = new Set(
        queryAllSafely(items, '[data-blog-card-key]').map(cardKey).filter(Boolean),
      );
      const appended = [];
      for (const sourceItem of Array.from(sourceItems.children ?? [])) {
        const key = cardKey(sourceItem);
        if (!key || knownKeys.has(key)) {
          continue;
        }
        const imported = typeof documentRef.importNode === 'function'
          ? documentRef.importNode(sourceItem, true)
          : sourceItem.cloneNode?.(true);
        if (!imported) {
          continue;
        }
        knownKeys.add(key);
        appended.push(imported);
      }

      const sourceNext = querySafely(sourceRoot, NEXT_SELECTOR);
      const rawNextHref = sourceNext?.getAttribute?.('href') ?? '';
      const nextHref = rawNextHref === ''
        ? null
        : normalizeContinuationHref(rawNextHref, finalUrl.href);
      if (rawNextHref !== '' && nextHref === null) {
        throw new Error('Invalid Blog collection continuation URL.');
      }
      if (nextHref !== null && appended.length === 0) {
        throw new Error('The next Blog batch contained no new cards.');
      }

      for (const imported of appended) {
        items.appendChild?.(imported);
      }

      batchCount += 1;
      if (nextHref !== null) {
        next.setAttribute?.('href', nextHref);
        next.hidden = false;
        setStatus(status, 'ready', '');
        shouldContinueNearEnd = true;
        if (batchCount >= maxBatches) {
          intersectionObserver?.disconnect();
          intersectionObserver = null;
        }
      } else {
        finish();
      }
      if (appended.length > 0) {
        emitAppended(appended, finalUrl);
      }
    } catch (error) {
      if (
        error?.name !== 'AbortError'
        && !disposed
        && ownGeneration === generation
        && requestController === controller
      ) {
        next.textContent = retryLabel;
        setStatus(status, 'error', statusMessage(status, 'error'));
      }
    } finally {
      if (ownGeneration === generation && requestController === controller) {
        const clearTimer = view.clearTimeout ?? globalThis.clearTimeout;
        if (requestTimer !== null) {
          clearTimer?.(requestTimer);
          requestTimer = null;
        }
        requestController = null;
        setBusy(root, false);
        if (nextUrl()) {
          next.removeAttribute?.('aria-disabled');
        }
        if (shouldContinueNearEnd) {
          scheduleNearEndContinuation();
        }
      }
    }
  };

  next.addEventListener?.('click', (event) => {
    if (
      !isPlainClick(event)
      || !nextUrl()
      || batchCount >= maxBatches
    ) {
      return;
    }
    event.preventDefault?.();
    void loadNext();
  }, { signal: listeners.signal });

  const IntersectionObserverConstructor = view.IntersectionObserver
    ?? globalThis.IntersectionObserver;
  if (mode === 'near-end' && typeof IntersectionObserverConstructor === 'function') {
    intersectionObserver = new IntersectionObserverConstructor((entries) => {
      if (entries.some((entry) => entry.isIntersecting)) {
        void loadNext();
      }
    }, { rootMargin: '50% 0px' });
    intersectionObserver.observe(next);
  }

  const hasNextUrl = nextUrl() !== null;
  setStatus(
    status,
    hasNextUrl ? 'ready' : 'end',
    hasNextUrl ? '' : statusMessage(status, 'end'),
  );

  return () => {
    if (disposed) {
      return;
    }
    disposed = true;
    generation += 1;
    cancelContinuationCheck();
    requestController?.abort();
    requestController = null;
    const clearTimer = view.clearTimeout ?? globalThis.clearTimeout;
    if (requestTimer !== null) {
      clearTimer?.(requestTimer);
      requestTimer = null;
    }
    listeners.abort();
    intersectionObserver?.disconnect();
    intersectionObserver = null;
    setBusy(root, false);
    next.removeAttribute?.('aria-disabled');
  };
};

export const cleanupBlogCollectionLoader = () => {
  disposeActiveCollections();
  disposeActiveCollections = () => {};
};

export const initBlogCollectionLoader = (scope = globalThis.document) => {
  cleanupBlogCollectionLoader();
  const documentRef = scope?.ownerDocument
    ?? (scope?.nodeType === 9 ? scope : globalThis.document);
  const view = getView(documentRef);
  const instances = new Map();

  const scan = (scanScope) => {
    for (const root of queryAllSafely(scanScope, ROOT_SELECTOR)) {
      if (!instances.has(root)) {
        instances.set(root, installCollection(root));
      }
    }
    for (const [root, cleanup] of instances) {
      if ('isConnected' in root && !root.isConnected) {
        cleanup();
        instances.delete(root);
      }
    }
  };

  scan(scope);
  const listeners = new (view.AbortController ?? globalThis.AbortController)();
  documentRef?.addEventListener?.(RESULTS_UPDATED_EVENT, (event) => {
    scan(event.detail?.target ?? documentRef);
  }, { signal: listeners.signal });

  let cleaned = false;
  disposeActiveCollections = () => {
    if (cleaned) {
      return;
    }
    cleaned = true;
    listeners.abort();
    for (const cleanup of instances.values()) {
      cleanup();
    }
    instances.clear();
  };

  return disposeActiveCollections;
};

export default initBlogCollectionLoader;

if (import.meta.hot) {
  import.meta.hot.dispose(cleanupBlogCollectionLoader);
}
