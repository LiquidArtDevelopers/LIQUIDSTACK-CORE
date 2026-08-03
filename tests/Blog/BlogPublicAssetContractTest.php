<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BlogPublicAssetContractTest extends TestCase
{
    public function testFallbackStylesheetIsNeutralResponsiveAndStandalone(): void
    {
        $path = dirname(__DIR__, 2)
            . '/modules/blog/published/assets/blog-public.css';
        $css = file_get_contents($path);
        self::assertIsString($css);

        foreach ([
            'color-scheme: light dark',
            'font-family: system-ui',
            'width: min(100%',
            '.blogDocument__image--cover',
            '.blogDocument__liteYoutube',
            'aspect-ratio: 16 / 9',
            '@media (max-width: 35rem)',
            '@media (prefers-reduced-motion: reduce)',
            'a:focus-visible',
        ] as $contract) {
            self::assertStringContainsString($contract, $css);
        }

        foreach ([
            '@import',
            'javascript:',
            'expression(',
            'http://',
            'https://',
            'url(',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $css);
        }
    }

    public function testYoutubeRuntimeRequiresConsentAndCleansItsLifecycle(): void
    {
        $asset = dirname(__DIR__, 2)
            . '/modules/blog/published/assets/blog-public.js';
        $javascript = file_get_contents($asset);
        self::assertIsString($javascript);

        foreach ([
            "readCookie('cookie_social') === 'true'",
            "'cookielad:consent-change'",
            "event.button !== 0",
            'event.metaKey',
            'event.ctrlKey',
            'event.shiftKey',
            'event.altKey',
            'event.preventDefault()',
            'https://www.youtube-nocookie.com/embed/',
            "source.searchParams.set('autoplay', '1')",
            "iframe.referrerPolicy = 'strict-origin-when-cross-origin'",
            'new AbortController()',
            'previousRuntime.destroy()',
            'Array.from(mounted.keys()).forEach(unmount)',
        ] as $contract) {
            self::assertStringContainsString($contract, $javascript);
        }
        foreach ([
            'https://i.ytimg.com',
            'https://www.youtube.com/embed/',
            '.innerHTML',
            'document.write',
            'eval(',
            'new Function',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $javascript);
        }

        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no estÃ¡ disponible.');
        }

        $script = <<<'JS'
import fs from 'node:fs';
import vm from 'node:vm';
import { URL } from 'node:url';

const source = fs.readFileSync(process.argv[1], 'utf8');

function eventBus() {
  const listeners = new Map();
  return {
    addEventListener(type, handler, options = {}) {
      const entries = listeners.get(type) || [];
      entries.push(handler);
      listeners.set(type, entries);
      options.signal?.addEventListener('abort', () => {
        const current = listeners.get(type) || [];
        listeners.set(type, current.filter((entry) => entry !== handler));
      }, { once: true });
    },
    dispatch(type, event = {}) {
      (listeners.get(type) || []).slice().forEach((handler) => handler(event));
    },
    count(type) {
      return (listeners.get(type) || []).length;
    },
  };
}

const windowBus = eventBus();
const documentBus = eventBus();
let cookie = '';
let focused = false;

const caption = { textContent: 'Matrix trailer accesible' };
const frameAttributes = new Map();
const trigger = {
  hidden: false,
  getAttribute(name) {
    return name === 'aria-labelledby' ? 'video-caption' : '';
  },
  closest(selector) {
    if (selector === '[data-blog-youtube-play]') return this;
    if (selector === '[data-blog-lite-youtube]') return root;
    return null;
  },
};
const target = {
  closest(selector) {
    return selector === '[data-blog-youtube-play]' ? trigger : null;
  },
};
const root = {
  dataset: { videoId: 'dQw4w9WgXcQ', startSeconds: '42' },
  children: [],
  querySelector(selector) {
    if (selector === '[data-blog-youtube-play]') return trigger;
    if (selector === '[data-blog-youtube-frame]') {
      return this.children.find((child) => child.isFrame) || null;
    }
    return null;
  },
  appendChild(child) {
    child.parent = this;
    this.children.push(child);
  },
};

const documentRef = {
  visibilityState: 'visible',
  addEventListener: documentBus.addEventListener,
  get cookie() { return cookie; },
  getElementById(id) { return id === 'video-caption' ? caption : null; },
  querySelectorAll(selector) {
    return selector.includes('data-blog-youtube-mounted')
      && root.dataset.blogYoutubeMounted === 'true'
      ? [root]
      : [];
  },
  createElement(name) {
    if (name !== 'iframe') throw new Error('Unexpected element.');
    const iframe = {
      isFrame: true,
      setAttribute(key, value) { frameAttributes.set(key, value); },
      focus() { focused = true; },
      remove() {
        if (!this.parent) return;
        this.parent.children = this.parent.children.filter(
          (child) => child !== this
        );
        this.parent = null;
      },
    };
    return iframe;
  },
};
const windowRef = {
  addEventListener: windowBus.addEventListener,
};
const sandbox = {
  window: windowRef,
  document: documentRef,
  AbortController,
  URL,
  Map,
  Array,
  Number,
  String,
  Boolean,
  encodeURIComponent,
  decodeURIComponent,
};

function click(overrides = {}) {
  const event = {
    target,
    button: 0,
    defaultPrevented: false,
    metaKey: false,
    ctrlKey: false,
    shiftKey: false,
    altKey: false,
    prevented: false,
    preventDefault() {
      this.defaultPrevented = true;
      this.prevented = true;
    },
    ...overrides,
  };
  documentBus.dispatch('click', event);
  return event;
}

vm.runInNewContext(source, sandbox, { filename: process.argv[1] });

const denied = click();
const deniedFrameCount = root.children.length;
cookie = 'cookie_social=true';
const modified = click({ ctrlKey: true });
const modifiedFrameCount = root.children.length;
const accepted = click();
const iframe = root.children[0];
const mounted = {
  prevented: accepted.prevented,
  frameCount: root.children.length,
  src: iframe?.src || '',
  title: iframe?.title || '',
  referrerPolicy: iframe?.referrerPolicy || '',
  triggerHidden: trigger.hidden,
  focused,
};

cookie = '';
windowBus.dispatch('cookielad:consent-change');
const revoked = {
  frameCount: root.children.length,
  triggerHidden: trigger.hidden,
};

cookie = 'cookie_social=true';
click();
vm.runInNewContext(source, sandbox, { filename: process.argv[1] });
const reinitialized = {
  clickListeners: documentBus.count('click'),
  frameCount: root.children.length,
  triggerHidden: trigger.hidden,
};
windowRef.LiquidStackBlogPublic.destroy();
const destroyed = {
  clickListeners: documentBus.count('click'),
  frameCount: root.children.length,
  triggerHidden: trigger.hidden,
};

process.stdout.write(JSON.stringify({
  denied: { prevented: denied.prevented, frameCount: deniedFrameCount },
  modified: {
    prevented: modified.prevented,
    frameCount: modifiedFrameCount,
  },
  mounted,
  revoked,
  reinitialized,
  destroyed,
}));
JS;

        $process = new Process([
            'node',
            '--input-type=module',
            '--eval',
            $script,
            $asset,
        ]);
        $process->mustRun();
        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertFalse($result['denied']['prevented']);
        self::assertSame(0, $result['denied']['frameCount']);
        self::assertFalse($result['modified']['prevented']);
        self::assertSame(0, $result['modified']['frameCount']);
        self::assertTrue($result['mounted']['prevented']);
        self::assertSame(1, $result['mounted']['frameCount']);
        self::assertStringStartsWith(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?',
            $result['mounted']['src']
        );
        self::assertStringContainsString(
            'autoplay=1',
            $result['mounted']['src']
        );
        self::assertStringContainsString(
            'start=42',
            $result['mounted']['src']
        );
        self::assertSame(
            'Matrix trailer accesible',
            $result['mounted']['title']
        );
        self::assertSame(
            'strict-origin-when-cross-origin',
            $result['mounted']['referrerPolicy']
        );
        self::assertTrue($result['mounted']['triggerHidden']);
        self::assertTrue($result['mounted']['focused']);
        self::assertSame(0, $result['revoked']['frameCount']);
        self::assertFalse($result['revoked']['triggerHidden']);
        self::assertSame(1, $result['reinitialized']['clickListeners']);
        self::assertSame(0, $result['reinitialized']['frameCount']);
        self::assertFalse($result['reinitialized']['triggerHidden']);
        self::assertSame(0, $result['destroyed']['clickListeners']);
        self::assertSame(0, $result['destroyed']['frameCount']);
        self::assertFalse($result['destroyed']['triggerHidden']);
    }
}
