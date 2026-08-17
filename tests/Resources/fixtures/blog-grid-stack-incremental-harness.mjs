import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const root = resolve(process.argv[2]);
const resources = resolve(
  root,
  'modules/blog/resources/project/src/js/resources',
);

const importResource = async (file, replacements) => {
  let source = await readFile(resolve(resources, file), 'utf8');
  for (const [from, to] of replacements) {
    source = source.replace(from, to);
  }

  return import(
    `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`
  );
};

class FakeTarget {
  constructor() {
    this.listeners = new Map();
  }

  addEventListener(type, listener) {
    const listeners = this.listeners.get(type) ?? new Set();
    listeners.add(listener);
    this.listeners.set(type, listeners);
  }

  removeEventListener(type, listener) {
    this.listeners.get(type)?.delete(listener);
  }

  emit(type, detail) {
    for (const listener of this.listeners.get(type) ?? []) {
      listener({ type, detail });
    }
  }
}

class FakeClassList {
  constructor(...classes) {
    this.classes = new Set(classes);
  }

  add(className) {
    this.classes.add(className);
  }

  remove(className) {
    this.classes.delete(className);
  }

  contains(className) {
    return this.classes.has(className);
  }

  [Symbol.iterator]() {
    return this.classes[Symbol.iterator]();
  }
}

class FakeStyle {
  setProperty() {}
  removeProperty() {}
}

class FakeCard {
  constructor(key) {
    this.dataset = { blogCardKey: key };
    this.style = new FakeStyle();
    this.offsetHeight = 120;
  }

  matches(selector) {
    return selector === '[data-blog-card-key]';
  }

  getBoundingClientRect() {
    return { height: 120 };
  }
}

const nextTick = () => new Promise((resolveTick) => {
  setTimeout(resolveTick, 5);
});

globalThis.__gridGsap = {
  set() {},
  fromTo(cards, from, to) {
    return {
      kill() {
        to.onInterrupt?.();
      },
    };
  },
};
const gridModule = await importResource('_moduleBlogGrid02.js', [[
  "import gsap from 'gsap';",
  'const gsap = globalThis.__gridGsap;',
]]);
const gridMotion = new FakeTarget();
gridMotion.matches = false;
const gridView = { matchMedia: () => gridMotion };
const gridDocument = new FakeTarget();
gridDocument.nodeType = 9;
gridDocument.defaultView = gridView;
const gridRoot = {
  ownerDocument: gridDocument,
  isConnected: true,
  classList: new FakeClassList(
    'moduleBlogGrid02',
    'moduleBlogGrid02--items-0',
  ),
  cards: [],
  matches: (selector) => selector === '[data-blog-grid02]',
  querySelectorAll(selector) {
    return selector === '[data-blog-card-key]' ? this.cards : [];
  },
  contains(card) {
    return this.cards.includes(card);
  },
};
gridDocument.querySelectorAll = (selector) => (
  selector === '[data-blog-grid02]' ? [gridRoot] : []
);
const cleanupGrid = gridModule.initModuleBlogGrid02(gridDocument);
gridRoot.cards.push(
  new FakeCard('grid-a'),
  new FakeCard('grid-b'),
  new FakeCard('grid-c'),
);
gridDocument.emit('liquidstack:blog-collection-appended', {
  root: gridRoot,
  items: gridRoot.cards,
});
assert.equal(gridRoot.classList.contains('moduleBlogGrid02--items-0'), false);
assert.equal(gridRoot.classList.contains('moduleBlogGrid02--items-3'), true);
cleanupGrid();

globalThis.__stackGsap = { registerPlugin() {} };
globalThis.__stackScrollTrigger = { create() {}, refresh() {} };
const stackModule = await importResource('_sectionBlogStack01.js', [
  ["import gsap from 'gsap';", 'const gsap = globalThis.__stackGsap;'],
  [
    "import ScrollTrigger from 'gsap/ScrollTrigger';",
    'const ScrollTrigger = globalThis.__stackScrollTrigger;',
  ],
]);
const stackMotion = new FakeTarget();
stackMotion.matches = false;
const stackView = new FakeTarget();
stackView.matchMedia = () => stackMotion;
stackView.innerHeight = 800;
stackView.requestAnimationFrame = (callback) => setTimeout(callback, 0);
stackView.cancelAnimationFrame = clearTimeout;
stackView.setTimeout = setTimeout;
stackView.clearTimeout = clearTimeout;
stackView.getComputedStyle = () => ({ getPropertyValue: () => '12px' });
const stackDocument = new FakeTarget();
stackDocument.nodeType = 9;
stackDocument.defaultView = stackView;
const stackList = {
  cards: [],
  matches: () => false,
  querySelectorAll(selector) {
    return selector === '[data-blog-card-key]' ? this.cards : [];
  },
};
const stackRoot = {
  id: 'sectionBlogStack01-00',
  ownerDocument: stackDocument,
  isConnected: true,
  classList: new FakeClassList(
    'sectionBlogStack01',
    'sectionBlogStack01--items-0',
  ),
  matches: (selector) => selector === '[data-blog-stack01]',
  querySelector(selector) {
    return selector === '[data-blog-collection-items]' ? stackList : null;
  },
};
stackDocument.querySelectorAll = (selector) => (
  selector === '[data-blog-stack01]' ? [stackRoot] : []
);
const cleanupStack = stackModule.initSectionBlogStack01(stackDocument);
await nextTick();
stackList.cards.push(new FakeCard('stack-a'), new FakeCard('stack-b'));
stackDocument.emit('liquidstack:blog-collection-appended', {
  root: stackRoot,
  items: stackList.cards,
});
await nextTick();
assert.equal(stackRoot.classList.contains('sectionBlogStack01--items-0'), false);
assert.equal(stackRoot.classList.contains('sectionBlogStack01--items-2'), true);
cleanupStack();

process.stdout.write(JSON.stringify({ grid: 3, stack: 2 }));
