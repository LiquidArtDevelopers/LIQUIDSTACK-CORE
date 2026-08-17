import fs from 'node:fs';
import vm from 'node:vm';

const uuid = (number) => '40000000-0000-4000-8000-'
    + String(number).padStart(12, '0');

let generatedUuid = 9000;
globalThis.document = {
    readyState: 'loading',
    addEventListener() {},
};
globalThis.HTMLInputElement = class HTMLInputElement {};
globalThis.window = {
    crypto: {
        randomUUID() {
            generatedUuid += 1;
            return uuid(generatedUuid);
        },
    },
    location: { href: 'http://localhost/admin/blog/editor' },
};

let source = fs.readFileSync(process.argv[2], 'utf8');
const marker = '}());';
const markerIndex = source.lastIndexOf(marker);
if (markerIndex < 0) {
    throw new Error('Unable to expose editor layout test hooks.');
}
source = source.slice(0, markerIndex)
    + 'globalThis.__blogEditorLayoutHooks = {'
    + ' validV2Document, validV2DraftDocument, v2SetPreset, v2Add,'
    + ' makeV2Section, v2ColumnPresetModels, v2ApplyPresetControl'
    + ' };\n'
    + source.slice(markerIndex);
vm.runInThisContext(source, { filename: process.argv[2] });

const hooks = globalThis.__blogEditorLayoutHooks;
const richText = (value) => [{ type: 'text', text: value, marks: [] }];
const presentation = () => ({
    width: 'full',
    align: 'start',
    text_align: 'start',
    size: 'm',
    font_weight: 'default',
    text_color: 'default',
});
const heading = (number) => ({
    id: uuid(number),
    type: 'heading',
    level: 2,
    content: richText('Layout contract'),
    presentation: presentation(),
});
const paragraph = (number, label) => ({
    id: uuid(number),
    type: 'paragraph',
    content: richText(label),
    presentation: presentation(),
});
const labels = (column) => column.children.map(
    (child) => child.content[0].text
);
const firstText = (module) => {
    const first = module.content[0];
    if (first && first.type === 'paragraph') {
        return first.content[0]?.text ?? '';
    }
    return first?.text ?? '';
};
const snapshot = (documentValue, container) => ({
    valid: hooks.validV2Document(documentValue),
    preset: container.layout.preset,
    columnIds: container.layout.columns.map((column) => column.id),
    children: container.layout.columns.map(labels),
    childIds: container.layout.columns.map(
        (column) => column.children.map((child) => child.id)
    ),
});

function exercise(type, base) {
    const firstColumn = {
        id: uuid(base + 4),
        children: [
            paragraph(base + 5, 'A'),
            paragraph(base + 6, 'B'),
        ],
    };
    const container = {
        id: uuid(base + 3),
        type,
        layout: {
            preset: '1',
            columns: [firstColumn],
        },
    };
    const documentValue = {
        schema: 'liquidstack.blog.document',
        version: 2,
        template: 'article-basic-01',
        blocks: [{
            id: uuid(base + 1),
            type: 'section',
            children: [heading(base + 2), container],
        }],
    };
    const context = { documentValue };
    const initialValid = hooks.validV2Document(documentValue);
    const originalColumnId = firstColumn.id;
    const growthMatrix = {};
    ['2-40-60', '2-60-40', '3', '4', '5'].forEach((preset) => {
        hooks.v2SetPreset(context, container, preset);
        growthMatrix[preset] = snapshot(documentValue, container);
        hooks.v2SetPreset(context, container, '1');
    });

    const changedToFive = hooks.v2SetPreset(context, container, '5');
    const growth = snapshot(documentValue, container);
    container.layout.columns[2].children.push(paragraph(base + 7, 'C'));
    container.layout.columns[3].children.push(paragraph(base + 8, 'D'));
    container.layout.columns[4].children.push(paragraph(base + 9, 'E'));
    const seededFive = snapshot(documentValue, container);

    const changedToThree = hooks.v2SetPreset(context, container, '3');
    const reducedToThree = snapshot(documentValue, container);
    const changedToTwo = hooks.v2SetPreset(context, container, '2-40-60');
    const reducedToTwo = snapshot(documentValue, container);
    const ratioState = JSON.stringify({
        columnIds: reducedToTwo.columnIds,
        children: reducedToTwo.children,
    });
    const changedRatio = hooks.v2SetPreset(context, container, '2-60-40');
    const ratioChanged = snapshot(documentValue, container);
    const ratioStable = ratioState === JSON.stringify({
        columnIds: ratioChanged.columnIds,
        children: ratioChanged.children,
    });
    const changedToOne = hooks.v2SetPreset(context, container, '1');
    const reducedToOne = snapshot(documentValue, container);

    return {
        initialValid,
        originalColumnId,
        growthMatrix,
        changedToFive,
        growth,
        seededFive,
        changedToThree,
        reducedToThree,
        changedToTwo,
        reducedToTwo,
        changedRatio,
        ratioChanged,
        ratioStable,
        changedToOne,
        reducedToOne,
    };
}

function exerciseDefaults() {
    const context = {
        documentValue: {
            schema: 'liquidstack.blog.document',
            version: 2,
            template: 'article-basic-01',
            blocks: [],
        },
        media: [],
        headingDefaults: {},
        selectedNodeId: null,
        inspectorMode: 'config',
    };
    const section = hooks.makeV2Section(context);
    context.documentValue.blocks.push(section);
    const trigger = (owner, column, index, type) => ({
        dataset: {
            blogV2Owner: owner,
            blogV2Column: column,
            blogV2Index: String(index),
            blogV2AddType: type,
        },
    });

    const paragraphAdded = hooks.v2Add(
        context,
        trigger(section.id, '', 1, 'paragraph')
    );
    const directParagraph = section.children[1];
    const articleAdded = hooks.v2Add(
        context,
        trigger(section.id, '', 2, 'article')
    );
    const directArticle = section.children[2];
    const nestedParagraphAdded = hooks.v2Add(
        context,
        trigger(
            directArticle.id,
            directArticle.layout.columns[0].id,
            0,
            'paragraph'
        )
    );
    const nestedParagraph = directArticle.layout.columns[0].children[0];
    const divisionAdded = hooks.v2Add(
        context,
        trigger(section.id, '', 3, 'div')
    );
    const directDivision = section.children[3];

    return {
        paragraphAdded,
        articleAdded,
        nestedParagraphAdded,
        divisionAdded,
        validDraft: hooks.validV2DraftDocument(context.documentValue),
        sectionHeading: {
            text: section.children[0].content[0].content[0].text,
            presentation: section.children[0].presentation,
        },
        directParagraph: {
            text: firstText(directParagraph),
            presentation: directParagraph.presentation,
        },
        directArticle: directArticle.presentation,
        nestedParagraph: {
            text: firstText(nestedParagraph),
            presentation: nestedParagraph.presentation,
        },
        directDivision: directDivision.presentation,
    };
}

function exerciseVisualPresetChange(type, base) {
    const firstColumn = {
        id: uuid(base + 4),
        children: [paragraph(base + 5, 'A')],
    };
    const container = {
        id: uuid(base + 3),
        type,
        layout: {
            preset: '1',
            columns: [firstColumn],
        },
    };
    const documentValue = {
        schema: 'liquidstack.blog.document',
        version: 2,
        template: 'article-basic-01',
        blocks: [{
            id: uuid(base + 1),
            type: 'section',
            children: [heading(base + 2), container],
        }],
    };
    const context = { documentValue };
    const models = hooks.v2ColumnPresetModels('2-40-60');
    const change = (value) => hooks.v2ApplyPresetControl(context, {
        type: 'radio',
        value,
        dataset: { blogV2Node: container.id },
    });
    const trace = [snapshot(documentValue, container)];

    const toTwo = change('2-40-60');
    trace.push(snapshot(documentValue, container));
    container.layout.columns[1].children.push(paragraph(base + 6, 'B'));
    const twoColumnIds = container.layout.columns.map((column) => column.id);

    const toFive = change('5');
    container.layout.columns[2].children.push(paragraph(base + 7, 'C'));
    container.layout.columns[3].children.push(paragraph(base + 8, 'D'));
    container.layout.columns[4].children.push(paragraph(base + 9, 'E'));
    const fiveColumnIds = container.layout.columns.map((column) => column.id);
    const fiveChildIds = container.layout.columns.flatMap(
        (column) => column.children.map((child) => child.id)
    );
    trace.push(snapshot(documentValue, container));

    const toOne = change('1');
    const finalState = snapshot(documentValue, container);
    trace.push(finalState);

    return {
        interaction: 'native-radio-change',
        models,
        selectedCount: models.filter((model) => model.selected).length,
        toTwo: Boolean(toTwo),
        toFive: Boolean(toFive),
        toOne: Boolean(toOne),
        trace,
        twoColumnIds,
        fiveColumnIds,
        fiveChildIds,
        finalState,
    };
}

process.stdout.write(JSON.stringify({
    article: exercise('article', 100),
    div: exercise('div', 200),
    defaults: exerciseDefaults(),
    visualArticle: exerciseVisualPresetChange('article', 300),
    visualDiv: exerciseVisualPresetChange('div', 400),
}));
