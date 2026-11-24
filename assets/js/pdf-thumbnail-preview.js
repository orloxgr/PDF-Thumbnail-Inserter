import { getPdfLibrary } from './pdf-runtime.js';

function assetUrl(relativePath) {
    return new URL(relativePath, import.meta.url).href;
}

// Bound simultaneous PDF decoders, including dynamically inserted editor previews.
const pendingPreviews = [];
const scheduledPreviews = new WeakSet();
let activePreviews = 0;

function queuePreview(element) {
    if (!element || element.dataset.ptiRendered === '1' || scheduledPreviews.has(element)) {
        return;
    }
    scheduledPreviews.add(element);
    pendingPreviews.push(element);
    drainPreviews();
}

function drainPreviews() {
    while (activePreviews < 2 && pendingPreviews.length) {
        const element = pendingPreviews.shift();
        activePreviews += 1;
        renderPreview(element).finally(() => {
            activePreviews -= 1;
            drainPreviews();
        });
    }
}

async function renderPreview(element) {
    if (!element || element.dataset.ptiRendered === '1') {
        return;
    }

    const fileUrl = element.dataset.pdfUrl || '';
    let canvas = element.querySelector('canvas');
    const status = element.querySelector('.pti-pdf-canvas-preview__status');

    if (!fileUrl) {
        return;
    }

    // Elementor Classic sanitizes image-control HTML with wp_kses_post(),
    // which retains our data-bearing span but removes the canvas element.
    if (!canvas) {
        canvas = document.createElement('canvas');
        canvas.setAttribute('aria-hidden', 'true');
        element.prepend(canvas);
    }

    element.dataset.ptiRendered = '1';
    let loadingTask;

    try {
        const pdfjsLib = await getPdfLibrary();
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

        const pdf = await loadingTask.promise;
        const page = await pdf.getPage(1);
        const baseViewport = page.getViewport({ scale: 1 });
        const targetWidth = Math.max(240, Math.floor(element.clientWidth || element.getBoundingClientRect().width || 640));
        const outputScale = Math.min(window.devicePixelRatio || 1, 2);
        if (!Number.isFinite(baseViewport.width) || !Number.isFinite(baseViewport.height) || baseViewport.width <= 0 || baseViewport.height <= 0) {
            throw new Error('Invalid PDF page dimensions.');
        }
        const scale = Math.min(2, targetWidth / baseViewport.width, 1200 / (baseViewport.width * outputScale), 2000 / (baseViewport.height * outputScale));
        const viewport = page.getViewport({ scale });
        const context = canvas.getContext('2d', { alpha: false });

        canvas.width = Math.max(1, Math.floor(viewport.width * outputScale));
        canvas.height = Math.max(1, Math.floor(viewport.height * outputScale));
        canvas.style.width = `${Math.ceil(viewport.width)}px`;
        canvas.style.height = `${Math.ceil(viewport.height)}px`;

        await page.render({
            canvasContext: context,
            viewport,
            transform: outputScale === 1 ? null : [outputScale, 0, 0, outputScale, 0, 0],
            background: '#ffffff',
        }).promise;

        element.classList.add('is-loaded');
        if (status) {
            status.hidden = true;
        }
    } catch (error) {
        console.error('PDF thumbnail preview error:', error);
        element.classList.add('is-error');
        if (status) {
            status.textContent = 'PDF preview unavailable';
            status.hidden = false;
        }
    } finally {
        // Release the worker and document data even when loading/rendering fails.
        // The painted canvas remains usable after the loading task is destroyed.
        if (loadingTask) {
            await loadingTask.destroy().catch(() => {});
        }
    }
}

function initPreviewRendering(root = document) {
    const selector = '.pti-pdf-canvas-preview[data-pdf-url]';
    const observed = new WeakSet();
    const observer = 'IntersectionObserver' in window ? new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }

                observer.unobserve(entry.target);
                queuePreview(entry.target);
            });
        },
        { rootMargin: '200px 0px' }
    ) : null;
    function discover(node) {
        if (!node || !node.querySelectorAll) return;
        const previews = Array.from(node.querySelectorAll(selector));
        if (node.matches && node.matches(selector)) previews.unshift(node);
        previews.forEach(preview => {
            if (observed.has(preview) || preview.dataset.ptiRendered === '1') return;
            observed.add(preview);
            if (observer) observer.observe(preview);
            else queuePreview(preview);
        });
    }
    discover(root);
    return { discover, disconnect: () => observer?.disconnect() };
}

// ServerSideRender replaces its DOM after each editor change. Observe that block's
// own root, including when it lives in the iframe, and release observers on unmount.
export function watchPreviewRendering(root) {
    const renderer = initPreviewRendering(root);
    const observer = new MutationObserver(records => {
        records.forEach(record => record.addedNodes.forEach(renderer.discover));
    });
    observer.observe(root, { childList: true, subtree: true });
    return () => {
        observer.disconnect();
        renderer.disconnect();
    };
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initPreviewRendering());
} else {
    initPreviewRendering();
}
