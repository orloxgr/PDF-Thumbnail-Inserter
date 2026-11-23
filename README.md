# PDF Thumbnail Inserter

**Version:** 1.11.51
**Author:** Byron Iniotakis
**License:** GPL-3.0-or-later

## Description

PDF Thumbnail Inserter generates and reuses thumbnail previews for PDF attachments and lets you insert them with a shortcode, a Gutenberg block, or a Classic Editor media button.

The plugin first reuses any preview WordPress already provides. On PDF upload, it attempts to generate a missing preview from the first page using Imagick. Administrators can generate previews for older PDFs through server maintenance or in the browser with the bundled PDF.js runtime. Generated upload/maintenance previews are stored for reuse. Normal frontend rendering reuses existing images or displays the configured cover/browser canvas fallback, so visiting a listing does not start a batch of server-side PDF conversions.

It supports rendering one PDF or multiple PDFs in a responsive grid with separate column controls for widescreen, desktop, tablet, and mobile.

## Features

- Automatically creates missing PDF previews on upload.
- Reuses existing WordPress PDF previews when available.
- Shortcode support for single or multiple PDFs.
- Responsive grid layout with separate widescreen, desktop, tablet, and mobile column counts.
- Saved column defaults for new inserts, without column prompts in the Classic Editor.
- Optional title covers for PDFs without a preview image, using the existing plugin colors.
- Gutenberg block support with multi-select PDF picker.
- Classic Editor insert button with multi-select PDF picker.
- Link modes for the thumbnail card:
  - PDF file
  - Attachment page
  - Custom URL
  - No link
- Media Library attachment field with a copyable shortcode.
- Settings page with color picker controls.
- Maintenance tool to generate missing previews for existing PDFs through the server or through the browser with bundled PDF.js.
- Optional automatic browser generation immediately after uploading a PDF without a server preview, using the same renderer and save endpoint as manual maintenance.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Imagick for automatic server-side previews when WordPress does not already provide one
- A modern browser for the browser-based PDF.js maintenance fallback

## Shortcode

### Basic usage

```text
[pdf_thumbnail_inserter id="123"]
```

### Multiple PDFs in a grid

```text
[pdf_thumbnail_inserter ids="123,124,125,126" columns_widescreen="4" columns_desktop="3" columns_laptop="2" columns_mobile="1"]
```

### Common attributes

```text
[pdf_thumbnail_inserter id="123" link_to="file" show_title="yes" show_button="yes"]
[pdf_thumbnail_inserter id="123" link_to="attachment" new_tab="no"]
[pdf_thumbnail_inserter id="123" link_to="custom" url="https://example.com/custom-page/"]
[pdf_thumbnail_inserter id="123" link_to="none" show_button="no"]
[pdf_thumbnail_inserter ids="123,124,125" columns_desktop="3" columns_laptop="2" columns_mobile="1"]
```

Supported attributes:

- `id`
- `ids`
- `thumbnail`
- `title`
- `url`
- `link_to` (`file`, `attachment`, `custom`, `none`)
- `show_title` (`yes`, `no`)
- `show_button` (`yes`, `no`)
- `button_text`
- `new_tab` (`yes`, `no`)
- `size` (`thumbnail`, `medium`, `large`, `full`)
- `thumbnail_width`
- `title_color`
- `title_size`
- `button_color`
- `button_hover_color`
- `button_text_color`
- `button_text_size`
- `columns_desktop` (`1` to `6`)
- `columns_widescreen` (`1` to `6`; when omitted, follows `columns_desktop`)
- `columns_laptop` (`1` to `6`)
- `columns_mobile` (`1` to `6`)
- `class`
- `rel`

## Notes

- Enable **Automatically create missing PDF previews in my browser after upload** in **Settings > PDF Thumbnail Settings** to generate and save a JPEG of the first page after upload. This covers editor media dialogs, Gutenberg REST uploads, and **Media > Add New**. Keep the upload tab open until the progress notice finishes. Existing previews are preserved, failures do not stop later files, and the manual maintenance button remains available for older PDFs. The option is off by default.
- In **Settings > PDF Thumbnail Settings**, set the widescreen (1600px+), desktop (1181–1599px), tablet (768–1180px), and mobile (up to 767px) columns once. New Classic Editor inserts and new Gutenberg blocks save those values automatically. Existing content keeps its explicit columns, or the original 3/2/1 defaults when omitted.
- The `columns_laptop` attribute remains the tablet setting for compatibility with existing content.
- Enable **Fallback cover for PDFs without previews** to show a cover with the PDF title when a PDF exists but has no preview image. This works in PDF cards and listing previews; posts without a PDF are unchanged. A generated, WordPress, or custom preview image always takes priority. The small document label is editable; accent and text reuse the existing button and title colors. Fake covers do not count as real previews, so browser preview generation can still run.
- Configure **Additional listing pages** with this site's page IDs or slugs. Category archives and the WordPress posts page are included automatically. The initial `nea` value preserves existing installations.
- If Imagick or WordPress PDF preview support is unavailable, the plugin still works; administrators can generate missing previews from the settings page with the browser-based PDF.js fallback.
- The server maintenance button is disabled automatically when the server cannot generate PDF previews, but the browser PDF.js maintenance button remains available.
- Server maintenance saves progress in small batches. Keep settings open to continue automatically, or return to resume an interrupted job. Without JavaScript, submit **Continue Generating Previews on Server** after each batch. Files uploaded after a job starts are handled by the next job; individual failures do not stop the remaining files.
- Generated preview images are saved as child image attachments of the original PDF.
- `thumbnail`, `title`, and `url` overrides are intended for single-PDF usage. When multiple PDFs are rendered, the plugin ignores single-item overrides that would otherwise apply the same custom data to every card.
- `[pdf_thumbnail]` remains available as a legacy alias for existing content; new inserts use `[pdf_thumbnail_inserter]`.
- Deactivation and uninstall preserve settings, PDFs, generated image attachments and stored post content. Reinstallation reuses those previews and settings. Deleting an original PDF while the plugin is active removes its own generated child preview; unrelated media are preserved.

## Development checks

```sh
php -l pdf-thumbnail-inserter.php
python scripts/check_syntax.py
php tests/preview-regression.php
php tests/security-regression.php
php tests/readiness-regression.php
node tests/editor-regression.cjs
node tests/browser-upload-regression.cjs
node tests/browser-maintenance-regression.cjs
node tests/preview-renderer-regression.cjs
node tests/server-maintenance-regression.cjs
node tests/viewer-regression.cjs
```

`tests/wordpress-integration.php` installs the ZIP and checks real WordPress APIs on an explicitly disposable site. It requires `PTI_TEST_DISPOSABLE=true`; see its header for the install and verification phases. The test creates attachments and changes settings, so use a fresh test database.

`tests/readiness-wordpress.php` exercises actual settings, block rendering, preview publication/rollback, local-file failures and optional Imagick/Ghostscript conversion. Its header describes the explicit disposable-site constants; run it directly with PHP so frontend filters are exercised. The site must have environment type `local` and cron disabled. `tests/preview-lock-concurrency.php` and `tests/preview-discovery-queries.php` take the disposable WordPress root as a CLI argument and require the environment variable `PTI_TEST_DISPOSABLE=true`. These checks create and remove synthetic fixtures and must never run against a live site.

`tests/server-maintenance-wordpress.php` tests real resumable jobs, failures, concurrent deletion/new uploads, lock contention and bounded discovery on a disposable site with Imagick. `tests/server-maintenance-http.php` requires an owned loopback HTTP server with Imagick; it tests actual authenticated form/AJAX requests, CSRF and object permissions, multipart image saving/replay and media-model refresh. Both take the disposable WordPress root as their argument and require `PTI_TEST_DISPOSABLE=true`; fixtures and sessions are removed afterward.

`tests/deletion-concurrency.php` checks actual cross-process writer/deletion contention, ownership, third-party deletion vetoes and stale publication paths. `tests/upload-http.php` checks actual REST PDF uploads and conditional Media Add New assets with automatic previews on and off. Both require the same disposable-site root and environment variable; the HTTP test additionally needs its owned loopback server. `tests/viewer-regression.cjs` covers lazy page batches, viewport rendering, pixel bounds, cancellation and worker disposal using explicit browser/PDF mocks.

With the same disposable-site root and `PTI_TEST_DISPOSABLE=true`, `tests/reference-lookup-wordpress.php` checks cold/warm query budgets and filename cache invalidation; `tests/warnings-wordpress.php` verifies real excerpt hooks, routing isolation and literal SQL patterns. `tests/translations-wordpress.php` uses fresh processes and temporary MO fixtures to verify language-pack priority, local fallback, missing translations and repeated locale switching. It refuses to replace existing translation files. `tests/public-resources-http.php` requires the owned loopback server and checks actual anonymous viewer/cover responses, malformed inputs and absence of data mutations. The pinned PHPStan configuration and source-backed runtime contracts are in `tools/phpstan/`; they add no ignores or baseline and are excluded from the ZIP.

`tests/lifecycle-wordpress.php` must start on a **fresh disposable local site without this plugin or its options**. Set `PTI_TEST_DISPOSABLE=true`. Each phase is a separate PHP process: `php tests/lifecycle-wordpress.php /path/to/wp PHASE`. Run `init`, install/activate the ZIP with WordPress/WP-CLI, run `fresh` and `legacy`, deactivate the plugin, run `inactive`, uninstall through WordPress (including removal of its files), run `uninstalled`, reinstall/activate the ZIP, and run `reinstalled`. The synthetic PDF, child preview, settings and stored content are retained for an upgrade check. To establish an actual published starting version, install that release ZIP and run `baseline ZIP_PATH`; then install/activate the new version and run `upgraded VERSION` followed by `cleanup VERSION`. Never run these commands on production. An additional HTTP server or browser is not required for these native lifecycle checks.

`python scripts/verify_pdfjs.py` checks the pinned PDF.js npm artifact integrity, compares every bundled library/resource/notice against its documented upstream source and queries the npm advisory API for that exact version. It downloads public metadata/artifacts only; it does not upgrade runtime files. Corresponding source/build links and supplemental QuickJS notice provenance are recorded in `assets/pdfjs/VERSION.txt` and the **Source Code and Build Instructions** section of `readme.txt`.

Build the installable ZIP with `python scripts/package.py`. This preserves the existing `pdf-thumbnail-inserter/` archive layout and includes only plugin root files and Git-tracked assets/blocks. The script checks version consistency, archive integrity, its runtime-only manifest, and every packaged byte against the source. Output goes to `dist/`; tests, development scripts, agent skills, Git metadata and local archives are excluded. Stage new runtime assets before building.

The [readiness audit](https://github.com/orloxgr/PDF-Thumbnail-Inserter/blob/main/docs/readiness-audit.md) records the complete requirement matrix, fixes, tool findings and verification limits.

## Changelog

### 1.11.51

- Add direct public links to this release's plugin source, packaging instructions, exact PDF.js source/build tools and supplemental license-notice provenance in the WordPress.org readme.
- Prepare a document-preview directory icon at 128×128 and 256×256. Directory artwork and its upload instructions live in `.wordpress-org/`, outside the installable ZIP.

### 1.11.50

- Route public viewer/cover parameters through declared WordPress query variables, preserving old URLs and input validation without raw GET access or session-dependent nonces.
- Use core excerpt generation to preserve existing excerpt customization; use modern just-in-time translation loading with a tested WordPress 6.0 fallback and locale switching.
- Batch and cache legacy filename/token searches, prime candidates together and invalidate matches/misses when attachments change. Measured filename-only lookup: 15 to 5 cold queries, 6 to 1 metadata searches, and 12 to 0 warm queries on WordPress 6.0.
- Simplify temporary metadata deferral and established capability checks while preserving rollback and initialization guards. Correct source-verified analysis contracts instead of suppressing findings.
- Add native and real HTTP regressions for all warning corrections. Detailed clean Plugin Check/PHPStan results and verification limits are recorded in the repository audit.

### 1.11.49

- Make preview publication atomic and serialize server/browser generation with PDF deletion; retain existing images and clean failed new files.
- Repair malformed settings/IDs, missing or corrupt previews, Greek URLs and literal filenames containing glob characters.
- Bound and resume server maintenance, batch preview discovery and keep ordinary frontend rendering free of server conversion writes.
- Correct PDF/DOC conversion settings, block defaults/classes/accessibility and Elementor/WGL current-post behavior.
- Render viewer pages near the viewport, bound canvas pixels and release offscreen canvases/workers; load the shared PDF.js library only when needed.
- Restore upstream dependency license notices and add real WordPress, HTTP, concurrency, lifecycle and targeted browser regressions. See the repository's readiness audit for exact coverage and limitations.

### 1.11.48
- Restricts local PDF preview lookup and the bundled viewer to readable PDF files inside uploads; rejects traversal, foreign origins and symlinks outside uploads.
- Preserves complete PDF URLs with nested query parameters in viewer links and leaves unsupported file-block embeds unchanged.
- Deletes generated preview images only when their type, attachment parent and ownership metadata match the original PDF; installs browser replacements before cleaning up old images.
- Scans missing browser previews in batches of 100 without triggering server rendering during discovery. Legacy unpaged AJAX discovery remains available.
- Releases the PDF.js viewer worker on success and failure, restores the upload hook after metadata exceptions, and removes orphan preview files if attachment insertion fails.
- Adds security, maintenance and real WordPress integration regressions plus a reproducible, verified release packager.

### 1.11.47
- Corrects default-cover eligibility: only PDFs without preview images receive a fake cover. Posts without PDFs are unchanged.
- Uses the PDF title instead of a category name, with portrait covers for cards and landscape covers for listings.
- Preserves real/custom image previews and keeps browser generation available while a fake cover is displayed.
- Clarifies the setting description and encodes PDF URLs and titles correctly.

### 1.11.46
- Adds an opt-in setting to automatically render and save missing PDF previews in the uploading browser.
- Supports media-dialog uploads, Gutenberg REST uploads, and the legacy Media > Add New uploader, processing files one at a time without replacing existing previews.
- Shares the renderer with the existing manual browser maintenance tool and releases workers on success or failure.
- Shows saved browser previews in Media Library dialog responses and restricts saves to users allowed to upload and edit the PDF.

### 1.11.45
- Matches WGL listing titles independently of their HTML heading level or element.
- Renders the first PDF page with bundled PDF.js in shortcode and Gutenberg cards when no generated thumbnail exists, while preserving saved and custom images.
- Releases browser preview workers after rendering or failure.
- Keeps the top of the PDF page visible when listing thumbnails crop to a landscape frame.
- Adds configurable category document covers that reuse the existing button and title colors.
- Adds configurable listing pages for use across sites.
- Adds saved widescreen, desktop, tablet, and mobile defaults for new insertions; removes Classic Editor column prompts and preserves existing layouts.

### 1.11.44
- Fixes browser preview generation on PDF.js builds where the loaded PDF document exposes `cleanup()` but not `destroy()`.

### 1.11.43
- Adds an admin-only browser PDF.js maintenance fallback that renders missing PDF previews in the browser and saves them as reusable WordPress image attachments.
- Keeps the existing WordPress/Imagick generation path unchanged while allowing manual preview generation when server-side PDF rendering is unavailable.

### 1.11.42
- Updates the bundled PDF.js runtime from 6.1.200 to 6.3.289, the current npm `latest` release at build time, addressing the high-severity PDF.js advisory fixed in 6.2.108.
- Adds the bundled PDF.js image decoder resources to keep the 6.3.x runtime fully local.

### 1.11.41
- Renames plugin-created settings, action, nonce, and transient identifiers to the full `pdf_thumbnail_inserter` prefix while migrating existing saved settings.
- Adds `[pdf_thumbnail_inserter]` as the canonical shortcode and keeps `[pdf_thumbnail]` as a legacy alias for existing content.
- Routes the bundled PDF.js viewer through a WordPress-rendered endpoint, restricts it to local uploads PDFs, and prints viewer assets through registered WordPress style/script handles.

### 1.11.40
- Adds a narrowly scoped loose title resolver for PDF Thumbnail Inserter blocks saved only with `id`/`ids` and `title`, so punctuation or filename/title differences do not make the block disappear.

### 1.11.39
- Matches PDF canvas archive previews to the WGL listing image frame ratio, preventing portrait PDF pages from making `/nea/` cards excessively tall.

### 1.11.38
- Enqueues the PDF.js canvas thumbnail renderer early on `/nea/` and category archives, so previews inserted by the WGL output-buffer pass do not stay stuck on “Loading PDF preview...”.

### 1.11.37
- Adds a PDF.js canvas thumbnail fallback for `/nea/` and category featured media when a PDF preview block exists but no generated JPG preview image is available.
- Keeps existing generated image previews as the first choice and avoids iframe replacement in archive cards.

### 1.11.36
- Makes the PDF Thumbnail Inserter dynamic block render from a resolved PDF URL when saved `id`/`ids` cannot produce a usable attachment card.

### 1.11.35
- Restores `/nea/` and category featured-media fallbacks to image previews only, avoiding PDF.js iframe substitution in archive cards.
- Keeps the PDF Thumbnail Inserter block ID/parent/title resolution fixes separate from archive rendering.

### 1.11.34
- Resolves PDF Thumbnail Inserter blocks whose saved `id` points at a PDF preview child image by following the attachment parent back to the real PDF.
- Adds a title/filename fallback for PDF Thumbnail Inserter blocks that only expose `id`, `ids`, and `title` attributes.

### 1.11.33
- Accepts PDF Thumbnail Inserter block attachment IDs as PDFs when the attachment URL ends in `.pdf`, even if the stored MIME metadata is missing or wrong.
- Reuses standalone PDF preview lookup for those attachment-ID cards before falling back to the placeholder image.

### 1.11.32
- Adds a PDF.js iframe fallback for list/category featured media when a Gutenberg PDF preview exists but WordPress has no generated `-pdf.jpg` preview image for that file.

### 1.11.31
- Distinguishes Gutenberg File preview PDFs (`displayPreview` / embedded PDF objects) from normal PDF cards/links when resolving list-page fallback images, so preview blocks are preferred without changing card rendering.

### 1.11.30
- Adds a narrowly scoped frontend output pass for WGL Blog listing cards on `/nea/` and category archives, inserting the resolved first-PDF preview image when the theme emits `format-no_featured` markup and never calls the normal thumbnail hooks.

### 1.11.29
- Fixes first-PDF detection for Gutenberg File blocks whose PDF URLs contain Greek/non-ASCII filenames by extracting URL paths without relying only on `wp_parse_url()`.
- Checks decoded and encoded upload-relative filename variants when mapping a local PDF URL back to an attachment.

### 1.11.28
- Restores the PDF attachment ID as a valid fallback thumbnail ID so WordPress and Elementor can use native PDF preview metadata when available.
- Applies the featured-image fallback to `/nea/` Loop Grid requests as well as category archives, while still leaving single posts untouched.
- Registers the existing Elementor dynamic tag hook so templates using the dynamic tag can resolve the fallback.

### 1.11.27
- Makes `has_post_thumbnail()` return true in category listings when the post has no real featured image but does have a resolvable first-PDF preview, so themes that skip thumbnail markup for `format-no_featured` posts still render the fallback image.

### 1.11.26
- Adds a dedicated first-PDF resolver that prefers the real PDF URL in post content over stale imported attachment IDs.
- Keeps the Elementor widget dynamic and tied to the current Loop Grid post ID, rendering only a real featured image or one generated/static PDF preview image.
- Restricts category archive handling to featured-image fallback plus attachment cleanup; previews are no longer injected into excerpts or content.
- Skips frontend filters in admin, REST, WP-CLI, AJAX, and Elementor editor/preview contexts.

### 1.11.25
- Separates the three list-rendering paths: Elementor `/nea/`, category featured media, and category excerpt/content cleanup.
- Resolves the first PDF from Gutenberg File blocks, raw PDF URLs, `[pdf_thumbnail_inserter]`/`[pdf_thumbnail]` shortcodes, and PDF Thumbnail block `id`/`ids` attributes.
- Removes PDF/DOC attachment labels from automatically generated excerpts while preserving real article text.
- Ensures category archives render PDF fallback visuals through the featured-image slot only.
- Leaves single-post attachment rendering unchanged.

### 1.11.24
- Isolated the `/nea/` Elementor Loop Grid fallback inside the dedicated widget.
- Restored category-only featured-image fallback using the first PDF preview.
- Hid file blocks and PDF Thumbnail shortcodes only on category archives so attachment lists are not duplicated.
- Added first-page PDF.js fallback in the Elementor widget when no static preview image can be generated.

### 1.11.23
- Rebuilds the Elementor fallback from the stable 1.11.20 code path without global featured-image filters or archive block suppression.
- Resolves the current Loop Grid post at render time and renders the widget once per item, avoiding cached image data from the containing page or template.
- Uses only the first PDF found in the post content, including imported Gutenberg/raw HTML content.
- Reuses or generates the first PDF preview when the local PDF exists, while leaving category/archive post content unchanged.

### 1.11.20
- The Elementor fallback widget now injects its image directly at render time instead of relying on a dynamic-tag default, so PDF previews work inside Loop Grid templates and Elementor preview contexts.
- Adds a dedicated Elementor widget named `Featured Image or First PDF Preview`, which uses the real featured image first and the first PDF preview only as fallback.
- Keeps existing Elementor widgets unchanged until the new tag is selected explicitly.
- Removes automatic WordPress featured-image filtering, so the plugin no longer changes normal featured-image output globally.

### 1.11.17
- Fixed Elementor dynamic-tag initialization so the PDF featured-image fallback no longer causes a fatal error in Loop Grid rendering.

### 1.11.16

- Fixes the Elementor Pro featured-image fallback fatal error by matching the public visibility of the parent dynamic-tag method.
- No fallback logic or other plugin behavior was changed.

### 1.11.15

- Adds direct compatibility with Elementor Pro’s Post Featured Image dynamic tag in Loop Grid templates.
- Uses the first existing PDF preview image by URL when imported content has no valid PDF attachment record.
- Keeps real featured images as the first priority and does not write `_thumbnail_id` or any other post data.

### 1.11.14

- Uses the Gutenberg File block `href` as the authoritative file URL when its stored attachment ID points to a different file.
- Resolves the correct attachment from the PDF URL when possible, so legacy posts with incorrect block IDs still use PDF.js previews and generated thumbnails.
- Adds an opt-in frontend fallback that uses the first PDF preview as the featured image in post lists when no real featured image exists.
- Existing featured images always take priority, and no `_thumbnail_id` value is written to the database.

### 1.11.13

- Detects Gutenberg PDF previews from both the `displayPreview` block attribute and the rendered `wp-block-file__embed` object.
- Keeps existing PDF previews as previews in archive/category rendering instead of converting them to cards.

### 1.11.12

- Replaced direct browser PDF iframe loading with a local PDF.js 6.1.200 viewer.
- The PDF.js iframe displays the PDF pages without the browser fallback screen or duplicate open/download controls.
- Kept the existing opt-in setting, maximum preview height, WordPress file title, and download button behavior unchanged.

### 1.11.9

- Added an opt-in setting that replaces existing WordPress PDF file-block previews with iframes.
- Added a configurable maximum PDF preview height, defaulting to 600px.

### 1.11.8

- Restored support for legacy URL-based shortcodes without attachment IDs.
- Uses the bundled placeholder when `thumbnail` is missing.
- Builds the title from the PDF filename when `title` is missing.

### 1.11.5
- Changed PDF card titles to a single-line ellipsis layout
- Added hover tooltips with the full PDF title
- Improved card stretching so the download button stays aligned at the bottom without fixed caption heights

### 1.11.1

- Added support for rendering multiple PDFs from one shortcode or block.
- Added responsive grid column controls for desktop, laptop/tablet, and mobile.
- Updated the Classic Editor insert flow to support selecting multiple PDFs.
- Updated the Gutenberg block to support selecting multiple PDFs.
- Kept single-PDF backward compatibility with the original `id` attribute.

### 1.9.0

- Implemented actual PDF preview generation for missing previews.
- Added Gutenberg block support.
- Added link target modes.
- Moved presentation styling into CSS with CSS custom properties.
- Upgraded settings page with better defaults and color pickers.
- Added a maintenance tool for existing PDFs.
- Updated docs to match the real plugin behavior.
