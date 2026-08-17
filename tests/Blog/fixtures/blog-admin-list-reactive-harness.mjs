import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(process.argv[2], 'utf8');
let documentListeners = [];
let windowListeners = [];
let currentResults;
const replacements = [];
const historyCalls = [];
const assigned = [];
const pendingRequests = [];
const statusStates = [];
const timers = new Map();
let timerSequence = 0;

class ElementFixture {
    closest() {
        return null;
    }
}

class SearchInputFixture extends ElementFixture {
    constructor() {
        super();
        this.value = '';
    }

    closest(selector) {
        return selector === '[data-blog-admin-live-search]' ? this : null;
    }
}

class FormFixture extends ElementFixture {
    constructor(searchInput) {
        super();
        this.action = 'https://example.test/admin/blog';
        this.searchInput = searchInput;
        this.controls = {
            q: searchInput,
            status: { value: '' },
            locale: { value: '' },
        };
        this.elements = {
            namedItem: (name) => this.controls[name] || null,
        };
    }

    contains(node) {
        return Object.values(this.controls).includes(node);
    }

    checkValidity() {
        return this.searchInput.value === ''
            || this.searchInput.value.length >= 2;
    }

    matches(selector) {
        return selector === '[data-blog-trash-form]' ? false : false;
    }
}

class AnchorFixture extends ElementFixture {}

const searchInput = new SearchInputFixture();
const form = new FormFixture(searchInput);
const statusDataset = {};
Object.defineProperty(statusDataset, 'state', {
    get() {
        return statusStates.at(-1) || '';
    },
    set(state) {
        statusStates.push(state);
    },
});
const status = { hidden: true, textContent: '', dataset: statusDataset };

const results = (label) => ({
    dataset: { blogAdminResultCount: '1', label },
    setAttribute() {},
    removeAttribute() {},
    replaceWith(next) {
        currentResults = next;
        replacements.push(next.dataset.label);
    },
    querySelector(selector) {
        return selector === '[role="region"]' ? { focus() {} } : null;
    },
});
currentResults = results('initial');

const addListener = (collection, type, handler, options = {}) => {
    const entry = { type, handler };
    collection.push(entry);
    options.signal?.addEventListener('abort', () => {
        const index = collection.indexOf(entry);
        if (index >= 0) {
            collection.splice(index, 1);
        }
    }, { once: true });
};

const incomingForm = (search) => ({
    elements: {
        namedItem(name) {
            if (name === 'q') {
                return { value: search };
            }
            if (name === 'status' || name === 'locale') {
                return { value: '' };
            }
            return null;
        },
    },
});

class DOMParserFixture {
    parseFromString(search) {
        const parsedResults = results(search);
        return {
            title: `Blog ${search}`,
            querySelector(selector) {
                if (selector === '[data-blog-admin-results]') {
                    return parsedResults;
                }
                if (selector === '[data-blog-admin-filter-form]') {
                    return incomingForm(search);
                }
                return null;
            },
        };
    }
}

class FormDataFixture {
    constructor(target) {
        this.target = target;
    }

    *[Symbol.iterator]() {
        yield ['q', this.target.controls.q.value];
        yield ['status', this.target.controls.status.value];
        yield ['locale', this.target.controls.locale.value];
    }
}

const location = {
    href: 'https://example.test/admin/blog',
    origin: 'https://example.test',
    assign(url) {
        assigned.push(url);
    },
};
const writeHistory = (type, url) => {
    historyCalls.push({ type, url });
    location.href = new URL(url, location.href).href;
};
const windowRef = {
    location,
    history: {
        pushState(_state, _title, url) {
            writeHistory('push', url);
        },
        replaceState(_state, _title, url) {
            writeHistory('replace', url);
        },
    },
    DOMParser: DOMParserFixture,
    fetch(url, options) {
        return new Promise((resolve) => {
            pendingRequests.push({ url, options, resolve });
        });
    },
    addEventListener(type, handler, options) {
        addListener(windowListeners, type, handler, options);
    },
    setTimeout(handler) {
        const id = ++timerSequence;
        timers.set(id, handler);
        return id;
    },
    clearTimeout(id) {
        timers.delete(id);
    },
    confirm() {
        return true;
    },
};
const documentRef = {
    title: 'Blog initial',
    querySelector(selector) {
        if (selector === '[data-blog-admin-filter-form]') {
            return form;
        }
        if (selector === '[data-blog-admin-results]') {
            return currentResults;
        }
        if (selector === '[data-blog-admin-filter-status]') {
            return status;
        }
        return null;
    },
    addEventListener(type, handler, options) {
        addListener(documentListeners, type, handler, options);
    },
    importNode(node) {
        return node;
    },
};

const sandbox = {
    window: windowRef,
    document: documentRef,
    AbortController,
    Element: ElementFixture,
    HTMLFormElement: FormFixture,
    HTMLAnchorElement: AnchorFixture,
    FormData: FormDataFixture,
    URL,
    URLSearchParams,
    Symbol,
};
vm.runInNewContext(source, sandbox, { filename: process.argv[2] });

const dispatchDocument = (type, target) => {
    const event = {
        target,
        button: 0,
        ctrlKey: false,
        metaKey: false,
        shiftKey: false,
        altKey: false,
        preventDefault() {},
    };
    documentListeners
        .filter((entry) => entry.type === type)
        .forEach((entry) => entry.handler(event));
};
const runNextTimer = () => {
    const entry = timers.entries().next();
    if (entry.done) {
        throw new Error('Expected a pending live-search timer.');
    }
    const [id, handler] = entry.value;
    timers.delete(id);
    return handler();
};
const responseFor = (request) => ({
    ok: true,
    url: request.url,
    headers: { get: () => 'text/html; charset=utf-8' },
    text: () => new URL(request.url).searchParams.get('q') || 'empty',
});
const failedResponseFor = (request) => ({
    ok: false,
    url: request.url,
    headers: { get: () => 'text/html; charset=utf-8' },
    text: () => '',
});

searchInput.value = 'first';
dispatchDocument('input', searchInput);
const firstTask = runNextTimer();

searchInput.value = 'second';
dispatchDocument('input', searchInput);
const firstAborted = pendingRequests[0].options.signal.aborted;
const secondTask = runNextTimer();
pendingRequests[1].resolve(responseFor(pendingRequests[1]));
await secondTask;
pendingRequests[0].resolve(responseFor(pendingRequests[0]));
await firstTask;

searchInput.value = 'third';
dispatchDocument('input', searchInput);
const thirdTask = runNextTimer();
pendingRequests[2].resolve(responseFor(pendingRequests[2]));
await thirdTask;

windowListeners
    .filter((entry) => entry.type === 'popstate')
    .forEach((entry) => entry.handler({}));
pendingRequests[3].resolve(responseFor(pendingRequests[3]));
for (let index = 0; index < 6; index += 1) {
    await Promise.resolve();
}

searchInput.value = 'failure';
dispatchDocument('input', searchInput);
const failureTask = runNextTimer();
pendingRequests[4].resolve(failedResponseFor(pendingRequests[4]));
await failureTask;

process.stdout.write(JSON.stringify({
    firstAborted,
    historyTypes: historyCalls.map((entry) => entry.type),
    historyUrls: historyCalls.map((entry) => entry.url),
    replacements,
    assigned,
    statusStates,
    statusState: status.dataset.state,
    statusText: status.textContent,
    statusHidden: status.hidden,
}));
