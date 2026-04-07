<?php
/**
 * Plugin Name: PDF Thumbnail Inserter
 * Description: Adds a button in the WordPress editor to insert a PDF thumbnail with animation, title, and customizable download link.
 * Version: 1.8.0
 * Author: Byron Iniotakis
 * Text Domain: pdf-thumbnail-inserter
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PDF_THUMBNAIL_INSERTER_VERSION', '1.8.0' );
define( 'PDF_THUMBNAIL_INSERTER_URL', plugin_dir_url( __FILE__ ) );

if ( ! class_exists( 'PDF_Thumbnail_Inserter' ) ) {

    class PDF_Thumbnail_Inserter {

        public function __construct() {
            // Initialization
            add_action( 'init', array( $this, 'load_textdomain' ) );

            // Admin Hooks
            add_action( 'media_buttons', array( $this, 'add_media_button' ), 20 );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
            add_action( 'admin_menu', array( $this, 'add_settings_menu' ) );
            add_action( 'admin_init', array( $this, 'register_settings' ) );

            // AJAX Hooks
            add_action( 'wp_ajax_fetch_pdf_data', array( $this, 'ajax_fetch_pdf_data' ) );

            // Shortcode
            add_shortcode( 'pdf_thumbnail', array( $this, 'render_shortcode' ) );
        }

        /**
         * Load translations.
         */
        public function load_textdomain() {
            load_plugin_textdomain(
                'pdf-thumbnail-inserter',
                false,
                dirname( plugin_basename( __FILE__ ) ) . '/languages'
            );
        }

        /**
         * Add Classic Editor button.
         */
        public function add_media_button() {
            echo '<button type="button" id="insert-pdf-thumbnail" class="button">' . esc_html__( 'Insert PDF Thumbnail', 'pdf-thumbnail-inserter' ) . '</button>';
        }

        /**
         * Enqueue admin scripts (Classic editor screens).
         */
        public function enqueue_admin_scripts( $hook ) {
            if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
                return;
            }

            wp_enqueue_media();

            wp_enqueue_script(
                'pdf-thumbnail-js',
                PDF_THUMBNAIL_INSERTER_URL . 'js/pdf-thumbnail.js',
                array( 'jquery' ),
                PDF_THUMBNAIL_INSERTER_VERSION,
                true
            );

            wp_localize_script( 'pdf-thumbnail-js', 'pdfThumbnail', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'pdf_thumbnail_nonce' ),
            ) );
        }

        /**
         * Settings page menu.
         */
        public function add_settings_menu() {
            add_options_page(
                __( 'PDF Thumbnail Settings', 'pdf-thumbnail-inserter' ),
                __( 'PDF Thumbnail Settings', 'pdf-thumbnail-inserter' ),
                'manage_options',
                'pdf-thumbnail-settings',
                array( $this, 'render_settings_page' )
            );
        }

        /**
         * Register settings (with sanitization).
         */
        public function register_settings() {
            register_setting(
                'pdf_thumbnail_settings_group',
                'pdf_thumbnail_defaults',
                array(
                    'type'              => 'array',
                    'sanitize_callback' => array( $this, 'sanitize_settings' ),
                    'default'           => $this->get_defaults(),
                )
            );
        }

        /**
         * Get default settings.
         */
        private function get_defaults() {
            return array(
                'button_text'        => __( 'Download PDF', 'pdf-thumbnail-inserter' ),
                'thumbnail_width'    => '212px',
                'title_color'        => '#333',
                'title_size'         => '14px',
                'button_color'       => '#0073aa',
                'button_hover_color' => '#005177',
                'button_text_size'   => '12px',
            );
        }

        /**
         * Sanitize settings input.
         */
        public function sanitize_settings( $input ) {
            $defaults = $this->get_defaults();
            $input    = is_array( $input ) ? $input : array();
            $out      = array();

            $out['button_text'] = isset( $input['button_text'] ) ? sanitize_text_field( $input['button_text'] ) : $defaults['button_text'];

            $out['thumbnail_width'] = isset( $input['thumbnail_width'] )
                ? $this->sanitize_css_dimension( $input['thumbnail_width'], $defaults['thumbnail_width'] )
                : $defaults['thumbnail_width'];

            $out['title_color'] = isset( $input['title_color'] ) ? sanitize_hex_color( $input['title_color'] ) : $defaults['title_color'];
            
            $out['title_size']  = isset( $input['title_size'] )
                ? $this->sanitize_css_dimension( $input['title_size'], $defaults['title_size'] )
                : $defaults['title_size'];

            $out['button_color']       = isset( $input['button_color'] ) ? sanitize_hex_color( $input['button_color'] ) : $defaults['button_color'];
            $out['button_hover_color'] = isset( $input['button_hover_color'] ) ? sanitize_hex_color( $input['button_hover_color'] ) : $defaults['button_hover_color'];

            $out['button_text_size'] = isset( $input['button_text_size'] )
                ? $this->sanitize_css_dimension( $input['button_text_size'], $defaults['button_text_size'] )
                : $defaults['button_text_size'];

            return $out;
        }

        /**
         * Allows: 12px, 1.2rem, 100%, 2em
         */
        private function sanitize_css_dimension( $value, $fallback ) {
            $value = trim( (string) $value );
            return preg_match( '/^\d+(\.\d+)?(px|em|rem|%)$/', $value ) ? $value : $fallback;
        }

        /**
         * Render the settings page.
         */
        public function render_settings_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $defaults = get_option( 'pdf_thumbnail_defaults', $this->get_defaults() );
            $defaults = wp_parse_args( $defaults, $this->get_defaults() );
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'PDF Thumbnail Settings', 'pdf-thumbnail-inserter' ); ?></h1>
                <form method="post" action="options.php">
                    <?php settings_fields( 'pdf_thumbnail_settings_group' ); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Download Button Text', 'pdf-thumbnail-inserter' ); ?></th>
                            <td><input type="text" name="pdf_thumbnail_defaults[button_text]" value="<?php echo esc_attr( $defaults['button_text'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Thumbnail Width', 'pdf-thumbnail-inserter' ); ?></th>
                            <td><input type="text" name="pdf_thumbnail_defaults[thumbnail_width]" value="<?php echo esc_attr( $defaults['thumbnail_width'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Title Color', 'pdf-thumbnail-inserter' ); ?></th>
                            <td><input type="text" name="pdf_thumbnail_defaults[title_color]" value="<?php echo esc_attr( $defaults['title_color'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Title Font Size', 'pdf-thumbnail-inserter' ); ?></th>
                            <td><input type="text" name="pdf_thumbnail_defaults[title_size]" value="<?php echo esc_attr( $defaults['title_size'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Button Color', 'pdf-thumbnail-inserter' ); ?></th>
                            <td><input type="text" name="pdf_thumbnail_defaults[button_color]" value="<?php echo esc_attr( $defaults['button_color'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Button Hover Color', 'pdf-thumbnail-inserter' ); ?></th>
                            <td><input type="text" name="pdf_thumbnail_defaults[button_hover_color]" value="<?php echo esc_attr( $defaults['button_hover_color'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Button Font Size', 'pdf-thumbnail-inserter' ); ?></th>
                            <td><input type="text" name="pdf_thumbnail_defaults[button_text_size]" value="<?php echo esc_attr( $defaults['button_text_size'] ); ?>" /></td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>
            </div>
            <?php
        }

        /**
         * AJAX handler for fetching PDF thumbnail, title, and URL.
         */
        public function ajax_fetch_pdf_data() {
            check_ajax_referer( 'pdf_thumbnail_nonce', 'nonce' );

            if ( ! current_user_can( 'upload_files' ) ) {
                wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'pdf-thumbnail-inserter' ) ), 403 );
            }

            $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
            if ( ! $attachment_id ) {
                wp_send_json_error( array( 'message' => __( 'Invalid attachment ID.', 'pdf-thumbnail-inserter' ) ), 400 );
            }

            if ( get_post_mime_type( $attachment_id ) !== 'application/pdf' ) {
                wp_send_json_error( array( 'message' => __( 'Selected file is not a PDF.', 'pdf-thumbnail-inserter' ) ), 400 );
            }

            $file_url   = wp_get_attachment_url( $attachment_id );
            $file_title = get_the_title( $attachment_id );

            $thumbnail_url = wp_get_attachment_image_src( $attachment_id, 'medium' );
            if ( ! $thumbnail_url || ! isset( $thumbnail_url[0] ) ) {
                $thumbnail_url[0] = PDF_THUMBNAIL_INSERTER_URL . 'images/pdf-placeholder.png';
            }

            wp_send_json_success( array(
                'thumbnail_url' => esc_url_raw( $thumbnail_url[0] ),
                'file_url'      => esc_url_raw( $file_url ),
                'file_title'    => sanitize_text_field( $file_title ),
            ) );
        }

        /**
         * Shortcode renderer.
         * [pdf_thumbnail thumbnail="..." title="..." url="..."]
         */
        public function render_shortcode( $atts ) {
            // Enqueue CSS ONLY when shortcode is executed
            wp_enqueue_style(
                'pdf-thumbnail-css',
                PDF_THUMBNAIL_INSERTER_URL . 'css/pdf-thumbnail.css',
                array(),
                PDF_THUMBNAIL_INSERTER_VERSION
            );

            $defaults = get_option( 'pdf_thumbnail_defaults', $this->get_defaults() );
            $defaults = wp_parse_args( $defaults, $this->get_defaults() );

            $fallback_values = array(
                'title'     => __( 'PDF File', 'pdf-thumbnail-inserter' ),
                'url'       => '#',
                'thumbnail' => PDF_THUMBNAIL_INSERTER_URL . 'images/pdf-placeholder.png',
            );

            $atts = shortcode_atts( array_merge( $defaults, $fallback_values ), $atts, 'pdf_thumbnail' );

            ob_start();
            ?>
            <div class="pdf-thumbnail-container" style="width: <?php echo esc_attr( $atts['thumbnail_width'] ); ?>; margin: 10px; display: inline-block; text-align: center;">
                <div class="pdf-title" style="color: <?php echo esc_attr( $atts['title_color'] ); ?>; font-size: <?php echo esc_attr( $atts['title_size'] ); ?>; max-width: 100%; word-wrap: break-word; margin-bottom: 5px;">
                    <?php echo esc_html( $atts['title'] ); ?>
                </div>
                <a href="<?php echo esc_url( $atts['url'] ); ?>" target="_blank" rel="noopener noreferrer">
                    <div class="pdf-thumbnail book-style" style="background-image: url('<?php echo esc_url( $atts['thumbnail'] ); ?>');"></div>
                </a>
                <a href="<?php echo esc_url( $atts['url'] ); ?>" class="pdf-download-button" download
                   style="--btn-bg: <?php echo esc_attr( $atts['button_color'] ); ?>; --btn-hover: <?php echo esc_attr( $atts['button_hover_color'] ); ?>; font-size: <?php echo esc_attr( $atts['button_text_size'] ); ?>;">
                    <?php echo esc_html( $atts['button_text'] ); ?>
                </a>
            </div>
            <?php
            return ob_get_clean();
        }
    }

    // Initialize the class
    new PDF_Thumbnail_Inserter();
}
