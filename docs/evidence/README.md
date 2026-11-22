# Executed audit evidence

These records and screenshots contain synthetic fixtures from disposable local sites. They are development evidence and are excluded from the installable ZIP. Repository paths in JSON messages are normalized to `<repository>`; credentials, session tokens, nonces and commercial integration files are not included.

- `readiness-expanded-*.json`: actual WordPress API/server regressions, WP 6.0/PHP 7.4.27 and WP 7.1.5/PHP 8.2.30, without/with real Imagick. Latest totals are 266/274 and 267/275, respectively. Only the latest site has Elementor/Pro 4.4.1 installed; its extra assertion covers an actual null current-document state.
- `server-maintenance-native*.json`: real resumable generation, failures, contention, bounded queries, concurrent insertion/deletion between batches, replay, write rejection and empty-library handling; 20 assertions per native environment.
- `server-maintenance-http.json`: 35 actual authenticated form/AJAX/multipart HTTP assertions against the owned WP 7.1.5/PHP 8.2 server with Imagick. This is not a browser automation test.
- `classic-editor-live.png`: resulting WP 6.0 page after Visual multiple-PDF insertion and Text single-PDF insertion, save/reload and successful PDF.js painting.
- `viewer-two-pages-browser.png`: the second rendered page of a real synthetic two-page PDF in the local top-level viewer. It is not evidence of large-document resource bounds.
- `responsive-css-browser.json`, `visual-css-browser.json`: computed CSS from actual plugin markup and its stylesheet in srcdoc viewports. They verify the stylesheet in isolation, rather than claiming multiple real theme/device sessions.
- `readonly-listing-browser.*` and `readonly-render-server.json`: actual TheGov/WGL listing with Imagick available; saved preview/real featured-image priority and a painted browser fallback for PDF 450. The server record shows no generated files or preview/error markers. The core `filesize` field comes from the upload and is not a generated image.

The WordPress 7.1 visual Gutenberg blob-iframe limitation remains unverified under the user's accepted best-effort exception. Other incomplete requirements remain listed in the working audit. These artifacts do not independently approve a release or certify a clean Plugin Check/PHPStan result.
