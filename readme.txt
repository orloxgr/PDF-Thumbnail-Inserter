=== PDF Thumbnail Inserter ===
Contributors: orloxgr
Tags: pdf, thumbnail, media, shortcode, gutenberg
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.11.41
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Generate and reuse PDF thumbnails, then insert one or many of them with a shortcode, Gutenberg block, or Classic Editor button.

== Description ==

PDF Thumbnail Inserter generates and reuses thumbnail previews for PDF attachments.

If WordPress already provides a preview image for a PDF, the plugin reuses it. If no preview exists, the plugin attempts to generate one from the first page of the PDF using Imagick. The preview can then be displayed through a shortcode, a Gutenberg block, or a Classic Editor insert button.

The plugin also supports rendering multiple PDFs in a responsive grid with separate column counts for desktop, laptop/tablet, and mobile.

Features:

* Automatically creates missing PDF previews on upload.
* Reuses existing WordPress previews when available.
* Shortcode support for single or multiple PDFs.
* Responsive grid layout with device-specific column counts.
* Gutenberg block support.
* Classic Editor insert button.
* Link modes: file, attachment page, custom URL, or no link.
* Copyable shortcode field in Media Library attachment details.
* Settings page with visual color controls.
* Maintenance tool for generating previews for older PDFs when the server supports PDF preview generation.

== Installation ==

1. Upload the `pdf-thumbnail-inserter` folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress.
3. Go to **Settings > PDF Thumbnail Settings** to configure defaults.
4. Insert a PDF thumbnail using the shortcode, the block, or the Classic Editor button.

== Frequently Asked Questions ==

= Does this require Imagick? =

Only when WordPress does not already provide a PDF preview. If the server cannot generate PDF previews, the plugin disables the maintenance action and falls back to the placeholder image until support is installed. Refresh the settings page after enabling Imagick/ImageMagick/Ghostscript and the maintenance action will become available automatically.

= How do I insert a PDF card manually? =

Use:

[pdf_thumbnail_inserter id="123"]

where `123` is the PDF attachment ID.

For multiple PDFs, use:

[pdf_thumbnail_inserter ids="123,124,125" columns_desktop="3" columns_laptop="2" columns_mobile="1"]

The older [pdf_thumbnail] shortcode remains available as a legacy alias for existing content.

= Can I link somewhere other than the PDF file? =

Yes. Use `link_to="attachment"`, `link_to="custom"` with `url="..."`, or `link_to="none"`.

== Changelog ==

= 1.11.41 =
* Renames plugin-created settings, action, nonce, and transient identifiers to the full pdf_thumbnail_inserter prefix while migrating existing saved settings.
* Adds [pdf_thumbnail_inserter] as the canonical shortcode and keeps [pdf_thumbnail] as a legacy alias for existing content.
* Routes the bundled PDF.js viewer through a WordPress-rendered endpoint, restricts it to local uploads PDFs, and prints viewer assets through registered WordPress style/script handles.

= 1.11.40 =
* Adds a narrowly scoped loose title resolver for PDF Thumbnail Inserter blocks saved only with id/ids and title, so punctuation or filename/title differences do not make the block disappear.

= 1.11.39 =
* Matches PDF canvas archive previews to the WGL listing image frame ratio, preventing portrait PDF pages from making /nea/ cards excessively tall.

= 1.11.38 =
* Enqueues the PDF.js canvas thumbnail renderer early on /nea/ and category archives, so previews inserted by the WGL output-buffer pass do not stay stuck on “Loading PDF preview...”.

= 1.11.37 =
* Adds a PDF.js canvas thumbnail fallback for /nea/ and category featured media when a PDF preview block exists but no generated JPG preview image is available.
* Keeps existing generated image previews as the first choice and avoids iframe replacement in archive cards.

= 1.11.36 =
* Makes the PDF Thumbnail Inserter dynamic block render from a resolved PDF URL when saved id/ids cannot produce a usable attachment card.

= 1.11.35 =
* Restores /nea/ and category featured-media fallbacks to image previews only, avoiding PDF.js iframe substitution in archive cards.
* Keeps the PDF Thumbnail Inserter block ID/parent/title resolution fixes separate from archive rendering.

= 1.11.34 =
* Resolves PDF Thumbnail Inserter blocks whose saved id points at a PDF preview child image by following the attachment parent back to the real PDF.
* Adds a title/filename fallback for PDF Thumbnail Inserter blocks that only expose id, ids, and title attributes.

= 1.11.33 =
* Accepts PDF Thumbnail Inserter block attachment IDs as PDFs when the attachment URL ends in .pdf, even if the stored MIME metadata is missing or wrong.
* Reuses standalone PDF preview lookup for those attachment-ID cards before falling back to the placeholder image.

= 1.11.32 =
* Adds a PDF.js iframe fallback for list/category featured media when a Gutenberg PDF preview exists but WordPress has no generated preview image for that file.

= 1.11.31 =
* Distinguishes Gutenberg File preview PDFs from normal PDF cards/links when resolving list-page fallback images, so preview blocks are preferred without changing card rendering.

= 1.11.30 =
* Adds a narrowly scoped frontend output pass for WGL Blog listing cards on `/nea/` and category archives when the theme emits `format-no_featured` markup and never calls the normal thumbnail hooks.

= 1.11.29 =
* Fixes first-PDF detection for Gutenberg File blocks whose PDF URLs contain Greek/non-ASCII filenames.
* Checks decoded and encoded upload-relative filename variants when mapping a local PDF URL back to an attachment.

= 1.11.28 =
* Restores the PDF attachment ID as a valid fallback thumbnail ID so WordPress and Elementor can use native PDF preview metadata when available.
* Applies the featured-image fallback to `/nea/` Loop Grid requests as well as category archives, while still leaving single posts untouched.
* Registers the existing Elementor dynamic tag hook so templates using the dynamic tag can resolve the fallback.

= 1.11.27 =
* Makes has_post_thumbnail() return true in category listings when the post has no real featured image but does have a resolvable first-PDF preview.

= 1.11.26 =
* Adds a dedicated first-PDF resolver that prefers real post-content PDF URLs over stale imported attachment IDs.
* Keeps the Elementor widget dynamic and tied to the current Loop Grid post ID.
* Restricts category archive handling to featured-image fallback plus attachment cleanup, without injecting previews into excerpts/content.
* Skips frontend filters in admin, REST, WP-CLI, AJAX, and Elementor editor/preview contexts.

= 1.11.25 =
* Separates Elementor Loop Grid, category featured media, and category excerpt/content cleanup.
* Detects the first PDF in Gutenberg blocks, raw URLs, shortcodes, and PDF Thumbnail block ID lists.
* Removes attachment-title text from automatic excerpts and prevents category duplicate PDF output.
* Keeps single posts unchanged.

= 1.11.24 =
* Isolated Elementor Loop Grid, category thumbnail fallback, and category attachment suppression into separate code paths.
* Added first-page PDF.js fallback for the custom Elementor widget when no static thumbnail exists.

= 1.11.23 =
* Rebuilds the Elementor fallback from the stable 1.11.20 code path without global featured-image filters or archive block suppression.
* Resolves the current Loop Grid post at render time and renders the widget once per item, avoiding cached image data from the containing page or template.
* Uses only the first PDF found in the post content, including imported Gutenberg/raw HTML content.
* Reuses or generates the first PDF preview when the local PDF exists, while leaving category/archive post content unchanged.

= 1.11.20 =
* Fixes the Elementor fallback widget so it renders the real featured image or first PDF preview directly inside Loop Grid templates.
* Adds a dedicated Elementor widget named Featured Image or First PDF Preview, which uses the real featured image first and the first PDF preview only as fallback.
* Keeps existing Elementor widgets unchanged until the new tag is selected explicitly.
* Removes automatic WordPress featured-image filtering, so normal featured-image output is no longer changed globally.

= 1.11.17 =
* Fixed Elementor dynamic-tag initialization so the PDF featured-image fallback no longer causes a fatal error in Loop Grid rendering.

= 1.11.16 =
* Fixes the Elementor Pro featured-image fallback fatal error by matching the public visibility of the parent dynamic-tag method.
* No fallback logic or other plugin behavior was changed.

= 1.11.15 =
* Adds direct compatibility with Elementor Pro Post Featured Image dynamic tags in Loop Grid templates.
* Uses the first existing PDF preview image by URL when imported content has no valid PDF attachment record.
* Keeps real featured images as the first priority and does not write _thumbnail_id or any other post data.

= 1.11.14 =
* Uses the Gutenberg File block href as the authoritative file URL when its stored attachment ID points to a different file.
* Resolves the correct attachment from the PDF URL when possible, so legacy posts with incorrect block IDs still use PDF.js previews and generated thumbnails.
* Adds an opt-in frontend fallback that uses the first PDF preview as the featured image in post lists when no real featured image exists.
* Existing featured images always take priority, and no _thumbnail_id value is written to the database.

= 1.11.13 =
* Detects Gutenberg PDF previews from both the displayPreview block attribute and the rendered wp-block-file__embed object.
* Keeps existing PDF previews as previews in archive/category rendering instead of converting them to cards.

= 1.11.12 =
* Replaced direct browser PDF iframe loading with a local PDF.js 6.1.200 viewer.
* The PDF.js iframe displays PDF pages without the browser fallback screen or duplicate open/download controls.
* Kept the opt-in setting, maximum preview height, WordPress file title, and download button behavior unchanged.

= 1.11.9 =
* Added an opt-in setting that replaces existing WordPress PDF file-block previews with iframes.
* Added a configurable maximum PDF preview height, defaulting to 600px.

= 1.11.8 =
* Restored support for legacy URL-based shortcodes without attachment IDs.
* Uses the bundled placeholder when `thumbnail` is missing.
* Builds the title from the PDF filename when `title` is missing.

= 1.11.5 =
* Changed PDF card titles to a single-line ellipsis layout.
* Added hover tooltips with the full PDF title.
* Improved card stretching so the download button stays aligned at the bottom without fixed caption heights.


= 1.11.3 =
* Switched the fallback placeholder asset from SVG to PNG for broader compatibility

= 1.11.2 =
* Fixed block editor selection by making the server-rendered preview non-interactive inside the editor and wrapping the block output with proper block props.



= 1.11.1 =
* Added support for rendering multiple PDFs from one shortcode or block.
* Added responsive grid column controls for desktop, laptop/tablet, and mobile.
* Updated the Classic Editor insert flow to support selecting multiple PDFs.
* Updated the Gutenberg block to support selecting multiple PDFs.
* Kept backward compatibility with the original `id` shortcode attribute.

= 1.9.0 =
* Implemented actual PDF preview generation for missing previews.
* Added Gutenberg block support.
* Added link target modes.
* Cleaned up frontend styling.
* Improved settings handling and defaults.
* Added a maintenance tool for existing PDFs.
* Updated documentation to match the code.
