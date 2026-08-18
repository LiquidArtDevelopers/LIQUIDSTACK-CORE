<?php

declare(strict_types=1);

namespace Tests\Blog\Tags;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BlogTagEditorAssetsTest extends TestCase
{
    private string $javascript;
    private string $stylesheet;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $javascript = file_get_contents(
            $root . '/modules/blog/published/assets/blog-editor.js'
        );
        $stylesheet = file_get_contents(
            $root . '/modules/blog/published/assets/blog-admin.css'
        );
        self::assertIsString($javascript);
        self::assertIsString($stylesheet);
        $this->javascript = $javascript;
        $this->stylesheet = $stylesheet;
    }

    public function testTagEnhancementUsesSingleFlightAndFacetGuards(): void
    {
        foreach ([
            'function saveTagAssignment(state, commitPendingInput)',
            'var submittedFingerprint = tagListFingerprint(state.tags);',
            'var submittedRevision = state.revision;',
            'if (state.assignmentPending)',
            "'X-LiquidStack-Tag-Editor': 'async'",
            'function saveCategoryAssignment(state)',
            'categorySelectionFingerprint(state.assignmentForm)',
            'function editorialFacetsHaveChanges(context)',
            'function waitEditorialFacets(context)',
            'function saveEditorialFacets(context)',
            'function discardEditorialFacets(context)',
            'v2PrepareSavedPreview(context, forceDocumentSave)',
            'payload.tag_workspace_version',
            'v2SyncTagWorkspaceVersion(',
            'composer.dir = \'auto\';',
            'composer.maxLength = MAX_TAG_CSV_BYTES;',
            'var UNSAFE_TAG_TEXT =',
            "event.inputType === 'insertFromPaste'",
            'saveTagAssignment(state, false);',
            'saveTagAssignment(state, true);',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
        self::assertStringNotContainsString(
            'beforeunload',
            $this->javascript
        );
        self::assertStringNotContainsString(
            'composer.maxLength = MAX_TAG_NAME_BYTES;',
            $this->javascript
        );
        self::assertStringNotContainsString(
            '.toLocaleLowerCase(',
            $this->javascript
        );

        $tagStart = strpos(
            $this->javascript,
            'function tagAssignmentRequest(state)'
        );
        $tagEnd = strpos(
            $this->javascript,
            'function saveTagAssignment(state, commitPendingInput)',
            $tagStart === false ? 0 : $tagStart
        );
        self::assertNotFalse($tagStart);
        self::assertNotFalse($tagEnd);
        self::assertStringNotContainsString(
            'AbortController',
            substr($this->javascript, $tagStart, $tagEnd - $tagStart)
        );
    }

    public function testTagChipsHaveRoomAndAccessibleTouchTargets(): void
    {
        foreach ([
            '.webadmin .blogEditor__tags {',
            'padding-block: clamp(1.35rem, 3vw, 2rem);',
            'gap: 1rem;',
            'padding-inline: 0.8rem 0.3rem;',
            '.webadmin .blogEditor__tagList [data-blog-tag-remove] {',
            'width: 2.75rem;',
            'min-width: 2.75rem;',
            'min-height: 2.75rem;',
            '@media (forced-colors: active)',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->stylesheet);
        }
        $start = strpos($this->stylesheet, '.webadmin .blogEditor__tags {');
        $end = strpos($this->stylesheet, '}', $start === false ? 0 : $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        self::assertStringNotContainsString(
            'border-block-start',
            substr($this->stylesheet, $start, $end - $start)
        );
    }

    public function testCanonicalPayloadAndLocalizedCasRunInNode(): void
    {
        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no está disponible.');
        }

        $asset = dirname(__DIR__, 3)
            . '/modules/blog/published/assets/blog-editor.js';
        $script = <<<'JS'
import fs from 'node:fs';
import vm from 'node:vm';

let source = fs.readFileSync(process.argv[1], 'utf8');
const marker = '}());';
const markerIndex = source.lastIndexOf(marker);
if (markerIndex < 0) throw new Error('Unable to expose tag hooks.');
source = source.slice(0, markerIndex)
  + 'globalThis.__tagHooks = { normalizedTagName, '
  + 'validTagAssignmentPayload, v2SyncTagWorkspaceVersion, tagCsv, '
  + 'tagNameKey, '
  + 'maxTags: MAX_TAGS_PER_VARIANT, maxCsvBytes: MAX_TAG_CSV_BYTES };\n'
  + source.slice(markerIndex);

class FakeInput {
  constructor(value) { this.value = value; }
}
class FakeForm {
  constructor(values) {
    this.controls = Object.fromEntries(
      Object.entries(values).map(([name, value]) => [name, new FakeInput(value)])
    );
    this.elements = { namedItem: (name) => this.controls[name] || null };
  }
}
const editor = new FakeForm({
  post: 'post-a', locale: 'es', tag_workspace_version: '4',
});
const publish = new FakeForm({
  post: 'post-a', locale: 'es', tag_workspace_version: '4',
});
const english = new FakeForm({
  post: 'post-a', locale: 'en', tag_workspace_version: '9',
});
const anotherPost = new FakeForm({
  post: 'post-b', locale: 'es', tag_workspace_version: '7',
});

globalThis.HTMLInputElement = FakeInput;
globalThis.HTMLFormElement = FakeForm;
globalThis.document = {
  readyState: 'loading',
  addEventListener() {},
  querySelectorAll(selector) {
    return selector === 'form' ? [editor, publish, english, anotherPost] : [];
  },
};
globalThis.window = {};
vm.runInThisContext(source, { filename: process.argv[1] });

const valid = {
  ok: true,
  lock_version: 7,
  tag_workspace_version: 5,
  tags: [
    { name: 'Ahorro', slug: 'ahorro' },
    { name: 'Fiscalidad', slug: 'fiscalidad' },
  ],
};
const unsorted = { ...valid, tags: [...valid.tags].reverse() };
const extra = { ...valid, internal_id: 44 };
const pastedTags = Array.from({ length: 30 }, (_, index) => ({
  name: `Etiqueta editorial ${String(index + 1).padStart(2, '0')} con contexto`,
  slug: '',
}));
const pastedCsv = globalThis.__tagHooks.tagCsv(pastedTags);
const synced = globalThis.__tagHooks.v2SyncTagWorkspaceVersion(
  { form: editor },
  0
);
process.stdout.write(JSON.stringify({
  normalized: globalThis.__tagHooks.normalizedTagName('  Banca   ética  '),
  composed: globalThis.__tagHooks.normalizedTagName('Café'),
  decomposed: globalThis.__tagHooks.normalizedTagName('Cafe\u0301'),
  sharpSKey: globalThis.__tagHooks.tagNameKey('Straße'),
  foldedSsKey: globalThis.__tagHooks.tagNameKey('Strasse'),
  dottedIKey: globalThis.__tagHooks.tagNameKey('\u0130'),
  lowercaseDottedIKey: globalThis.__tagHooks.tagNameKey('i\u0307'),
  controlRejected: globalThis.__tagHooks.normalizedTagName('Ahorro\nneto'),
  zeroWidthSpaceRejected: globalThis.__tagHooks.normalizedTagName('Ahorro\u200Bneto'),
  zeroWidthNonJoinerRejected: globalThis.__tagHooks.normalizedTagName('Ahorro\u200Cneto'),
  softHyphenRejected: globalThis.__tagHooks.normalizedTagName('Ahorro\u00ADneto'),
  leftToRightMarkRejected: globalThis.__tagHooks.normalizedTagName('Ahorro\u200Eneto'),
  lineSeparatorRejected: globalThis.__tagHooks.normalizedTagName('Ahorro\u2028neto'),
  paragraphSeparatorRejected: globalThis.__tagHooks.normalizedTagName('Ahorro\u2029neto'),
  bidiOverrideRejected: globalThis.__tagHooks.normalizedTagName('Ahorro\u202Eneto'),
  bidiIsolateRejected: globalThis.__tagHooks.normalizedTagName('Ahorro\u2066neto'),
  wordJoinerRejected: globalThis.__tagHooks.normalizedTagName('Ahorro\u2060neto'),
  bomRejected: globalThis.__tagHooks.normalizedTagName('Ahorro\uFEFFneto'),
  zwjAccepted: globalThis.__tagHooks.normalizedTagName('Familia 👩‍👩‍👧'),
  valid: globalThis.__tagHooks.validTagAssignmentPayload(valid),
  unsorted: globalThis.__tagHooks.validTagAssignmentPayload(unsorted),
  extra: globalThis.__tagHooks.validTagAssignmentPayload(extra),
  pasteCount: pastedTags.length,
  pasteCharacters: pastedCsv.length,
  pasteBytes: new TextEncoder().encode(pastedCsv).length,
  pasteNamesValid: pastedTags.every((tag) => (
    globalThis.__tagHooks.normalizedTagName(tag.name) === tag.name
  )),
  maxTags: globalThis.__tagHooks.maxTags,
  maxCsvBytes: globalThis.__tagHooks.maxCsvBytes,
  synced,
  editor: editor.controls.tag_workspace_version.value,
  publish: publish.controls.tag_workspace_version.value,
  english: english.controls.tag_workspace_version.value,
  anotherPost: anotherPost.controls.tag_workspace_version.value,
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

        self::assertSame('Banca ética', $result['normalized']);
        self::assertSame('Café', $result['composed']);
        self::assertSame($result['composed'], $result['decomposed']);
        self::assertNotSame($result['sharpSKey'], $result['foldedSsKey']);
        self::assertNotSame(
            $result['dottedIKey'],
            $result['lowercaseDottedIKey']
        );
        self::assertNull($result['controlRejected']);
        self::assertNull($result['zeroWidthSpaceRejected']);
        self::assertNull($result['zeroWidthNonJoinerRejected']);
        self::assertNull($result['softHyphenRejected']);
        self::assertNull($result['leftToRightMarkRejected']);
        self::assertNull($result['lineSeparatorRejected']);
        self::assertNull($result['paragraphSeparatorRejected']);
        self::assertNull($result['bidiOverrideRejected']);
        self::assertNull($result['bidiIsolateRejected']);
        self::assertNull($result['wordJoinerRejected']);
        self::assertNull($result['bomRejected']);
        self::assertSame('Familia 👩‍👩‍👧', $result['zwjAccepted']);
        self::assertTrue($result['valid']);
        self::assertFalse($result['unsorted']);
        self::assertFalse($result['extra']);
        self::assertSame(30, $result['pasteCount']);
        self::assertGreaterThan(255, $result['pasteCharacters']);
        self::assertLessThanOrEqual(
            $result['maxCsvBytes'],
            $result['pasteBytes']
        );
        self::assertTrue($result['pasteNamesValid']);
        self::assertSame(30, $result['maxTags']);
        self::assertTrue($result['synced']);
        self::assertSame('0', $result['editor']);
        self::assertSame('0', $result['publish']);
        self::assertSame('9', $result['english']);
        self::assertSame('7', $result['anotherPost']);
    }

    public function testCategoryAndTagSavesReplayLatestStateSingleFlight(): void
    {
        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no estÃ¡ disponible.');
        }

        $root = dirname(__DIR__, 3);
        $process = new Process([
            'node',
            $root . '/tests/Blog/Tags/fixtures/'
                . 'blog-tag-single-flight-harness.mjs',
            $root . '/modules/blog/published/assets/blog-editor.js',
        ]);
        $process->mustRun();

        self::assertSame(
            "BLOG_TAG_SINGLE_FLIGHT_OK\n",
            $process->getOutput()
        );
    }
}
