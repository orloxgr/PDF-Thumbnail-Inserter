import * as pdfjsLib from '../pdfjs/pdf.min.js';

pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('../pdfjs/pdf.worker.min.js', import.meta.url).href;

// Shared by automatic uploads and the manual maintenance tool.
export async function renderPdfToBlob(fileUrl) {
    const assetUrl = path => new URL(path, import.meta.url).href;
    const task = pdfjsLib.getDocument({
        url: fileUrl,
        cMapUrl: assetUrl('../pdfjs/cmaps/'),
        cMapPacked: true,
        iccUrl: assetUrl('../pdfjs/iccs/'),
        standardFontDataUrl: assetUrl('../pdfjs/standard_fonts/'),
        wasmUrl: assetUrl('../pdfjs/wasm/'),
        isEvalSupported: false,
    });
    let canvas;
    try {
        const pdf = await task.promise;
        const page = await pdf.getPage(1);
        const base = page.getViewport({ scale: 1 });
        const viewport = page.getViewport({ scale: Math.min(1200 / base.width, 2000 / base.height) });
        canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.floor(viewport.width));
        canvas.height = Math.max(1, Math.floor(viewport.height));
        const context = canvas.getContext('2d', { alpha: false });
        if (!context) {
            throw new Error('Could not create the PDF preview canvas.');
        }
        await page.render({ canvasContext: context, viewport, background: '#ffffff' }).promise;
        return await new Promise((resolve, reject) => {
            canvas.toBlob(blob => blob ? resolve(blob) : reject(new Error('Could not render the PDF preview.')), 'image/jpeg', 0.86);
        });
    } finally {
        await task.destroy().catch(() => {});
        if (canvas) {
            canvas.width = canvas.height = 0;
        }
    }
}
