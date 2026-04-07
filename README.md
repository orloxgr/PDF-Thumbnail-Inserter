# PDF Thumbnail Inserter

**Version:** 1.11.2  
**Author:** Byron Iniotakis  
**License:** GPL-3.0-or-later

## Overview

PDF Thumbnail Inserter lets you display one or more WordPress PDF attachments as thumbnail cards using a shortcode, a Gutenberg block, or a Classic Editor insert button.

The plugin reuses existing WordPress PDF previews when available. If the server supports PDF preview generation, it can also generate missing previews from the first page of the PDF. When preview generation is not available, the plugin falls back gracefully to a placeholder image.

## Features

- Display a single PDF or multiple PDFs
- Responsive grid with separate columns for:
  - desktop
  - laptop/tablet
  - mobile
- Gutenberg block with multi-PDF selection
- Classic Editor insert button with multi-PDF selection
- Reuses existing WordPress PDF preview images
- Can generate missing previews when server support exists
- Link modes:
  - file
  - attachment page
  - custom URL
  - none
- Optional title and download button
- Media Library helper field with copyable shortcode
- Settings for thumbnail/button/title styling
- Runtime capability detection for PDF preview generation

## Requirements

- WordPress 6.0+
- PHP 7.4+
- For generating new PDF previews:
  - WordPress PDF-capable image editor support
  - typically Imagick / ImageMagick / Ghostscript

## Shortcode

### Single PDF

```text
[pdf_thumbnail id="123"]
```
### Multiple PDFs
```text
[pdf_thumbnail ids="123,124,125,126" columns_desktop="4" columns_laptop="2" columns_mobile="1"]
```
### Examples
```text
[pdf_thumbnail id="123" link_to="file" show_title="yes" show_button="yes"]
[pdf_thumbnail id="123" link_to="attachment" new_tab="no"]
[pdf_thumbnail id="123" link_to="custom" url="https://example.com/custom-page/"]
[pdf_thumbnail id="123" link_to="none" show_button="no"]
[pdf_thumbnail ids="123,124,125" columns_desktop="3" columns_laptop="2" columns_mobile="1"]
```
