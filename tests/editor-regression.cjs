// Run with: node tests/editor-regression.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.join(__dirname, '..');
const source = name => fs.readFileSync(path.join(root, name), 'utf8');
const columns = { columns_widescreen: 6, columns_desktop: 4, columns_laptop: 3, columns_mobile: 2 };

function insertClassic(selection) {
    let click, select, inserted;
    const frame = {
        on(event, callback) { assert.equal(event, 'select'); select = callback; },
        open() {},
        state() { return { get() { return { toJSON: () => selection }; } }; }
    };
    const media = () => frame;
    media.editor = { insert(value) { inserted = value; } };
    const jq = value => {
        if (typeof value === 'function') value(jq);
        return { on(event, selector, callback) { click = callback; } };
    };
    vm.runInNewContext(source('assets/js/classic-editor.js'), {
        jQuery: jq, document: {}, wp: { media },
        pdfThumbnailClassic: { columns, labels: { selectPdf: 'Select', error: 'Error' } },
        window: { prompt() { throw new Error('Insertion must not prompt for columns'); } },
        alert() { throw new Error('Unexpected error'); }
    });
    click({ preventDefault() {} });
    select();
    return inserted;
}
assert.equal(insertClassic([{ id: 42 }]), '[pdf_thumbnail_inserter id="42" columns_widescreen="6" columns_desktop="4" columns_laptop="3" columns_mobile="2"]');
assert.equal(insertClassic([{ id: 42 }, { id: 43 }, { id: 42 }]), '[pdf_thumbnail_inserter ids="42,43" columns_widescreen="6" columns_desktop="4" columns_laptop="3" columns_mobile="2"]');

let block, variation;
const createElement = (type, props, ...children) => ({ type, props: props || {}, children });
const wp = {
    element: { createElement }, i18n: { __: s => s },
    blocks: {
        registerBlockType(name, definition) { block = definition; },
        registerBlockVariation(name, definition) { variation = definition; }
    },
    blockEditor: { useBlockProps: value => value, InspectorControls: 'InspectorControls', MediaUpload: 'MediaUpload', MediaUploadCheck: 'MediaUploadCheck' },
    components: Object.fromEntries(['PanelBody', 'Button', 'TextControl', 'SelectControl', 'ToggleControl', 'Notice', 'RangeControl'].map(name => [name, name])),
    serverSideRender: 'ServerSideRender'
};
vm.runInNewContext(source('blocks/pdf-thumbnail/index.js'), { window: { wp, pdfThumbnailBlock: { columns } } });
assert.equal(variation.isDefault, true);
assert.equal(JSON.stringify(variation.scope), '["inserter"]');
assert.deepEqual(variation.attributes, columns);
const schema = JSON.parse(source('blocks/pdf-thumbnail/block.json')).attributes;
assert.equal(schema.columns_desktop.default, 3);
assert.equal(schema.columns_laptop.default, 2);
assert.equal(schema.columns_mobile.default, 1);
assert.equal(schema.columns_widescreen.default, undefined);
const oldAttributes = { id: 42, ids: [], columns_desktop: 5, columns_laptop: 4, columns_mobile: 1 };
let mutations = 0;
const tree = block.edit({ attributes: oldAttributes, setAttributes() { mutations++; } });
assert.equal(mutations, 0, 'Opening an old block must not rewrite its attributes');
const nodes = [];
function collect(node) {
    if (!node || typeof node !== 'object') return;
    nodes.push(node);
    (node.children || []).forEach(collect);
}
collect(tree);
assert.equal(nodes.find(n => n.props.label === 'Widescreen columns').props.value, 5);
assert.equal(nodes.find(n => n.type === 'ServerSideRender').props.attributes, oldAttributes);
console.log('PASS: prompt-free Classic inserts, Gutenberg insertion defaults, and existing block layouts.');
