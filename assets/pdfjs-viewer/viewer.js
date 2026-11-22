import * as pdfjsLib from '../pdfjs/pdf.min.js';

pdfjsLib.GlobalWorkerOptions.workerSrc = new URL(
    '../pdfjs/pdf.worker.min.js',
    import.meta.url
).href;

const viewer = document.getElementById('viewer');
const status = document.getElementById('status');
const searchParams = new URLSearchParams(window.location.search);
const fileUrl = searchParams.get('file');
const requestedPages = Math.max(0, Number.parseInt(searchParams.get('pages') || '0', 10) || 0);
const thumbnailMode = searchParams.get('mode') === 'thumbnail';
const states = [];
const queue = [];
const byElement = new Map();
let loadingTask;
let pdf;
let observer;
let running = false;
let disposed = false;
let resizeFrame = 0;
let scrollFrame = 0;
let moreFrame = 0;
let pageLimit = 0;
let nextPage = 1;
let initialBase;
let sentinel;

if (thumbnailMode) {
    document.body.classList.add('is-thumbnail');
}

function assetUrl(relativePath) {
    return new URL(relativePath, import.meta.url).href;
}

function showError() {
    status.textContent = viewer.dataset.errorLabel || 'The PDF preview could not be loaded.';
    status.classList.add('is-error');
    status.hidden = false;
}

function viewportFor(base) {
    const horizontalPadding = window.innerWidth <= 600 ? 12 : 24;
    const availableWidth = Math.max(200, viewer.clientWidth - horizontalPadding);
    return Math.max(0.1, Math.min(2, availableWidth / base.width));
}

function layout(state) {
    const scale = viewportFor(state.base);
    state.element.style.width = `${Math.ceil(state.base.width * scale)}px`;
    state.element.style.aspectRatio = `${state.base.width} / ${state.base.height}`;
}

function releaseCanvas(state) {
    if (state.canvas) {
        state.canvas.width = state.canvas.height = 0;
        state.canvas.remove();
        state.canvas = null;
    }
    state.element.dataset.renderState = state.failed ? 'error' : 'idle';
}

function enqueue(state) {
    if (!disposed && state.visible && !state.canvas && !state.rendering && !state.queued && !state.failed) {
        state.queued = true;
        queue.push(state);
        processQueue();
    }
}

function visibility(state, visible) {
    state.visible = visible;
    if (visible) {
        enqueue(state);
    } else {
        state.task?.cancel();
        releaseCanvas(state);
    }
}

async function processQueue() {
    if (running || disposed) return;
    running = true;
    try {
        while (queue.length && !disposed) {
            const state = queue.shift();
            state.queued = false;
            if (!state.visible || state.canvas || state.failed) continue;
            state.rendering = true;
            let page;
            try {
                page = await pdf.getPage(state.number);
                if (!state.visible || disposed) continue;
                state.base = page.getViewport({ scale: 1 });
                if (!Number.isFinite(state.base.width) || !Number.isFinite(state.base.height) || !(state.base.width > 0 && state.base.height > 0)) throw new Error('Invalid PDF page dimensions');
                layout(state);
                const viewport = page.getViewport({ scale: viewportFor(state.base) });
                // Bound backing pixels even for extreme page dimensions and high-DPI screens.
                const outputScale = Math.min(window.devicePixelRatio || 1, 2, 1200 / viewport.width, 2000 / viewport.height);
                const canvas = document.createElement('canvas');
                state.canvas = canvas;
                canvas.width = Math.max(1, Math.floor(viewport.width * outputScale));
                canvas.height = Math.max(1, Math.floor(viewport.height * outputScale));
                const context = canvas.getContext('2d', { alpha: false });
                if (!context) throw new Error('Could not create a PDF canvas');
                state.element.appendChild(canvas);
                state.element.dataset.renderState = 'loading';
                state.task = page.render({ canvasContext: context, viewport,
                    transform: outputScale === 1 ? null : [outputScale, 0, 0, outputScale, 0, 0], background: '#ffffff' });
                await state.task.promise;
                if (!state.visible || disposed) releaseCanvas(state);
                else state.element.dataset.renderState = 'loaded';
            } catch (error) {
                releaseCanvas(state);
                if (!disposed && state.visible && error.name !== 'RenderingCancelledException') {
                    state.failed = true;
                    state.element.dataset.renderState = 'error';
                    state.element.classList.add('is-error');
                    state.element.textContent = `${state.label}: ${viewer.dataset.errorLabel || 'The PDF preview could not be loaded.'}`;
                    console.error('PDF page preview error:', error);
                }
            } finally {
                state.task = null;
                state.rendering = false;
                page?.cleanup();
                enqueue(state); // A resize may have cancelled a still-visible page.
            }
        }
    } finally {
        running = false;
    }
}

function updateVisibility() {
    for (const state of states) {
        const bounds = state.element.getBoundingClientRect();
        visibility(state, bounds.bottom > -300 && bounds.top < window.innerHeight + 300);
    }
    scheduleMorePages();
}

function scheduleMorePages() {
    if (moreFrame || disposed || nextPage > pageLimit) return;
    moreFrame = window.requestAnimationFrame(() => {
        moreFrame = 0;
        if (!disposed && sentinel.getBoundingClientRect().top < window.innerHeight + 300) appendPages();
    });
}

function appendPages() {
    // A malformed /Count must not allocate millions of DOM nodes before any page is viewed.
    const end = Math.min(pageLimit, nextPage + 49);
    for (; nextPage <= end; nextPage++) {
        const element = document.createElement('section');
        element.className = 'pdf-page';
        const label = (viewer.dataset.pageLabel || 'Page %1$d of %2$d').replace('%1$d', nextPage).replace('%2$d', pdf.numPages);
        element.setAttribute('aria-label', label);
        const state = { number: nextPage, element, label, base: initialBase, canvas: null, task: null,
            visible: false, queued: false, rendering: false, failed: false };
        states.push(state);
        byElement.set(element, state);
        layout(state);
        viewer.insertBefore(element, sentinel);
        observer?.observe(element);
    }
    if (nextPage > pageLimit) {
        observer?.unobserve(sentinel);
        sentinel.remove();
    } else scheduleMorePages();
    if (!observer) onScroll();
}

function onScroll() {
    if (!scrollFrame) scrollFrame = window.requestAnimationFrame(() => {
        scrollFrame = 0;
        updateVisibility();
    });
}

function onResize() {
    if (!resizeFrame) resizeFrame = window.requestAnimationFrame(() => {
        resizeFrame = 0;
        for (const state of states) {
            state.task?.cancel();
            releaseCanvas(state);
            layout(state);
        }
        updateVisibility();
    });
}

async function dispose() {
    if (disposed) return;
    disposed = true;
    observer?.disconnect();
    window.removeEventListener('scroll', onScroll);
    window.removeEventListener('resize', onResize);
    window.cancelAnimationFrame(scrollFrame);
    window.cancelAnimationFrame(resizeFrame);
    window.cancelAnimationFrame(moreFrame);
    queue.length = 0;
    for (const state of states) {
        state.task?.cancel();
        releaseCanvas(state);
    }
    if (loadingTask) await loadingTask.destroy().catch(() => {});
}

async function loadPdf() {
    if (!fileUrl) {
        showError();
        return;
    }

    try {
        loadingTask = pdfjsLib.getDocument({
            url: fileUrl,
            cMapUrl: assetUrl('../pdfjs/cmaps/'),
            cMapPacked: true,
            iccUrl: assetUrl('../pdfjs/iccs/'),
            imageResourcesPath: assetUrl('../pdfjs/image_decoders/'),
            standardFontDataUrl: assetUrl('../pdfjs/standard_fonts/'),
            wasmUrl: assetUrl('../pdfjs/wasm/'),
            isEvalSupported: false,
        });

        pdf = await loadingTask.promise;
        if (disposed) return;
        if (!Number.isSafeInteger(pdf.numPages) || pdf.numPages < 1) throw new Error('Invalid PDF page count');
        const firstPage = await pdf.getPage(1);
        const base = firstPage.getViewport({ scale: 1 });
        firstPage.cleanup();
        if (!Number.isFinite(base.width) || !Number.isFinite(base.height) || !(base.width > 0 && base.height > 0)) throw new Error('Invalid PDF page dimensions');
        if (disposed) return;
        status.hidden = true;
        pageLimit = thumbnailMode ? 1 : (requestedPages > 0 ? Math.min(requestedPages, pdf.numPages) : pdf.numPages);
        initialBase = base;
        sentinel = document.createElement('div');
        sentinel.className = 'pdf-page-loader';
        sentinel.setAttribute('aria-hidden', 'true');
        viewer.appendChild(sentinel);
        appendPages();
        if (typeof IntersectionObserver === 'function') {
            observer = new IntersectionObserver(entries => {
                for (const entry of entries) {
                    if (entry.target === sentinel) {
                        if (entry.isIntersecting) appendPages();
                    } else visibility(byElement.get(entry.target), entry.isIntersecting);
                }
            }, { rootMargin: '300px 0px' });
            for (const state of states) observer.observe(state.element);
            if (nextPage <= pageLimit) observer.observe(sentinel);
        } else {
            // Older browsers retain the same visible-page policy without an observer.
            window.addEventListener('scroll', onScroll, { passive: true });
            updateVisibility();
        }
        window.addEventListener('resize', onResize);
    } catch (error) {
        if (disposed) return;
        console.error('PDF preview error:', error);
        viewer.replaceChildren();
        showError();
        await dispose();
    }
}

window.addEventListener('pagehide', dispose, { once: true });
loadPdf();
