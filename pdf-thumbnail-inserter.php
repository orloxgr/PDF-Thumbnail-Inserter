<?php
/**
 * Plugin Name:       PDF Thumbnail Inserter
 * Plugin URI:        https://github.com/orloxgr/PDF-Thumbnail-Inserter
 * Description:       Generates and reuses PDF thumbnails, provides a shortcode and Gutenberg block, and adds editor helpers for inserting PDF thumbnail cards.
 * Version:           2.01.01
 * Author:            Byron Iniotakis
 * Author URI:        https://github.com/orloxgr
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       pdf-thumbnail-inserter
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'PDF_Thumbnail_Inserter' ) ) {
    final class PDF_Thumbnail_Inserter {
        const VERSION = '2.01.01';
        const TRANSIENT_REGEN_REPORT = 'pdf_thumbnail_inserter_regen_report';
        const TRANSIENT_SERVER_JOB = 'pdf_thumbnail_inserter_server_job';
        const SERVER_SCAN_LIMIT = 100;
        const SERVER_RENDER_LIMIT = 10;
        const OPTION_NAME = 'pdf_thumbnail_inserter_defaults';
        const LEGACY_OPTION_NAME = 'pdf_thumbnail_defaults';
        const NONCE_ACTION = 'pdf_thumbnail_inserter_nonce';
        const ADMIN_ACTION_REGENERATE_MISSING = 'pdf_thumbnail_inserter_regenerate_missing';
        const VIEWER_QUERY_VAR = 'pdf_thumbnail_inserter_viewer';
        const COVER_QUERY_VAR = 'pdf_thumbnail_inserter_cover';
        const META_PREVIEW_ID = '_pdf_thumbnail_preview_id';
        const META_PREVIEW_ERROR = '_pdf_thumbnail_preview_error';
        const META_PREVIEW_GENERATED_AT = '_pdf_thumbnail_preview_generated_at';
        const META_PARENT_PDF = '_pdf_thumbnail_parent_pdf_id';

        /**
         * @var PDF_Thumbnail_Inserter|null
         */
        private static $instance = null;

        /**
         * Request-local cache for PDF featured-image fallbacks.
         *
         * @var array<string,array{id:int,url:string}>
         */
        private $pdf_featured_image_cache = array();

        /**
         * Request-local cache for the first PDF found in post content.
         *
         * @var array<string,array|false>
         */
        private $first_pdf_resolver_cache = array();

        /**
         * Tracks whether a category archive has already rendered its one visual for each post.
         *
         * @var array<int,bool>
         */
        private $category_visual_rendered = array();

        /** @var array<int,array{path:string,lock:resource}> Locks held through core attachment deletion. */
        private $attachment_delete_locks = array();

        /** @var array<string,string> Validated public read-only request parameters. */
        private $public_preview_request = array();

        /** @var string[] Legacy parameters introduced by this plugin, not other routes. */
        private $preview_query_vars = array();

        /** @var array<WP_Post|null> Nested core excerpt contexts. */
        private $excerpt_posts = array();

        /**
         * @return PDF_Thumbnail_Inserter
         */
        public static function instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        private function __construct() {
            define( 'PDF_THUMBNAIL_INSERTER_FILE', __FILE__ );
            define( 'PDF_THUMBNAIL_INSERTER_PATH', plugin_dir_path( __FILE__ ) );
            define( 'PDF_THUMBNAIL_INSERTER_URL', plugin_dir_url( __FILE__ ) );

            add_action( 'init', array( $this, 'load_textdomain' ) );
            add_action( 'change_locale', array( $this, 'load_textdomain' ) );
            add_filter( 'query_vars', array( $this, 'register_preview_query_vars' ), PHP_INT_MAX );
            add_filter( 'request', array( $this, 'parse_public_preview_request' ) );
            add_action( 'added_post_meta', array( $this, 'invalidate_attachment_lookup_metadata' ), 10, 3 );
            add_action( 'updated_post_meta', array( $this, 'invalidate_attachment_lookup_metadata' ), 10, 3 );
            add_action( 'deleted_post_meta', array( $this, 'invalidate_attachment_lookup_metadata' ), 10, 3 );
            add_action( 'init', array( $this, 'register_block' ) );
            add_action( 'admin_menu', array( $this, 'add_settings_menu' ) );
            add_action( 'admin_init', array( $this, 'register_settings' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
            add_action( 'wp_enqueue_media', array( $this, 'enqueue_browser_upload_assets' ) );
            add_action( 'init', array( $this, 'enqueue_block_editor_assets' ) );
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_listing_assets' ) );
            add_action( 'media_buttons', array( $this, 'render_media_button' ) );
            add_action( 'wp_ajax_pdf_thumbnail_inserter_fetch_pdf_data', array( $this, 'ajax_fetch_pdf_data' ) );
            add_action( 'wp_ajax_pdf_thumbnail_inserter_list_missing_previews', array( $this, 'ajax_list_missing_previews' ) );
            add_action( 'wp_ajax_pdf_thumbnail_inserter_save_browser_preview', array( $this, 'ajax_save_browser_preview' ) );
            add_action( 'admin_post_' . self::ADMIN_ACTION_REGENERATE_MISSING, array( $this, 'handle_regenerate_missing_previews' ) );
            add_action( 'delete_attachment', array( $this, 'cleanup_generated_preview' ) );
            add_filter( 'pre_delete_attachment', array( $this, 'protect_pdf_attachment_deletion' ), PHP_INT_MAX, 3 );
            add_action( 'clean_post_cache', array( $this, 'release_deleted_pdf_lock' ) );
            add_action( 'shutdown', array( $this, 'release_pdf_deletion_locks' ) );
            add_shortcode( 'pdf_thumbnail_inserter', array( $this, 'render_shortcode' ) );
            add_shortcode( 'pdf_thumbnail', array( $this, 'render_shortcode' ) );
            add_filter( 'attachment_fields_to_edit', array( $this, 'add_attachment_fields' ), 10, 2 );
            add_filter( 'wp_prepare_attachment_for_js', array( $this, 'add_browser_preview_to_media_response' ), 10, 3 );
            add_filter( 'wp_generate_attachment_metadata', array( $this, 'maybe_generate_pdf_preview_on_upload' ), 20, 3 );
            add_filter( 'render_block_core/file', array( $this, 'replace_pdf_file_block_preview' ), 10, 2 );
            add_filter( 'has_post_thumbnail', array( $this, 'has_first_pdf_preview_as_featured_image' ), 20, 3 );
            add_filter( 'post_thumbnail_id', array( $this, 'use_first_pdf_preview_as_featured_image' ), 20, 2 );
            add_filter( 'post_thumbnail_html', array( $this, 'render_first_pdf_preview_as_featured_image' ), 20, 5 );
            add_filter( 'get_the_excerpt', array( $this, 'begin_excerpt_context' ), PHP_INT_MIN, 2 );
            add_filter( 'get_the_excerpt', array( $this, 'end_excerpt_context' ), PHP_INT_MAX );
            add_filter( 'the_content', array( $this, 'filter_excerpt_attachment_source' ), 1 );
            add_filter( 'the_excerpt', array( $this, 'filter_category_archive_excerpt_output' ), 99 );
            add_filter( 'the_content', array( $this, 'filter_category_archive_content' ), 99 );
            add_action( 'template_redirect', array( $this, 'maybe_render_pdfjs_viewer' ), 0 );
            add_action( 'template_redirect', array( $this, 'maybe_render_document_cover' ), 0 );
            add_action( 'template_redirect', array( $this, 'start_listing_output_buffer' ), 20 );
            add_action( 'elementor/dynamic_tags/register', array( $this, 'register_elementor_featured_image_fallback_tag' ), 100 );
            add_action( 'elementor/widgets/register', array( $this, 'register_elementor_featured_image_fallback_widget' ), 100 );
            add_filter( 'wp_script_attributes', array( $this, 'add_module_attributes_to_preview_renderer' ) );
            add_filter( 'script_loader_tag', array( $this, 'add_module_type_to_preview_renderer' ), 10, 3 );
            add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_action_links' ) );
        }

        public function load_textdomain() {
            global $wp_textdomain_registry;
            if ( $wp_textdomain_registry instanceof WP_Textdomain_Registry ) {
                // Modern core loads the catalogue just in time and handles locale switching.
                $wp_textdomain_registry->set_custom_path( 'pdf-thumbnail-inserter', __DIR__ . '/languages' );
                return;
            }
            // WP 6.0 JIT knows only WP_LANG_DIR; retain GitHub-local catalogues on that core.
            get_translations_for_domain( 'pdf-thumbnail-inserter' );
            if ( ! is_textdomain_loaded( 'pdf-thumbnail-inserter' ) ) {
                $locale = determine_locale();
                if ( preg_match( '/^[A-Za-z0-9_-]+$/D', $locale ) ) {
                    $language_pack = WP_LANG_DIR . '/plugins/pdf-thumbnail-inserter-' . $locale . '.mo';
                    if ( is_readable( $language_pack ) && load_textdomain( 'pdf-thumbnail-inserter', $language_pack ) ) return;
                    $catalogue = __DIR__ . '/languages/pdf-thumbnail-inserter-' . $locale . '.mo';
                    if ( is_readable( $catalogue ) ) load_textdomain( 'pdf-thumbnail-inserter', $catalogue );
                }
            }
        }

        public function register_preview_query_vars( $query_vars ) {
            $this->preview_query_vars = array_diff( array( 'file', 'title', 'shape' ), $query_vars );
            return array_unique( array_merge( $query_vars, array( self::VIEWER_QUERY_VAR, self::COVER_QUERY_VAR, 'file', 'title', 'shape' ) ) );
        }

        /** Route public GET resources through WordPress parsing, without changing the content query. */
        public function parse_public_preview_request( $query_vars ) {
            $this->public_preview_request = array();
            $this->public_preview_request['cover_requested'] = isset( $query_vars[ self::COVER_QUERY_VAR ] ) ? '1' : '';
            foreach ( array( self::VIEWER_QUERY_VAR, self::COVER_QUERY_VAR, 'file', 'title', 'shape' ) as $key ) {
                $value = isset( $query_vars[ $key ] ) && is_scalar( $query_vars[ $key ] ) ? wp_unslash( (string) $query_vars[ $key ] ) : '';
                if ( 'file' === $key ) $value = esc_url_raw( $value );
                elseif ( 'shape' === $key ) $value = sanitize_key( $value );
                else $value = sanitize_text_field( $value );
                $this->public_preview_request[ $key ] = $value;
            }
            foreach ( $this->preview_query_vars as $key ) unset( $query_vars[ $key ] );
            return $query_vars;
        }

        private function invalidate_preview_caches() {
            $this->first_pdf_resolver_cache = array();
            $this->pdf_featured_image_cache = array();
        }

        public function add_plugin_action_links( $links ) {
            $settings_link = sprintf(
                '<a href="%s">%s</a>',
                esc_url( admin_url( 'options-general.php?page=pdf-thumbnail-settings' ) ),
                esc_html__( 'Settings', 'pdf-thumbnail-inserter' )
            );

            array_unshift( $links, $settings_link );

            return $links;
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

        public function add_settings_menu() {
            add_options_page(
                __( 'PDF Thumbnail Settings', 'pdf-thumbnail-inserter' ),
                __( 'PDF Thumbnail Settings', 'pdf-thumbnail-inserter' ),
                'manage_options',
                'pdf-thumbnail-settings',
                array( $this, 'render_settings_page' )
            );
        }

        public function register_settings() {
            $this->maybe_migrate_legacy_options();

            register_setting(
                'pdf_thumbnail_inserter_settings_group',
                self::OPTION_NAME,
                array(
                    'type'              => 'array',
                    'sanitize_callback' => array( $this, 'sanitize_settings' ),
                    'default'           => $this->get_defaults(),
                )
            );
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
                    self::VERSION,
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
                    self::VERSION
                );
                wp_enqueue_script(
                    'pdf-thumbnail-browser-maintenance',
                    PDF_THUMBNAIL_INSERTER_URL . 'assets/js/browser-maintenance.js',
                    array(),
                    self::VERSION,
                    true
                );
                wp_localize_script(
                    'pdf-thumbnail-browser-maintenance',
                    'pdfThumbnailBrowserMaintenance',
                    array(
                        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                        'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
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
                    self::VERSION,
                    true
                );
                wp_localize_script(
                    'pdf-thumbnail-classic-editor',
                    'pdfThumbnailClassic',
                    array(
                        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                        'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
                        'columns' => $this->get_insert_column_defaults(),
                        'labels'  => array(
                            'selectPdf'       => __( 'Select PDF File', 'pdf-thumbnail-inserter' ),
                            'error'           => __( 'An error occurred while preparing the PDF shortcode.', 'pdf-thumbnail-inserter' ),
                        ),
                    )
                );
            }
        }

        public function enqueue_browser_upload_assets() {
            $settings = $this->get_settings();
            if ( empty( $settings['auto_browser_preview_on_upload'] ) || ! current_user_can( 'upload_files' ) ) {
                return;
            }
            wp_enqueue_script(
                'pdf-thumbnail-browser-upload',
                PDF_THUMBNAIL_INSERTER_URL . 'assets/js/browser-upload.js',
                array( 'wp-plupload', 'wp-api-fetch' ),
                self::VERSION,
                true
            );
            wp_localize_script( 'pdf-thumbnail-browser-upload', 'pdfThumbnailBrowserUpload', array(
                'enabled'     => true,
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( self::NONCE_ACTION ),
                'rendererUrl' => PDF_THUMBNAIL_INSERTER_URL . 'assets/js/browser-preview.js?ver=' . self::VERSION,
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
            $settings = $this->get_settings();
            wp_register_script(
                'pdf-thumbnail-block-editor',
                PDF_THUMBNAIL_INSERTER_URL . 'blocks/pdf-thumbnail/index.js',
                array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-server-side-render' ),
                self::VERSION,
                true
            );

            wp_localize_script(
                'pdf-thumbnail-block-editor',
                'pdfThumbnailBlock',
                array(
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
                    'columns' => $this->get_insert_column_defaults(),
                    'defaults' => array(
                        'show_title'  => ! empty( $settings['default_show_title'] ) ? 'yes' : 'no',
                        'show_button' => ! empty( $settings['default_show_button'] ) ? 'yes' : 'no',
                        'new_tab'     => ! empty( $settings['default_new_tab'] ) ? 'yes' : 'no',
                        'link_to'     => $settings['default_link_to'],
                    ),
                    'previewRendererUrl' => PDF_THUMBNAIL_INSERTER_URL . 'assets/js/pdf-thumbnail-preview.js?ver=' . self::VERSION,
                )
            );

            wp_register_style(
                'pdf-thumbnail-frontend',
                PDF_THUMBNAIL_INSERTER_URL . 'assets/css/pdf-thumbnail.css',
                array(),
                self::VERSION
            );

            wp_register_script(
                'pdf-thumbnail-preview-renderer',
                PDF_THUMBNAIL_INSERTER_URL . 'assets/js/pdf-thumbnail-preview.js',
                array(),
                self::VERSION,
                true
            );
            wp_script_add_data( 'pdf-thumbnail-preview-renderer', 'type', 'module' );

            wp_register_style(
                'pdf-thumbnail-viewer',
                PDF_THUMBNAIL_INSERTER_URL . 'assets/pdfjs-viewer/viewer.css',
                array(),
                self::VERSION
            );

            wp_register_script(
                'pdf-thumbnail-viewer',
                PDF_THUMBNAIL_INSERTER_URL . 'assets/pdfjs-viewer/viewer.js',
                array(),
                self::VERSION,
                true
            );
            wp_script_add_data( 'pdf-thumbnail-viewer', 'type', 'module' );

            wp_register_style(
                'pdf-thumbnail-block-editor-style',
                PDF_THUMBNAIL_INSERTER_URL . 'blocks/pdf-thumbnail/editor.css',
                array( 'pdf-thumbnail-frontend' ),
                self::VERSION
            );
        }

        public function enqueue_frontend_listing_assets() {
            if ( ! $this->is_news_or_category_list_context() ) {
                return;
            }

            wp_enqueue_style( 'pdf-thumbnail-frontend' );
            wp_enqueue_script( 'pdf-thumbnail-preview-renderer' );
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
                    'render_callback' => array( $this, 'render_block' ),
                )
            );
        }

        private function get_defaults() {
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
                $out[ $key ] = $this->clamp_columns( isset( $input[ $key ] ) ? $input[ $key ] : $defaults[ $key ], $defaults[ $key ] );
            }
            $max_preview_height = isset( $input['max_pdf_preview_height'] ) ? absint( $input['max_pdf_preview_height'] ) : $defaults['max_pdf_preview_height'];
            $out['max_pdf_preview_height'] = $max_preview_height > 0 ? $max_preview_height : $defaults['max_pdf_preview_height'];

            return $out;
        }

        private function get_insert_column_defaults() {
            $settings = $this->get_settings();
            return array(
                'columns_widescreen' => $this->clamp_columns( $settings['default_columns_widescreen'], 4 ),
                'columns_desktop'    => $this->clamp_columns( $settings['default_columns_desktop'], 3 ),
                'columns_laptop'     => $this->clamp_columns( $settings['default_columns_tablet'], 2 ),
                'columns_mobile'     => $this->clamp_columns( $settings['default_columns_mobile'], 1 ),
            );
        }

        private function sanitize_hex_color_with_fallback( $value, $fallback ) {
            $sanitized = is_scalar( $value ) ? sanitize_hex_color( (string) $value ) : null;
            return $sanitized ? $sanitized : $fallback;
        }

        private function sanitize_css_dimension( $value, $fallback ) {
            if ( ! is_scalar( $value ) ) {
                return $fallback;
            }
            $value = trim( (string) $value );
            return preg_match( '/^\d+(?:\.\d+)?(?:px|em|rem|%)$/', $value ) ? $value : $fallback;
        }

        private function sanitize_link_to( $value ) {
            $allowed = array( 'file', 'attachment', 'custom', 'none' );
            return in_array( $value, $allowed, true ) ? $value : 'file';
        }

        private function get_settings() {
            $settings = get_option( self::OPTION_NAME, false );
            if ( false === $settings ) {
                $settings = get_option( self::LEGACY_OPTION_NAME, array() );
            }

            return wp_parse_args( is_array( $settings ) ? array_filter( $settings, 'is_scalar' ) : array(), $this->get_defaults() );
        }

        private function maybe_migrate_legacy_options() {
            if ( false !== get_option( self::OPTION_NAME, false ) ) {
                return;
            }

            $legacy_settings = get_option( self::LEGACY_OPTION_NAME, false );
            if ( false !== $legacy_settings ) {
                update_option( self::OPTION_NAME, $legacy_settings, false );
            }
        }

        private function metadata_has_pdf_preview( $metadata ) {
            if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
                return false;
            }

            foreach ( $metadata['sizes'] as $size_data ) {
                if ( empty( $size_data['file'] ) ) {
                    continue;
                }

                $mime = ! empty( $size_data['mime-type'] ) ? (string) $size_data['mime-type'] : '';
                $file = (string) $size_data['file'];

                if ( ( $mime && 0 === strpos( $mime, 'image/' ) ) || preg_match( '/\.(?:jpe?g|png|gif|webp)$/i', $file ) ) {
                    return true;
                }
            }

            return false;
        }

        private function get_pdf_preview_generation_status( $attachment_id = 0 ) {
            $status = array(
                'available' => false,
                'reason'    => '',
                'editor_ok' => false,
                'editor'    => '',
                'imagick'   => class_exists( 'Imagick' ),
            );

            if ( ! $status['imagick'] ) {
                $status['reason'] = __( 'PDF preview generation is unavailable on this server because the Imagick PHP extension is missing. If Imagick/ImageMagick/Ghostscript is installed later, refresh this page and preview generation will become available automatically.', 'pdf-thumbnail-inserter' );
                return $status;
            }

            if ( $attachment_id ) {
                $file_path = get_attached_file( absint( $attachment_id ), true );
                if ( ! $file_path || ! file_exists( $file_path ) ) {
                    $status['reason'] = __( 'The sample PDF file could not be found on disk.', 'pdf-thumbnail-inserter' );
                    return $status;
                }

                require_once ABSPATH . 'wp-admin/includes/image.php';
                $editor = wp_get_image_editor( $file_path );

                if ( is_wp_error( $editor ) ) {
                    $status['reason'] = sprintf(
                        /* translators: %s: WordPress image editor error message. */
                        __( 'The sample PDF could not be opened by the WordPress image editor: %s', 'pdf-thumbnail-inserter' ),
                        $editor->get_error_message()
                    );
                    return $status;
                }

                $status['available'] = true;
                $status['editor_ok'] = true;
                $status['editor']    = get_class( $editor );
                $status['reason']    = $status['editor'] ? $status['editor'] : __( 'WordPress can generate PDF previews on this server.', 'pdf-thumbnail-inserter' );
                return $status;
            }

            $status['available'] = true;
            $status['reason']    = __( 'Imagick is available to attempt preview generation. Individual PDFs may still fail; see the sample editor test and per-file results.', 'pdf-thumbnail-inserter' );
            return $status;
        }

        private function get_environment_status() {
            $pdf_ids = get_posts(
                array(
                    'post_type'      => 'attachment',
                    'post_mime_type' => 'application/pdf',
                    'post_status'    => 'inherit',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'orderby'        => 'ID',
                    'order'          => 'ASC',
                )
            );

            $status = array(
                'imagick'             => class_exists( 'Imagick' ),
                'editor_ok'           => false,
                'editor_message'      => __( 'No PDF attachment was found to test WordPress preview generation.', 'pdf-thumbnail-inserter' ),
                'generation_available'=> false,
                'generation_message'  => '',
            );

            $sample_id = ! empty( $pdf_ids ) ? (int) $pdf_ids[0] : 0;
            $generation_status = $this->get_pdf_preview_generation_status( $sample_id );
            $capability_status = $this->get_pdf_preview_generation_status();

            $status['generation_available'] = ! empty( $capability_status['available'] );
            $status['generation_message']   = ! empty( $capability_status['reason'] ) ? (string) $capability_status['reason'] : '';

            if ( ! empty( $pdf_ids ) ) {
                if ( ! empty( $generation_status['editor_ok'] ) ) {
                    $status['editor_ok'] = true;
                    $status['editor_message'] = ! empty( $generation_status['editor'] ) ? (string) $generation_status['editor'] : __( 'WordPress image editor loaded successfully.', 'pdf-thumbnail-inserter' );
                } elseif ( ! empty( $generation_status['reason'] ) ) {
                    $status['editor_message'] = (string) $generation_status['reason'];
                }
            } elseif ( ! empty( $generation_status['reason'] ) ) {
                $status['editor_message'] = (string) $generation_status['reason'];
            }

            return $status;
        }

        public function render_settings_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $settings = $this->get_settings();
            $notice = '';
            $environment = $this->get_environment_status();
            $server_job = $this->get_server_maintenance_job();
            $server_pending = $server_job && ! $server_job['complete'];
            $report = get_transient( self::TRANSIENT_REGEN_REPORT );
            if ( false !== $report ) {
                delete_transient( self::TRANSIENT_REGEN_REPORT );
            }
            if ( $server_job ) {
                $report = $server_job['report'];
            }

            if ( is_array( $report ) ) {
                $notice = sprintf(
                    /* translators: 1: WordPress-generated count, 2: plugin-generated count, 3: failed count, 4: already-existing count. */
                    __( 'WordPress previews: %1$d · Plugin previews: %2$d · Failed: %3$d · Already present: %4$d', 'pdf-thumbnail-inserter' ),
                    isset( $report['wordpress_generated'] ) ? absint( $report['wordpress_generated'] ) : 0,
                    isset( $report['plugin_generated'] ) ? absint( $report['plugin_generated'] ) : 0,
                    isset( $report['failed'] ) ? absint( $report['failed'] ) : 0,
                    isset( $report['already_present'] ) ? absint( $report['already_present'] ) : 0
                );
            }
            ?>
            <div class="wrap pdf-thumbnail-settings-wrap">
                <h1><?php echo esc_html__( 'PDF Thumbnail Inserter', 'pdf-thumbnail-inserter' ); ?></h1>
                <p><?php echo esc_html__( 'Set the default presentation for the shortcode and Gutenberg block, and regenerate missing previews when needed.', 'pdf-thumbnail-inserter' ); ?></p>

                <?php if ( $notice ) : ?>
                    <div class="notice <?php echo ! empty( $report['failed'] ) ? 'notice-warning' : ( $server_pending ? 'notice-info' : 'notice-success' ); ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
                <?php endif; ?>

                <form method="post" action="options.php">
                    <?php settings_fields( 'pdf_thumbnail_inserter_settings_group' ); ?>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="pti-button-text"><?php echo esc_html__( 'Default button text', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td><input id="pti-button-text" class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[button_text]" value="<?php echo esc_attr( $settings['button_text'] ); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-thumbnail-width"><?php echo esc_html__( 'Thumbnail width', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td>
                                    <input id="pti-thumbnail-width" class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[thumbnail_width]" value="<?php echo esc_attr( $settings['thumbnail_width'] ); ?>">
                                    <p class="description"><?php echo esc_html__( 'Examples: 212px, 16rem, 100%', 'pdf-thumbnail-inserter' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-title-color"><?php echo esc_html__( 'Title color', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td><input id="pti-title-color" class="pti-color-field" type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[title_color]" value="<?php echo esc_attr( $settings['title_color'] ); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-title-size"><?php echo esc_html__( 'Title size', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td><input id="pti-title-size" class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[title_size]" value="<?php echo esc_attr( $settings['title_size'] ); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-button-color"><?php echo esc_html__( 'Button color', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td><input id="pti-button-color" class="pti-color-field" type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[button_color]" value="<?php echo esc_attr( $settings['button_color'] ); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-button-hover-color"><?php echo esc_html__( 'Button hover color', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td><input id="pti-button-hover-color" class="pti-color-field" type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[button_hover_color]" value="<?php echo esc_attr( $settings['button_hover_color'] ); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-button-text-color"><?php echo esc_html__( 'Button text color', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td><input id="pti-button-text-color" class="pti-color-field" type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[button_text_color]" value="<?php echo esc_attr( $settings['button_text_color'] ); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-button-text-size"><?php echo esc_html__( 'Button text size', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td><input id="pti-button-text-size" class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[button_text_size]" value="<?php echo esc_attr( $settings['button_text_size'] ); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-default-link-to"><?php echo esc_html__( 'Default link target', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td>
                                    <select id="pti-default-link-to" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_link_to]">
                                        <option value="file" <?php selected( $settings['default_link_to'], 'file' ); ?>><?php echo esc_html__( 'PDF file', 'pdf-thumbnail-inserter' ); ?></option>
                                        <option value="attachment" <?php selected( $settings['default_link_to'], 'attachment' ); ?>><?php echo esc_html__( 'Attachment page', 'pdf-thumbnail-inserter' ); ?></option>
                                        <option value="custom" <?php selected( $settings['default_link_to'], 'custom' ); ?>><?php echo esc_html__( 'Custom URL', 'pdf-thumbnail-inserter' ); ?></option>
                                        <option value="none" <?php selected( $settings['default_link_to'], 'none' ); ?>><?php echo esc_html__( 'No link', 'pdf-thumbnail-inserter' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Default visibility', 'pdf-thumbnail-inserter' ); ?></th>
                                <td>
                                    <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_show_title]" value="1" <?php checked( ! empty( $settings['default_show_title'] ) ); ?>> <?php echo esc_html__( 'Show title', 'pdf-thumbnail-inserter' ); ?></label><br>
                                    <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_show_button]" value="1" <?php checked( ! empty( $settings['default_show_button'] ) ); ?>> <?php echo esc_html__( 'Show button', 'pdf-thumbnail-inserter' ); ?></label><br>
                                    <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_new_tab]" value="1" <?php checked( ! empty( $settings['default_new_tab'] ) ); ?>> <?php echo esc_html__( 'Open links in a new tab by default', 'pdf-thumbnail-inserter' ); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Χρήση PDF.js για προεπισκόπηση PDF', 'pdf-thumbnail-inserter' ); ?></th>
                                <td>
                                    <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[use_iframe_pdf_previews]" value="1" <?php checked( ! empty( $settings['use_iframe_pdf_previews'] ) ); ?>> <?php echo esc_html__( 'Αντικαθιστά τις προεπισκοπήσεις PDF των μπλοκ Αρχείου WordPress με τοπικό PDF.js viewer μέσα σε iframe.', 'pdf-thumbnail-inserter' ); ?></label>
                                    <p class="description"><?php echo esc_html__( 'Επηρεάζονται μόνο PDF blocks στα οποία είναι ήδη ενεργοποιημένη η προεπισκόπηση.', 'pdf-thumbnail-inserter' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-max-pdf-preview-height"><?php echo esc_html__( 'Μέγιστο ύψος προεπισκόπησης PDF', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td>
                                    <input id="pti-max-pdf-preview-height" class="small-text" type="number" min="1" step="1" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[max_pdf_preview_height]" value="<?php echo esc_attr( $settings['max_pdf_preview_height'] ); ?>"> px
                                    <p class="description"><?php echo esc_html__( 'Αν το ύψος του block είναι μικρότερο, διατηρείται το μικρότερο ύψος. Προεπιλογή: 600px.', 'pdf-thumbnail-inserter' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Convert Gutenberg PDF links to cards', 'pdf-thumbnail-inserter' ); ?></th>
                                <td>
                                    <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[convert_gutenberg_pdf_links_to_cards]" value="1" <?php checked( ! empty( $settings['convert_gutenberg_pdf_links_to_cards'] ) ); ?>> <?php echo esc_html__( 'Convert WordPress File blocks that link to PDFs into PDF Thumbnail Inserter cards.', 'pdf-thumbnail-inserter' ); ?></label>
                                    <p class="description"><?php echo esc_html__( 'Applies to all Gutenberg File blocks that point to PDFs, including blocks that currently show the default WordPress preview.', 'pdf-thumbnail-inserter' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Convert Gutenberg DOC/DOCX links to cards', 'pdf-thumbnail-inserter' ); ?></th>
                                <td>
                                    <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[convert_gutenberg_doc_links_to_cards]" value="1" <?php checked( ! empty( $settings['convert_gutenberg_doc_links_to_cards'] ) ); ?>> <?php echo esc_html__( 'Convert WordPress File blocks that link to DOC or DOCX files into document cards.', 'pdf-thumbnail-inserter' ); ?></label>
                                    <p class="description"><?php echo esc_html__( 'Word document cards use a separate placeholder image. Saved post content is not modified.', 'pdf-thumbnail-inserter' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Featured image / PDF preview in listings', 'pdf-thumbnail-inserter' ); ?></th>
                                <td>
                                    <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[use_first_pdf_preview_as_featured_image]" value="1" <?php checked( ! empty( $settings['use_first_pdf_preview_as_featured_image'] ) ); ?>> <?php echo esc_html__( 'Use the first PDF when a listing post has no featured image, and enable the Elementor widget “Featured Image or First PDF Preview”.', 'pdf-thumbnail-inserter' ); ?></label>
                                    <p class="description"><?php echo esc_html__( 'Adds the Elementor widget “Featured Image or First PDF Preview”. A real featured image always takes priority, and no featured image is written to the database.', 'pdf-thumbnail-inserter' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <h2><?php echo esc_html__( 'Automatic browser previews on upload', 'pdf-thumbnail-inserter' ); ?></h2>
                    <p><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auto_browser_preview_on_upload]" value="1" <?php checked( ! empty( $settings['auto_browser_preview_on_upload'] ) ); ?>> <?php echo esc_html__( 'Automatically create missing PDF previews in my browser after upload', 'pdf-thumbnail-inserter' ); ?></label></p>
                    <p class="description"><?php echo esc_html__( 'WordPress and server-generated previews are kept first. When a newly uploaded PDF has no preview, this browser renders its first page with local PDF.js and saves a reusable image. Keep the upload tab open until generation finishes. Applies to Media Library uploads and editor media dialogs for users who can upload and edit the PDF.', 'pdf-thumbnail-inserter' ); ?></p>
                    <h2><?php echo esc_html__( 'Defaults for new inserts', 'pdf-thumbnail-inserter' ); ?></h2>
                    <p><?php echo esc_html__( 'New Classic Editor inserts and new Gutenberg blocks use these columns automatically. Previously saved shortcodes and blocks keep their column settings.', 'pdf-thumbnail-inserter' ); ?></p>
                    <table class="form-table" role="presentation"><tbody>
                        <?php foreach ( array( 'widescreen' => __( 'Widescreen columns (1600px and up)', 'pdf-thumbnail-inserter' ), 'desktop' => __( 'Desktop columns (1181–1599px)', 'pdf-thumbnail-inserter' ), 'tablet' => __( 'Tablet columns (768–1180px)', 'pdf-thumbnail-inserter' ), 'mobile' => __( 'Mobile columns (up to 767px)', 'pdf-thumbnail-inserter' ) ) as $device => $column_label ) : ?>
                            <tr>
                                <th scope="row"><label for="pti-columns-<?php echo esc_attr( $device ); ?>"><?php echo esc_html( $column_label ); ?></label></th>
                                <td><input id="pti-columns-<?php echo esc_attr( $device ); ?>" class="small-text" type="number" min="1" max="6" step="1" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_columns_<?php echo esc_attr( $device ); ?>]" value="<?php echo esc_attr( $settings[ 'default_columns_' . $device ] ); ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <th scope="row"><label for="pti-listing-pages"><?php echo esc_html__( 'Additional listing pages', 'pdf-thumbnail-inserter' ); ?></label></th>
                            <td><input id="pti-listing-pages" class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[listing_pages]" value="<?php echo esc_attr( $settings['listing_pages'] ); ?>"><p class="description"><?php echo esc_html__( 'Comma-separated page IDs or slugs, for example: news, announcements. Category archives and the WordPress posts page are included automatically. Leave empty if none.', 'pdf-thumbnail-inserter' ); ?></p></td>
                        </tr>
                    </tbody></table>
                    <h2><?php echo esc_html__( 'Fallback cover for PDFs without previews', 'pdf-thumbnail-inserter' ); ?></h2>
                    <p><?php echo esc_html__( 'When a PDF has no preview image, show a document cover with the PDF title using the existing button and title colors. This applies to PDF cards and listing previews. Posts without a PDF are unchanged. A real preview always takes priority.', 'pdf-thumbnail-inserter' ); ?></p>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Enable default cover', 'pdf-thumbnail-inserter' ); ?></th>
                                <td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[use_default_document_cover]" value="1" <?php checked( ! empty( $settings['use_default_document_cover'] ) ); ?>> <?php echo esc_html__( 'Show a cover with the PDF title when the PDF has no preview', 'pdf-thumbnail-inserter' ); ?></label></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-document-cover-label"><?php echo esc_html__( 'Document label', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td><input id="pti-document-cover-label" class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[document_cover_label]" value="<?php echo esc_attr( $settings['document_cover_label'] ); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Saved cover preview', 'pdf-thumbnail-inserter' ); ?></th>
                                <td><div style="max-width:480px"><?php echo $this->render_document_cover_svg( __( 'PDF title', 'pdf-thumbnail-inserter' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div></td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button(); ?>
                </form>

                <hr>

                <h2><?php echo esc_html__( 'Maintenance', 'pdf-thumbnail-inserter' ); ?></h2>
                <p><?php echo esc_html__( 'Generate previews for existing PDF attachments that do not already have one.', 'pdf-thumbnail-inserter' ); ?></p>
                <p>
                    <strong><?php echo esc_html__( 'Imagick:', 'pdf-thumbnail-inserter' ); ?></strong>
                    <?php echo $environment['imagick'] ? esc_html__( 'Available', 'pdf-thumbnail-inserter' ) : esc_html__( 'Missing', 'pdf-thumbnail-inserter' ); ?>
                    <br>
                    <strong><?php echo esc_html__( 'WordPress PDF editor test:', 'pdf-thumbnail-inserter' ); ?></strong>
                    <?php echo $environment['editor_ok'] ? esc_html__( 'Available', 'pdf-thumbnail-inserter' ) : esc_html__( 'Unavailable', 'pdf-thumbnail-inserter' ); ?>
                    — <?php echo esc_html( $environment['editor_message'] ); ?>
                    <br>
                    <strong><?php echo esc_html__( 'Server preview generation status:', 'pdf-thumbnail-inserter' ); ?></strong>
                    <?php echo ! empty( $environment['generation_available'] ) ? esc_html__( 'Available', 'pdf-thumbnail-inserter' ) : esc_html__( 'Unavailable', 'pdf-thumbnail-inserter' ); ?>
                    — <?php echo esc_html( $environment['generation_message'] ); ?>
                </p>
                <?php if ( ! empty( $report['errors'] ) && is_array( $report['errors'] ) ) : ?>
                    <div class="notice notice-warning inline"><p><?php echo esc_html__( 'Recent preview generation errors:', 'pdf-thumbnail-inserter' ); ?><br><?php echo esc_html( implode( ' | ', array_slice( $report['errors'], 0, 5 ) ) ); ?></p></div>
                <?php endif; ?>
                <?php if ( empty( $environment['generation_available'] ) ) : ?>
                    <div class="notice notice-info inline"><p><?php echo esc_html( $environment['generation_message'] ); ?></p></div>
                <?php endif; ?>
                <form id="pti-server-maintenance-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ADMIN_ACTION_REGENERATE_MISSING ); ?>">
                    <input type="hidden" name="job_id" value="<?php echo $server_pending ? esc_attr( $server_job['id'] ) : ''; ?>">
                    <?php wp_nonce_field( self::ADMIN_ACTION_REGENERATE_MISSING ); ?>
                    <?php submit_button( $server_pending ? __( 'Continue Generating Previews on Server', 'pdf-thumbnail-inserter' ) : __( 'Generate Missing Previews on Server', 'pdf-thumbnail-inserter' ), 'secondary', 'submit', false, array_merge( array( 'id' => 'pti-server-generate-previews' ), empty( $environment['generation_available'] ) ? array( 'disabled' => 'disabled' ) : array() ) ); ?>
                    <p class="description"><?php echo esc_html__( 'Progress is saved in small batches. Keep this page open to finish automatically; if interrupted, return here to continue. Without JavaScript, use Continue after each batch.', 'pdf-thumbnail-inserter' ); ?></p>
                    <progress id="pti-server-preview-progress" class="pti-browser-preview-progress" hidden></progress>
                    <span id="pti-server-preview-status" class="pti-browser-preview-status" aria-live="polite"></span>
                    <ul id="pti-server-preview-log" class="pti-browser-preview-log" aria-live="polite"></ul>
                    <?php if ( empty( $environment['generation_available'] ) ) : ?>
                        <p class="description"><?php echo esc_html__( 'This server button will automatically become available after the server gains PDF preview support and you refresh the page.', 'pdf-thumbnail-inserter' ); ?></p>
                    <?php endif; ?>
                </form>

                <div class="pti-browser-maintenance" data-pti-browser-maintenance>
                    <h3><?php echo esc_html__( 'Browser PDF.js preview generation', 'pdf-thumbnail-inserter' ); ?></h3>
                    <p><?php echo esc_html__( 'Generate missing previews in this browser with the bundled PDF.js runtime. This does not require server-side PDF rendering support.', 'pdf-thumbnail-inserter' ); ?></p>
                    <p>
                        <strong><?php echo esc_html__( 'Browser PDF.js fallback:', 'pdf-thumbnail-inserter' ); ?></strong>
                        <?php echo esc_html__( 'Available', 'pdf-thumbnail-inserter' ); ?>
                    </p>
                    <button type="button" class="button button-primary" id="pti-browser-generate-previews"><?php echo esc_html__( 'Generate Missing Previews in Browser', 'pdf-thumbnail-inserter' ); ?></button>
                    <progress id="pti-browser-preview-progress" class="pti-browser-preview-progress" value="0" max="1" hidden></progress>
                    <span id="pti-browser-preview-status" class="pti-browser-preview-status" aria-live="polite"></span>
                    <ul id="pti-browser-preview-log" class="pti-browser-preview-log" aria-live="polite"></ul>
                </div>
            </div>
            <?php
        }

        public function render_media_button() {
            if ( ! current_user_can( 'upload_files' ) ) {
                return;
            }

            echo '<button type="button" class="button" id="insert-pdf-thumbnail">' . esc_html__( 'Insert PDF Thumbnail', 'pdf-thumbnail-inserter' ) . '</button>';
        }

        public function ajax_fetch_pdf_data() {
            check_ajax_referer( self::NONCE_ACTION, 'nonce' );

            if ( ! current_user_can( 'upload_files' ) ) {
                wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'pdf-thumbnail-inserter' ) ), 403 );
            }

            $attachment_id = isset( $_POST['attachment_id'] ) && is_scalar( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
            if ( ! $attachment_id ) {
                wp_send_json_error( array( 'message' => __( 'Invalid attachment ID.', 'pdf-thumbnail-inserter' ) ), 400 );
            }

            if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'pdf-thumbnail-inserter' ) ), 403 );
            }

            if ( 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Selected file is not a PDF.', 'pdf-thumbnail-inserter' ) ), 400 );
            }

            $preview_data = $this->get_pdf_preview_data( $attachment_id, 'medium' );

            wp_send_json_success(
                array(
                    'attachment_id' => $attachment_id,
                    'thumbnail_url' => $preview_data['thumbnail_url'],
                    'file_url'      => $preview_data['file_url'],
                    'file_title'    => $preview_data['title'],
                    'preview_source' => $preview_data['preview_source'],
                    'shortcode'     => sprintf( '[pdf_thumbnail_inserter id="%d"]', $attachment_id ),
                )
            );
        }

        private function get_valid_local_pdf_attachment_file( $attachment_id ) {
            $attachment_id = absint( $attachment_id );
            if ( ! $attachment_id || 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
                return new WP_Error( 'invalid_pdf', __( 'Attachment is not a valid PDF.', 'pdf-thumbnail-inserter' ) );
            }

            $file_path = get_attached_file( $attachment_id, true );
            if ( ! $file_path || ! file_exists( $file_path ) ) {
                return new WP_Error( 'missing_file', __( 'Original PDF file could not be found.', 'pdf-thumbnail-inserter' ) );
            }

            $file_realpath = realpath( $file_path );
            if ( ! $file_realpath || ! is_file( $file_realpath ) || ! is_readable( $file_realpath ) ) {
                return new WP_Error( 'unreadable_file', __( 'Original PDF file is not readable.', 'pdf-thumbnail-inserter' ) );
            }

            if ( 'pdf' !== strtolower( pathinfo( $file_realpath, PATHINFO_EXTENSION ) ) ) {
                return new WP_Error( 'invalid_extension', __( 'Attachment file is not a PDF.', 'pdf-thumbnail-inserter' ) );
            }

            $uploads = wp_get_upload_dir();
            $uploads_basedir = ! empty( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : '';
            if ( ! $uploads_basedir ) {
                return new WP_Error( 'uploads_missing', __( 'Uploads directory could not be resolved.', 'pdf-thumbnail-inserter' ) );
            }

            $uploads_prefix = trailingslashit( wp_normalize_path( $uploads_basedir ) );
            $file_normalized = wp_normalize_path( $file_realpath );
            $inside_uploads = '\\' === DIRECTORY_SEPARATOR ? 0 === stripos( $file_normalized, $uploads_prefix ) : 0 === strpos( $file_normalized, $uploads_prefix );
            if ( ! $inside_uploads ) {
                return new WP_Error( 'outside_uploads', __( 'PDF file is outside the uploads directory.', 'pdf-thumbnail-inserter' ) );
            }

            $file_url = wp_get_attachment_url( $attachment_id );
            if ( ! $file_url ) {
                return new WP_Error( 'missing_url', __( 'PDF file URL could not be resolved.', 'pdf-thumbnail-inserter' ) );
            }

            $title = get_the_title( $attachment_id );

            return array(
                'path'  => $file_realpath,
                'url'   => $file_url,
                'title' => $title ? $title : __( 'PDF File', 'pdf-thumbnail-inserter' ),
            );
        }

        public function ajax_list_missing_previews() {
            check_ajax_referer( self::NONCE_ACTION, 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'pdf-thumbnail-inserter' ) ), 403 );
            }

            // The browser opts into bounded discovery; keep the legacy unpaged response available.
            $page = isset( $_POST['page'] ) && is_scalar( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 0;
            $pdf_ids = get_posts(
                array(
                    'post_type'      => 'attachment',
                    'post_mime_type' => 'application/pdf',
                    'post_status'    => 'inherit',
                    'posts_per_page' => $page ? 100 : -1,
                    'paged'          => $page ? $page : 1,
                    'fields'         => 'ids',
                    'orderby'        => 'ID',
                    'order'          => 'ASC',
                )
            );

            $items = array();
            $skipped = 0;

            // An IDs-only WP_Query does not prime post/meta caches on WP 6.0.
            // Prime only the bounded page; the legacy unpaged response stays compatible.
            if ( $page && $pdf_ids ) {
                _prime_post_caches( $pdf_ids, false, true );
            }

            foreach ( $pdf_ids as $pdf_id ) {
                $pdf_id = absint( $pdf_id );
                // Discovery must not start expensive server-side rendering for every PDF.
                $preview_data = $this->get_pdf_preview_data( $pdf_id, 'full', false );
                if ( 'placeholder' !== $preview_data['preview_source'] ) {
                    continue;
                }

                $file_data = $this->get_valid_local_pdf_attachment_file( $pdf_id );
                if ( is_wp_error( $file_data ) ) {
                    $skipped++;
                    continue;
                }

                $items[] = array(
                    'id'       => $pdf_id,
                    'title'    => $file_data['title'],
                    'file_url' => esc_url_raw( $file_data['url'] ),
                );
            }

            wp_send_json_success(
                array(
                    'items'   => $items,
                    'total'   => count( $items ),
                    'skipped' => $skipped,
                    'next_page' => $page && 100 === count( $pdf_ids ) ? $page + 1 : 0,
                )
            );
        }

        private function can_save_browser_preview( $attachment_id, $automatic = false ) {
            if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
                return false;
            }
            $settings = $this->get_settings();
            if ( $automatic || ! current_user_can( 'manage_options' ) ) {
                return ! empty( $settings['auto_browser_preview_on_upload'] );
            }
            return true;
        }

        /**
         * Serialize plugin preview writers for a validated, local PDF.
         *
         * The zero-byte lock file is retained: unlinking a flock file permits
         * waiting and newly arriving processes to lock different inodes.
         * OS locks are released on process termination, so crashes cannot leave
         * a permanently busy PDF. WP_Filesystem does not expose stream locks.
         *
         * @param string   $pdf_path Validated real path inside uploads.
         * @param callable $callback Work to perform while holding the lock.
         * @return mixed|WP_Error Callback result, or a lock error.
         */
        private function with_pdf_preview_lock( $pdf_path, $callback ) {
            return $this->with_preview_file_lock( $pdf_path . '.pti-preview.lock', function () use ( $pdf_path, $callback ) {
                // A deletion can finish after a writer validated its path but before it obtained the lock.
                clearstatcache( true, $pdf_path );
                if ( ! is_file( $pdf_path ) || ! is_readable( $pdf_path ) ) {
                    return new WP_Error( 'missing_file', __( 'Original PDF file could not be found.', 'pdf-thumbnail-inserter' ) );
                }
                return call_user_func( $callback );
            } );
        }

        /** Native locks are also used to serialize each administrator's maintenance cursor. */
        private function with_preview_file_lock( $lock_path, $callback ) {
            $lock = $this->acquire_preview_file_lock( $lock_path );
            if ( is_wp_error( $lock ) ) return $lock;
            try {
                return call_user_func( $callback );
            } finally {
                fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Releases the native OS lock.
            }
        }

        private function acquire_preview_file_lock( $lock_path ) {
            if ( is_link( $lock_path ) || ( file_exists( $lock_path ) && ! is_file( $lock_path ) ) ) {
                return new WP_Error( 'preview_lock_failed', __( 'Could not lock PDF preview generation.', 'pdf-thumbnail-inserter' ) );
            }

            // A native stream is required for the OS advisory lock; no credentials or remote filesystem are involved.
            $lock = @fopen( $lock_path, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            if ( false === $lock ) {
                return new WP_Error( 'preview_lock_failed', __( 'Could not lock PDF preview generation.', 'pdf-thumbnail-inserter' ) );
            }

            if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
                fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Failed native lock must be closed.
                return new WP_Error( 'preview_busy', __( 'A preview operation is already running. Please try again shortly.', 'pdf-thumbnail-inserter' ) );
            }
            return $lock;
        }

        public function ajax_save_browser_preview() {
            check_ajax_referer( self::NONCE_ACTION, 'nonce' );

            $attachment_id = isset( $_POST['attachment_id'] ) && is_scalar( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
            if ( ! $attachment_id ) {
                wp_send_json_error( array( 'message' => __( 'Invalid attachment ID.', 'pdf-thumbnail-inserter' ) ), 400 );
            }

            if ( ! $this->can_save_browser_preview( $attachment_id, ! empty( $_POST['automatic'] ) ) ) {
                wp_send_json_error( array( 'message' => __( 'Insufficient permissions or automatic browser previews are disabled.', 'pdf-thumbnail-inserter' ) ), 403 );
            }

            $file_data = $this->get_valid_local_pdf_attachment_file( $attachment_id );
            if ( is_wp_error( $file_data ) ) {
                wp_send_json_error( array( 'message' => $file_data->get_error_message() ), 400 );
            }

            // Raw upload fields are shape-checked below; wp_handle_upload validates the HTTP upload and MIME.
            $uploaded_file = isset( $_FILES['preview'] ) ? $_FILES['preview'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $result = $this->with_pdf_preview_lock(
                $file_data['path'],
                function () use ( $attachment_id, $file_data, $uploaded_file ) {
                    return $this->save_browser_preview( $attachment_id, $file_data, $uploaded_file );
                }
            );
            if ( is_wp_error( $result ) ) {
                $details = $result->get_error_data();
                $status = 'preview_busy' === $result->get_error_code() ? 409 : ( isset( $details['status'] ) ? $details['status'] : 500 );
                wp_send_json_error( array( 'message' => $result->get_error_message() ), $status );
            }
            wp_send_json_success( $result );
        }

        private function save_browser_preview( $attachment_id, $file_data, $uploaded_file ) {
            clean_post_cache( $attachment_id );
            $current_file = $this->get_valid_local_pdf_attachment_file( $attachment_id );
            if ( is_wp_error( $current_file ) ) return $current_file;
            wp_cache_delete( $attachment_id, 'post_meta' );
            $current_preview = $this->get_pdf_preview_data( $attachment_id, 'full', false );
            if ( 'placeholder' !== $current_preview['preview_source'] ) {
                return array(
                        'skipped'        => true,
                        'preview_source' => $current_preview['preview_source'],
                        'preview_id'     => ! empty( $current_preview['preview_id'] ) ? absint( $current_preview['preview_id'] ) : 0,
                );
            }

            if ( ! is_array( $uploaded_file ) ) {
                return new WP_Error( 'browser_preview_failed', __( 'Missing preview image upload.', 'pdf-thumbnail-inserter' ), array( 'status' => 400 ) );
            }

            foreach ( array( 'name', 'tmp_name', 'type', 'error', 'size' ) as $field ) {
                if ( ! isset( $uploaded_file[ $field ] ) || ! is_scalar( $uploaded_file[ $field ] ) ) {
                    return new WP_Error( 'browser_preview_failed', __( 'Invalid preview image upload.', 'pdf-thumbnail-inserter' ), array( 'status' => 400 ) );
                }
            }

            $upload_error = absint( $uploaded_file['error'] );
            if ( UPLOAD_ERR_OK !== $upload_error ) {
                return new WP_Error( 'browser_preview_failed', __( 'Preview image upload failed.', 'pdf-thumbnail-inserter' ), array( 'status' => 400 ) );
            }

            $uploaded_size = absint( $uploaded_file['size'] );
            if ( ! $uploaded_size || $uploaded_size > 8 * MB_IN_BYTES ) {
                return new WP_Error( 'browser_preview_failed', __( 'Preview image upload is too large or empty.', 'pdf-thumbnail-inserter' ), array( 'status' => 400 ) );
            }

            $uploaded_name = sanitize_file_name( wp_unslash( (string) $uploaded_file['name'] ) );
            if ( ! $uploaded_name ) {
                $uploaded_name = sprintf( 'pdf-%d-browser-preview.jpg', $attachment_id );
            }
            $uploaded_file['name'] = $uploaded_name;

            $allowed_mimes = array(
                'jpg|jpeg|jpe' => 'image/jpeg',
                'png'          => 'image/png',
            );
            $filetype = wp_check_filetype_and_ext(
                $uploaded_file['tmp_name'],
                $uploaded_name,
                $allowed_mimes
            );

            if ( empty( $filetype['type'] ) || ! in_array( $filetype['type'], array( 'image/jpeg', 'image/png' ), true ) ) {
                return new WP_Error( 'browser_preview_failed', __( 'Preview upload must be a JPEG or PNG image.', 'pdf-thumbnail-inserter' ), array( 'status' => 400 ) );
            }

            // Compressed upload size alone does not bound image decoding memory.
            $dimensions = wp_getimagesize( $uploaded_file['tmp_name'] );
            if ( ! $dimensions || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 4096 || $dimensions[1] > 4096 ) {
                return new WP_Error( 'browser_preview_failed', __( 'Preview image dimensions must be between 1 and 4096 pixels.', 'pdf-thumbnail-inserter' ), array( 'status' => 400 ) );
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $upload = wp_handle_upload(
                $uploaded_file,
                array(
                    'test_form' => false,
                    'mimes'     => $allowed_mimes,
                )
            );

            if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) || empty( $upload['type'] ) ) {
                $message = ! empty( $upload['error'] ) ? $upload['error'] : __( 'WordPress could not store the preview image.', 'pdf-thumbnail-inserter' );
                return new WP_Error( 'browser_preview_failed', sanitize_text_field( $message ), array( 'status' => 500 ) );
            }

            return $this->create_pdf_preview_attachment( $attachment_id, $upload['file'], $upload['type'], $file_data['title'] );
        }

        /** Publish an owned image only after metadata succeeds; roll back failed writes. */
        private function create_pdf_preview_attachment( $attachment_id, $path, $mime, $title ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $old_preview_id = absint( get_post_meta( $attachment_id, self::META_PREVIEW_ID, true ) );
            $preview_id = 0;
            $published = false;
            $metadata = array();
            try {
                clean_post_cache( $attachment_id );
                $parent_file = $this->get_valid_local_pdf_attachment_file( $attachment_id );
                if ( is_wp_error( $parent_file ) ) return $parent_file;
                $inserted = wp_insert_attachment(
                    array(
                        'post_mime_type' => $mime,
                        'post_title'     => $title . ' ' . __( 'Preview', 'pdf-thumbnail-inserter' ),
                        'post_content'   => '',
                        'post_status'    => 'inherit',
                        'post_parent'    => $attachment_id,
                    ),
                    $path,
                    $attachment_id,
                    true
                );
                if ( is_wp_error( $inserted ) ) {
                    return $inserted;
                }
                $preview_id = $inserted;
                $metadata = wp_generate_attachment_metadata( $preview_id, $path );
                if ( is_wp_error( $metadata ) || empty( $metadata['width'] ) || empty( $metadata['height'] ) ) {
                    return new WP_Error( 'preview_metadata_failed', __( 'Could not create preview image metadata.', 'pdf-thumbnail-inserter' ) );
                }
                wp_update_attachment_metadata( $preview_id, $metadata );
                // A false return may mean unchanged data; verify the persisted record instead.
                $stored_metadata = wp_get_attachment_metadata( $preview_id, true );
                if ( ! is_array( $stored_metadata ) || empty( $stored_metadata['file'] ) || empty( $stored_metadata['width'] ) || empty( $stored_metadata['height'] ) ) {
                    return new WP_Error( 'preview_metadata_failed', __( 'Could not save preview image metadata.', 'pdf-thumbnail-inserter' ) );
                }
                $thumbnail_url = $this->get_image_url_with_size_fallback( $preview_id, 'medium' );
                if ( ! get_attached_file( $preview_id, true ) || '' === $thumbnail_url ) {
                    return new WP_Error( 'preview_metadata_failed', __( 'Could not save the preview image file reference.', 'pdf-thumbnail-inserter' ) );
                }
                if ( 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
                    return new WP_Error( 'invalid_pdf', __( 'The original PDF attachment no longer exists.', 'pdf-thumbnail-inserter' ) );
                }
                update_post_meta( $preview_id, self::META_PARENT_PDF, $attachment_id );
                update_post_meta( $attachment_id, self::META_PREVIEW_ID, $preview_id );
                if ( ! $this->is_owned_pdf_preview( $preview_id, $attachment_id ) || $preview_id !== absint( get_post_meta( $attachment_id, self::META_PREVIEW_ID, true ) ) ) {
                    return new WP_Error( 'preview_metadata_failed', __( 'Could not save preview ownership metadata.', 'pdf-thumbnail-inserter' ) );
                }
                $published = true;
                $this->invalidate_preview_caches();
                update_post_meta( $attachment_id, self::META_PREVIEW_GENERATED_AT, time() );
                delete_post_meta( $attachment_id, self::META_PREVIEW_ERROR );
                if ( $this->is_owned_pdf_preview( $old_preview_id, $attachment_id ) ) {
                    wp_delete_attachment( $old_preview_id, true );
                }
                return array(
                    'generated'      => true,
                    'preview_source' => 'plugin',
                    'preview_id'     => $preview_id,
                    'thumbnail_url'  => esc_url_raw( $thumbnail_url ),
                );
            } catch ( \Throwable $error ) {
                return new WP_Error( 'preview_save_failed', sanitize_text_field( $error->getMessage() ) );
            } finally {
                if ( ! $published ) {
                    if ( $preview_id ) {
                        if ( $preview_id === absint( get_post_meta( $attachment_id, self::META_PREVIEW_ID, true ) ) ) {
                            if ( $old_preview_id ) {
                                update_post_meta( $attachment_id, self::META_PREVIEW_ID, $old_preview_id );
                            } else {
                                delete_post_meta( $attachment_id, self::META_PREVIEW_ID );
                            }
                        }
                        // Metadata writes can fail after sizes are created. Clean the generated
                        // files using the in-memory record before deleting the new attachment.
                        if ( is_array( $metadata ) && ! empty( $metadata ) ) {
                            $generated_file = ! empty( $metadata['file'] ) ? path_join( wp_get_upload_dir()['basedir'], $metadata['file'] ) : $path;
                            wp_delete_attachment_files( $preview_id, $metadata, array(), $generated_file );
                        }
                        wp_delete_attachment( $preview_id, true );
                        wp_delete_file( $path );
                    } else {
                        wp_delete_file( $path );
                    }
                }
            }
        }

        public function add_browser_preview_to_media_response( $response, $attachment, $metadata ) {
            if ( 'application/pdf' !== $attachment->post_mime_type ) {
                return $response;
            }
            if ( ! empty( $response['sizes'] ) && is_array( $response['sizes'] ) ) {
                foreach ( $response['sizes'] as $size => $image ) {
                    if ( ! is_array( $image ) || empty( $image['url'] ) || ! $this->is_available_preview_url( $image['url'] ) ) {
                        unset( $response['sizes'][ $size ] );
                    }
                }
            }
            if ( ! empty( $response['sizes']['full'] ) ) {
                return $response;
            }
            $preview_id = absint( get_post_meta( $attachment->ID, self::META_PREVIEW_ID, true ) );
            if ( ! $preview_id || 0 !== strpos( (string) get_post_mime_type( $preview_id ), 'image/' ) ) {
                return $response;
            }
            foreach ( array( 'thumbnail', 'medium', 'large', 'full' ) as $size ) {
                $image = wp_get_attachment_image_src( $preview_id, $size );
                if ( ! $image || ! $this->is_available_preview_url( $image[0] ) ) {
                    continue;
                }
                $response['sizes'][ $size ] = array(
                    'url' => esc_url_raw( $image[0] ), 'width' => $image[1], 'height' => $image[2],
                    'orientation' => $image[2] > $image[1] ? 'portrait' : 'landscape',
                );
            }
            return $response;
        }

        public function add_attachment_fields( $form_fields, $post ) {
            if ( 'application/pdf' !== get_post_mime_type( $post ) ) {
                return $form_fields;
            }

            $preview_data = $this->get_pdf_preview_data( $post->ID, 'medium' );
            $status = $preview_data['preview_source'];
            $status_label = array(
                'wordpress'   => __( 'WordPress preview', 'pdf-thumbnail-inserter' ),
                'plugin'      => __( 'Plugin-generated preview', 'pdf-thumbnail-inserter' ),
                'standalone'  => __( 'Plugin-generated preview', 'pdf-thumbnail-inserter' ),
                'placeholder' => __( 'Placeholder only', 'pdf-thumbnail-inserter' ),
            );

            $form_fields['pdf_thumbnail_shortcode'] = array(
                'label' => __( 'PDF thumbnail shortcode', 'pdf-thumbnail-inserter' ),
                'input' => 'html',
                'html'  => '<input type="text" class="widefat code" readonly value="' . esc_attr( sprintf( '[pdf_thumbnail_inserter id="%d"]', $post->ID ) ) . '">',
                'helps' => __( 'Copy and paste this shortcode into posts, pages, or widgets.', 'pdf-thumbnail-inserter' ),
            );

            $form_fields['pdf_thumbnail_preview_status'] = array(
                'label' => __( 'Preview status', 'pdf-thumbnail-inserter' ),
                'input' => 'html',
                'html'  => '<span>' . esc_html( isset( $status_label[ $status ] ) ? $status_label[ $status ] : $status ) . '</span>',
                'helps' => __( 'This shows whether the preview comes from WordPress, the plugin, or the placeholder image.', 'pdf-thumbnail-inserter' ),
            );

            return $form_fields;
        }

        public function maybe_generate_pdf_preview_on_upload( $metadata, $attachment_id, $context ) {
            if ( 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
                return $metadata;
            }

            if ( 'create' !== $context && 'update' !== $context ) {
                return $metadata;
            }

            if ( $this->metadata_has_pdf_preview( $metadata ) ) {
                return $metadata;
            }

            $generation_status = $this->get_pdf_preview_generation_status();
            if ( empty( $generation_status['available'] ) ) {
                return $metadata;
            }

            $this->ensure_pdf_preview( $attachment_id );

            return $metadata;
        }

        private function is_owned_pdf_preview( $preview_id, $attachment_id ) {
            $preview_id = absint( $preview_id );
            $attachment_id = absint( $attachment_id );
            $preview = $preview_id ? get_post( $preview_id ) : null;

            return $preview && $preview_id !== $attachment_id
                && 'attachment' === $preview->post_type
                && 0 === strpos( (string) get_post_mime_type( $preview_id ), 'image/' )
                && $attachment_id === absint( $preview->post_parent )
                && $attachment_id === absint( get_post_meta( $preview_id, self::META_PARENT_PDF, true ) );
        }

        public function cleanup_generated_preview( $attachment_id ) {
            if ( 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
                return;
            }

            $this->invalidate_preview_caches();

            $preview_id = absint( get_post_meta( $attachment_id, self::META_PREVIEW_ID, true ) );
            if ( $this->is_owned_pdf_preview( $preview_id, $attachment_id ) ) {
                if ( wp_delete_attachment( $preview_id, true ) ) {
                    delete_post_meta( $attachment_id, self::META_PREVIEW_ID, $preview_id );
                }
            }
        }

        /** Keep core deletion and preview publication in the same per-PDF critical section. */
        public function protect_pdf_attachment_deletion( $delete, $post, $force_delete ) {
            if ( null !== $delete || 'application/pdf' !== $post->post_mime_type ) return $delete;
            $id = (int) $post->ID;
            if ( isset( $this->attachment_delete_locks[ $id ] ) ) return false;
            $file = get_attached_file( $id, true );
            if ( is_string( $file ) && '' !== $file ) {
                $path = realpath( $file );
                if ( false === $path && realpath( dirname( $file ) ) ) $path = realpath( dirname( $file ) ) . '/' . basename( $file );
                $uploads = wp_get_upload_dir();
                $base = ! empty( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
                if ( $path && $base && 'pdf' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
                    $prefix = trailingslashit( wp_normalize_path( $base ) );
                    $path = wp_normalize_path( $path );
                    $inside = '\\' === DIRECTORY_SEPARATOR ? 0 === stripos( $path, $prefix ) : 0 === strpos( $path, $prefix );
                    if ( $inside ) {
                        $lock = $this->acquire_preview_file_lock( $path . '.pti-preview.lock' );
                        if ( is_wp_error( $lock ) ) return false;
                        $this->attachment_delete_locks[ $id ] = array( 'path' => $path, 'lock' => $lock );
                    }
                }
            }
            $preview_id = absint( get_post_meta( $id, self::META_PREVIEW_ID, true ) );
            if ( $this->is_owned_pdf_preview( $preview_id, $id ) ) {
                $this->cleanup_generated_preview( $id );
                if ( get_post( $preview_id ) ) {
                    $this->release_pdf_deletion_locks( $id );
                    return false; // A third-party deletion veto must not orphan the owned image.
                }
            }
            return $delete;
        }

        public function release_deleted_pdf_lock( $post_id ) {
            if ( isset( $this->attachment_delete_locks[ $post_id ] ) ) {
                clearstatcache( true, $this->attachment_delete_locks[ $post_id ]['path'] );
                if ( ! file_exists( $this->attachment_delete_locks[ $post_id ]['path'] ) ) $this->release_pdf_deletion_locks( $post_id );
            }
        }

        public function release_pdf_deletion_locks( $post_id = 0 ) {
            foreach ( $this->attachment_delete_locks as $id => $entry ) {
                if ( $post_id && (int) $post_id !== $id ) continue;
                fclose( $entry['lock'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Releases a native deletion lock.
                unset( $this->attachment_delete_locks[ $id ] );
            }
        }

        public function handle_regenerate_missing_previews() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Insufficient permissions.', 'pdf-thumbnail-inserter' ), '', array( 'response' => 403 ) );
            }

            check_admin_referer( self::ADMIN_ACTION_REGENERATE_MISSING );

            $ajax = isset( $_POST['pti_ajax'] ) && is_scalar( $_POST['pti_ajax'] ) && '1' === sanitize_key( wp_unslash( $_POST['pti_ajax'] ) );
            if ( isset( $_POST['job_id'] ) && ! is_scalar( $_POST['job_id'] ) ) {
                $result = new WP_Error( 'invalid_job', __( 'Invalid maintenance job.', 'pdf-thumbnail-inserter' ), array( 'status' => 400 ) );
            } else {
                $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
                $result = $this->run_server_maintenance_batch( $job_id );
            }
            if ( is_wp_error( $result ) ) {
                $data = $result->get_error_data();
                $status = 'preview_busy' === $result->get_error_code() ? 409 : ( isset( $data['status'] ) ? $data['status'] : 500 );
                if ( $ajax ) {
                    wp_send_json_error( array( 'message' => $result->get_error_message(), 'restart' => 'expired_job' === $result->get_error_code() ), $status );
                }
                wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => absint( $status ), 'back_link' => true ) );
            }
            if ( $ajax ) {
                wp_send_json_success( $result );
            }
            wp_safe_redirect( add_query_arg( 'page', 'pdf-thumbnail-settings', admin_url( 'options-general.php' ) ) );
            exit;
        }

        private function get_server_maintenance_job_key( $user_id = 0 ) {
            return self::TRANSIENT_SERVER_JOB . '_' . absint( $user_id ? $user_id : get_current_user_id() );
        }

        private function get_server_maintenance_job( $user_id = 0 ) {
            $job = get_transient( $this->get_server_maintenance_job_key( $user_id ) );
            if ( ! is_array( $job ) || empty( $job['id'] ) || ! is_string( $job['id'] )
                || ! isset( $job['complete'], $job['report'] ) || ! is_bool( $job['complete'] ) || ! is_array( $job['report'] ) ) {
                return false;
            }
            foreach ( array( 'cursor', 'upper', 'busy_id', 'busy_attempts' ) as $key ) {
                if ( ! isset( $job[ $key ] ) || ! is_int( $job[ $key ] ) || $job[ $key ] < 0 ) {
                    return false;
                }
            }
            foreach ( array( 'wordpress_generated', 'plugin_generated', 'already_present', 'failed' ) as $key ) {
                if ( ! isset( $job['report'][ $key ] ) || ! is_int( $job['report'][ $key ] ) || $job['report'][ $key ] < 0 ) {
                    return false;
                }
            }
            return $job['cursor'] <= $job['upper'] && isset( $job['report']['errors'] ) && is_array( $job['report']['errors'] ) ? $job : false;
        }

        /** Seek by ID instead of OFFSET, so deletions cannot skip remaining PDFs. */
        private function get_server_maintenance_ids( $cursor, $upper, $limit, $order = 'ASC' ) {
            global $wpdb;
            $query = new WP_Query();
            $where_filter = function ( $where, $current_query ) use ( $query, $cursor, $upper, $wpdb ) {
                if ( $current_query !== $query ) {
                    return $where;
                }
                // The core posts table identifier is trusted; %i is unavailable on WP 6.0.
                return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d AND {$wpdb->posts}.ID <= %d", $cursor, $upper );
            };
            add_filter( 'posts_where', $where_filter, 10, 2 );
            try {
                return $query->query( array(
                    'post_type' => 'attachment', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit',
                    'fields' => 'ids', 'posts_per_page' => $limit, 'orderby' => 'ID', 'order' => $order,
                    'no_found_rows' => true, 'pti_preview_maintenance' => true,
                ) );
            } finally {
                remove_filter( 'posts_where', $where_filter, 10 );
            }
        }

        private function store_server_maintenance_job( $user_id, $job ) {
            $key = $this->get_server_maintenance_job_key( $user_id );
            set_transient( $key, $job, HOUR_IN_SECONDS );
            return get_transient( $key ) === $job;
        }

        private function run_server_maintenance_batch( $job_id ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                return new WP_Error( 'maintenance_forbidden', __( 'Insufficient permissions.', 'pdf-thumbnail-inserter' ), array( 'status' => 403 ) );
            }
            $capability = $this->get_pdf_preview_generation_status();
            if ( ! $capability['available'] ) {
                return new WP_Error( 'maintenance_unavailable', $capability['reason'], array( 'status' => 503 ) );
            }
            $uploads = wp_upload_dir();
            if ( ! empty( $uploads['error'] ) ) {
                return new WP_Error( 'maintenance_uploads', __( 'The uploads directory is unavailable.', 'pdf-thumbnail-inserter' ) );
            }
            $user_id = get_current_user_id();
            return $this->with_preview_file_lock(
                trailingslashit( $uploads['basedir'] ) . '.pti-server-maintenance-' . $user_id . '.lock',
                function () use ( $job_id, $user_id ) { return $this->process_server_maintenance_batch( $job_id, $user_id ); }
            );
        }

        private function process_server_maintenance_batch( $job_id, $user_id ) {
            $job = $this->get_server_maintenance_job( $user_id );
            if ( '' === $job_id ) {
                $last = $this->get_server_maintenance_ids( 0, PHP_INT_MAX, 1, 'DESC' );
                $job = array(
                    'id' => wp_generate_uuid4(), 'cursor' => 0, 'upper' => $last ? absint( $last[0] ) : 0,
                    'complete' => false, 'busy_id' => 0, 'busy_attempts' => 0,
                    'report' => array( 'wordpress_generated' => 0, 'plugin_generated' => 0, 'already_present' => 0, 'failed' => 0, 'errors' => array() ),
                );
            } elseif ( ! is_array( $job ) || empty( $job['id'] ) || ! hash_equals( $job['id'], $job_id ) ) {
                return new WP_Error( 'expired_job', __( 'This maintenance job expired or was replaced. Start a new job.', 'pdf-thumbnail-inserter' ), array( 'status' => 409 ) );
            }
            $processed = 0;
            $attempts = 0;
            $retry_after = 0;
            if ( ! $job['complete'] ) {
                $ids = $this->get_server_maintenance_ids( $job['cursor'], $job['upper'], self::SERVER_SCAN_LIMIT + 1 );
                $more = count( $ids ) > self::SERVER_SCAN_LIMIT;
                $ids = array_slice( $ids, 0, self::SERVER_SCAN_LIMIT );
                if ( $ids ) {
                    _prime_post_caches( $ids, false, true );
                }
                $started = microtime( true );
                foreach ( $ids as $pdf_id ) {
                    $error = '';
                    try {
                        $before = $this->get_pdf_preview_data( $pdf_id, 'full', false );
                        if ( 'placeholder' !== $before['preview_source'] ) {
                            $job['report']['already_present']++;
                        } else {
                            $attempts++;
                            $result = $this->ensure_pdf_preview( $pdf_id, true );
                            if ( is_wp_error( $result ) && 'preview_busy' === $result->get_error_code() ) {
                                $job['busy_attempts'] = $job['busy_id'] === $pdf_id ? $job['busy_attempts'] + 1 : 1;
                                $job['busy_id'] = $pdf_id;
                                if ( $job['busy_attempts'] < 3 ) {
                                    $retry_after = 1;
                                    break;
                                }
                            }
                            $after = $this->get_pdf_preview_data( $pdf_id, 'full', false );
                            if ( is_wp_error( $result ) && 'preview_busy' === $result->get_error_code() && 'placeholder' !== $after['preview_source'] ) {
                                $job['report']['already_present']++;
                            } elseif ( is_wp_error( $result ) ) {
                                $error = $result->get_error_message();
                            } elseif ( 'placeholder' !== $after['preview_source'] && empty( $result['generated'] ) ) {
                                $job['report']['already_present']++;
                            } elseif ( 'wordpress' === $after['preview_source'] ) {
                                $job['report']['wordpress_generated']++;
                            } elseif ( in_array( $after['preview_source'], array( 'plugin', 'standalone' ), true ) ) {
                                $job['report']['plugin_generated']++;
                            } else {
                                $error = __( 'No usable preview was generated.', 'pdf-thumbnail-inserter' );
                            }
                        }
                    } catch ( \Throwable $exception ) {
                        $error = $exception->getMessage();
                    }
                    if ( $error ) {
                        $job['report']['failed']++;
                        if ( count( $job['report']['errors'] ) < 20 ) {
                            $job['report']['errors'][] = '#' . absint( $pdf_id ) . ': ' . wp_html_excerpt( sanitize_text_field( $error ), 200, '…' );
                        }
                    }
                    $job['busy_id'] = 0;
                    $job['busy_attempts'] = 0;
                    $job['cursor'] = absint( $pdf_id );
                    $processed++;
                    // Persist after each PDF: an interrupted request resumes without starting over.
                    if ( ! $this->store_server_maintenance_job( $user_id, $job ) ) {
                        return new WP_Error( 'maintenance_store_failed', __( 'Could not save maintenance progress. Please try again.', 'pdf-thumbnail-inserter' ) );
                    }
                    // A soft time budget is checked between PDFs; a single decoder uses the server's own limits.
                    if ( $attempts >= self::SERVER_RENDER_LIMIT || microtime( true ) - $started >= 5 ) {
                        break;
                    }
                }
                $job['complete'] = ! $retry_after && ! $more && ( ! $ids || $job['cursor'] >= end( $ids ) );
                if ( ! $this->store_server_maintenance_job( $user_id, $job ) ) {
                    return new WP_Error( 'maintenance_store_failed', __( 'Could not save maintenance progress. Please try again.', 'pdf-thumbnail-inserter' ) );
                }
            }
            return array( 'job_id' => $job['id'], 'complete' => $job['complete'], 'report' => $job['report'], 'batch_processed' => $processed, 'batch_attempts' => $attempts, 'retry_after' => $retry_after );
        }

        private function maybe_generate_core_pdf_preview( $attachment_id, $force = false ) {
            $attachment_id = absint( $attachment_id );
            if ( ! $attachment_id || 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
                return new WP_Error( 'invalid_pdf', __( 'Attachment is not a valid PDF.', 'pdf-thumbnail-inserter' ) );
            }

            $generation_status = $this->get_pdf_preview_generation_status();
            if ( empty( $generation_status['available'] ) ) {
                $message = ! empty( $generation_status['reason'] ) ? (string) $generation_status['reason'] : __( 'PDF preview generation is unavailable on this server.', 'pdf-thumbnail-inserter' );
                update_post_meta( $attachment_id, self::META_PREVIEW_ERROR, $message );
                return new WP_Error( 'generation_unavailable', $message );
            }

            $existing_meta = wp_get_attachment_metadata( $attachment_id, true );
            if ( ! $force && $this->metadata_has_pdf_preview( $existing_meta ) ) {
                return array(
                    'generated'      => false,
                    'preview_source' => 'wordpress',
                    'preview_id'     => 0,
                );
            }

            $file_path = get_attached_file( $attachment_id, true );
            if ( ! $file_path || ! file_exists( $file_path ) ) {
                update_post_meta( $attachment_id, self::META_PREVIEW_ERROR, __( 'Original PDF file could not be found.', 'pdf-thumbnail-inserter' ) );
                return new WP_Error( 'missing_file', __( 'Original PDF file could not be found.', 'pdf-thumbnail-inserter' ) );
            }

            require_once ABSPATH . 'wp-admin/includes/image.php';

            $literal_prefix = strtr( dirname( $file_path ) . '/' . pathinfo( $file_path, PATHINFO_FILENAME ), array( '[' => '[[]', ']' => '[]]', '?' => '[?]', '*' => '[*]' ) );
            $existing_files = glob( $literal_prefix . '-pdf*.*' );
            $existing_files = $existing_files ? array_map( 'wp_normalize_path', $existing_files ) : array();
            $generated_files = array();
            $capture_metadata = function ( $data, $id ) use ( $attachment_id, $file_path, &$generated_files ) {
                if ( (int) $id === $attachment_id ) {
                    $generated_files = array_unique( array_merge( $generated_files, $this->get_core_preview_files( $file_path, $data ) ) );
                }
                return $data;
            };
            $published = false;
            $metadata = array();
            $failure = '';
            $defer_metadata = function ( $check, $id, $key ) use ( $attachment_id ) {
                // Core builds sub-size metadata in memory; publish once after every size is ready.
                return (int) $id === $attachment_id && '_wp_attachment_metadata' === $key ? false : $check;
            };
            add_filter( 'wp_update_attachment_metadata', $capture_metadata, PHP_INT_MIN, 2 );
            add_filter( 'update_post_metadata', $defer_metadata, PHP_INT_MAX, 3 );
            remove_filter( 'wp_generate_attachment_metadata', array( $this, 'maybe_generate_pdf_preview_on_upload' ), 20 );
            try {
                $metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
                if ( is_array( $metadata ) ) {
                    remove_filter( 'update_post_metadata', $defer_metadata, PHP_INT_MAX );
                    wp_update_attachment_metadata( $attachment_id, $metadata );
                    wp_cache_delete( $attachment_id, 'post_meta' );
                    $stored = wp_get_attachment_metadata( $attachment_id, true );
                    $published = $this->metadata_has_pdf_preview( $metadata ) && is_array( $stored )
                        && isset( $stored['sizes'] ) && $stored['sizes'] === $metadata['sizes']
                        && '' !== $this->get_wordpress_pdf_preview_url( $attachment_id, 'full' );
                    if ( $this->metadata_has_pdf_preview( $metadata ) && ! $published ) {
                        $failure = __( 'Could not save WordPress PDF preview metadata.', 'pdf-thumbnail-inserter' );
                    }
                }
            } catch ( \Throwable $error ) {
                $failure = sanitize_text_field( $error->getMessage() );
            } finally {
                remove_filter( 'wp_update_attachment_metadata', $capture_metadata, PHP_INT_MIN );
                remove_filter( 'update_post_metadata', $defer_metadata, PHP_INT_MAX );
                add_filter( 'wp_generate_attachment_metadata', array( $this, 'maybe_generate_pdf_preview_on_upload' ), 20, 3 );
                if ( ! $published ) {
                    wp_cache_delete( $attachment_id, 'post_meta' );
                    if ( wp_get_attachment_metadata( $attachment_id, true ) !== $existing_meta ) {
                        try {
                            // Restore exact stored values, without reapplying the transformation that failed.
                            if ( false === $existing_meta ) delete_post_meta( $attachment_id, '_wp_attachment_metadata' );
                            else update_post_meta( $attachment_id, '_wp_attachment_metadata', $existing_meta );
                        } catch ( \Throwable $rollback_error ) {
                            $failure = __( 'Could not restore the previous PDF preview metadata.', 'pdf-thumbnail-inserter' );
                        }
                    }
                    wp_cache_delete( $attachment_id, 'post_meta' );
                    $retained_meta = wp_get_attachment_metadata( $attachment_id, true );
                    if ( $retained_meta !== $existing_meta ) $failure = __( 'Could not restore the previous PDF preview metadata.', 'pdf-thumbnail-inserter' );
                    $referenced_files = $this->get_core_preview_files( $file_path, $retained_meta );
                    foreach ( array_diff( $generated_files, $existing_files, $referenced_files ) as $generated_file ) wp_delete_file( $generated_file );
                }
            }

            if ( $published ) {
                delete_post_meta( $attachment_id, self::META_PREVIEW_ERROR );
                $this->invalidate_preview_caches();
                return array( 'generated' => true, 'preview_source' => 'wordpress', 'preview_id' => 0 );
            }
            if ( $failure ) {
                update_post_meta( $attachment_id, self::META_PREVIEW_ERROR, $failure );
                return new WP_Error( 'core_preview_metadata_failed', $failure );
            }

            require_once ABSPATH . 'wp-includes/media.php';
            $editor = wp_get_image_editor( $file_path );
            $message = is_wp_error( $editor )
                /* translators: %s: WordPress image editor error message. */
                ? sprintf( __( 'WordPress could not generate a PDF preview: %s', 'pdf-thumbnail-inserter' ), $editor->get_error_message() )
                : __( 'WordPress did not generate preview metadata for this PDF.', 'pdf-thumbnail-inserter' );

            update_post_meta( $attachment_id, self::META_PREVIEW_ERROR, $message );

            return new WP_Error( 'core_preview_failed', $message );
        }

        /** Paths returned by core's own PDF metadata, never arbitrary uploaded metadata paths. */
        private function get_core_preview_files( $pdf_path, $metadata ) {
            $files = array();
            if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) return $files;
            $directory = wp_normalize_path( realpath( dirname( $pdf_path ) ) );
            $prefix = pathinfo( $pdf_path, PATHINFO_FILENAME ) . '-pdf';
            foreach ( $metadata['sizes'] as $size ) {
                if ( ! is_array( $size ) || empty( $size['file'] ) || ! is_string( $size['file'] )
                    || basename( $size['file'] ) !== $size['file'] || 0 !== strpos( $size['file'], $prefix ) ) continue;
                $path = realpath( $directory . '/' . $size['file'] );
                if ( $path && wp_normalize_path( dirname( $path ) ) === $directory && wp_getimagesize( $path ) ) $files[] = wp_normalize_path( $path );
            }
            return $files;
        }

        /**
         * @param int  $attachment_id Attachment ID.
         * @param bool $allow_retry   Whether to retry generation when a preview is missing.
         * @return array<string,mixed>|WP_Error
         */
        private function ensure_pdf_preview( $attachment_id, $allow_retry = false ) {
            $attachment_id = absint( $attachment_id );
            if ( ! $attachment_id || 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
                return new WP_Error( 'invalid_pdf', __( 'Attachment is not a valid PDF.', 'pdf-thumbnail-inserter' ) );
            }

            $file_data = $this->get_valid_local_pdf_attachment_file( $attachment_id );
            if ( is_wp_error( $file_data ) ) {
                return $file_data;
            }
            return $this->with_pdf_preview_lock(
                $file_data['path'],
                function () use ( $attachment_id, $allow_retry ) {
                    wp_cache_delete( $attachment_id, 'post_meta' );
                    return $this->generate_pdf_attachment_preview( $attachment_id, $allow_retry );
                }
            );
        }

        private function generate_pdf_attachment_preview( $attachment_id, $allow_retry ) {
            clean_post_cache( $attachment_id );
            $current_file = $this->get_valid_local_pdf_attachment_file( $attachment_id );
            if ( is_wp_error( $current_file ) ) return $current_file;
            $existing = $this->get_pdf_preview_data( $attachment_id, 'full', false );
            if ( 'placeholder' !== $existing['preview_source'] && ! $allow_retry ) {
                return array(
                    'generated'      => false,
                    'preview_source' => $existing['preview_source'],
                    'preview_id'     => ! empty( $existing['preview_id'] ) ? absint( $existing['preview_id'] ) : 0,
                );
            }

            $core_result = $this->maybe_generate_core_pdf_preview( $attachment_id, $allow_retry );
            $existing = $this->get_pdf_preview_data( $attachment_id, 'full', false );
            if ( 'placeholder' !== $existing['preview_source'] ) {
                return array(
                    'generated'      => ! is_wp_error( $core_result ),
                    'preview_source' => $existing['preview_source'],
                    'preview_id'     => ! empty( $existing['preview_id'] ) ? absint( $existing['preview_id'] ) : 0,
                );
            }

            $file_path = get_attached_file( $attachment_id, true );
            if ( ! $file_path || ! file_exists( $file_path ) ) {
                update_post_meta( $attachment_id, self::META_PREVIEW_ERROR, __( 'Original PDF file could not be found.', 'pdf-thumbnail-inserter' ) );
                return new WP_Error( 'missing_file', __( 'Original PDF file could not be found.', 'pdf-thumbnail-inserter' ) );
            }

            if ( ! class_exists( 'Imagick' ) ) {
                $message = is_wp_error( $core_result ) ? $core_result->get_error_message() : __( 'Imagick is not available on this server.', 'pdf-thumbnail-inserter' );
                update_post_meta( $attachment_id, self::META_PREVIEW_ERROR, $message );
                return new WP_Error( 'imagick_missing', $message );
            }

            $path_info = pathinfo( $file_path );
            $dir_path = $path_info['dirname'];
            $filename = $path_info['filename'];
            $preview_filename = wp_unique_filename( $dir_path, $filename . '-pdf-preview.jpg' );
            $preview_path = trailingslashit( $dir_path ) . $preview_filename;

            $imagick = null;
            try {
                $imagick = new Imagick();
                $imagick->setResolution( 150, 150 );
                $imagick->readImage( $file_path . '[0]' );
                $imagick->setImageFormat( 'jpeg' );
                $imagick->setImageBackgroundColor( 'white' );

                if ( defined( 'Imagick::ALPHACHANNEL_REMOVE' ) ) {
                    $imagick->setImageAlphaChannel( Imagick::ALPHACHANNEL_REMOVE );
                }

                if ( defined( 'Imagick::LAYERMETHOD_FLATTEN' ) ) {
                    $flattened = $imagick->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
                    $imagick->clear();
                    $imagick->destroy();
                    $imagick = $flattened;
                }

                $imagick->setImageCompressionQuality( 82 );
                $imagick->thumbnailImage( 1200, 2000, true, false );
                $imagick->writeImage( $preview_path );
            } catch ( \Throwable $e ) {
                wp_delete_file( $preview_path );
                update_post_meta( $attachment_id, self::META_PREVIEW_ERROR, sanitize_text_field( $e->getMessage() ) );
                return new WP_Error( 'imagick_failed', sanitize_text_field( $e->getMessage() ) );
            } finally {
                if ( $imagick instanceof \Imagick ) {
                    $imagick->clear();
                    $imagick->destroy();
                }
            }

            if ( ! file_exists( $preview_path ) ) {
                update_post_meta( $attachment_id, self::META_PREVIEW_ERROR, __( 'Preview image could not be written to disk.', 'pdf-thumbnail-inserter' ) );
                return new WP_Error( 'preview_missing', __( 'Preview image could not be written to disk.', 'pdf-thumbnail-inserter' ) );
            }

            $result = $this->create_pdf_preview_attachment( $attachment_id, $preview_path, 'image/jpeg', get_the_title( $attachment_id ) );
            if ( is_wp_error( $result ) ) {
                update_post_meta( $attachment_id, self::META_PREVIEW_ERROR, $result->get_error_message() );
            }
            return $result;
        }

        private function is_available_preview_url( $url ) {
            if ( ! $url ) {
                return false;
            }
            $uploads = wp_get_upload_dir();
            $home = home_url( '/' );
            // Leave CDN/offloaded URLs to WordPress filters; do not probe them over HTTP.
            if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) )
                || wp_parse_url( $url, PHP_URL_PORT ) !== wp_parse_url( $home, PHP_URL_PORT )
                || empty( $uploads['baseurl'] )
                || 0 !== strpos( $this->get_file_url_identity( $url ), trailingslashit( $this->get_file_url_identity( $uploads['baseurl'] ) ) ) ) {
                return true;
            }
            $path = $this->get_local_upload_file_path( $url );
            return '' !== $path && false !== wp_getimagesize( $path );
        }

        private function get_image_url_with_size_fallback( $attachment_id, $preferred_size = 'medium' ) {
            $attachment_id = absint( $attachment_id );
            if ( ! $attachment_id ) {
                return '';
            }

            $size_candidates = array_filter(
                array_unique(
                    array_merge(
                        array( $preferred_size ),
                        array( 'full', 'large', 'medium_large', 'medium', 'thumbnail' )
                    )
                )
            );

            foreach ( $size_candidates as $candidate_size ) {
                $url = wp_get_attachment_image_url( $attachment_id, $candidate_size );
                if ( $this->is_available_preview_url( $url ) ) {
                    return $url;
                }
            }

            $metadata = wp_get_attachment_metadata( $attachment_id, true );
            if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
                return '';
            }

            $base_url = wp_get_attachment_url( $attachment_id );
            if ( ! $base_url ) {
                return '';
            }

            $base_dir_url = trailingslashit( dirname( $base_url ) );
            if ( './' === $base_dir_url || '.' === $base_dir_url ) {
                $uploads = wp_get_upload_dir();
                $base_dir_url = trailingslashit( $uploads['baseurl'] );
            }

            foreach ( $size_candidates as $candidate_size ) {
                if ( empty( $metadata['sizes'][ $candidate_size ]['file'] ) ) {
                    continue;
                }

                $url = trailingslashit( $base_dir_url ) . ltrim( $metadata['sizes'][ $candidate_size ]['file'], '/' );
                if ( $this->is_available_preview_url( $url ) ) {
                    return $url;
                }
            }

            foreach ( $metadata['sizes'] as $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $url = trailingslashit( $base_dir_url ) . ltrim( $size_data['file'], '/' );
                    if ( $this->is_available_preview_url( $url ) ) {
                        return $url;
                    }
                }
            }

            return '';
        }

        private function get_wordpress_pdf_preview_url( $attachment_id, $preferred_size = 'medium' ) {
            $attachment_id = absint( $attachment_id );
            $file_url      = $attachment_id ? (string) wp_get_attachment_url( $attachment_id ) : '';
            $extension     = strtolower( pathinfo( $this->get_url_path( $file_url ), PATHINFO_EXTENSION ) );

            if ( ! $attachment_id || ( 'application/pdf' !== get_post_mime_type( $attachment_id ) && 'pdf' !== $extension ) ) {
                return '';
            }

            $preview_url = $this->get_image_url_with_size_fallback( $attachment_id, $preferred_size );

            if ( $preview_url ) {
                return $preview_url;
            }

            $metadata = wp_get_attachment_metadata( $attachment_id, true );
            if ( is_array( $metadata ) ) {
                $base_url = wp_get_attachment_url( $attachment_id );
                if ( $base_url ) {
                    $base_dir_url = trailingslashit( dirname( $base_url ) );

                    if ( ! empty( $metadata['file'] ) && preg_match( '/\.(?:jpe?g|png|gif|webp)$/i', (string) $metadata['file'] ) ) {
                        $relative_file = wp_basename( (string) $metadata['file'] );
                        if ( $relative_file ) {
                            $url = trailingslashit( $base_dir_url ) . ltrim( $relative_file, '/' );
                            if ( $this->is_available_preview_url( $url ) ) {
                                return $url;
                            }
                        }
                    }
                }

                if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                    foreach ( $metadata['sizes'] as $size_data ) {
                        if ( empty( $size_data['file'] ) ) {
                            continue;
                        }

                        if ( ! empty( $size_data['mime-type'] ) && 0 === strpos( (string) $size_data['mime-type'], 'image/' ) ) {
                            if ( ! empty( $base_dir_url ) ) {
                                $url = trailingslashit( $base_dir_url ) . ltrim( $size_data['file'], '/' );
                                if ( $this->is_available_preview_url( $url ) ) {
                                    return $url;
                                }
                            }
                        }
                    }
                }
            }

            $thumbnail_id = absint( get_post_meta( $attachment_id, '_thumbnail_id', true ) );
            if ( $thumbnail_id && 'image/' === substr( (string) get_post_mime_type( $thumbnail_id ), 0, 6 ) ) {
                $thumb_url = $this->get_image_url_with_size_fallback( $thumbnail_id, $preferred_size );
                if ( $thumb_url ) {
                    return $thumb_url;
                }
            }

            return '';
        }

        private function get_pdf_preview_data( $attachment_id, $size = 'medium', $generate_if_missing = false ) {
            $attachment_id = absint( $attachment_id );
            $title = get_the_title( $attachment_id );
            $file_url = wp_get_attachment_url( $attachment_id );
            $attachment_url = get_attachment_link( $attachment_id );

            $thumbnail_url = '';
            $preview_id = 0;
            $source = 'placeholder';

            $core_preview = $this->get_wordpress_pdf_preview_url( $attachment_id, $size );
            if ( $core_preview ) {
                $thumbnail_url = $core_preview;
                $source = 'wordpress';
            }

            if ( ! $thumbnail_url ) {
                $preview_id = absint( get_post_meta( $attachment_id, self::META_PREVIEW_ID, true ) );
                if ( $preview_id && 'image/' === substr( (string) get_post_mime_type( $preview_id ), 0, 6 ) ) {
                    $plugin_preview = $this->get_image_url_with_size_fallback( $preview_id, $size );
                    if ( ! $plugin_preview ) {
                        $plugin_preview = wp_get_attachment_url( $preview_id );
                    }
                    if ( $this->is_available_preview_url( $plugin_preview ) ) {
                        $thumbnail_url = $plugin_preview;
                        $source = 'plugin';
                    }
                }
            }

            if ( ! $thumbnail_url && $file_url ) {
                $standalone_preview = $this->get_pdf_preview_url_from_file_url( $file_url, $generate_if_missing );
                if ( $standalone_preview ) {
                    $thumbnail_url = $standalone_preview;
                    $source        = 'standalone';
                }
            }

            if ( ! $thumbnail_url ) {
                $thumbnail_url = PDF_THUMBNAIL_INSERTER_URL . 'assets/images/pdf-placeholder.png';
            }

            return array(
                'title'          => $title ? $title : __( 'PDF File', 'pdf-thumbnail-inserter' ),
                'file_url'       => $file_url ? $file_url : '',
                'attachment_url' => $attachment_url ? $attachment_url : '',
                'thumbnail_url'  => esc_url_raw( $thumbnail_url ),
                'preview_id'     => $preview_id,
                'preview_source' => $source,
            );
        }

        private function normalize_bool_attr( $value, $default = false ) {
            if ( ! is_scalar( $value ) && null !== $value ) {
                return (bool) $default;
            }
            if ( is_bool( $value ) ) {
                return $value;
            }

            if ( null === $value || '' === $value ) {
                return (bool) $default;
            }

            return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
        }

        private function build_link_data( $atts, $preview_data ) {
            $link_to = $this->sanitize_link_to( $atts['link_to'] );
            $target_url = '';

            switch ( $link_to ) {
                case 'attachment':
                    $target_url = $preview_data['attachment_url'];
                    break;
                case 'custom':
                    $target_url = esc_url_raw( $atts['url'] );
                    break;
                case 'none':
                    $target_url = '';
                    break;
                case 'file':
                default:
                    $target_url = $preview_data['file_url'];
                    break;
            }

            return array(
                'link_to'    => $link_to,
                'target_url' => $target_url,
            );
        }

        private function parse_attachment_ids( $atts ) {
            $ids = array();

            if ( isset( $atts['ids'] ) && ! empty( $atts['ids'] ) ) {
                $raw_ids = is_array( $atts['ids'] ) ? $atts['ids'] : preg_split( '/[\s,]+/', (string) $atts['ids'] );

                foreach ( (array) $raw_ids as $raw_id ) {
                    $id = absint( $raw_id );
                    $file_data = $id ? $this->get_pdf_file_data_from_attachment_id( $id ) : array();
                    if ( ! empty( $file_data['is_pdf'] ) && ! empty( $file_data['attachment_id'] ) ) {
                        $ids[] = absint( $file_data['attachment_id'] );
                    }
                }
            }

            if ( empty( $ids ) && ! empty( $atts['id'] ) ) {
                $id = absint( $atts['id'] );
                $file_data = $id ? $this->get_pdf_file_data_from_attachment_id( $id ) : array();
                if ( ! empty( $file_data['is_pdf'] ) && ! empty( $file_data['attachment_id'] ) ) {
                    $ids[] = absint( $file_data['attachment_id'] );
                }
            }

            return array_values( array_unique( array_filter( $ids ) ) );
        }

        private function clamp_columns( $value, $default = 1 ) {
            $value = is_scalar( $value ) && preg_match( '/^\d+$/', (string) $value ) ? (int) $value : 0;

            if ( $value < 1 || $value > 6 ) {
                $value = absint( $default );
            }

            if ( $value < 1 || $value > 6 ) {
                $value = 1;
            }

            return $value;
        }

        private function get_grid_style( $atts ) {
            $styles = array(
                '--pti-thumbnail-width:' . $this->sanitize_css_dimension( $atts['thumbnail_width'], $this->get_defaults()['thumbnail_width'] ),
                '--pti-title-color:' . $this->sanitize_hex_color_with_fallback( $atts['title_color'], $this->get_defaults()['title_color'] ),
                '--pti-title-size:' . $this->sanitize_css_dimension( $atts['title_size'], $this->get_defaults()['title_size'] ),
                '--pti-button-bg:' . $this->sanitize_hex_color_with_fallback( $atts['button_color'], $this->get_defaults()['button_color'] ),
                '--pti-button-bg-hover:' . $this->sanitize_hex_color_with_fallback( $atts['button_hover_color'], $this->get_defaults()['button_hover_color'] ),
                '--pti-button-color:' . $this->sanitize_hex_color_with_fallback( $atts['button_text_color'], $this->get_defaults()['button_text_color'] ),
                '--pti-button-size:' . $this->sanitize_css_dimension( $atts['button_text_size'], $this->get_defaults()['button_text_size'] ),
                '--pti-columns-desktop:' . $this->clamp_columns( isset( $atts['columns_desktop'] ) ? $atts['columns_desktop'] : 3, 3 ),
                '--pti-columns-widescreen:' . $this->clamp_columns( ! empty( $atts['columns_widescreen'] ) ? $atts['columns_widescreen'] : ( $atts['columns_desktop'] ?? 3 ), 3 ),
                '--pti-columns-laptop:' . $this->clamp_columns( isset( $atts['columns_laptop'] ) ? $atts['columns_laptop'] : 2, 2 ),
                '--pti-columns-mobile:' . $this->clamp_columns( isset( $atts['columns_mobile'] ) ? $atts['columns_mobile'] : 1, 1 ),
            );

            return implode( ';', $styles );
        }

        private function render_card_thumbnail( $preview_data ) {
            $thumbnail_url = $preview_data['thumbnail_url'];
            $file_url      = $preview_data['file_url'];

            // Cards need the same browser fallback as listing previews when the
            // server cannot generate an image. Keep saved/custom images first.
            if (
                PDF_THUMBNAIL_INSERTER_URL . 'assets/images/pdf-placeholder.png' === $thumbnail_url
                && 'pdf' === strtolower( pathinfo( $this->get_url_path( $file_url ), PATHINFO_EXTENSION ) )
            ) {
                $thumbnail_url = $this->get_document_cover_url( $file_url, $preview_data['title'], 'portrait' );
                if ( '' === $thumbnail_url ) {
                    return $this->render_pdf_canvas_preview( $file_url, $preview_data['title'], 'pti-card__thumb' );
                }
            }

            return sprintf(
                '<img class="pti-card__thumb" src="%1$s" alt="%2$s" title="%2$s">',
                esc_url( $thumbnail_url ),
                esc_attr( $preview_data['title'] )
            );
        }

        private function render_pdf_card( $attachment_id, $atts, $context = array() ) {
            $context = wp_parse_args(
                $context,
                array(
                    'size'                   => 'medium',
                    'show_title'             => true,
                    'show_button'            => true,
                    'new_tab'                => true,
                    'button_text'            => '',
                    'rel_attr'               => '',
                    'target_attr'            => '',
                    'allow_custom_overrides' => true,
                )
            );

            $preview_data = $this->get_pdf_preview_data( $attachment_id, $context['size'] ? $context['size'] : 'medium' );
            $effective_atts = $atts;

            if ( ! $context['allow_custom_overrides'] ) {
                $effective_atts['thumbnail'] = '';
                $effective_atts['title']     = '';

                if ( 'custom' === $this->sanitize_link_to( $effective_atts['link_to'] ) ) {
                    $effective_atts['link_to'] = 'file';
                    $effective_atts['url']     = '';
                }
            }

            if ( ! empty( $effective_atts['thumbnail'] ) ) {
                $preview_data['thumbnail_url'] = esc_url_raw( $effective_atts['thumbnail'] );
            }

            if ( ! empty( $effective_atts['title'] ) ) {
                $preview_data['title'] = sanitize_text_field( $effective_atts['title'] );
            }

            $link_data = $this->build_link_data( $effective_atts, $preview_data );
            $target_url = $link_data['target_url'];
            $link_to = $link_data['link_to'];
            $button_should_render = $context['show_button'] && 'none' !== $link_to && ! empty( $target_url );
            $button_title = trim( $context['button_text'] . ' ' . $preview_data['title'] );

            ob_start();
            ?>
            <div class="pti-card-wrap">
                <figure class="pti-card">
                    <?php if ( ! empty( $target_url ) ) : ?>
                        <a class="pti-card__thumb-link" href="<?php echo esc_url( $target_url ); ?>"<?php echo $context['target_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $context['rel_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-label="<?php echo esc_attr( $preview_data['title'] ); ?>" title="<?php echo esc_attr( $preview_data['title'] ); ?>">
                            <?php echo $this->render_card_thumbnail( $preview_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </a>
                    <?php else : ?>
                        <span class="pti-card__thumb-link pti-card__thumb-link--static" aria-hidden="true" title="<?php echo esc_attr( $preview_data['title'] ); ?>">
                            <?php echo $this->render_card_thumbnail( $preview_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </span>
                    <?php endif; ?>

                    <?php if ( $context['show_title'] ) : ?>
                        <figcaption class="pti-card__caption" title="<?php echo esc_attr( $preview_data['title'] ); ?>">
                            <?php if ( ! empty( $target_url ) ) : ?>
                                <a class="pti-card__title pti-card__title-link" href="<?php echo esc_url( $target_url ); ?>"<?php echo $context['target_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $context['rel_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> title="<?php echo esc_attr( $preview_data['title'] ); ?>"><?php echo esc_html( $preview_data['title'] ); ?></a>
                            <?php else : ?>
                                <span class="pti-card__title" title="<?php echo esc_attr( $preview_data['title'] ); ?>"><?php echo esc_html( $preview_data['title'] ); ?></span>
                            <?php endif; ?>
                        </figcaption>
                    <?php endif; ?>

                    <?php if ( $button_should_render ) : ?>
                        <a class="pti-card__button" href="<?php echo esc_url( $target_url ); ?>"<?php echo $context['target_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $context['rel_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> title="<?php echo esc_attr( $button_title ); ?>"><?php echo esc_html( $context['button_text'] ); ?></a>
                    <?php endif; ?>
                </figure>
            </div>
            <?php

            return (string) ob_get_clean();
        }

        private function render_legacy_url_card( $atts, $context = array() ) {
            $context = wp_parse_args(
                $context,
                array(
                    'show_title'  => true,
                    'show_button' => true,
                    'button_text' => '',
                    'rel_attr'    => '',
                    'target_attr' => '',
                )
            );

            $file_url = esc_url_raw( $atts['url'] );

            if ( '' === $file_url ) {
                return '';
            }

            $thumbnail_url = esc_url_raw( $atts['thumbnail'] );

            if ( '' === $thumbnail_url ) {
                $thumbnail_url = PDF_THUMBNAIL_INSERTER_URL . 'assets/images/pdf-placeholder.png';
            }

            $title = sanitize_text_field( $atts['title'] );

            if ( '' === $title ) {
                $path     = $this->get_url_path( $file_url );
                $filename = rawurldecode( pathinfo( basename( $path ), PATHINFO_FILENAME ) );
                $title    = sanitize_text_field( trim( preg_replace( '/[-_]+/u', ' ', $filename ) ) );

                if ( '' === $title ) {
                    $title = __( 'PDF File', 'pdf-thumbnail-inserter' );
                }
            }

            $preview_data = array(
                'title'          => $title,
                'file_url'       => $file_url,
                'attachment_url' => $file_url,
                'thumbnail_url'  => $thumbnail_url,
            );

            $link_data = $this->build_link_data( $atts, $preview_data );
            $target_url = $link_data['target_url'];
            $link_to = $link_data['link_to'];
            $button_should_render = $context['show_button'] && 'none' !== $link_to && ! empty( $target_url );
            $button_title = trim( $context['button_text'] . ' ' . $preview_data['title'] );
            $legacy_style = $this->get_grid_style( $atts ) . ';width:' . $this->sanitize_css_dimension( $atts['thumbnail_width'], $this->get_defaults()['thumbnail_width'] ) . ';margin:10px;display:inline-block;text-align:center';

            ob_start();
            ?>
            <div class="pti-card-wrap" style="<?php echo esc_attr( $legacy_style ); ?>">
                <figure class="pti-card">
                    <?php if ( ! empty( $target_url ) ) : ?>
                        <a class="pti-card__thumb-link" href="<?php echo esc_url( $target_url ); ?>"<?php echo $context['target_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $context['rel_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-label="<?php echo esc_attr( $preview_data['title'] ); ?>" title="<?php echo esc_attr( $preview_data['title'] ); ?>">
                            <?php echo $this->render_card_thumbnail( $preview_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </a>
                    <?php else : ?>
                        <span class="pti-card__thumb-link pti-card__thumb-link--static" aria-hidden="true" title="<?php echo esc_attr( $preview_data['title'] ); ?>">
                            <?php echo $this->render_card_thumbnail( $preview_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </span>
                    <?php endif; ?>

                    <?php if ( $context['show_title'] ) : ?>
                        <figcaption class="pti-card__caption" title="<?php echo esc_attr( $preview_data['title'] ); ?>">
                            <?php if ( ! empty( $target_url ) ) : ?>
                                <a class="pti-card__title pti-card__title-link" href="<?php echo esc_url( $target_url ); ?>"<?php echo $context['target_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $context['rel_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> title="<?php echo esc_attr( $preview_data['title'] ); ?>"><?php echo esc_html( $preview_data['title'] ); ?></a>
                            <?php else : ?>
                                <span class="pti-card__title" title="<?php echo esc_attr( $preview_data['title'] ); ?>"><?php echo esc_html( $preview_data['title'] ); ?></span>
                            <?php endif; ?>
                        </figcaption>
                    <?php endif; ?>

                    <?php if ( $button_should_render ) : ?>
                        <a class="pti-card__button" href="<?php echo esc_url( $target_url ); ?>"<?php echo $context['target_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $context['rel_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> title="<?php echo esc_attr( $button_title ); ?>"><?php echo esc_html( $context['button_text'] ); ?></a>
                    <?php endif; ?>
                </figure>
            </div>
            <?php

            return (string) ob_get_clean();
        }

        private function get_shortcode_atts( $atts ) {
            $settings = $this->get_settings();

            $defaults = array(
                'id'                 => 0,
                'ids'                => '',
                'thumbnail'          => '',
                'title'              => '',
                'url'                => '',
                'link_to'            => $settings['default_link_to'],
                'show_title'         => $settings['default_show_title'] ? 'yes' : 'no',
                'show_button'        => $settings['default_show_button'] ? 'yes' : 'no',
                'button_text'        => $settings['button_text'],
                'new_tab'            => $settings['default_new_tab'] ? 'yes' : 'no',
                'size'               => 'medium',
                'thumbnail_width'    => $settings['thumbnail_width'],
                'title_color'        => $settings['title_color'],
                'title_size'         => $settings['title_size'],
                'button_color'       => $settings['button_color'],
                'button_hover_color' => $settings['button_hover_color'],
                'button_text_color'  => $settings['button_text_color'],
                'button_text_size'   => $settings['button_text_size'],
                'columns_desktop'    => 3,
                'columns_widescreen' => '',
                'columns_laptop'     => 2,
                'columns_mobile'     => 1,
                'class'              => '',
                'rel'                => '',
            );

            $atts = is_array( $atts ) ? $atts : array();
            foreach ( $atts as $key => $value ) {
                if ( 'ids' === $key && is_array( $value ) ) {
                    $atts[ $key ] = array_filter( $value, 'is_scalar' );
                } elseif ( ! is_scalar( $value ) ) {
                    unset( $atts[ $key ] );
                }
            }
            return shortcode_atts( $defaults, $atts, 'pdf_thumbnail_inserter' );
        }


        private function get_doc_placeholder_url() {
            return PDF_THUMBNAIL_INSERTER_URL . 'assets/images/doc-placeholder.png';
        }

        private function is_word_document_mime( $mime ) {
            $mime = (string) $mime;

            return in_array(
                $mime,
                array(
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ),
                true
            );
        }

        private function get_file_title_from_url( $file_url, $fallback_label ) {
            $path     = $this->get_url_path( $file_url );
            $filename = rawurldecode( pathinfo( basename( $path ), PATHINFO_FILENAME ) );
            $title    = sanitize_text_field( trim( preg_replace( '/[-_]+/u', ' ', $filename ) ) );

            if ( '' === $title ) {
                $title = $fallback_label;
            }

            return $title;
        }

        private function get_gutenberg_file_block_title( $block_content, $attachment_id, $file_url, $fallback_label ) {
            if ( preg_match_all( '~<a\b([^>]*)>(.*?)</a>~is', $block_content, $matches, PREG_SET_ORDER ) ) {
                foreach ( $matches as $match ) {
                    $attrs = $match[1];

                    if ( false !== strpos( $attrs, 'wp-block-file__button' ) ) {
                        continue;
                    }

                    $title = wp_strip_all_tags( html_entity_decode( (string) $match[2], ENT_QUOTES, get_bloginfo( 'charset' ) ) );
                    $title = sanitize_text_field( trim( preg_replace( '/\s+/u', ' ', $title ) ) );

                    if ( '' !== $title ) {
                        return $title;
                    }
                }
            }

            if ( $attachment_id ) {
                $attachment_title = sanitize_text_field( get_the_title( $attachment_id ) );
                if ( '' !== $attachment_title ) {
                    return $attachment_title;
                }
            }

            return $this->get_file_title_from_url( $file_url, $fallback_label );
        }

        private function get_url_path( $file_url ) {
            $file_url = html_entity_decode( (string) $file_url, ENT_QUOTES, get_bloginfo( 'charset' ) );
            $file_url = trim( wp_unslash( $file_url ) );

            if ( '' === $file_url ) {
                return '';
            }

            $fallback_path = '';
            if ( preg_match( '~^(?:[a-z][a-z0-9+.-]*:)?//[^/]*(/[^?#]*)~iu', $file_url, $match ) ) {
                $fallback_path = rawurldecode( $match[1] );
            }

            // PHP 7.4 on Windows can replace UTF-8 bytes according to the active
            // locale. Parse an ASCII URI, then decode its path exactly once.
            $parse_url = preg_replace_callback( '/[^\x21-\x7E]/u', function ( $match ) {
                return rawurlencode( $match[0] );
            }, $file_url );
            $path = wp_parse_url( null === $parse_url ? $file_url : $parse_url, PHP_URL_PATH );
            if ( is_string( $path ) && '' !== $path ) {
                $decoded_path = rawurldecode( $path );
                if (
                    '' !== $fallback_path
                    && '' === pathinfo( $decoded_path, PATHINFO_EXTENSION )
                    && '' !== pathinfo( $fallback_path, PATHINFO_EXTENSION )
                ) {
                    return $fallback_path;
                }

                return $decoded_path;
            }

            if ( '' !== $fallback_path ) {
                return $fallback_path;
            }

            if ( 0 === strpos( $file_url, '/' ) ) {
                return rawurldecode( preg_replace( '~[?#].*$~', '', $file_url ) );
            }

            if ( 0 === strpos( $file_url, 'wp-content/' ) ) {
                return '/' . rawurldecode( preg_replace( '~[?#].*$~', '', $file_url ) );
            }

            return rawurldecode( preg_replace( '~[?#].*$~', '', $file_url ) );
        }

        private function encode_url_path_segments( $path ) {
            $path = (string) $path;
            if ( '' === $path ) {
                return '';
            }

            $leading_slash = 0 === strpos( $path, '/' );
            $segments      = explode( '/', ltrim( $path, '/' ) );
            $encoded       = array();

            foreach ( $segments as $segment ) {
                $encoded[] = rawurlencode( rawurldecode( $segment ) );
            }

            return ( $leading_slash ? '/' : '' ) . implode( '/', $encoded );
        }

        private function get_relative_upload_file_variants( $relative_file ) {
            $relative_file = ltrim( (string) $relative_file, '/' );
            if ( '' === $relative_file ) {
                return array();
            }

            return array_values(
                array_unique(
                    array_filter(
                        array(
                            $relative_file,
                            rawurldecode( $relative_file ),
                            ltrim( $this->encode_url_path_segments( rawurldecode( $relative_file ) ), '/' ),
                        )
                    )
                )
            );
        }

        private function get_file_url_identity( $file_url ) {
            return $this->get_url_path( $file_url );
        }

        public function invalidate_attachment_lookup_metadata( $meta_id, $post_id, $meta_key ) {
            if ( '_wp_attached_file' === $meta_key ) wp_cache_set( 'last_changed', microtime(), 'pdf-thumbnail-inserter-lookup' );
        }

        private function get_attachment_lookup_cache_key( $values ) {
            return md5( wp_json_encode( $values ) . wp_cache_get_last_changed( 'posts' ) . wp_cache_get_last_changed( 'pdf-thumbnail-inserter-lookup' ) );
        }

        /**
         * One ranked, cached batch replaces a WP_Query/meta join for every filename/token.
         * A cold substring search still examines attachment metadata; batching removes repeated
         * joins/scans and cache invalidation prevents repeating it for every referring post.
         *
         * @param string[] $needles At most six literal relative paths or filename fragments.
         * @param string   $mode    exact, filename or reference (also search the display title).
         * @return int[]
         */
        private function find_attachment_filename_candidates( $needles, $mode = 'filename' ) {
            global $wpdb;
            $needles = array_slice( array_values( array_unique( array_filter( $needles, static function ( $needle ) { return '' !== $needle; } ) ) ), 0, 6 );
            if ( ! $needles ) return array();
            $key = $this->get_attachment_lookup_cache_key( array( $mode, $needles ) );
            $cached = wp_cache_get( $key, 'pdf-thumbnail-inserter-lookup' );
            if ( is_array( $cached ) ) return $cached;
            $values = array_pad( $needles, 6, '' );
            if ( 'exact' === $mode ) {
                $condition = $wpdb->prepare( "pti_lookup_file.meta_value <> '' AND pti_lookup_file.meta_value IN (%s,%s,%s,%s,%s,%s)",
                    $values[0], $values[1], $values[2], $values[3], $values[4], $values[5] );
                $score = $wpdb->prepare( 'CASE pti_lookup_file.meta_value WHEN %s THEN 6 WHEN %s THEN 5 WHEN %s THEN 4 WHEN %s THEN 3 WHEN %s THEN 2 WHEN %s THEN 1 ELSE 0 END',
                    $values[0], $values[1], $values[2], $values[3], $values[4], $values[5] );
            } else {
                $arguments = array();
                foreach ( $values as $value ) {
                    $pattern = '%' . $wpdb->esc_like( $value ) . '%';
                    $arguments[] = $value;
                    $arguments[] = 'reference' === $mode ? 1 : 0;
                    $arguments[] = $pattern;
                    $arguments[] = $pattern;
                }
                $score = $wpdb->prepare(
                    "(CASE WHEN %s <> '' AND ((%d = 1 AND {$wpdb->posts}.post_title LIKE %s) OR pti_lookup_file.meta_value LIKE %s) THEN 1 ELSE 0 END) +
                    (CASE WHEN %s <> '' AND ((%d = 1 AND {$wpdb->posts}.post_title LIKE %s) OR pti_lookup_file.meta_value LIKE %s) THEN 1 ELSE 0 END) +
                    (CASE WHEN %s <> '' AND ((%d = 1 AND {$wpdb->posts}.post_title LIKE %s) OR pti_lookup_file.meta_value LIKE %s) THEN 1 ELSE 0 END) +
                    (CASE WHEN %s <> '' AND ((%d = 1 AND {$wpdb->posts}.post_title LIKE %s) OR pti_lookup_file.meta_value LIKE %s) THEN 1 ELSE 0 END) +
                    (CASE WHEN %s <> '' AND ((%d = 1 AND {$wpdb->posts}.post_title LIKE %s) OR pti_lookup_file.meta_value LIKE %s) THEN 1 ELSE 0 END) +
                    (CASE WHEN %s <> '' AND ((%d = 1 AND {$wpdb->posts}.post_title LIKE %s) OR pti_lookup_file.meta_value LIKE %s) THEN 1 ELSE 0 END)",
                    $arguments[0], $arguments[1], $arguments[2], $arguments[3], $arguments[4], $arguments[5], $arguments[6], $arguments[7],
                    $arguments[8], $arguments[9], $arguments[10], $arguments[11], $arguments[12], $arguments[13], $arguments[14], $arguments[15],
                    $arguments[16], $arguments[17], $arguments[18], $arguments[19], $arguments[20], $arguments[21], $arguments[22], $arguments[23]
                );
                $condition = '(' . $score . ') > 0';
            }
            $query = new WP_Query();
            $clauses_filter = static function ( $clauses, $current_query ) use ( $query, $condition, $score, $wpdb ) {
                if ( $current_query !== $query ) return $clauses;
                $clauses['join'] .= " INNER JOIN {$wpdb->postmeta} pti_lookup_file ON {$wpdb->posts}.ID = pti_lookup_file.post_id";
                $clauses['where'] .= " AND pti_lookup_file.meta_key = '_wp_attached_file' AND (" . $condition . ')';
                $clauses['groupby'] = "{$wpdb->posts}.ID, {$wpdb->posts}.post_date";
                $clauses['orderby'] = ( '' !== $score ? 'MAX(' . $score . ') DESC, ' : '' ) . "{$wpdb->posts}.post_date DESC, {$wpdb->posts}.ID DESC";
                return $clauses;
            };
            add_filter( 'posts_clauses', $clauses_filter, 10, 2 );
            try {
                $ids = $query->query( array( 'post_type' => 'attachment', 'post_status' => 'inherit',
                    'posts_per_page' => 20, 'fields' => 'ids', 'no_found_rows' => true ) );
            } finally {
                remove_filter( 'posts_clauses', $clauses_filter, 10 );
            }
            $ids = array_map( 'absint', $ids );
            if ( $ids ) {
                // Prime full posts and metadata together before candidate validation/scoring.
                get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post__in' => $ids,
                    'posts_per_page' => 20, 'orderby' => 'post__in', 'no_found_rows' => true ) );
            }
            wp_cache_set( $key, $ids, 'pdf-thumbnail-inserter-lookup', 300 );
            return $ids;
        }

        private function get_attachment_id_from_file_url( $file_url ) {
            $key = $this->get_attachment_lookup_cache_key( array( 'url', $file_url ) );
            $cached = wp_cache_get( $key, 'pdf-thumbnail-inserter-lookup' );
            if ( false !== $cached ) return absint( $cached );
            $id = $this->resolve_attachment_id_from_file_url( $file_url );
            wp_cache_set( $key, $id, 'pdf-thumbnail-inserter-lookup', 300 );
            return $id;
        }

        private function resolve_attachment_id_from_file_url( $file_url ) {
            $attachment_id = absint( attachment_url_to_postid( $file_url ) );
            if ( $attachment_id ) {
                return $attachment_id;
            }

            $uploads     = wp_get_upload_dir();
            $uploads_url = ! empty( $uploads['baseurl'] ) ? $this->get_file_url_identity( $uploads['baseurl'] ) : '';
            $file_path   = $this->get_file_url_identity( $file_url );

            if ( '' === $uploads_url || '' === $file_path || 0 !== strpos( $file_path, trailingslashit( $uploads_url ) ) ) {
                return 0;
            }

            $relative_file = ltrim( substr( $file_path, strlen( trailingslashit( $uploads_url ) ) ), '/' );
            if ( '' === $relative_file ) {
                return 0;
            }

            $variants = $this->get_relative_upload_file_variants( $relative_file );
            $attachment_ids = $this->find_attachment_filename_candidates( $variants, 'exact' );
            foreach ( $variants as $variant ) {
                foreach ( $attachment_ids as $id ) {
                    if ( get_post_meta( $id, '_wp_attached_file', true ) === $variant ) return $id;
                }
            }
            return $attachment_ids ? $attachment_ids[0] : 0;
        }

        private function get_file_url_from_block_markup( $markup ) {
            $markup = (string) $markup;
            if ( '' === trim( $markup ) ) {
                return '';
            }

            if ( preg_match_all( '~<a\b([^>]*)\bhref=(["\'])(.*?)\2[^>]*>~is', $markup, $matches, PREG_SET_ORDER ) ) {
                foreach ( $matches as $match ) {
                    $tag = $match[0];
                    if ( false !== strpos( $tag, 'wp-block-file__button' ) ) {
                        continue;
                    }

                    $candidate = esc_url_raw( html_entity_decode( $match[3], ENT_QUOTES, get_bloginfo( 'charset' ) ) );
                    if ( '' !== $candidate ) {
                        return $candidate;
                    }
                }
            }

            if ( preg_match( '~<(?:object|embed)\b[^>]*(?:data|src)=(["\'])(.*?)\1~is', $markup, $match ) ) {
                return esc_url_raw( html_entity_decode( $match[2], ENT_QUOTES, get_bloginfo( 'charset' ) ) );
            }

            return '';
        }

        private function get_gutenberg_file_block_markup( $block, $block_content = '' ) {
            $markup = (string) $block_content;

            if ( '' === $markup && ! empty( $block['innerHTML'] ) ) {
                $markup = (string) $block['innerHTML'];
            }

            if ( '' === $markup && ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
                $markup = implode( '', array_map( 'strval', $block['innerContent'] ) );
            }

            return $markup;
        }

        private function is_gutenberg_file_preview_markup( $markup ) {
            $markup = (string) $markup;
            if ( '' === trim( $markup ) ) {
                return false;
            }

            return (bool) preg_match( '~<(?:object|embed)\b[^>]*\bclass=(["\'])[^"\']*\bwp-block-file__embed\b[^"\']*\1[^>]*(?:data|src)=(["\'])(.*?)\2~is', $markup )
                || (bool) preg_match( '~<(?:object|embed)\b[^>]*(?:data|src)=(["\'])(.*?)\1[^>]*\btype=(["\'])application/pdf\3~is', $markup );
        }

        private function get_gutenberg_file_block_data( $block, $block_content = '' ) {
            $attributes        = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
            $attachment_id     = isset( $attributes['id'] ) ? absint( $attributes['id'] ) : 0;
            $block_file_url    = ! empty( $attributes['href'] ) ? esc_url_raw( $attributes['href'] ) : '';
            $attachment_url    = '';
            $file_url          = '';
            $mime              = '';
            $markup            = $this->get_gutenberg_file_block_markup( $block, $block_content );
            $display_preview   = ! empty( $attributes['displayPreview'] ) || $this->is_gutenberg_file_preview_markup( $markup );

            if ( '' === $block_file_url ) {
                $block_file_url = $this->get_file_url_from_block_markup( $markup );
            }

            if ( $attachment_id && get_post( $attachment_id ) ) {
                $attachment_url = (string) wp_get_attachment_url( $attachment_id );
                $mime           = (string) get_post_mime_type( $attachment_id );
            }

            if ( '' !== $block_file_url ) {
                $file_url = $block_file_url;

                if (
                    '' === $attachment_url
                    || $this->get_file_url_identity( $attachment_url ) !== $this->get_file_url_identity( $block_file_url )
                ) {
                    $resolved_attachment_id = $this->get_attachment_id_from_file_url( $block_file_url );
                    $attachment_id          = $resolved_attachment_id;
                    $mime                   = $resolved_attachment_id ? (string) get_post_mime_type( $resolved_attachment_id ) : '';
                }
            } elseif ( '' !== $attachment_url ) {
                $file_url = $attachment_url;
            }

            if ( '' === $file_url ) {
                return array(
                    'attachment_id'   => $attachment_id,
                    'file_url'        => '',
                    'mime'            => '',
                    'extension'       => '',
                    'is_pdf'          => false,
                    'is_doc'          => false,
                    'display_preview' => $display_preview,
                );
            }

            $path      = $this->get_url_path( $file_url );
            $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

            if ( '' === $mime ) {
                $file_type = wp_check_filetype( $path );
                $mime      = ! empty( $file_type['type'] ) ? (string) $file_type['type'] : '';
            }

            $is_pdf = 'application/pdf' === $mime || 'pdf' === $extension;
            $is_doc = $this->is_word_document_mime( $mime ) || in_array( $extension, array( 'doc', 'docx' ), true );

            return array(
                'attachment_id'   => $attachment_id,
                'file_url'        => $file_url,
                'mime'            => $mime,
                'extension'       => $extension,
                'is_pdf'          => $is_pdf,
                'is_doc'          => $is_doc,
                'display_preview' => $display_preview,
            );
        }

        private function normalize_pdf_candidate_url( $candidate ) {
            $candidate = html_entity_decode( (string) $candidate, ENT_QUOTES, get_bloginfo( 'charset' ) );
            $candidate = trim( wp_unslash( $candidate ) );

            if ( '' === $candidate ) {
                return '';
            }

            $query = (string) wp_parse_url( $candidate, PHP_URL_QUERY );
            if ( '' !== $query ) {
                parse_str( $query, $query_args );
                if ( ! empty( $query_args['file'] ) ) {
                    $embedded_file = rawurldecode( (string) $query_args['file'] );
                    $embedded_path = $this->get_url_path( $embedded_file );
                    if ( 'pdf' === strtolower( pathinfo( $embedded_path, PATHINFO_EXTENSION ) ) ) {
                        $candidate = $embedded_file;
                    }
                }
            }

            if ( 0 === strpos( $candidate, '//' ) ) {
                $candidate = ( is_ssl() ? 'https:' : 'http:' ) . $candidate;
            } elseif ( 0 === strpos( $candidate, '/' ) ) {
                $candidate = home_url( $candidate );
            } elseif ( 0 === strpos( $candidate, 'wp-content/' ) ) {
                $candidate = home_url( '/' . $candidate );
            }

            $path = $this->get_url_path( $candidate );
            if ( 'pdf' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
                return '';
            }

            return esc_url_raw( $candidate );
        }

        private function get_pdf_file_data_from_url( $file_url ) {
            $file_url = $this->normalize_pdf_candidate_url( $file_url );
            if ( '' === $file_url ) {
                return array();
            }

            $attachment_id = $this->get_attachment_id_from_file_url( $file_url );

            return array(
                'attachment_id'   => $attachment_id,
                'file_url'        => $file_url,
                'mime'            => $attachment_id ? (string) get_post_mime_type( $attachment_id ) : 'application/pdf',
                'extension'       => 'pdf',
                'is_pdf'          => true,
                'is_doc'          => false,
                'display_preview' => false,
            );
        }

        private function get_pdf_file_data_from_attachment_id( $attachment_id, $follow_parent = true ) {
            $attachment_id = absint( $attachment_id );
            $attachment    = $attachment_id ? get_post( $attachment_id ) : null;

            if ( ! $attachment ) {
                return array();
            }

            $file_url = (string) wp_get_attachment_url( $attachment_id );
            $mime      = (string) get_post_mime_type( $attachment_id );
            $path      = $this->get_url_path( $file_url );
            $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

            if ( 'application/pdf' !== $mime && 'pdf' !== $extension ) {
                if ( $follow_parent && ! empty( $attachment->post_parent ) ) {
                    $parent_data = $this->get_pdf_file_data_from_attachment_id( $attachment->post_parent, false );
                    if ( ! empty( $parent_data['is_pdf'] ) ) {
                        return $parent_data;
                    }
                }

                return array();
            }

            if ( '' === $file_url ) {
                return array();
            }

            return array(
                'attachment_id'   => $attachment_id,
                'file_url'        => esc_url_raw( $file_url ),
                'mime'            => '' !== $mime ? $mime : 'application/pdf',
                'extension'       => 'pdf',
                'is_pdf'          => true,
                'is_doc'          => false,
                'display_preview' => false,
            );
        }

        private function normalize_reference_pdf_title( $title ) {
            $title = html_entity_decode( (string) $title, ENT_QUOTES, get_bloginfo( 'charset' ) );
            $title = wp_strip_all_tags( $title );
            $title = preg_replace( '/\.pdf$/iu', '', $title );
            $title = preg_replace( '/\s+/u', ' ', $title );

            return trim( $title );
        }

        private function normalize_reference_pdf_lookup_text( $text ) {
            $text = html_entity_decode( (string) $text, ENT_QUOTES, get_bloginfo( 'charset' ) );
            $text = rawurldecode( $text );
            $text = wp_strip_all_tags( $text );
            $text = preg_replace( '/\.pdf$/iu', '', $text );

            if ( function_exists( 'mb_strtolower' ) ) {
                $text = mb_strtolower( $text, get_bloginfo( 'charset' ) );
            } else {
                $text = strtolower( $text );
            }

            $text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
            $text = preg_replace( '/\s+/u', ' ', $text );

            return trim( $text );
        }

        private function get_reference_pdf_title_tokens( $title ) {
            $normalized = $this->normalize_reference_pdf_lookup_text( $title );
            if ( '' === $normalized ) {
                return array();
            }

            $tokens = preg_split( '/\s+/u', $normalized );
            $tokens = array_filter(
                array_unique( array_map( 'trim', (array) $tokens ) ),
                function ( $token ) {
                    $length = function_exists( 'mb_strlen' ) ? mb_strlen( $token, get_bloginfo( 'charset' ) ) : strlen( $token );
                    return $length >= 3;
                }
            );

            usort(
                $tokens,
                function ( $left, $right ) {
                    $left_length  = function_exists( 'mb_strlen' ) ? mb_strlen( $left, get_bloginfo( 'charset' ) ) : strlen( $left );
                    $right_length = function_exists( 'mb_strlen' ) ? mb_strlen( $right, get_bloginfo( 'charset' ) ) : strlen( $right );
                    return $right_length <=> $left_length;
                }
            );

            return $tokens;
        }

        private function score_reference_pdf_candidate( $attachment_id, $tokens ) {
            $attachment_id = absint( $attachment_id );
            $tokens        = array_values( array_filter( (array) $tokens ) );
            if ( ! $attachment_id || empty( $tokens ) ) {
                return 0;
            }

            $file_url = (string) wp_get_attachment_url( $attachment_id );
            $haystack = implode(
                ' ',
                array_filter(
                    array(
                        get_the_title( $attachment_id ),
                        basename( $this->get_url_path( $file_url ) ),
                        get_post_meta( $attachment_id, '_wp_attached_file', true ),
                    )
                )
            );
            $haystack = $this->normalize_reference_pdf_lookup_text( $haystack );

            if ( '' === $haystack ) {
                return 0;
            }

            $score = 0;
            foreach ( $tokens as $token ) {
                if ( false !== strpos( $haystack, $token ) ) {
                    $score++;
                }
            }

            return $score;
        }

        private function get_pdf_file_data_from_loose_reference_title( $title ) {
            $tokens = $this->get_reference_pdf_title_tokens( $title );
            if ( empty( $tokens ) ) {
                return array();
            }

            $attachment_ids = $this->find_attachment_filename_candidates( array_slice( $tokens, 0, 6 ), 'reference' );

            $best_data  = array();
            $best_score = 0;
            foreach ( array_values( array_unique( array_filter( $attachment_ids ) ) ) as $attachment_id ) {
                $file_data = $this->get_pdf_file_data_from_attachment_id( $attachment_id );
                if ( empty( $file_data['is_pdf'] ) ) {
                    continue;
                }

                $score = $this->score_reference_pdf_candidate( $attachment_id, $tokens );
                if ( $score > $best_score ) {
                    $best_score = $score;
                    $best_data  = $file_data;
                }
            }

            $minimum_score = min( 3, max( 2, (int) ceil( count( $tokens ) / 3 ) ) );
            return $best_score >= $minimum_score ? $best_data : array();
        }

        private function get_pdf_file_data_from_reference_title( $title ) {
            $title = $this->normalize_reference_pdf_title( $title );
            if ( '' === $title ) {
                return array();
            }

            $attachment_ids = array();
            $query_sets     = array(
                array( 'title' => $title ),
                array( 's' => $title ),
            );

            foreach ( $query_sets as $query_args ) {
                $found_ids = get_posts(
                    array_merge(
                        array(
                            'post_type'      => 'attachment',
                            'post_status'    => 'inherit',
                            'posts_per_page' => 10,
                            'fields'         => 'ids',
                            'no_found_rows'  => true,
                        ),
                        $query_args
                    )
                );

                if ( ! empty( $found_ids ) ) {
                    $attachment_ids = array_merge( $attachment_ids, array_map( 'absint', $found_ids ) );
                }
            }

            $file_variants = array_values(
                array_unique(
                    array_filter(
                        array(
                            $title,
                            str_replace( ' ', '-', $title ),
                            str_replace( ' ', '_', $title ),
                            rawurlencode( $title ),
                            rawurlencode( str_replace( ' ', '-', $title ) ),
                            rawurlencode( str_replace( ' ', '_', $title ) ),
                        )
                    )
                )
            );

            $attachment_ids = array_merge( $attachment_ids, $this->find_attachment_filename_candidates( $file_variants ) );

            foreach ( array_values( array_unique( array_filter( $attachment_ids ) ) ) as $attachment_id ) {
                $file_data = $this->get_pdf_file_data_from_attachment_id( $attachment_id );
                if ( ! empty( $file_data['is_pdf'] ) ) {
                    return $file_data;
                }
            }

            return $this->get_pdf_file_data_from_loose_reference_title( $title );
        }

        private function get_first_pdf_from_reference_atts( $atts ) {
            if ( ! is_array( $atts ) ) {
                return array();
            }

            if ( ! empty( $atts['url'] ) ) {
                $file_data = $this->get_pdf_file_data_from_url( $atts['url'] );
                if ( ! empty( $file_data['is_pdf'] ) ) {
                    return $file_data;
                }
            }

            $raw_ids = array();
            if ( ! empty( $atts['ids'] ) ) {
                $raw_ids = is_array( $atts['ids'] ) ? $atts['ids'] : preg_split( '/[\s,]+/', (string) $atts['ids'] );
            }

            if ( ! empty( $atts['id'] ) ) {
                array_unshift( $raw_ids, $atts['id'] );
            }

            foreach ( (array) $raw_ids as $raw_id ) {
                $file_data = $this->get_pdf_file_data_from_attachment_id( $raw_id );
                if ( ! empty( $file_data['is_pdf'] ) ) {
                    return $file_data;
                }
            }

            if ( ! empty( $atts['title'] ) ) {
                $file_data = $this->get_pdf_file_data_from_reference_title( $atts['title'] );
                if ( ! empty( $file_data['is_pdf'] ) ) {
                    return $file_data;
                }
            }

            return array();
        }

        private function find_first_pdf_in_post_content( $post_content ) {
            $post_content = (string) $post_content;
            if ( '' === trim( $post_content ) ) {
                return array();
            }

            $candidates = array();

            if ( preg_match_all( '/\[(?:pdf_thumbnail_inserter|pdf_thumbnail)\b([^\]]*)\]/i', $post_content, $shortcodes, PREG_OFFSET_CAPTURE ) ) {
                foreach ( $shortcodes[0] as $index => $whole_match ) {
                    $raw_atts = isset( $shortcodes[1][ $index ][0] ) ? $shortcodes[1][ $index ][0] : '';
                    $atts     = shortcode_parse_atts( $raw_atts );
                    $data     = $this->get_first_pdf_from_reference_atts( is_array( $atts ) ? $atts : array() );

                    if ( ! empty( $data['is_pdf'] ) ) {
                        $candidates[] = array(
                            'offset' => (int) $whole_match[1],
                            'data'   => $data,
                        );
                    }
                }
            }

            if ( preg_match_all( '~<!--\s+wp:pdf-thumbnail-inserter/pdf-thumbnail\s+(\{.*?\})\s*(?:/)?-->~is', $post_content, $block_comments, PREG_OFFSET_CAPTURE ) ) {
                foreach ( $block_comments[0] as $index => $whole_match ) {
                    $json = isset( $block_comments[1][ $index ][0] ) ? $block_comments[1][ $index ][0] : '';
                    $atts = json_decode( html_entity_decode( $json, ENT_QUOTES, get_bloginfo( 'charset' ) ), true );
                    $data = $this->get_first_pdf_from_reference_atts( is_array( $atts ) ? $atts : array() );

                    if ( ! empty( $data['is_pdf'] ) ) {
                        $candidates[] = array(
                            'offset' => (int) $whole_match[1],
                            'data'   => $data,
                        );
                    }
                }
            }

            $patterns = array(
                '~\b(?:href|data|src)\s*=\s*(["\'])(.*?)\1~is',
                '~"href"\s*:\s*"([^"]+)"~is',
                '~(?:(?:https?:)?//|/|wp-content/)[^\s"\'<>]+?\.pdf(?:\?[^\s"\'<>]*)?~iu',
            );

            foreach ( $patterns as $pattern_index => $pattern ) {
                if ( ! preg_match_all( $pattern, $post_content, $matches, PREG_OFFSET_CAPTURE ) ) {
                    continue;
                }

                foreach ( $matches[0] as $index => $whole_match ) {
                    if ( 0 === $pattern_index ) {
                        $candidate = isset( $matches[2][ $index ][0] ) ? $matches[2][ $index ][0] : '';
                    } elseif ( 1 === $pattern_index ) {
                        $candidate = isset( $matches[1][ $index ][0] ) ? $matches[1][ $index ][0] : '';
                    } else {
                        $candidate = $whole_match[0];
                    }

                    $candidate = str_replace( '\\/', '/', $candidate );
                    $data      = $this->get_pdf_file_data_from_url( $candidate );
                    if ( empty( $data['is_pdf'] ) ) {
                        continue;
                    }

                    $candidates[] = array(
                        'offset' => (int) $whole_match[1],
                        'data'   => $data,
                    );
                }
            }

            if ( ! empty( $candidates ) ) {
                usort(
                    $candidates,
                    static function ( $left, $right ) {
                        return $left['offset'] <=> $right['offset'];
                    }
                );

                return $candidates[0]['data'];
            }

            return $this->find_first_pdf_block_data( parse_blocks( $post_content ) );
        }

        private function find_first_preview_pdf_in_post_content( $post_content ) {
            $post_content = (string) $post_content;
            if ( '' === trim( $post_content ) ) {
                return array();
            }

            $file_data = $this->find_first_preview_pdf_block_data( parse_blocks( $post_content ) );
            if ( ! empty( $file_data['is_pdf'] ) ) {
                return $file_data;
            }

            if ( preg_match_all( '~<(?:object|embed)\b[^>]*(?:data|src)=(["\'])(.*?)\1[^>]*>~is', $post_content, $matches, PREG_OFFSET_CAPTURE ) ) {
                foreach ( $matches[0] as $index => $whole_match ) {
                    $markup = $whole_match[0];
                    if ( ! $this->is_gutenberg_file_preview_markup( $markup ) ) {
                        continue;
                    }

                    $candidate = isset( $matches[2][ $index ][0] ) ? $matches[2][ $index ][0] : '';
                    $data      = $this->get_pdf_file_data_from_url( $candidate );
                    if ( empty( $data['is_pdf'] ) ) {
                        continue;
                    }

                    $data['display_preview'] = true;
                    return $data;
                }
            }

            return array();
        }

        private function find_first_pdf_block_data( $blocks ) {
            if ( ! is_array( $blocks ) ) {
                return array();
            }

            foreach ( $blocks as $block ) {
                if ( ! is_array( $block ) ) {
                    continue;
                }

                $block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

                if ( 'core/file' === $block_name ) {
                    $file_data = $this->get_gutenberg_file_block_data( $block );
                    if ( ! empty( $file_data['is_pdf'] ) ) {
                        return $file_data;
                    }
                }

                if ( 'pdf-thumbnail-inserter/pdf-thumbnail' === $block_name ) {
                    $file_data = $this->get_first_pdf_from_reference_atts( isset( $block['attrs'] ) ? $block['attrs'] : array() );
                    if ( ! empty( $file_data['is_pdf'] ) ) {
                        return $file_data;
                    }
                }

                if ( ! empty( $block['innerBlocks'] ) ) {
                    $file_data = $this->find_first_pdf_block_data( $block['innerBlocks'] );
                    if ( ! empty( $file_data ) ) {
                        return $file_data;
                    }
                }
            }

            return array();
        }

        private function find_first_preview_pdf_block_data( $blocks ) {
            if ( ! is_array( $blocks ) ) {
                return array();
            }

            foreach ( $blocks as $block ) {
                if ( ! is_array( $block ) ) {
                    continue;
                }

                $block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

                if ( 'core/file' === $block_name ) {
                    $file_data = $this->get_gutenberg_file_block_data( $block );
                    if ( ! empty( $file_data['is_pdf'] ) && ! empty( $file_data['display_preview'] ) ) {
                        return $file_data;
                    }
                }

                if ( ! empty( $block['innerBlocks'] ) ) {
                    $file_data = $this->find_first_preview_pdf_block_data( $block['innerBlocks'] );
                    if ( ! empty( $file_data ) ) {
                        return $file_data;
                    }
                }
            }

            return array();
        }

        /** Resolve only real PDF files within this site's uploads, never decoded traversal or symlinks outside it. */
        private function get_local_upload_pdf_path( $file_url ) {
            return $this->get_local_upload_file_path( $file_url, 'pdf' );
        }

        private function get_local_upload_file_path( $file_url, $extension = '' ) {
            $uploads = wp_get_upload_dir();
            if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
                return '';
            }

            $file_host = wp_parse_url( $file_url, PHP_URL_HOST );
            $base_host = wp_parse_url( $uploads['baseurl'], PHP_URL_HOST );
            $scheme = wp_parse_url( $file_url, PHP_URL_SCHEME );
            if ( ! $file_host || ! $base_host || strtolower( $file_host ) !== strtolower( $base_host )
                || ! in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true )
                || wp_parse_url( $file_url, PHP_URL_PORT ) !== wp_parse_url( $uploads['baseurl'], PHP_URL_PORT )
                || wp_parse_url( $file_url, PHP_URL_USER ) || wp_parse_url( $file_url, PHP_URL_PASS ) ) {
                return '';
            }

            $file_path = $this->get_file_url_identity( $file_url );
            $base_path = trailingslashit( $this->get_file_url_identity( $uploads['baseurl'] ) );
            if ( 0 !== strpos( $file_path, $base_path ) || ( $extension && $extension !== strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) ) )
                || false !== strpos( $file_path, "\0" ) || false !== strpos( $file_path, '\\' ) ) {
                return '';
            }
            $relative_file = substr( $file_path, strlen( $base_path ) );
            foreach ( explode( '/', $relative_file ) as $segment ) {
                if ( '.' === $segment || '..' === $segment ) {
                    return '';
                }
            }

            $real_uploads = realpath( $uploads['basedir'] );
            $real_file = realpath( trailingslashit( $uploads['basedir'] ) . $relative_file );
            if ( ! $real_uploads || ! $real_file || ! is_file( $real_file ) || ! is_readable( $real_file ) ) {
                return '';
            }
            $prefix = trailingslashit( wp_normalize_path( $real_uploads ) );
            $path = wp_normalize_path( $real_file );
            $inside = '\\' === DIRECTORY_SEPARATOR ? 0 === stripos( $path, $prefix ) : 0 === strpos( $path, $prefix );
            return $inside && ( ! $extension || $extension === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) ? $path : '';
        }

        private function get_pdf_preview_url_from_file_url( $file_url, $generate_if_missing = false ) {
            $file_url = esc_url_raw( $file_url );
            $pdf_path = $this->get_local_upload_pdf_path( $file_url );
            if ( '' === $pdf_path ) {
                return '';
            }

            $uploads = wp_get_upload_dir();

            $path_info = pathinfo( $pdf_path );
            $dir_path  = ! empty( $path_info['dirname'] ) ? wp_normalize_path( $path_info['dirname'] ) : '';
            $filename  = ! empty( $path_info['filename'] ) ? (string) $path_info['filename'] : '';

            if ( '' === $dir_path || '' === $filename ) {
                return '';
            }

            $preferred_candidates = array(
                $dir_path . '/' . $filename . '-pdf.jpg',
                $dir_path . '/' . $filename . '-pdf.jpeg',
                $dir_path . '/' . $filename . '-pdf.png',
                $dir_path . '/' . $filename . '-pdf.webp',
                $dir_path . '/' . $filename . '-pdf-preview.jpg',
                $dir_path . '/' . $filename . '-pdf-preview.jpeg',
                $dir_path . '/' . $filename . '-pdf-preview.png',
                $dir_path . '/' . $filename . '-pdf-preview.webp',
            );

            $candidate_paths = array();
            foreach ( $preferred_candidates as $candidate_path ) {
                if ( file_exists( $candidate_path ) ) {
                    $candidate_paths[] = wp_normalize_path( $candidate_path );
                }
            }

            // The directory and filename are literal filesystem data, not glob patterns.
            $glob_prefix = strtr( $dir_path . '/' . $filename, array( '[' => '[[]', ']' => '[]]', '?' => '[?]', '*' => '[*]' ) );
            foreach ( array( 'jpg', 'jpeg', 'png', 'webp' ) as $extension ) {
                $matches = glob( $glob_prefix . '-pdf*.' . $extension );
                if ( empty( $matches ) ) {
                    continue;
                }

                foreach ( $matches as $match ) {
                    if ( file_exists( $match ) ) {
                        $candidate_paths[] = wp_normalize_path( $match );
                    }
                }
            }

            $candidate_paths = array_values( array_unique( $candidate_paths ) );
            $preview_path = '';
            $largest_area = 0;
            $uploads_basedir = trailingslashit( wp_normalize_path( realpath( $uploads['basedir'] ) ) );

            foreach ( $candidate_paths as $candidate_path ) {
                $candidate_realpath = realpath( $candidate_path );
                if ( ! $candidate_realpath || 0 !== strpos( wp_normalize_path( $candidate_realpath ), $uploads_basedir ) ) {
                    continue;
                }
                $image_size = wp_getimagesize( $candidate_path );
                if ( ! is_array( $image_size ) || empty( $image_size[0] ) || empty( $image_size[1] ) ) {
                    continue;
                }

                $area = absint( $image_size[0] ) * absint( $image_size[1] );
                if ( $area > $largest_area ) {
                    $largest_area = $area;
                    $preview_path = $candidate_path;
                }
            }

            // Invalid existing images must not prevent regeneration of a missing usable preview.
            if ( '' === $preview_path && $generate_if_missing ) {
                $preview_path = $this->generate_standalone_pdf_preview( $pdf_path, $dir_path, $filename, $uploads['basedir'] );
            }
            if ( '' === $preview_path ) {
                return '';
            }

            $relative_preview = ltrim( substr( $preview_path, strlen( $uploads_basedir ) ), '/' );
            if ( '' === $relative_preview ) {
                return '';
            }

            return esc_url_raw( trailingslashit( $uploads['baseurl'] ) . $this->encode_url_path_segments( $relative_preview ) );
        }

        private function get_local_pdf_viewer_file_url( $pdf_url ) {
            $pdf_url = esc_url_raw( $this->normalize_pdf_candidate_url( $pdf_url ) );
            return '' !== $this->get_local_upload_pdf_path( $pdf_url ) ? $pdf_url : '';
        }

        private function get_pdfjs_viewer_url( $pdf_url ) {
            $pdf_url = $this->get_local_pdf_viewer_file_url( $pdf_url );
            if ( '' === $pdf_url ) {
                return '';
            }

            return esc_url_raw(
                add_query_arg(
                    array(
                        self::VIEWER_QUERY_VAR => 1,
                        'file'                 => rawurlencode( $pdf_url ),
                    ),
                    home_url( '/' )
                )
            );
        }

        public function maybe_render_pdfjs_viewer() {
            $viewer_requested = $this->public_preview_request[ self::VIEWER_QUERY_VAR ] ?? '';

            if ( '' === $viewer_requested ) {
                return;
            }

            $raw_file = $this->public_preview_request['file'] ?? '';
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

        private function render_pdf_canvas_preview( $pdf_url, $title = '', $classes = 'pti-featured-image-or-pdf-preview wp-post-image' ) {
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

        private function generate_standalone_pdf_preview( $pdf_path, $dir_path, $filename, $uploads_basedir ) {
            if ( ! class_exists( 'Imagick' ) || '' === $pdf_path || ! file_exists( $pdf_path ) ) {
                return '';
            }

            $real_pdf     = realpath( $pdf_path );
            $real_uploads = realpath( $uploads_basedir );
            if ( false === $real_pdf || false === $real_uploads ) {
                return '';
            }

            $real_pdf     = wp_normalize_path( $real_pdf );
            $real_uploads = trailingslashit( wp_normalize_path( $real_uploads ) );
            $inside_uploads = '\\' === DIRECTORY_SEPARATOR ? 0 === stripos( $real_pdf, $real_uploads ) : 0 === strpos( $real_pdf, $real_uploads );
            if ( ! $inside_uploads || 'pdf' !== strtolower( pathinfo( $real_pdf, PATHINFO_EXTENSION ) ) ) {
                return '';
            }

            $preview_path = wp_normalize_path( trailingslashit( $dir_path ) . $filename . '-pdf-preview.jpg' );
            if ( is_file( $preview_path ) && wp_getimagesize( $preview_path ) ) {
                return $preview_path;
            }

            $failure_key = 'pdf_thumbnail_inserter_preview_fail_' . md5( $real_pdf . ':' . (string) filemtime( $real_pdf ) );
            if ( get_transient( $failure_key ) ) {
                return '';
            }

            $result = $this->with_pdf_preview_lock(
                $real_pdf,
                function () use ( $real_pdf, $preview_path, $failure_key ) {
                    clearstatcache( true, $preview_path );
                    if ( is_file( $preview_path ) && wp_getimagesize( $preview_path ) ) {
                        return $preview_path;
                    }
                    $temp_path = $preview_path . '.tmp.jpg';
                    $imagick = null;
                    try {
                        $imagick = new \Imagick();
                        $imagick->setResolution( 150, 150 );
                        $imagick->readImage( $real_pdf . '[0]' );
                        $imagick->setImageFormat( 'jpeg' );
                        $imagick->setImageBackgroundColor( 'white' );
                        $imagick->setImageAlphaChannel( \Imagick::ALPHACHANNEL_REMOVE );
                        $flattened = $imagick->mergeImageLayers( \Imagick::LAYERMETHOD_FLATTEN );
                        $imagick->clear();
                        $imagick->destroy();
                        $imagick = $flattened;
                        $imagick->setImageCompressionQuality( 82 );
                        $imagick->thumbnailImage( 1200, 2000, true, false );
                        if ( ! $imagick->writeImage( $temp_path ) || ! wp_getimagesize( $temp_path ) ) {
                            return '';
                        }
                        // Publish atomically: WP_Filesystem::move may fall back to a non-atomic copy.
                        if ( ! @rename( $temp_path, $preview_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                            return '';
                        }
                        $this->invalidate_preview_caches();
                        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
                        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
                        $filesystem = new WP_Filesystem_Direct( null );
                        $filesystem->chmod( $preview_path, 0644 );
                        delete_transient( $failure_key );
                        return $preview_path;
                    } catch ( \Throwable $error ) {
                        return '';
                    } finally {
                        if ( $imagick instanceof \Imagick ) {
                            $imagick->clear();
                            $imagick->destroy();
                        }
                        if ( is_file( $temp_path ) ) {
                            wp_delete_file( $temp_path );
                        }
                        if ( ! is_file( $preview_path ) ) {
                            set_transient( $failure_key, 1, 6 * HOUR_IN_SECONDS );
                        }
                    }
                }
            );
            return is_wp_error( $result ) ? '' : $result;
        }

        /**
         * Resolve the first PDF, preserving the public API's legacy generation default.
         * Frontend renderers explicitly opt out; uploads and maintenance own generation.
         */
        public function resolve_first_pdf_for_post( int $post_id, $size = 'full', bool $generate_if_missing = true ) {
            $post_id = absint( $post_id );
            if ( ! $post_id ) {
                return false;
            }

            $cache_key = $post_id . ':' . md5( wp_json_encode( $size ) ) . ':' . (int) $generate_if_missing;
            if ( array_key_exists( $cache_key, $this->first_pdf_resolver_cache ) ) {
                return $this->first_pdf_resolver_cache[ $cache_key ];
            }

            $this->first_pdf_resolver_cache[ $cache_key ] = false;

            $post = get_post( $post_id );
            if ( ! $post || 'post' !== $post->post_type || '' === trim( (string) $post->post_content ) ) {
                return false;
            }

            $file_data = $this->find_first_preview_pdf_in_post_content( $post->post_content );
            if ( empty( $file_data['is_pdf'] ) ) {
                $file_data = $this->find_first_pdf_in_post_content( $post->post_content );
            }

            if ( empty( $file_data['is_pdf'] ) || empty( $file_data['file_url'] ) ) {
                return false;
            }

            $pdf_url     = esc_url_raw( $file_data['file_url'] );
            $pdf_id      = ! empty( $file_data['attachment_id'] ) ? absint( $file_data['attachment_id'] ) : 0;
            $preview_url = '';
            $preview_id  = 0;

            if ( $pdf_id && 'application/pdf' === get_post_mime_type( $pdf_id ) ) {
                $preview_data = $this->get_pdf_preview_data( $pdf_id, $size, $generate_if_missing );

                if (
                    $generate_if_missing && 'placeholder' === $preview_data['preview_source']
                    && ! get_post_meta( $pdf_id, self::META_PREVIEW_ERROR, true )
                ) {
                    $this->ensure_pdf_preview( $pdf_id );
                    $preview_data = $this->get_pdf_preview_data( $pdf_id, $size, $generate_if_missing );
                }

                if ( ! empty( $preview_data['thumbnail_url'] ) && 'placeholder' !== $preview_data['preview_source'] ) {
                    $preview_url = esc_url_raw( $preview_data['thumbnail_url'] );
                    $preview_id  = ! empty( $preview_data['preview_id'] ) ? absint( $preview_data['preview_id'] ) : $pdf_id;
                }
            }

            if ( '' === $preview_url ) {
                $preview_url = $this->get_pdf_preview_url_from_file_url( $pdf_url, $generate_if_missing );
            }

            $result = array(
                'pdf_url'       => $pdf_url,
                'attachment_id' => $pdf_id,
                'preview_url'   => esc_url_raw( $preview_url ),
                'preview_id'    => $preview_id,
            );

            $this->first_pdf_resolver_cache[ $cache_key ] = $result;

            return $result;
        }

        private function get_document_cover_url( $pdf_url, $title = '', $shape = 'landscape' ) {
            $settings = $this->get_settings();
            $pdf_url = esc_url_raw( $pdf_url );
            if ( empty( $settings['use_default_document_cover'] ) || 'pdf' !== strtolower( pathinfo( $this->get_url_path( $pdf_url ), PATHINFO_EXTENSION ) ) ) {
                return '';
            }
            $title = sanitize_text_field( $title );
            if ( '' === $title ) {
                $title = $this->get_file_title_from_url( $pdf_url, __( 'PDF File', 'pdf-thumbnail-inserter' ) );
            }
            return esc_url_raw( add_query_arg(
                array(
                    self::COVER_QUERY_VAR => 1,
                    'file' => rawurlencode( $pdf_url ),
                    'title' => rawurlencode( $title ),
                    'shape' => 'portrait' === $shape ? 'portrait' : 'landscape',
                    'ver' => md5( wp_json_encode( array( self::VERSION, $title, $settings['document_cover_label'], $settings['button_color'], $settings['title_color'] ) ) ),
                ),
                home_url( '/' )
            ) );
        }

        private function render_document_cover_svg( $title, $shape = 'landscape' ) {
            $settings = $this->get_settings();
            $defaults = $this->get_defaults();
            $background = '#f6f7f7';
            $accent = $this->sanitize_hex_color_with_fallback( $settings['button_color'], $defaults['button_color'] );
            $text = $this->sanitize_hex_color_with_fallback( $settings['title_color'], $defaults['title_color'] );
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
            $title_text = '';
            foreach ( $lines as $index => $title_line ) {
                $title_line = wp_html_excerpt( $title_line, $max_chars, '…' );
                if ( $overflow && 2 === $index ) {
                    $title_line = wp_html_excerpt( $title_line, $max_chars - 1, '' ) . '…';
                }
                $title_text .= sprintf( '<tspan x="%1$d" y="%2$d">%3$s</tspan>', $text_x, ( $portrait ? 650 : 338 ) + $index * 68, esc_html( $title_line ) );
            }

            // All text and colors are escaped; no scripts, external assets or fonts.
            return sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %7$d %8$d" width="%7$d" height="%8$d" role="img" aria-label="%1$s" style="max-width:100%%;height:auto">'
                . '<rect width="%7$d" height="%8$d" fill="%2$s"/><rect width="18" height="%8$d" fill="%3$s"/>'
                . '<g transform="translate(%9$d,%10$d)" fill="none" stroke="%3$s" stroke-width="9" stroke-linejoin="round" stroke-linecap="round"><path d="M100 205h115l65 65v250H100z"/><path d="M215 205v65h65M135 330h110M135 375h110M135 420h70"/></g>'
                . '<g font-family="Arial, sans-serif"><text x="%11$d" y="%12$d" font-size="32" font-weight="700" fill="%3$s">%4$s</text><text font-size="48" font-weight="600" fill="%5$s">%6$s</text></g>'
                . '<rect x="%11$d" y="%13$d" width="110" height="8" rx="4" fill="%3$s"/></svg>',
                esc_attr( trim( $label . ' ' . $title ) ), esc_attr( $background ), esc_attr( $accent ), esc_html( $label ), esc_attr( $text ), $title_text,
                $portrait ? 730 : 1170, $portrait ? 960 : 725, $portrait ? 165 : 0, $portrait ? -80 : 0, $text_x, $portrait ? 550 : 235, $portrait ? 865 : 560
            );
        }

        public function maybe_render_document_cover() {
            if ( empty( $this->public_preview_request['cover_requested'] ) ) {
                return;
            }
            $requested = $this->public_preview_request[ self::COVER_QUERY_VAR ];
            $pdf_url = $this->public_preview_request['file'];
            $title = $this->public_preview_request['title'];
            $shape = $this->public_preview_request['shape'];
            $shape = 'portrait' === $shape ? 'portrait' : 'landscape';
            if ( '1' !== $requested || '' === $this->get_document_cover_url( $pdf_url, $title, $shape ) ) {
                status_header( 404 );
                exit;
            }
            if ( '' === $title ) {
                $title = $this->get_file_title_from_url( $pdf_url, __( 'PDF File', 'pdf-thumbnail-inserter' ) );
            }
            status_header( 200 );
            header( 'Content-Type: image/svg+xml; charset=UTF-8' );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'X-Robots-Tag: noindex' );
            header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox" );
            header( 'Cache-Control: public, max-age=3600' );
            echo $this->render_document_cover_svg( $title, $shape ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            exit;
        }

        private function get_pdf_featured_image_fallback_data( $post_id, $size = 'full' ) {
            $post_id = absint( $post_id );
            if ( ! $post_id ) {
                return array(
                    'id'  => 0,
                    'url' => '',
                );
            }

            $cache_key = $post_id . ':' . md5( wp_json_encode( $size ) );
            if ( array_key_exists( $cache_key, $this->pdf_featured_image_cache ) ) {
                return $this->pdf_featured_image_cache[ $cache_key ];
            }

            $fallback = array(
                'id'      => 0,
                'url'     => '',
                'pdf_url' => '',
            );
            $this->pdf_featured_image_cache[ $cache_key ] = $fallback;

            $resolved = $this->resolve_first_pdf_for_post( $post_id, $size, false );
            if ( empty( $resolved ) || empty( $resolved['pdf_url'] ) ) {
                $this->pdf_featured_image_cache[ $cache_key ] = $fallback;
                return $fallback;
            }

            $fallback['pdf_url'] = esc_url_raw( $resolved['pdf_url'] );
            $fallback['url']     = ! empty( $resolved['preview_url'] ) ? esc_url_raw( $resolved['preview_url'] ) : '';

            if ( '' !== $fallback['url'] ) {
                $preview_id = ! empty( $resolved['preview_id'] ) ? absint( $resolved['preview_id'] ) : 0;
                $pdf_id     = ! empty( $resolved['attachment_id'] ) ? absint( $resolved['attachment_id'] ) : 0;

                if ( $preview_id && 0 === strpos( (string) get_post_mime_type( $preview_id ), 'image/' ) ) {
                    $fallback['id'] = $preview_id;
                } elseif ( $pdf_id && 'application/pdf' === get_post_mime_type( $pdf_id ) ) {
                    $fallback['id'] = $pdf_id;
                }
            }

            if ( '' === $fallback['url'] ) {
                $pdf_title = ! empty( $resolved['attachment_id'] ) ? get_the_title( $resolved['attachment_id'] ) : '';
                $fallback['url'] = $this->get_document_cover_url( $fallback['pdf_url'], $pdf_title );
            }

            $this->pdf_featured_image_cache[ $cache_key ] = $fallback;

            return $fallback;
        }

        private function get_pdf_featured_image_fallback_id( $post_id ) {
            $fallback = $this->get_pdf_featured_image_fallback_data( $post_id, 'medium_large' );

            return ! empty( $fallback['id'] ) ? absint( $fallback['id'] ) : 0;
        }

        private function should_use_first_pdf_preview_as_featured_image( $post ) {
            $settings = $this->get_settings();
            if ( empty( $settings['use_first_pdf_preview_as_featured_image'] ) ) {
                return false;
            }

            if ( $this->should_skip_frontend_list_filters() ) {
                return false;
            }

            $post = get_post( $post );
            if ( ! $post || 'post' !== $post->post_type ) {
                return false;
            }

            // Keep this limited to listing contexts. Single posts render their attachments normally.
            return is_category() || is_home() || $this->is_news_page_request();
        }

        private function category_visual_has_rendered( $post_id ) {
            return ! empty( $this->category_visual_rendered[ absint( $post_id ) ] );
        }

        private function mark_category_visual_rendered( $post_id ) {
            $post_id = absint( $post_id );
            if ( $post_id ) {
                $this->category_visual_rendered[ $post_id ] = true;
            }
        }

        private function has_real_featured_image_id( $post_id ) {
            return absint( get_post_meta( absint( $post_id ), '_thumbnail_id', true ) );
        }

        public function use_first_pdf_preview_as_featured_image( $thumbnail_id, $post ) {
            if ( $thumbnail_id || ! $this->should_use_first_pdf_preview_as_featured_image( $post ) ) {
                return $thumbnail_id;
            }

            $post        = get_post( $post );
            $fallback_id = $this->get_pdf_featured_image_fallback_id( $post->ID );

            return $fallback_id ? $fallback_id : $thumbnail_id;
        }

        public function has_first_pdf_preview_as_featured_image( $has_thumbnail, $post, $thumbnail_id ) {
            if ( $has_thumbnail || $thumbnail_id || ! $this->should_use_first_pdf_preview_as_featured_image( $post ) ) {
                return $has_thumbnail;
            }

            $post = get_post( $post );
            if ( ! $post ) {
                return $has_thumbnail;
            }

            $fallback = $this->get_pdf_featured_image_fallback_data( $post->ID, 'medium_large' );

            return ( ! empty( $fallback['url'] ) || ! empty( $fallback['pdf_url'] ) ) ? true : $has_thumbnail;
        }

        public function render_first_pdf_preview_as_featured_image( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
            $post_id = absint( $post_id );

            if ( ! $this->should_use_first_pdf_preview_as_featured_image( $post_id ) ) {
                return $html;
            }

            if ( '' !== $html ) {
                $this->mark_category_visual_rendered( $post_id );
                return $html;
            }

            if ( $this->category_visual_has_rendered( $post_id ) ) {
                return '';
            }

            $fallback = $this->get_pdf_featured_image_fallback_data( $post_id, $size );
            if ( empty( $fallback['url'] ) && empty( $fallback['pdf_url'] ) ) {
                return $html;
            }

            if ( ! empty( $fallback['id'] ) ) {
                $image_html = wp_get_attachment_image( absint( $fallback['id'] ), $size, false, $attr );
                if ( $image_html ) {
                    $this->mark_category_visual_rendered( $post_id );
                    return $image_html;
                }
            }

            if ( empty( $fallback['url'] ) && ! empty( $fallback['pdf_url'] ) ) {
                $canvas_html = $this->render_pdf_canvas_preview( $fallback['pdf_url'], get_the_title( $post_id ) );
                if ( '' !== $canvas_html ) {
                    $this->mark_category_visual_rendered( $post_id );
                    return $canvas_html;
                }
            }

            $attributes = wp_parse_args( $attr, array() );
            $size_class = is_string( $size ) ? sanitize_html_class( $size ) : 'medium_large';
            $classes    = array( 'attachment-' . $size_class, 'size-' . $size_class, 'wp-post-image', 'pti-featured-image-or-pdf-preview' );

            if ( ! empty( $attributes['class'] ) ) {
                $classes[] = $attributes['class'];
            }

            $attributes['class']    = implode( ' ', array_filter( $classes ) );
            $attributes['src']      = esc_url_raw( $fallback['url'] );
            $attributes['alt']      = isset( $attributes['alt'] ) ? $attributes['alt'] : get_the_title( $post_id );
            $attributes['decoding'] = isset( $attributes['decoding'] ) ? $attributes['decoding'] : 'async';

            $html_attributes = '';
            foreach ( $attributes as $name => $value ) {
                if ( false === $value || null === $value || '' === $value ) {
                    continue;
                }

                $html_attributes .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
            }

            $this->mark_category_visual_rendered( $post_id );
            return '<img' . $html_attributes . '>';
        }

        public function get_current_render_post_id() {
            $candidate_id = absint( get_the_ID() );
            if ( $candidate_id && 'post' === get_post_type( $candidate_id ) ) {
                return $candidate_id;
            }

            global $post;

            if ( $post instanceof \WP_Post && 'post' === $post->post_type ) {
                return absint( $post->ID );
            }

            return 0;
        }

        public function get_elementor_featured_image_fallback_value( $post_id = 0 ) {
            $post_id = absint( $post_id ? $post_id : $this->get_current_render_post_id() );
            if ( ! $post_id ) {
                return array();
            }

            $real_thumbnail_id = absint( get_post_meta( $post_id, '_thumbnail_id', true ) );
            if ( $real_thumbnail_id ) {
                $real_image = wp_get_attachment_image_src( $real_thumbnail_id, 'full' );
                if ( is_array( $real_image ) && ! empty( $real_image[0] ) ) {
                    return array(
                        'id'  => $real_thumbnail_id,
                        'url' => esc_url_raw( $real_image[0] ),
                    );
                }

                return array();
            }

            if ( ! $this->should_use_first_pdf_preview_as_featured_image( $post_id ) ) {
                return array();
            }

            $fallback = $this->get_pdf_featured_image_fallback_data( $post_id, 'full' );
            if ( empty( $fallback['url'] ) ) {
                return array();
            }

            return array(
                'id'  => ! empty( $fallback['id'] ) ? absint( $fallback['id'] ) : 0,
                'url' => esc_url_raw( $fallback['url'] ),
            );
        }

        public function get_elementor_featured_image_widget_value( $post_id = 0 ) {
            $post_id = absint( $post_id ? $post_id : $this->get_current_render_post_id() );
            if ( ! $post_id ) {
                return array();
            }

            $real_thumbnail_id = absint( get_post_meta( $post_id, '_thumbnail_id', true ) );
            if ( $real_thumbnail_id ) {
                $real_image = wp_get_attachment_image_src( $real_thumbnail_id, 'full' );
                if ( is_array( $real_image ) && ! empty( $real_image[0] ) ) {
                    return array(
                        'id'  => $real_thumbnail_id,
                        'url' => esc_url_raw( $real_image[0] ),
                    );
                }

                return array();
            }

            $settings = $this->get_settings();
            if ( empty( $settings['use_first_pdf_preview_as_featured_image'] ) ) {
                return array();
            }

            $fallback = $this->get_pdf_featured_image_fallback_data( $post_id, 'full' );
            if ( empty( $fallback['url'] ) && empty( $fallback['pdf_url'] ) ) {
                return array();
            }

            return array(
                'id'      => ! empty( $fallback['id'] ) ? absint( $fallback['id'] ) : 0,
                'url'     => ! empty( $fallback['url'] ) ? esc_url_raw( $fallback['url'] ) : '',
                'pdf_url' => ! empty( $fallback['pdf_url'] ) ? esc_url_raw( $fallback['pdf_url'] ) : '',
            );
        }

        public function register_elementor_featured_image_fallback_tag( $dynamic_tags ) {
            $settings = $this->get_settings();
            if ( empty( $settings['use_first_pdf_preview_as_featured_image'] ) ) {
                return;
            }

            if (
                ! class_exists( '\\Elementor\\Core\\DynamicTags\\Data_Tag' )
                || ! is_object( $dynamic_tags )
                || ! method_exists( $dynamic_tags, 'register' )
            ) {
                return;
            }

            $dynamic_tags->register(
                new class() extends \Elementor\Core\DynamicTags\Data_Tag {
                    public function get_name() {
                        return 'pdf-thumbnail-featured-image-or-first-pdf';
                    }

                    public function get_title() {
                        return __( 'Featured Image or First PDF Preview', 'pdf-thumbnail-inserter' );
                    }

                    public function get_group() {
                        return 'post';
                    }

                    public function get_categories() {
                        return array( 'image' );
                    }

                    public function get_value( array $options = array() ) {
                        return \PDF_Thumbnail_Inserter::instance()->get_elementor_featured_image_fallback_value();
                    }
                }
            );
        }

        public function register_elementor_featured_image_fallback_widget( $widgets_manager ) {
            $settings = $this->get_settings();
            if ( empty( $settings['use_first_pdf_preview_as_featured_image'] ) ) {
                return;
            }

            if (
                ! class_exists( '\\ElementorPro\\Modules\\ThemeBuilder\\Widgets\\Post_Featured_Image' )
                || ! class_exists( '\\Elementor\\Plugin' )
                || ! is_object( $widgets_manager )
                || ! method_exists( $widgets_manager, 'register' )
            ) {
                return;
            }

            $widgets_manager->register(
                new class() extends \ElementorPro\Modules\ThemeBuilder\Widgets\Post_Featured_Image {
                    public function get_name() {
                        return 'pdf-thumbnail-featured-image-or-first-pdf-widget';
                    }

                    public function get_title() {
                        return __( 'Featured Image or First PDF Preview', 'pdf-thumbnail-inserter' );
                    }

                    public function get_icon() {
                        return 'eicon-featured-image';
                    }

                    public function get_style_depends(): array {
                        return array_values( array_unique( array_merge( parent::get_style_depends(), array( 'pdf-thumbnail-frontend' ) ) ) );
                    }

                    protected function is_dynamic_content(): bool {
                        return true;
                    }

                    protected function render() {
                        $post_id = \PDF_Thumbnail_Inserter::instance()->get_current_render_post_id();
                        if ( ! $post_id || \PDF_Thumbnail_Inserter::instance()->should_suppress_elementor_post_content( $post_id ) ) {
                            return;
                        }

                        wp_enqueue_style( 'pdf-thumbnail-frontend' );

                        $image = \PDF_Thumbnail_Inserter::instance()->get_elementor_featured_image_widget_value( $post_id );
                        if ( empty( $image['url'] ) && empty( $image['pdf_url'] ) ) {
                            return;
                        }

                        $settings   = parent::get_settings_for_display();
                        $image_size = ! empty( $settings['image_size'] ) ? $settings['image_size'] : 'full';
                        $media_html = '';

                        if ( ! empty( $image['url'] ) ) {
                            if ( ! empty( $image['id'] ) ) {
                                $media_html = wp_get_attachment_image(
                                    absint( $image['id'] ),
                                    $image_size,
                                    false,
                                    array(
                                        'class' => 'pti-featured-image-or-pdf-preview wp-post-image',
                                        'alt'   => get_the_title( $post_id ),
                                    )
                                );
                            }

                            if ( '' === $media_html ) {
                                $media_html = sprintf(
                                    '<img class="pti-featured-image-or-pdf-preview wp-post-image" src="%1$s" alt="%2$s" loading="lazy" decoding="async">',
                                    esc_url( $image['url'] ),
                                    esc_attr( get_the_title( $post_id ) )
                                );
                            }
                        } elseif ( ! empty( $image['pdf_url'] ) ) {
                            $media_html = \PDF_Thumbnail_Inserter::instance()->render_pdf_canvas_preview_for_post( $image['pdf_url'], get_the_title( $post_id ) );
                        }

                        if ( '' === $media_html ) {
                            return;
                        }

                        $link = array();
                        if ( ! empty( $settings['link_to'] ) && 'file' === $settings['link_to'] ) {
                            $link = array( 'url' => ! empty( $image['pdf_url'] ) ? $image['pdf_url'] : $image['url'] );
                        } elseif ( ! empty( $settings['link_to'] ) && 'custom' === $settings['link_to'] && ! empty( $settings['link']['url'] ) ) {
                            $link = $settings['link'];
                        }

                        if ( ! empty( $link['url'] ) ) {
                            $this->add_link_attributes( 'link', $link );
                            echo '<a class="pti-featured-media-link" ';
                            $this->print_render_attribute_string( 'link' );
                            echo '>' . $media_html . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            return;
                        }

                        echo $media_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    }
                }
            );
        }

        private function is_news_or_category_list_context() {
            $settings = $this->get_settings();
            if ( empty( $settings['use_first_pdf_preview_as_featured_image'] ) ) {
                return false;
            }

            if ( $this->should_skip_frontend_list_filters() ) {
                return false;
            }

            if ( is_category() || is_home() || $this->is_news_page_request() ) {
                return true;
            }

            return false;
        }

        private function is_news_page_request() {
            $settings = $this->get_settings();
            $pages = array_filter( array_map( 'trim', explode( ',', $settings['listing_pages'] ) ) );
            if ( empty( $pages ) ) {
                return false;
            }
            if ( is_page( $pages ) ) {
                return true;
            }

            $request_path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
            $request_path = trim( $request_path, '/' );

            foreach ( $pages as $page ) {
                if ( ! ctype_digit( $page ) && ( $page === $request_path || 0 === strpos( $request_path, $page . '/page/' ) ) ) {
                    return true;
                }
            }
            return false;
        }

        private function is_attachment_list_render_context() {
            return $this->is_news_or_category_list_context() && (bool) $this->get_current_render_post_id();
        }

        public function should_suppress_elementor_post_content( $post_id ) {
            if ( ! $this->is_attachment_list_render_context() || ! class_exists( '\\Elementor\\Plugin' ) ) {
                return false;
            }

            $document = \Elementor\Plugin::instance()->documents->get_current();
            // Suppress only the post's own attachment widget when its content is used
            // as an excerpt. A Loop Grid template has a different document ID.
            return $document && absint( $document->get_main_id() ) === absint( $post_id );
        }

        private function should_skip_frontend_list_filters() {
            if ( is_admin() || wp_doing_ajax() ) {
                return true;
            }

            if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
                return true;
            }

            if ( defined( 'WP_CLI' ) && WP_CLI ) {
                return true;
            }

            if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
                return true;
            }

            if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance ) ) {
                $elementor = \Elementor\Plugin::$instance;

                if (
                    isset( $elementor->editor )
                    && $elementor->editor->is_edit_mode()
                ) {
                    return true;
                }

                if (
                    isset( $elementor->preview )
                    && $elementor->preview->is_preview_mode()
                ) {
                    return true;
                }
            }

            return false;
        }

        private function strip_attachment_sources_from_content( $content ) {
            $content = (string) $content;
            if ( '' === $content ) {
                return '';
            }

            $patterns = array(
                '~<!--\s+wp:file\b.*?-->.*?<!--\s+/wp:file\s+-->~is',
                '~<!--\s+wp:pdf-thumbnail-inserter/pdf-thumbnail\b.*?/-->~is',
                '~<!--\s+wp:pdf-thumbnail-inserter/pdf-thumbnail\b.*?-->.*?<!--\s+/wp:pdf-thumbnail-inserter/pdf-thumbnail\s+-->~is',
                '/\[(?:pdf_thumbnail_inserter|pdf_thumbnail)\b[^\]]*\]/i',
                '~<div\b(?=[^>]*\bclass=(?:"[^"]*\bwp-block-file\b[^"]*"|\'[^\']*\bwp-block-file\b[^\']*\'))[^>]*>.*?</div>~is',
                '~<object\b[^>]*(?:application/pdf|\.pdf(?:[?"\']))[^>]*>.*?</object>~is',
                '~<iframe\b[^>]*(?:\.pdf(?:[?"\'])|pdfjs-viewer)[^>]*>.*?</iframe>~is',
                '~<a\b[^>]*\bhref=(?:"[^\"]+\.(?:pdf|docx?)(?:\?[^\"]*)?"|\'[^\']+\.(?:pdf|docx?)(?:\?[^\']*)?\')[^>]*>.*?</a>~is',
            );

            $content = preg_replace( $patterns, ' ', $content );
            // Naked URLs belong to text nodes. Matching across HTML attributes can
            // truncate cover-image src URLs whose query contains a PDF filename.
            $parts = wp_html_split( (string) $content );
            foreach ( $parts as &$part ) {
                if ( '' !== $part && '<' !== $part[0] ) {
                    $part = preg_replace( '~(?:(?:https?:)?//|/|wp-content/)[^\s"\'<>]+?\.(?:pdf|docx?)(?:\?[^\s"\'<>]*)?~iu', ' ', $part );
                }
            }
            unset( $part );
            $content = implode( '', $parts );
            $content = preg_replace( '~<!--\s*/?wp:[^>]+-->~', ' ', (string) $content );

            return (string) $content;
        }

        private function strip_rendered_attachment_markup( $content ) {
            $content = $this->strip_attachment_sources_from_content( $content );
            $content = preg_replace( '~<p>\s*</p>~i', '', $content );

            return trim( (string) $content );
        }

        public function begin_excerpt_context( $excerpt, $post ) {
            $this->excerpt_posts[] = get_post( $post );
            return $excerpt;
        }

        public function end_excerpt_context( $excerpt ) {
            array_pop( $this->excerpt_posts );
            return $excerpt;
        }

        /** Clean source before core trims once, preserving its filters and content pipeline. */
        public function filter_excerpt_attachment_source( $content ) {
            if ( ! doing_filter( 'get_the_excerpt' ) || ! $this->is_news_or_category_list_context() ) return $content;
            $post = end( $this->excerpt_posts );
            if ( ! $post || 'post' !== $post->post_type || '' !== trim( (string) $post->post_excerpt ) ) return $content;
            return $this->strip_attachment_sources_from_content( $content );
        }

        /** Legacy public callback retained for callers explicitly invoking the old adapter. */
        public function filter_list_excerpt_attachment_text( $excerpt, $post ) {
            if ( ! $this->is_news_or_category_list_context() ) {
                return $excerpt;
            }

            $post = get_post( $post );
            if ( ! $post || 'post' !== $post->post_type ) {
                return $excerpt;
            }

            // Respect a deliberately written manual excerpt. Only rebuild automatic excerpts,
            // whose file-link labels otherwise become strings such as "PDF title Download".
            if ( '' !== trim( (string) $post->post_excerpt ) ) {
                return $excerpt;
            }

            $clean_content = $this->strip_attachment_sources_from_content( $post->post_content );
            $clean_content = strip_shortcodes( $clean_content );
            $clean_content = wp_strip_all_tags( $clean_content, true );
            $clean_content = preg_replace( '/\s+/u', ' ', (string) $clean_content );
            $clean_content = trim( (string) $clean_content );

            if ( '' === $clean_content ) {
                return '';
            }

            $excerpt_post = clone $post;
            $excerpt_post->post_content = $clean_content;
            return wp_trim_excerpt( '', $excerpt_post );
        }

        public function filter_category_archive_excerpt_output( $excerpt ) {
            if ( ! $this->is_attachment_list_render_context() ) {
                return $excerpt;
            }

            $post_id = $this->get_current_render_post_id();
            if ( ! $post_id ) {
                return $excerpt;
            }

            return $this->strip_rendered_attachment_markup( $excerpt );
        }

        public function filter_category_archive_content( $content ) {
            if ( ! $this->is_attachment_list_render_context() ) {
                return $content;
            }

            $post_id = $this->get_current_render_post_id();
            if ( ! $post_id ) {
                return $content;
            }

            return $this->strip_rendered_attachment_markup( $content );
        }

        public function start_listing_output_buffer() {
            $settings = $this->get_settings();
            if ( empty( $settings['use_first_pdf_preview_as_featured_image'] ) || ! $this->is_news_or_category_list_context() ) {
                return;
            }

            ob_start( array( $this, 'filter_listing_output' ) );
        }

        public function filter_listing_output( $html ) {
            $html = (string) $html;
            if ( '' === $html ) {
                return $html;
            }

            $filtered_html = preg_replace_callback(
                '~(<div\s+class=(["\'])(?=[^"\']*\bblog-post\b)(?=[^"\']*\bformat-no_featured\b)([^"\']*)\2[^>]*>\s*<div\s+class=(["\'])blog-post_wrapper\4>\s*)(<div\s+class=(["\'])blog-post_content\6>(?:(?!<div\s+class=(["\'])(?=[^"\']*\bblog-post\b)).)*?<[a-z][a-z0-9]*\b[^>]*\bclass=(["\'])blog-post_title\8[^>]*>\s*<a\s+[^>]*href=(["\'])(.*?)\9)~is',
                function ( $matches ) {
                    $permalink = esc_url_raw( html_entity_decode( $matches[10], ENT_QUOTES, get_bloginfo( 'charset' ) ) );
                    $post_id   = $permalink ? absint( url_to_postid( $permalink ) ) : 0;
                    if ( ! $post_id || 'post' !== get_post_type( $post_id ) || $this->has_real_featured_image_id( $post_id ) ) {
                        return $matches[0];
                    }

                    $fallback = $this->get_pdf_featured_image_fallback_data( $post_id, 'large' );
                    if ( empty( $fallback['url'] ) && empty( $fallback['pdf_url'] ) ) {
                        return $matches[0];
                    }

                    $wrapper_start = str_replace( 'format-no_featured', 'format-has-post-thumbnail has-post-thumbnail', $matches[1] );
                    $media_html    = $this->render_listing_pdf_preview_media(
                        $post_id,
                        ! empty( $fallback['url'] ) ? $fallback['url'] : '',
                        ! empty( $fallback['pdf_url'] ) ? $fallback['pdf_url'] : ''
                    );

                    return $wrapper_start . $media_html . $matches[5];
                },
                $html
            );

            return is_string( $filtered_html ) ? $filtered_html : $html;
        }

        private function render_listing_pdf_preview_media( $post_id, $preview_url, $pdf_url = '' ) {
            $post_id     = absint( $post_id );
            $preview_url = esc_url_raw( $preview_url );
            $pdf_url     = esc_url_raw( $pdf_url );
            if ( ! $post_id || ( '' === $preview_url && '' === $pdf_url ) ) {
                return '';
            }

            $title     = get_the_title( $post_id );
            $permalink = get_permalink( $post_id );
            $image     = '';

            if ( '' !== $preview_url ) {
                $image = sprintf(
                    '<img class="pti-featured-image-or-pdf-preview wp-post-image" src="%1$s" alt="%2$s" loading="lazy" decoding="async">',
                    esc_url( $preview_url ),
                    esc_attr( $title )
                );
            } elseif ( '' !== $pdf_url ) {
                $image = $this->render_pdf_canvas_preview( $pdf_url, $title );
            }

            if ( '' === $image ) {
                return '';
            }

            if ( $permalink ) {
                $image = sprintf(
                    '<a class="pti-featured-media-link" href="%1$s">%2$s</a>',
                    esc_url( $permalink ),
                    $image
                );
            }

            return '<div class="blog-post_media pti-listing-pdf-preview">' . $image . '</div>';
        }

        private function render_gutenberg_file_card( $file_url, $title, $thumbnail_url ) {
            $settings = $this->get_settings();
            $atts = $this->get_shortcode_atts(
                array(
                    'thumbnail'   => $thumbnail_url,
                    'title'       => $title,
                    'url'         => $file_url,
                    'link_to'     => 'custom',
                    'show_title'  => ! empty( $settings['default_show_title'] ) ? 'yes' : 'no',
                    'show_button' => ! empty( $settings['default_show_button'] ) ? 'yes' : 'no',
                    'button_text' => $settings['button_text'],
                    'new_tab'     => ! empty( $settings['default_new_tab'] ) ? 'yes' : 'no',
                )
            );

            $show_title  = $this->normalize_bool_attr( $atts['show_title'], true );
            $show_button = $this->normalize_bool_attr( $atts['show_button'], true );
            $new_tab     = $this->normalize_bool_attr( $atts['new_tab'], true );
            $target_attr = $new_tab ? ' target="_blank"' : '';
            $rel_values  = $new_tab ? array( 'noopener', 'noreferrer' ) : array();
            $rel_attr    = ! empty( $rel_values ) ? ' rel="' . esc_attr( implode( ' ', array_unique( $rel_values ) ) ) . '"' : '';

            return $this->render_legacy_url_card(
                $atts,
                array(
                    'show_title'  => $show_title,
                    'show_button' => $show_button,
                    'button_text' => $atts['button_text'],
                    'rel_attr'    => $rel_attr,
                    'target_attr' => $target_attr,
                )
            );
        }

        private function render_gutenberg_pdf_block_as_card( $block_content, $block, $file_data ) {
            $attachment_id = ! empty( $file_data['attachment_id'] ) ? absint( $file_data['attachment_id'] ) : 0;
            $file_url      = $file_data['file_url'];
            $title         = $this->get_gutenberg_file_block_title( $block_content, $attachment_id, $file_url, __( 'PDF File', 'pdf-thumbnail-inserter' ) );
            $thumbnail_url = PDF_THUMBNAIL_INSERTER_URL . 'assets/images/pdf-placeholder.png';

            if ( $attachment_id && 'application/pdf' === get_post_mime_type( $attachment_id ) ) {
                $preview_data  = $this->get_pdf_preview_data( $attachment_id, 'medium' );
                $thumbnail_url = ! empty( $preview_data['thumbnail_url'] ) ? esc_url_raw( $preview_data['thumbnail_url'] ) : $thumbnail_url;
            }

            return $this->render_gutenberg_file_card( $file_url, $title, $thumbnail_url );
        }

        private function render_gutenberg_doc_block_as_card( $block_content, $block, $file_data ) {
            $attachment_id = ! empty( $file_data['attachment_id'] ) ? absint( $file_data['attachment_id'] ) : 0;
            $file_url      = $file_data['file_url'];
            $title         = $this->get_gutenberg_file_block_title( $block_content, $attachment_id, $file_url, __( 'Document File', 'pdf-thumbnail-inserter' ) );

            return $this->render_gutenberg_file_card( $file_url, $title, $this->get_doc_placeholder_url() );
        }

        public function replace_pdf_file_block_preview( $block_content, $block ) {
            if ( $this->should_skip_frontend_list_filters() ) {
                return $block_content;
            }

            $settings   = $this->get_settings();
            $attributes = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
            $file_data  = $this->get_gutenberg_file_block_data( $block, $block_content );

            if (
                $this->is_attachment_list_render_context()
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
                return $this->render_gutenberg_pdf_block_as_card( $block_content, $block, $file_data );
            }

            if ( ! empty( $settings['convert_gutenberg_doc_links_to_cards'] ) && ! empty( $file_data['is_doc'] ) ) {
                return $this->render_gutenberg_doc_block_as_card( $block_content, $block, $file_data );
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
                $title = $this->get_gutenberg_file_block_title(
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

            $viewer_url = $this->get_pdfjs_viewer_url( $file_data['file_url'] );
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
            $html = $this->render_shortcode( $attributes );
            if ( '' === $html ) {
                return '';
            }

            return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
        }

        public function render_shortcode( $atts ) {
            $list_settings = $this->get_settings();
            if ( ! empty( $list_settings['use_first_pdf_preview_as_featured_image'] ) && $this->is_attachment_list_render_context() ) {
                return '';
            }

            wp_enqueue_style( 'pdf-thumbnail-frontend', PDF_THUMBNAIL_INSERTER_URL . 'assets/css/pdf-thumbnail.css', array(), self::VERSION );

            $atts = $this->get_shortcode_atts( $atts );
            $attachment_ids = $this->parse_attachment_ids( $atts );
            $size = sanitize_key( $atts['size'] );
            $show_title = $this->normalize_bool_attr( $atts['show_title'], true );
            $show_button = $this->normalize_bool_attr( $atts['show_button'], true );
            $new_tab = $this->normalize_bool_attr( $atts['new_tab'], true );
            $custom_classes = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $atts['class'] ) ) );
            $rel = sanitize_text_field( $atts['rel'] );

            if ( empty( $attachment_ids ) && ( ! empty( $atts['id'] ) || ! empty( $atts['ids'] ) || ! empty( $atts['title'] ) ) ) {
                $resolved_file_data = $this->get_first_pdf_from_reference_atts( $atts );

                if ( ! empty( $resolved_file_data['attachment_id'] ) ) {
                    $attachment_ids = array( absint( $resolved_file_data['attachment_id'] ) );
                } elseif ( ! empty( $resolved_file_data['file_url'] ) ) {
                    $atts['url'] = esc_url_raw( $resolved_file_data['file_url'] );

                    if ( empty( $atts['thumbnail'] ) ) {
                        $resolved_preview_url = $this->get_pdf_preview_url_from_file_url( $atts['url'], false );
                        if ( '' !== $resolved_preview_url ) {
                            $atts['thumbnail'] = $resolved_preview_url;
                        }
                    }
                }
            }

            $legacy_url_mode = empty( $attachment_ids ) && '' !== esc_url_raw( $atts['url'] );

            if ( empty( $attachment_ids ) && ! $legacy_url_mode ) {
                return '';
            }

            $target_attr = $new_tab ? ' target="_blank"' : '';
            $rel_values = array_filter( array_map( 'trim', explode( ' ', $rel ) ) );

            if ( $new_tab ) {
                $rel_values[] = 'noopener';
                $rel_values[] = 'noreferrer';
            }

            $rel_attr = $rel_values ? ' rel="' . esc_attr( implode( ' ', array_unique( $rel_values ) ) ) . '"' : '';
            $wrapper_style = $this->get_grid_style( $atts );
            $classes = trim( 'pti-grid-wrap ' . implode( ' ', $custom_classes ) );
            $button_text = sanitize_text_field( $atts['button_text'] );

            if ( '' === $button_text ) {
                $button_text = $this->get_settings()['button_text'];
            }

            if ( $legacy_url_mode ) {
                return $this->render_legacy_url_card(
                    $atts,
                    array(
                        'show_title'  => $show_title,
                        'show_button' => $show_button,
                        'button_text' => $button_text,
                        'rel_attr'    => $rel_attr,
                        'target_attr' => $target_attr,
                    )
                );
            }

            $allow_custom_overrides = 1 === count( $attachment_ids );

            ob_start();
            ?>
            <div class="<?php echo esc_attr( $classes ); ?>" style="<?php echo esc_attr( $wrapper_style ); ?>">
                <div class="pti-grid">
                    <?php foreach ( $attachment_ids as $attachment_id ) : ?>
                        <?php
                        $card_html = $this->render_pdf_card(
                            $attachment_id,
                            $atts,
                            array(
                                'size'                   => $size ? $size : 'medium',
                                'show_title'             => $show_title,
                                'show_button'            => $show_button,
                                'new_tab'                => $new_tab,
                                'button_text'            => $button_text,
                                'rel_attr'               => $rel_attr,
                                'target_attr'            => $target_attr,
                                'allow_custom_overrides' => $allow_custom_overrides,
                            )
                        );
                        // All variable output is escaped for its context inside render_pdf_card().
                        echo $card_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php

            return (string) ob_get_clean();
        }
    }

    PDF_Thumbnail_Inserter::instance();
}
