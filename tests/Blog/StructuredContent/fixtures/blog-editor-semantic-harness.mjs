import fs from 'node:fs';
import vm from 'node:vm';

let source = fs.readFileSync(process.argv[2], 'utf8');
const marker = '}());';
const markerIndex = source.lastIndexOf(marker);
if (markerIndex < 0) {
    throw new Error('Unable to expose editor test hooks.');
}
source = source.slice(0, markerIndex)
    + 'globalThis.__blogEditorSemanticHooks = {'
    + ' validDocument, semanticRange, semanticMoveTarget, moveSemanticGroup,'
    + ' isExpectedEditorRedirect, isExpectedCategoryRedirect'
    + ' };\n'
    + source.slice(markerIndex);

globalThis.document = {
    readyState: 'loading',
    addEventListener() {},
};
globalThis.HTMLInputElement = class HTMLInputElement {
    constructor(value) {
        this.value = value;
    }
};
globalThis.window = { location: { href: 'http://localhost/admin/blog/editor' } };
vm.runInThisContext(source, { filename: process.argv[2] });

const hooks = globalThis.__blogEditorSemanticHooks;
const id = (number) => '00000000-0000-4000-8000-'
    + String(number).padStart(12, '0');
const text = (value) => [{ type: 'text', text: value, marks: [] }];
const heading = (level, number) => ({
    id: id(number),
    type: 'heading',
    level,
    content: text('H' + level + '-' + number),
});
const paragraph = (number) => ({
    id: id(number),
    type: 'paragraph',
    content: text('P-' + number),
});
const documentValue = (blocks) => ({
    schema: 'liquidstack.blog.document',
    version: 1,
    template: 'article-basic-01',
    blocks,
});

const blocks = [
    heading(2, 1),
    heading(3, 2),
    heading(4, 3),
    paragraph(4),
    heading(5, 5),
    heading(6, 6),
    paragraph(7),
    heading(4, 8),
    paragraph(9),
    heading(3, 10),
    paragraph(11),
    heading(2, 12),
];
const invalidJump = [heading(2, 20), heading(4, 21)];
const context = { documentValue: documentValue(structuredClone(blocks)) };
const moved = hooks.moveSemanticGroup(context, 7, -1);
const postId = id(90);
const form = {
    action: 'http://localhost/admin/blog/editor/save',
    elements: {
        namedItem(name) {
            return name === 'post'
                ? new HTMLInputElement(postId)
                : new HTMLInputElement('es');
        },
    },
};

process.stdout.write(JSON.stringify({
    valid: hooks.validDocument(documentValue(blocks)),
    invalidJump: hooks.validDocument(documentValue(invalidJump)),
    h4Range: hooks.semanticRange(blocks, 2),
    h3Range: hooks.semanticRange(blocks, 1),
    h2Range: hooks.semanticRange(blocks, 0),
    h4Previous: hooks.semanticMoveTarget(blocks, 7, -1),
    moved,
    movedValid: hooks.validDocument(context.documentValue),
    movedIds: context.documentValue.blocks.map((block) => block.id),
    expectedRedirect: hooks.isExpectedEditorRedirect(form, {
        redirected: true,
        ok: true,
        url: 'http://localhost/admin/blog/editor?post=' + postId + '&locale=es',
    }),
    loginRedirect: hooks.isExpectedEditorRedirect(form, {
        redirected: true,
        ok: true,
        url: 'http://localhost/admin/login',
    }),
    expectedCategoryRedirect: hooks.isExpectedCategoryRedirect({
        action: 'http://localhost/admin/blog/categories/assign',
    }, {
        redirected: true,
        ok: true,
        url: 'http://localhost/admin/blog/categories/updated',
    }),
    categoryLoginRedirect: hooks.isExpectedCategoryRedirect({
        action: 'http://localhost/admin/blog/categories/assign',
    }, {
        redirected: true,
        ok: true,
        url: 'http://localhost/admin/login',
    }),
}));
