import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const rootPath = resolve(process.argv[2] ?? '.');
const resourcePath = resolve(rootPath, 'resources/js/_art11.js');
let source = await readFile(resourcePath, 'utf8');

const replacements = [
  [
    "import gsap from 'gsap';",
    'const gsap = globalThis.__art11Gsap;',
  ],
  [
    "import ScrollTrigger from 'gsap/ScrollTrigger';",
    'const ScrollTrigger = globalThis.__art11ScrollTrigger;',
  ],
];

for (const [from, to] of replacements) {
  assert.equal(source.includes(from), true, `Missing module import: ${from}`);
  source = source.replace(from, to);
}

const valueWrites = [];
let valueText = '0';
const valueElement = {};
Object.defineProperty(valueElement, 'textContent', {
  get: () => valueText,
  set: (value) => {
    valueText = String(value);
    valueWrites.push(valueText);
  },
});

const suffixWrites = [];
let suffixText = '+';
const suffixElement = {};
Object.defineProperty(suffixElement, 'textContent', {
  get: () => suffixText,
  set: (value) => {
    suffixText = String(value);
    suffixWrites.push(suffixText);
  },
});

const counterWrites = [];
const counter = {
  dataset: { target: '25' },
  parentElement: { id: 'stat-item' },
  querySelector(selector) {
    return selector === '[data-art11-counter-value]'
      ? valueElement
      : null;
  },
};
Object.defineProperty(counter, 'textContent', {
  get: () => `${valueText}${suffixText}`,
  set: (value) => {
    counterWrites.push(String(value));
  },
});

const observerRecords = [];
class MutationObserverFixture {
  constructor(callback) {
    this.callback = callback;
    this.target = null;
    this.options = null;
    this.disconnected = false;
    observerRecords.push(this);
  }

  observe(target, options) {
    this.target = target;
    this.options = options;
  }

  disconnect() {
    this.disconnected = true;
    this.target = null;
  }

  emit(records) {
    if (!this.disconnected) {
      this.callback(records);
    }
  }
}

const tweenRecords = [];
const gsapFixture = {
  registeredPlugins: [],
  registerPlugin(plugin) {
    this.registeredPlugins.push(plugin);
  },
  to(proxy, configuration) {
    const record = {
      proxy,
      configuration,
      killed: false,
      killCalls: 0,
      kill() {
        this.killed = true;
        this.killCalls += 1;
      },
    };
    tweenRecords.push(record);
    proxy.value = configuration.value;
    configuration.onUpdate?.();

    return record;
  },
};

const triggerRecords = [];
const ScrollTriggerFixture = {
  create(configuration) {
    const record = {
      configuration,
      killed: false,
      killCalls: 0,
      kill() {
        this.killed = true;
        this.killCalls += 1;
      },
    };
    triggerRecords.push(record);

    return record;
  },
};

globalThis.document = {
  querySelectorAll(selector) {
    return selector === '.art11 .stat-number' ? [counter] : [];
  },
};
globalThis.MutationObserver = MutationObserverFixture;
globalThis.__art11Gsap = gsapFixture;
globalThis.__art11ScrollTrigger = ScrollTriggerFixture;

const moduleUrl = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const art11 = await import(`${moduleUrl}#art11-inline-editor-runtime`);
assert.equal(typeof art11.default, 'function');

const cleanupFirst = art11.default();
assert.equal(typeof cleanupFirst, 'function');
assert.equal(triggerRecords.length, 1);
assert.equal(observerRecords.length, 1);
assert.deepEqual(observerRecords[0].options, {
  attributes: true,
  attributeFilter: ['data-target'],
});

triggerRecords[0].configuration.onEnter();
const initialTween = tweenRecords.at(-1);
assert.equal(initialTween.configuration.value, 25);
assert.equal(valueText, '25');
assert.equal(counterWrites.length, 0);
assert.equal(suffixText, '+');
assert.equal(suffixWrites.length, 0);
const initialValue = valueText;

counter.dataset.target = '30';
observerRecords[0].emit([{
  type: 'attributes',
  target: counter,
  attributeName: 'data-target',
}]);
assert.equal(initialTween.killed, true);
assert.equal(valueText, '30');
assert.equal(counterWrites.length, 0);
assert.equal(suffixText, '+');
const immediateValue = valueText;

triggerRecords[0].configuration.onEnter();
const refreshedTween = tweenRecords.at(-1);
assert.equal(refreshedTween.configuration.value, 30);
assert.equal(valueText, '30');

counter.dataset.target = 'not-a-number';
observerRecords[0].emit([{
  type: 'attributes',
  target: counter,
  attributeName: 'data-target',
}]);
assert.equal(refreshedTween.killed, true);
assert.equal(valueText, '0');
const invalidValue = valueText;

counter.dataset.target = '25';
triggerRecords[0].configuration.onEnter();
const tweenBeforeReinitialisation = tweenRecords.at(-1);
const triggerBeforeReinitialisation = triggerRecords[0];
const observerBeforeReinitialisation = observerRecords[0];
const cleanupSecond = art11.default();

assert.equal(tweenBeforeReinitialisation.killed, true);
assert.equal(triggerBeforeReinitialisation.killed, true);
assert.equal(observerBeforeReinitialisation.disconnected, true);
assert.equal(triggerRecords.length, 2);
assert.equal(observerRecords.length, 2);

triggerRecords[1].configuration.onEnter();
const finalTween = tweenRecords.at(-1);
assert.equal(finalTween.configuration.value, 25);
assert.equal(valueText, '25');

const finalTrigger = triggerRecords[1];
const finalObserver = observerRecords[1];
cleanupSecond();
assert.equal(finalTween.killed, true);
assert.equal(finalTrigger.killed, true);
assert.equal(finalObserver.disconnected, true);
cleanupSecond();

assert.equal(counterWrites.length, 0);
assert.equal(suffixText, '+');
assert.equal(suffixWrites.length, 0);
assert.ok(valueWrites.length >= 5);

process.stdout.write(JSON.stringify({
  animation: {
    initialValue,
    rootWrites: counterWrites.length,
    suffix: suffixText,
    suffixWrites: suffixWrites.length,
  },
  targetChanges: {
    immediateValue,
    nextEnterTarget: refreshedTween.configuration.value,
    invalidValue,
  },
  reinitialisation: {
    tweenKilled: tweenBeforeReinitialisation.killed,
    triggerKilled: triggerBeforeReinitialisation.killed,
    observerDisconnected: observerBeforeReinitialisation.disconnected,
  },
  finalCleanup: {
    tweenKilled: finalTween.killed,
    triggerKilled: finalTrigger.killed,
    observerDisconnected: finalObserver.disconnected,
  },
}));
