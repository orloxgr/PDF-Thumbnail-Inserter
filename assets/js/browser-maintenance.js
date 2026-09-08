import * as pdfjsLib from '../pdfjs/pdf.min.js';

pdfjsLib.GlobalWorkerOptions.workerSrc = new URL(
    '../pdfjs/pdf.worker.min.js',
    import.meta.url
).href;

const config = window.pdfThumbnailBrowserMaintenance || {};
const labels = config.labels || {};

function assetUrl(relativePath) {
    return new URL(relativePath, import.meta.url).href;
}

function label(key, fallback) {
    return labels[key] || fallback;
}

function format(template, ...values) {
    return values.reduce(
        (message, value, index) => message.split(`%${index + 1}$d`).join(value).split(`%${index + 1}$s`).join(value),
        template
    );
}

async function postForm(body) {
    const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        },
        body: new URLSearchParams(body),
    });
    const payload = await readJsonResponse(response);

    if (!response.ok || !payload.success) {
        const message = payload?.data?.message || label('unknownError', 'Unknown error.');
        throw new Error(message);
    }

    return payload.data || {};
}

async function postUpload(formData) {
    const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: formData,
    });
    const payload = await readJsonResponse(response);

    if (!response.ok || !payload.success) {
        const message = payload?.data?.message || label('unknownError', 'Unknown error.');
        throw new Error(message);
    }

    return payload.data || {};
}

async function readJsonResponse(response) {
    try {
        return await response.json();
    } catch (error) {
        throw new Error(label('unknownError', 'Unknown error.'));
    }
}

async function renderPdfToBlob(fileUrl) {
    const loadingTask = pdfjsLib.getDocument({
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
    const targetWidth = 1200;
    const scale = Math.max(0.1, targetWidth / baseViewport.width);
    const viewport = page.getViewport({ scale });
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d', { alpha: false });

    canvas.width = Math.max(1, Math.floor(viewport.width));
    canvas.height = Math.max(1, Math.floor(viewport.height));

    await page.render({
        canvasContext: context,
        viewport,
        background: '#ffffff',
    }).promise;

    const blob = await new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => {
                if (blob) {
                    resolve(blob);
                    return;
                }

                reject(new Error(label('renderError', 'Could not render the PDF in this browser.')));
            },
            'image/jpeg',
            0.86
        );
    });

    try {
        if (typeof pdf.destroy === 'function') {
            await pdf.destroy();
        } else if (typeof pdf.cleanup === 'function') {
            pdf.cleanup();
        }
    } catch (error) {
        // Preview rendering has already succeeded; cleanup support differs across PDF.js builds.
    }

    return blob;
}

function appendLog(logElement, message) {
    if (!logElement) {
        return;
    }

    const item = document.createElement('li');
    item.textContent = message;
    logElement.appendChild(item);
}

function initBrowserMaintenance() {
    const button = document.getElementById('pti-browser-generate-previews');
    const progress = document.getElementById('pti-browser-preview-progress');
    const status = document.getElementById('pti-browser-preview-status');
    const log = document.getElementById('pti-browser-preview-log');

    if (!button || !config.ajaxUrl || !config.nonce) {
        return;
    }

    button.addEventListener('click', async () => {
        const defaultText = label('buttonDefault', 'Generate Missing Previews in Browser');
        let generated = 0;
        let skipped = 0;
        let failed = 0;

        button.disabled = true;
        button.textContent = label('buttonWorking', 'Generating...');
        if (status) {
            status.textContent = label('checking', 'Checking PDFs without previews...');
        }
        if (log) {
            log.innerHTML = '';
        }
        if (progress) {
            progress.hidden = false;
            progress.value = 0;
            progress.max = 1;
        }

        try {
            const list = await postForm({
                action: 'pdf_thumbnail_inserter_list_missing_previews',
                nonce: config.nonce,
            });
            const items = Array.isArray(list.items) ? list.items : [];
            skipped += Number(list.skipped || 0);

            if (!items.length) {
                if (status) {
                    status.textContent = label('none', 'No missing PDF previews were found.');
                }
                return;
            }

            if (progress) {
                progress.max = items.length;
            }

            for (let index = 0; index < items.length; index += 1) {
                const item = items[index];
                const current = index + 1;
                const title = item.title || `#${item.id}`;

                try {
                    if (status) {
                        status.textContent = format(
                            label('rendering', 'Rendering %1$d of %2$d: %3$s'),
                            current,
                            items.length,
                            title
                        );
                    }
                    const blob = await renderPdfToBlob(item.file_url);

                    if (status) {
                        status.textContent = format(
                            label('saving', 'Saving %1$d of %2$d: %3$s'),
                            current,
                            items.length,
                            title
                        );
                    }

                    const formData = new FormData();
                    formData.append('action', 'pdf_thumbnail_inserter_save_browser_preview');
                    formData.append('nonce', config.nonce);
                    formData.append('attachment_id', item.id);
                    formData.append('preview', blob, `pdf-${item.id}-browser-preview.jpg`);

                    const saved = await postUpload(formData);
                    if (saved.skipped) {
                        skipped += 1;
                    } else {
                        generated += 1;
                    }
                } catch (error) {
                    failed += 1;
                    appendLog(log, `${title}: ${error.message || label('unknownError', 'Unknown error.')}`);
                } finally {
                    if (progress) {
                        progress.value = current;
                    }
                }
            }

            if (status) {
                status.textContent = format(
                    label('complete', 'Browser previews complete: %1$d generated, %2$d skipped, %3$d failed.'),
                    generated,
                    skipped,
                    failed
                );
            }
        } catch (error) {
            if (status) {
                status.textContent = `${label('failed', 'Browser preview generation failed.')} ${
                    error.message || label('unknownError', 'Unknown error.')
                }`;
            }
        } finally {
            button.disabled = false;
            button.textContent = defaultText;
            if (progress && Number(progress.value) >= Number(progress.max)) {
                progress.hidden = true;
            }
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBrowserMaintenance);
} else {
    initBrowserMaintenance();
}
