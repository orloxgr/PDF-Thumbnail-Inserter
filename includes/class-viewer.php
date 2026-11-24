<?php
/** Viewer subsystem. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_Viewer {
    /** @var PDF_Thumbnail_Inserter_Discovery */
    private $discovery;

    /** @var PDF_Thumbnail_Inserter_FileResolver */
    private $file_resolver;

    /** @var PDF_Thumbnail_Inserter_Settings */
    private $settings;

    /** @var PDF_Thumbnail_Inserter_RuntimeState */
    private $state;

    /** Wire the request-local collaborators before registering any hooks. */
    public function set_dependencies( PDF_Thumbnail_Inserter_Discovery $discovery, PDF_Thumbnail_Inserter_FileResolver $file_resolver, PDF_Thumbnail_Inserter_Settings $settings, PDF_Thumbnail_Inserter_RuntimeState $state ) {
        $this->discovery = $discovery;
        $this->file_resolver = $file_resolver;
        $this->settings = $settings;
        $this->state = $state;
    }

        public function register_preview_query_vars( $query_vars ) {
            $this->state->preview_query_vars = array_diff( array( 'file', 'title', 'shape' ), $query_vars );
            return array_unique( array_merge( $query_vars, array( PDF_Thumbnail_Inserter::VIEWER_QUERY_VAR, PDF_Thumbnail_Inserter::COVER_QUERY_VAR, 'file', 'title', 'shape' ) ) );
        }

        /** Route public GET resources through WordPress parsing, without changing the content query. */
        public function parse_public_preview_request( $query_vars ) {
            $this->state->public_preview_request = array();
            $this->state->public_preview_request['cover_requested'] = isset( $query_vars[ PDF_Thumbnail_Inserter::COVER_QUERY_VAR ] ) ? '1' : '';
            foreach ( array( PDF_Thumbnail_Inserter::VIEWER_QUERY_VAR, PDF_Thumbnail_Inserter::COVER_QUERY_VAR, 'file', 'title', 'shape' ) as $key ) {
                $value = isset( $query_vars[ $key ] ) && is_scalar( $query_vars[ $key ] ) ? wp_unslash( (string) $query_vars[ $key ] ) : '';
                if ( 'file' === $key ) $value = esc_url_raw( $value );
                elseif ( 'shape' === $key ) $value = sanitize_key( $value );
                else $value = sanitize_text_field( $value );
                $this->state->public_preview_request[ $key ] = $value;
            }
            foreach ( $this->state->preview_query_vars as $key ) unset( $query_vars[ $key ] );
            return $query_vars;
        }

        public function get_local_pdf_viewer_file_url( $pdf_url ) {
            $pdf_url = esc_url_raw( $this->file_resolver->normalize_pdf_candidate_url( $pdf_url ) );
            return '' !== $this->file_resolver->get_local_upload_pdf_path( $pdf_url ) ? $pdf_url : '';
        }

        public function get_pdfjs_viewer_url( $pdf_url ) {
            $pdf_url = $this->get_local_pdf_viewer_file_url( $pdf_url );
            if ( '' === $pdf_url ) {
                return '';
            }

            return esc_url_raw(
                add_query_arg(
                    array(
                        PDF_Thumbnail_Inserter::VIEWER_QUERY_VAR => 1,
                        'file'                 => rawurlencode( $pdf_url ),
                    ),
                    home_url( '/' )
                )
            );
        }

        public function maybe_render_pdfjs_viewer() {
            $viewer_requested = $this->state->public_preview_request[ PDF_Thumbnail_Inserter::VIEWER_QUERY_VAR ] ?? '';

            if ( '' === $viewer_requested ) {
                return;
            }

            $raw_file = $this->state->public_preview_request['file'] ?? '';
            $pdf_url  = $this->get_local_pdf_viewer_file_url( $raw_file );

            if ( '' === $pdf_url ) {
                status_header( 404 );
                nocache_headers();
                wp_die(
                    esc_html__( 'PDF preview unavailable.', 'pdf-thumbnail-inserter' ),
                    esc_html__( 'PDF preview unavailable.', 'pdf-thumbnail-inserter' ),
                    array( 'response' => 404 )
                );
            }

            status_header( 200 );
            nocache_headers();
            header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
            header( 'X-Content-Type-Options: nosniff' );
            header( "Content-Security-Policy: default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; style-src 'self'; connect-src 'self'; img-src 'self' data: blob:; font-src 'self'; worker-src 'self' blob:; object-src 'none'; base-uri 'none'; frame-ancestors 'self'" );

            wp_enqueue_style( 'pdf-thumbnail-viewer' );
            wp_enqueue_script( 'pdf-thumbnail-viewer' );

            ?><!doctype html>
            <html <?php language_attributes(); ?>>
            <head>
                <meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title><?php echo esc_html__( 'PDF preview', 'pdf-thumbnail-inserter' ); ?></title>
                <?php wp_print_styles( array( 'pdf-thumbnail-viewer' ) ); ?>
            </head>
            <body>
                <main id="viewer" aria-label="<?php echo esc_attr__( 'PDF preview', 'pdf-thumbnail-inserter' ); ?>"
                    <?php /* translators: 1: current PDF page, 2: total PDF pages. */ ?>
                    data-page-label="<?php echo esc_attr__( 'Page %1$d of %2$d', 'pdf-thumbnail-inserter' ); ?>"
                    data-error-label="<?php echo esc_attr__( 'The PDF preview could not be loaded.', 'pdf-thumbnail-inserter' ); ?>"></main>
                <div id="status" role="status" aria-live="polite"><?php echo esc_html__( 'Loading PDF preview...', 'pdf-thumbnail-inserter' ); ?></div>
                <?php wp_print_scripts( array( 'pdf-thumbnail-viewer' ) ); ?>
            </body>
            </html>
            <?php
            exit;
        }

        public function render_featured_pdf_frame( $pdf_url, $title = '', $height = 360 ) {
            $viewer_url = $this->get_pdfjs_viewer_url( $pdf_url );
            if ( '' === $viewer_url ) {
                return '';
            }

            $height = absint( $height );
            if ( $height < 120 ) {
                $height = 360;
            }

            if ( '' === $title ) {
                $title = __( 'PDF Preview', 'pdf-thumbnail-inserter' );
            }

            return sprintf(
                '<iframe class="pti-featured-pdf-frame wp-post-image" style="height:%1$dpx" src="%2$s" title="%3$s" loading="lazy"></iframe>',
                $height,
                esc_url( $viewer_url ),
                esc_attr( $title )
            );
        }

        public function render_pdf_canvas_preview( $pdf_url, $title = '', $classes = 'pti-featured-image-or-pdf-preview wp-post-image' ) {
            $pdf_url = esc_url_raw( $pdf_url );
            if ( '' === $pdf_url ) {
                return '';
            }

            wp_enqueue_style( 'pdf-thumbnail-frontend' );
            wp_enqueue_script( 'pdf-thumbnail-preview-renderer' );

            if ( '' === $title ) {
                $title = __( 'PDF Preview', 'pdf-thumbnail-inserter' );
            }

            return sprintf(
                '<span class="pti-pdf-canvas-preview %4$s" data-pdf-url="%1$s" role="img" aria-label="%2$s"><canvas aria-hidden="true"></canvas><span class="pti-pdf-canvas-preview__status">%3$s</span></span>',
                esc_url( $pdf_url ),
                esc_attr( $title ),
                esc_html__( 'Loading PDF preview...', 'pdf-thumbnail-inserter' ),
                esc_attr( $classes )
            );
        }

        public function render_pdf_canvas_preview_for_post( $pdf_url, $title = '' ) {
            return $this->render_pdf_canvas_preview( $pdf_url, $title );
        }

        public function get_document_cover_url( $pdf_url, $title = '', $shape = 'landscape' ) {
            $settings = $this->settings->get_settings();
            $pdf_url = esc_url_raw( $pdf_url );
            if ( empty( $settings['use_default_document_cover'] ) || 'pdf' !== strtolower( pathinfo( $this->file_resolver->get_url_path( $pdf_url ), PATHINFO_EXTENSION ) ) ) {
                return '';
            }
            $title = sanitize_text_field( $title );
            if ( '' === $title ) {
                $title = $this->discovery->get_file_title_from_url( $pdf_url, __( 'PDF File', 'pdf-thumbnail-inserter' ) );
            }
            return esc_url_raw( add_query_arg(
                array(
                    PDF_Thumbnail_Inserter::COVER_QUERY_VAR => 1,
                    'file' => rawurlencode( $pdf_url ),
                    'title' => rawurlencode( $title ),
                    'shape' => 'portrait' === $shape ? 'portrait' : 'landscape',
                    'ver' => md5( wp_json_encode( array( PDF_Thumbnail_Inserter::VERSION, $title, $settings['document_cover_label'], $settings['button_color'], $settings['title_color'] ) ) ),
                ),
                home_url( '/' )
            ) );
        }

        public function render_document_cover_svg( $title, $shape = 'landscape' ) {
            ob_start();
            $this->print_document_cover_svg( $title, $shape );
            return (string) ob_get_clean();
        }

        /** Render fixed SVG markup with every dynamic value escaped in its output context. */
        public function print_document_cover_svg( $title, $shape = 'landscape' ) {
            $settings = $this->settings->get_settings();
            $defaults = $this->settings->get_defaults();
            $background = '#f6f7f7';
            $accent = $this->settings->sanitize_hex_color_with_fallback( $settings['button_color'], $defaults['button_color'] );
            $text = $this->settings->sanitize_hex_color_with_fallback( $settings['title_color'], $defaults['title_color'] );
            $label = wp_html_excerpt( $settings['document_cover_label'], 40, '…' );
            $title = trim( wp_strip_all_tags( $title ) );
            $portrait = 'portrait' === $shape;
            $max_chars = $portrait ? 23 : 27;
            $text_x = $portrait ? 80 : 350;
            $lines = array();
            $line = '';
            foreach ( preg_split( '/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
                $next = '' === $line ? $word : $line . ' ' . $word;
                if ( '' !== $line && preg_match_all( '/./us', $next ) > $max_chars ) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $next;
                }
            }
            if ( '' !== $line ) {
                $lines[] = $line;
            }
            $overflow = count( $lines ) > 3;
            $lines = array_slice( $lines, 0, 3 );
            // SVG attribute names are case-sensitive. Do not serialize this template through HTML KSES.
            ?>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 <?php echo (int) ( $portrait ? 730 : 1170 ); ?> <?php echo (int) ( $portrait ? 960 : 725 ); ?>" width="<?php echo (int) ( $portrait ? 730 : 1170 ); ?>" height="<?php echo (int) ( $portrait ? 960 : 725 ); ?>" role="img" aria-label="<?php echo esc_attr( trim( $label . ' ' . $title ) ); ?>" style="max-width:100%;height:auto">
                <rect width="<?php echo (int) ( $portrait ? 730 : 1170 ); ?>" height="<?php echo (int) ( $portrait ? 960 : 725 ); ?>" fill="<?php echo esc_attr( $background ); ?>"/><rect width="18" height="<?php echo (int) ( $portrait ? 960 : 725 ); ?>" fill="<?php echo esc_attr( $accent ); ?>"/>
                <g transform="translate(<?php echo (int) ( $portrait ? 165 : 0 ); ?>,<?php echo (int) ( $portrait ? -80 : 0 ); ?>)" fill="none" stroke="<?php echo esc_attr( $accent ); ?>" stroke-width="9" stroke-linejoin="round" stroke-linecap="round"><path d="M100 205h115l65 65v250H100z"/><path d="M215 205v65h65M135 330h110M135 375h110M135 420h70"/></g>
                <g font-family="Arial, sans-serif"><text x="<?php echo (int) $text_x; ?>" y="<?php echo (int) ( $portrait ? 550 : 235 ); ?>" font-size="32" font-weight="700" fill="<?php echo esc_attr( $accent ); ?>"><?php echo esc_html( $label ); ?></text><text font-size="48" font-weight="600" fill="<?php echo esc_attr( $text ); ?>">
                    <?php foreach ( $lines as $index => $title_line ) :
                        $title_line = wp_html_excerpt( $title_line, $max_chars, '…' );
                        if ( $overflow && 2 === $index ) $title_line = wp_html_excerpt( $title_line, $max_chars - 1, '' ) . '…';
                        ?><tspan x="<?php echo (int) $text_x; ?>" y="<?php echo (int) ( ( $portrait ? 650 : 338 ) + $index * 68 ); ?>"><?php echo esc_html( $title_line ); ?></tspan><?php
                    endforeach; ?>
                </text></g>
                <rect x="<?php echo (int) $text_x; ?>" y="<?php echo (int) ( $portrait ? 865 : 560 ); ?>" width="110" height="8" rx="4" fill="<?php echo esc_attr( $accent ); ?>"/>
            </svg>
            <?php
        }

        public function maybe_render_document_cover() {
            if ( empty( $this->state->public_preview_request['cover_requested'] ) ) {
                return;
            }
            $requested = $this->state->public_preview_request[ PDF_Thumbnail_Inserter::COVER_QUERY_VAR ];
            $pdf_url = $this->state->public_preview_request['file'];
            $title = $this->state->public_preview_request['title'];
            $shape = $this->state->public_preview_request['shape'];
            $shape = 'portrait' === $shape ? 'portrait' : 'landscape';
            if ( '1' !== $requested || '' === $this->get_document_cover_url( $pdf_url, $title, $shape ) ) {
                status_header( 404 );
                exit;
            }
            if ( '' === $title ) {
                $title = $this->discovery->get_file_title_from_url( $pdf_url, __( 'PDF File', 'pdf-thumbnail-inserter' ) );
            }
            status_header( 200 );
            header( 'Content-Type: image/svg+xml; charset=UTF-8' );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'X-Robots-Tag: noindex' );
            header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox" );
            header( 'Cache-Control: public, max-age=3600' );
            $this->print_document_cover_svg( $title, $shape );
            exit;
        }
}
