<?php
/** Blocks subsystem. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_Blocks {
    /** @var PDF_Thumbnail_Inserter */
    private $callbacks;

    /** @var PDF_Thumbnail_Inserter_Cards */
    private $cards;

    /** @var PDF_Thumbnail_Inserter_Discovery */
    private $discovery;

    /** @var PDF_Thumbnail_Inserter_ListingFilters */
    private $listing_filters;

    /** @var PDF_Thumbnail_Inserter_Settings */
    private $settings;

    /** @var PDF_Thumbnail_Inserter_Viewer */
    private $viewer;

    /** Wire the request-local collaborators before registering any hooks. */
    public function set_dependencies( PDF_Thumbnail_Inserter $callbacks, PDF_Thumbnail_Inserter_Cards $cards, PDF_Thumbnail_Inserter_Discovery $discovery, PDF_Thumbnail_Inserter_ListingFilters $listing_filters, PDF_Thumbnail_Inserter_Settings $settings, PDF_Thumbnail_Inserter_Viewer $viewer ) {
        $this->callbacks = $callbacks;
        $this->cards = $cards;
        $this->discovery = $discovery;
        $this->listing_filters = $listing_filters;
        $this->settings = $settings;
        $this->viewer = $viewer;
    }

        public function register_block() {
            if ( ! function_exists( 'register_block_type' ) ) {
                return;
            }

            register_block_type(
                PDF_THUMBNAIL_INSERTER_PATH . 'blocks/pdf-thumbnail',
                array(
                    // API 3 enables the iframe editor on WP 6.3+; retain WP 6.0 support.
                    'api_version'     => version_compare( get_bloginfo( 'version' ), '6.3', '>=' ) ? 3 : 2,
                    'editor_script'   => 'pdf-thumbnail-block-editor',
                    'style'           => 'pdf-thumbnail-frontend',
                    'editor_style'    => 'pdf-thumbnail-block-editor-style',
                    'render_callback' => array( $this->callbacks, 'render_block' ),
                )
            );
        }

        public function replace_pdf_file_block_preview( $block_content, $block ) {
            if ( $this->listing_filters->should_skip_frontend_list_filters() ) {
                return $block_content;
            }

            $settings   = $this->settings->get_settings();
            $attributes = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
            $file_data  = $this->discovery->get_gutenberg_file_block_data( $block, $block_content );

            if (
                $this->listing_filters->is_attachment_list_render_context()
                && ( ! empty( $file_data['is_pdf'] ) || ! empty( $file_data['is_doc'] ) )
            ) {
                return '';
            }

            if ( empty( $file_data['file_url'] ) ) {
                return $block_content;
            }

            $pattern = '~<object\b(?=[^>]*\bclass=(["\'])[^"\']*\bwp-block-file__embed\b[^"\']*\1)[^>]*>.*?</object>~is';
            $has_preview = ! empty( $attributes['displayPreview'] ) || preg_match( $pattern, $block_content );

            if ( ! empty( $settings['convert_gutenberg_pdf_links_to_cards'] ) && ! empty( $file_data['is_pdf'] ) ) {
                return $this->cards->render_gutenberg_pdf_block_as_card( $block_content, $block, $file_data );
            }

            if ( ! empty( $settings['convert_gutenberg_doc_links_to_cards'] ) && ! empty( $file_data['is_doc'] ) ) {
                return $this->cards->render_gutenberg_doc_block_as_card( $block_content, $block, $file_data );
            }

            if ( empty( $settings['use_iframe_pdf_previews'] ) || empty( $file_data['is_pdf'] ) || ! $has_preview ) {
                return $block_content;
            }

            if ( ! preg_match( $pattern, $block_content, $object_match ) ) {
                return $block_content;
            }

            $title = '';

            if ( preg_match( '~\baria-label=(["\'])(.*?)\1~is', $object_match[0], $title_match ) ) {
                $title = sanitize_text_field( html_entity_decode( $title_match[2], ENT_QUOTES, get_bloginfo( 'charset' ) ) );
            }

            if ( '' === $title ) {
                $title = $this->discovery->get_gutenberg_file_block_title(
                    $block_content,
                    ! empty( $file_data['attachment_id'] ) ? absint( $file_data['attachment_id'] ) : 0,
                    $file_data['file_url'],
                    __( 'PDF File', 'pdf-thumbnail-inserter' )
                );
            }

            $max_height   = isset( $settings['max_pdf_preview_height'] ) ? absint( $settings['max_pdf_preview_height'] ) : 600;
            $max_height   = $max_height > 0 ? $max_height : 600;
            $block_height = isset( $attributes['previewHeight'] ) ? absint( $attributes['previewHeight'] ) : 0;
            $height       = $block_height > 0 ? min( $block_height, $max_height ) : $max_height;

            $viewer_url = $this->viewer->get_pdfjs_viewer_url( $file_data['file_url'] );
            if ( '' === $viewer_url ) {
                return $block_content;
            }

            $iframe = sprintf(
                '<iframe class="wp-block-file__embed" style="width: 100%%; height: %1$dpx; border: 0;" src="%2$s" title="%3$s" loading="lazy"></iframe>',
                $height,
                esc_url( $viewer_url ),
                esc_attr( $title )
            );

            return preg_replace( $pattern, $iframe, $block_content, 1 );
        }

        public function render_block( $attributes ) {
            $html = $this->cards->render_shortcode( $attributes );
            if ( '' === $html ) {
                return '';
            }

            return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
        }
}
