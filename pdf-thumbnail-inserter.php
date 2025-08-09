<?php
/**
 * Plugin Name: PDF Thumbnail Inserter
 * Description: Adds a button in the WordPress editor to insert a PDF thumbnail with animation, title, and customizable download link.
 * Version: 1.7
 * Author: Byron Iniotakis
 */

// Hook to add editor button
add_action('media_buttons', 'pdf_thumbnail_button');
function pdf_thumbnail_button() {
    echo '<button type="button" id="insert-pdf-thumbnail" class="button">Insert PDF Thumbnail</button>';
}

// Load scripts and styles for the plugin
add_action('admin_enqueue_scripts', 'pdf_thumbnail_scripts');
add_action('wp_enqueue_scripts', 'pdf_thumbnail_styles');
function pdf_thumbnail_scripts($hook) {
    if ($hook === 'post.php' || $hook === 'post-new.php') {
        wp_enqueue_media(); // Load media uploader
        wp_enqueue_script('pdf-thumbnail-js', plugin_dir_url(__FILE__) . 'js/pdf-thumbnail.js', ['jquery'], '1.0', true);
        wp_localize_script('pdf-thumbnail-js', 'pdfThumbnail', [
            'ajax_url' => admin_url('admin-ajax.php'),
        ]);
    }
}

function pdf_thumbnail_styles() {
    wp_enqueue_style('pdf-thumbnail-css', plugin_dir_url(__FILE__) . 'css/pdf-thumbnail.css', [], '1.0');
}

// Settings page for default options
add_action('admin_menu', 'pdf_thumbnail_settings_menu');
function pdf_thumbnail_settings_menu() {
    add_options_page('PDF Thumbnail Settings', 'PDF Thumbnail Settings', 'manage_options', 'pdf-thumbnail-settings', 'pdf_thumbnail_settings_page');
}

// Register settings
add_action('admin_init', 'pdf_thumbnail_register_settings');
function pdf_thumbnail_register_settings() {
    register_setting('pdf_thumbnail_settings_group', 'pdf_thumbnail_defaults');
}

// Settings page callback
function pdf_thumbnail_settings_page() {
    $defaults = get_option('pdf_thumbnail_defaults', [
        'button_text' => 'Download PDF',
        'thumbnail_width' => '212px',
        'title_color' => '#333',
        'title_size' => '14px',
        'button_color' => '#0073aa',
        'button_hover_color' => '#005177',
        'button_text_size' => '12px'
    ]);
    ?>
    <div class="wrap">
        <h1>PDF Thumbnail Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('pdf_thumbnail_settings_group'); ?>
            <?php do_settings_sections('pdf_thumbnail_settings_group'); ?>
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

// AJAX handler for fetching PDF thumbnail, title, and URL
add_action('wp_ajax_fetch_pdf_data', 'fetch_pdf_data');
function fetch_pdf_data() {
    $attachment_id = intval($_POST['attachment_id']);

    if (!$attachment_id) {
        wp_send_json_error(['message' => 'Invalid attachment ID.']);
    }

    $file_url = wp_get_attachment_url($attachment_id);
    $file_title = get_the_title($attachment_id);

    // Get the PDF thumbnail directly from the attachment ID
    $thumbnail_url = wp_get_attachment_image_src($attachment_id, 'medium');

    // Fallback to placeholder if no thumbnail exists
    if (!$thumbnail_url || !isset($thumbnail_url[0])) {
        $thumbnail_url[0] = plugin_dir_url(__FILE__) . 'images/pdf-placeholder.png';
    }

    wp_send_json_success([
        'thumbnail_url' => $thumbnail_url[0],
        'file_url' => $file_url,
        'file_title' => $file_title,
    ]);
}

// Shortcode to display PDF thumbnail, title, and download button with customizable options
add_shortcode('pdf_thumbnail', 'display_pdf_thumbnail');
function display_pdf_thumbnail($atts) {
    $defaults = get_option('pdf_thumbnail_defaults', [
        'button_text' => 'Download PDF',
        'thumbnail_width' => '212px',
        'title_color' => '#333',
        'title_size' => '14px',
        'button_color' => '#0073aa',
        'button_hover_color' => '#005177',
        'button_text_size' => '12px',
    ]);

    // Define fallback values for missing dynamic attributes
    $fallback_values = [
        'title'     => 'PDF File',
        'url'       => '#',
        'thumbnail' => plugin_dir_url(__FILE__) . 'images/pdf-placeholder.png',
    ];

    // Merge defaults and fallbacks into attributes
    $atts = shortcode_atts(array_merge($defaults, $fallback_values), $atts);

    $html = '<div class="pdf-thumbnail-container" style="width: ' . esc_attr($atts['thumbnail_width']) . '; margin: 10px; display: inline-block; text-align: center;">
        <div class="pdf-title" style="color: ' . esc_attr($atts['title_color']) . '; font-size: ' . esc_attr($atts['title_size']) . '; max-width: 100%; word-wrap: break-word; margin-bottom: 5px;">
            ' . esc_html($atts['title']) . '
        </div>
        <a href="' . esc_url($atts['url']) . '" target="_blank">
            <div class="pdf-thumbnail book-style" style="background-image: url(' . esc_url($atts['thumbnail']) . '); width: 212px; height: 300px;"></div>
        </a>
        <a href="' . esc_url($atts['url']) . '" class="pdf-download-button" download 
            style="background-color: ' . esc_attr($atts['button_color']) . '; font-size: ' . esc_attr($atts['button_text_size']) . '; color: #fff; text-decoration: none; padding: 5px 10px; border-radius: 4px; display: inline-block; transition: background-color 0.3s;" 
            onmouseover="this.style.backgroundColor=\'' . esc_js($atts['button_hover_color']) . '\'" 
            onmouseout="this.style.backgroundColor=\'' . esc_js($atts['button_color']) . '\'">
            ' . esc_html($atts['button_text']) . '
        </a>
    </div>';

    return $html;
}
