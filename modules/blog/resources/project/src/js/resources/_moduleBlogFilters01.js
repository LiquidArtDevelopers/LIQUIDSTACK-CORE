const FORM_SELECTOR = '[data-blog-filter-form]';
const RESULTS_UPDATED_EVENT = 'liquidstack:blog-results-updated';
const QUERY_DEBOUNCE_MS = 350;
const REQUEST_TIMEOUT_MS = 12_000;
const PRESERVED_STATE_NAMES = new Set([
  'q',
  'order',
  'category[]',
  'category_mode',
]);

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

const setResultsStale = (target, stale) => {
  if (!target || typeof target.setAttribute !== 'function') {
    return;
  }

  if (stale) {
    target.setAttribute('data-blog-results-stale', 'true');
  } else {
    target.removeAttribute('data-blog-results-stale');
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

const syncPreservedHiddenState = (form, sourceForm) => {
  if (!form || !sourceForm) {
    return;
  }

  const isPreservedHidden = (control) => (
    control?.type === 'hidden'
    && PRESERVED_STATE_NAMES.has(control.name ?? '')
  );
  const sourceControls = queryAllSafely(
    sourceForm,
    'input[type="hidden"][name]',
  ).filter(isPreservedHidden);

  for (const control of queryAllSafely(
    form,
    'input[type="hidden"][name]',
  ).filter(isPreservedHidden)) {
    control.remove?.();
  }

  const documentRef = form.ownerDocument;
  const anchor = form.firstChild ?? null;
  for (const source of sourceControls) {
    const clone = typeof documentRef?.importNode === 'function'
      ? documentRef.importNode(source, true)
      : source.cloneNode?.(true);
    if (!clone) {
      continue;
    }

    if (typeof form.insertBefore === 'function') {
      form.insertBefore(clone, anchor);
    } else if (typeof form.prepend === 'function') {
      form.prepend(clone);
    }
  }
};

const syncFilterReset = (form, sourceForm) => {
  const reset = querySafely(form, '[data-blog-filter-reset]');
  const incomingReset = querySafely(
    sourceForm,
    '[data-blog-filter-reset]',
  );
  if (!reset || !incomingReset) {
    return;
  }

  const href = incomingReset.getAttribute?.('href');
  if (href === null || href === undefined) {
    reset.removeAttribute?.('href');
  } else {
    reset.setAttribute?.('href', href);
  }
  reset.hidden = Boolean(incomingReset.hidden);
};

const controlsNamed = (form, name) => Array.from(form?.elements ?? [])
  .filter((control) => control?.name === name);

const visibleControlsNamed = (form, name) => controlsNamed(form, name)
  .filter((control) => control.type !== 'hidden');

const replacePreservedHiddenValues = (form, entries) => {
  const documentRef = form?.ownerDocument;
  if (typeof documentRef?.createElement !== 'function') {
    return;
  }

  for (const control of queryAllSafely(
    form,
    'input[type="hidden"][name]',
  )) {
    if (PRESERVED_STATE_NAMES.has(control?.name ?? '')) {
      control.remove?.();
    }
  }

  const anchor = form.firstChild ?? null;
  for (const [name, value] of entries) {
    const hidden = documentRef.createElement('input');
    hidden.type = 'hidden';
    hidden.name = name;
    hidden.value = value;
    form.insertBefore?.(hidden, anchor);
  }
};

const readSharedFormState = (forms) => {
  const capabilities = new Set();
  for (const form of forms) {
    for (const control of Array.from(form?.elements ?? [])) {
      if (PRESERVED_STATE_NAMES.has(control?.name ?? '')) {
        capabilities.add(control.name);
      }
    }
  }

  const firstVisible = (name) => forms
    .flatMap((form) => visibleControlsNamed(form, name))[0] ?? null;
  const firstControl = (name) => forms
    .flatMap((form) => controlsNamed(form, name))[0] ?? null;
  const categorySource = forms.find(
    (form) => visibleControlsNamed(form, 'category[]').length > 0,
  );
  const categoryControls = categorySource
    ? visibleControlsNamed(categorySource, 'category[]')
    : forms.flatMap((form) => controlsNamed(form, 'category[]'));

  return {
    capabilities,
    query: String((firstVisible('q') ?? firstControl('q'))?.value ?? ''),
    order: String(
      (firstVisible('order') ?? firstControl('order'))?.value ?? 'newest',
    ),
    categories: categoryControls
      .filter((control) => control.type === 'hidden' || control.checked)
      .map((control) => String(control.value ?? ''))
      .filter((value, index, values) => value && values.indexOf(value) === index),
    categoryMode: String(
      (firstVisible('category_mode') ?? firstControl('category_mode'))?.value
        ?? 'any',
    ) === 'all' ? 'all' : 'any',
  };
};

const synchronizeSharedFormState = (forms) => {
  const state = readSharedFormState(forms);
  for (const form of forms) {
    const visibleQuery = visibleControlsNamed(form, 'q');
    const visibleOrder = visibleControlsNamed(form, 'order');
    const visibleCategories = visibleControlsNamed(form, 'category[]');
    const visibleMode = visibleControlsNamed(form, 'category_mode');

    for (const control of visibleQuery) {
      control.value = state.query;
    }
    for (const control of visibleOrder) {
      control.value = state.order;
    }
    for (const control of visibleCategories) {
      control.checked = state.categories.includes(String(control.value ?? ''));
    }
    for (const control of visibleMode) {
      control.value = state.categoryMode;
    }

    const hiddenEntries = [];
    if (state.capabilities.has('q') && visibleQuery.length === 0
        && state.query !== '') {
      hiddenEntries.push(['q', state.query]);
    }
    if (state.capabilities.has('order') && visibleOrder.length === 0) {
      hiddenEntries.push(['order', state.order]);
    }
    if (state.capabilities.has('category[]') && visibleCategories.length === 0) {
      for (const category of state.categories) {
        hiddenEntries.push(['category[]', category]);
      }
      if (state.categories.length > 0 && visibleMode.length === 0) {
        hiddenEntries.push(['category_mode', state.categoryMode]);
      }
    }
    replacePreservedHiddenValues(form, hiddenEntries);
  }

  return state;
};

const syncFilterForms = (
  responseDocument,
  forms,
  activeForm,
) => {
  const incomingForms = queryAllSafely(responseDocument, FORM_SELECTOR);
  const incomingById = new Map(
    incomingForms
      .filter((form) => form?.id)
      .map((form) => [form.id, form]),
  );

  for (const form of forms) {
    let sourceForm = form?.id ? incomingById.get(form.id) : null;
    if (!sourceForm && form === activeForm && forms.length === 1) {
      sourceForm = incomingForms[0] ?? null;
    }
    if (!sourceForm) {
      continue;
    }

    syncPreservedHiddenState(form, sourceForm);
    syncFormControls(form, sourceForm);
    syncFilterReset(form, sourceForm);
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

const installFormGroup = (forms) => {
  const firstForm = forms[0];
  if (!firstForm) {
    return () => {};
  }

  const documentRef = firstForm.ownerDocument ?? globalThis.document;
  const view = getView(firstForm);
  const targetSelector = firstForm.dataset?.blogResultsTarget ?? '';
  const initialTarget = querySafely(documentRef, targetSelector);
  const canEnhanceTarget = Boolean(
    initialTarget
    && typeof view.fetch === 'function'
    && typeof (view.DOMParser ?? globalThis.DOMParser) === 'function'
    && typeof (view.AbortController ?? globalThis.AbortController) === 'function'
    && view.history
    && typeof view.history.pushState === 'function'
    && typeof view.history.replaceState === 'function',
  );
  const enhancedForms = canEnhanceTarget
    ? forms.filter((form) => {
      const method = String(
        form.getAttribute?.('method') ?? form.method ?? 'get',
      ).toLowerCase();

      return method === 'get';
    })
    : [];

  if (enhancedForms.length === 0) {
    return () => {};
  }

  const enhancedSubmitButtons = enhancedForms
    .map((form) => querySafely(form, '[data-blog-filter-submit]'))
    .filter(Boolean);
  for (const button of enhancedSubmitButtons) {
    button.hidden = true;
  }

  const listenerController = new (view.AbortController
    ?? globalThis.AbortController)();
  const statuses = new Map(enhancedForms.map((form) => [
    form,
    querySafely(form, '[data-blog-filter-status]'),
  ]));
  let requestController = null;
  let requestTimer = null;
  let requestGeneration = 0;
  let queryTimer = null;
  let liveSearchHasHistoryEntry = false;
  let disposed = false;

  const currentTarget = () => querySafely(documentRef, targetSelector);
  const setFilterStatus = (status, state, message = '') => {
    if (!status) {
      return;
    }
    if (status.dataset) {
      status.dataset.state = state;
    } else {
      status.setAttribute?.('data-state', state);
    }
    status.textContent = message;
  };
  const resetGroupStatuses = (state = 'idle') => {
    for (const status of statuses.values()) {
      setFilterStatus(status, state);
    }
  };
  const communicateRequestError = (activeForm, target) => {
    setResultsStale(target, false);
    resetGroupStatuses();
    const status = statuses.get(activeForm);
    setFilterStatus(status, 'error', status?.dataset?.errorMessage ?? '');
  };
  const communicateValidity = (activeForm = enhancedForms[0]) => {
    let valid = true;
    let message = '';
    let invalidForm = null;
    for (const form of enhancedForms) {
      let formIsValid = typeof form.checkValidity !== 'function'
        || form.checkValidity();
      for (const control of visibleControlsNamed(form, 'q')) {
        const queryLength = Array.from(
          String(control.value ?? '').trim(),
        ).length;
        const queryIsValid = queryLength === 0 || queryLength >= 2;
        const controlIsValid = queryIsValid && (
          typeof control.checkValidity !== 'function'
          || control.checkValidity()
        );
        formIsValid = formIsValid && controlIsValid;
        if (controlIsValid) {
          control.removeAttribute?.('aria-invalid');
        } else {
          control.setAttribute?.('aria-invalid', 'true');
          message ||= queryIsValid
            ? String(control.validationMessage ?? '')
            : String(control.dataset?.minlengthMessage ?? '');
        }
      }
      valid = valid && formIsValid;
      if (!formIsValid) {
        invalidForm ??= form;
      }
    }
    if (!valid) {
      setResultsStale(currentTarget(), true);
      const status = statuses.get(invalidForm ?? activeForm);
      setFilterStatus(status, 'invalid', message);
    }

    return valid;
  };
  const setGroupBusy = (busy) => {
    for (const form of enhancedForms) {
      setBusy(form, null, busy);
    }
    setBusy(null, currentTarget(), busy);
  };
  const navigate = (url) => {
    if (!disposed && typeof view.location?.assign === 'function') {
      view.location.assign(url.href);
    }
  };
  const clearRequestTimer = () => {
    if (requestTimer === null) {
      return;
    }
    const clearTimer = view.clearTimeout ?? globalThis.clearTimeout;
    clearTimer?.(requestTimer);
    requestTimer = null;
  };
  const invalidateActiveRequest = () => {
    requestGeneration += 1;
    clearRequestTimer();
    requestController?.abort();
    requestController = null;
    setGroupBusy(false);
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

  const request = async (
    url,
    activeForm = enhancedForms[0],
    historyMode = 'push',
  ) => {
    const target = currentTarget();
    if (!target) {
      navigate(url);
      return;
    }

    clearRequestTimer();
    requestController?.abort();
    const ownGeneration = ++requestGeneration;
    requestController = new (view.AbortController
      ?? globalThis.AbortController)();
    const ownController = requestController;
    setResultsStale(target, true);
    setGroupBusy(true);
    resetGroupStatuses('loading');
    const setTimer = view.setTimeout ?? globalThis.setTimeout;
    requestTimer = setTimer?.(() => {
      if (
        disposed
        || ownGeneration !== requestGeneration
        || requestController !== ownController
      ) {
        return;
      }

      // El timeout invalida su propia generacion: incluso un fetch que ignore
      // abort() no puede aplicar tarde una respuesta ni sustituir el SSR visible.
      requestGeneration += 1;
      requestController = null;
      requestTimer = null;
      ownController.abort();
      setGroupBusy(false);
      communicateRequestError(activeForm, target);
    }, REQUEST_TIMEOUT_MS) ?? null;

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
        throw new Error('Invalid Blog results fragment.');
      }

      target.innerHTML = incomingTarget.innerHTML;
      syncFilterForms(
        responseDocument,
        enhancedForms,
        activeForm,
      );
      synchronizeSharedFormState(enhancedForms);
      syncDocumentMetadata(documentRef, responseDocument);
      setResultsStale(target, false);

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
      const status = statuses.get(activeForm);
      resetGroupStatuses();
      setFilterStatus(
        status,
        'success',
        status?.dataset?.message ?? '',
      );
      emitResultsUpdated(target, view, url);
    } catch (error) {
      if (
        ownGeneration === requestGeneration
        && ownController === requestController
        && !disposed
        && !isAbortError(error)
      ) {
        communicateRequestError(activeForm, currentTarget() ?? target);
      }
    } finally {
      if (
        ownGeneration === requestGeneration
        && ownController === requestController
      ) {
        clearRequestTimer();
        setGroupBusy(false);
        requestController = null;
      }
    }
  };

  const requestFromForm = (form, historyMode = 'push') => {
    synchronizeSharedFormState(enhancedForms);
    if (!communicateValidity(form)) {
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
    void request(url, form, historyMode);

    return true;
  };

  const scheduleRequestFromForm = (form, resetHistory = false) => {
    synchronizeSharedFormState(enhancedForms);
    if (resetHistory) {
      resetLiveSearchSequence();
    }
    invalidateActiveRequest();
    setResultsStale(currentTarget(), true);
    setFilterStatus(statuses.get(form), 'idle');
    clearQueryTimer();
    if (!communicateValidity(form)) {
      return;
    }
    setBusy(null, currentTarget(), true);
    queryTimer = view.setTimeout(() => {
      queryTimer = null;
      requestFromForm(form, 'live-search');
    }, QUERY_DEBOUNCE_MS);
  };

  for (const form of enhancedForms) {
    const onSubmit = (event) => {
      synchronizeSharedFormState(enhancedForms);
      if (!communicateValidity(form)) {
        event.preventDefault();
        return;
      }
      event.preventDefault();
      clearQueryTimer();
      resetLiveSearchSequence();
      requestFromForm(form, 'push');
    };
    const onInput = (event) => {
      if (event.target?.name !== 'q') {
        return;
      }
      scheduleRequestFromForm(form);
    };
    const onChange = (event) => {
      const control = event.target;
      if (PRESERVED_STATE_NAMES.has(control?.name ?? '')) {
        synchronizeSharedFormState(enhancedForms);
      }
      if (
        control?.name !== 'order'
        && control?.name !== 'category[]'
        && control?.name !== 'category_mode'
      ) {
        return;
      }
      scheduleRequestFromForm(form, true);
    };
    const onClick = (event) => {
      const reset = event.target?.closest?.('[data-blog-filter-reset]');
      if (!reset || !form.contains?.(reset)) {
        return;
      }
      let url;
      try {
        url = new URL(reset.href, view.location.href);
      } catch {
        return;
      }
      if (url.origin !== view.location.origin) {
        return;
      }
      event.preventDefault();
      clearQueryTimer();
      resetLiveSearchSequence();
      void request(url, form, 'push');
    };

    form.addEventListener('submit', onSubmit, {
      signal: listenerController.signal,
    });
    form.addEventListener('input', onInput, {
      signal: listenerController.signal,
    });
    form.addEventListener('change', onChange, {
      signal: listenerController.signal,
    });
    form.addEventListener('click', onClick, {
      signal: listenerController.signal,
    });
  }

  const onPopState = () => {
    clearQueryTimer();
    resetLiveSearchSequence();
    let url;
    try {
      url = new URL(view.location.href);
    } catch {
      return;
    }
    void request(url, enhancedForms[0], 'none');
  };
  view.addEventListener('popstate', onPopState, {
    signal: listenerController.signal,
  });

  synchronizeSharedFormState(enhancedForms);
  communicateValidity();

  return () => {
    disposed = true;
    listenerController.abort();
    invalidateActiveRequest();
    clearQueryTimer();
    setGroupBusy(false);
    setResultsStale(currentTarget(), false);
    for (const button of enhancedSubmitButtons) {
      button.hidden = false;
    }
  };
};

export const cleanupModuleBlogFilters01 = () => {
  disposeActiveFilters();
  disposeActiveFilters = () => {};
};

export const initModuleBlogFilters01 = (scope = globalThis.document) => {
  cleanupModuleBlogFilters01();
  const groups = [];
  for (const form of queryAllSafely(scope, FORM_SELECTOR)) {
    const documentRef = form.ownerDocument ?? globalThis.document;
    const targetSelector = form.dataset?.blogResultsTarget ?? '';
    let group = groups.find((candidate) => (
      candidate.documentRef === documentRef
      && candidate.targetSelector === targetSelector
    ));
    if (!group) {
      group = { documentRef, targetSelector, forms: [] };
      groups.push(group);
    }
    group.forms.push(form);
  }
  const cleanups = groups.map((group) => installFormGroup(group.forms));
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
