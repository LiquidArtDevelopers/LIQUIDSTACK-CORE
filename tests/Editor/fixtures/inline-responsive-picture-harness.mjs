import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const coreRoot = resolve(process.argv[2]);
const source = await readFile(
  resolve(coreRoot, 'resources/js/_inlineResponsivePicture.js'),
  'utf8',
);
const {
  applyInlineResponsivePicture,
  resolveInlineBackgroundContainer,
} = await import(
  `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`
);

class FakeElement {
  constructor(tagName, dataset = {}) {
    this.tagName = tagName;
    this.dataset = { ...dataset };
    this.attributes = new Map();
  }

  setAttribute(name, value) {
    this.attributes.set(name, String(value));
  }

  getAttribute(name) {
    return this.attributes.get(name) ?? null;
  }
}

const sourceElement = new FakeElement('SOURCE');
const image = new FakeElement('IMG', {
  bgMobile: 'https://example.test/mobile.avif',
  bgTablet: 'https://example.test/tablet.avif',
  bgDesktop: 'https://example.test/desktop.avif',
  bgFallback: 'https://example.test/fallback.avif',
});
const container = {
  dataset: {
    inlineBackgroundTarget: '.hero00-media',
    inlineBackgroundPictureSource: '.hero00-picture source',
    inlineBackgroundMobileDescriptor: '480w',
    inlineBackgroundTabletDescriptor: '900w',
    inlineBackgroundDesktopDescriptor: '1800w',
  },
  querySelector(selector) {
    if (selector === '.hero00-media') {
      return image;
    }
    return selector === '.hero00-picture source' ? sourceElement : null;
  },
};

image.closest = (selector) => (
  selector === '[data-inline-background]' ? container : null
);
image.contains = (candidate) => candidate === image;
const heading = {
  closest(selector) {
    return selector === '[data-inline-background]' ? container : null;
  },
};

assert.equal(resolveInlineBackgroundContainer(image, 1), container);
assert.equal(resolveInlineBackgroundContainer(heading, 1), null);
assert.equal(resolveInlineBackgroundContainer(heading, 0), container);
assert.equal(
  resolveInlineBackgroundContainer({ closest: () => null }, 0),
  null,
);

assert.equal(applyInlineResponsivePicture(container, image), true);
assert.equal(
  sourceElement.getAttribute('srcset'),
  'https://example.test/mobile.avif 480w, '
    + 'https://example.test/tablet.avif 900w, '
    + 'https://example.test/desktop.avif 1800w',
);
assert.equal(
  image.getAttribute('src'),
  'https://example.test/fallback.avif',
);

const invalidSource = new FakeElement('SOURCE');
const invalidContainer = {
  ...container,
  dataset: {
    ...container.dataset,
    inlineBackgroundTabletDescriptor: '900w onerror=alert(1)',
  },
  querySelector() {
    return invalidSource;
  },
};
assert.equal(applyInlineResponsivePicture(invalidContainer, image), false);
assert.equal(invalidSource.getAttribute('srcset'), null);

assert.equal(
  applyInlineResponsivePicture({ dataset: {}, querySelector() {} }, image),
  false,
);

process.stdout.write(JSON.stringify({
  refreshed: true,
  visualTargetPrioritized: true,
  nestedCopyPreserved: true,
  invalidDescriptorRejected: true,
  missingContractRejected: true,
}));
