import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(process.argv[2], 'utf8');
const listenersFor = (target) => target.__listeners || (target.__listeners = []);

class EventFixture {
    constructor(type, options = {}) {
        this.type = type;
        this.bubbles = options.bubbles === true;
        this.cancelable = options.cancelable === true;
        this.defaultPrevented = false;
        this.target = null;
    }

    preventDefault() {
        this.defaultPrevented = true;
    }
}

class CustomEventFixture extends EventFixture {
    constructor(type, options = {}) {
        super(type, options);
        this.detail = options.detail;
    }
}

class ElementFixture {
    constructor(tag = 'div') {
        this.tagName = tag.toUpperCase();
        this.attributes = new Map();
        this.children = [];
        this.parentNode = null;
        this.textContent = '';
        this.hidden = false;
        this.disabled = false;
        this.value = '';
        this.className = '';
    }

    addEventListener(type, handler, options = {}) {
        const entry = { type, handler };
        listenersFor(this).push(entry);
        options.signal?.addEventListener('abort', () => {
            const index = listenersFor(this).indexOf(entry);
            if (index >= 0) listenersFor(this).splice(index, 1);
        }, { once: true });
    }

    dispatchEvent(event) {
        event.target = this;
        listenersFor(this)
            .filter((entry) => entry.type === event.type)
            .slice()
            .forEach((entry) => entry.handler(event));
        return !event.defaultPrevented;
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
        if (name === 'open') this.open = true;
        notify(this, { type: 'attributes', attributeName: name });
    }

    getAttribute(name) {
        return this.attributes.has(name) ? this.attributes.get(name) : null;
    }

    removeAttribute(name) {
        this.attributes.delete(name);
        if (name === 'open') this.open = false;
        notify(this, { type: 'attributes', attributeName: name });
    }

    append(...nodes) {
        nodes.forEach((node) => {
            node.parentNode = this;
            this.children.push(node);
        });
        notify(this, { type: 'childList' });
    }

    replaceChildren(...nodes) {
        this.children = [];
        this.append(...nodes);
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] || null;
    }

    querySelectorAll(selector) {
        const found = [];
        const visit = (node) => {
            node.children.forEach((child) => {
                if (matches(child, selector)) found.push(child);
                visit(child);
            });
        };
        visit(this);
        return found;
    }
}

class HTMLElementFixture extends ElementFixture {}
class HTMLButtonElementFixture extends HTMLElementFixture {
    constructor() { super('button'); }
    click() { this.dispatchEvent(new EventFixture('click')); }
}
class HTMLInputElementFixture extends HTMLElementFixture {
    constructor() { super('input'); }
}
class HTMLProgressElementFixture extends HTMLElementFixture {
    constructor() { super('progress'); }
}
class HTMLOptionElementFixture extends HTMLElementFixture {
    constructor() { super('option'); }
}
class HTMLSelectElementFixture extends HTMLElementFixture {
    constructor() { super('select'); }
    get options() { return this.children; }
    get selectedOptions() {
        return this.options.filter((option) => option.value === this.value);
    }
}
class HTMLFormElementFixture extends HTMLElementFixture {
    constructor() {
        super('form');
        this.controls = {};
        this.elements = { namedItem: (name) => this.controls[name] || null };
        this.valid = true;
        this.resetCount = 0;
    }
    reportValidity() { return this.valid; }
    reset() {
        this.resetCount += 1;
        Object.values(this.controls).forEach((control) => { control.value = ''; });
    }
}
class HTMLDialogElementFixture extends HTMLElementFixture {
    constructor() { super('dialog'); this.open = false; }
    showModal() { this.setAttribute('open', ''); }
    close() {
        this.removeAttribute('open');
        this.dispatchEvent(new EventFixture('close'));
    }
}

const observers = [];
class MutationObserverFixture {
    constructor(callback) { this.callback = callback; this.targets = []; }
    observe(target, options) { this.targets.push({ target, options }); observers.push(this); }
    disconnect() { this.targets = []; }
}
function notify(target, record) {
    observers.forEach((observer) => {
        if (observer.targets.some((entry) => entry.target === target)) {
            observer.callback([record]);
        }
    });
}

function matches(node, selector) {
    if (selector === 'button[type="submit"]') {
        return node instanceof HTMLButtonElementFixture
            && node.getAttribute('type') === 'submit';
    }
    const match = selector.match(/^\[([^=\]]+)(?:="([^"]*)")?\]$/);
    if (!match) return false;
    return node.attributes.has(match[1])
        && (match[2] === undefined || node.getAttribute(match[1]) === match[2]);
}

const make = (ClassName, attr = null) => {
    const node = new ClassName();
    if (attr) node.setAttribute(attr, '');
    return node;
};
const dialog = make(HTMLDialogElementFixture, 'data-webadmin-media-picker');
dialog.setAttribute('data-webadmin-media-picker-catalog', '/admin/media/catalog');
const search = make(HTMLFormElementFixture, 'data-webadmin-media-picker-search');
const query = new HTMLInputElementFixture();
search.controls.q = query;
const results = make(HTMLElementFixture, 'data-webadmin-media-picker-results');
const status = make(HTMLElementFixture, 'data-webadmin-media-picker-status');
const active = make(HTMLElementFixture, 'data-webadmin-media-picker-active');
const previous = make(HTMLButtonElementFixture, 'data-webadmin-media-picker-previous');
const next = make(HTMLButtonElementFixture, 'data-webadmin-media-picker-next');
const select = make(HTMLSelectElementFixture, 'data-webadmin-media-picker-select');
const placeholder = new HTMLOptionElementFixture();
placeholder.value = '';
placeholder.textContent = 'Selecciona una imagen';
select.append(placeholder);
const confirm = make(HTMLButtonElementFixture, 'data-webadmin-media-picker-confirm');
confirm.disabled = true;
const close = make(HTMLButtonElementFixture, 'data-webadmin-media-picker-close');
const upload = make(HTMLFormElementFixture, 'data-webadmin-media-picker-upload');
upload.action = '/admin/media/upload';
const label = new HTMLInputElementFixture();
const image = new HTMLInputElementFixture();
const idempotency = new HTMLInputElementFixture();
upload.controls = { label, image, idempotency_key: idempotency };
upload.elements = { namedItem: (name) => upload.controls[name] || null };
const submit = new HTMLButtonElementFixture();
submit.setAttribute('type', 'submit');
const progress = make(HTMLProgressElementFixture, 'data-webadmin-media-picker-progress');
progress.hidden = true;
const uploadStatus = make(HTMLElementFixture, 'data-webadmin-media-picker-upload-status');
upload.append(label, image, idempotency, submit);
dialog.append(
    search, results, status, active, previous, next, select, confirm, close,
    upload, progress, uploadStatus
);

const ids = {
    first: '11111111-1111-4111-8111-111111111111',
    second: '22222222-2222-4222-8222-222222222222',
    upload: '33333333-3333-4333-8333-333333333333',
};
const urlFor = (id) => `/admin/media/file?asset=${id}&width=480`;
const itemFor = (id, name) => ({
    public_id: id,
    label: name,
    thumbnail: { url: urlFor(id) },
    created_at: '2030-01-01T10:00:00+00:00',
    source_width: 1800,
    source_height: 1200,
    variants: [{ width: 480, height: 320, bytes: 100 }],
});
const requests = [];
let idempotencyAtSend = '';
const fetchFixture = async (url, options) => {
    requests.push({ url: String(url), options });
    if (options.method === 'POST') {
        idempotencyAtSend = idempotency.value;
        return {
            ok: true,
            json: async () => ({
                ok: true,
                media: {
                    public_id: ids.upload,
                    label: 'Subida',
                    thumbnail_url: urlFor(ids.upload),
                    thumbnail_width: 480,
                },
            }),
        };
    }
    const requested = new URL(String(url));
    const second = requested.searchParams.get('q') === 'segunda';
    return {
        ok: true,
        json: async () => ({
            ok: true,
            query: { page: 1, per_page: 24 },
            pagination: { has_previous: false, has_next: false },
            items: [itemFor(second ? ids.second : ids.first, second ? 'Segunda' : 'Primera')],
        }),
    };
};

const documentRef = {
    documentElement: { lang: 'es' },
    querySelectorAll(selector) {
        return selector === '[data-webadmin-media-picker]' ? [dialog] : [];
    },
    createElement(tag) {
        if (tag === 'button') return new HTMLButtonElementFixture();
        if (tag === 'option') return new HTMLOptionElementFixture();
        return new HTMLElementFixture(tag);
    },
};
const windowRef = {
    location: { href: 'https://example.test/admin/blog', origin: 'https://example.test' },
    crypto: { randomUUID: () => '44444444-4444-4444-8444-444444444444' },
    fetch: fetchFixture,
};
class FormDataFixture { constructor(form) { this.form = form; } }

const sandbox = {
    window: windowRef,
    document: documentRef,
    fetch: fetchFixture,
    AbortController,
    MutationObserver: MutationObserverFixture,
    Event: EventFixture,
    CustomEvent: CustomEventFixture,
    HTMLElement: HTMLElementFixture,
    HTMLButtonElement: HTMLButtonElementFixture,
    HTMLInputElement: HTMLInputElementFixture,
    HTMLProgressElement: HTMLProgressElementFixture,
    HTMLSelectElement: HTMLSelectElementFixture,
    HTMLFormElement: HTMLFormElementFixture,
    FormData: FormDataFixture,
    URL,
    Date,
    Intl,
    Uint8Array,
    Set,
};
vm.runInNewContext(source, sandbox, { filename: process.argv[2] });

const settle = async () => {
    for (let index = 0; index < 12; index += 1) await Promise.resolve();
};
const cards = () => results.querySelectorAll('[data-webadmin-media-picker-item]');
const selections = [];
dialog.addEventListener('liquidstack:webadmin-media-picker:selected', (event) => {
    selections.push(event.detail);
});

const enhanced = dialog.getAttribute('data-webadmin-media-picker-enhanced') === 'true';
dialog.showModal();
await settle();
cards()[0].click();
const enabledFromEmpty = confirm.disabled === false;
confirm.click();
const firstConfirmed = selections.length === 1 && dialog.open === false;

dialog.showModal();
query.value = 'segunda';
search.dispatchEvent(new EventFixture('submit'));
await settle();
cards()[0].click();
const tentativeSecond = select.value === ids.second;
dialog.dispatchEvent(new EventFixture('cancel', { cancelable: true }));
const cancelPreservedDraft = selections.length === 1
    && select.value === ids.first && dialog.open === false;

dialog.showModal();
label.value = 'Subida';
image.value = 'image.png';
upload.dispatchEvent(new EventFixture('submit', { cancelable: true }));
upload.dispatchEvent(new EventFixture('submit', { cancelable: true }));
await settle();
const uploadRequest = requests.find((request) => request.options.method === 'POST');
const uploadConfirmed = selections.length === 2
    && selections[1].public_id === ids.upload
    && uploadRequest.options.headers['X-LiquidStack-Media-Manager'] === 'async'
    && requests.filter((request) => request.options.method === 'POST').length === 1
    && idempotencyAtSend === '44444444-4444-4444-8444-444444444444'
    && idempotency.value === ''
    && upload.resetCount === 1
    && progress.hidden === true;

process.stdout.write(JSON.stringify({
    enhanced,
    enabledFromEmpty,
    firstConfirmed,
    tentativeSecond,
    cancelPreservedDraft,
    uploadConfirmed,
    selectionShape: Object.keys(selections[0] || {}).sort(),
}));
