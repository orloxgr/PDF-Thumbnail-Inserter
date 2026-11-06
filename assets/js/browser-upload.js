(function () {
    const config = window.pdfThumbnailBrowserUpload || {};
    if (!config.enabled || !config.ajaxUrl || !config.nonce) {
        return;
    }
    const labels = config.labels || {};
    const seen = new Set();
    const pending = [];
    let running = false;
    let renderer;
    let generated = 0;
    let skipped = 0;
    let failed = 0;
    let notice;
    let status;
    let errors;

    function report(message, error) {
        if (!notice) {
            notice = document.createElement('div');
            notice.className = 'notice notice-info pti-browser-upload-notice';
            notice.setAttribute('role', 'status');
            notice.setAttribute('aria-live', 'polite');
            status = document.createElement('p');
            errors = document.createElement('ul');
            notice.append(status, errors);
            const container = document.getElementById('wpbody-content') || document.body;
            container.prepend(notice);
        }
        status.textContent = message;
        if (error) {
            const item = document.createElement('li');
            item.textContent = error;
            errors.appendChild(item);
        }
        notice.classList.toggle('notice-warning', failed > 0);
        notice.classList.toggle('notice-info', failed === 0);
    }

    async function request(body) {
        const response = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body });
        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error(labels.unknownError || 'Could not generate or save the PDF preview.');
        }
        if (!response.ok || !payload.success) {
            throw new Error(payload?.data?.message || labels.unknownError || 'Could not save the PDF preview.');
        }
        return payload.data || {};
    }

    async function processQueue() {
        if (running) {
            return;
        }
        running = true;
        try {
            while (pending.length) {
                const item = pending.shift();
                report(`${labels.working || 'Creating PDF preview:'} ${item.title}`);
                try {
                    // Recheck on the server: it may already have generated a preview.
                    const data = await request(new URLSearchParams({
                        action: 'pdf_thumbnail_inserter_fetch_pdf_data', nonce: config.nonce, attachment_id: item.id,
                    }));
                    if (data.preview_source !== 'placeholder') {
                        skipped += 1;
                        continue;
                    }
                    renderer = renderer || import(config.rendererUrl);
                    const { renderPdfToBlob } = await renderer;
                    const blob = await renderPdfToBlob(data.file_url);
                    const form = new FormData();
                    form.append('action', 'pdf_thumbnail_inserter_save_browser_preview');
                    form.append('nonce', config.nonce);
                    form.append('attachment_id', item.id);
                    form.append('automatic', '1');
                    form.append('preview', blob, `pdf-${item.id}-browser-preview.jpg`);
                    const saved = await request(form);
                    if (saved.skipped) {
                        skipped += 1;
                    } else {
                        generated += 1;
                    }
                    // Fetch the updated PDF model so media dialogs show its saved image.
                    if (item.model && typeof item.model.fetch === 'function') {
                        try {
                            await item.model.fetch();
                        } catch (error) {
                            // Saving succeeded; a media-dialog refresh must not mark it failed.
                        }
                    }
                } catch (error) {
                    failed += 1;
                    report('', `${item.title}: ${error.message || labels.unknownError || 'Preview failed.'}`);
                }
            }
        } finally {
            running = false;
            report(`${labels.complete || 'PDF previews saved:'} ${generated} · ${labels.skipped || 'Existing previews kept:'} ${skipped} · ${labels.failed || 'PDF previews failed:'} ${failed}`);
        }
    }

    function enqueue(id, title, model) {
        id = Number.parseInt(id, 10);
        if (!id || seen.has(id)) {
            return;
        }
        seen.add(id);
        pending.push({ id, title: title || `#${id}`, model });
        processQueue();
    }

    function uploaded(model) {
        if (!model || model.get('uploading') !== false) {
            return;
        }
        const filename = model.get('filename') || model.get('url') || '';
        if (model.get('mime') === 'application/pdf' || /\.pdf(?:[?#]|$)/i.test(filename)) {
            enqueue(model.get('id'), model.get('filename') || model.get('title'), model);
        }
    }

    function init() {
        // Gutenberg can upload a dropped File block directly through REST,
        // without using the media dialog's plupload queue.
        const apiFetch = window.wp?.apiFetch;
        if (apiFetch && typeof apiFetch.use === 'function') {
            apiFetch.use((options, next) => {
                const uploading = String(options.method || 'GET').toUpperCase() === 'POST'
                    && /\/wp\/v2\/media\/?(?:\?|$)/.test(options.path || options.url || '');
                const request = next(options);
                if (!uploading) {
                    return request;
                }
                function completed(data) {
                    if (data?.id && data.mime_type === 'application/pdf') {
                        const model = typeof window.wp?.media?.attachment === 'function' ? window.wp.media.attachment(data.id) : null;
                        enqueue(data.id, data.title?.raw || data.title?.rendered || data.slug, model);
                    }
                }
                return request.then(response => {
                    if (typeof response?.clone === 'function') {
                        response.clone().json().then(completed).catch(() => {});
                    } else {
                        completed(response);
                    }
                    return response;
                });
            });
        }
        const queue = window.wp?.Uploader?.queue;
        if (queue) {
            queue.on('change:uploading', uploaded);
            queue.each(uploaded);
        }
        // Media > Add New uses the legacy plupload uploader and numeric responses.
        if (window.uploader && typeof window.uploader.bind === 'function') {
            window.uploader.bind('FileUploaded', (up, file, response) => {
                if (!/\.pdf$/i.test(file.name || '')) {
                    return;
                }
                const raw = String(response.response || '').trim().replace(/^<pre>(\d+)<\/pre>$/i, '$1');
                if (/^\d+$/.test(raw)) {
                    enqueue(raw, file.name);
                }
            });
        }
    }
    window.jQuery(init);
}());
