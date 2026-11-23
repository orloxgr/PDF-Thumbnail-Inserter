<?php
/** Backward-compatible public API, dependency wiring and WordPress hooks. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-runtime-state.php';
require_once __DIR__ . '/class-translations.php';
require_once __DIR__ . '/class-viewer.php';
require_once __DIR__ . '/class-featured-images.php';
require_once __DIR__ . '/class-admin.php';
require_once __DIR__ . '/class-assets.php';
require_once __DIR__ . '/class-blocks.php';
require_once __DIR__ . '/class-settings.php';
require_once __DIR__ . '/class-media.php';
require_once __DIR__ . '/class-file-resolver.php';
require_once __DIR__ . '/class-preview-storage.php';
require_once __DIR__ . '/class-maintenance.php';
require_once __DIR__ . '/class-preview-generation.php';
require_once __DIR__ . '/class-previews.php';
require_once __DIR__ . '/class-cards.php';
require_once __DIR__ . '/class-discovery.php';
require_once __DIR__ . '/class-reference-lookup.php';
require_once __DIR__ . '/class-elementor.php';
require_once __DIR__ . '/class-listing-filters.php';

final class PDF_Thumbnail_Inserter {
        const VERSION = '2.01.02';
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

    /** @var PDF_Thumbnail_Inserter_Translations */
    private $translations;

    /** @var PDF_Thumbnail_Inserter_Viewer */
    private $viewer;

    /** @var PDF_Thumbnail_Inserter_FeaturedImages */
    private $featured_images;

    /** @var PDF_Thumbnail_Inserter_Admin */
    private $admin;

    /** @var PDF_Thumbnail_Inserter_Assets */
    private $assets;

    /** @var PDF_Thumbnail_Inserter_Blocks */
    private $blocks;

    /** @var PDF_Thumbnail_Inserter_Settings */
    private $settings;

    /** @var PDF_Thumbnail_Inserter_Media */
    private $media;

    /** @var PDF_Thumbnail_Inserter_FileResolver */
    private $file_resolver;

    /** @var PDF_Thumbnail_Inserter_PreviewStorage */
    private $preview_storage;

    /** @var PDF_Thumbnail_Inserter_Maintenance */
    private $maintenance;

    /** @var PDF_Thumbnail_Inserter_PreviewGeneration */
    private $preview_generation;

    /** @var PDF_Thumbnail_Inserter_Previews */
    private $previews;

    /** @var PDF_Thumbnail_Inserter_Cards */
    private $cards;

    /** @var PDF_Thumbnail_Inserter_Discovery */
    private $discovery;

    /** @var PDF_Thumbnail_Inserter_ReferenceLookup */
    private $reference_lookup;

    /** @var PDF_Thumbnail_Inserter_Elementor */
    private $elementor;

    /** @var PDF_Thumbnail_Inserter_ListingFilters */
    private $listing_filters;

    /** @var PDF_Thumbnail_Inserter_RuntimeState */
    private $state;

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
            $this->state = new PDF_Thumbnail_Inserter_RuntimeState();
            $this->translations = new PDF_Thumbnail_Inserter_Translations();
            $this->viewer = new PDF_Thumbnail_Inserter_Viewer();
            $this->featured_images = new PDF_Thumbnail_Inserter_FeaturedImages();
            $this->admin = new PDF_Thumbnail_Inserter_Admin();
            $this->assets = new PDF_Thumbnail_Inserter_Assets();
            $this->blocks = new PDF_Thumbnail_Inserter_Blocks();
            $this->settings = new PDF_Thumbnail_Inserter_Settings();
            $this->media = new PDF_Thumbnail_Inserter_Media();
            $this->file_resolver = new PDF_Thumbnail_Inserter_FileResolver();
            $this->preview_storage = new PDF_Thumbnail_Inserter_PreviewStorage();
            $this->maintenance = new PDF_Thumbnail_Inserter_Maintenance();
            $this->preview_generation = new PDF_Thumbnail_Inserter_PreviewGeneration();
            $this->previews = new PDF_Thumbnail_Inserter_Previews();
            $this->cards = new PDF_Thumbnail_Inserter_Cards();
            $this->discovery = new PDF_Thumbnail_Inserter_Discovery();
            $this->reference_lookup = new PDF_Thumbnail_Inserter_ReferenceLookup();
            $this->elementor = new PDF_Thumbnail_Inserter_Elementor();
            $this->listing_filters = new PDF_Thumbnail_Inserter_ListingFilters();

            $this->viewer->set_dependencies( $this->discovery, $this->file_resolver, $this->settings, $this->state );
            $this->featured_images->set_dependencies( $this->discovery, $this->listing_filters, $this->settings, $this->state, $this->viewer );
            $this->admin->set_dependencies( $this, $this->maintenance, $this->settings, $this->viewer );
            $this->assets->set_dependencies( $this->listing_filters, $this->settings );
            $this->blocks->set_dependencies( $this, $this->cards, $this->discovery, $this->listing_filters, $this->settings, $this->viewer );
            $this->settings->set_dependencies( $this->cards );
            $this->media->set_dependencies( $this->admin, $this->file_resolver, $this->preview_generation, $this->preview_storage, $this->previews, $this->settings );
            $this->preview_storage->set_dependencies( $this->featured_images, $this->file_resolver, $this->previews, $this->state );
            $this->maintenance->set_dependencies( $this->admin, $this->preview_generation, $this->preview_storage, $this->previews );
            $this->preview_generation->set_dependencies( $this->admin, $this, $this->featured_images, $this->file_resolver, $this->preview_storage, $this->previews );
            $this->previews->set_dependencies( $this->file_resolver, $this->preview_generation );
            $this->cards->set_dependencies( $this->discovery, $this->file_resolver, $this->listing_filters, $this->previews, $this->reference_lookup, $this->settings, $this->viewer );
            $this->discovery->set_dependencies( $this->file_resolver, $this->preview_generation, $this->previews, $this->reference_lookup, $this->state );
            $this->reference_lookup->set_dependencies( $this->discovery, $this->file_resolver );
            $this->elementor->set_dependencies( $this->featured_images, $this->settings );
            $this->listing_filters->set_dependencies( $this, $this->featured_images, $this->settings, $this->state );

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
            add_filter( 'plugin_action_links_' . plugin_basename( PDF_THUMBNAIL_INSERTER_FILE ), array( $this, 'add_plugin_action_links' ) );
        }

        public function load_textdomain() {
            return $this->translations->load_textdomain(  );
        }

        public function register_preview_query_vars( $query_vars ) {
            return $this->viewer->register_preview_query_vars( $query_vars );
        }

        /** Route public GET resources through WordPress parsing, without changing the content query. */
        public function parse_public_preview_request( $query_vars ) {
            return $this->viewer->parse_public_preview_request( $query_vars );
        }

        public function add_plugin_action_links( $links ) {
            return $this->admin->add_plugin_action_links( $links );
        }

        public function add_module_type_to_preview_renderer( $tag, $handle, $src ) {
            return $this->assets->add_module_type_to_preview_renderer( $tag, $handle, $src );
        }

        public function add_module_attributes_to_preview_renderer( $attributes ) {
            return $this->assets->add_module_attributes_to_preview_renderer( $attributes );
        }

        public function add_settings_menu() {
            return $this->admin->add_settings_menu(  );
        }

        public function register_settings() {
            return $this->admin->register_settings(  );
        }

        public function enqueue_admin_assets( $hook ) {
            return $this->assets->enqueue_admin_assets( $hook );
        }

        public function enqueue_browser_upload_assets() {
            return $this->assets->enqueue_browser_upload_assets(  );
        }

        public function enqueue_block_editor_assets() {
            return $this->assets->enqueue_block_editor_assets(  );
        }

        public function enqueue_frontend_listing_assets() {
            return $this->assets->enqueue_frontend_listing_assets(  );
        }

        public function register_block() {
            return $this->blocks->register_block(  );
        }

        public function sanitize_settings( $input ) {
            return $this->settings->sanitize_settings( $input );
        }

        public function render_settings_page() {
            return $this->admin->render_settings_page(  );
        }

        public function render_media_button() {
            return $this->admin->render_media_button(  );
        }

        public function ajax_fetch_pdf_data() {
            return $this->media->ajax_fetch_pdf_data(  );
        }

        public function ajax_list_missing_previews() {
            return $this->media->ajax_list_missing_previews(  );
        }

        public function ajax_save_browser_preview() {
            return $this->media->ajax_save_browser_preview(  );
        }

        public function add_browser_preview_to_media_response( $response, $attachment, $metadata ) {
            return $this->media->add_browser_preview_to_media_response( $response, $attachment, $metadata );
        }

        public function add_attachment_fields( $form_fields, $post ) {
            return $this->media->add_attachment_fields( $form_fields, $post );
        }

        public function maybe_generate_pdf_preview_on_upload( $metadata, $attachment_id, $context ) {
            return $this->media->maybe_generate_pdf_preview_on_upload( $metadata, $attachment_id, $context );
        }

        public function cleanup_generated_preview( $attachment_id ) {
            return $this->preview_storage->cleanup_generated_preview( $attachment_id );
        }

        /** Keep core deletion and preview publication in the same per-PDF critical section. */
        public function protect_pdf_attachment_deletion( $delete, $post, $force_delete ) {
            return $this->preview_storage->protect_pdf_attachment_deletion( $delete, $post, $force_delete );
        }

        public function release_deleted_pdf_lock( $post_id ) {
            return $this->preview_storage->release_deleted_pdf_lock( $post_id );
        }

        public function release_pdf_deletion_locks( $post_id = 0 ) {
            return $this->preview_storage->release_pdf_deletion_locks( $post_id );
        }

        public function handle_regenerate_missing_previews() {
            return $this->maintenance->handle_regenerate_missing_previews(  );
        }

        public function invalidate_attachment_lookup_metadata( $meta_id, $post_id, $meta_key ) {
            return $this->file_resolver->invalidate_attachment_lookup_metadata( $meta_id, $post_id, $meta_key );
        }

        public function maybe_render_pdfjs_viewer() {
            return $this->viewer->maybe_render_pdfjs_viewer(  );
        }

        public function render_featured_pdf_frame( $pdf_url, $title = '', $height = 360 ) {
            return $this->viewer->render_featured_pdf_frame( $pdf_url, $title, $height );
        }

        public function render_pdf_canvas_preview_for_post( $pdf_url, $title = '' ) {
            return $this->viewer->render_pdf_canvas_preview_for_post( $pdf_url, $title );
        }

        /**
         * Resolve the first PDF, preserving the public API's legacy generation default.
         * Frontend renderers explicitly opt out; uploads and maintenance own generation.
         */
        public function resolve_first_pdf_for_post( int $post_id, $size = 'full', bool $generate_if_missing = true ) {
            return $this->discovery->resolve_first_pdf_for_post( $post_id, $size, $generate_if_missing );
        }

        public function maybe_render_document_cover() {
            return $this->viewer->maybe_render_document_cover(  );
        }

        public function use_first_pdf_preview_as_featured_image( $thumbnail_id, $post ) {
            return $this->featured_images->use_first_pdf_preview_as_featured_image( $thumbnail_id, $post );
        }

        public function has_first_pdf_preview_as_featured_image( $has_thumbnail, $post, $thumbnail_id ) {
            return $this->featured_images->has_first_pdf_preview_as_featured_image( $has_thumbnail, $post, $thumbnail_id );
        }

        public function render_first_pdf_preview_as_featured_image( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
            return $this->featured_images->render_first_pdf_preview_as_featured_image( $html, $post_id, $post_thumbnail_id, $size, $attr );
        }

        public function get_current_render_post_id() {
            return $this->featured_images->get_current_render_post_id(  );
        }

        public function get_elementor_featured_image_fallback_value( $post_id = 0 ) {
            return $this->elementor->get_elementor_featured_image_fallback_value( $post_id );
        }

        public function get_elementor_featured_image_widget_value( $post_id = 0 ) {
            return $this->elementor->get_elementor_featured_image_widget_value( $post_id );
        }

        public function register_elementor_featured_image_fallback_tag( $dynamic_tags ) {
            return $this->elementor->register_elementor_featured_image_fallback_tag( $dynamic_tags );
        }

        public function register_elementor_featured_image_fallback_widget( $widgets_manager ) {
            return $this->elementor->register_elementor_featured_image_fallback_widget( $widgets_manager );
        }

        public function should_suppress_elementor_post_content( $post_id ) {
            return $this->listing_filters->should_suppress_elementor_post_content( $post_id );
        }

        public function begin_excerpt_context( $excerpt, $post ) {
            return $this->listing_filters->begin_excerpt_context( $excerpt, $post );
        }

        public function end_excerpt_context( $excerpt ) {
            return $this->listing_filters->end_excerpt_context( $excerpt );
        }

        /** Clean source before core trims once, preserving its filters and content pipeline. */
        public function filter_excerpt_attachment_source( $content ) {
            return $this->listing_filters->filter_excerpt_attachment_source( $content );
        }

        /** Legacy public callback retained for callers explicitly invoking the old adapter. */
        public function filter_list_excerpt_attachment_text( $excerpt, $post ) {
            return $this->listing_filters->filter_list_excerpt_attachment_text( $excerpt, $post );
        }

        public function filter_category_archive_excerpt_output( $excerpt ) {
            return $this->listing_filters->filter_category_archive_excerpt_output( $excerpt );
        }

        public function filter_category_archive_content( $content ) {
            return $this->listing_filters->filter_category_archive_content( $content );
        }

        public function start_listing_output_buffer() {
            return $this->listing_filters->start_listing_output_buffer(  );
        }

        public function filter_listing_output( $html ) {
            return $this->listing_filters->filter_listing_output( $html );
        }

        public function replace_pdf_file_block_preview( $block_content, $block ) {
            return $this->blocks->replace_pdf_file_block_preview( $block_content, $block );
        }

        public function render_block( $attributes ) {
            return $this->blocks->render_block( $attributes );
        }

        public function render_shortcode( $atts ) {
            return $this->cards->render_shortcode( $atts );
        }
}
