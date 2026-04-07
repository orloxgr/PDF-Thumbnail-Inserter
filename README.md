# PDF Thumbnail Inserter

**Version:** 1.11.4  
**Author:** Byron Iniotakis  
**License:** GPL-3.0-or-later

## Description

PDF Thumbnail Inserter generates and reuses thumbnail previews for PDF attachments and lets you insert them with a shortcode, a Gutenberg block, or a Classic Editor media button.

The plugin first reuses any preview WordPress already provides. If no preview exists, it attempts to generate one from the first page of the PDF using Imagick. Generated previews are stored as image attachments so they can be reused later.

It now supports rendering one PDF or multiple PDFs in a responsive grid with separate column controls for desktop, laptop/tablet, and mobile.

## Features

- Automatically creates missing PDF previews on upload.
- Reuses existing WordPress PDF previews when available.
- Shortcode support for single or multiple PDFs.
- Responsive grid layout with separate desktop, laptop/tablet, and mobile column counts.
- Gutenberg block support with multi-select PDF picker.
- Classic Editor insert button with multi-select PDF picker.
- Link modes for the thumbnail card:
  - PDF file
  - Attachment page
  - Custom URL
  - No link
- Media Library attachment field with a copyable shortcode.
- Settings page with color picker controls.
- Maintenance tool to generate missing previews for existing PDFs when the server supports PDF preview generation.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Imagick for plugin-generated previews when WordPress does not already provide one

## Shortcode

### Basic usage

```text
[pdf_thumbnail id="123"]
```

### Multiple PDFs in a grid

```text
[pdf_thumbnail ids="123,124,125,126" columns_desktop="4" columns_laptop="2" columns_mobile="1"]
```

### Common attributes

```text
[pdf_thumbnail id="123" link_to="file" show_title="yes" show_button="yes"]
[pdf_thumbnail id="123" link_to="attachment" new_tab="no"]
[pdf_thumbnail id="123" link_to="custom" url="https://example.com/custom-page/"]
[pdf_thumbnail id="123" link_to="none" show_button="no"]
[pdf_thumbnail ids="123,124,125" columns_desktop="3" columns_laptop="2" columns_mobile="1"]
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
- `columns_laptop` (`1` to `6`)
- `columns_mobile` (`1` to `6`)
- `class`
- `rel`

## Notes

- If Imagick or WordPress PDF preview support is unavailable, the plugin still works, but PDFs without an existing preview will fall back to the placeholder image.
- The maintenance button is disabled automatically when the server cannot generate PDF previews. If support is installed later, refresh the settings page and the button becomes available again.
- Generated preview images are saved as child image attachments of the original PDF.
- `thumbnail`, `title`, and `url` overrides are intended for single-PDF usage. When multiple PDFs are rendered, the plugin ignores single-item overrides that would otherwise apply the same custom data to every card.

## Changelog

### 1.11.4
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
