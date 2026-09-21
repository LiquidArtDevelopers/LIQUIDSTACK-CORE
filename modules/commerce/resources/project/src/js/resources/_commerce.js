const INTEREST_SCOPE = '[data-commerce-interest-scope]';
const INQUIRY_ROOT = '[data-commerce-inquiry-root]';
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

const installInterestScope = (root) => {
  const documentRef = root.ownerDocument;
  const view = documentRef.defaultView ?? globalThis;
  const selected = parseItems(root, view);
  const buttons = queryAll(root, '[data-commerce-interest-add]');
  const counters = queryAll(root, '[data-commerce-interest-count]');
  const links = queryAll(root, '[data-commerce-interest-link]');
  const status = root.querySelector('[data-commerce-interest-status]');
  const inquiryPath = root.dataset.commerceInquiryUrl ?? '/';

  const sync = () => {
    for (const button of buttons) {
      const id = button.dataset.itemId ?? '';
      const active = selected.has(id);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
      button.textContent = active
        ? (button.dataset.addedLabel ?? button.textContent)
        : (button.dataset.defaultLabel ?? button.textContent);
    }
    for (const counter of counters) {
      counter.textContent = String(selected.size);
    }
    for (const link of links) {
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
    if (status) {
      status.textContent = removing
        ? (root.dataset.commerceRemovedStatus ?? '')
        : (root.dataset.commerceAddedStatus ?? '');
    }
    sync();
  };

  root.addEventListener('click', onClick);
  sync();

  return () => root.removeEventListener('click', onClick);
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
