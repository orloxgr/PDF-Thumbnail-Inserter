# PDF Thumbnail Inserter

**Version:** 1.11.52
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

For development checks see [development.txt](development.txt)

For changelog see [changelog.txt](changelog.txt)
