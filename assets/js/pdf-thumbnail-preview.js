import * as pdfjsLib from '../pdfjs/pdf.min.js';

pdfjsLib.GlobalWorkerOptions.workerSrc = new URL(
    '../pdfjs/pdf.worker.min.js',
    import.meta.url
).href;

function assetUrl(relativePath) {
    return new URL(relativePath, import.meta.url).href;
}

async function renderPreview(element) {
    if (!element || element.dataset.ptiRendered === '1') {
        return;
    }

    const fileUrl = element.dataset.pdfUrl || '';
    const canvas = element.querySelector('canvas');
    const status = element.querySelector('.pti-pdf-canvas-preview__status');

    if (!fileUrl || !canvas) {
        return;
    }

    element.dataset.ptiRendered = '1';
    let loadingTask;

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

        const pdf = await loadingTask.promise;
        const page = await pdf.getPage(1);
        const baseViewport = page.getViewport({ scale: 1 });
        const targetWidth = Math.max(240, Math.floor(element.clientWidth || element.getBoundingClientRect().width || 640));
        const scale = Math.max(0.1, Math.min(2, targetWidth / baseViewport.width));
        const viewport = page.getViewport({ scale });
        const outputScale = Math.min(window.devicePixelRatio || 1, 2);
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

function initPreviewRendering() {
    const previews = Array.from(document.querySelectorAll('.pti-pdf-canvas-preview[data-pdf-url]'));
    if (!previews.length) {
        return;
    }

    if (!('IntersectionObserver' in window)) {
        previews.forEach(renderPreview);
        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }

                observer.unobserve(entry.target);
                renderPreview(entry.target);
            });
        },
        { rootMargin: '200px 0px' }
    );

    previews.forEach((preview) => observer.observe(preview));
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPreviewRendering);
} else {
    initPreviewRendering();
}
