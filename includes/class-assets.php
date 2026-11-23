<?php
/** Assets subsystem. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_Assets {
    /** @var PDF_Thumbnail_Inserter_ListingFilters */
    private $listing_filters;

    /** @var PDF_Thumbnail_Inserter_Settings */
    private $settings;

    /** Wire the request-local collaborators before registering any hooks. */
    public function set_dependencies( PDF_Thumbnail_Inserter_ListingFilters $listing_filters, PDF_Thumbnail_Inserter_Settings $settings ) {
        $this->listing_filters = $listing_filters;
        $this->settings = $settings;
    }

        public function add_module_type_to_preview_renderer( $tag, $handle, $src ) {
            if ( ! in_array( $handle, array( 'pdf-thumbnail-browser-maintenance', 'pdf-thumbnail-preview-renderer', 'pdf-thumbnail-viewer' ), true ) ) {
                return $tag;
            }

            // Older WP_Scripts versions do not call wp_get_script_tag(). Only
            // rebuild the matching empty external tag; retain adjacent inline
            // configuration, translations and conditional comments verbatim.
            return preg_replace_callback(
                '~<script\b((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>\s*</script>~i',
                function ( $match ) use ( $handle ) {
                    $parsed = wp_kses_hair( $match[1], wp_allowed_protocols() );
                    if ( empty( $parsed['src'] ) || empty( $parsed['id'] ) || $handle . '-js' !== $parsed['id']['value'] ) {
                        return $match[0];
                    }
                    if ( isset( $parsed['type'] ) && 'module' === $parsed['type']['value'] ) {
                        return $match[0];
                    }
                    $attributes = array();
                    foreach ( $parsed as $name => $attribute ) {
                        $attributes[ $name ] = 'y' === $attribute['vless'] ? true : html_entity_decode( $attribute['value'], ENT_QUOTES, 'UTF-8' );
                    }
                    $attributes['type'] = 'module';
                    return wp_get_script_tag( $attributes );
                },
                $tag
            );
        }

        public function add_module_attributes_to_preview_renderer( $attributes ) {
            $module_ids = array( 'pdf-thumbnail-browser-maintenance-js', 'pdf-thumbnail-preview-renderer-js', 'pdf-thumbnail-viewer-js' );
            if ( isset( $attributes['id'] ) && in_array( $attributes['id'], $module_ids, true ) ) {
                $attributes['type'] = 'module';
            }

            return $attributes;
        }

        public function enqueue_admin_assets( $hook ) {
            $is_settings_page = 'settings_page_pdf-thumbnail-settings' === $hook;
            $is_editor_screen = in_array( $hook, array( 'post.php', 'post-new.php' ), true );

            if ( $is_editor_screen || in_array( $hook, array( 'upload.php', 'media-new.php', 'media-upload-popup' ), true ) ) {
                $this->enqueue_browser_upload_assets();
            }

            if ( $is_settings_page ) {
                wp_enqueue_style( 'wp-color-picker' );
                wp_enqueue_script(
                    'pdf-thumbnail-admin',
                    PDF_THUMBNAIL_INSERTER_URL . 'assets/js/admin.js',
                    array( 'jquery', 'wp-color-picker' ),
                    PDF_Thumbnail_Inserter::VERSION,
                    true
                );
                wp_localize_script( 'pdf-thumbnail-admin', 'pdfThumbnailServerMaintenance', array( 'labels' => array(
                    'start' => __( 'Generate Missing Previews on Server', 'pdf-thumbnail-inserter' ),
                    'resume' => __( 'Continue Generating Previews on Server', 'pdf-thumbnail-inserter' ),
                    'working' => __( 'Generating...', 'pdf-thumbnail-inserter' ),
                    /* translators: 1: WordPress-generated previews, 2: plugin-generated previews, 3: failed PDFs, 4: already-existing previews. */
                    'progress' => __( 'WordPress previews: %1$d · Plugin previews: %2$d · Failed: %3$d · Already present: %4$d', 'pdf-thumbnail-inserter' ),
                    'error' => __( 'Could not process server previews. Refresh the page and try again.', 'pdf-thumbnail-inserter' ),
                ) ) );
                wp_enqueue_style(
                    'pdf-thumbnail-admin',
                    PDF_THUMBNAIL_INSERTER_URL . 'assets/css/admin.css',
                    array(),
                    PDF_Thumbnail_Inserter::VERSION
                );
                wp_enqueue_script(
                    'pdf-thumbnail-browser-maintenance',
                    PDF_THUMBNAIL_INSERTER_URL . 'assets/js/browser-maintenance.js',
                    array(),
                    PDF_Thumbnail_Inserter::VERSION,
                    true
                );
                wp_localize_script(
                    'pdf-thumbnail-browser-maintenance',
                    'pdfThumbnailBrowserMaintenance',
                    array(
                        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                        'nonce'   => wp_create_nonce( PDF_Thumbnail_Inserter::NONCE_ACTION ),
                        'labels'  => array(
                            'checking'       => __( 'Checking PDFs without previews...', 'pdf-thumbnail-inserter' ),
                            'none'           => __( 'No missing PDF previews were found.', 'pdf-thumbnail-inserter' ),
                            /* translators: 1: Current PDF number, 2: Total PDFs, 3: PDF title. */
                            'rendering'      => __( 'Rendering %1$d of %2$d: %3$s', 'pdf-thumbnail-inserter' ),
                            /* translators: 1: Current PDF number, 2: Total PDFs, 3: PDF title. */
                            'saving'         => __( 'Saving %1$d of %2$d: %3$s', 'pdf-thumbnail-inserter' ),
                            /* translators: 1: Generated previews, 2: Skipped PDFs, 3: Failed previews. */
                            'complete'       => __( 'Browser previews complete: %1$d generated, %2$d skipped, %3$d failed.', 'pdf-thumbnail-inserter' ),
                            'failed'         => __( 'Browser preview generation failed.', 'pdf-thumbnail-inserter' ),
                            'buttonWorking'  => __( 'Generating...', 'pdf-thumbnail-inserter' ),
                            'buttonDefault'  => __( 'Generate Missing Previews in Browser', 'pdf-thumbnail-inserter' ),
                            'renderError'    => __( 'Could not render the PDF in this browser.', 'pdf-thumbnail-inserter' ),
                            'uploadError'    => __( 'Could not save the rendered preview.', 'pdf-thumbnail-inserter' ),
                            'unknownError'   => __( 'Unknown error.', 'pdf-thumbnail-inserter' ),
                        ),
                    )
                );
            }

            if ( $is_editor_screen ) {
                wp_enqueue_media();
                wp_enqueue_script(
                    'pdf-thumbnail-classic-editor',
                    PDF_THUMBNAIL_INSERTER_URL . 'assets/js/classic-editor.js',
                    array( 'jquery' ),
                    PDF_Thumbnail_Inserter::VERSION,
                    true
                );
                wp_localize_script(
                    'pdf-thumbnail-classic-editor',
                    'pdfThumbnailClassic',
                    array(
                        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                        'nonce'   => wp_create_nonce( PDF_Thumbnail_Inserter::NONCE_ACTION ),
                        'columns' => $this->settings->get_insert_column_defaults(),
                        'labels'  => array(
                            'selectPdf'       => __( 'Select PDF File', 'pdf-thumbnail-inserter' ),
                            'error'           => __( 'An error occurred while preparing the PDF shortcode.', 'pdf-thumbnail-inserter' ),
                        ),
                    )
                );
            }
        }

        public function enqueue_browser_upload_assets() {
            $settings = $this->settings->get_settings();
            if ( empty( $settings['auto_browser_preview_on_upload'] ) || ! current_user_can( 'upload_files' ) ) {
                return;
            }
            wp_enqueue_script(
                'pdf-thumbnail-browser-upload',
                PDF_THUMBNAIL_INSERTER_URL . 'assets/js/browser-upload.js',
                array( 'wp-plupload', 'wp-api-fetch' ),
                PDF_Thumbnail_Inserter::VERSION,
                true
            );
            wp_localize_script( 'pdf-thumbnail-browser-upload', 'pdfThumbnailBrowserUpload', array(
                'enabled'     => true,
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( PDF_Thumbnail_Inserter::NONCE_ACTION ),
                'rendererUrl' => PDF_THUMBNAIL_INSERTER_URL . 'assets/js/browser-preview.js?ver=' . PDF_Thumbnail_Inserter::VERSION,
                'labels'      => array(
                    'working' => __( 'Creating PDF preview:', 'pdf-thumbnail-inserter' ),
                    'complete' => __( 'PDF previews saved:', 'pdf-thumbnail-inserter' ),
                    'failed' => __( 'PDF previews failed:', 'pdf-thumbnail-inserter' ),
                    'skipped' => __( 'Existing previews kept:', 'pdf-thumbnail-inserter' ),
                    'unknownError' => __( 'Could not generate or save the PDF preview.', 'pdf-thumbnail-inserter' ),
                ),
            ) );
        }

        public function enqueue_block_editor_assets() {
            $settings = $this->settings->get_settings();
            wp_register_script(
                'pdf-thumbnail-block-editor',
                PDF_THUMBNAIL_INSERTER_URL . 'blocks/pdf-thumbnail/index.js',
                array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-server-side-render' ),
                PDF_Thumbnail_Inserter::VERSION,
                true
            );

            wp_localize_script(
                'pdf-thumbnail-block-editor',
                'pdfThumbnailBlock',
                array(
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce'   => wp_create_nonce( PDF_Thumbnail_Inserter::NONCE_ACTION ),
                    'columns' => $this->settings->get_insert_column_defaults(),
                    'defaults' => array(
                        'show_title'  => ! empty( $settings['default_show_title'] ) ? 'yes' : 'no',
                        'show_button' => ! empty( $settings['default_show_button'] ) ? 'yes' : 'no',
                        'new_tab'     => ! empty( $settings['default_new_tab'] ) ? 'yes' : 'no',
                        'link_to'     => $settings['default_link_to'],
                    ),
                    'previewRendererUrl' => PDF_THUMBNAIL_INSERTER_URL . 'assets/js/pdf-thumbnail-preview.js?ver=' . PDF_Thumbnail_Inserter::VERSION,
                )
            );

            wp_register_style(
                'pdf-thumbnail-frontend',
                PDF_THUMBNAIL_INSERTER_URL . 'assets/css/pdf-thumbnail.css',
                array(),
                PDF_Thumbnail_Inserter::VERSION
            );

            wp_register_script(
                'pdf-thumbnail-preview-renderer',
                PDF_THUMBNAIL_INSERTER_URL . 'assets/js/pdf-thumbnail-preview.js',
                array(),
                PDF_Thumbnail_Inserter::VERSION,
                true
            );
            wp_script_add_data( 'pdf-thumbnail-preview-renderer', 'type', 'module' );

            wp_register_style(
                'pdf-thumbnail-viewer',
                PDF_THUMBNAIL_INSERTER_URL . 'assets/pdfjs-viewer/viewer.css',
                array(),
                PDF_Thumbnail_Inserter::VERSION
            );

            wp_register_script(
                'pdf-thumbnail-viewer',
                PDF_THUMBNAIL_INSERTER_URL . 'assets/pdfjs-viewer/viewer.js',
                array(),
                PDF_Thumbnail_Inserter::VERSION,
                true
            );
            wp_script_add_data( 'pdf-thumbnail-viewer', 'type', 'module' );

            wp_register_style(
                'pdf-thumbnail-block-editor-style',
                PDF_THUMBNAIL_INSERTER_URL . 'blocks/pdf-thumbnail/editor.css',
                array( 'pdf-thumbnail-frontend' ),
                PDF_Thumbnail_Inserter::VERSION
            );
        }

        public function enqueue_frontend_listing_assets() {
            if ( ! $this->listing_filters->is_news_or_category_list_context() ) {
                return;
            }

            wp_enqueue_style( 'pdf-thumbnail-frontend' );
            wp_enqueue_script( 'pdf-thumbnail-preview-renderer' );
        }
}
