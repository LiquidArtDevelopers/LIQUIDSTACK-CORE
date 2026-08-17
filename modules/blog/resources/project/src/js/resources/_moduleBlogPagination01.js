const RESULTS_SELECTOR = '#blog-results[data-blog-results]';
const RESULTS_FALLBACK_SELECTOR = '#blog-results';
const PAGINATION_SELECTOR = '.moduleBlogPagination01';
const PAGINATION_LINK_SELECTOR = `${PAGINATION_SELECTOR} a[href]`;
const FILTER_FORM_SELECTOR = '[data-blog-filter-form]';
const FILTER_STATUS_SELECTOR = '[data-blog-filter-status]';
const RESULTS_UPDATED_EVENT = 'liquidstack:blog-results-updated';
const PARTIAL_NAME = 'blog-results';
const REQUEST_TIMEOUT_MS = 12_000;
const OWN_BUSY_ATTRIBUTE = 'data-blog-pagination-busy';
const RUNTIME_STATUS_ATTRIBUTE = 'data-blog-pagination-runtime-status';

const FALLBACK_ERROR_MESSAGES = {
  es: 'No se pudo cargar la página. Los resultados actuales se conservan; inténtalo de nuevo.',
  en: 'The page could not be loaded. The current results are still available; try again.',
  eu: 'Ezin izan da orria kargatu. Uneko emaitzak mantendu dira; saiatu berriro.',
};

const VISUALLY_HIDDEN_STYLE = [
  'position:absolute',
  'width:1px',
  'height:1px',
  'padding:0',
  'margin:-1px',
  'overflow:hidden',
  'clip:rect(0,0,0,0)',
  'white-space:nowrap',
  'border:0',
].join(';');

let disposeActivePagination = () => {};

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

const queryAllSafely = (scope, selector) => {
  if (!scope || typeof scope.querySelectorAll !== 'function') {
    return [];
  }

  try {
    return Array.from(scope.querySelectorAll(selector));
  } catch {
    return [];
  }
};

const getView = (node) => node?.ownerDocument?.defaultView
  ?? globalThis.window
  ?? globalThis;

const closestSafely = (node, selector) => {
  if (!node || typeof node.closest !== 'function') {
    return null;
  }

  try {
    return node.closest(selector);
  } catch {
    return null;
  }
};

const resultsTarget = (documentRef) => (
  querySafely(documentRef, RESULTS_SELECTOR)
  ?? querySafely(documentRef, RESULTS_FALLBACK_SELECTOR)
);

const attributeIsTrue = (element, name) => (
  element?.getAttribute?.(name) === 'true'
);

const targetContains = (target, node) => (
  typeof target?.contains === 'function' && target.contains(node)
);

const associatedFilterForms = (documentRef) => queryAllSafely(
  documentRef,
  FILTER_FORM_SELECTOR,
).filter((form) => (
  form?.dataset?.blogResultsTarget === RESULTS_FALLBACK_SELECTOR
  && String(form.getAttribute?.('method') ?? form.method ?? 'get')
    .toLowerCase() === 'get'
));

const formBelongsToResults = (form) => (
  form?.dataset?.blogResultsTarget === RESULTS_FALLBACK_SELECTOR
);

const foreignResultsActivity = (documentRef, target) => {
  if (associatedFilterForms(documentRef).some(
    (form) => attributeIsTrue(form, 'aria-busy'),
  )) {
    return true;
  }

  const ownBusy = attributeIsTrue(target, OWN_BUSY_ATTRIBUTE);

  return !ownBusy && (
    attributeIsTrue(target, 'aria-busy')
    || attributeIsTrue(target, 'data-blog-results-stale')
  );
};

const setOwnBusy = (target, busy) => {
  if (!target || typeof target.setAttribute !== 'function') {
    return;
  }

  if (busy) {
    target.setAttribute(OWN_BUSY_ATTRIBUTE, 'true');
    target.setAttribute('aria-busy', 'true');
    target.setAttribute('data-blog-results-stale', 'true');
    return;
  }

  if (!attributeIsTrue(target, OWN_BUSY_ATTRIBUTE)) {
    return;
  }
  target.removeAttribute(OWN_BUSY_ATTRIBUTE);
  target.removeAttribute('aria-busy');
  target.removeAttribute('data-blog-results-stale');
};

const normalizedSameOriginUrl = (rawUrl, view, baseHref) => {
  const URLConstructor = view.URL ?? globalThis.URL;
  if (typeof URLConstructor !== 'function') {
    return null;
  }

  try {
    const url = new URLConstructor(rawUrl, baseHref ?? view.location?.href);
    if (
      url.origin !== view.location?.origin
      || url.username !== ''
      || url.password !== ''
      || (url.protocol !== 'http:' && url.protocol !== 'https:')
    ) {
      return null;
    }
    url.hash = '';

    return url;
  } catch {
    return null;
  }
};

const isPlainClick = (event) => (
  (event?.button ?? 0) === 0
  && !event?.altKey
  && !event?.ctrlKey
  && !event?.metaKey
  && !event?.shiftKey
);

const linkCanBeEnhanced = (anchor) => {
  const target = String(anchor?.getAttribute?.('target') ?? '').toLowerCase();

  return !anchor?.hasAttribute?.('download')
    && (target === '' || target === '_self');
};

const existingStatus = (documentRef) => {
  for (const form of associatedFilterForms(documentRef)) {
    const status = querySafely(form, FILTER_STATUS_SELECTOR);
    if (status) {
      return status;
    }
  }

  return null;
};

const languageCode = (documentRef) => String(
  documentRef?.documentElement?.lang ?? 'en',
).trim().toLowerCase().split('-')[0];

const fallbackErrorMessage = (documentRef) => (
  FALLBACK_ERROR_MESSAGES[languageCode(documentRef)]
  ?? FALLBACK_ERROR_MESSAGES.en
);

const createRuntimeStatus = (documentRef, target, createdStatuses) => {
  if (typeof documentRef?.createElement !== 'function') {
    return null;
  }
  const status = documentRef.createElement('p');
  status.setAttribute(RUNTIME_STATUS_ATTRIBUTE, '');
  status.setAttribute('role', 'status');
  status.setAttribute('aria-live', 'polite');
  status.setAttribute('aria-atomic', 'true');
  status.dataset.state = 'idle';
  status.hidden = true;
  if (status.style) {
    status.style.cssText = VISUALLY_HIDDEN_STYLE;
  }

  const pagination = querySafely(target, PAGINATION_SELECTOR);
  if (typeof pagination?.insertAdjacentElement === 'function') {
    pagination.insertAdjacentElement('afterend', status);
  } else {
    target?.appendChild?.(status);
  }
  createdStatuses.add(status);

  return status;
};

const statusFor = (documentRef, target, createdStatuses) => (
  existingStatus(documentRef)
  ?? querySafely(target, `[${RUNTIME_STATUS_ATTRIBUTE}]`)
  ?? createRuntimeStatus(documentRef, target, createdStatuses)
);

const captureStatus = (status) => {
  if (!status) {
    return null;
  }

  return {
    status,
    state: status.dataset?.state ?? status.getAttribute?.('data-state') ?? '',
    text: status.textContent ?? '',
    hidden: Boolean(status.hidden),
    style: status.style?.cssText ?? null,
  };
};

const restoreStatus = (snapshot) => {
  const status = snapshot?.status;
  if (!status) {
    return;
  }
  if (status.dataset) {
    status.dataset.state = snapshot.state;
  } else {
    status.setAttribute?.('data-state', snapshot.state);
  }
  status.textContent = snapshot.text;
  status.hidden = snapshot.hidden;
  if (snapshot.style !== null && status.style) {
    status.style.cssText = snapshot.style;
  }
};

const setStatus = (status, state, message = '') => {
  if (!status) {
    return;
  }
  if (status.dataset) {
    status.dataset.state = state;
  } else {
    status.setAttribute?.('data-state', state);
  }
  status.textContent = message;

  if (status.hasAttribute?.(RUNTIME_STATUS_ATTRIBUTE)) {
    const error = state === 'error';
    status.hidden = message === '';
    if (status.style) {
      status.style.cssText = error ? '' : VISUALLY_HIDDEN_STYLE;
    }
  }
};

const statusMessage = (status, state, documentRef, responseDocument = null) => {
  if (state === 'error') {
    return status?.dataset?.errorMessage || fallbackErrorMessage(documentRef);
  }
  if (state === 'success') {
    return status?.dataset?.message
      || String(responseDocument?.title ?? documentRef?.title ?? '').trim();
  }

  return status?.dataset?.loadingMessage ?? '';
};

const metadataSnapshot = (documentRef) => {
  const robots = querySafely(documentRef, 'meta[name="robots"]');
  const canonical = querySafely(documentRef, 'link[rel="canonical"]');

  return {
    title: documentRef?.title ?? '',
    robots,
    robotsContent: robots?.getAttribute?.('content') ?? null,
    canonical,
    canonicalHref: canonical?.getAttribute?.('href') ?? null,
  };
};

const restoreMetadata = (documentRef, snapshot) => {
  if (!documentRef || !snapshot) {
    return;
  }
  documentRef.title = snapshot.title;
  if (snapshot.robots && snapshot.robotsContent !== null) {
    snapshot.robots.setAttribute?.('content', snapshot.robotsContent);
  }
  if (snapshot.canonical && snapshot.canonicalHref !== null) {
    snapshot.canonical.setAttribute?.('href', snapshot.canonicalHref);
  }
};

const syncDocumentMetadata = (documentRef, responseDocument) => {
  if (typeof responseDocument?.title === 'string') {
    documentRef.title = responseDocument.title;
  }

  for (const [selector, attribute] of [
    ['meta[name="robots"]', 'content'],
    ['link[rel="canonical"]', 'href'],
  ]) {
    const incoming = querySafely(responseDocument, selector);
    const current = querySafely(documentRef, selector);
    const value = incoming?.getAttribute?.(attribute);
    if (incoming && current && value !== null && value !== undefined) {
      current.setAttribute?.(attribute, value);
    }
  }
};

const importedResults = (documentRef, responseDocument) => {
  const incoming = queryAllSafely(responseDocument, RESULTS_SELECTOR);
  if (incoming.length !== 1) {
    return null;
  }

  return typeof documentRef?.importNode === 'function'
    ? documentRef.importNode(incoming[0], true)
    : incoming[0].cloneNode?.(true) ?? null;
};

const replaceResults = (current, incoming) => {
  if (typeof current?.replaceWith === 'function') {
    current.replaceWith(incoming);
    return true;
  }
  if (typeof current?.parentNode?.replaceChild === 'function') {
    current.parentNode.replaceChild(incoming, current);
    return true;
  }

  return false;
};

const emitResultsUpdated = (documentRef, view, target, url) => {
  const CustomEventConstructor = view.CustomEvent ?? globalThis.CustomEvent;
  if (
    typeof documentRef?.dispatchEvent !== 'function'
    || typeof CustomEventConstructor !== 'function'
  ) {
    return;
  }
  documentRef.dispatchEvent(new CustomEventConstructor(RESULTS_UPDATED_EVENT, {
    bubbles: false,
    detail: {
      target,
      url: url.href,
      navigation: 'pagination',
    },
  }));
};

const focusResults = (target) => {
  const focusTarget = querySafely(target, [
    '[data-blog-card-key] h1',
    '[data-blog-card-key] h2',
    '[data-blog-card-key] h3',
    '[data-blog-card-key] h4',
    '[data-blog-card-key] h5',
    '[data-blog-card-key] h6',
  ].join(', ')) ?? querySafely(
    target,
    '[data-blog-collection-status]:not([hidden])',
  ) ?? target;
  if (!focusTarget || typeof focusTarget.focus !== 'function') {
    return;
  }

  const hadTabindex = focusTarget.hasAttribute?.('tabindex') ?? false;
  if (!hadTabindex) {
    focusTarget.setAttribute?.('tabindex', '-1');
  }
  try {
    focusTarget.focus({ preventScroll: true });
  } catch {
    focusTarget.focus();
  }
  target.scrollIntoView?.({ block: 'start', behavior: 'auto' });
  if (!hadTabindex) {
    focusTarget.addEventListener?.('blur', () => {
      focusTarget.removeAttribute?.('tabindex');
    }, { once: true });
  }
};

const scheduleMicrotask = (view, callback) => {
  const scheduler = view.queueMicrotask ?? globalThis.queueMicrotask;
  if (typeof scheduler === 'function') {
    scheduler(callback);
  } else {
    Promise.resolve().then(callback);
  }
};

const installPagination = (scope) => {
  const documentRef = scope?.nodeType === 9
    ? scope
    : scope?.ownerDocument ?? globalThis.document;
  const view = getView(documentRef);
  const Parser = view.DOMParser ?? globalThis.DOMParser;
  const AbortControllerConstructor = view.AbortController
    ?? globalThis.AbortController;
  const initialTarget = resultsTarget(documentRef);
  const canEnhance = Boolean(
    initialTarget
    && typeof view.fetch === 'function'
    && typeof Parser === 'function'
    && typeof AbortControllerConstructor === 'function'
    && view.history
    && typeof view.history.pushState === 'function'
    && typeof view.URL === 'function',
  );
  if (!canEnhance) {
    return () => {};
  }

  const listeners = new AbortControllerConstructor();
  const createdStatuses = new Set();
  let generation = 0;
  let activeRequest = null;
  let disposed = false;

  const clearRequestTimer = (request) => {
    if (!request || request.timer === null) {
      return;
    }
    const clearTimer = view.clearTimeout ?? globalThis.clearTimeout;
    clearTimer?.(request.timer);
    request.timer = null;
  };

  const abortActiveRequest = (restore = true) => {
    const request = activeRequest;
    if (!request) {
      return null;
    }
    generation += 1;
    activeRequest = null;
    clearRequestTimer(request);
    request.controller.abort();
    setOwnBusy(request.target, false);
    if (restore) {
      restoreStatus(request.statusSnapshot);
    }

    return request.statusSnapshot;
  };

  const communicateError = (target, statusSnapshot = null) => {
    const status = statusSnapshot?.status
      ?? statusFor(documentRef, target, createdStatuses);
    setStatus(
      status,
      'error',
      statusMessage(status, 'error', documentRef),
    );
  };

  const requestPage = async (url, {
    historyMode = 'push',
    shouldFocus = true,
  } = {}) => {
    const target = resultsTarget(documentRef);
    if (!target || foreignResultsActivity(documentRef, target)) {
      return false;
    }

    const previousSnapshot = activeRequest?.statusSnapshot ?? null;
    abortActiveRequest(false);
    const status = previousSnapshot?.status
      ?? statusFor(documentRef, target, createdStatuses);
    const statusSnapshot = previousSnapshot ?? captureStatus(status);
    const ownGeneration = ++generation;
    const controller = new AbortControllerConstructor();
    const request = {
      controller,
      generation: ownGeneration,
      target,
      timer: null,
      statusSnapshot,
    };
    activeRequest = request;
    setOwnBusy(target, true);
    setStatus(
      status,
      'loading',
      statusMessage(status, 'loading', documentRef),
    );

    const setTimer = view.setTimeout ?? globalThis.setTimeout;
    request.timer = setTimer?.(() => {
      if (
        disposed
        || activeRequest !== request
        || generation !== ownGeneration
      ) {
        return;
      }
      generation += 1;
      activeRequest = null;
      request.timer = null;
      controller.abort();
      setOwnBusy(target, false);
      communicateError(target, statusSnapshot);
    }, REQUEST_TIMEOUT_MS) ?? null;

    try {
      const response = await view.fetch(url.href, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          Accept: 'text/html',
          'X-LiquidStack-Partial': PARTIAL_NAME,
        },
        signal: controller.signal,
      });
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }
      const finalUrl = normalizedSameOriginUrl(
        response.url || url.href,
        view,
        url.href,
      );
      if (!finalUrl) {
        throw new Error('Cross-origin Blog response rejected.');
      }
      const responseDocument = new Parser().parseFromString(
        await response.text(),
        'text/html',
      );
      const incomingTarget = importedResults(documentRef, responseDocument);
      if (!incomingTarget) {
        throw new Error('Invalid Blog results fragment.');
      }
      if (
        disposed
        || activeRequest !== request
        || generation !== ownGeneration
      ) {
        return true;
      }

      const oldMetadata = metadataSnapshot(documentRef);
      setOwnBusy(target, false);
      if (!replaceResults(target, incomingTarget)) {
        throw new Error('Blog results target could not be replaced.');
      }
      syncDocumentMetadata(documentRef, responseDocument);

      if (historyMode === 'push' && finalUrl.href !== view.location?.href) {
        try {
          view.history.pushState(
            { liquidstackBlogPagination: true },
            '',
            finalUrl.href,
          );
        } catch (error) {
          replaceResults(incomingTarget, target);
          restoreMetadata(documentRef, oldMetadata);
          throw error;
        }
      }

      clearRequestTimer(request);
      activeRequest = null;
      const successStatus = statusFor(
        documentRef,
        incomingTarget,
        createdStatuses,
      );
      setStatus(
        successStatus,
        'success',
        statusMessage(
          successStatus,
          'success',
          documentRef,
          responseDocument,
        ),
      );
      emitResultsUpdated(documentRef, view, incomingTarget, finalUrl);
      if (shouldFocus) {
        focusResults(incomingTarget);
      }

      return true;
    } catch (error) {
      if (
        !disposed
        && activeRequest === request
        && generation === ownGeneration
        && error?.name !== 'AbortError'
      ) {
        clearRequestTimer(request);
        activeRequest = null;
        setOwnBusy(target, false);
        communicateError(resultsTarget(documentRef) ?? target, statusSnapshot);
      }

      return false;
    }
  };

  const requestFromHref = (rawHref, options) => {
    const url = normalizedSameOriginUrl(
      rawHref,
      view,
      view.location?.href,
    );
    if (!url) {
      return false;
    }
    void requestPage(url, options);

    return true;
  };

  const onClick = (event) => {
    if (event.defaultPrevented || !isPlainClick(event)) {
      return;
    }
    const anchor = closestSafely(event.target, PAGINATION_LINK_SELECTOR);
    const target = resultsTarget(documentRef);
    if (
      !anchor
      || !target
      || !targetContains(target, anchor)
      || !linkCanBeEnhanced(anchor)
      || foreignResultsActivity(documentRef, target)
    ) {
      return;
    }
    const rawHref = anchor.getAttribute?.('href') ?? anchor.href ?? '';
    const url = normalizedSameOriginUrl(
      rawHref,
      view,
      view.location?.href,
    );
    if (!url) {
      return;
    }

    event.preventDefault();
    void requestPage(url, { historyMode: 'push', shouldFocus: true });
  };

  const onFilterInteraction = (event) => {
    const form = closestSafely(event.target, FILTER_FORM_SELECTOR);
    if (formBelongsToResults(form)) {
      abortActiveRequest(true);
    }
  };

  const onPopState = () => {
    // Back/forward invalidates a click request immediately. The filter runtime
    // may start its own restoration in the same event turn; no older response
    // is then able to win that race.
    abortActiveRequest(true);
    scheduleMicrotask(view, () => {
      if (disposed) {
        return;
      }
      const target = resultsTarget(documentRef);
      // _moduleBlogFilters01 owns this popstate when it has already marked the
      // shared target/form busy. If it is absent, pagination restores itself.
      if (!target || foreignResultsActivity(documentRef, target)) {
        return;
      }
      requestFromHref(view.location?.href, {
        historyMode: 'none',
        shouldFocus: false,
      });
    });
  };

  const onPageHide = () => {
    abortActiveRequest(true);
  };
  const onPageShow = () => {
    const target = resultsTarget(documentRef);
    if (attributeIsTrue(target, OWN_BUSY_ATTRIBUTE)) {
      setOwnBusy(target, false);
    }
  };

  documentRef.addEventListener('click', onClick, {
    signal: listeners.signal,
  });
  for (const type of ['input', 'change', 'submit', 'reset']) {
    documentRef.addEventListener(type, onFilterInteraction, {
      capture: true,
      signal: listeners.signal,
    });
  }
  view.addEventListener('popstate', onPopState, {
    signal: listeners.signal,
  });
  view.addEventListener('pagehide', onPageHide, {
    signal: listeners.signal,
  });
  view.addEventListener('pageshow', onPageShow, {
    signal: listeners.signal,
  });

  return () => {
    if (disposed) {
      return;
    }
    disposed = true;
    listeners.abort();
    abortActiveRequest(true);
    for (const status of createdStatuses) {
      status.remove?.();
    }
    createdStatuses.clear();
    const target = resultsTarget(documentRef);
    if (attributeIsTrue(target, OWN_BUSY_ATTRIBUTE)) {
      setOwnBusy(target, false);
    }
  };
};

export const cleanupModuleBlogPagination01 = () => {
  disposeActivePagination();
  disposeActivePagination = () => {};
};

export const initModuleBlogPagination01 = (scope = globalThis.document) => {
  cleanupModuleBlogPagination01();
  const cleanup = installPagination(scope);
  let cleaned = false;

  disposeActivePagination = () => {
    if (cleaned) {
      return;
    }
    cleaned = true;
    cleanup();
  };

  return disposeActivePagination;
};

export default initModuleBlogPagination01;

if (import.meta.hot) {
  import.meta.hot.dispose(cleanupModuleBlogPagination01);
}
