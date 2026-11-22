const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/admin.js'), 'utf8');
function setup(mode) {
    let submit, calls = 0, release;
    const sent = [], timers = [];
    const job = { value: mode === 'expired' ? 'expired-job' : '' };
    const button = { value: 'Start', disabled: false };
    const status = { textContent: '' }, progress = { hidden: true };
    const log = { children: [], replaceChildren() { this.children = []; }, appendChild(item) { this.children.push(item); } };
    const endpoint = '/wp-admin/admin-post.php';
    const form = { action: { value: 'pdf_thumbnail_inserter_regenerate_missing' }, getAttribute: name => { assert.equal(name, 'action'); return endpoint; }, querySelector: () => job, addEventListener(event, callback) { assert.equal(event, 'submit'); submit = callback; } };
    const document = { getElementById: id => ({ 'pti-server-maintenance-form': form, 'pti-server-generate-previews': button, 'pti-server-preview-status': status, 'pti-server-preview-progress': progress, 'pti-server-preview-log': log })[id], createElement: () => ({ textContent: '' }) };
    class FormData { [Symbol.iterator]() { return [['action', 'pdf_thumbnail_inserter_regenerate_missing'], ['job_id', job.value], ['_wpnonce', 'valid']][Symbol.iterator](); } }
    const report = { wordpress_generated: 7, plugin_generated: 2, failed: 1, already_present: 3, errors: ['#1: Broken <PDF>'] };
    const success = complete => ({ ok: true, status: 200, json: async () => ({ success: true, data: { job_id: 'job-one', complete, report } }) });
    const fetch = async (url, options) => {
        calls++;
        assert.equal(url, endpoint, 'The hidden action input must not shadow the submission URL');
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.body.get('pti_ajax'), '1');
        assert.equal(options.body.get('_wpnonce'), 'valid');
        sent.push(options.body.get('job_id'));
        if (mode === 'duplicate') return new Promise(resolve => { release = () => resolve(success(true)); });
        if (mode === 'expired') return { ok: false, status: 409, json: async () => ({ success: false, data: { restart: true, message: 'Expired job' } }) };
        if (mode === 'lock' && calls === 1) return { ok: false, status: 409, json: async () => ({ success: false, data: { message: 'Busy' } }) };
        if (mode === 'invalid-json') return { ok: false, status: 500, json: async () => { throw new SyntaxError('Unexpected HTML'); } };
        if (mode === 'network' && calls === 2) throw new Error('Offline');
        return success(mode === 'lock' || calls >= 3);
    };
    function jQuery(callback) { callback(() => ({ wpColorPicker() {} })); }
    vm.runInNewContext(source, { jQuery, document, window: { fetch, pdfThumbnailServerMaintenance: { labels: { start: 'Start', resume: 'Continue', working: 'Working', error: 'Refresh and retry' } } }, URLSearchParams, FormData, setTimeout: callback => { timers.push(1); callback(); } });
    return { submit: () => submit({ preventDefault() {} }), job, button, status, progress, log, sent, timers, release: () => release(), calls: () => calls };
}
(async () => {
    const normal = setup('success');
    await normal.submit();
    assert.deepEqual(normal.sent, ['', 'job-one', 'job-one'], 'Every next request resumes the server-issued job');
    assert.equal(normal.job.value, '');
    assert.equal(normal.button.disabled, false);
    assert.equal(normal.progress.hidden, true);
    assert.match(normal.status.textContent, /7.*2.*1.*3/);
    assert.equal(normal.log.children[0].textContent, '#1: Broken <PDF>', 'Errors are rendered as text');

    const network = setup('network');
    await network.submit();
    assert.equal(network.job.value, 'job-one', 'Interrupted work retains its continuation token');
    assert.equal(network.button.value, 'Continue');
    await network.submit();
    assert.deepEqual(network.sent, ['', 'job-one', 'job-one']);
    assert.equal(network.button.value, 'Start');

    const expired = setup('expired');
    await expired.submit();
    assert.equal(expired.calls(), 1, 'Expired work does not retry indefinitely');
    assert.equal(expired.job.value, '');
    assert.equal(expired.status.textContent, 'Expired job');
    const busy = setup('lock');
    await busy.submit();
    assert.equal(busy.calls(), 2);
    assert.equal(busy.timers.length, 1, 'A busy job waits before retrying');
    const invalid = setup('invalid-json');
    await invalid.submit();
    assert.equal(invalid.status.textContent, 'Refresh and retry', 'HTML/expired-login responses have a useful recovery message');
    assert.equal(invalid.button.disabled, false);
    const duplicate = setup('duplicate');
    const first = duplicate.submit();
    await duplicate.submit();
    assert.equal(duplicate.calls(), 1, 'Double submits cannot start parallel jobs');
    duplicate.release();
    await first;
    console.log('PASS: server batches, resume after failure, stale tokens, contention, readable errors and duplicate-submit protection.');
})().catch(error => { console.error(error); process.exitCode = 1; });
