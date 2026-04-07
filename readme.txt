=== PDF Thumbnail Inserter ===
Contributors: orloxgr
Tags: pdf, thumbnail, media, shortcode, gutenberg
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.11.5
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

[pdf_thumbnail id="123"]

where `123` is the PDF attachment ID.

For multiple PDFs, use:

[pdf_thumbnail ids="123,124,125" columns_desktop="3" columns_laptop="2" columns_mobile="1"]

= Can I link somewhere other than the PDF file? =

Yes. Use `link_to="attachment"`, `link_to="custom"` with `url="..."`, or `link_to="none"`.

== Changelog ==

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
