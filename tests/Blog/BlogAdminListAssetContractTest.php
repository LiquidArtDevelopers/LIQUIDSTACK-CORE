<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BlogAdminListAssetContractTest extends TestCase
{
    public function testDesktopListUsesFullWorkspaceAndLocalTableOverflow(): void
    {
        $css = file_get_contents(
            dirname(__DIR__, 2)
                . '/modules/blog/published/assets/blog-admin.css'
        );
        self::assertIsString($css);

        foreach ([
            '.webadmin .blogAdminPage--index {',
            'width: 100%',
            'max-width: none',
            '.webadmin .blogAdminPage__tableViewport {',
            'overflow-x: auto',
            '.webadmin .blogAdminPage__rowActions {',
            'flex-wrap: wrap',
            '.webadmin .blogAdminPage__locale {',
            '.webadmin .blogAdminPage__action--disabled {',
        ] as $contract) {
            self::assertStringContainsString($contract, $css);
        }

        self::assertDoesNotMatchRegularExpression(
            '/\.blogAdminPage\s*\{[^}]*overflow-x\s*:\s*auto/s',
            $css
        );
    }

    public function testTrashConfirmationIsHmrSafeAndCancelsSubmission(): void
    {
        $asset = dirname(__DIR__, 2)
            . '/modules/blog/published/assets/blog-admin-list.js';
        $javascript = file_get_contents($asset);
        self::assertIsString($javascript);

        foreach ([
            "Symbol.for('liquidstack.blog.admin-list')",
            'new AbortController()',
            "'[data-blog-trash-form]'",
            'window.confirm(',
            'event.preventDefault()',
            'previous.dispose()',
        ] as $contract) {
            self::assertStringContainsString($contract, $javascript);
        }
        foreach (['.innerHTML', 'eval(', 'new Function', 'fetch('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $javascript);
        }

        $node = new Process(['node', '--check', $asset]);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no estÃ¡ disponible.');
        }
        self::assertSame(0, $node->getExitCode(), $node->getErrorOutput());

        $harness = <<<'JS'
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(process.argv[1], 'utf8');
let listeners = [];
let confirmResult = false;
let confirmCount = 0;
const documentRef = {
  addEventListener(type, handler, options = {}) {
    const entry = { type, handler };
    listeners.push(entry);
    options.signal?.addEventListener('abort', () => {
      listeners = listeners.filter((candidate) => candidate !== entry);
    }, { once: true });
  },
};
class Form {
  constructor(matches, title = '') {
    this.matchesSelector = matches;
    this.dataset = { blogTitle: title };
  }
  matches(selector) {
    return this.matchesSelector && selector === '[data-blog-trash-form]';
  }
}
const windowRef = {
  confirm() {
    confirmCount += 1;
    return confirmResult;
  },
};
const sandbox = {
  window: windowRef,
  document: documentRef,
  AbortController,
  HTMLFormElement: Form,
  Symbol,
};
const run = () => vm.runInNewContext(source, sandbox, {
  filename: process.argv[1],
});
const submit = (target) => {
  const event = {
    target,
    prevented: false,
    preventDefault() { this.prevented = true; },
  };
  listeners
    .filter((entry) => entry.type === 'submit')
    .forEach((entry) => entry.handler(event));
  return event.prevented;
};

run();
run();
const listenerCount = listeners.filter(
  (entry) => entry.type === 'submit'
).length;
const unrelatedPrevented = submit(new Form(false));
const cancelled = submit(new Form(true, 'Matrix “segura”'));
confirmResult = true;
const acceptedWasPrevented = submit(new Form(true, 'Matrix'));

process.stdout.write(JSON.stringify({
  listenerCount,
  unrelatedPrevented,
  cancelled,
  acceptedWasPrevented,
  confirmCount,
}));
JS;
        $runtime = new Process([
            'node',
            '--input-type=module',
            '--eval',
            $harness,
            $asset,
        ]);
        $runtime->run();
        self::assertTrue($runtime->isSuccessful(), $runtime->getErrorOutput());
        $result = json_decode(
            $runtime->getOutput(),
            true,
            8,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(1, $result['listenerCount']);
        self::assertFalse($result['unrelatedPrevented']);
        self::assertTrue($result['cancelled']);
        self::assertFalse($result['acceptedWasPrevented']);
        self::assertSame(2, $result['confirmCount']);
    }
}
