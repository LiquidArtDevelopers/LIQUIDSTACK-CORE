const FORM_SELECTOR = '[data-blog-filter-form]';
const RESULTS_UPDATED_EVENT = 'liquidstack:blog-results-updated';
const QUERY_DEBOUNCE_MS = 350;

let disposeActiveFilters = () => {};

const querySafely = (scope, selector) => {
  if (!scope || typeof scope.querySelector !== 'function' || !selector) {
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

const setBusy = (form, target, busy) => {
  for (const element of [form, target]) {
    if (!element || typeof element.setAttribute !== 'function') {
      continue;
    }

    if (busy) {
      element.setAttribute('aria-busy', 'true');
    } else {
      element.removeAttribute('aria-busy');
    }
  }
};

const isAbortError = (error) => error?.name === 'AbortError';

const buildRequestUrl = (form, view) => {
  const currentHref = view.location?.href ?? '/';
  const url = new URL(form.action || currentHref, currentHref);
  const FormDataConstructor = view.FormData ?? globalThis.FormData;
  const data = new FormDataConstructor(form);
  const params = new URLSearchParams();

  for (const [name, rawValue] of data) {
    if (typeof rawValue !== 'string') {
      continue;
    }

    // Equivale al GET nativo: conserva espacios y controles exitosos vacíos.
    params.append(name, rawValue);
  }

  url.search = params.toString();
  url.hash = '';

  return url;
};

const syncDocumentMetadata = (documentRef, responseDocument) => {
  if (!documentRef || !responseDocument) {
    return;
  }

  if (typeof responseDocument.title === 'string') {
    documentRef.title = responseDocument.title;
  }

  const syncHeadElement = (selector, attribute) => {
    const incoming = querySafely(responseDocument, selector);
    const current = querySafely(documentRef, selector);
    if (!incoming) {
      current?.remove?.();
      return;
    }

    const value = incoming.getAttribute?.(attribute);
    if (current) {
      if (value === null || value === undefined) {
        current.removeAttribute?.(attribute);
      } else {
        current.setAttribute?.(attribute, value);
      }
      return;
    }

    const clone = typeof documentRef.importNode === 'function'
      ? documentRef.importNode(incoming, true)
      : incoming.cloneNode?.(true);
    documentRef.head?.appendChild?.(clone);
  };

  syncHeadElement('meta[name="robots"]', 'content');
  syncHeadElement('link[rel="canonical"]', 'href');
};

const syncFormControls = (form, sourceForm) => {
  if (!form?.elements || !sourceForm?.elements) {
    return;
  }

  const sourceByKey = new Map();
  for (const source of Array.from(sourceForm.elements)) {
    if (!source?.name) {
      continue;
    }

    const key = `${source.name}\u0000${source.value ?? ''}`;
    sourceByKey.set(key, source);
    if (!sourceByKey.has(source.name)) {
      sourceByKey.set(source.name, source);
    }
  }

  for (const control of Array.from(form.elements)) {
    if (!control?.name) {
      continue;
    }

    const key = `${control.name}\u0000${control.value ?? ''}`;
    const source = sourceByKey.get(key) ?? sourceByKey.get(control.name);
    if (!source) {
      continue;
    }

    if (control.type === 'checkbox' || control.type === 'radio') {
      control.checked = Boolean(source.checked);
    } else if ('value' in control) {
      control.value = source.value ?? '';
    }
  }
};

const emitResultsUpdated = (target, view, url) => {
  const documentRef = target?.ownerDocument;
  const CustomEventConstructor = view.CustomEvent ?? globalThis.CustomEvent;
  if (!documentRef || typeof documentRef.dispatchEvent !== 'function'
      || typeof CustomEventConstructor !== 'function') {
    return;
  }

  documentRef.dispatchEvent(new CustomEventConstructor(
    RESULTS_UPDATED_EVENT,
    {
      bubbles: false,
      detail: { target, url: url.href },
    },
  ));
};

const installForm = (form) => {
  const documentRef = form.ownerDocument ?? globalThis.document;
  const view = getView(form);
  const targetSelector = form.dataset?.blogResultsTarget ?? '';
  const initialTarget = querySafely(documentRef, targetSelector);
  const method = String(form.getAttribute?.('method') ?? form.method ?? 'get')
    .toLowerCase();
  const canEnhance = Boolean(
    initialTarget
    && method === 'get'
    && typeof view.fetch === 'function'
    && typeof (view.DOMParser ?? globalThis.DOMParser) === 'function'
    && typeof (view.AbortController ?? globalThis.AbortController) === 'function'
    && view.history
    && typeof view.history.pushState === 'function'
    && typeof view.history.replaceState === 'function',
  );

  // Sin un destino SSR real, el formulario conserva su navegación GET nativa.
  if (!canEnhance) {
    return () => {};
  }

  const listenerController = new (view.AbortController
    ?? globalThis.AbortController)();
  const status = querySafely(form, '[data-blog-filter-status]');
  let requestController = null;
  let requestGeneration = 0;
  let queryTimer = null;
  let liveSearchHasHistoryEntry = false;
  let disposed = false;

  const currentTarget = () => querySafely(documentRef, targetSelector);
  const navigate = (url) => {
    if (!disposed && typeof view.location?.assign === 'function') {
      view.location.assign(url.href);
    }
  };

  const invalidateActiveRequest = () => {
    requestGeneration += 1;
    requestController?.abort();
    requestController = null;
    setBusy(form, currentTarget(), false);
  };

  const resetLiveSearchSequence = () => {
    liveSearchHasHistoryEntry = false;
  };

  const clearQueryTimer = () => {
    if (queryTimer !== null) {
      view.clearTimeout(queryTimer);
      queryTimer = null;
    }
  };

  const request = async (url, historyMode = 'push') => {
    const target = currentTarget();
    if (!target) {
      navigate(url);
      return;
    }

    requestController?.abort();
    const ownGeneration = ++requestGeneration;
    requestController = new (view.AbortController
      ?? globalThis.AbortController)();
    const ownController = requestController;
    setBusy(form, target, true);
    if (status) {
      status.textContent = '';
    }

    try {
      const response = await view.fetch(url.href, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          Accept: 'text/html',
          'X-LiquidStack-Partial': 'blog-results',
        },
        signal: ownController.signal,
      });
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const Parser = view.DOMParser ?? globalThis.DOMParser;
      const responseDocument = new Parser().parseFromString(
        await response.text(),
        'text/html',
      );
      if (
        ownGeneration !== requestGeneration
        || ownController !== requestController
        || disposed
      ) {
        return;
      }

      const incomingTarget = querySafely(responseDocument, targetSelector);
      if (!incomingTarget) {
        navigate(url);
        return;
      }

      target.innerHTML = incomingTarget.innerHTML;
      const incomingForm = form.id && typeof responseDocument.getElementById === 'function'
        ? responseDocument.getElementById(form.id)
        : querySafely(responseDocument, FORM_SELECTOR);
      syncFormControls(form, incomingForm);
      syncDocumentMetadata(documentRef, responseDocument);

      if (url.href !== view.location.href) {
        if (historyMode === 'live-search') {
          const historyMethod = liveSearchHasHistoryEntry
            ? 'replaceState'
            : 'pushState';
          view.history[historyMethod](
            { liquidstackBlogFilters: true },
            '',
            url.href,
          );
          liveSearchHasHistoryEntry = true;
        } else if (historyMode === 'push') {
          view.history.pushState(
            { liquidstackBlogFilters: true },
            '',
            url.href,
          );
        }
      }
      if (status) {
        status.textContent = status.dataset?.message ?? '';
      }
      emitResultsUpdated(target, view, url);
    } catch (error) {
      if (
        ownGeneration === requestGeneration
        && ownController === requestController
        && !disposed
        && !isAbortError(error)
      ) {
        navigate(url);
      }
    } finally {
      if (
        ownGeneration === requestGeneration
        && ownController === requestController
      ) {
        setBusy(form, currentTarget() ?? target, false);
        requestController = null;
      }
    }
  };

  const requestFromForm = (historyMode = 'push') => {
    if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
      return false;
    }

    let url;
    try {
      url = buildRequestUrl(form, view);
    } catch {
      return false;
    }

    if (url.origin !== view.location.origin) {
      navigate(url);
      return false;
    }
    void request(url, historyMode);

    return true;
  };

  const onSubmit = (event) => {
    if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
      return;
    }

    event.preventDefault();
    clearQueryTimer();
    resetLiveSearchSequence();
    requestFromForm('push');
  };
  const onInput = (event) => {
    if (event.target?.name !== 'q') {
      return;
    }

    // Invalida ya, antes de la pausa: una respuesta vieja nunca pisa el input.
    invalidateActiveRequest();
    if (status) {
      status.textContent = '';
    }
    clearQueryTimer();
    queryTimer = view.setTimeout(() => {
      queryTimer = null;
      requestFromForm('live-search');
    }, QUERY_DEBOUNCE_MS);
  };
  const onChange = (event) => {
    const control = event.target;
    if (control?.name !== 'category[]' && control?.name !== 'category_mode') {
      return;
    }

    clearQueryTimer();
    resetLiveSearchSequence();
    requestFromForm('push');
  };
  const onPopState = () => {
    clearQueryTimer();
    resetLiveSearchSequence();
    let url;
    try {
      url = new URL(view.location.href);
    } catch {
      return;
    }

    void request(url, 'none');
  };

  form.addEventListener('submit', onSubmit, { signal: listenerController.signal });
  form.addEventListener('input', onInput, { signal: listenerController.signal });
  form.addEventListener('change', onChange, { signal: listenerController.signal });
  view.addEventListener('popstate', onPopState, { signal: listenerController.signal });

  return () => {
    disposed = true;
    listenerController.abort();
    invalidateActiveRequest();
    clearQueryTimer();
    setBusy(form, currentTarget(), false);
  };
};

export const cleanupModuleBlogFilters01 = () => {
  disposeActiveFilters();
  disposeActiveFilters = () => {};
};

export const initModuleBlogFilters01 = (scope = globalThis.document) => {
  cleanupModuleBlogFilters01();
  const cleanups = queryAllSafely(scope, FORM_SELECTOR).map(installForm);
  let cleaned = false;

  disposeActiveFilters = () => {
    if (cleaned) {
      return;
    }
    cleaned = true;
    for (const cleanup of cleanups) {
      cleanup();
    }
  };

  return disposeActiveFilters;
};

export default initModuleBlogFilters01;

if (import.meta.hot) {
  import.meta.hot.dispose(cleanupModuleBlogFilters01);
}
