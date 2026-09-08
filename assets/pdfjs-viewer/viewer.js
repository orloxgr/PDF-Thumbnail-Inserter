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

if (thumbnailMode) {
    document.body.classList.add('is-thumbnail');
}

function assetUrl(relativePath) {
    return new URL(relativePath, import.meta.url).href;
}

function showError() {
    status.textContent = 'The PDF preview could not be loaded.';
    status.classList.add('is-error');
    status.hidden = false;
}

async function renderPage(pdf, pageNumber) {
    const page = await pdf.getPage(pageNumber);
    const baseViewport = page.getViewport({ scale: 1 });
    const horizontalPadding = window.innerWidth <= 600 ? 12 : 24;
    const availableWidth = Math.max(200, viewer.clientWidth - horizontalPadding);
    const scale = Math.max(0.1, Math.min(2, availableWidth / baseViewport.width));
    const viewport = page.getViewport({ scale });
    const outputScale = Math.min(window.devicePixelRatio || 1, 2);

    const pageElement = document.createElement('section');
    pageElement.className = 'pdf-page';
    pageElement.style.width = `${Math.ceil(viewport.width)}px`;
    pageElement.setAttribute('aria-label', `Page ${pageNumber} of ${pdf.numPages}`);

    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d', { alpha: false });

    canvas.width = Math.max(1, Math.floor(viewport.width * outputScale));
    canvas.height = Math.max(1, Math.floor(viewport.height * outputScale));
    pageElement.appendChild(canvas);
    viewer.appendChild(pageElement);

    const transform = outputScale === 1
        ? null
        : [outputScale, 0, 0, outputScale, 0, 0];

    await page.render({
        canvasContext: context,
        viewport,
        transform,
        background: '#ffffff',
    }).promise;
}

async function loadPdf() {
    if (!fileUrl) {
        showError();
        return;
    }

    try {
        const loadingTask = pdfjsLib.getDocument({
            url: fileUrl,
            cMapUrl: assetUrl('../pdfjs/cmaps/'),
            cMapPacked: true,
            iccUrl: assetUrl('../pdfjs/iccs/'),
            standardFontDataUrl: assetUrl('../pdfjs/standard_fonts/'),
            wasmUrl: assetUrl('../pdfjs/wasm/'),
            isEvalSupported: false,
        });

        const pdf = await loadingTask.promise;

        status.hidden = true;

        const pageLimit = requestedPages > 0 ? Math.min(requestedPages, pdf.numPages) : pdf.numPages;
        for (let pageNumber = 1; pageNumber <= pageLimit; pageNumber += 1) {
            await renderPage(pdf, pageNumber);
        }
    } catch (error) {
        console.error('PDF preview error:', error);
        viewer.replaceChildren();
        showError();
    }
}

loadPdf();
