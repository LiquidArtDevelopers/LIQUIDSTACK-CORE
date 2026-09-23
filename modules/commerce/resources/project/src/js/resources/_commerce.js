const INTEREST_SCOPE = '[data-commerce-interest-scope]';
const INQUIRY_ROOT = '[data-commerce-inquiry-root]';
const FILTER_FORM = '[data-commerce-filter-form]';
const FILTER_RESET = '[data-commerce-filter-reset]';
const FILTER_SUBMIT = '[data-commerce-filter-submit]';
const PAGINATION_LINK = '[data-commerce-pagination-link]';
const FILTER_DEBOUNCE_MS = 350;
const RESULTS_UPDATED_EVENT = 'liquidstack:commerce-results-updated';
const ITEM_TOKEN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

let disposeActiveCommerce = () => {};

const queryAll = (scope, selector) => {
  if (!scope || typeof scope.querySelectorAll !== 'function') {
    return [];
  }

  const matches = Array.from(scope.querySelectorAll(selector));
  if (typeof scope.matches === 'function' && scope.matches(selector)) {
    matches.unshift(scope);
  }

  return [...new Set(matches)];
};

const parseItems = (root, view) => {
  const selected = new Set(
    (root?.dataset?.commerceSelectedItems ?? '')
      .split(',')
      .map((value) => value.trim())
      .filter((value) => ITEM_TOKEN.test(value))
      .slice(0, 20),
  );
  try {
    const url = new URL(view.location.href);
    for (const value of (url.searchParams.get('items') ?? '').split(',')) {
      const token = value.trim();
      if (ITEM_TOKEN.test(token) && selected.size < 20) {
        selected.add(token);
      }
    }
  } catch {
    // The server-rendered state remains authoritative.
  }

  return selected;
};

const interestHref = (path, selected, view) => {
  try {
    const url = new URL(path, view.location.origin);
    if (selected.size > 0) {
      url.searchParams.set('items', [...selected].join(','));
    } else {
      url.searchParams.delete('items');
    }
    return `${url.pathname}${url.search}${url.hash}`;
  } catch {
    return path;
  }
};

const syncFilterControls = (form, incomingForm) => {
  if (!form?.elements || !incomingForm?.elements) {
    return;
  }
  const incoming = Array.from(incomingForm.elements);
  for (const control of Array.from(form.elements)) {
    if (!control?.name) {
      continue;
    }
    const source = incoming.find((candidate) => (
      candidate?.name === control.name
      && (
        control.type !== 'checkbox'
        && control.type !== 'radio'
        || candidate.value === control.value
      )
    ));
    if (!source) {
      continue;
    }
    if (control.type === 'checkbox' || control.type === 'radio') {
      control.checked = Boolean(source.checked);
    } else {
      control.value = source.value ?? '';
    }
  }
  const reset = form.querySelector?.(FILTER_RESET);
  const incomingReset = incomingForm.querySelector?.(FILTER_RESET);
  if (reset && incomingReset) {
    reset.href = incomingReset.href;
  }
};

const installCatalogFilters = (scope) => {
  const candidates = queryAll(scope, FILTER_FORM);
  const firstForm = candidates[0];
  if (!firstForm) {
    return () => {};
  }

  const documentRef = firstForm.ownerDocument;
  const view = documentRef?.defaultView ?? globalThis;
  const FormDataConstructor = view.FormData ?? globalThis.FormData;
  const CustomEventConstructor = view.CustomEvent ?? globalThis.CustomEvent;
  const canEnhance = Boolean(
    documentRef
    && typeof view.fetch === 'function'
    && typeof FormDataConstructor === 'function'
    && typeof CustomEventConstructor === 'function'
    && typeof (view.DOMParser ?? globalThis.DOMParser) === 'function'
    && typeof (view.AbortController ?? globalThis.AbortController) === 'function'
    && typeof view.history?.pushState === 'function'
    && typeof view.history?.replaceState === 'function',
  );
  const forms = canEnhance
    ? candidates.filter((form) => {
      const method = String(
        form.getAttribute?.('method') ?? form.method ?? 'get',
      ).toLowerCase();
      const target = form.dataset?.commerceResultsTarget ?? '';

      return method === 'get' && Boolean(documentRef.querySelector?.(target));
    })
    : [];
  if (forms.length === 0) {
    return () => {};
  }

  const submitButtons = forms
    .map((form) => form.querySelector?.(FILTER_SUBMIT))
    .filter(Boolean);
  for (const button of submitButtons) {
    button.hidden = true;
  }

  const listenerController = new (view.AbortController
    ?? globalThis.AbortController)();
  let requestController = null;
  let requestGeneration = 0;
  let debounceTimer = null;
  let debouncedHistoryEntry = false;
  let disposed = false;

  const clearDebounce = () => {
    if (debounceTimer !== null) {
      (view.clearTimeout ?? globalThis.clearTimeout)(debounceTimer);
      debounceTimer = null;
    }
  };
  const setBusy = (busy) => {
    for (const form of forms) {
      if (busy) {
        form.setAttribute('aria-busy', 'true');
      } else {
        form.removeAttribute('aria-busy');
      }
      const selector = form.dataset?.commerceResultsTarget ?? '';
      const target = documentRef.querySelector?.(selector);
      if (busy) {
        target?.setAttribute?.('aria-busy', 'true');
      } else {
        target?.removeAttribute?.('aria-busy');
      }
    }
  };
  const buildUrl = (form) => {
    const url = new URL(form.action || view.location.href, view.location.href);
    const params = new URLSearchParams();
    for (const [name, value] of new FormDataConstructor(form)) {
      if (typeof value === 'string' && value !== '') {
        params.append(name, value);
      }
    }
    url.search = params.toString();
    url.hash = '';

    return url;
  };
  const request = async (url, historyMode = 'push') => {
    clearDebounce();
    requestController?.abort();
    const ownGeneration = ++requestGeneration;
    requestController = new (view.AbortController
      ?? globalThis.AbortController)();
    const ownController = requestController;
    setBusy(true);

    try {
      const response = await view.fetch(url.href, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          Accept: 'text/html',
          'X-LiquidStack-Partial': 'commerce-results',
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
        disposed
        || ownGeneration !== requestGeneration
        || ownController !== requestController
      ) {
        return;
      }

      let updatedTargets = 0;
      for (const form of forms) {
        const selector = form.dataset?.commerceResultsTarget ?? '';
        const target = documentRef.querySelector?.(selector);
        const incomingTarget = responseDocument.querySelector?.(selector);
        if (target && incomingTarget) {
          target.innerHTML = incomingTarget.innerHTML;
          target.dispatchEvent(new CustomEventConstructor(RESULTS_UPDATED_EVENT, {
            bubbles: true,
            detail: { url: url.href },
          }));
          updatedTargets += 1;
        }
        const incomingForm = form.id === ''
          ? null
          : responseDocument.getElementById?.(form.id);
        syncFilterControls(form, incomingForm);
        form.removeAttribute('data-commerce-filter-error');
      }
      if (updatedTargets === 0) {
        throw new Error('Invalid Commerce results fragment.');
      }

      if (url.href !== view.location.href) {
        if (historyMode === 'debounced') {
          const method = debouncedHistoryEntry ? 'replaceState' : 'pushState';
          view.history[method]({ liquidstackCommerceFilters: true }, '', url.href);
          debouncedHistoryEntry = true;
        } else if (historyMode === 'push') {
          view.history.pushState(
            { liquidstackCommerceFilters: true },
            '',
            url.href,
          );
        }
      }
    } catch (error) {
      if (
        error?.name !== 'AbortError'
        && ownGeneration === requestGeneration
        && !disposed
      ) {
        for (const form of forms) {
          form.setAttribute('data-commerce-filter-error', 'true');
        }
      }
    } finally {
      if (
        ownGeneration === requestGeneration
        && ownController === requestController
      ) {
        requestController = null;
        setBusy(false);
      }
    }
  };
  const requestFromForm = (form, historyMode) => {
    if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
      form.reportValidity?.();
      return;
    }
    let url;
    try {
      url = buildUrl(form);
    } catch {
      return;
    }
    if (url.origin !== view.location.origin) {
      view.location.assign?.(url.href);
      return;
    }
    void request(url, historyMode);
  };
  const schedule = (form, resetHistory = false) => {
    clearDebounce();
    requestController?.abort();
    requestController = null;
    requestGeneration += 1;
    setBusy(false);
    if (resetHistory) {
      debouncedHistoryEntry = false;
    }
    debounceTimer = (view.setTimeout ?? globalThis.setTimeout)(() => {
      debounceTimer = null;
      requestFromForm(form, 'debounced');
    }, FILTER_DEBOUNCE_MS);
  };

  for (const form of forms) {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      clearDebounce();
      debouncedHistoryEntry = false;
      requestFromForm(form, 'push');
    }, { signal: listenerController.signal });
    form.addEventListener('input', (event) => {
      if (!event.target?.name) {
        return;
      }
      schedule(form);
    }, { signal: listenerController.signal });
    form.addEventListener('change', (event) => {
      if (!event.target?.name || event.target?.name === 'q') {
        return;
      }
      schedule(form, true);
    }, { signal: listenerController.signal });
    form.addEventListener('click', (event) => {
      const reset = event.target?.closest?.(FILTER_RESET);
      if (!reset || !form.contains(reset)) {
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
      clearDebounce();
      debouncedHistoryEntry = false;
      void request(url, 'push');
    }, { signal: listenerController.signal });
  }

  documentRef.addEventListener('click', (event) => {
    if (
      event.defaultPrevented
      || event.button !== 0
      || event.metaKey
      || event.ctrlKey
      || event.shiftKey
      || event.altKey
    ) {
      return;
    }
    const link = event.target?.closest?.(PAGINATION_LINK);
    if (!link || link.hasAttribute('download')) {
      return;
    }
    const targetName = String(link.getAttribute('target') ?? '').toLowerCase();
    if (targetName !== '' && targetName !== '_self') {
      return;
    }
    const belongsToManagedResults = forms.some((form) => {
      const selector = form.dataset?.commerceResultsTarget ?? '';
      const target = selector === '' ? null : documentRef.querySelector?.(selector);

      return Boolean(target?.contains?.(link));
    });
    if (!belongsToManagedResults) {
      return;
    }
    let url;
    try {
      url = new URL(link.href, view.location.href);
    } catch {
      return;
    }
    if (url.origin !== view.location.origin) {
      return;
    }
    event.preventDefault();
    clearDebounce();
    debouncedHistoryEntry = false;
    void request(url, 'push');
  }, { signal: listenerController.signal });

  view.addEventListener('popstate', () => {
    clearDebounce();
    debouncedHistoryEntry = false;
    void request(new URL(view.location.href), 'none');
  }, { signal: listenerController.signal });

  return () => {
    disposed = true;
    listenerController.abort();
    clearDebounce();
    requestController?.abort();
    requestController = null;
    setBusy(false);
    for (const button of submitButtons) {
      button.hidden = false;
    }
  };
};

const installInterestScope = (root) => {
  const documentRef = root.ownerDocument;
  const view = documentRef.defaultView ?? globalThis;
  const selected = parseItems(root, view);
  const inquiryPath = root.dataset.commerceInquiryUrl ?? '/';

  const sync = () => {
    root.dataset.commerceSelectedItems = [...selected].join(',');
    for (const button of queryAll(root, '[data-commerce-interest-add]')) {
      const id = button.dataset.itemId ?? '';
      const active = selected.has(id);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
      button.textContent = active
        ? (button.dataset.addedLabel ?? button.textContent)
        : (button.dataset.defaultLabel ?? button.textContent);
    }
    for (const counter of queryAll(root, '[data-commerce-interest-count]')) {
      counter.textContent = String(selected.size);
    }
    for (const link of queryAll(root, '[data-commerce-interest-link]')) {
      link.href = interestHref(inquiryPath, selected, view);
    }
  };

  const onClick = (event) => {
    const button = event.target.closest?.('[data-commerce-interest-add]');
    if (!button || !root.contains(button)) {
      return;
    }
    const form = button.closest('form');
    if (!form?.hasAttribute('data-commerce-development-fixture')) {
      return;
    }
    event.preventDefault();
    const id = button.dataset.itemId ?? '';
    if (!ITEM_TOKEN.test(id)) {
      return;
    }
    const removing = selected.has(id);
    if (removing) {
      selected.delete(id);
    } else if (selected.size < 20) {
      selected.add(id);
    }
    const status = root.querySelector('[data-commerce-interest-status]');
    if (status) {
      status.textContent = removing
        ? (root.dataset.commerceRemovedStatus ?? '')
        : (root.dataset.commerceAddedStatus ?? '');
    }
    sync();
  };
  const onResultsUpdated = () => sync();

  root.addEventListener('click', onClick);
  root.addEventListener(RESULTS_UPDATED_EVENT, onResultsUpdated);
  sync();

  return () => {
    root.removeEventListener('click', onClick);
    root.removeEventListener(RESULTS_UPDATED_EVENT, onResultsUpdated);
  };
};

const installInquiry = (root) => {
  const documentRef = root.ownerDocument;
  const form = root.querySelector('[data-commerce-inquiry-form]');
  const list = root.querySelector('[data-commerce-interest-list]');
  const empty = root.querySelector('[data-commerce-interest-empty]');
  const status = root.querySelector('[data-commerce-form-status]');
  const submit = form?.querySelector('[type="submit"]');
  let submitted = false;

  const rows = () => queryAll(list, '[data-commerce-interest-row]');
  const sync = () => {
    if (empty) {
      empty.hidden = rows().length > 0;
    }
  };
  const announce = (message, state = '') => {
    if (!status) {
      return;
    }
    status.textContent = message;
    status.dataset.state = state;
  };
  const onClick = (event) => {
    const button = event.target.closest?.('[data-commerce-interest-remove]');
    if (!button || !root.contains(button)) {
      return;
    }
    if (!button.closest('form')?.hasAttribute('data-commerce-development-fixture')) {
      return;
    }
    event.preventDefault();
    button.closest('[data-commerce-interest-row]')?.remove();
    announce('');
    sync();
  };
  const onSubmit = (event) => {
    if (!form || submitted) {
      return;
    }
    if (rows().length === 0) {
      event.preventDefault();
      announce(root.dataset.emptyError ?? '', 'error');
      status?.focus?.();
      return;
    }
    if (!form.checkValidity()) {
      event.preventDefault();
      announce(form.dataset.invalidMessage ?? '', 'error');
      form.reportValidity();
      return;
    }

    if (!form.hasAttribute('data-commerce-development-fixture')) {
      return;
    }
    event.preventDefault();

    submitted = true;
    if (submit) {
      submit.disabled = true;
    }
    announce(form.dataset.successMessage ?? '', 'success');
    status?.focus?.();
  };
  const onReset = () => {
    submitted = false;
    if (submit) {
      submit.disabled = false;
    }
    announce('');
  };

  root.addEventListener('click', onClick);
  form?.addEventListener('submit', onSubmit);
  form?.addEventListener('reset', onReset);
  sync();
  if ((documentRef.defaultView?.location?.hash ?? '') === '#solicitud-enviada') {
    announce(form?.dataset.successMessage ?? '', 'success');
    status?.focus?.();
  }

  return () => {
    root.removeEventListener('click', onClick);
    form?.removeEventListener('submit', onSubmit);
    form?.removeEventListener('reset', onReset);
  };
};

export const cleanupCommerce = () => {
  disposeActiveCommerce();
  disposeActiveCommerce = () => {};
};

export const initCommerce = (scope = globalThis.document) => {
  cleanupCommerce();
  const cleanups = [
    installCatalogFilters(scope),
    ...queryAll(scope, INTEREST_SCOPE).map(installInterestScope),
    ...queryAll(scope, INQUIRY_ROOT).map(installInquiry),
  ];
  let cleaned = false;
  disposeActiveCommerce = () => {
    if (cleaned) {
      return;
    }
    cleaned = true;
    for (const cleanup of cleanups.reverse()) {
      cleanup();
    }
  };

  return disposeActiveCommerce;
};

export default initCommerce;

if (import.meta.hot) {
  import.meta.hot.dispose(cleanupCommerce);
}
