import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(process.argv[2], 'utf8');
const initialization = '    initializeCatalog();';
const boundary = source.indexOf(initialization);
if (boundary < 0) {
    throw new Error('Language-flow initialization boundary not found.');
}

const instrumented = `${source.slice(0, boundary)}
    window.__languageHarness = {
        syncLanguageFlow,
        submitLanguageFlow,
        restoreLanguageSubmission,
    };
})();`;

class InputFixture {
    constructor(value = '', operationId = '') {
        this.value = value;
        this.disabled = false;
        this.dataset = operationId === ''
            ? {}
            : { blogLanguageOperationId: operationId };
        this.attributes = new Map();
        this.removed = false;
    }

    setAttribute(name, value) {
        this.attributes.set(name, value);
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    remove() {
        this.removed = true;
    }
}

const createdInputs = [];
const documentFixture = {
    createElement(tag) {
        if (tag !== 'input') {
            throw new Error(`Unexpected element: ${tag}`);
        }
        const input = new InputFixture();
        createdInputs.push(input);
        return input;
    },
};
const windowFixture = {
    requestAnimationFrame(callback) {
        callback();
    },
};
const context = {
    AbortController,
    Element: class {},
    HTMLAnchorElement: class {},
    HTMLButtonElement: class {},
    HTMLDialogElement: class {},
    HTMLFormElement: class {},
    HTMLInputElement: InputFixture,
    URL,
    WeakSet,
    document: documentFixture,
    window: windowFixture,
};
vm.runInNewContext(instrumented, context, {
    filename: 'blog-admin-list.instrumented.js',
});

const es = new InputFixture('es', 'option-operation-es');
const en = new InputFixture('en', 'option-operation-en');
es.dataset.blogLanguageOutcome = 'Copia ES';
es.dataset.blogLanguageSubmitLabel = 'Duplicar ES';
en.dataset.blogLanguageOutcome = 'Copia EN';
en.dataset.blogLanguageSubmitLabel = 'Añadir EN';
let selected = es;
const appended = [];
const form = {
    querySelector(selector) {
        if (!selector.includes('destination_locale')) {
            return null;
        }
        return selector.includes(':not(:disabled)') && selected.disabled
            ? null
            : selected;
    },
    append(input) {
        appended.push(input);
    },
    removeAttribute() {},
};
const operation = new InputFixture('fallback-operation');
const attributeTarget = () => ({
    disabled: false,
    textContent: '',
    attributes: new Map(),
    setAttribute(name, value) {
        this.attributes.set(name, value);
    },
    removeAttribute(name) {
        this.attributes.delete(name);
    },
});
const state = {
    form,
    operation,
    fallbackOperationId: operation.value,
    outcome: attributeTarget(),
    submit: attributeTarget(),
    close: attributeTarget(),
    dialog: {
        open: true,
        attributes: new Map(),
        setAttribute(name, value) {
            this.attributes.set(name, value);
        },
        removeAttribute(name) {
            this.attributes.delete(name);
        },
        focus() {},
    },
    radios: [
        { radio: es, disabled: false },
        { radio: en, disabled: false },
    ],
    submitting: false,
    submissionDestination: null,
};

const api = windowFixture.__languageHarness;
api.syncLanguageFlow(state);
const initial = operation.value;
api.submitLanguageFlow(state, { preventDefault() {} });
api.restoreLanguageSubmission(state);
const sameAfterPageshow = operation.value;

selected = en;
api.syncLanguageFlow(state);
const differentAfterBack = operation.value;
api.submitLanguageFlow(state, { preventDefault() {} });
api.restoreLanguageSubmission(state);
const differentAfterPageshow = operation.value;

process.stdout.write(JSON.stringify({
    initial,
    sameAfterPageshow,
    differentAfterBack,
    differentAfterPageshow,
    submittedDestinations: appended.length,
}));
