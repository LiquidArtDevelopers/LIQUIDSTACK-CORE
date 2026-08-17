import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const projectRoot = resolve(import.meta.dirname, '..', '..', '..');
const source = await readFile(
  resolve(
  projectRoot,
  'modules/blog/resources/project/src/js/resources/_moduleBlogPagination01.js',
),
  'utf8',
);
const runtime = await import(
  `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`
);

const RESULTS_SELECTOR = '#blog-results[data-blog-results]';
const RESULTS_FALLBACK_SELECTOR = '#blog-results';
const PAGINATION_SELECTOR = '.moduleBlogPagination01';
const PAGINATION_LINK_SELECTOR = `${PAGINATION_SELECTOR} a[href]`;
const FILTER_FORM_SELECTOR = '[data-blog-filter-form]';
const FILTER_STATUS_SELECTOR = '[data-blog-filter-status]';

const flush = async (turns = 8) => {
  for (let index = 0; index < turns; index += 1) {
    await Promise.resolve();
  }
};

class FakeEventTarget {
  constructor() {
    this.listeners = new Map();
  }

  addEventListener(type, listener, options = {}) {
    const entries = this.listeners.get(type) ?? new Set();
    const entry = { listener, capture: Boolean(options.capture) };
    entries.add(entry);
    this.listeners.set(type, entries);
    options.signal?.addEventListener('abort', () => entries.delete(entry), {
      once: true,
    });
  }

  emit(type, properties = {}) {
    const event = {
      type,
      target: properties.target ?? this,
      button: properties.button ?? 0,
      altKey: Boolean(properties.altKey),
      ctrlKey: Boolean(properties.ctrlKey),
      metaKey: Boolean(properties.metaKey),
      shiftKey: Boolean(properties.shiftKey),
      defaultPrevented: false,
      preventDefault() {
        this.defaultPrevented = true;
      },
      ...properties,
    };
    const entries = [...(this.listeners.get(type) ?? [])]
      .sort((left, right) => Number(right.capture) - Number(left.capture));
    for (const { listener } of entries) {
      listener(event);
    }

    return event;
  }

  dispatchEvent(event) {
    this.emittedEvents ??= [];
    this.emittedEvents.push(event);
    this.emit(event.type, event);
    return true;
  }
}

class FakeElement extends FakeEventTarget {
  constructor(ownerDocument = null, tagName = 'div') {
    super();
    this.ownerDocument = ownerDocument;
    this.tagName = tagName.toUpperCase();
    this.attributes = new Map();
    this.dataset = {};
    this.children = [];
    this.parentElement = null;
    this.parentNode = null;
    this.textContent = '';
    this.hidden = false;
    this.style = { cssText: '' };
    this.isConnected = true;
  }

  setAttribute(name, value) {
    this.attributes.set(name, String(value));
  }

  getAttribute(name) {
    return this.attributes.get(name) ?? null;
  }

  hasAttribute(name) {
    return this.attributes.has(name);
  }

  removeAttribute(name) {
    this.attributes.delete(name);
  }

  appendChild(child) {
    child.parentElement = this;
    child.parentNode = this;
    this.children.push(child);
    return child;
  }

  insertAdjacentElement(position, child) {
    assert.equal(position, 'afterend');
    const parent = this.parentElement;
    if (!parent) {
      return null;
    }
    const index = parent.children.indexOf(this);
    child.parentElement = parent;
    child.parentNode = parent;
    parent.children.splice(index + 1, 0, child);
    return child;
  }

  remove() {
    const parent = this.parentElement;
    if (parent) {
      parent.children = parent.children.filter((child) => child !== this);
    }
    this.parentElement = null;
    this.parentNode = null;
    this.isConnected = false;
  }

  contains(node) {
    let current = node;
    while (current) {
      if (current === this) {
        return true;
      }
      current = current.parentElement;
    }

    return false;
  }

  closest(selector) {
    let current = this;
    while (current) {
      if (current.matches?.(selector)) {
        return current;
      }
      current = current.parentElement;
    }

    return null;
  }

  matches(selector) {
    return selector === '[data-blog-pagination-runtime-status]'
      && this.hasAttribute('data-blog-pagination-runtime-status');
  }

  querySelector(selector) {
    return this.querySelectorAll(selector)[0] ?? null;
  }

  querySelectorAll(selector) {
    const matches = [];
    const visit = (element) => {
      for (const child of element.children) {
        if (child.matches?.(selector)) {
          matches.push(child);
        }
        visit(child);
      }
    };
    visit(this);

    return matches;
  }

  focus() {
    this.focused = true;
    if (this.ownerDocument) {
      this.ownerDocument.activeElement = this;
    }
  }
}

class FakeAnchor extends FakeElement {
  constructor(ownerDocument, href) {
    super(ownerDocument, 'a');
    this.setAttribute('href', href);
  }

  matches(selector) {
    return selector === PAGINATION_LINK_SELECTOR;
  }
}

class FakePagination extends FakeElement {
  constructor(ownerDocument, hrefs) {
    super(ownerDocument, 'div');
    this.className = 'moduleBlogPagination01';
    this.links = hrefs.map((href) => this.appendChild(
      new FakeAnchor(ownerDocument, href),
    ));
  }

  matches(selector) {
    return selector === PAGINATION_SELECTOR;
  }
}

class FakeHeading extends FakeElement {
  constructor(ownerDocument, text) {
    super(ownerDocument, 'h2');
    this.textContent = text;
  }

  matches(selector) {
    return selector === [
      '[data-blog-card-key] h1',
      '[data-blog-card-key] h2',
      '[data-blog-card-key] h3',
      '[data-blog-card-key] h4',
      '[data-blog-card-key] h5',
      '[data-blog-card-key] h6',
    ].join(', ');
  }
}

class FakeResults extends FakeElement {
  constructor(environment, marker, hrefs = []) {
    super(environment.documentRef, 'div');
    this.environment = environment;
    this.marker = marker;
    this.id = 'blog-results';
    this.setAttribute('data-blog-results', '');
    this.heading = this.appendChild(new FakeHeading(
      environment.documentRef,
      `Results ${marker}`,
    ));
    this.heading.setAttribute('data-blog-card-key', marker);
    this.pagination = this.appendChild(new FakePagination(
      environment.documentRef,
      hrefs,
    ));
  }

  matches(selector) {
    return selector === RESULTS_SELECTOR || selector === RESULTS_FALLBACK_SELECTOR;
  }

  replaceWith(replacement) {
    assert.equal(this.environment.currentTarget, this);
    this.isConnected = false;
    replacement.environment = this.environment;
    replacement.isConnected = true;
    this.environment.currentTarget = replacement;
  }

  scrollIntoView(options) {
    this.scrolled = options;
  }
}

class FakeStatus extends FakeElement {
  constructor(ownerDocument) {
    super(ownerDocument, 'p');
    this.dataset.state = 'idle';
    this.dataset.message = 'Resultados actualizados';
    this.dataset.errorMessage = 'Resultados no disponibles';
  }

  matches(selector) {
    return selector === FILTER_STATUS_SELECTOR;
  }
}

class FakeForm extends FakeElement {
  constructor(ownerDocument, status) {
    super(ownerDocument, 'form');
    this.dataset.blogResultsTarget = RESULTS_FALLBACK_SELECTOR;
    this.method = 'get';
    this.status = this.appendChild(status);
  }

  matches(selector) {
    return selector === FILTER_FORM_SELECTOR;
  }
}

class FakeControl extends FakeElement {
  constructor(ownerDocument, form) {
    super(ownerDocument, 'input');
    form.appendChild(this);
  }
}

const metadataElement = (documentRef, attribute, value) => {
  const element = new FakeElement(documentRef);
  element.setAttribute(attribute, value);
  return element;
};

const responseDocument = (
  environment,
  marker,
  {
    count = 1,
    hrefs = [],
    canonical = `http://localhost:1309/es/noticias/${marker}`,
  } = {},
) => {
  const targets = Array.from(
    { length: count },
    () => new FakeResults(environment, marker, hrefs),
  );
  const robots = metadataElement(environment.documentRef, 'content', 'index, follow');
  const canonicalElement = metadataElement(
    environment.documentRef,
    'href',
    canonical,
  );

  return {
    title: `Title ${marker}`,
    querySelector(selector) {
      return ({
        'meta[name="robots"]': robots,
        'link[rel="canonical"]': canonicalElement,
      })[selector] ?? null;
    },
    querySelectorAll(selector) {
      return selector === RESULTS_SELECTOR ? targets : [];
    },
  };
};

const createLocation = (initialHref) => {
  let current = new URL(initialHref);
  const assigned = [];

  return {
    get href() {
      return current.href;
    },
    set href(value) {
      current = new URL(value, current);
    },
    get origin() {
      return current.origin;
    },
    assign(value) {
      assigned.push(value);
      current = new URL(value, current);
    },
    assigned,
  };
};

const createEnvironment = ({
  withFilters = true,
  hrefs = [
    '/es/noticias/pagina/2#blog-results',
    '/es/noticias/pagina/3#blog-results',
  ],
} = {}) => {
  const environment = {};
  const documentRef = new FakeEventTarget();
  const view = new FakeEventTarget();
  const location = createLocation('http://localhost:1309/es/noticias');
  const historyOperations = [];
  const requests = [];
  const timers = [];
  const currentRobots = metadataElement(documentRef, 'content', 'index, follow');
  const currentCanonical = metadataElement(
    documentRef,
    'href',
    'http://localhost:1309/es/noticias',
  );
  const status = new FakeStatus(documentRef);
  const form = withFilters ? new FakeForm(documentRef, status) : null;

  Object.assign(environment, {
    documentRef,
    view,
    location,
    historyOperations,
    requests,
    timers,
    status,
    form,
    currentRobots,
    currentCanonical,
    fetchImpl: async () => ({
      ok: true,
      status: 200,
      url: 'http://localhost:1309/es/noticias/pagina/2',
      text: async () => 'page2',
    }),
  });

  environment.currentTarget = new FakeResults(environment, 'initial', hrefs);
  documentRef.nodeType = 9;
  documentRef.defaultView = view;
  documentRef.documentElement = { lang: 'es' };
  documentRef.title = 'Initial title';
  documentRef.activeElement = null;
  documentRef.querySelector = (selector) => ({
    [RESULTS_SELECTOR]: environment.currentTarget,
    [RESULTS_FALLBACK_SELECTOR]: environment.currentTarget,
    'meta[name="robots"]': currentRobots,
    'link[rel="canonical"]': currentCanonical,
  })[selector] ?? null;
  documentRef.querySelectorAll = (selector) => {
    if (selector === FILTER_FORM_SELECTOR) {
      return form ? [form] : [];
    }

    return [];
  };
  documentRef.createElement = (tagName) => new FakeElement(documentRef, tagName);
  documentRef.importNode = (node) => node;

  view.location = location;
  view.URL = URL;
  view.AbortController = AbortController;
  view.CustomEvent = class FakeCustomEvent {
    constructor(type, options) {
      this.type = type;
      this.detail = options.detail;
      this.bubbles = options.bubbles;
    }
  };
  view.queueMicrotask = queueMicrotask;
  view.setTimeout = (callback, delay) => {
    const timer = { callback, delay, cancelled: false };
    timers.push(timer);
    return timer;
  };
  view.clearTimeout = (timer) => {
    if (timer) {
      timer.cancelled = true;
    }
  };
  view.history = {
    pushState(state, title, href) {
      historyOperations.push({ state, title, href });
      location.href = href;
    },
  };
  view.DOMParser = class FakeDOMParser {
    parseFromString(marker) {
      if (marker === 'missing') {
        return responseDocument(environment, marker, { count: 0 });
      }
      if (marker === 'duplicate') {
        return responseDocument(environment, marker, { count: 2 });
      }

      return responseDocument(environment, marker, {
        hrefs: marker === 'page2'
          ? ['/es/noticias', '/es/noticias/pagina/3#blog-results']
          : ['/es/noticias/pagina/2#blog-results'],
      });
    }
  };
  view.fetch = (url, options) => {
    requests.push({ url, options });
    return environment.fetchImpl(url, options);
  };

  globalThis.document = documentRef;
  globalThis.window = view;

  return environment;
};

const click = (environment, anchor, properties = {}) => (
  environment.documentRef.emit('click', {
    target: anchor,
    button: 0,
    ...properties,
  })
);

const successResponse = (marker, url) => ({
  ok: true,
  status: 200,
  url,
  text: async () => marker,
});

const testSuccessfulNavigationAndNativeBoundaries = async () => {
  const environment = createEnvironment();
  const cleanup = runtime.initModuleBlogPagination01(environment.documentRef);
  const [page2] = environment.currentTarget.pagination.links;

  const modified = click(environment, page2, { ctrlKey: true });
  assert.equal(modified.defaultPrevented, false, 'Ctrl+click remains native');
  assert.equal(environment.requests.length, 0);

  const external = new FakeAnchor(
    environment.documentRef,
    'https://example.net/page/2',
  );
  environment.currentTarget.pagination.appendChild(external);
  assert.equal(click(environment, external).defaultPrevented, false);

  const newTab = new FakeAnchor(
    environment.documentRef,
    '/es/noticias/pagina/2',
  );
  newTab.setAttribute('target', '_blank');
  environment.currentTarget.pagination.appendChild(newTab);
  assert.equal(click(environment, newTab).defaultPrevented, false);

  const download = new FakeAnchor(
    environment.documentRef,
    '/es/noticias/pagina/2',
  );
  download.setAttribute('download', 'noticias.html');
  environment.currentTarget.pagination.appendChild(download);
  assert.equal(click(environment, download).defaultPrevented, false);

  const navigation = click(environment, page2);
  assert.equal(navigation.defaultPrevented, true, 'plain pagination is enhanced');
  await flush();

  assert.equal(environment.requests.length, 1);
  assert.equal(
    environment.requests[0].options.headers['X-LiquidStack-Partial'],
    'blog-results',
  );
  assert.equal(environment.requests[0].options.credentials, 'same-origin');
  assert.equal(environment.timers[0].delay, 12_000);
  assert.equal(environment.currentTarget.marker, 'page2');
  assert.equal(
    environment.location.href,
    'http://localhost:1309/es/noticias/pagina/2',
    'enhanced history omits the SSR-only hash',
  );
  assert.equal(environment.historyOperations.length, 1);
  assert.deepEqual(
    environment.historyOperations[0].state,
    { liquidstackBlogPagination: true },
  );
  assert.equal(environment.documentRef.title, 'Title page2');
  assert.equal(
    environment.currentCanonical.getAttribute('href'),
    'http://localhost:1309/es/noticias/page2',
  );
  assert.equal(environment.status.dataset.state, 'success');
  assert.equal(environment.status.textContent, 'Resultados actualizados');
  assert.equal(environment.currentTarget.heading.focused, true);
  assert.deepEqual(
    environment.currentTarget.scrolled,
    { block: 'start', behavior: 'auto' },
  );
  assert.equal(
    environment.documentRef.emittedEvents.some(
      (event) => event.type === 'liquidstack:blog-results-updated'
        && event.detail.target === environment.currentTarget,
    ),
    true,
  );

  cleanup();
  const afterCleanup = click(
    environment,
    environment.currentTarget.pagination.links[0],
  );
  assert.equal(afterCleanup.defaultPrevented, false, 'cleanup restores native links');
};

const testLatestClickWinsEvenWhenFetchIgnoresAbort = async () => {
  const environment = createEnvironment();
  let resolveFirst;
  let fetchNumber = 0;
  environment.fetchImpl = () => {
    fetchNumber += 1;
    if (fetchNumber === 1) {
      return new Promise((resolveResponse) => {
        resolveFirst = () => resolveResponse(successResponse(
          'page2',
          'http://localhost:1309/es/noticias/pagina/2',
        ));
      });
    }

    return Promise.resolve(successResponse(
      'page3',
      'http://localhost:1309/es/noticias/pagina/3',
    ));
  };
  const cleanup = runtime.initModuleBlogPagination01(environment.documentRef);
  const [page2, page3] = environment.currentTarget.pagination.links;

  assert.equal(click(environment, page2).defaultPrevented, true);
  assert.equal(click(environment, page3).defaultPrevented, true);
  await flush();
  assert.equal(environment.currentTarget.marker, 'page3');
  assert.equal(environment.historyOperations.length, 1);

  resolveFirst();
  await flush();
  assert.equal(
    environment.currentTarget.marker,
    'page3',
    'a stale response cannot replace the latest page',
  );
  assert.equal(environment.historyOperations.length, 1);
  assert.equal(environment.requests[0].options.signal.aborted, true);
  cleanup();
};

const testFilterCoordination = async () => {
  const environment = createEnvironment();
  let resolvePending;
  environment.fetchImpl = () => new Promise((resolveResponse) => {
    resolvePending = () => resolveResponse(successResponse(
      'page2',
      'http://localhost:1309/es/noticias/pagina/2',
    ));
  });
  const cleanup = runtime.initModuleBlogPagination01(environment.documentRef);
  const [page2] = environment.currentTarget.pagination.links;
  click(environment, page2);
  const pendingSignal = environment.requests[0].options.signal;
  const control = new FakeControl(environment.documentRef, environment.form);
  environment.documentRef.emit('input', { target: control });
  assert.equal(pendingSignal.aborted, true, 'filter input cancels pagination');
  assert.equal(environment.currentTarget.getAttribute('aria-busy'), null);

  resolvePending();
  await flush();
  assert.equal(environment.currentTarget.marker, 'initial');
  assert.equal(environment.historyOperations.length, 0);

  click(environment, page2);
  const pendingBeforeHistory = environment.requests.at(-1).options.signal;
  environment.form.setAttribute('aria-busy', 'true');
  environment.location.href = 'http://localhost:1309/es/noticias/pagina/2';
  environment.view.emit('popstate');
  assert.equal(
    pendingBeforeHistory.aborted,
    true,
    'back/forward invalidates an older pagination response immediately',
  );
  await flush();
  assert.equal(
    environment.requests.length,
    2,
    'the filter runtime owns popstate once it marks the shared form busy',
  );

  const nativeDuringFilter = click(environment, page2);
  assert.equal(
    nativeDuringFilter.defaultPrevented,
    false,
    'a busy filter keeps the real href as the race-free fallback',
  );
  assert.equal(environment.requests.length, 2);
  cleanup();
};

const testPopStateFallbackAndBfcacheCleanup = async () => {
  const environment = createEnvironment({ withFilters: false });
  environment.fetchImpl = () => Promise.resolve(successResponse(
    'page2',
    'http://localhost:1309/es/noticias/pagina/2',
  ));
  const cleanup = runtime.initModuleBlogPagination01(environment.documentRef);
  environment.location.href = 'http://localhost:1309/es/noticias/pagina/2';
  environment.view.emit('popstate');
  await flush();
  assert.equal(environment.currentTarget.marker, 'page2');
  assert.equal(environment.historyOperations.length, 0, 'popstate never pushes');
  assert.notEqual(
    environment.currentTarget.heading.focused,
    true,
    'history restoration does not steal focus',
  );

  let resolvePending;
  environment.fetchImpl = () => new Promise((resolveResponse) => {
    resolvePending = () => resolveResponse(successResponse(
      'initial',
      'http://localhost:1309/es/noticias',
    ));
  });
  click(environment, environment.currentTarget.pagination.links[0]);
  const signal = environment.requests.at(-1).options.signal;
  environment.view.emit('pagehide', { persisted: true });
  assert.equal(signal.aborted, true, 'pagehide aborts work before BFCache');
  environment.view.emit('pageshow', { persisted: true });
  assert.equal(environment.currentTarget.getAttribute('aria-busy'), null);
  resolvePending();
  await flush();
  assert.equal(environment.currentTarget.marker, 'page2');
  cleanup();
};

const testRecoverableFailuresAndTimeout = async () => {
  const failureCases = [
    {
      name: 'offline',
      response: () => Promise.reject(new Error('offline')),
    },
    {
      name: 'missing target',
      response: () => Promise.resolve(successResponse(
        'missing',
        'http://localhost:1309/es/noticias/pagina/2',
      )),
    },
    {
      name: 'duplicate target',
      response: () => Promise.resolve(successResponse(
        'duplicate',
        'http://localhost:1309/es/noticias/pagina/2',
      )),
    },
    {
      name: 'cross-origin final URL',
      response: () => Promise.resolve(successResponse(
        'page2',
        'https://example.net/es/noticias/pagina/2',
      )),
    },
    {
      name: 'HTTP error',
      response: () => Promise.resolve({
        ok: false,
        status: 503,
        url: 'http://localhost:1309/es/noticias/pagina/2',
        text: async () => 'page2',
      }),
    },
  ];

  for (const failureCase of failureCases) {
    const environment = createEnvironment();
    environment.fetchImpl = failureCase.response;
    const cleanup = runtime.initModuleBlogPagination01(environment.documentRef);
    const original = environment.currentTarget;
    assert.equal(
      click(environment, original.pagination.links[0]).defaultPrevented,
      true,
      failureCase.name,
    );
    await flush();
    assert.equal(environment.currentTarget, original, failureCase.name);
    assert.equal(environment.historyOperations.length, 0, failureCase.name);
    assert.equal(original.getAttribute('aria-busy'), null, failureCase.name);
    assert.equal(environment.status.dataset.state, 'error', failureCase.name);
    assert.equal(
      environment.status.textContent,
      'Resultados no disponibles',
      failureCase.name,
    );
    cleanup();
  }

  const timeoutEnvironment = createEnvironment();
  timeoutEnvironment.fetchImpl = () => new Promise(() => {});
  const cleanupTimeout = runtime.initModuleBlogPagination01(
    timeoutEnvironment.documentRef,
  );
  const original = timeoutEnvironment.currentTarget;
  click(timeoutEnvironment, original.pagination.links[0]);
  const timeout = timeoutEnvironment.timers.find(
    (timer) => timer.delay === 12_000 && !timer.cancelled,
  );
  assert.ok(timeout, 'the request owns an exact 12 second timeout');
  const signal = timeoutEnvironment.requests[0].options.signal;
  timeout.callback();
  assert.equal(signal.aborted, true);
  assert.equal(timeoutEnvironment.currentTarget, original);
  assert.equal(original.getAttribute('aria-busy'), null);
  assert.equal(timeoutEnvironment.status.dataset.state, 'error');
  cleanupTimeout();
};

const testRuntimeStatusAndDisposal = async () => {
  const environment = createEnvironment({ withFilters: false });
  environment.fetchImpl = () => Promise.reject(new Error('offline'));
  const cleanup = runtime.initModuleBlogPagination01(environment.documentRef);
  click(environment, environment.currentTarget.pagination.links[0]);
  await flush();
  const runtimeStatus = environment.currentTarget.querySelector(
    '[data-blog-pagination-runtime-status]',
  );
  assert.ok(runtimeStatus, 'a page without filters still receives live feedback');
  assert.equal(runtimeStatus.getAttribute('role'), 'status');
  assert.equal(runtimeStatus.getAttribute('aria-live'), 'polite');
  assert.equal(runtimeStatus.dataset.state, 'error');
  assert.equal(runtimeStatus.hidden, false);
  assert.match(runtimeStatus.textContent, /conservan/);

  cleanup();
  assert.equal(runtimeStatus.isConnected, false);
  assert.equal(
    click(environment, environment.currentTarget.pagination.links[0])
      .defaultPrevented,
    false,
  );
};

await testSuccessfulNavigationAndNativeBoundaries();
await testLatestClickWinsEvenWhenFetchIgnoresAbort();
await testFilterCoordination();
await testPopStateFallbackAndBfcacheCleanup();
await testRecoverableFailuresAndTimeout();
await testRuntimeStatusAndDisposal();

process.stdout.write('Blog reactive pagination adversarial runtime: OK\n');
