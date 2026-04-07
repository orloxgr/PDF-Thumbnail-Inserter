<?php
/**
 * Plugin Name:       PDF Thumbnail Inserter
 * Plugin URI:        https://github.com/orloxgr/PDF-Thumbnail-Inserter
 * Description:       Generates and reuses PDF thumbnails, provides a shortcode and Gutenberg block, and adds editor helpers for inserting PDF thumbnail cards.
 * Version:           1.10.0
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
        const VERSION = '1.10.0';
        const OPTION_NAME = 'pdf_thumbnail_defaults';
        const NONCE_ACTION = 'pdf_thumbnail_nonce';
        const META_PREVIEW_ID = '_pdf_thumbnail_preview_id';
        const META_PREVIEW_ERROR = '_pdf_thumbnail_preview_error';
        const META_PREVIEW_GENERATED_AT = '_pdf_thumbnail_preview_generated_at';
        const META_PARENT_PDF = '_pdf_thumbnail_parent_pdf_id';

        /**
         * @var PDF_Thumbnail_Inserter|null
         */
        private static $instance = null;

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
            add_action( 'init', array( $this, 'register_block' ) );
            add_action( 'admin_menu', array( $this, 'add_settings_menu' ) );
            add_action( 'admin_init', array( $this, 'register_settings' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
            add_action( 'init', array( $this, 'enqueue_block_editor_assets' ) );
            add_action( 'media_buttons', array( $this, 'render_media_button' ) );
            add_action( 'wp_ajax_pdf_thumbnail_fetch_pdf_data', array( $this, 'ajax_fetch_pdf_data' ) );
            add_action( 'admin_post_pdf_thumbnail_regenerate_missing', array( $this, 'handle_regenerate_missing_previews' ) );
            add_action( 'delete_attachment', array( $this, 'cleanup_generated_preview' ) );
            add_shortcode( 'pdf_thumbnail', array( $this, 'render_shortcode' ) );
            add_filter( 'attachment_fields_to_edit', array( $this, 'add_attachment_fields' ), 10, 2 );
            add_filter( 'wp_generate_attachment_metadata', array( $this, 'maybe_generate_pdf_preview_on_upload' ), 20, 3 );
            add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_action_links' ) );
        }

        public function load_textdomain() {
            load_plugin_textdomain( 'pdf-thumbnail-inserter', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
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
            register_setting(
                'pdf_thumbnail_settings_group',
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

            if ( $is_settings_page ) {
                wp_enqueue_style( 'wp-color-picker' );
                wp_enqueue_script(
                    'pdf-thumbnail-admin',
                    PDF_THUMBNAIL_INSERTER_URL . 'assets/js/admin.js',
                    array( 'jquery', 'wp-color-picker' ),
                    self::VERSION,
                    true
                );
                wp_enqueue_style(
                    'pdf-thumbnail-admin',
                    PDF_THUMBNAIL_INSERTER_URL . 'assets/css/admin.css',
                    array(),
                    self::VERSION
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
                        'labels'  => array(
                            'selectPdf'       => __( 'Select PDF File', 'pdf-thumbnail-inserter' ),
                            'error'           => __( 'An error occurred while preparing the PDF shortcode.', 'pdf-thumbnail-inserter' ),
                            'desktopColumns'  => __( 'Desktop columns (1-6)', 'pdf-thumbnail-inserter' ),
                            'laptopColumns'   => __( 'Laptop / tablet columns (1-6)', 'pdf-thumbnail-inserter' ),
                            'mobileColumns'   => __( 'Mobile columns (1-6)', 'pdf-thumbnail-inserter' ),
                        ),
                    )
                );
            }
        }

        public function enqueue_block_editor_assets() {
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
                )
            );

            wp_register_style(
                'pdf-thumbnail-frontend',
                PDF_THUMBNAIL_INSERTER_URL . 'assets/css/pdf-thumbnail.css',
                array(),
                self::VERSION
            );

            wp_register_style(
                'pdf-thumbnail-block-editor-style',
                PDF_THUMBNAIL_INSERTER_URL . 'blocks/pdf-thumbnail/editor.css',
                array( 'pdf-thumbnail-frontend' ),
                self::VERSION
            );
        }

        public function register_block() {
            if ( ! function_exists( 'register_block_type' ) ) {
                return;
            }

            register_block_type(
                PDF_THUMBNAIL_INSERTER_PATH . 'blocks/pdf-thumbnail',
                array(
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
            );
        }

        public function sanitize_settings( $input ) {
            $defaults = $this->get_defaults();
            $input    = is_array( $input ) ? $input : array();
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

            return $out;
        }

        private function sanitize_hex_color_with_fallback( $value, $fallback ) {
            $sanitized = sanitize_hex_color( $value );
            return $sanitized ? $sanitized : $fallback;
        }

        private function sanitize_css_dimension( $value, $fallback ) {
            $value = trim( (string) $value );
            return preg_match( '/^\d+(?:\.\d+)?(?:px|em|rem|%)$/', $value ) ? $value : $fallback;
        }

        private function sanitize_link_to( $value ) {
            $allowed = array( 'file', 'attachment', 'custom', 'none' );
            return in_array( $value, $allowed, true ) ? $value : 'file';
        }

        private function get_settings() {
            return wp_parse_args( get_option( self::OPTION_NAME, array() ), $this->get_defaults() );
        }

        public function render_settings_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $settings = $this->get_settings();
            $notice = '';

            if ( isset( $_GET['pti_regenerated'] ) ) {
                $count = isset( $_GET['pti_regenerated_count'] ) ? absint( $_GET['pti_regenerated_count'] ) : 0;
                $notice = sprintf(
                    /* translators: %d: number of previews generated. */
                    __( 'Generated %d missing PDF preview(s).', 'pdf-thumbnail-inserter' ),
                    $count
                );
            }
            ?>
            <div class="wrap pdf-thumbnail-settings-wrap">
                <h1><?php echo esc_html__( 'PDF Thumbnail Inserter', 'pdf-thumbnail-inserter' ); ?></h1>
                <p><?php echo esc_html__( 'Set the default presentation for the shortcode and Gutenberg block, and regenerate missing previews when needed.', 'pdf-thumbnail-inserter' ); ?></p>

                <?php if ( $notice ) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
                <?php endif; ?>

                <form method="post" action="options.php">
                    <?php settings_fields( 'pdf_thumbnail_settings_group' ); ?>
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
                        </tbody>
                    </table>
                    <?php submit_button(); ?>
                </form>

                <hr>

                <h2><?php echo esc_html__( 'Maintenance', 'pdf-thumbnail-inserter' ); ?></h2>
                <p><?php echo esc_html__( 'Generate previews for existing PDF attachments that do not already have one.', 'pdf-thumbnail-inserter' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="pdf_thumbnail_regenerate_missing">
                    <?php wp_nonce_field( 'pdf_thumbnail_regenerate_missing' ); ?>
                    <?php submit_button( __( 'Generate Missing Previews', 'pdf-thumbnail-inserter' ), 'secondary', 'submit', false ); ?>
                </form>
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

            $attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
            if ( ! $attachment_id ) {
                wp_send_json_error( array( 'message' => __( 'Invalid attachment ID.', 'pdf-thumbnail-inserter' ) ), 400 );
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
                    'shortcode'     => sprintf( '[pdf_thumbnail id="%d"]', $attachment_id ),
                )
            );
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
                'placeholder' => __( 'Placeholder only', 'pdf-thumbnail-inserter' ),
            );

            $form_fields['pdf_thumbnail_shortcode'] = array(
                'label' => __( 'PDF thumbnail shortcode', 'pdf-thumbnail-inserter' ),
                'input' => 'html',
                'html'  => '<input type="text" class="widefat code" readonly value="' . esc_attr( sprintf( '[pdf_thumbnail id="%d"]', $post->ID ) ) . '">',
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

            $this->ensure_pdf_preview( $attachment_id );

            return $metadata;
        }

        public function cleanup_generated_preview( $attachment_id ) {
            if ( 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
                return;
            }

            $preview_id = absint( get_post_meta( $attachment_id, self::META_PREVIEW_ID, true ) );
            if ( $preview_id && get_post_meta( $preview_id, self::META_PARENT_PDF, true ) ) {
                delete_post_meta( $attachment_id, self::META_PREVIEW_ID );
                wp_delete_attachment( $preview_id, true );
            }
        }

        public function handle_regenerate_missing_previews() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Insufficient permissions.', 'pdf-thumbnail-inserter' ) );
            }

            check_admin_referer( 'pdf_thumbnail_regenerate_missing' );

            $pdf_ids = get_posts(
                array(
                    'post_type'      => 'attachment',
                    'post_mime_type' => 'application/pdf',
                    'post_status'    => 'inherit',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'orderby'        => 'ID',
                    'order'          => 'ASC',
                )
            );

            $generated = 0;

            foreach ( $pdf_ids as $pdf_id ) {
                $preview_data = $this->get_pdf_preview_data( $pdf_id, 'full' );
                if ( 'placeholder' !== $preview_data['preview_source'] ) {
                    continue;
                }

                $result = $this->ensure_pdf_preview( $pdf_id, true );
                if ( ! is_wp_error( $result ) && ! empty( $result['generated'] ) ) {
                    $generated++;
                }
            }

            wp_safe_redirect(
                add_query_arg(
                    array(
                        'page'                  => 'pdf-thumbnail-settings',
                        'pti_regenerated'       => 1,
                        'pti_regenerated_count' => $generated,
                    ),
                    admin_url( 'options-general.php' )
                )
            );
            exit;
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

            $existing = $this->get_pdf_preview_data( $attachment_id, 'full' );
            if ( 'placeholder' !== $existing['preview_source'] && ! $allow_retry ) {
                return array(
                    'generated'      => false,
                    'preview_source' => $existing['preview_source'],
                    'preview_id'     => ! empty( $existing['preview_id'] ) ? absint( $existing['preview_id'] ) : 0,
                );
            }

            $file_path = get_attached_file( $attachment_id );
            if ( ! $file_path || ! file_exists( $file_path ) ) {
                update_post_meta( $attachment_id, self::META_PREVIEW_ERROR, __( 'Original PDF file could not be found.', 'pdf-thumbnail-inserter' ) );
                return new WP_Error( 'missing_file', __( 'Original PDF file could not be found.', 'pdf-thumbnail-inserter' ) );
            }

            if ( ! class_exists( 'Imagick' ) ) {
                update_post_meta( $attachment_id, self::META_PREVIEW_ERROR, __( 'Imagick is not available on this server.', 'pdf-thumbnail-inserter' ) );
                return new WP_Error( 'imagick_missing', __( 'Imagick is not available on this server.', 'pdf-thumbnail-inserter' ) );
            }

            $path_info = pathinfo( $file_path );
            $dir_path  = isset( $path_info['dirname'] ) ? $path_info['dirname'] : '';
            $filename  = isset( $path_info['filename'] ) ? $path_info['filename'] : 'pdf-preview';
            $preview_filename = wp_unique_filename( $dir_path, $filename . '-pdf-preview.jpg' );
            $preview_path = trailingslashit( $dir_path ) . $preview_filename;

            try {
                $imagick = new Imagick();
                $imagick->setResolution( 150, 150 );
                $imagick->readImage( $file_path . '[0]' );
                $imagick->setImageFormat( 'jpeg' );
                $imagick->setImageBackgroundColor( 'white' );

                if ( method_exists( $imagick, 'setImageAlphaChannel' ) && defined( 'Imagick::ALPHACHANNEL_REMOVE' ) ) {
                    $imagick->setImageAlphaChannel( Imagick::ALPHACHANNEL_REMOVE );
                }

                if ( method_exists( $imagick, 'mergeImageLayers' ) && defined( 'Imagick::LAYERMETHOD_FLATTEN' ) ) {
                    $imagick = $imagick->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
                }

                $imagick->setImageCompressionQuality( 82 );
                $imagick->thumbnailImage( 1200, 0, true, true );
                $imagick->writeImage( $preview_path );
                $imagick->clear();
                $imagick->destroy();
            } catch ( Exception $e ) {
                update_post_meta( $attachment_id, self::META_PREVIEW_ERROR, sanitize_text_field( $e->getMessage() ) );
                return new WP_Error( 'imagick_failed', $e->getMessage() );
            }

            if ( ! file_exists( $preview_path ) ) {
                update_post_meta( $attachment_id, self::META_PREVIEW_ERROR, __( 'Preview image could not be written to disk.', 'pdf-thumbnail-inserter' ) );
                return new WP_Error( 'preview_missing', __( 'Preview image could not be written to disk.', 'pdf-thumbnail-inserter' ) );
            }

            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $preview_attachment = array(
                'post_mime_type' => 'image/jpeg',
                'post_title'     => get_the_title( $attachment_id ) . ' ' . __( 'Preview', 'pdf-thumbnail-inserter' ),
                'post_content'   => '',
                'post_status'    => 'inherit',
                'post_parent'    => $attachment_id,
            );

            $preview_id = wp_insert_attachment( $preview_attachment, $preview_path, 0, true );
            if ( is_wp_error( $preview_id ) ) {
                update_post_meta( $attachment_id, self::META_PREVIEW_ERROR, $preview_id->get_error_message() );
                return $preview_id;
            }

            $preview_meta = wp_generate_attachment_metadata( $preview_id, $preview_path );
            if ( ! is_wp_error( $preview_meta ) && is_array( $preview_meta ) ) {
                wp_update_attachment_metadata( $preview_id, $preview_meta );
            }

            update_post_meta( $preview_id, self::META_PARENT_PDF, $attachment_id );
            update_post_meta( $attachment_id, self::META_PREVIEW_ID, $preview_id );
            update_post_meta( $attachment_id, self::META_PREVIEW_GENERATED_AT, time() );
            delete_post_meta( $attachment_id, self::META_PREVIEW_ERROR );

            return array(
                'generated'      => true,
                'preview_source' => 'plugin',
                'preview_id'     => $preview_id,
            );
        }

        private function get_pdf_preview_data( $attachment_id, $size = 'medium' ) {
            $attachment_id = absint( $attachment_id );
            $title = get_the_title( $attachment_id );
            $file_url = wp_get_attachment_url( $attachment_id );
            $attachment_url = get_attachment_link( $attachment_id );

            $thumbnail_url = '';
            $preview_id = 0;
            $source = 'placeholder';

            $core_preview = wp_get_attachment_image_url( $attachment_id, $size );
            if ( $core_preview ) {
                $thumbnail_url = $core_preview;
                $source = 'wordpress';
            }

            if ( ! $thumbnail_url ) {
                $preview_id = absint( get_post_meta( $attachment_id, self::META_PREVIEW_ID, true ) );
                if ( $preview_id && 'image/' === substr( (string) get_post_mime_type( $preview_id ), 0, 6 ) ) {
                    $plugin_preview = wp_get_attachment_image_url( $preview_id, $size );
                    if ( ! $plugin_preview ) {
                        $plugin_preview = wp_get_attachment_url( $preview_id );
                    }
                    if ( $plugin_preview ) {
                        $thumbnail_url = $plugin_preview;
                        $source = 'plugin';
                    }
                }
            }

            if ( ! $thumbnail_url ) {
                $thumbnail_url = PDF_THUMBNAIL_INSERTER_URL . 'assets/images/pdf-placeholder.svg';
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
                    if ( $id && 'application/pdf' === get_post_mime_type( $id ) ) {
                        $ids[] = $id;
                    }
                }
            }

            if ( empty( $ids ) && ! empty( $atts['id'] ) ) {
                $id = absint( $atts['id'] );
                if ( $id && 'application/pdf' === get_post_mime_type( $id ) ) {
                    $ids[] = $id;
                }
            }

            return array_values( array_unique( array_filter( $ids ) ) );
        }

        private function clamp_columns( $value, $default = 1 ) {
            $value = absint( $value );

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
                '--pti-columns-laptop:' . $this->clamp_columns( isset( $atts['columns_laptop'] ) ? $atts['columns_laptop'] : 2, 2 ),
                '--pti-columns-mobile:' . $this->clamp_columns( isset( $atts['columns_mobile'] ) ? $atts['columns_mobile'] : 1, 1 ),
            );

            return implode( ';', $styles );
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

            ob_start();
            ?>
            <div class="pti-card-wrap">
                <figure class="pti-card">
                    <?php if ( ! empty( $target_url ) ) : ?>
                        <a class="pti-card__thumb-link" href="<?php echo esc_url( $target_url ); ?>"<?php echo $context['target_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $context['rel_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-label="<?php echo esc_attr( $preview_data['title'] ); ?>">
                            <img class="pti-card__thumb" src="<?php echo esc_url( $preview_data['thumbnail_url'] ); ?>" alt="<?php echo esc_attr( $preview_data['title'] ); ?>">
                        </a>
                    <?php else : ?>
                        <span class="pti-card__thumb-link pti-card__thumb-link--static" aria-hidden="true">
                            <img class="pti-card__thumb" src="<?php echo esc_url( $preview_data['thumbnail_url'] ); ?>" alt="<?php echo esc_attr( $preview_data['title'] ); ?>">
                        </span>
                    <?php endif; ?>

                    <?php if ( $context['show_title'] ) : ?>
                        <figcaption class="pti-card__caption">
                            <?php if ( ! empty( $target_url ) ) : ?>
                                <a class="pti-card__title pti-card__title-link" href="<?php echo esc_url( $target_url ); ?>"<?php echo $context['target_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $context['rel_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $preview_data['title'] ); ?></a>
                            <?php else : ?>
                                <span class="pti-card__title"><?php echo esc_html( $preview_data['title'] ); ?></span>
                            <?php endif; ?>
                        </figcaption>
                    <?php endif; ?>

                    <?php if ( $button_should_render ) : ?>
                        <a class="pti-card__button" href="<?php echo esc_url( $target_url ); ?>"<?php echo $context['target_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $context['rel_attr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $context['button_text'] ); ?></a>
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
                'columns_laptop'     => 2,
                'columns_mobile'     => 1,
                'class'              => '',
                'rel'                => '',
            );

            return shortcode_atts( $defaults, $atts, 'pdf_thumbnail' );
        }

        public function render_block( $attributes ) {
            return $this->render_shortcode( $attributes );
        }

        public function render_shortcode( $atts ) {
            wp_enqueue_style( 'pdf-thumbnail-frontend', PDF_THUMBNAIL_INSERTER_URL . 'assets/css/pdf-thumbnail.css', array(), self::VERSION );

            $atts = $this->get_shortcode_atts( $atts );
            $attachment_ids = $this->parse_attachment_ids( $atts );
            $size = sanitize_key( $atts['size'] );
            $show_title = $this->normalize_bool_attr( $atts['show_title'], true );
            $show_button = $this->normalize_bool_attr( $atts['show_button'], true );
            $new_tab = $this->normalize_bool_attr( $atts['new_tab'], true );
            $custom_classes = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $atts['class'] ) ) );
            $rel = sanitize_text_field( $atts['rel'] );

            if ( empty( $attachment_ids ) ) {
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

            $allow_custom_overrides = 1 === count( $attachment_ids );

            ob_start();
            ?>
            <div class="<?php echo esc_attr( $classes ); ?>" style="<?php echo esc_attr( $wrapper_style ); ?>">
                <div class="pti-grid">
                    <?php foreach ( $attachment_ids as $attachment_id ) : ?>
                        <?php
                        echo $this->render_pdf_card(
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
                        ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
