# Executed audit evidence

The current 1.11.50 follow-up is in [warnings-1.11.50/](warnings-1.11.50/): full native/HTTP/Node reruns, measured before/after lookup, real locale switches, source-contract negative probe and exact-ZIP lifecycle/upgrade. Its clean tool reports are in [plugin-check-1.11.50.json](../plugin-check-1.11.50.json) and [phpstan-1.11.50.json](../phpstan-1.11.50.json). The records outside that folder retain the historical 1.11.49 checkpoint.

These records and screenshots contain synthetic fixtures from disposable local sites. They are development evidence and are excluded from the installable ZIP. Repository paths in JSON messages are normalized to `<repository>`; credentials, session tokens, nonces and commercial integration files are not included.

- `readiness-expanded-*.json`: actual WordPress API/server regressions, WP 6.0/PHP 7.4.27 and WP 7.1.5/PHP 8.2.30, without/with real Imagick. Latest totals are 268/282 and 269/283, respectively. Only the latest site has Elementor/Pro 4.4.1 installed; its extra assertion covers an actual null current-document state.
- `server-maintenance-native*.json`: real resumable generation, failures, contention, bounded queries, concurrent insertion/deletion between batches, replay, write rejection and empty-library handling; 20 assertions per native environment.
- `server-maintenance-http.json`: 35 actual authenticated form/AJAX/multipart HTTP assertions against the owned WP 7.1.5/PHP 8.2 server with Imagick. This is not a browser automation test.
- `lifecycle-*.json`: fresh WordPress 7.1.5/PHP 8.2.30/SQLite site, 47 assertions through fresh installation, legacy migration, deactivation, actual uninstall and reinstallation. The published-baseline record adds 14 assertions after installing the immutable public v1.11.48 artifact. The final 1.11.49 ZIP upgrade passes; upgraded and cleanup phase records are included.
- `classic-editor-live.png`: resulting WP 6.0 page after Visual multiple-PDF insertion and Text single-PDF insertion, save/reload and successful PDF.js painting.
- `viewer-two-pages-browser.png`: the second rendered page of a real synthetic two-page PDF in the local top-level viewer. It is not evidence of large-document resource bounds.
- `responsive-css-browser.json`, `visual-css-browser.json`: computed CSS from actual plugin markup and its stylesheet in srcdoc viewports. They verify the stylesheet in isolation, rather than claiming multiple real theme/device sessions.
- `readonly-listing-browser.*` and `readonly-render-server.json`: actual TheGov/WGL listing with Imagick available; saved preview/real featured-image priority and a painted browser fallback for PDF 450. The server record shows no generated files or preview/error markers. The core `filesize` field comes from the upload and is not a generated image.

The WordPress 7.1 visual Gutenberg blob-iframe limitation remains unverified under the user's accepted best-effort exception. See the final audit for exact layer-specific coverage and remaining environment limits. These artifacts do not independently approve a release or certify a clean Plugin Check/PHPStan result.

- Final candidate records: `final-tests.json`, `syntax-final.json`, `compatibility-final.json`, `deletion-concurrency-*.json`, `upload-http-*.json`, `server-maintenance-*.json` and lifecycle upgraded/cleanup. Viewer 100-page/edge records precede the final placeholder batching adjustment, which is explicitly behaviorally tested. `published-core-rejection-proof.json` is a before-fix probe against the immutable public release, not the new candidate.
