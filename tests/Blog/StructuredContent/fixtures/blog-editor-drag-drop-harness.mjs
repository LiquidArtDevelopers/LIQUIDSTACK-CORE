import fs from 'node:fs';
import vm from 'node:vm';

const uuid = (number) => '60000000-0000-4000-8000-'
    + String(number).padStart(12, '0');

globalThis.document = {
    readyState: 'loading',
    addEventListener() {},
};
globalThis.window = {
    crypto: {
        randomUUID() {
            return uuid(9999);
        },
    },
    location: { href: 'http://localhost/admin/blog/editor' },
};

let source = fs.readFileSync(process.argv[2], 'utf8');
const marker = '}());';
const markerIndex = source.lastIndexOf(marker);
if (markerIndex < 0) {
    throw new Error('Unable to expose editor drag and drop hooks.');
}
source = source.slice(0, markerIndex)
    + 'globalThis.__blogEditorDragHooks = {'
    + ' validV2DraftDocument, v2DropPlan, v2MoveToTarget, v2Location'
    + ' };\n'
    + source.slice(markerIndex);
vm.runInThisContext(source, { filename: process.argv[2] });

const hooks = globalThis.__blogEditorDragHooks;
const richText = (value) => [{ type: 'text', text: value, marks: [] }];
const presentation = () => ({
    width: 'full',
    align: 'start',
    text_align: 'start',
    size: 'm',
    font_weight: 'default',
    text_color: 'default',
});
const heading = (number, level, label) => ({
    id: uuid(number),
    type: 'heading',
    level,
    content: richText(label),
    presentation: presentation(),
    preset: 'default',
});
const paragraph = (number, label) => ({
    id: uuid(number),
    type: 'paragraph',
    content: richText(label),
    presentation: presentation(),
});
const column = (number, children) => ({
    id: uuid(number),
    children,
});
const container = (number, type, columnNumber, children) => ({
    id: uuid(number),
    type,
    layout: {
        preset: '1',
        columns: [column(columnNumber, children)],
    },
});

function fixture() {
    const nestedA = container(11, 'div', 12, [paragraph(13, 'Nested A')]);
    const outerA = container(8, 'div', 9, [
        paragraph(10, 'Outer A'),
        nestedA,
    ]);
    const articleA = container(4, 'article', 5, [
        heading(6, 3, 'Article A'),
        paragraph(7, 'Article A copy'),
    ]);

    const nestedB = container(24, 'div', 25, [paragraph(26, 'Nested B')]);
    const outerB = container(21, 'div', 22, [
        paragraph(23, 'Outer B'),
        nestedB,
    ]);
    const articleB = container(17, 'article', 18, [
        heading(19, 3, 'Article B'),
        paragraph(20, 'Article B copy'),
    ]);

    return {
        schema: 'liquidstack.blog.document',
        version: 2,
        template: 'article-basic-01',
        blocks: [
            {
                id: uuid(1),
                type: 'section',
                children: [
                    heading(2, 2, 'Section A'),
                    paragraph(3, 'Direct A'),
                    articleA,
                    outerA,
                ],
            },
            {
                id: uuid(14),
                type: 'section',
                children: [
                    heading(15, 2, 'Section B'),
                    paragraph(16, 'Direct B'),
                    articleB,
                    outerB,
                ],
            },
        ],
    };
}

function canDrop(documentValue, node, owner, columnId, index) {
    return hooks.v2DropPlan(
        { documentValue },
        uuid(node),
        owner === 'root' ? 'root' : uuid(owner),
        columnId === null ? '' : uuid(columnId),
        index
    ) !== null;
}

const invalidDocument = fixture();
const invalidBefore = JSON.stringify(invalidDocument);
const invalid = {
    protectedH2: canDrop(invalidDocument, 2, 14, null, 1),
    articleInsideArticle: canDrop(invalidDocument, 4, 17, 18, 2),
    sectionInsideSection: canDrop(invalidDocument, 1, 14, null, 2),
    ownDescendant: canDrop(invalidDocument, 8, 11, 12, 1),
    fourthDivLevel: canDrop(invalidDocument, 8, 24, 25, 1),
    secondH3: canDrop(invalidDocument, 6, 17, 18, 2),
    samePosition: canDrop(invalidDocument, 3, 1, null, 2),
    unchanged: invalidBefore === JSON.stringify(invalidDocument),
};

const crossSection = fixture();
const paragraphMoved = hooks.v2MoveToTarget(
    { documentValue: crossSection },
    uuid(3),
    uuid(14),
    '',
    1
);

const crossColumn = fixture();
const crossColumnArticle = hooks.v2Location(crossColumn, uuid(4)).node;
crossColumnArticle.layout.preset = '2-50-50';
crossColumnArticle.layout.columns.push(column(27, []));
const paragraphMovedAcrossColumns = hooks.v2MoveToTarget(
    { documentValue: crossColumn },
    uuid(7),
    uuid(4),
    uuid(27),
    0
);

const nestedMove = fixture();
const nestedDivMoved = hooks.v2MoveToTarget(
    { documentValue: nestedMove },
    uuid(11),
    uuid(24),
    uuid(25),
    1
);

const articleMove = fixture();
const articleMoved = hooks.v2MoveToTarget(
    { documentValue: articleMove },
    uuid(4),
    uuid(14),
    '',
    4
);

const sectionMove = fixture();
const sectionMoved = hooks.v2MoveToTarget(
    { documentValue: sectionMove },
    uuid(1),
    'root',
    '',
    2
);

process.stdout.write(JSON.stringify({
    initialValid: hooks.validV2DraftDocument(fixture()),
    invalid,
    crossSection: {
        moved: paragraphMoved,
        valid: hooks.validV2DraftDocument(crossSection),
        sourceIds: crossSection.blocks[0].children.map((node) => node.id),
        targetIds: crossSection.blocks[1].children.map((node) => node.id),
    },
    crossColumn: {
        moved: paragraphMovedAcrossColumns,
        valid: hooks.validV2DraftDocument(crossColumn),
        sourceIds: crossColumnArticle.layout.columns[0].children.map(
            (node) => node.id
        ),
        targetIds: crossColumnArticle.layout.columns[1].children.map(
            (node) => node.id
        ),
    },
    nestedMove: {
        moved: nestedDivMoved,
        valid: hooks.validV2DraftDocument(nestedMove),
        targetIds: hooks.v2Location(nestedMove, uuid(24))
            .node.layout.columns[0].children.map((node) => node.id),
    },
    articleMove: {
        moved: articleMoved,
        valid: hooks.validV2DraftDocument(articleMove),
        targetIds: articleMove.blocks[1].children.map((node) => node.id),
    },
    sectionMove: {
        moved: sectionMoved,
        valid: hooks.validV2DraftDocument(sectionMove),
        rootIds: sectionMove.blocks.map((node) => node.id),
    },
}));
