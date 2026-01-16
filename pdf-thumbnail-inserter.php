<?php
/**
 * Plugin Name: PDF Thumbnail Inserter
 * Description: Adds a button in the WordPress editor to insert a PDF thumbnail with animation, title, and customizable download link.
 * Version: 1.8
 * Author: Byron Iniotakis
 * Text Domain: pdf-thumbnail-inserter
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PDF_THUMBNAIL_INSERTER_VERSION', '1.8');
define('PDF_THUMBNAIL_INSERTER_URL', plugin_dir_url(__FILE__));

/**
 * Load translations.
 */
add_action('init', function () {
    load_plugin_textdomain(
        'pdf-thumbnail-inserter',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
});

/**
 * Add Classic Editor button.
 */
add_action('media_buttons', 'pdf_thumbnail_button', 20);
function pdf_thumbnail_button() {
    echo '<button type="button" id="insert-pdf-thumbnail" class="button">Insert PDF Thumbnail</button>';
}

/**
 * Enqueue admin scripts (Classic editor screens).
 */
add_action('admin_enqueue_scripts', 'pdf_thumbnail_scripts');
function pdf_thumbnail_scripts($hook) {
    if ($hook !== 'post.php' && $hook !== 'post-new.php') {
        return;
    }

    wp_enqueue_media();

    wp_enqueue_script(
        'pdf-thumbnail-js',
        PDF_THUMBNAIL_INSERTER_URL . 'js/pdf-thumbnail.js',
        array('jquery'),
        PDF_THUMBNAIL_INSERTER_VERSION,
        true
    );

    wp_localize_script('pdf-thumbnail-js', 'pdfThumbnail', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('pdf_thumbnail_nonce'),
    ));
}

/**
 * Enqueue frontend styles.
 */
add_action('wp_enqueue_scripts', 'pdf_thumbnail_styles');
function pdf_thumbnail_styles() {
    wp_enqueue_style(
        'pdf-thumbnail-css',
        PDF_THUMBNAIL_INSERTER_URL . 'css/pdf-thumbnail.css',
        array(),
        PDF_THUMBNAIL_INSERTER_VERSION
    );
}

/**
 * Settings page.
 */
add_action('admin_menu', 'pdf_thumbnail_settings_menu');
function pdf_thumbnail_settings_menu() {
    add_options_page(
        'PDF Thumbnail Settings',
        'PDF Thumbnail Settings',
        'manage_options',
        'pdf-thumbnail-settings',
        'pdf_thumbnail_settings_page'
    );
}

/**
 * Register settings (with sanitization).
 */
add_action('admin_init', 'pdf_thumbnail_register_settings');
function pdf_thumbnail_register_settings() {
    register_setting(
        'pdf_thumbnail_settings_group',
        'pdf_thumbnail_defaults',
        array(
            'type'              => 'array',
            'sanitize_callback' => 'pdf_thumbnail_sanitize_defaults',
            'default'           => pdf_thumbnail_get_defaults(),
        )
    );
}

function pdf_thumbnail_get_defaults() {
    return array(
        // Translatable default as requested:
        'button_text'        => __('Download PDF', 'pdf-thumbnail-inserter'),

        'thumbnail_width'    => '212px',
        'title_color'        => '#333',
        'title_size'         => '14px',
        'button_color'       => '#0073aa',
        'button_hover_color' => '#005177',
        'button_text_size'   => '12px',
    );
}

function pdf_thumbnail_sanitize_defaults($input) {
    $defaults = pdf_thumbnail_get_defaults();
    $input = is_array($input) ? $input : array();

    $out = array();

    $out['button_text'] = isset($input['button_text']) ? sanitize_text_field($input['button_text']) : $defaults['button_text'];

    $out['thumbnail_width'] = isset($input['thumbnail_width'])
        ? pdf_thumbnail_sanitize_css_dimension($input['thumbnail_width'], $defaults['thumbnail_width'])
        : $defaults['thumbnail_width'];

    $out['title_color'] = isset($input['title_color']) ? sanitize_hex_color($input['title_color']) : $defaults['title_color'];
    $out['title_size']  = isset($input['title_size'])
        ? pdf_thumbnail_sanitize_css_dimension($input['title_size'], $defaults['title_size'])
        : $defaults['title_size'];

    $out['button_color']       = isset($input['button_color']) ? sanitize_hex_color($input['button_color']) : $defaults['button_color'];
    $out['button_hover_color'] = isset($input['button_hover_color']) ? sanitize_hex_color($input['button_hover_color']) : $defaults['button_hover_color'];

    $out['button_text_size'] = isset($input['button_text_size'])
        ? pdf_thumbnail_sanitize_css_dimension($input['button_text_size'], $defaults['button_text_size'])
        : $defaults['button_text_size'];

    return $out;
}

/**
 * Allows: 12px, 1.2rem, 100%, 2em
 */
function pdf_thumbnail_sanitize_css_dimension($value, $fallback) {
    $value = trim((string) $value);
    return preg_match('/^\d+(\.\d+)?(px|em|rem|%)$/', $value) ? $value : $fallback;
}

/**
 * Settings page callback.
 */
function pdf_thumbnail_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $defaults = get_option('pdf_thumbnail_defaults', pdf_thumbnail_get_defaults());
    $defaults = wp_parse_args($defaults, pdf_thumbnail_get_defaults());
    ?>
    <div class="wrap">
        <h1>PDF Thumbnail Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('pdf_thumbnail_settings_group'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Download Button Text</th>
                    <td><input type="text" name="pdf_thumbnail_defaults[button_text]" value="<?php echo esc_attr($defaults['button_text']); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">Thumbnail Width</th>
                    <td><input type="text" name="pdf_thumbnail_defaults[thumbnail_width]" value="<?php echo esc_attr($defaults['thumbnail_width']); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">Title Color</th>
                    <td><input type="text" name="pdf_thumbnail_defaults[title_color]" value="<?php echo esc_attr($defaults['title_color']); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">Title Font Size</th>
                    <td><input type="text" name="pdf_thumbnail_defaults[title_size]" value="<?php echo esc_attr($defaults['title_size']); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">Button Color</th>
                    <td><input type="text" name="pdf_thumbnail_defaults[button_color]" value="<?php echo esc_attr($defaults['button_color']); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">Button Hover Color</th>
                    <td><input type="text" name="pdf_thumbnail_defaults[button_hover_color]" value="<?php echo esc_attr($defaults['button_hover_color']); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">Button Font Size</th>
                    <td><input type="text" name="pdf_thumbnail_defaults[button_text_size]" value="<?php echo esc_attr($defaults['button_text_size']); ?>" /></td>
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
add_action('wp_ajax_fetch_pdf_data', 'fetch_pdf_data');
function fetch_pdf_data() {
    check_ajax_referer('pdf_thumbnail_nonce', 'nonce');

    // Limit to users who can upload media (reasonable default).
    if (!current_user_can('upload_files')) {
        wp_send_json_error(array('message' => 'Insufficient permissions.'), 403);
    }

    $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
    if (!$attachment_id) {
        wp_send_json_error(array('message' => 'Invalid attachment ID.'), 400);
    }

    if (get_post_mime_type($attachment_id) !== 'application/pdf') {
        wp_send_json_error(array('message' => 'Selected file is not a PDF.'), 400);
    }

    $file_url   = wp_get_attachment_url($attachment_id);
    $file_title = get_the_title($attachment_id);

    $thumbnail_url = wp_get_attachment_image_src($attachment_id, 'medium');
    if (!$thumbnail_url || !isset($thumbnail_url[0])) {
        $thumbnail_url[0] = PDF_THUMBNAIL_INSERTER_URL . 'images/pdf-placeholder.png';
    }

    wp_send_json_success(array(
        'thumbnail_url' => esc_url_raw($thumbnail_url[0]),
        'file_url'      => esc_url_raw($file_url),
        'file_title'    => sanitize_text_field($file_title),
    ));
}

/**
 * Shortcode.
 * [pdf_thumbnail thumbnail="..." title="..." url="..."]
 */
add_shortcode('pdf_thumbnail', 'display_pdf_thumbnail');
function display_pdf_thumbnail($atts) {
    $defaults = get_option('pdf_thumbnail_defaults', pdf_thumbnail_get_defaults());
    $defaults = wp_parse_args($defaults, pdf_thumbnail_get_defaults());

    $fallback_values = array(
        'title'     => 'PDF File',
        'url'       => '#',
        'thumbnail' => PDF_THUMBNAIL_INSERTER_URL . 'images/pdf-placeholder.png',
    );

    $atts = shortcode_atts(array_merge($defaults, $fallback_values), $atts, 'pdf_thumbnail');

    $html = '<div class="pdf-thumbnail-container" style="width: ' . esc_attr($atts['thumbnail_width']) . '; margin: 10px; display: inline-block; text-align: center;">
        <div class="pdf-title" style="color: ' . esc_attr($atts['title_color']) . '; font-size: ' . esc_attr($atts['title_size']) . '; max-width: 100%; word-wrap: break-word; margin-bottom: 5px;">
            ' . esc_html($atts['title']) . '
        </div>
        <a href="' . esc_url($atts['url']) . '" target="_blank" rel="noopener noreferrer">
            <div class="pdf-thumbnail book-style" style="background-image: url(' . esc_url($atts['thumbnail']) . ');"></div>
        </a>
        <a href="' . esc_url($atts['url']) . '" class="pdf-download-button" download
           style="background-color: ' . esc_attr($atts['button_color']) . '; font-size: ' . esc_attr($atts['button_text_size']) . ';"
           data-hover="' . esc_attr($atts['button_hover_color']) . '"
           data-normal="' . esc_attr($atts['button_color']) . '">
            ' . esc_html($atts['button_text']) . '
        </a>
    </div>';

    return $html;
}
