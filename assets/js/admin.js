jQuery(function ($) {
    $('.pti-color-field').wpColorPicker();

    const form = document.getElementById('pti-server-maintenance-form');
    const button = document.getElementById('pti-server-generate-previews');
    if (!form || !button || !window.fetch) return;
    const config = window.pdfThumbnailServerMaintenance || {};
    const labels = config.labels || {};
    const jobInput = form.querySelector('[name="job_id"]');
    if (!jobInput) return;
    const progress = document.getElementById('pti-server-preview-progress');
    const status = document.getElementById('pti-server-preview-status');
    const log = document.getElementById('pti-server-preview-log');
    let running = false;

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (running) return;
        running = true;
        button.disabled = true;
        button.value = labels.working || 'Generating...';
        if (status) status.textContent = labels.working || 'Generating...';
        if (progress) progress.hidden = false;
        if (log) log.replaceChildren();
        try {
            let complete = false;
            let lockRetries = 0;
            while (!complete) {
                const body = new URLSearchParams(new FormData(form));
                body.set('pti_ajax', '1');
                // The hidden WordPress `action` field shadows form.action in real browsers.
                const response = await window.fetch(form.getAttribute('action'), { method: 'POST', credentials: 'same-origin', body });
                let payload;
                try { payload = await response.json(); }
                catch (error) { throw new Error(labels.error || 'Could not process server previews. Refresh the page and try again.'); }
                if (!response.ok || !payload.success) {
                    // A second tab may briefly hold this administrator's job lock.
                    if (response.status === 409 && !payload.data?.restart && lockRetries++ < 3) {
                        await new Promise(resolve => setTimeout(resolve, 1000));
                        continue;
                    }
                    if (payload.data?.restart) jobInput.value = '';
                    throw new Error(payload.data?.message || labels.error);
                }
                lockRetries = 0;
                const data = payload.data;
                if (!data || typeof data.job_id !== 'string' || typeof data.complete !== 'boolean' || !data.report) {
                    throw new Error(labels.error);
                }
                complete = data.complete;
                jobInput.value = complete ? '' : data.job_id;
                const report = data.report;
                let message = labels.progress || 'WordPress previews: %1$d · Plugin previews: %2$d · Failed: %3$d · Already present: %4$d';
                [report.wordpress_generated, report.plugin_generated, report.failed, report.already_present].forEach((count, index) => {
                    message = message.split(`%${index + 1}$d`).join(Number(count) || 0);
                });
                if (status) status.textContent = message;
                if (log) {
                    log.replaceChildren();
                    (Array.isArray(report.errors) ? report.errors : []).forEach(error => {
                        const item = document.createElement('li');
                        item.textContent = error;
                        log.appendChild(item);
                    });
                }
                if (!complete && data.retry_after) await new Promise(resolve => setTimeout(resolve, 1000));
            }
        } catch (error) {
            if (status) status.textContent = error.message || labels.error || 'Could not process server previews. Refresh the page and try again.';
        } finally {
            running = false;
            button.disabled = false;
            button.value = jobInput.value ? (labels.resume || 'Continue Generating Previews on Server') : (labels.start || 'Generate Missing Previews on Server');
            if (progress) progress.hidden = true;
        }
    });
});
