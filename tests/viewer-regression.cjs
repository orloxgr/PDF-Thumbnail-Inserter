const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/pdfjs-viewer/viewer.js'), 'utf8')
    .replace(/^import .*;\r?\n/, '').replaceAll('import.meta.url', '"https://example.test/assets/pdfjs-viewer/viewer.js"');
function fixture(options = {}) {
    const metrics = { documentRequests: 0, pages: [], renders: [], active: 0, peak: 0, destroyed: 0, cleanup: 0, cancelled: 0, errors: [] };
    const listeners = {}, frames = new Map();
    let observer, frameId = 0;
    class Element {
        constructor(tag) { this.tag = tag; this.children = []; this.dataset = {}; this.style = {}; this.classes = new Set(); this.classList = { add: c => this.classes.add(c) }; }
        appendChild(child) { this.children.push(child); child.parent = this; }
        insertBefore(child, reference) { this.children.splice(this.children.indexOf(reference), 0, child); child.parent = this; }
        replaceChildren() { this.children = []; }
        remove() { if (this.parent) this.parent.children = this.parent.children.filter(n => n !== this); this.parent = null; }
        setAttribute(name, value) { this[name] = value; }
        getContext() { return { canvas: this }; }
        getBoundingClientRect() { const top = viewer.children.indexOf(this) * 600 - window.scrollY; return { top, bottom: top + 600 }; }
    }
    const viewer = new Element('main'); viewer.clientWidth = 1000;
    const status = new Element('div');
    const body = new Element('body');
    const document = { body, getElementById: id => id === 'viewer' ? viewer : status, createElement: tag => new Element(tag) };
    const window = {
        innerWidth: 1024, innerHeight: 700, devicePixelRatio: 4, scrollY: 0,
        location: { search: options.search ?? '?file=https%3A%2F%2Fexample.test%2Ffile.pdf' },
        addEventListener: (name, fn) => { listeners[name] = fn; },
        removeEventListener: name => { delete listeners[name]; },
        requestAnimationFrame: fn => { frames.set(++frameId, fn); return frameId; }, cancelAnimationFrame: id => frames.delete(id),
    };
    const pdf = {
        numPages: options.pages || 300,
        async getPage(number) {
            metrics.pages.push(number);
            if (options.badPage === number) throw new Error('Broken page');
            const [width, height] = (options.dimensions || {})[number] || [200, 300];
            return {
                getViewport: ({ scale }) => ({ width: width * scale, height: height * scale }),
                cleanup: () => { metrics.cleanup++; },
                render(args) {
                    metrics.active++; metrics.peak = Math.max(metrics.peak, metrics.active);
                    const entry = { number, width: args.canvasContext.canvas.width, height: args.canvasContext.canvas.height, canvas: args.canvasContext.canvas };
                    metrics.renders.push(entry);
                    let resolve, reject, settled = false;
                    const promise = new Promise((ok, fail) => { resolve = ok; reject = fail; });
                    const finish = () => { if (!settled) { settled = true; metrics.active--; resolve(); } };
                    entry.finish = finish;
                    if (options.renderError === number) { settled = true; metrics.active--; reject(new Error('Render failed')); }
                    else if (options.pause !== number) finish();
                    return { promise, cancel() { if (!settled) { settled = true; metrics.active--; metrics.cancelled++; const error = new Error('Cancelled'); error.name = 'RenderingCancelledException'; reject(error); } } };
                },
            };
        },
    };
    const pdfjsLib = { GlobalWorkerOptions: {}, getDocument(args) {
        metrics.documentRequests++; assert.equal(args.isEvalSupported, false);
        return { promise: options.badFile ? Promise.reject(new Error('Broken PDF')) : Promise.resolve(pdf), async destroy() { metrics.destroyed++; } };
    } };
    class IntersectionObserver {
        constructor(callback) { this.callback = callback; observer = this; }
        observe() {}
        unobserve() {}
        disconnect() { this.disconnected = true; }
    }
    vm.runInNewContext(source, { pdfjsLib, document, window, URL, URLSearchParams, Map,
        IntersectionObserver: options.fallback ? undefined : IntersectionObserver,
        console: { error: (...args) => metrics.errors.push(args) } });
    return { viewer, status, body, metrics, window, listeners,
        observer: () => observer,
        visible(number, value) { observer.callback([{ target: viewer.children[number - 1], isIntersecting: value }]); },
        more() { observer.callback([{ target: viewer.children.find(e => e.className === 'pdf-page-loader'), isIntersecting: true }]); },
        frame() { const callbacks = [...frames.values()]; frames.clear(); callbacks.forEach(fn => fn()); },
    };
}
const flush = () => new Promise(resolve => setImmediate(resolve));
module.exports = { fixture, flush };
if (require.main === module) (async () => {
    const lazy = fixture({ dimensions: { 2: [100, 200000], 3: [200000, 100] } });
    await flush();
    assert.equal(lazy.viewer.children.filter(e => e.tag === 'section').length, 50, 'Only a bounded placeholder batch is created initially');
    for (let i = 0; i < 5; i++) lazy.more();
    assert.equal(lazy.viewer.children.length, 300, 'Scrolling can append all remaining pages without an eager page-count allocation');
    assert.deepEqual(lazy.metrics.pages, [1], 'Only first-page geometry is fetched before visibility');
    assert.equal(lazy.metrics.renders.length, 0, 'No hidden page is rasterized eagerly');
    lazy.visible(1, true); lazy.visible(2, true); lazy.visible(3, true);
    await flush();
    assert.equal(lazy.metrics.renders.length, 3);
    assert.equal(lazy.metrics.peak, 1, 'Only one page decoder/render is active per viewer');
    assert(lazy.metrics.renders.every(r => r.width <= 1200 && r.height <= 2000), 'Tall/wide pages and DPR 4 remain pixel-bounded');
    const released = lazy.metrics.renders[0].canvas;
    lazy.visible(1, false);
    assert.equal(released.width, 0); assert.equal(released.height, 0);
    assert.equal(lazy.viewer.children[0].children.length, 0, 'Offscreen canvas is removed and its pixel allocation released');
    lazy.visible(1, true); await flush();
    assert.equal(lazy.metrics.renders.length, 4, 'A revisited page renders again with the live document');
    assert.equal(lazy.metrics.destroyed, 0, 'Scrolling retains one usable document worker');
    await lazy.listeners.pagehide(); await lazy.listeners.pagehide(); await flush();
    assert.equal(lazy.metrics.destroyed, 1, 'Navigation destroys the worker exactly once');
    assert(lazy.observer().disconnected);
    assert(lazy.metrics.renders.every(r => r.canvas.width === 0));

    const broken = fixture({ pages: 4, badPage: 2 }); await flush();
    broken.visible(1, true); broken.visible(2, true); broken.visible(3, true); await flush();
    assert.equal(broken.viewer.children[1].dataset.renderState, 'error');
    assert.equal(broken.viewer.children[2].dataset.renderState, 'loaded', 'A bad page does not discard later valid pages');
    await broken.listeners.pagehide();

    const pending = fixture({ pause: 1 }); await flush(); pending.visible(1, true); await flush();
    assert.equal(pending.metrics.active, 1);
    await pending.listeners.pagehide(); await flush();
    assert.equal(pending.metrics.cancelled, 1); assert.equal(pending.metrics.destroyed, 1);
    assert.equal(pending.metrics.errors.length, 0, 'Navigation cancellation is not reported as a broken PDF');

    const fallback = fixture({ fallback: true, pages: 20 }); await flush();
    assert.equal(fallback.metrics.renders.length, 2, 'No-observer fallback still renders only the viewport window');
    fallback.window.scrollY = 3000; fallback.listeners.scroll(); fallback.frame(); await flush();
    assert.equal(fallback.viewer.children[0].children.length, 0);
    assert(fallback.metrics.renders.some(r => r.number >= 5), 'Fallback scrolling reaches later pages');
    fallback.listeners.resize(); fallback.frame(); await flush();
    await fallback.listeners.pagehide();

    const thumbnail = fixture({ search: '?file=x.pdf&mode=thumbnail&pages=99' }); await flush();
    assert.equal(thumbnail.viewer.children.length, 1, 'Thumbnail mode decodes at most the first page');
    assert(thumbnail.body.classes.has('is-thumbnail')); await thumbnail.listeners.pagehide();
    const limited = fixture({ search: '?file=x.pdf&pages=2' }); await flush();
    assert.equal(limited.viewer.children.length, 2, 'The existing pages query limit remains supported'); await limited.listeners.pagehide();
    const invalid = fixture({ badFile: true }); await flush();
    assert.equal(invalid.metrics.destroyed, 1); assert(invalid.status.classes.has('is-error')); assert.equal(invalid.viewer.children.length, 0);
    const absent = fixture({ search: '' }); await flush();
    assert.equal(absent.metrics.documentRequests, 0); assert(absent.status.classes.has('is-error'));
    const huge = fixture({ pages: 1000000000 }); await flush();
    assert.equal(huge.viewer.children.filter(e => e.tag === 'section').length, 50, 'An enormous untrusted page count cannot cause an eager DOM allocation'); await huge.listeners.pagehide();
    const nonfinite = fixture({ dimensions: { 1: [Infinity, 300] } }); await flush();
    assert(nonfinite.status.classes.has('is-error')); assert.equal(nonfinite.metrics.destroyed, 1);
    console.log('PASS: viewport-only pages, pixel limits, offscreen release/revisit, cancellation/disposal, broken pages/files, resize/scroll fallback and query modes.');
})().catch(error => { console.error(error); process.exitCode = 1; });
