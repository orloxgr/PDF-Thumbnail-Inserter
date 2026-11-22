// Run with node tests/browser-maintenance-regression.cjs (no browser dependencies).
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

async function maintenanceTest(mode) {
    const elements = Object.fromEntries(['generate-previews', 'preview-progress', 'preview-status', 'preview-log'].map(name => [
        `pti-browser-${name}`, { textContent: '', hidden: false, value: 0, max: 0, children: [],
            addEventListener(event, callback) { this.click = callback; }, appendChild(item) { this.children.push(item); } }
    ]));
    const pages = [], rendered = [], saved = [];
    const document = { readyState: 'complete', getElementById: id => elements[id], createElement: () => ({ textContent: '' }) };
    const config = { ajaxUrl: '/ajax', nonce: 'valid' };
    const fetch = async (url, options) => {
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.body.get('nonce'), 'valid');
        const action = options.body.get('action');
        let data;
        if (action.endsWith('list_missing_previews')) {
            const page = Number(options.body.get('page'));
            pages.push(page);
            if (mode === 'discovery-error') throw new Error('Network error');
            data = page === 1 ? { items: [], next_page: mode === 'bad-page' ? 1 : 2 } : {
                items: [1, 2, 3].map(id => ({ id, title: `PDF ${id}`, file_url: `/${id}.pdf` })), next_page: 0
            };
        } else {
            assert.equal(action, 'pdf_thumbnail_inserter_save_browser_preview');
            const id = Number(options.body.get('attachment_id'));
            assert.equal(options.body.get('preview').type, 'image/jpeg');
            saved.push(id);
            data = id === 3 ? { skipped: true } : { generated: true };
        }
        return { ok: true, json: async () => ({ success: true, data }) };
    };
    const renderPdfToBlob = async url => {
        rendered.push(url);
        if (url === '/2.pdf') throw new Error('Broken PDF');
        return new Blob(['JPEG'], { type: 'image/jpeg' });
    };
    const source = fs.readFileSync(path.join(__dirname, '../assets/js/browser-maintenance.js'), 'utf8')
        .replace("import { renderPdfToBlob } from './browser-preview.js';", '');
    new Function('window', 'document', 'fetch', 'renderPdfToBlob', source)({ pdfThumbnailBrowserMaintenance: config }, document, fetch, renderPdfToBlob);
    const button = elements['pti-browser-generate-previews'];
    await button.click();
    assert.equal(button.disabled, false, 'Button recovers after success and failures');
    if (mode === 'success') {
        assert.deepEqual(pages, [1, 2], 'An empty page of missing previews must not terminate discovery');
        assert.deepEqual(rendered, ['/1.pdf', '/2.pdf', '/3.pdf']);
        assert.deepEqual(saved, [1, 3], 'A failed PDF must not stop later files');
        assert.match(elements['pti-browser-preview-status'].textContent, /1 generated, 1 skipped, 1 failed/);
        assert.equal(elements['pti-browser-preview-log'].children.length, 1);
    } else {
        assert.deepEqual(pages, [1]);
        assert.deepEqual(rendered, []);
        assert.match(elements['pti-browser-preview-status'].textContent, /failed/i);
    }
}

async function viewerTest(mode) {
    let destroyed = 0;
    const element = () => ({ style: {}, classList: { add() {} }, setAttribute() {}, appendChild() {}, replaceChildren() {}, getContext: () => ({}) });
    const mock = { GlobalWorkerOptions: {}, getDocument: () => ({
        promise: mode === 'load-error' ? Promise.reject(new Error('Load failed')) : Promise.resolve({
            numPages: 1, getPage: async () => ({ getViewport: () => ({ width: 600, height: 800 }),
                render: () => ({ promise: mode === 'render-error' ? Promise.reject(new Error('Render failed')) : Promise.resolve() }) })
        }), destroy: async () => { destroyed++; }
    }) };
    const document = { body: element(), getElementById: element, createElement: element };
    const window = { innerWidth: 800, location: { search: '?file=%2Ftest.pdf' } };
    const source = fs.readFileSync(path.join(__dirname, '../assets/pdfjs-viewer/viewer.js'), 'utf8')
        .replace("import * as pdfjsLib from '../pdfjs/pdf.min.js';", '')
        .replaceAll('import.meta.url', JSON.stringify('https://example.test/assets/pdfjs-viewer/viewer.js'))
        .replace(/loadPdf\(\);\s*$/, 'return loadPdf();');
    await new Function('pdfjsLib', 'window', 'document', 'console', source)(mock, window, document, { error() {} });
    assert.equal(destroyed, 1, `Viewer releases its worker on ${mode}`);
}

(async () => {
    for (const mode of ['success', 'bad-page', 'discovery-error']) await maintenanceTest(mode);
    for (const mode of ['success', 'load-error', 'render-error']) await viewerTest(mode);
    console.log('PASS: paginated maintenance, empty pages, error recovery, later PDFs, and viewer worker cleanup.');
})().catch(error => { console.error(error); process.exitCode = 1; });
