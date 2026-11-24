=== PDF Thumbnail Inserter ===
Contributors: orloxgr
Tags: pdf, thumbnail, media, shortcode, gutenberg
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.01.04
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Generate and reuse PDF thumbnails, then insert one or many of them with a shortcode, Gutenberg block, or Classic Editor button.

== Description ==

PDF Thumbnail Inserter generates and reuses thumbnail previews for PDF attachments.

If WordPress already provides a preview image for a PDF, the plugin reuses it. If no preview exists, the plugin attempts to generate one from the first page of the PDF using Imagick. Administrators can also generate missing previews in the browser with the bundled PDF.js runtime when the server cannot render PDFs. The preview can then be displayed through a shortcode, a Gutenberg block, or a Classic Editor insert button.

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
* Maintenance tool for generating previews for older PDFs through the server or through the browser with bundled PDF.js.
* Optional automatic browser generation of missing PDF previews immediately after upload, using the same renderer as manual maintenance.

== Installation ==

1. Upload the `pdf-thumbnail-inserter` folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress.
3. Go to **Settings > PDF Thumbnail Settings** to configure defaults.
4. Insert a PDF thumbnail using the shortcode, the block, or the Classic Editor button.

== Frequently Asked Questions ==

= Does this require Imagick? =

Only for automatic server-side preview generation when WordPress does not already provide a PDF preview. If the server cannot generate PDF previews, administrators can still use the browser PDF.js maintenance action from the settings page to create reusable image previews.

= How do I insert a PDF card manually? =

Use:

[pdf_thumbnail_inserter id="123"]

where `123` is the PDF attachment ID.

For multiple PDFs, use:

[pdf_thumbnail_inserter ids="123,124,125" columns_desktop="3" columns_laptop="2" columns_mobile="1"]

The older [pdf_thumbnail] shortcode remains available as a legacy alias for existing content.

= Can I link somewhere other than the PDF file? =

Yes. Use `link_to="attachment"`, `link_to="custom"` with `url="..."`, or `link_to="none"`.

For development checks see development.txt

For changelog see changelog.txt
