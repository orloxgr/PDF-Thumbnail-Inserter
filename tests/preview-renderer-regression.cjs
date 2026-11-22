// Behavioral checks for decoder concurrency, canvas bounds and dynamic SSR nodes.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const tasks = [];
const errors = [];
const mutations = [];
class MutationObserver {
    constructor(callback) { this.callback = callback; mutations.push(this); }
    observe() {}
    disconnect() { this.disconnected = true; }
}
function preview(id) {
    const canvas = { style: {}, getContext: () => ({}) };
    const status = { hidden: false };
    const classes = new Set();
    return {
        dataset: { pdfUrl: `https://example.org/${id}.pdf` }, clientWidth: 640,
        classList: { add: value => classes.add(value) }, classes, canvas, status,
        querySelector: selector => selector === 'canvas' ? canvas : status,
        querySelectorAll: () => [], matches: () => true,
    };
}
const elements = [preview(1), preview(2), preview(3), preview(4), preview(5)];
const root = { querySelectorAll: () => elements };
const pdfjsLib = {
    GlobalWorkerOptions: {},
    getDocument() {
        let resolve, reject;
        const promise = new Promise((ok, fail) => { resolve = ok; reject = fail; });
        const task = { promise, resolve, reject, destroyed: 0, async destroy() { this.destroyed++; } };
        tasks.push(task);
        return task;
    },
};
let source = fs.readFileSync(path.join(__dirname, '../assets/js/pdf-thumbnail-preview.js'), 'utf8')
    .replace(/^import .*;\r?\n/m, '')
    .replaceAll('import.meta.url', '"https://example.org/plugin/assets/js/pdf-thumbnail-preview.js"')
    .replace('export function watchPreviewRendering', 'function watchPreviewRendering');
source += '\nglobalThis.watchPreviewRendering = watchPreviewRendering;';
let libraryRequests = 0;
const context = { getPdfLibrary: async () => { libraryRequests++; return pdfjsLib; }, URL, WeakSet, MutationObserver, window: { devicePixelRatio: 2 }, document: { readyState: 'loading', addEventListener() {} }, console: { error: (...args) => errors.push(args) } };
vm.runInNewContext(source, context);
const disconnectEmpty = context.watchPreviewRendering({ querySelectorAll: () => [] });
assert.equal(libraryRequests, 0, 'A listing/editor without canvases does not load PDF.js');
disconnectEmpty();
const disconnect = context.watchPreviewRendering(root);
const flush = () => new Promise(resolve => setImmediate(resolve));
function documentWithPage(width, height) {
    return { async getPage() { return { getViewport: ({ scale }) => ({ width: width * scale, height: height * scale }), render: () => ({ promise: Promise.resolve() }) }; } };
}
(async () => {
    let imports = 0;
    const runtime = { URL, importPdf: () => { imports++; return imports === 1 ? Promise.reject(new Error('Temporary import failure')) : Promise.resolve(pdfjsLib); } };
    const runtimeSource = fs.readFileSync(path.join(__dirname, '../assets/js/pdf-runtime.js'), 'utf8')
        .replace('export function getPdfLibrary', 'function getPdfLibrary')
        .replace("import('../pdfjs/pdf.min.js')", 'importPdf()')
        .replaceAll('import.meta.url', '"https://example.org/plugin/assets/js/pdf-runtime.js"');
    vm.runInNewContext(runtimeSource, runtime);
    assert.equal(imports, 0, 'Loading the small runtime does not import PDF.js eagerly');
    const unavailable = runtime.getPdfLibrary();
    assert.equal(unavailable, runtime.getPdfLibrary(), 'Simultaneous jobs share one library import');
    await assert.rejects(unavailable, /Temporary import failure/);
    assert.equal(await runtime.getPdfLibrary(), pdfjsLib, 'A failed import can recover on a later job');
    assert.equal(imports, 2);
    assert.equal(pdfjsLib.GlobalWorkerOptions.workerSrc, 'https://example.org/plugin/assets/pdfjs/pdf.worker.min.js');
    await flush();
    assert.equal(tasks.length, 2, 'Only two visible PDFs may begin decoding concurrently');
    tasks[0].resolve(documentWithPage(100, 200000));
    tasks[1].reject(new Error('Unreadable PDF'));
    await flush();
    assert.equal(tasks.length, 4, 'Success and failure each release a decoder slot');
    assert(elements[0].canvas.width <= 1200 && elements[0].canvas.height <= 2000, 'Very tall PDF stays within canvas pixel limits');
    assert(elements[0].classes.has('is-loaded') && elements[0].status.hidden);
    assert(elements[1].classes.has('is-error') && !elements[1].status.hidden);
    tasks[2].resolve(documentWithPage(200000, 100));
    tasks[3].resolve(documentWithPage(200, 300));
    await flush();
    assert.equal(tasks.length, 5);
    assert(elements[2].canvas.width <= 1200 && elements[2].canvas.height <= 2000, 'Very wide PDF stays within canvas pixel limits');
    tasks[4].resolve(documentWithPage(200, 300));
    await flush();
    const added = preview(6);
    mutations[1].callback([{ addedNodes: [added] }]);
    await flush();
    assert.equal(tasks.length, 6, 'A later ServerSideRender node starts rendering');
    mutations[1].callback([{ addedNodes: [added] }]);
    assert.equal(tasks.length, 6, 'Repeated mutations do not decode the same preview twice');
    tasks[5].resolve(documentWithPage(200, 300));
    await flush();
    assert(tasks.every(task => task.destroyed === 1), 'Every worker is destroyed after success or failure');
    assert.equal(errors.length, 1, 'Only the deliberately unreadable PDF reports a failure');
    disconnect();
    assert(mutations[1].disconnected, 'Editor observer is disconnected on unmount');
    console.log('PASS: two concurrent decoders, bounded wide/tall canvases, dynamic SSR previews, duplicate events and worker cleanup.');
})().catch(error => { console.error(error); process.exitCode = 1; });
