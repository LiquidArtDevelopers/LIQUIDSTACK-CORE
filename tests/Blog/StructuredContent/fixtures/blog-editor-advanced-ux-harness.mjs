import fs from 'node:fs';
import vm from 'node:vm';

const asset = process.argv[2];
let source = fs.readFileSync(asset, 'utf8');
const marker = '}());';
const markerIndex = source.lastIndexOf(marker);
if (markerIndex < 0) {
  throw new Error('Unable to expose advanced editor hooks.');
}
source = source.slice(0, markerIndex)
  + `globalThis.__advancedUxHooks = {
    RICH_PALETTE_OPTIONS,
    RICH_SOURCE_INDENT,
    readHeadingPolicy,
    richSourceIndentEdit,
    richSourceEnterEdit,
    richSourceAutoCloseEdit,
    richSourcePairEdit,
    richSourcePairDeleteEdit,
    richCodeEnterEdit,
    richCodeAltGraphInput,
    richCodeHasCommandModifier,
    richCodeHistory,
    richResetCodeHistory,
    richCodeHistoryDirection,
    richCodeHistoryStep,
    richCodeHistoryBeforeInput,
    richCodeHistoryCommitInput,
    richCodeHistoryCommitComposition,
    richCodeTouched,
    richApplyTextareaEdit,
    richFormatSourceRoot,
    richApplyMark,
    richSerializeHtml,
    richSerializeTextFlowHtml,
    richAdvancedParagraphBlock,
    richTextBlockEmpty,
    richAdvancedCssVisualSafe,
    validAdvancedHtml,
    richSourceUsesStandardParagraph,
    richSourceSupportsAdvancedVisualFlow,
    richParseAdvancedVisualFlowHtml,
    richParseInlineRoot,
    richParseTextFlowRoot,
    isTextFlowContent,
    richAdvancedVisualStructureLocked,
    richAdvancedVisualCanSplitSelection,
    richSelectionBreaksLeadingHeading,
    richInsertFlowParagraph,
    richInsertFlowLineBreak,
    richInsertPlainText,
    richResolveCaretExitAfterInput,
    richDiscardEmptyCaretExit,
    richDiscardCaretExitOutsideSelection,
    richPlaceFlowLineBreakCaret,
    richSerializeAdvancedVisualFlowHtml,
    richAdvancedPreviewPolicy,
    richAdvancedPreviewDocument,
    richAdvancedVisualScopedCss,
    richAdvancedVisualProjectAttributes,
    richAdvancedVisualStripProjectedAttributes,
    richCommitAdvancedEditors,
    richAdvancedVisualSource,
    richResetAdvancedTouchState,
    richResetAdvancedVisualBaseline,
    richReconcileAdvancedVisualTouch,
    richSeedAdvancedVisualText,
    richParseEditorHtml,
    richSerializeEditorHtml,
    richClosePalettes,
    richTogglePalette,
    richClosePalettesOnFocusLeave,
    richClosePalettesOnPointerDown,
    richSourceGutterModel,
    richSourceMeasureWrappedLines,
    v2SectionHeadingModule,
    richDraftKeepsLeadingHeading,
    useCustomTextPolicy(value) { CUSTOM_TEXT_POLICY = value; }
  };\n`
  + source.slice(markerIndex);

globalThis.Node = {
  ELEMENT_NODE: 1,
  TEXT_NODE: 3,
  DOCUMENT_FRAGMENT_NODE: 11,
};
globalThis.document = {
  readyState: 'loading',
  addEventListener() {},
  createElement(tag) {
    return element(tag);
  },
  createTextNode(value) {
    return text(value);
  },
};
globalThis.window = {};
vm.runInThisContext(source, { filename: asset });

const hooks = globalThis.__advancedUxHooks;

function text(value) {
  return { nodeType: Node.TEXT_NODE, nodeValue: value };
}

function element(tag, attributes = {}, childNodes = []) {
  const values = { ...attributes };
  function detach(child) {
    if (!child || !child.parentNode) return;
    const siblings = child.parentNode.childNodes;
    const index = siblings.indexOf(child);
    if (index >= 0) siblings.splice(index, 1);
    child.parentNode.refresh?.();
    child.parentNode = null;
    child.parentElement = null;
  }
  function expanded(children) {
    return children.flatMap((child) => {
      if (child && child.nodeType === Node.DOCUMENT_FRAGMENT_NODE) {
        const fragmentChildren = [...child.childNodes];
        child.childNodes = [];
        return fragmentChildren;
      }
      return [child];
    });
  }
  function matches(candidate, selector) {
    return selector.split(',').some((part) => {
      const match = /^([a-z0-9]+)(?:\[([^=]+)="([^"]*)"\])?$/u.exec(
        part.trim().toLowerCase(),
      );
      if (!match || candidate.nodeName.toLowerCase() !== match[1]) return false;
      return !match[2] || candidate.getAttribute(match[2]) === match[3];
    });
  }
  function inlineStyle(value) {
    const declarations = new Map();
    String(value || '').split(';').forEach((declaration) => {
      const separator = declaration.indexOf(':');
      if (separator < 0) return;
      const property = declaration.slice(0, separator).trim().toLowerCase();
      let propertyValue = declaration.slice(separator + 1).trim();
      let priority = '';
      if (/\s*!important\s*$/iu.test(propertyValue)) {
        priority = 'important';
        propertyValue = propertyValue.replace(/\s*!important\s*$/iu, '').trim();
      }
      if (property !== '') declarations.set(property, { propertyValue, priority });
    });
    const properties = [...declarations.keys()];
    return {
      length: properties.length,
      item(index) { return properties[index] || ''; },
      getPropertyValue(property) {
        return declarations.get(property)?.propertyValue || '';
      },
      getPropertyPriority(property) {
        return declarations.get(property)?.priority || '';
      },
    };
  }
  const node = {
    nodeType: Node.ELEMENT_NODE,
    nodeName: tag.toUpperCase(),
    tagName: tag.toUpperCase(),
    attributes: [],
    childNodes: [...childNodes],
    children: [],
    style: inlineStyle(values.style),
    parentNode: null,
    parentElement: null,
    getAttribute(name) {
      return Object.prototype.hasOwnProperty.call(values, name)
        ? values[name]
        : null;
    },
    hasAttribute(name) {
      return Object.prototype.hasOwnProperty.call(values, name);
    },
    setAttribute(name, value) {
      values[name] = String(value);
      refresh();
    },
    removeAttribute(name) {
      delete values[name];
      refresh();
    },
    append(...children) {
      const nextChildren = expanded(children);
      nextChildren.forEach(detach);
      node.childNodes.push(...nextChildren);
      refresh();
    },
    replaceChildren(...children) {
      node.childNodes.forEach((child) => { child.parentNode = null; });
      const nextChildren = expanded(children);
      nextChildren.forEach(detach);
      node.childNodes = nextChildren;
      refresh();
    },
    removeChild(child) {
      const index = node.childNodes.indexOf(child);
      if (index < 0) throw new Error('Child not found.');
      node.childNodes.splice(index, 1);
      child.parentNode = null;
      child.parentElement = null;
      refresh();
      return child;
    },
    contains(candidate) {
      let current = candidate;
      while (current) {
        if (current === node) return true;
        current = current.parentNode;
      }
      return false;
    },
    closest(selector) {
      let current = node;
      while (current && current.nodeType === Node.ELEMENT_NODE) {
        if (matches(current, selector)) return current;
        current = current.parentElement;
      }
      return null;
    },
    querySelectorAll(selector) {
      const results = [];
      function visit(candidate) {
        if (candidate.nodeType !== Node.ELEMENT_NODE) return;
        if (candidate !== node && matches(candidate, selector)) {
          results.push(candidate);
        }
        candidate.children.forEach(visit);
      }
      visit(node);
      return results;
    },
    querySelector(selector) {
      return node.querySelectorAll(selector)[0] || null;
    },
    after(sibling) {
      if (!node.parentNode) return;
      detach(sibling);
      const index = node.parentNode.childNodes.indexOf(node);
      node.parentNode.childNodes.splice(index + 1, 0, sibling);
      node.parentNode.refresh();
    },
    before(sibling) {
      if (!node.parentNode) return;
      detach(sibling);
      const index = node.parentNode.childNodes.indexOf(node);
      node.parentNode.childNodes.splice(index, 0, sibling);
      node.parentNode.refresh();
    },
    replaceWith(replacement) {
      if (!node.parentNode) return;
      detach(replacement);
      const parent = node.parentNode;
      const index = parent.childNodes.indexOf(node);
      parent.childNodes.splice(index, 1, replacement);
      node.parentNode = null;
      parent.refresh();
    },
    remove() {
      detach(node);
    },
  };
  node.refresh = refresh;
  Object.defineProperty(node, 'firstChild', {
    get() { return node.childNodes[0] || null; },
  });
  Object.defineProperty(node, 'lastChild', {
    get() { return node.childNodes[node.childNodes.length - 1] || null; },
  });
  Object.defineProperty(node, 'lastElementChild', {
    get() { return node.children[node.children.length - 1] || null; },
  });
  Object.defineProperty(node, 'textContent', {
    get() {
      return node.childNodes.map((child) => child.nodeType === Node.TEXT_NODE
        ? (child.nodeValue || '')
        : (child.textContent || '')).join('');
    },
  });
  Object.defineProperty(node, 'innerHTML', {
    get() {
      const escape = (value, attribute = false) => String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', attribute ? '&quot;' : '"');
      const serialize = (child) => {
        if (child.nodeType === Node.TEXT_NODE) {
          return escape(child.nodeValue || '');
        }
        const name = child.nodeName.toLowerCase();
        const attrs = child.attributes.map((attribute) => (
          ` ${attribute.name}="${escape(attribute.value, true)}"`
        )).join('');
        if (['br', 'img'].includes(name)) {
          return `<${name}${attrs}>`;
        }
        return `<${name}${attrs}>${child.innerHTML}</${name}>`;
      };
      return node.childNodes.map(serialize).join('');
    },
  });
  function refresh() {
    node.attributes = Object.keys(values).map((name) => ({
      name,
      value: values[name],
    }));
    node.children = node.childNodes.filter(
      (child) => child.nodeType === Node.ELEMENT_NODE,
    );
    node.style = inlineStyle(values.style);
    node.childNodes.forEach((child) => {
      child.parentNode = node;
      child.parentElement = node;
    });
  }
  refresh();
  return node;
}

function form(policy) {
  return {
    getAttribute(name) {
      return name === 'data-blog-heading-policy' ? policy : null;
    },
  };
}

const rawAdvancedHtml = '<p>uno <strong>dos</strong></p><p>tres</p>';
const classAdvancedHtml = '<p>uno</p><p class="miClase">nuevo párrafo</p>';
const rootBreakAdvancedHtml = '<br><p class="miClase">nuevo párrafo</p>';
const classAdvancedMarkedHtml = '<p>uno</p>\n'
  + '<p class="miClase"><span data-content-format-text-color="color04">'
  + '<strong>nuevo párrafo</strong></span></p>';
const attributedAdvancedHtml = '<p id="lead" class="miClase" '
  + 'data-content-theme="warm">abcd</p>';
const attributedSplitAdvancedHtml = '<p id="lead" class="miClase" '
  + 'data-content-theme="warm">ab</p>\n'
  + '<p class="miClase" data-content-theme="warm">cd</p>';
const advancedSectionHeadingHtml = '<h3 class="sectionTitle">Título</h3>'
  + '<p>Texto</p>';
const complexAdvancedHtml = '<div class="lead">uno</div>';
const helloAdvancedHtml = '<p>Hello</p>';
const abAdvancedHtml = '<p>A</p><p>B</p>';
const listItemId = '81000000-0000-4000-8000-000000000001';
const orderedItemId = '81000000-0000-4000-8000-000000000002';
const advancedListCss = '& .miLista { color: red; }';
const unorderedAdvancedHtml = '<ul class="miLista" aria-label="Prueba" '
  + 'data-content-list-marker="square"><li data-content-list-item-id="'
  + listItemId + '" class="item" lang="es">'
  + '<span data-content-format-text-color="color04"><strong>Uno</strong>'
  + '</span></li></ul>';
const unorderedAdvancedSplitHtml = '<ul class="miLista" aria-label="Prueba" '
  + 'data-content-list-marker="square">\n'
  + '    <li data-content-list-item-id="' + listItemId
  + '" class="item" lang="es"><span '
  + 'data-content-format-text-color="color04"><strong>Uno</strong></span></li>\n'
  + '    <li class="item" lang="es"></li>\n</ul>';
const unorderedAdvancedExitHtml = '<ul class="miLista" aria-label="Prueba" '
  + 'data-content-list-marker="square">\n'
  + '    <li data-content-list-item-id="' + listItemId
  + '" class="item" lang="es"><span '
  + 'data-content-format-text-color="color04"><strong>Uno</strong></span></li>\n'
  + '</ul>\n<br>';
const orderedAdvancedHtml = '<ol class="steps" start="3">'
  + '<li data-content-list-item-id="' + orderedItemId
  + '"><em>Primero</em></li></ol>';
const orderedAdvancedSplitHtml = '<ol class="steps" start="3">\n'
  + '    <li data-content-list-item-id="' + orderedItemId
  + '"><em>Primero</em></li>\n    <li></li>\n</ol>';
const orderedAdvancedExitHtml = '<ol class="steps" start="3">\n'
  + '    <li data-content-list-item-id="' + orderedItemId
  + '"><em>Primero</em></li>\n</ol>\n<br>';
const singleEmptyAdvancedHtml = '<ul class="single"><li '
  + 'data-content-list-item-id="' + listItemId + '"></li></ul>';
const singleBreakAdvancedHtml = '<ol class="single"><li><br></li></ol>';
globalThis.DOMParser = class {
  parseFromString(value) {
    let bodyChildren = [];
    if (value === rawAdvancedHtml) {
      bodyChildren = [
        element('p', {}, [
          text('uno '),
          element('strong', {}, [text('dos')]),
        ]),
        element('p', {}, [text('tres')]),
      ];
    } else if (value === classAdvancedHtml) {
      bodyChildren = [
        element('p', {}, [text('uno')]),
        element('p', { class: 'miClase' }, [text('nuevo párrafo')]),
      ];
    } else if (value === rootBreakAdvancedHtml) {
      bodyChildren = [
        element('br'),
        element('p', { class: 'miClase' }, [text('nuevo párrafo')]),
      ];
    } else if (value === '<ul><li></li></ul>') {
      bodyChildren = [element('ul', {}, [element('li')])];
    } else if (value === '<p></p><p></p>') {
      bodyChildren = [element('p'), element('p')];
    } else if (value === classAdvancedMarkedHtml) {
      bodyChildren = [
        element('p', {}, [text('uno')]),
        element('p', { class: 'miClase' }, [
          element('span', {
            'data-content-format-text-color': 'color04',
          }, [
            element('strong', {}, [text('nuevo párrafo')]),
          ]),
        ]),
      ];
    } else if (value === attributedAdvancedHtml) {
      bodyChildren = [element('p', {
        id: 'lead',
        class: 'miClase',
        'data-content-theme': 'warm',
      }, [text('abcd')])];
    } else if (value === attributedSplitAdvancedHtml) {
      bodyChildren = [
        element('p', {
          id: 'lead',
          class: 'miClase',
          'data-content-theme': 'warm',
        }, [text('ab')]),
        element('p', {
          class: 'miClase',
          'data-content-theme': 'warm',
        }, [text('cd')]),
      ];
    } else if (value === advancedSectionHeadingHtml) {
      bodyChildren = [
        element('h3', { class: 'sectionTitle' }, [text('Título')]),
        element('p', {}, [text('Texto')]),
      ];
    } else if (value === complexAdvancedHtml) {
      bodyChildren = [element('div', { class: 'lead' }, [text('uno')])];
    } else if (value === helloAdvancedHtml) {
      bodyChildren = [element('p', {}, [text('Hello')])];
    } else if (value === abAdvancedHtml) {
      bodyChildren = [
        element('p', {}, [text('A')]),
        element('p', {}, [text('B')]),
      ];
    } else if (
      value === unorderedAdvancedHtml
      || value === unorderedAdvancedSplitHtml
      || value === unorderedAdvancedExitHtml
    ) {
      const items = [element('li', {
        'data-content-list-item-id': listItemId,
        class: 'item',
        lang: 'es',
      }, [element('span', {
        'data-content-format-text-color': 'color04',
      }, [element('strong', {}, [text('Uno')])])])];
      if (value === unorderedAdvancedSplitHtml) {
        items.push(element('li', { class: 'item', lang: 'es' }));
      }
      bodyChildren = [element('ul', {
        class: 'miLista',
        'aria-label': 'Prueba',
        'data-content-list-marker': 'square',
      }, items)];
      if (value === unorderedAdvancedExitHtml) {
        bodyChildren.push(element('br'));
      }
    } else if (
      value === orderedAdvancedHtml
      || value === orderedAdvancedSplitHtml
      || value === orderedAdvancedExitHtml
    ) {
      const items = [element('li', {
        'data-content-list-item-id': orderedItemId,
      }, [element('em', {}, [text('Primero')])])];
      if (value === orderedAdvancedSplitHtml) {
        items.push(element('li'));
      }
      bodyChildren = [element('ol', { class: 'steps', start: '3' }, items)];
      if (value === orderedAdvancedExitHtml) {
        bodyChildren.push(element('br'));
      }
    } else if (value === singleEmptyAdvancedHtml) {
      bodyChildren = [element('ul', { class: 'single' }, [
        element('li', {
          'data-content-list-item-id': listItemId,
        }),
      ])];
    } else if (value === singleBreakAdvancedHtml) {
      bodyChildren = [element('ol', { class: 'single' }, [
        element('li', {}, [element('br')]),
      ])];
    }
    return {
      doctype: null,
      head: { childNodes: [] },
      documentElement: { attributes: [] },
      body: element('body', {}, bodyChildren),
      createElement(tag) { return element(tag); },
    };
  }
};

const openBrace = hooks.richSourcePairEdit('p', 1, 1, '{');
const pairedEnter = hooks.richCodeEnterEdit(
  openBrace.value,
  openBrace.start,
  openBrace.end,
);
const overtypedBrace = hooks.richSourcePairEdit('p{}', 2, 2, '}');
const deletedBracePair = hooks.richSourcePairDeleteEdit('p{}', 2, 2);
const wrappedSelection = hooks.richSourcePairEdit('alpha', 0, 5, '(');
const pairedQuote = hooks.richSourcePairEdit('', 0, 0, '"');
const overtypedQuote = hooks.richSourcePairEdit('""', 1, 1, '"');
const htmlClosed = hooks.richSourceAutoCloseEdit(
  '<p',
  2,
  2,
  true,
  true,
  [],
  true,
);
const htmlEntered = hooks.richSourceEnterEdit(
  '<p></p>',
  3,
  3,
  true,
  true,
  [],
  true,
);
const quotedAttributeSource = '<p title="a > b"';
const quotedAttributeHtmlClosed = hooks.richSourceAutoCloseEdit(
  quotedAttributeSource,
  quotedAttributeSource.length,
  quotedAttributeSource.length,
  true,
  true,
  [],
  true,
);
const quotedAttributeHtml = '<p title="a > b"></p>';
const quotedAttributeHtmlEntered = hooks.richSourceEnterEdit(
  quotedAttributeHtml,
  quotedAttributeSource.length + 1,
  quotedAttributeSource.length + 1,
  true,
  true,
  [],
  true,
);
const openQuotedAttribute = '<p title="a > b';
const openQuotedAttributeHtmlClosed = hooks.richSourceAutoCloseEdit(
  openQuotedAttribute,
  openQuotedAttribute.length,
  openQuotedAttribute.length,
  true,
  true,
  [],
  true,
);
const nestedQuotedAttributeSource = '<p title="a > b"><strong';
const nestedQuotedAttributeHtmlClosed = hooks.richSourceAutoCloseEdit(
  nestedQuotedAttributeSource,
  nestedQuotedAttributeSource.length,
  nestedQuotedAttributeSource.length,
  true,
  true,
  [],
  true,
);
const tabbed = hooks.richSourceIndentEdit('one\ntwo', 0, 7, false);
const untabbed = hooks.richSourceIndentEdit(
  tabbed.value,
  tabbed.start,
  tabbed.end,
  true,
);

const altGraphEvent = {
  key: '{',
  ctrlKey: true,
  altKey: true,
  metaKey: false,
  getModifierState(name) { return name === 'AltGraph'; },
};
const altGraphFallbackEvent = {
  key: '[',
  ctrlKey: true,
  altKey: true,
  metaKey: false,
};
const commandEvent = {
  key: '{',
  ctrlKey: true,
  altKey: false,
  metaKey: false,
  getModifierState() { return false; },
};

const textarea = {
  value: 'p',
  setRangeTextCalls: [],
  setRangeText(replacement, start, end, mode) {
    this.setRangeTextCalls.push({ replacement, start, end, mode });
    this.value = this.value.slice(0, start) + replacement + this.value.slice(end);
  },
  setSelectionRange(start, end) {
    this.selectionStart = start;
    this.selectionEnd = end;
  },
};
hooks.richApplyTextareaEdit(textarea, openBrace);

function codeControl(value, start = value.length, end = start) {
  return {
    value,
    selectionStart: start,
    selectionEnd: end,
    setRangeText(replacement, rangeStart, rangeEnd) {
      this.value = this.value.slice(0, rangeStart)
        + replacement
        + this.value.slice(rangeEnd);
    },
    setSelectionRange(nextStart, nextEnd) {
      this.selectionStart = nextStart;
      this.selectionEnd = nextEnd;
    },
  };
}

function historyRoundTrip(initial, edit) {
  const control = codeControl(initial);
  const history = hooks.richCodeHistory();
  hooks.richApplyTextareaEdit(control, edit, history);
  const after = {
    value: control.value,
    start: control.selectionStart,
    end: control.selectionEnd,
    touched: hooks.richCodeTouched(control, initial),
  };
  const undoHandled = hooks.richCodeHistoryStep(control, history, 'undo');
  const undo = {
    value: control.value,
    start: control.selectionStart,
    end: control.selectionEnd,
    touched: hooks.richCodeTouched(control, initial),
  };
  const redoHandled = hooks.richCodeHistoryStep(control, history, 'redo');
  const redo = {
    value: control.value,
    start: control.selectionStart,
    end: control.selectionEnd,
    touched: hooks.richCodeTouched(control, initial),
  };
  return { after, undoHandled, undo, redoHandled, redo };
}

function nativeInput(control, history, after, inputType, composing = false) {
  const beforeEvent = {
    inputType,
    isComposing: composing,
    prevented: false,
    preventDefault() { this.prevented = true; },
  };
  hooks.richCodeHistoryBeforeInput(control, history, beforeEvent);
  control.value = after.value;
  control.setSelectionRange(after.start, after.end);
  const recorded = hooks.richCodeHistoryCommitInput(control, history, {
    inputType,
    isComposing: composing,
  });
  return { recorded, prevented: beforeEvent.prevented };
}

const historyScenarios = {
  htmlPair: historyRoundTrip('p', openBrace),
  cssPair: historyRoundTrip('p', openBrace),
  pairedEnter: historyRoundTrip('p{}', hooks.richCodeEnterEdit('p{}', 2, 2)),
  tab: historyRoundTrip('one\ntwo', hooks.richSourceIndentEdit(
    'one\ntwo', 0, 7, false,
  )),
  shiftTab: historyRoundTrip('    one\n    two', hooks.richSourceIndentEdit(
    '    one\n    two', 0, 15, true,
  )),
  htmlAutoClose: historyRoundTrip('<p', htmlClosed),
};
const shortcutDirections = {
  undo: hooks.richCodeHistoryDirection({
    key: 'z', ctrlKey: true, metaKey: false, altKey: false, shiftKey: false,
  }),
  redoShift: hooks.richCodeHistoryDirection({
    key: 'z', ctrlKey: true, metaKey: false, altKey: false, shiftKey: true,
  }),
  redoY: hooks.richCodeHistoryDirection({
    key: 'y', ctrlKey: true, metaKey: false, altKey: false, shiftKey: false,
  }),
  native: hooks.richCodeHistoryDirection({
    key: 'z', ctrlKey: false, metaKey: false, altKey: false, shiftKey: false,
  }),
  ime: hooks.richCodeHistoryDirection({
    key: 'z', ctrlKey: true, metaKey: false, altKey: false, shiftKey: false,
    isComposing: true,
  }),
};
const unifiedControl = codeControl('p');
const unifiedHistory = hooks.richCodeHistory();
hooks.richResetCodeHistory(unifiedHistory, unifiedControl);
hooks.richApplyTextareaEdit(unifiedControl, openBrace, unifiedHistory);
nativeInput(unifiedControl, unifiedHistory, {
  value: 'p{x}', start: 3, end: 3,
}, 'insertText');
nativeInput(unifiedControl, unifiedHistory, {
  value: 'p{}', start: 2, end: 2,
}, 'deleteContentBackward');
const unifiedUndo = [];
for (let index = 0; index < 3; index += 1) {
  unifiedUndo.push({
    handled: hooks.richCodeHistoryStep(unifiedControl, unifiedHistory, 'undo'),
    value: unifiedControl.value,
    start: unifiedControl.selectionStart,
  });
}
const unifiedRedo = [];
for (let index = 0; index < 3; index += 1) {
  unifiedRedo.push({
    handled: hooks.richCodeHistoryStep(unifiedControl, unifiedHistory, 'redo'),
    value: unifiedControl.value,
    start: unifiedControl.selectionStart,
  });
}

const contiguousTypingControl = codeControl('');
const contiguousTypingHistory = hooks.richCodeHistory();
hooks.richResetCodeHistory(contiguousTypingHistory, contiguousTypingControl);
['a', 'ab', 'abc'].forEach((value) => {
  nativeInput(contiguousTypingControl, contiguousTypingHistory, {
    value,
    start: value.length,
    end: value.length,
  }, 'insertText');
});
const contiguousTypingUnits = contiguousTypingHistory.undo.length;
const contiguousTypingUndo = hooks.richCodeHistoryStep(
  contiguousTypingControl,
  contiguousTypingHistory,
  'undo',
);
const contiguousTypingAfterUndo = contiguousTypingControl.value;
const contiguousTypingRedo = hooks.richCodeHistoryStep(
  contiguousTypingControl,
  contiguousTypingHistory,
  'redo',
);
const contiguousTypingAfterRedo = contiguousTypingControl.value;

const overtypeHistoryControl = codeControl('p{}', 2, 2);
const overtypeHistory = hooks.richCodeHistory();
hooks.richResetCodeHistory(overtypeHistory, overtypeHistoryControl);
hooks.richApplyTextareaEdit(
  overtypeHistoryControl,
  overtypedBrace,
  overtypeHistory,
);
const overtypeHistoryResult = {
  units: overtypeHistory.undo.length,
  value: overtypeHistoryControl.value,
  start: overtypeHistoryControl.selectionStart,
};

const branchControl = codeControl('');
const branchHistory = hooks.richCodeHistory();
hooks.richResetCodeHistory(branchHistory, branchControl);
nativeInput(branchControl, branchHistory, {
  value: 'a', start: 1, end: 1,
}, 'insertText');
nativeInput(branchControl, branchHistory, {
  value: 'ab', start: 2, end: 2,
}, 'insertText');
hooks.richCodeHistoryStep(branchControl, branchHistory, 'undo');
nativeInput(branchControl, branchHistory, {
  value: 'c', start: 1, end: 1,
}, 'insertText');

const nativeKindsControl = codeControl('');
const nativeKindsHistory = hooks.richCodeHistory();
hooks.richResetCodeHistory(nativeKindsHistory, nativeKindsControl);
const typed = nativeInput(nativeKindsControl, nativeKindsHistory, {
  value: 'A', start: 1, end: 1,
}, 'insertText');
const pasted = nativeInput(nativeKindsControl, nativeKindsHistory, {
  value: 'ABC', start: 3, end: 3,
}, 'insertFromPaste');

const compositionControl = codeControl('');
const compositionHistory = hooks.richCodeHistory();
hooks.richResetCodeHistory(compositionHistory, compositionControl);
compositionHistory.composition = {
  value: '', start: 0, end: 0,
};
nativeInput(compositionControl, compositionHistory, {
  value: 'に', start: 1, end: 1,
}, 'insertCompositionText', true);
nativeInput(compositionControl, compositionHistory, {
  value: '日本', start: 2, end: 2,
}, 'insertCompositionText', true);
const compositionRecorded = hooks.richCodeHistoryCommitComposition(
  compositionControl,
  compositionHistory,
);
const compositionUndo = hooks.richCodeHistoryStep(
  compositionControl,
  compositionHistory,
  'undo',
);

const beforeInputUndoControl = codeControl('p');
const beforeInputUndoHistory = hooks.richCodeHistory();
hooks.richResetCodeHistory(beforeInputUndoHistory, beforeInputUndoControl);
hooks.richApplyTextareaEdit(
  beforeInputUndoControl,
  openBrace,
  beforeInputUndoHistory,
);
const historyUndoEvent = {
  inputType: 'historyUndo',
  prevented: false,
  preventDefault() { this.prevented = true; },
};
const beforeInputUndoDirection = hooks.richCodeHistoryBeforeInput(
  beforeInputUndoControl,
  beforeInputUndoHistory,
  historyUndoEvent,
);
const historyRedoEvent = {
  inputType: 'historyRedo',
  prevented: false,
  preventDefault() { this.prevented = true; },
};
const beforeInputRedoDirection = hooks.richCodeHistoryBeforeInput(
  beforeInputUndoControl,
  beforeInputUndoHistory,
  historyRedoEvent,
);
const boundedControl = codeControl('');
const boundedHistory = hooks.richCodeHistory();
for (let index = 0; index < 105; index += 1) {
  const next = boundedControl.value + String(index % 10);
  hooks.richApplyTextareaEdit(boundedControl, {
    value: next,
    start: next.length,
    end: next.length,
  }, boundedHistory);
}

const formattedRoot = element('body', {}, [
  element('div', { class: 'lead' }, [
    element('p', {}, [
      text('Alpha '),
      element('span', { id: 'beta' }, [text('beta')]),
    ]),
    element('blockquote', {}, [
      text('Gamma '),
      element('span', {}, [text('delta')]),
    ]),
  ]),
]);
const formatted = hooks.richFormatSourceRoot(formattedRoot);
const formattedAgain = hooks.richFormatSourceRoot(formattedRoot);

const mixedRoot = element('body', {}, [
  element('div', {}, [
    text('Alpha '),
    element('span', {}, [text('beta')]),
    text(' '),
    element('blockquote', {}, [
      text('Gamma '),
      element('span', {}, [text('delta')]),
    ]),
    text(' tail'),
  ]),
]);
const mixedFormatted = hooks.richFormatSourceRoot(mixedRoot);
const mixedEditedRoot = element('body', {}, [
  element('div', {}, [
    text('Alpha '),
    element('span', {}, [text('beta')]),
    text(' '),
    element('blockquote', {}, [
      text('Gamma '),
      element('span', {}, [text('delta')]),
    ]),
    text(' tail!'),
  ]),
]);
const mixedAfterMinimalEdit = hooks.richFormatSourceRoot(mixedEditedRoot);

const baseContent = [{
  type: 'text',
  text: 'independiente',
  marks: [],
}];
const colorThenBold = hooks.richApplyMark(
  hooks.richApplyMark(baseContent, 0, 13, 'text-color02', 'color'),
  0,
  13,
  'strong',
  null,
);
const boldThenColor = hooks.richApplyMark(
  hooks.richApplyMark(baseContent, 0, 13, 'strong', null),
  0,
  13,
  'text-color02',
  'color',
);
const stableFlow = [
  { type: 'paragraph', content: [{ type: 'text', text: 'Antes', marks: [] }] },
  { type: 'paragraph', content: colorThenBold },
  { type: 'paragraph', content: [{ type: 'text', text: 'Después', marks: [] }] },
];

const policyJson = JSON.stringify({
  allowed_levels: [2, 3, 4, 5, 6],
  defaults: { section: 2, article: 3, div: 3 },
});
const policy = hooks.readHeadingPolicy(form(policyJson));
const fallbackPolicy = hooks.readHeadingPolicy(form(JSON.stringify({
  allowed_levels: [1, 2],
  defaults: { section: 1, article: 2, div: 2 },
})));

const advancedFlow = [
  {
    type: 'paragraph',
    content: [
      { type: 'text', text: 'uno ', marks: [] },
      { type: 'text', text: 'dos', marks: ['strong'] },
    ],
  },
  {
    type: 'paragraph',
    content: [{ type: 'text', text: 'tres', marks: [] }],
  },
];
const advancedState = {
  advancedMode: true,
  advancedVisualEditable: true,
  visualTouched: false,
  sourceTouched: true,
  cssTouched: true,
  advancedHtmlDraft: rawAdvancedHtml,
  flowDraft: advancedFlow,
};
const advancedNoopSource = hooks.richAdvancedVisualSource(advancedState);
advancedState.visualTouched = true;
const advancedVisualSource = hooks.richAdvancedVisualSource(advancedState);
hooks.richResetAdvancedTouchState(advancedState);
const paragraphState = {
  textFlowMode: true,
  legacyListMode: false,
  headingMode: false,
  cssDraft: 'p { color: red; }',
};
const visualCssPolicy = {
  html: {
    tags: [
      'a', 'aside', 'blockquote', 'br', 'code', 'div', 'em',
      'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'mark',
      'ol', 'p', 'small', 'span', 'strong', 'sub', 'sup', 'u', 'ul',
    ],
    global_attributes: [
      'class', 'id', 'title', 'lang', 'dir', 'role', 'aria-label',
      'aria-hidden', 'aria-labelledby', 'aria-describedby',
    ],
    data_attribute_prefix: 'data-content-',
    roles: [
      'group', 'list', 'listitem', 'none', 'note', 'presentation', 'region',
    ],
    special_attributes: {
      a: ['href', 'target', 'rel'],
      blockquote: ['cite'],
      ol: ['start', 'reversed', 'type'],
      li: ['value'],
    },
    max_nodes: 1024,
    max_depth: 32,
  },
  css: {
    properties: ['color', 'display', 'white-space'],
    root_properties: ['color', 'display', 'white-space'],
    tags: [
      'a', 'aside', 'blockquote', 'br', 'code', 'div', 'em',
      'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'mark',
      'ol', 'p', 'small', 'span', 'strong', 'sub', 'sup', 'u', 'ul',
    ],
    at_rules: ['media', 'supports'],
    simple_pseudos: [],
    pseudo_elements: [],
    attribute_selectors: ['aria-hidden', 'dir', 'lang', 'data-content-*'],
    reserved_class_prefixes: [
      'blogdocument', 'blog-', 'liquidstack', 'ls-', 'webadmin',
    ],
    max_css_bytes: 30000,
    max_rendered_css_bytes: 600000,
    max_depth: 6,
    max_rules: 128,
    max_declarations: 256,
    max_selector_bytes: 512,
    max_value_bytes: 2048,
    max_numeric_tokens_per_value: 64,
    max_root_numeric_total: 4096,
  },
};
hooks.useCustomTextPolicy(visualCssPolicy);
const safeVisualCss = 'p { color: red; }';
const classVisualCss = '& .miClase { color: red; }';
const unsafeVisualCss = 'p { display: inline; white-space: pre; }';
const previewNonce = 'abcdefghijklmnop';
const previewSecurity = {
  styleNonce: previewNonce,
  contentSecurityPolicy: hooks.richAdvancedPreviewPolicy(previewNonce),
};
const unifiedPreviewDocument = hooks.richAdvancedPreviewDocument(
  '50000000-0000-4000-8000-000000000099',
  classAdvancedHtml,
  classVisualCss,
  previewSecurity,
  {
    color00: '#ffffff', color01: '#272727', color02: '#24658e',
    color03: '#092f64', color04: '#d6a757', color05: '#697886',
  },
);
function committedAdvancedState(css) {
  const shownSource = '<p>uno <strong>dos</strong></p>\n<p>tres</p>';
  const state = {
    wasAdvanced: true,
    advancedMode: true,
    advancedVisualEditable: true,
    advancedHtmlDraft: rawAdvancedHtml,
    cssDraft: css,
    source: codeControl(shownSource),
    cssSource: codeControl(css),
    sourceBaseline: shownSource,
    cssBaseline: css,
    sourceTouched: hooks.richCodeTouched(codeControl(shownSource), shownSource),
    cssTouched: false,
    visualTouched: false,
    sourceHistory: hooks.richCodeHistory(),
    cssHistory: hooks.richCodeHistory(),
    textFlowMode: true,
    legacyListMode: false,
    headingMode: false,
    flowDraft: advancedFlow,
    context: { technicalLimits: { custom_text_policy: visualCssPolicy } },
  };
  hooks.richCommitAdvancedEditors(state);
  return {
    html: state.advancedHtmlDraft,
    css: state.cssDraft,
    advanced: state.advancedMode,
    visualEditable: state.advancedVisualEditable,
    sourceTouched: state.sourceTouched,
    sourceBaseline: state.sourceBaseline,
  };
}
const safeCommit = committedAdvancedState(safeVisualCss);
const unsafeCommit = committedAdvancedState(unsafeVisualCss);
const classAdvancedFlow = hooks.richParseAdvancedVisualFlowHtml(
  classAdvancedHtml,
);
const classAdvancedState = {
  ...paragraphState,
  advancedMode: true,
  advancedVisualEditable: true,
  advancedVisualStructureLocked: true,
  advancedHtmlDraft: classAdvancedHtml,
  cssDraft: classVisualCss,
  visualTouched: true,
  flowDraft: JSON.parse(JSON.stringify(classAdvancedFlow)),
};
classAdvancedState.flowDraft[1].content[0].marks = [
  'strong',
  'text-color04',
];
const classAdvancedVisualSource = hooks.richAdvancedVisualSource(
  classAdvancedState,
);
const classAdvancedRoundTrip = hooks.richParseAdvancedVisualFlowHtml(
  classAdvancedVisualSource,
);
const advancedVisualStyleState = {
  advancedVisualScope: 'rich-visual-7',
  cssDraft: classVisualCss,
  context: { technicalLimits: { custom_text_policy: visualCssPolicy } },
};
let unsafeVisualStyleRejected = false;
try {
  hooks.richAdvancedVisualScopedCss({
    ...advancedVisualStyleState,
    cssDraft: unsafeVisualCss,
  });
} catch (error) {
  unsafeVisualStyleRejected = true;
}
let invalidVisualScopeRejected = false;
try {
  hooks.richAdvancedVisualScopedCss({
    ...advancedVisualStyleState,
    advancedVisualScope: 'webadmin',
  });
} catch (error) {
  invalidVisualScopeRejected = true;
}
const advancedVisualStyle = {
  stylesheet: hooks.richAdvancedVisualScopedCss(advancedVisualStyleState),
  unsafeRejected: unsafeVisualStyleRejected,
  invalidScopeRejected: invalidVisualScopeRejected,
};
const projectedVisual = element('div', {}, [
  element('p', {}, [text('uno')]),
  element('p', {}, [text('nuevo pÃ¡rrafo')]),
]);
const projectedState = {
  advancedMode: true,
  advancedVisualEditable: true,
  advancedHtmlDraft: classAdvancedHtml,
  visual: projectedVisual,
};
hooks.richAdvancedVisualProjectAttributes(projectedState);
const projectedNode = projectedVisual.children[1];
const trustedClone = element('p', { class: 'miClase' });
hooks.richAdvancedVisualStripProjectedAttributes(
  projectedState,
  projectedNode,
  trustedClone,
);
let changedAttributeRejected = false;
projectedNode.setAttribute('class', 'otraClase');
try {
  hooks.richAdvancedVisualStripProjectedAttributes(
    projectedState,
    projectedNode,
    element('p', { class: 'otraClase' }),
  );
} catch (error) {
  changedAttributeRejected = true;
}
projectedNode.setAttribute('class', 'miClase');
projectedNode.setAttribute('data-untrusted', 'x');
let unknownAttributeRejected = false;
try {
  hooks.richAdvancedVisualStripProjectedAttributes(
    projectedState,
    projectedNode,
    element('p', { class: 'miClase', 'data-untrusted': 'x' }),
  );
} catch (error) {
  unknownAttributeRejected = true;
}
let emptyParagraphBreaksAccepted = true;
try {
  hooks.richAdvancedVisualProjectAttributes({
    advancedMode: true,
    advancedVisualEditable: true,
    advancedHtmlDraft: '<p></p><p></p>',
    visual: element('div', {}, [element('br'), element('br')]),
  });
} catch (error) {
  emptyParagraphBreaksAccepted = false;
}
advancedVisualStyle.projectedClass = projectedNode.getAttribute('class');
advancedVisualStyle.trustedAttributeStripped = !trustedClone.hasAttribute('class');
advancedVisualStyle.changedAttributeRejected = changedAttributeRejected;
advancedVisualStyle.unknownAttributeRejected = unknownAttributeRejected;
advancedVisualStyle.emptyParagraphBreaksAccepted = emptyParagraphBreaksAccepted;
const classAdvancedNoopSource = hooks.richAdvancedVisualSource({
  ...classAdvancedState,
  visualTouched: false,
});
const attributedFlow = hooks.richParseAdvancedVisualFlowHtml(
  attributedAdvancedHtml,
);
const attributedSplitFlow = [
  {
    type: 'paragraph',
    content: [{ type: 'text', text: 'ab', marks: [] }],
  },
  {
    type: 'paragraph',
    content: [{ type: 'text', text: 'cd', marks: [] }],
  },
];
const attributedSplitSource = hooks.richSerializeAdvancedVisualFlowHtml(
  attributedAdvancedHtml,
  attributedSplitFlow,
);
const splitProjectedVisual = element('div', {}, [
  element('p', {}, [text('ab')]),
  element('p', {}, [text('cd')]),
]);
hooks.richAdvancedVisualProjectAttributes({
  advancedMode: true,
  advancedVisualEditable: true,
  advancedHtmlDraft: attributedSplitSource,
  visual: splitProjectedVisual,
});
advancedVisualStyle.splitProjectedClasses = splitProjectedVisual.children.map(
  (node) => node.getAttribute('class'),
);
const attributedBreakFlow = JSON.parse(JSON.stringify(attributedFlow));
attributedBreakFlow[0].content = [
  { type: 'text', text: 'ab', marks: [] },
  { type: 'break' },
  { type: 'text', text: 'cd', marks: [] },
];
const attributedBreakSource = hooks.richSerializeAdvancedVisualFlowHtml(
  attributedAdvancedHtml,
  attributedBreakFlow,
);
const attributedCalloutFlow = JSON.parse(JSON.stringify(attributedFlow));
attributedCalloutFlow[0].type = 'callout';
const attributedCalloutSource = hooks.richSerializeAdvancedVisualFlowHtml(
  attributedAdvancedHtml,
  attributedCalloutFlow,
);

function advancedListEnterScenario(source, ordered) {
  const originalFlow = hooks.richParseAdvancedVisualFlowHtml(source);
  const splitFlow = JSON.parse(JSON.stringify(originalFlow));
  splitFlow[0].items.push({ content: [] });
  const splitSource = hooks.richSerializeAdvancedVisualFlowHtml(
    source,
    splitFlow,
  );
  const exitFlow = JSON.parse(JSON.stringify(splitFlow));
  exitFlow[0].items.pop();
  exitFlow.push({ type: 'paragraph', content: [] });
  const exitSource = hooks.richSerializeAdvancedVisualFlowHtml(
    splitSource,
    exitFlow,
  );
  const discardedSource = hooks.richSerializeAdvancedVisualFlowHtml(
    exitSource,
    [exitFlow[0]],
  );
  return {
    ordered: originalFlow[0].ordered,
    expectedOrdered: ordered,
    splitSource,
    exitSource,
    discardedSource,
    originalMarks: originalFlow[0].items[0].content[0].marks,
    css: advancedListCss,
  };
}

const advancedListEnter = {
  unordered: advancedListEnterScenario(unorderedAdvancedHtml, false),
  ordered: advancedListEnterScenario(orderedAdvancedHtml, true),
  singleEmptyExit: hooks.richSerializeAdvancedVisualFlowHtml(
    singleEmptyAdvancedHtml,
    [{ type: 'paragraph', content: [] }],
  ),
  singleBreakExit: hooks.richSerializeAdvancedVisualFlowHtml(
    singleBreakAdvancedHtml,
    [{ type: 'paragraph', content: [] }],
  ),
};

function convertedAdvancedState() {
  const source = codeControl(helloAdvancedHtml);
  const cssSource = codeControl('');
  const state = {
    block: { type: 'paragraph' },
    wasAdvanced: true,
    advancedMode: true,
    advancedVisualEditable: true,
    advancedHtmlDraft: helloAdvancedHtml,
    cssDraft: safeVisualCss,
    source,
    cssSource,
    sourceBaseline: helloAdvancedHtml,
    cssBaseline: safeVisualCss,
    sourceTouched: false,
    cssTouched: true,
    visualTouched: false,
    sourceHistory: hooks.richCodeHistory(),
    cssHistory: hooks.richCodeHistory(),
    textFlowMode: true,
    legacyListMode: false,
    headingMode: false,
    flowDraft: [{
      type: 'paragraph',
      content: [{ type: 'text', text: 'Hello', marks: [] }],
    }],
    context: { technicalLimits: { custom_text_policy: visualCssPolicy } },
  };
  hooks.richCommitAdvancedEditors(state);
  const afterCss = {
    advanced: state.advancedMode,
    wasAdvanced: state.wasAdvanced,
    serialized: hooks.richSerializeEditorHtml(state),
  };
  state.source.value = hooks.richSerializeEditorHtml(state);
  state.sourceBaseline = state.source.value;
  state.sourceTouched = false;
  hooks.richParseEditorHtml(state, state.source.value);
  return {
    afterCss,
    afterHtml: hooks.richSerializeEditorHtml(state),
    advanced: state.advancedMode,
    wasAdvanced: state.wasAdvanced,
    flow: state.flowDraft,
  };
}
const convertedViaHtml = convertedAdvancedState();
const convertedViaVisual = convertedAdvancedState();

const visualNetState = {
  advancedMode: true,
  advancedVisualEditable: true,
  advancedHtmlDraft: abAdvancedHtml,
  flowDraft: [
    { type: 'paragraph', content: [{ type: 'text', text: 'A', marks: [] }] },
    { type: 'paragraph', content: [{ type: 'text', text: 'B', marks: [] }] },
  ],
  visualTouched: false,
};
hooks.richResetAdvancedVisualBaseline(visualNetState);
const visualOriginalFlow = JSON.parse(JSON.stringify(visualNetState.flowDraft));
visualNetState.flowDraft[0].content[0].text = 'A!';
hooks.richReconcileAdvancedVisualTouch(visualNetState);
const visualDirty = visualNetState.visualTouched;
visualNetState.advancedHtmlDraft = hooks.richAdvancedVisualSource(visualNetState);
visualNetState.flowDraft = visualOriginalFlow;
hooks.richReconcileAdvancedVisualTouch(visualNetState);
const visualNet = {
  dirty: visualDirty,
  reverted: visualNetState.visualTouched,
  html: visualNetState.advancedHtmlDraft,
};

const cssOnlyVisualState = {
  advancedMode: true,
  advancedVisualEditable: true,
  advancedHtmlDraft: '',
  cssDraft: safeVisualCss,
  textFlowMode: true,
  flowDraft: [{ type: 'paragraph', content: [] }],
  visualTouched: false,
};
hooks.richResetAdvancedVisualBaseline(cssOnlyVisualState);
const cssOnlySeeded = hooks.richSeedAdvancedVisualText(
  cssOnlyVisualState,
  'A',
);
hooks.richReconcileAdvancedVisualTouch(cssOnlyVisualState);
const cssOnlyVisual = {
  seeded: cssOnlySeeded,
  touched: cssOnlyVisualState.visualTouched,
  html: hooks.richAdvancedVisualSource(cssOnlyVisualState),
  css: cssOnlyVisualState.cssDraft,
};
const existingEmptyStructures = [
  {
    name: 'list',
    html: '<ul><li></li></ul>',
    flow: [{
      type: 'list',
      ordered: false,
      items: [{ content: [] }],
    }],
  },
  {
    name: 'paragraphs',
    html: '<p></p><p></p>',
    flow: [
      { type: 'paragraph', content: [] },
      { type: 'paragraph', content: [] },
    ],
  },
].map((fixture) => {
  const state = {
    advancedMode: true,
    advancedVisualEditable: true,
    advancedHtmlDraft: fixture.html,
    cssDraft: safeVisualCss,
    textFlowMode: true,
    flowDraft: hooks.richParseAdvancedVisualFlowHtml(fixture.html),
  };
  const seeded = hooks.richSeedAdvancedVisualText(state, 'A');
  return {
    name: fixture.name,
    seeded,
    raw: state.advancedHtmlDraft,
    serialized: hooks.richSerializeTextFlowHtml(state.flowDraft),
    flow: state.flowDraft,
  };
});
const rootBreakFlow = hooks.richParseAdvancedVisualFlowHtml(
  rootBreakAdvancedHtml,
);
const rootBreakContract = {
  advancedDeleted: hooks.richSerializeAdvancedVisualFlowHtml(
    rootBreakAdvancedHtml,
    rootBreakFlow.slice(1),
  ),
  protectsLeadingHeading: hooks.richSelectionBreaksLeadingHeading({
    requiresLeadingHeading: true,
    flowDraft: [
      { type: 'break' },
      { type: 'heading', level: 2, content: [] },
    ],
  }, [1], 'paragraph'),
  inlineInitialBreakIsFlow: hooks.isTextFlowContent([
    { type: 'break' },
    { type: 'text', text: 'inline', marks: [] },
  ]),
  allBreaksAreFlow: hooks.isTextFlowContent([
    { type: 'break' },
    { type: 'break' },
  ]),
};

function paletteHarnessState() {
  function trigger(scope) {
    return {
      expanded: 'true',
      disabled: false,
      focused: 0,
      scope,
      dataset: { blogRichPalette: scope },
      setAttribute(name, value) {
        if (name === 'aria-expanded') this.expanded = value;
      },
      focus() { this.focused += 1; },
    };
  }
  function palette(scope, open) {
    const paletteTrigger = trigger(scope);
    return {
      root: {
        contains(target) { return target && target.scope === scope; },
      },
      menu: { hidden: !open },
      trigger: paletteTrigger,
    };
  }
  return {
    palettes: {
      color: palette('color', true),
      background: palette('background', false),
    },
    rgbaControls: {
      color: { root: { hidden: false } },
      background: { root: { hidden: true } },
    },
  };
}
const paletteInsideState = paletteHarnessState();
const paletteInsideClosed = hooks.richClosePalettesOnFocusLeave(
  paletteInsideState,
  { scope: 'color' },
);
const paletteState = paletteHarnessState();
const paletteOutsideClosed = hooks.richClosePalettesOnFocusLeave(
  paletteState,
  { scope: 'toolbar-other' },
);
const paletteClose = {
  insideClosed: paletteInsideClosed,
  insideOpen: !paletteInsideState.palettes.color.menu.hidden,
  outsideClosed: paletteOutsideClosed,
  menuHidden: paletteState.palettes.color.menu.hidden,
  expanded: paletteState.palettes.color.trigger.expanded,
  customHidden: paletteState.rgbaControls.color.root.hidden,
};
const pointerPaletteState = paletteHarnessState();
const pointerInsideTarget = {
  scope: 'color',
  closest() { return {}; },
};
const pointerInsideClosed = hooks.richClosePalettesOnPointerDown(
  pointerPaletteState,
  pointerInsideTarget,
);
const pointerHeaderState = paletteHarnessState();
const pointerHeaderClosed = hooks.richClosePalettesOnPointerDown(
  pointerHeaderState,
  { scope: 'header', closest() { return null; } },
);
const pointerHelpState = paletteHarnessState();
const pointerHelpClosed = hooks.richClosePalettesOnPointerDown(
  pointerHelpState,
  { scope: 'help', closest() { return null; } },
);
const pointerFocusableState = paletteHarnessState();
const pointerOutsideClosed = hooks.richClosePalettesOnPointerDown(
  pointerFocusableState,
  { scope: 'toolbar-other', closest() { return {}; } },
);
const palettePointer = {
  insideClosed: pointerInsideClosed,
  insideOpen: !pointerPaletteState.palettes.color.menu.hidden,
  headerClosed: pointerHeaderClosed,
  headerTriggerFocus: pointerHeaderState.palettes.color.trigger.focused,
  helpClosed: pointerHelpClosed,
  helpTriggerFocus: pointerHelpState.palettes.color.trigger.focused,
  outsideClosed: pointerOutsideClosed,
  outsideTriggerFocus: pointerFocusableState.palettes.color.trigger.focused,
  menuHidden: pointerFocusableState.palettes.color.menu.hidden,
  expanded: pointerFocusableState.palettes.color.trigger.expanded,
};
const paletteTriggerState = paletteHarnessState();
paletteTriggerState.mode = 'visual';
paletteTriggerState.selection = { start: 0, end: 1 };
paletteTriggerState.active = true;
paletteTriggerState.dialog = { open: true };
paletteTriggerState.documentRevision = 1;
paletteTriggerState.selectionRevision = 1;
paletteTriggerState.context = { readOnly: false };
hooks.richTogglePalette(
  paletteTriggerState,
  paletteTriggerState.palettes.color.trigger,
);
const paletteTriggerClose = {
  hidden: paletteTriggerState.palettes.color.menu.hidden,
  expanded: paletteTriggerState.palettes.color.trigger.expanded,
  triggerFocusCalls: paletteTriggerState.palettes.color.trigger.focused,
};

function fragment() {
  const value = {
    nodeType: Node.DOCUMENT_FRAGMENT_NODE,
    childNodes: [],
    append(...children) {
      value.childNodes.push(...children);
    },
  };
  Object.defineProperty(value, 'lastChild', {
    get() { return value.childNodes[value.childNodes.length - 1] || null; },
  });
  return value;
}

class InteractionRange {
  constructor(preserveEmptyTextTail = false) {
    this.startContainer = null;
    this.startOffset = 0;
    this.endContainer = null;
    this.endOffset = 0;
    this.preserveEmptyTextTail = preserveEmptyTextTail;
  }

  get commonAncestorContainer() {
    return this.startContainer;
  }

  setStart(node, offset) {
    this.startContainer = node;
    this.startOffset = offset;
  }

  setEnd(node, offset) {
    this.endContainer = node;
    this.endOffset = offset;
  }

  setStartAfter(node) {
    const parent = node.parentNode;
    this.setStart(parent, parent.childNodes.indexOf(node) + 1);
  }

  selectNodeContents(node) {
    this.setStart(node, 0);
    this.setEnd(node, node.childNodes.length);
  }

  collapse(toStart) {
    if (toStart) {
      this.setEnd(this.startContainer, this.startOffset);
    } else {
      this.setStart(this.endContainer, this.endOffset);
    }
  }

  deleteContents() {}

  extractContents() {
    const extracted = fragment();
    if (this.startContainer.nodeType === Node.TEXT_NODE) {
      const trailing = (this.startContainer.nodeValue || '').slice(
        this.startOffset,
      );
      this.startContainer.nodeValue = (this.startContainer.nodeValue || '')
        .slice(0, this.startOffset);
      if (trailing !== '' || this.preserveEmptyTextTail) {
        extracted.append(text(trailing));
      }
      return extracted;
    }
    const moved = this.startContainer.childNodes.splice(
      this.startOffset,
      this.endOffset - this.startOffset,
    );
    extracted.append(...moved);
    this.startContainer.refresh?.();
    return extracted;
  }

  insertNode(inserted) {
    const children = inserted.nodeType === Node.DOCUMENT_FRAGMENT_NODE
      ? [...inserted.childNodes]
      : [inserted];
    if (inserted.nodeType === Node.DOCUMENT_FRAGMENT_NODE) {
      inserted.childNodes = [];
    }
    if (this.startContainer.nodeType === Node.ELEMENT_NODE) {
      this.startContainer.childNodes.splice(this.startOffset, 0, ...children);
      this.startContainer.refresh();
      return;
    }
    const parent = this.startContainer.parentNode;
    const index = parent.childNodes.indexOf(this.startContainer);
    const before = (this.startContainer.nodeValue || '').slice(
      0,
      this.startOffset,
    );
    const after = (this.startContainer.nodeValue || '').slice(
      this.startOffset,
    );
    const replacement = [];
    if (before !== '') replacement.push(text(before));
    replacement.push(...children);
    if (after !== '' || this.preserveEmptyTextTail) {
      replacement.push(text(after));
    }
    parent.childNodes.splice(index, 1, ...replacement);
    parent.refresh();
  }
}

function visualListExitInteraction(typeText) {
  globalThis.Element = Object;
  globalThis.HTMLElement = Object;
  document.createElement = (tag) => element(tag);
  document.createTextNode = (value) => text(value);
  document.createDocumentFragment = () => fragment();
  document.createRange = () => new InteractionRange();

  const initialText = text('Uno');
  const item = element('li', {}, [initialText]);
  const list = element('ul', {
    'data-content-list-marker': 'disc',
    style: 'list-style-type: disc;',
  }, [item]);
  const visual = element('div', {}, [list]);
  const selection = {
    rangeCount: 1,
    range: new InteractionRange(),
    getRangeAt() { return this.range; },
    removeAllRanges() { this.rangeCount = 0; },
    addRange(range) {
      this.range = range;
      this.rangeCount = 1;
    },
  };
  selection.range.setStart(initialText, initialText.nodeValue.length);
  selection.range.setEnd(initialText, initialText.nodeValue.length);
  window.getSelection = () => selection;
  const state = { visual, caretExitParagraph: null };
  const firstEnter = hooks.richInsertFlowParagraph(state);
  const secondEnter = hooks.richInsertFlowParagraph(state);
  const paragraph = visual.children[1];
  const filler = paragraph && paragraph.firstChild;
  const flowWithFiller = hooks.richParseTextFlowRoot(visual, false);
  let strictFillerRejected = false;
  try {
    hooks.richParseInlineRoot(paragraph, true, true, true);
  } catch (error) {
    strictFillerRejected = true;
  }
  const anchoredAfterExit = selection.range.startContainer === paragraph
    && selection.range.startOffset === 0;

  if (typeText) {
    hooks.richInsertPlainText(state, 'X');
    hooks.richResolveCaretExitAfterInput(state);
  } else {
    hooks.richDiscardEmptyCaretExit(state);
  }
  const finalFlow = hooks.richParseTextFlowRoot(visual, false);
  let strictStyleRejected = false;
  try {
    hooks.richParseTextFlowRoot(visual, true);
  } catch (error) {
    strictStyleRejected = true;
  }
  const finalParagraph = visual.children[1] || null;
  return {
    firstEnter,
    secondEnter,
    anchoredAfterExit,
    filler: filler ? {
      tag: filler.nodeName,
      internal: filler.getAttribute('data-blog-rich-caret-filler'),
    } : null,
    fillerIgnored: flowWithFiller.length === 1
      && flowWithFiller[0].type === 'list',
    strictFillerRejected,
    visualSyncAccepted: true,
    strictStyleRejected,
    lastListItemText: visual.children[0].lastElementChild.textContent,
    paragraphText: finalParagraph ? finalParagraph.textContent : null,
    pending: state.caretExitParagraph !== null,
    serialized: hooks.richSerializeTextFlowHtml(finalFlow),
  };
}
const visualListExit = {
  typed: visualListExitInteraction(true),
  abandoned: visualListExitInteraction(false),
};

function visualFlowInteraction(tag, value) {
  globalThis.Element = Object;
  globalThis.HTMLElement = Object;
  document.createElement = (name) => element(name);
  document.createTextNode = (textValue) => text(textValue);
  document.createDocumentFragment = () => fragment();
  document.createRange = () => new InteractionRange(true);

  const initialText = text(value);
  const block = element(tag, {}, [initialText]);
  const visual = element('div', {}, [block]);
  const selection = {
    rangeCount: 1,
    range: new InteractionRange(true),
    getRangeAt() { return this.range; },
    removeAllRanges() { this.rangeCount = 0; },
    addRange(range) {
      this.range = range;
      this.rangeCount = 1;
    },
  };
  selection.range.setStart(initialText, initialText.nodeValue.length);
  selection.range.setEnd(initialText, initialText.nodeValue.length);
  window.getSelection = () => selection;
  return {
    initialText,
    block,
    visual,
    selection,
    state: { visual, caretExitParagraph: null, allowBreak: true },
  };
}

function visualLeadingEnter(typeText) {
  const interaction = visualFlowInteraction('p', 'Texto existente');
  interaction.selection.range.setStart(interaction.initialText, 0);
  interaction.selection.range.setEnd(interaction.initialText, 0);
  const firstEnter = hooks.richInsertFlowParagraph(interaction.state);
  const pending = interaction.visual.children[0];
  const original = interaction.visual.children[1];
  const filler = pending && pending.firstChild;
  const anchored = interaction.selection.range.startContainer
    === interaction.visual
    && interaction.visual.childNodes[interaction.selection.range.startOffset]
      === pending;
  const secondEnter = hooks.richInsertFlowParagraph(interaction.state);
  const thirdEnter = hooks.richInsertFlowParagraph(interaction.state);
  const samePending = interaction.state.caretExitParagraph === pending;
  const blockCountAfterRepeat = interaction.visual.children.length;
  const semanticBreakCount = interaction.visual.children.filter(
    (child) => child.nodeName === 'BR',
  ).length;
  const anchoredAfterRepeat = interaction.selection.range.startContainer
    === interaction.visual
    && interaction.visual.childNodes[interaction.selection.range.startOffset]
      ?.nodeName === 'BR';
  const pendingSource = hooks.richSerializeTextFlowHtml(
    hooks.richParseTextFlowRoot(interaction.visual, false),
  );

  if (typeText) {
    hooks.richInsertPlainText(interaction.state, 'Título nuevo');
    hooks.richResolveCaretExitAfterInput(interaction.state);
  } else {
    hooks.richDiscardEmptyCaretExit(interaction.state);
  }
  const finalFlow = hooks.richParseTextFlowRoot(interaction.visual, false);
  return {
    firstEnter,
    secondEnter,
    thirdEnter,
    anchored,
    anchoredAfterRepeat,
    samePending,
    originalPreserved: original === interaction.visual.children[
      typeText ? 1 : 0
    ],
    blockCountAfterRepeat,
    semanticBreakCount,
    filler: filler ? {
      tag: filler.nodeName,
      internal: filler.getAttribute('data-blog-rich-caret-filler'),
    } : null,
    pendingSource,
    pending: interaction.state.caretExitParagraph !== null,
    serialized: hooks.richSerializeTextFlowHtml(finalFlow),
  };
}

function visualHeadingEnterTyped() {
  const interaction = visualFlowInteraction('h3', 'Encabezado');
  const textBoundary = interaction.selection.range.startContainer
    === interaction.initialText
    && interaction.selection.range.startOffset
      === interaction.initialText.nodeValue.length;
  const firstEnter = hooks.richInsertFlowParagraph(interaction.state);
  const pending = interaction.visual.children[1];
  const filler = pending && pending.firstChild;
  const pendingBeforeType = pending?.nodeName === 'BR';
  const pendingSource = hooks.richSerializeTextFlowHtml(
    hooks.richParseTextFlowRoot(interaction.visual, false),
  );
  let strictFillerRejected = false;
  try {
    hooks.richParseTextFlowRoot(interaction.visual, true);
  } catch (error) {
    strictFillerRejected = true;
  }
  const anchored = interaction.selection.range.startContainer
    === interaction.visual
    && interaction.visual.childNodes[interaction.selection.range.startOffset]
      === pending;
  const secondEnter = hooks.richInsertFlowParagraph(interaction.state);
  const pendingCount = interaction.visual.children.filter(
    (child) => child.nodeName === 'P',
  ).length;
  hooks.richInsertPlainText(interaction.state, 'Siguiente');
  hooks.richResolveCaretExitAfterInput(interaction.state);
  const finalFlow = hooks.richParseTextFlowRoot(interaction.visual, false);
  return {
    firstEnter,
    secondEnter,
    textBoundary,
    anchored,
    pendingCount,
    pendingBeforeType,
    filler: filler ? {
      tag: filler.nodeName,
      internal: filler.getAttribute('data-blog-rich-caret-filler'),
    } : null,
    emptyTextNodes: (pending?.childNodes || []).filter(
      (child) => child.nodeType === Node.TEXT_NODE && child.nodeValue === '',
    ).length,
    pendingSource,
    strictFillerRejected,
    pending: interaction.state.caretExitParagraph !== null,
    fillerCount: interaction.visual.querySelectorAll(
      'br[data-blog-rich-caret-filler="true"]',
    ).length,
    serialized: hooks.richSerializeTextFlowHtml(finalFlow),
  };
}

function visualHeadingEnterAbandoned() {
  const interaction = visualFlowInteraction('h3', 'Encabezado');
  const textBoundary = interaction.selection.range.startContainer
    === interaction.initialText
    && interaction.selection.range.startOffset
      === interaction.initialText.nodeValue.length;
  const firstEnter = hooks.richInsertFlowParagraph(interaction.state);
  const pending = interaction.state.caretExitParagraph;
  const filler = pending && pending.firstChild;
  const secondEnter = hooks.richInsertFlowParagraph(interaction.state);
  const discarded = hooks.richDiscardEmptyCaretExit(interaction.state);
  return {
    firstEnter,
    secondEnter,
    textBoundary,
    pendingBeforeDiscard: pending !== null,
    filler: filler ? {
      tag: filler.nodeName,
      internal: filler.getAttribute('data-blog-rich-caret-filler'),
    } : null,
    discarded,
    pending: interaction.state.caretExitParagraph !== null,
    paragraphCount: interaction.visual.children.filter(
      (child) => child.nodeName === 'P',
    ).length,
    serialized: hooks.richSerializeTextFlowHtml(
      hooks.richParseTextFlowRoot(interaction.visual, false),
    ),
  };
}

function visualHeadingEnterMovedSelection() {
  const interaction = visualFlowInteraction('h3', 'Encabezado');
  hooks.richInsertFlowParagraph(interaction.state);
  const previousPending = interaction.state.caretExitParagraph;
  interaction.selection.range.setStart(
    interaction.initialText,
    interaction.initialText.nodeValue.length,
  );
  interaction.selection.range.setEnd(
    interaction.initialText,
    interaction.initialText.nodeValue.length,
  );
  const discardedOnMove = hooks.richDiscardCaretExitOutsideSelection(
    interaction.state,
  );
  const movedEnter = hooks.richInsertFlowParagraph(interaction.state);
  const currentPending = interaction.state.caretExitParagraph;
  return {
    movedEnter,
    discardedOnMove,
    previousRemoved: previousPending !== null
      && previousPending.parentNode === null,
    replacedPending: currentPending !== previousPending,
    paragraphCount: interaction.visual.children.filter(
      (child) => child.nodeName === 'P',
    ).length,
  };
}

function visualHeadingCtrlEnter() {
  const interaction = visualFlowInteraction('h3', 'Encabezado');
  const headingEnter = hooks.richInsertFlowParagraph(interaction.state);
  hooks.richInsertPlainText(interaction.state, 'Primera linea');
  hooks.richResolveCaretExitAfterInput(interaction.state);
  const paragraph = interaction.visual.children[1];
  const firstLine = paragraph.firstChild;
  interaction.selection.range.setStart(
    firstLine,
    firstLine.nodeValue.length,
  );
  interaction.selection.range.setEnd(
    firstLine,
    firstLine.nodeValue.length,
  );
  const textBoundary = interaction.selection.range.startContainer === firstLine
    && interaction.selection.range.startOffset === firstLine.nodeValue.length;
  const inserted = hooks.richInsertFlowLineBreak(interaction.state);
  const breaksBeforeType = interaction.visual.querySelectorAll('br');
  const filler = interaction.visual.querySelector(
    'br[data-blog-rich-caret-filler="true"]',
  );
  const fillerParent = filler ? filler.parentNode : null;
  const fillerIndex = fillerParent
    ? fillerParent.childNodes.indexOf(filler)
    : -1;
  const caretAfterBreak = fillerParent !== null
    && interaction.selection.range.startContainer === fillerParent
    && interaction.selection.range.startOffset === fillerIndex
    && fillerIndex > 0
    && fillerParent.childNodes[fillerIndex - 1].nodeName === 'BR'
    && fillerParent.childNodes[fillerIndex - 1] !== filler;
  const pendingBeforeSecondLine = interaction.state.caretExitParagraph
    === paragraph;
  hooks.richInsertPlainText(interaction.state, 'Segunda linea');
  hooks.richResolveCaretExitAfterInput(interaction.state);
  const finalFlow = hooks.richParseTextFlowRoot(interaction.visual, false);
  return {
    headingEnter,
    inserted,
    textBoundary,
    breaksBeforeType: breaksBeforeType.length,
    fillerBeforeType: filler !== null,
    caretAfterBreak,
    pendingBeforeSecondLine,
    pending: interaction.state.caretExitParagraph !== null,
    fillerCount: interaction.visual.querySelectorAll(
      'br[data-blog-rich-caret-filler="true"]',
    ).length,
    paragraph: hooks.richSerializeTextFlowHtml([finalFlow[1]]),
    serialized: hooks.richSerializeTextFlowHtml(finalFlow),
  };
}

const visualBlockEnter = {
  leadingTyped: visualLeadingEnter(true),
  leadingAbandoned: visualLeadingEnter(false),
  typed: visualHeadingEnterTyped(),
  abandoned: visualHeadingEnterAbandoned(),
  moved: visualHeadingEnterMovedSelection(),
  ctrlEnter: visualHeadingCtrlEnter(),
};

function visualListStyleAccepted(style, marker = 'disc') {
  const listAttributes = { style };
  if (marker !== null) {
    listAttributes['data-content-list-marker'] = marker;
  }
  try {
    hooks.richParseTextFlowRoot(element('div', {}, [
      element('ul', listAttributes, [element('li', {}, [text('Uno')])]),
    ]), false);
    return true;
  } catch (error) {
    return false;
  }
}
const visualListStylePolicy = {
  generated: visualListStyleAccepted('list-style-type: disc;'),
  mismatched: visualListStyleAccepted('list-style-type: square;'),
  extra: visualListStyleAccepted('list-style-type: disc; color: red;'),
  priority: visualListStyleAccepted('list-style-type: disc !important;'),
  missingMarker: visualListStyleAccepted('list-style-type: disc;', null),
};

class SelectionElement {
  constructor(tag, parentElement = null) {
    this.nodeType = Node.ELEMENT_NODE;
    this.nodeName = tag.toUpperCase();
    this.parentElement = parentElement;
    this.children = [];
    if (parentElement) parentElement.children.push(this);
  }

  closest(selector) {
    const tags = selector.split(',').map((tag) => tag.trim().toLowerCase());
    let current = this;
    while (current) {
      if (tags.includes(current.nodeName.toLowerCase())) return current;
      current = current.parentElement;
    }
    return null;
  }

  contains(candidate) {
    let current = candidate;
    while (current) {
      if (current === this) return true;
      current = current.parentElement;
    }
    return false;
  }
}
globalThis.Element = SelectionElement;
globalThis.HTMLElement = SelectionElement;
function advancedListGuard(direct) {
  const visual = new SelectionElement('div');
  const parent = direct ? visual : new SelectionElement('div', visual);
  const list = new SelectionElement('ul', parent);
  const item = new SelectionElement('li', list);
  window.getSelection = () => ({
    rangeCount: 1,
    getRangeAt() {
      return { startContainer: item, endContainer: item };
    },
  });
  return hooks.richAdvancedVisualCanSplitSelection({
    advancedMode: true,
    advancedVisualEditable: true,
    advancedVisualStructureLocked: true,
    visual,
  });
}
const advancedListEnterGuard = {
  direct: advancedListGuard(true),
  nested: advancedListGuard(false),
};

class MeasureElement {
  constructor(tag = 'div') {
    this.tagName = tag.toUpperCase();
    this.className = '';
    this.textContent = '';
    this.style = {};
    this.children = [];
  }

  append(child) {
    this.children.push(child);
  }

  replaceChildren(...children) {
    this.children = children.flatMap((child) => (
      child && child.fragment === true ? child.children : [child]
    ));
  }

  getBoundingClientRect() {
    return { height: this.textContent.length > 12 ? 72 : 24 };
  }
}
globalThis.HTMLElement = MeasureElement;
globalThis.Element = MeasureElement;
document.createElement = (tag) => new MeasureElement(tag);
document.createDocumentFragment = () => {
  const fragment = new MeasureElement('fragment');
  fragment.fragment = true;
  return fragment;
};
window.getComputedStyle = () => ({ lineHeight: '24px' });
const measureMirror = new MeasureElement();
const measuredWrappedLines = hooks.richSourceMeasureWrappedLines({
  clientWidth: 208,
  value: 'una línea que envuelve varias veces\ncorta',
}, measureMirror);
const gutterModel = hooks.richSourceGutterModel(
  'una línea larga que ocupa tres filas\ncorta',
  measuredWrappedLines,
  24,
);
const gutterMeasure = {
  width: measureMirror.style.inlineSize,
  heights: measuredWrappedLines,
  rows: measureMirror.children.map((row) => row.textContent),
};
const gutterThousandRows = hooks.richSourceGutterModel(
  Array.from({ length: 1000 }, () => 'x').join('\n'),
  Array.from({ length: 1000 }, () => 24),
  24,
);
const gutterThousand = {
  count: gutterThousandRows.length,
  last: gutterThousandRows[gutterThousandRows.length - 1],
};

process.stdout.write(JSON.stringify({
  indent: hooks.RICH_SOURCE_INDENT,
  pairs: {
    openBrace,
    pairedEnter,
    overtypedBrace,
    deletedBracePair,
    wrappedSelection,
    pairedQuote,
    overtypedQuote,
    htmlClosed,
    htmlEntered,
    quotedAttributeHtmlClosed,
    quotedAttributeHtmlEntered,
    openQuotedAttributeHtmlClosed,
    nestedQuotedAttributeHtmlClosed,
    tabbed,
    untabbed,
  },
  modifiers: {
    altGraph: hooks.richCodeAltGraphInput(altGraphEvent),
    altGraphCommand: hooks.richCodeHasCommandModifier(altGraphEvent),
    fallback: hooks.richCodeAltGraphInput(altGraphFallbackEvent),
    fallbackCommand: hooks.richCodeHasCommandModifier(altGraphFallbackEvent),
    command: hooks.richCodeHasCommandModifier(commandEvent),
  },
  history: {
    scenarios: historyScenarios,
    directions: shortcutDirections,
    unifiedUndo,
    unifiedRedo,
    contiguousTyping: {
      units: contiguousTypingUnits,
      undo: contiguousTypingUndo,
      afterUndo: contiguousTypingAfterUndo,
      redo: contiguousTypingRedo,
      afterRedo: contiguousTypingAfterRedo,
    },
    overtype: overtypeHistoryResult,
    branch: {
      value: branchControl.value,
      redo: branchHistory.redo.length,
      undo: branchHistory.undo.length,
    },
    nativeKinds: {
      typed,
      pasted,
      inputTypes: nativeKindsHistory.undo.map((entry) => entry.inputType),
    },
    composition: {
      recorded: compositionRecorded,
      undo: compositionUndo,
      value: compositionControl.value,
      units: compositionHistory.redo.length,
    },
    beforeInputHistory: {
      undoDirection: beforeInputUndoDirection,
      undoPrevented: historyUndoEvent.prevented,
      redoDirection: beforeInputRedoDirection,
      redoPrevented: historyRedoEvent.prevented,
      value: beforeInputUndoControl.value,
    },
    boundedUndo: boundedHistory.undo.length,
  },
  textarea,
  formatted,
  formattedStable: formatted === formattedAgain,
  mixedFormatted,
  mixedAfterMinimalEdit,
  marks: {
    colorThenBold: hooks.richSerializeHtml(colorThenBold),
    boldThenColor: hooks.richSerializeHtml(boldThenColor),
    stableFlow: hooks.richSerializeTextFlowHtml(stableFlow),
  },
  policy,
  fallbackPolicy,
  palette: {
    color: hooks.RICH_PALETTE_OPTIONS.color,
    background: hooks.RICH_PALETTE_OPTIONS.background,
  },
  advanced: {
    noopSource: advancedNoopSource,
    visualSource: advancedVisualSource,
    touchState: {
      source: advancedState.sourceTouched,
      css: advancedState.cssTouched,
      visual: advancedState.visualTouched,
    },
    simpleShape: hooks.richAdvancedParagraphBlock({
      type: 'paragraph',
      html: rawAdvancedHtml,
      css: 'p { color: red; }',
    }),
    contentShape: hooks.richAdvancedParagraphBlock({
      type: 'paragraph',
      content: advancedFlow,
    }),
    simpleVisualEditable: hooks.richSourceUsesStandardParagraph(
      paragraphState,
      rawAdvancedHtml,
    ),
    complexVisualEditable: hooks.richSourceUsesStandardParagraph(
      paragraphState,
      complexAdvancedHtml,
    ),
    unsafeCssVisualEditable: hooks.richSourceUsesStandardParagraph(
      { ...paragraphState, cssDraft: unsafeVisualCss },
      rawAdvancedHtml,
    ),
    safeCss: hooks.richAdvancedCssVisualSafe(
      safeVisualCss,
      visualCssPolicy,
    ),
    unsafeCss: hooks.richAdvancedCssVisualSafe(
      unsafeVisualCss,
      visualCssPolicy,
    ),
    classedVisual: {
      visualEditable: hooks.richSourceSupportsAdvancedVisualFlow(
        paragraphState,
        classAdvancedHtml,
        classVisualCss,
      ),
      standardConvertible: hooks.richSourceUsesStandardParagraph(
        paragraphState,
        classAdvancedHtml,
        classVisualCss,
      ),
      structureLocked: hooks.richAdvancedVisualStructureLocked(
        classAdvancedHtml,
      ),
      noopSource: classAdvancedNoopSource,
      formattedSource: classAdvancedVisualSource,
      validSource: hooks.validAdvancedHtml(classAdvancedVisualSource, true),
      roundTripMarks: classAdvancedRoundTrip[1].content[0].marks,
      css: classVisualCss,
    },
    attributedVisual: {
      split: attributedSplitSource,
      lineBreak: attributedBreakSource,
      callout: attributedCalloutSource,
      emptyParagraphs: (attributedSplitSource.match(/<p><\/p>/gu) || []).length,
    },
    listEnter: advancedListEnter,
    listEnterGuard: advancedListEnterGuard,
    listExitInteraction: visualListExit,
    blockEnterInteraction: visualBlockEnter,
    listStylePolicy: visualListStylePolicy,
    advancedSectionHeading: hooks.v2SectionHeadingModule({
      type: 'paragraph',
      html: advancedSectionHeadingHtml,
      css: '.sectionTitle { color: red; }',
    }),
    advancedLeadingHeadingApply: {
      valid: hooks.richDraftKeepsLeadingHeading({
        requiresLeadingHeading: true,
        advancedMode: true,
        advancedHtmlDraft: advancedSectionHeadingHtml,
      }),
      invalid: hooks.richDraftKeepsLeadingHeading({
        requiresLeadingHeading: true,
        advancedMode: true,
        advancedHtmlDraft: rawAdvancedHtml,
      }),
    },
    unifiedPreviewCss: {
      headings: unifiedPreviewDocument.includes(':is(h2,h3,h4,h5,h6)'),
      lists: unifiedPreviewDocument.includes('padding-inline-start:1.25rem'),
      nestedLists: unifiedPreviewDocument.includes(
        'padding-inline-start:1.15rem'
      ),
      quote: unifiedPreviewDocument.includes(
        'border-inline-start:.22rem solid #24658e'
      ),
      callout: unifiedPreviewDocument.includes(
        'aside[data-content-callout=&quot;true&quot;]'
      ) || unifiedPreviewDocument.includes(
        'aside[data-content-callout="true"]'
      ),
      customAfterBase: unifiedPreviewDocument.indexOf(classVisualCss)
        > unifiedPreviewDocument.indexOf(':is(h2,h3,h4,h5,h6)'),
      marksAfterCustom: unifiedPreviewDocument.indexOf(
        '[data-content-format-text-color="color04"]'
      ) > unifiedPreviewDocument.indexOf(classVisualCss),
    },
    advancedVisualStyle,
    emptyCssOnly: hooks.richTextBlockEmpty({
      type: 'paragraph',
      html: '',
      css: 'color: red;',
    }),
    nonEmptyAdvanced: hooks.richTextBlockEmpty({
      type: 'paragraph',
      html: '<p>Texto</p>',
      css: 'color: red;',
    }),
    safeCommit,
    unsafeCommit,
    convertedViaHtml,
    convertedViaVisual,
    visualNet,
    cssOnlyVisual,
    existingEmptyStructures,
    rootBreakContract,
  },
  paletteClose,
  palettePointer,
  paletteTriggerClose,
  gutterModel,
  gutterMeasure,
  gutterThousand,
}));
