// Run with: node tests/browser-upload-regression.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const source = name => fs.readFileSync(path.join(__dirname, '..', 'assets/js', name), 'utf8');

function element() {
    return {
        children: [], textContent: '', classList: { toggle() {} },
        setAttribute() {}, append(...items) { this.children.push(...items); },
        appendChild(item) { this.children.push(item); }, prepend(item) { this.children.unshift(item); }
    };
}
function attachment(id, mime = 'application/pdf', uploading = false) {
    const values = { id, mime, uploading, filename: `${id}.${mime === 'application/pdf' ? 'pdf' : 'png'}` };
    return { get: key => values[key], fetch: async () => {}, values };
}
async function until(predicate) {
    const deadline = Date.now() + 3000;
    while (!predicate()) {
        if (Date.now() > deadline) throw new Error('Upload queue did not finish');
        await new Promise(setImmediate);
    }
}

async function testUploadQueue() {
    const events = {}, legacy = {}, root = element(), requests = [], rendered = [];
    let active = 0, maxActive = 0, refreshed = 0, restMiddleware;
    globalThis.__ptiTestRender = async url => {
        active++;
        maxActive = Math.max(maxActive, active);
        rendered.push(url);
        try {
            await new Promise(setImmediate);
            if (url.endsWith('/3.pdf')) throw new Error('Broken PDF');
            return new Blob(['test JPEG'], { type: 'image/jpeg' });
        } finally { active--; }
    };
    const rendererUrl = 'data:text/javascript,' + encodeURIComponent('export const renderPdfToBlob = url => globalThis.__ptiTestRender(url);');
    const window = {
        pdfThumbnailBrowserUpload: { enabled: true, ajaxUrl: '/ajax', nonce: 'nonce', rendererUrl },
        wp: { apiFetch: { use(fn) { restMiddleware = fn; } }, Uploader: { queue: { on(name, fn) { events[name] = fn; }, each() {} } } },
        jQuery: fn => fn(), uploader: { bind(name, fn) { legacy[name] = fn; } }
    };
    const document = { body: root, getElementById: () => root, createElement: element };
    const fetch = async (url, options) => {
        assert.equal(options.credentials, 'same-origin');
        const action = options.body.get('action');
        const id = Number(options.body.get('attachment_id'));
        requests.push([action, id]);
        if (action.endsWith('fetch_pdf_data')) {
            return { ok: true, json: async () => ({ success: true, data: {
                preview_source: id === 2 ? 'wordpress' : 'placeholder', file_url: `https://example.test/${id}.pdf`
            } }) };
        }
        assert.equal(action, 'pdf_thumbnail_inserter_save_browser_preview');
        assert.equal(options.body.get('automatic'), '1');
        assert.equal(options.body.get('nonce'), 'nonce');
        assert.equal(options.body.get('preview').type, 'image/jpeg');
        if (id === 5) return { ok: false, json: async () => ({ success: false, data: { message: 'Cannot save' } }) };
        return { ok: true, json: async () => ({ success: true, data: { generated: true } }) };
    };
    const run = new Function('window', 'document', 'fetch', 'URLSearchParams', 'FormData', source('browser-upload.js'));
    run(window, document, fetch, URLSearchParams, FormData);
    const uploaded = events['change:uploading'];
    const first = attachment(1);
    first.fetch = async () => { refreshed++; throw new Error('Refresh failed after successful save'); };
    uploaded(first);
    uploaded(first); // Duplicate event must not start a second render/save.
    uploaded(attachment(2)); // Existing server preview must be kept.
    uploaded(attachment(3)); // Broken PDF must not stop subsequent files.
    uploaded(attachment(4, 'image/png'));
    uploaded(attachment(100, 'application/pdf', true)); // Still uploading.
    uploaded(attachment(5)); // Save denied, then queue continues.
    legacy.FileUploaded(null, { name: 'six.pdf' }, { response: '6' });
    legacy.FileUploaded(null, { name: 'seven.pdf' }, { response: '<pre>7</pre>' });
    legacy.FileUploaded(null, { name: 'bad.pdf' }, { response: '<div>Upload error</div>' });
    const restResult = { id: 8, mime_type: 'application/pdf', title: { raw: 'REST upload' } };
    assert.equal(await restMiddleware({ method: 'POST', path: '/wp/v2/media' }, async () => restResult), restResult);
    // A response with parse:false must remain readable by the original caller.
    const rawResponse = new Response(JSON.stringify({ id: 9, mime_type: 'application/pdf' }));
    assert.equal(await restMiddleware({ method: 'POST', url: 'https://example.test/wp-json/wp/v2/media' }, async () => rawResponse), rawResponse);
    assert.equal((await rawResponse.json()).id, 9);
    await restMiddleware({ method: 'GET', path: '/wp/v2/media' }, async () => ({ id: 90, mime_type: 'application/pdf' }));
    await restMiddleware({ method: 'POST', path: '/wp/v2/media/91' }, async () => ({ id: 91, mime_type: 'application/pdf' }));
    await restMiddleware({ method: 'POST', path: '/wp/v2/media' }, async () => ({ id: 92, mime_type: 'image/png' }));
    await until(() => root.children[0]?.children[0]?.textContent.includes('PDF previews failed: 2'));
    assert.equal(maxActive, 1, 'Browser rendering must be serialized');
    assert.equal(refreshed, 1);
    assert.deepEqual(rendered, [1, 3, 5, 6, 7, 8, 9].map(id => `https://example.test/${id}.pdf`));
    assert.deepEqual(requests.filter(([action]) => action.endsWith('save_browser_preview')).map(([, id]) => id), [1, 5, 6, 7, 8, 9]);
    assert.match(root.children[0].children[0].textContent, /PDF previews saved: 5.*Existing previews kept: 1/);
    assert.equal(root.children[0].children[1].children.length, 2);
    let initialized = false;
    run({ pdfThumbnailBrowserUpload: { enabled: false }, jQuery() { initialized = true; } }, document, fetch, URLSearchParams, FormData);
    assert.equal(initialized, false, 'Disabled feature must not attach upload listeners');
    delete globalThis.__ptiTestRender;
}

async function testRendererCleanup() {
    let destroyed = 0, mode = 'success', output;
    const mock = {
        GlobalWorkerOptions: {},
        getDocument() {
            return {
                promise: mode === 'load-error' ? Promise.reject(new Error('Load failed')) : Promise.resolve({ getPage: async () => ({
                    getViewport: ({ scale }) => ({ width: 600 * scale, height: 900 * scale }),
                    render() { return { promise: mode === 'render-error' ? Promise.reject(new Error('Render failed')) : Promise.resolve() }; }
                }) }),
                destroy: async () => { destroyed++; }
            };
        }
    };
    globalThis.__ptiPdfMock = mock;
    const previousDocument = globalThis.document;
    globalThis.document = { createElement() {
        output = { width: 0, height: 0, getContext: () => ({}), toBlob(callback, type) {
            assert.ok(output.width <= 1200 && output.height <= 2000);
            callback(mode === 'blob-error' ? null : new Blob(['JPEG'], { type }));
        } };
        return output;
    } };
    const script = source('browser-preview.js')
        .replace("import * as pdfjsLib from '../pdfjs/pdf.min.js';", 'const pdfjsLib = globalThis.__ptiPdfMock;')
        .replaceAll('import.meta.url', JSON.stringify('https://example.test/assets/js/browser-preview.js'));
    try {
        const { renderPdfToBlob } = await import('data:text/javascript;base64,' + Buffer.from(script).toString('base64'));
        const blob = await renderPdfToBlob('/test.pdf');
        assert.equal(blob.type, 'image/jpeg');
        assert.equal(destroyed, 1);
        assert.equal(output.width, 0);
        for (const failure of ['load-error', 'render-error', 'blob-error']) {
            mode = failure;
            await assert.rejects(renderPdfToBlob('/test.pdf'));
        }
        assert.equal(destroyed, 4, 'PDF workers must be destroyed on every success/failure path');
    } finally {
        globalThis.document = previousDocument;
        delete globalThis.__ptiPdfMock;
    }
}

(async () => {
    await testUploadQueue();
    await testRendererCleanup();
    console.log('PASS: automatic upload detection, serial rendering, existing previews, duplicate events, errors, settings and cleanup.');
})().catch(error => { console.error(error); process.exitCode = 1; });
