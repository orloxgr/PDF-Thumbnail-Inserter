<?php
/** Settings subsystem. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_Settings {
    /** @var PDF_Thumbnail_Inserter_Cards */
    private $cards;

    /** Wire the request-local collaborators before registering any hooks. */
    public function set_dependencies( PDF_Thumbnail_Inserter_Cards $cards ) {
        $this->cards = $cards;
    }

        public function get_defaults() {
            return array(
                'button_text'       => __( 'Download PDF', 'pdf-thumbnail-inserter' ),
                'thumbnail_width'   => '212px',
                'title_color'       => '#333333',
                'title_size'        => '14px',
                'button_color'      => '#0073aa',
                'button_hover_color'=> '#005177',
                'button_text_color' => '#ffffff',
                'button_text_size'  => '12px',
                'default_link_to'   => 'file',
                'default_new_tab'   => 1,
                'default_show_title'=> 1,
                'default_show_button'=> 1,
                'use_iframe_pdf_previews'              => 0,
                'max_pdf_preview_height'               => 600,
                'convert_gutenberg_pdf_links_to_cards' => 0,
                'convert_gutenberg_doc_links_to_cards' => 0,
                'use_first_pdf_preview_as_featured_image' => 0,
                'use_default_document_cover'           => 0,
                'auto_browser_preview_on_upload'       => 0,
                'document_cover_label'                 => __( 'Έγγραφο', 'pdf-thumbnail-inserter' ),
                'listing_pages'                        => 'nea',
                'default_columns_widescreen'           => 4,
                'default_columns_desktop'              => 3,
                'default_columns_tablet'               => 2,
                'default_columns_mobile'               => 1,
            );
        }

        public function sanitize_settings( $input ) {
            $defaults = $this->get_defaults();
            $input    = is_array( $input ) ? $input : array();
            // Every option is scalar. Reject nested input before scalar sanitizers/casts.
            $input    = array_filter( $input, 'is_scalar' );
            $out      = array();

            $out['button_text'] = isset( $input['button_text'] ) ? sanitize_text_field( $input['button_text'] ) : $defaults['button_text'];
            $out['thumbnail_width'] = isset( $input['thumbnail_width'] ) ? $this->sanitize_css_dimension( $input['thumbnail_width'], $defaults['thumbnail_width'] ) : $defaults['thumbnail_width'];
            $out['title_color'] = $this->sanitize_hex_color_with_fallback( isset( $input['title_color'] ) ? $input['title_color'] : '', $defaults['title_color'] );
            $out['title_size'] = isset( $input['title_size'] ) ? $this->sanitize_css_dimension( $input['title_size'], $defaults['title_size'] ) : $defaults['title_size'];
            $out['button_color'] = $this->sanitize_hex_color_with_fallback( isset( $input['button_color'] ) ? $input['button_color'] : '', $defaults['button_color'] );
            $out['button_hover_color'] = $this->sanitize_hex_color_with_fallback( isset( $input['button_hover_color'] ) ? $input['button_hover_color'] : '', $defaults['button_hover_color'] );
            $out['button_text_color'] = $this->sanitize_hex_color_with_fallback( isset( $input['button_text_color'] ) ? $input['button_text_color'] : '', $defaults['button_text_color'] );
            $out['button_text_size'] = isset( $input['button_text_size'] ) ? $this->sanitize_css_dimension( $input['button_text_size'], $defaults['button_text_size'] ) : $defaults['button_text_size'];
            $out['default_link_to'] = $this->sanitize_link_to( isset( $input['default_link_to'] ) ? $input['default_link_to'] : $defaults['default_link_to'] );
            $out['default_new_tab'] = ! empty( $input['default_new_tab'] ) ? 1 : 0;
            $out['default_show_title'] = ! empty( $input['default_show_title'] ) ? 1 : 0;
            $out['default_show_button'] = ! empty( $input['default_show_button'] ) ? 1 : 0;
            $out['use_iframe_pdf_previews'] = ! empty( $input['use_iframe_pdf_previews'] ) ? 1 : 0;
            $out['convert_gutenberg_pdf_links_to_cards'] = ! empty( $input['convert_gutenberg_pdf_links_to_cards'] ) ? 1 : 0;
            $out['convert_gutenberg_doc_links_to_cards'] = ! empty( $input['convert_gutenberg_doc_links_to_cards'] ) ? 1 : 0;
            $out['use_first_pdf_preview_as_featured_image'] = ! empty( $input['use_first_pdf_preview_as_featured_image'] ) ? 1 : 0;
            $out['use_default_document_cover'] = ! empty( $input['use_default_document_cover'] ) ? 1 : 0;
            $out['auto_browser_preview_on_upload'] = ! empty( $input['auto_browser_preview_on_upload'] ) ? 1 : 0;
            $cover_label = isset( $input['document_cover_label'] ) ? sanitize_text_field( $input['document_cover_label'] ) : '';
            $out['document_cover_label'] = '' !== $cover_label ? $cover_label : $defaults['document_cover_label'];
            $pages = isset( $input['listing_pages'] ) ? sanitize_text_field( $input['listing_pages'] ) : $defaults['listing_pages'];
            $out['listing_pages'] = implode( ', ', array_filter( array_map( 'trim', explode( ',', $pages ) ) ) );
            foreach ( array( 'widescreen', 'desktop', 'tablet', 'mobile' ) as $device ) {
                $key = 'default_columns_' . $device;
                $out[ $key ] = $this->cards->clamp_columns( isset( $input[ $key ] ) ? $input[ $key ] : $defaults[ $key ], $defaults[ $key ] );
            }
            $max_preview_height = isset( $input['max_pdf_preview_height'] ) ? absint( $input['max_pdf_preview_height'] ) : $defaults['max_pdf_preview_height'];
            $out['max_pdf_preview_height'] = $max_preview_height > 0 ? $max_preview_height : $defaults['max_pdf_preview_height'];

            return $out;
        }

        public function get_insert_column_defaults() {
            $settings = $this->get_settings();
            return array(
                'columns_widescreen' => $this->cards->clamp_columns( $settings['default_columns_widescreen'], 4 ),
                'columns_desktop'    => $this->cards->clamp_columns( $settings['default_columns_desktop'], 3 ),
                'columns_laptop'     => $this->cards->clamp_columns( $settings['default_columns_tablet'], 2 ),
                'columns_mobile'     => $this->cards->clamp_columns( $settings['default_columns_mobile'], 1 ),
            );
        }

        public function sanitize_hex_color_with_fallback( $value, $fallback ) {
            $sanitized = is_scalar( $value ) ? sanitize_hex_color( (string) $value ) : null;
            return $sanitized ? $sanitized : $fallback;
        }

        public function sanitize_css_dimension( $value, $fallback ) {
            if ( ! is_scalar( $value ) ) {
                return $fallback;
            }
            $value = trim( (string) $value );
            return preg_match( '/^\d+(?:\.\d+)?(?:px|em|rem|%)$/', $value ) ? $value : $fallback;
        }

        public function sanitize_link_to( $value ) {
            $allowed = array( 'file', 'attachment', 'custom', 'none' );
            return in_array( $value, $allowed, true ) ? $value : 'file';
        }

        public function get_settings() {
            $settings = get_option( PDF_Thumbnail_Inserter::OPTION_NAME, false );
            if ( false === $settings ) {
                $settings = get_option( PDF_Thumbnail_Inserter::LEGACY_OPTION_NAME, array() );
            }

            return wp_parse_args( is_array( $settings ) ? array_filter( $settings, 'is_scalar' ) : array(), $this->get_defaults() );
        }

        public function maybe_migrate_legacy_options() {
            if ( false !== get_option( PDF_Thumbnail_Inserter::OPTION_NAME, false ) ) {
                return;
            }

            $legacy_settings = get_option( PDF_Thumbnail_Inserter::LEGACY_OPTION_NAME, false );
            if ( false !== $legacy_settings ) {
                update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $legacy_settings, false );
            }
        }
}
