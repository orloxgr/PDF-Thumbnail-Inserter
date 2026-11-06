<?php
/** Run with: php tests/preview-regression.php (no WordPress installation needed). */
if ( 'cli' !== PHP_SAPI ) {
    exit;
}

define( 'ABSPATH', __DIR__ . '/' );
$base_url = getenv( 'PTI_TEST_BASE_URL' ) ?: 'http://127.0.0.1:18765/';
$enqueued = array();
$images = array();
$featured = array();
$failures = array();
$options = array();
$categories = array( (object) array( 'term_id' => 7, 'name' => 'Προσκλήσεις Δημοτικού Συμβουλίου' ) );

// Minimal WordPress boundary doubles. Rendering and listing matching use the plugin itself.
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return $GLOBALS['base_url']; }
function plugin_basename( $file ) { return basename( $file ); }
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return esc_attr( $value ); }
function esc_url_raw( $value ) { return (string) $value; }
function esc_url( $value ) { return esc_attr( $value ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, $args ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_unslash( $value ) { return $value; }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_bloginfo( $key ) { return 'UTF-8'; }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function sanitize_html_class( $value ) { return $value; }
function sanitize_hex_color( $value ) { return preg_match( '/^#[0-9a-f]{6}$/i', $value ) ? $value : null; }
function shortcode_atts( $defaults, $atts, $shortcode = '' ) { return array_merge( $defaults, $atts ); }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function get_the_category( $id ) { return $GLOBALS['categories']; }
function is_category() { return ! empty( $GLOBALS['category_request'] ); }
function is_home() { return false; }
function is_page( $pages ) { return in_array( $GLOBALS['page_slug'] ?? '', (array) $pages, true ); }
function get_queried_object() { return $GLOBALS['queried_category'] ?? null; }
function home_url( $path ) { return $GLOBALS['base_url'] . ltrim( $path, '/' ); }
// WordPress expects new values to be URL-encoded before add_query_arg().
function add_query_arg( $args, $url ) {
    $parts = array();
    foreach ( $args as $key => $value ) $parts[] = $key . '=' . $value;
    return $url . '?' . implode( '&', $parts );
}
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function wp_html_excerpt( $value, $count, $more = null ) {
    $chars = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );
    return count( $chars ) > $count ? implode( '', array_slice( $chars, 0, $count ) ) . $more : $value;
}
function get_post( $id ) { return (object) array( 'ID' => $id, 'post_type' => 'post', 'post_content' => '' ); }
function get_term( $id, $taxonomy ) { return 7 === $id ? (object) array( 'term_id' => 7, 'name' => 'Προσκλήσεις Δημοτικού Συμβουλίου' ) : null; }
function is_wp_error( $value ) { return false; }
function status_header( $status ) { fwrite( STDERR, 'HTTP_STATUS=' . $status . "\n" ); }
function current_user_can( $capability, ...$args ) { return $GLOBALS['capabilities'][ $capability ] ?? true; }
function get_posts( $args ) { return array(); }
function get_transient( $key ) { return false; }
function settings_fields( $group ) {}
function selected( $value, $expected ) { if ( $value === $expected ) echo 'selected'; }
function checked( $value ) { if ( $value ) echo 'checked'; }
function submit_button( $text = 'Save changes', ...$args ) { echo '<button>' . esc_html( $text ) . '</button>'; }
function admin_url( $path ) { return $GLOBALS['base_url'] . $path; }
function wp_nonce_field( $action ) {}
function wp_create_nonce( $action ) { return 'test-nonce'; }
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['localized'][ $name ] = $data; }
function wp_enqueue_style( $handle, ...$args ) { $GLOBALS['enqueued'][ $handle ] = true; }
function wp_enqueue_script( $handle, ...$args ) { $GLOBALS['enqueued'][ $handle ] = true; }
function get_the_title( $id ) { return 'PDF "Preview" & title'; }
function get_permalink( $id ) { return $GLOBALS['base_url'] . 'post/' . $id; }
function url_to_postid( $url ) { return (int) basename( parse_url( $url, PHP_URL_PATH ) ); }
function get_post_type( $id ) { return 'post'; }
function get_post_meta( $id, $key, $single = false ) { return '_thumbnail_id' === $key ? ( $GLOBALS['featured'][ $id ] ?? 0 ) : ( $GLOBALS['metadata'][ $id ][ $key ] ?? 0 ); }
function get_post_mime_type( $id ) { return $GLOBALS['mime_types'][ $id ] ?? 'application/pdf'; }
function wp_get_attachment_image_src( $id, $size ) { return isset( $GLOBALS['images'][ $id ] ) ? array( $GLOBALS['images'][ $id ], 600, 850, true ) : false; }
function wp_get_attachment_url( $id ) { return $GLOBALS['base_url'] . 'sample.pdf'; }
function get_attachment_link( $id ) { return $GLOBALS['base_url'] . 'attachment/' . $id; }
function wp_get_attachment_image_url( $id, $size ) { return $GLOBALS['images'][ $id ] ?? false; }
function wp_get_attachment_metadata( $id, $unfiltered = false ) { return array(); }
function get_attached_file( $id, $unfiltered = false ) { return ''; }
function wp_get_upload_dir() { return array(); }

require getenv( 'PTI_TEST_PLUGIN' ) ?: dirname( __DIR__ ) . '/pdf-thumbnail-inserter.php';
$plugin = PDF_Thumbnail_Inserter::instance();
function call_private( $name, ...$args ) {
    $method = new ReflectionMethod( $GLOBALS['plugin'], $name );
    $method->setAccessible( true );
    return $method->invoke( $GLOBALS['plugin'], ...$args );
}
function check( $condition, $message ) {
    if ( ! $condition ) {
        $GLOBALS['failures'][] = $message;
    }
}

// Seed only the media resolver boundary to isolate WGL HTML matching.
$cache = new ReflectionProperty( $plugin, 'pdf_featured_image_cache' );
$cache->setAccessible( true );
$cache->setValue( $plugin, array(
    '101:' . md5( wp_json_encode( 'large' ) ) => array( 'id' => 0, 'url' => '', 'pdf_url' => $base_url . 'sample.pdf' ),
    '102:' . md5( wp_json_encode( 'large' ) ) => array( 'id' => 102, 'url' => $base_url . 'saved.jpg', 'pdf_url' => $base_url . 'sample.pdf' ),
    '103:' . md5( wp_json_encode( 'large' ) ) => array( 'id' => 0, 'url' => '', 'pdf_url' => '' ),
) );
$featured[104] = 900;
function listing_card( $id, $heading = 'h2' ) {
    // Structure reproduced from /nea/ and category archives, including the nested content-mid div.
    return '<div class="blog-post format-standard format-no_featured"><div class="blog-post_wrapper">'
        . '<div class="blog-post_content"><div class="blog-post_cats">Category</div>'
        . '<div class="blog-post_content-mid"><' . $heading . ' class="blog-post_title" style="margin-bottom:16px">'
        . '<a href="' . $GLOBALS['base_url'] . 'post/' . $id . '">Post ' . $id . '</a></' . $heading . '>'
        . '<p>Excerpt</p></div></div></div></div>';
}
foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span' ) as $heading ) {
    $html = $plugin->filter_listing_output( listing_card( 101, $heading ) );
    check( 1 === substr_count( $html, 'data-pdf-url=' ), "$heading listing inserts a browser preview" );
    check( false === strpos( $html, 'format-no_featured' ), "$heading listing updates theme media classes" );
    check( $html === $plugin->filter_listing_output( $html ), "$heading listing is idempotent" );
}
$combined = listing_card( 103 ) . listing_card( 104 ) . listing_card( 101 ) . listing_card( 102 );
$listing_html = $plugin->filter_listing_output( $combined );
check( 1 === substr_count( $listing_html, 'data-pdf-url=' ), 'Multiple cards use the correct PDF' );
check( 1 === substr_count( $listing_html, 'src="' . $base_url . 'saved.jpg"' ), 'Saved listing images remain preferred' );
check( false !== strpos( $listing_html, listing_card( 103 ) ), 'Posts without PDFs remain unchanged' );
check( false !== strpos( $listing_html, listing_card( 104 ) ), 'Real featured images remain unchanged' );

$atts = call_private( 'get_shortcode_atts', array() );
$card_html = call_private( 'render_pdf_card', 42, $atts );
check( 1 === substr_count( $card_html, 'data-pdf-url=' ), 'Attachment card renders a PDF canvas when no image exists' );
check( false !== strpos( $card_html, 'pti-pdf-canvas-preview pti-card__thumb' ), 'Canvas uses card dimensions' );
check( false === strpos( $card_html, 'wp-post-image' ), 'Card canvas does not use theme featured-image classes' );
check( false !== strpos( $card_html, '&quot;Preview&quot; &amp; title' ), 'Canvas title is escaped' );
check( isset( $enqueued['pdf-thumbnail-preview-renderer'] ), 'Cards enqueue the renderer on single posts' );
$images[42] = $base_url . 'saved.jpg';
$saved = call_private( 'render_pdf_card', 42, $atts );
check( false === strpos( $saved, 'data-pdf-url=' ) && false !== strpos( $saved, 'saved.jpg' ), 'Existing PDF image is reused' );
$custom_atts = array_merge( $atts, array( 'thumbnail' => $base_url . 'custom.jpg' ) );
$custom = call_private( 'render_pdf_card', 42, $custom_atts );
check( false === strpos( $custom, 'data-pdf-url=' ) && false !== strpos( $custom, 'custom.jpg' ), 'Custom image is preserved' );
unset( $images[42] );
$static = call_private( 'render_pdf_card', 42, array_merge( $atts, array( 'link_to' => 'none' ) ) );
check( false !== strpos( $static, 'data-pdf-url=' ) && false === strpos( $static, '<a ' ), 'Unlinked PDF cards still render a preview' );
$legacy_atts = array_merge( $atts, array( 'url' => $base_url . 'sample.pdf?download=1', 'title' => 'URL card' ) );
$legacy = call_private( 'render_legacy_url_card', $legacy_atts );
check( 1 === substr_count( $legacy, 'data-pdf-url=' ), 'Legacy URL cards support the PDF fallback' );
$gutenberg = call_private( 'render_gutenberg_file_card', $base_url . 'sample.pdf', 'Gutenberg PDF', PDF_THUMBNAIL_INSERTER_URL . 'assets/images/pdf-placeholder.png' );
check( 1 === substr_count( $gutenberg, 'data-pdf-url=' ), 'Converted Gutenberg cards support the PDF fallback' );
$document = call_private( 'render_gutenberg_file_card', $base_url . 'sample.docx', 'Word document', PDF_THUMBNAIL_INSERTER_URL . 'assets/images/doc-placeholder.png' );
check( false === strpos( $document, 'data-pdf-url=' ) && false !== strpos( $document, 'doc-placeholder.png' ), 'Word document cards keep their icon' );

// New insertion defaults must not change the rendering defaults of old content.
$options[PDF_Thumbnail_Inserter::OPTION_NAME] = array(
    'use_default_document_cover' => 1, 'document_cover_label' => 'Έγγραφο',
    'button_color' => '#1a437c', 'title_color' => '#234567',
    'default_columns_widescreen' => 6, 'default_columns_desktop' => 4,
    'default_columns_tablet' => 3, 'default_columns_mobile' => 2,
    'listing_pages' => 'news, 123',
);
$insert_defaults = call_private( 'get_insert_column_defaults' );
check( array( 'columns_widescreen' => 6, 'columns_desktop' => 4, 'columns_laptop' => 3, 'columns_mobile' => 2 ) === $insert_defaults, 'New inserts use all four saved column defaults' );
$old_atts = call_private( 'get_shortcode_atts', array() );
$old_style = call_private( 'get_grid_style', $old_atts );
check( false !== strpos( $old_style, '--pti-columns-desktop:3' ) && false !== strpos( $old_style, '--pti-columns-widescreen:3' ) && false !== strpos( $old_style, '--pti-columns-laptop:2' ) && false !== strpos( $old_style, '--pti-columns-mobile:1' ), 'Old content with omitted columns keeps its original layout' );
$old_custom = call_private( 'get_grid_style', array_merge( $old_atts, array( 'columns_desktop' => 5, 'columns_laptop' => 4 ) ) );
check( false !== strpos( $old_custom, '--pti-columns-widescreen:5' ) && false !== strpos( $old_custom, '--pti-columns-laptop:4' ), 'Old explicit columns apply to widescreen too' );
$new_style = call_private( 'get_grid_style', array_merge( $old_atts, $insert_defaults ) );
check( false !== strpos( $new_style, '--pti-columns-widescreen:6' ), 'New widescreen override renders independently' );
$page_slug = 'news';
check( call_private( 'is_news_page_request' ), 'Listing slugs are configurable' );
$page_slug = 'nea';
unset( $_SERVER['REQUEST_URI'] );
check( ! call_private( 'is_news_page_request' ), 'Old site slug is not forced after changing settings' );
$_SERVER['REQUEST_URI'] = '/news/page/2/';
check( call_private( 'is_news_page_request' ), 'Configured listing pagination works' );
unset( $_SERVER['REQUEST_URI'] );
$no_pdf = call_private( 'get_pdf_featured_image_fallback_data', 999, 'large' );
check( '' === $no_pdf['url'] && '' === $no_pdf['pdf_url'], 'Posts without a PDF never receive a fake cover' );
$resolver = new ReflectionProperty( $plugin, 'first_pdf_resolver_cache' );
$resolver->setAccessible( true );
$resolver->setValue( $plugin, array(
    '1001:' . md5( wp_json_encode( 'large' ) ) => array( 'pdf_url' => $base_url . 'sample.pdf', 'attachment_id' => 42, 'preview_url' => '', 'preview_id' => 0 ),
    '1002:' . md5( wp_json_encode( 'large' ) ) => array( 'pdf_url' => $base_url . 'sample.pdf', 'attachment_id' => 42, 'preview_url' => $base_url . 'actual-preview.jpg', 'preview_id' => 42 ),
) );
$fallback_cover = call_private( 'get_pdf_featured_image_fallback_data', 1001, 'large' );
parse_str( parse_url( $fallback_cover['url'], PHP_URL_QUERY ), $cover_query );
check( 0 === $fallback_cover['id'] && '1' === $cover_query['pdf_thumbnail_inserter_cover'] && get_the_title( 42 ) === $cover_query['title'] && $base_url . 'sample.pdf' === $cover_query['file'], 'Listing cover requires a PDF without a preview and uses the PDF title' );
$real_preview = call_private( 'get_pdf_featured_image_fallback_data', 1002, 'large' );
check( $base_url . 'actual-preview.jpg' === $real_preview['url'], 'An actual PDF preview takes priority over the fake cover' );
$covered_card = call_private( 'render_pdf_card', 42, $atts, array( 'button_text' => 'Λήψη PDF' ) );
check( false !== strpos( $covered_card, 'pdf_thumbnail_inserter_cover=1' ) && false !== strpos( $covered_card, 'shape=portrait' ) && false === strpos( $covered_card, 'data-pdf-url=' ), 'A PDF card without a preview uses a portrait title cover when enabled' );
check( 'placeholder' === call_private( 'get_pdf_preview_data', 42, 'medium' )['preview_source'], 'A fake cover does not mark the PDF as having a real preview or prevent browser generation' );
$images[42] = $base_url . 'saved.jpg';
$existing_card = call_private( 'render_pdf_card', 42, $atts );
check( false !== strpos( $existing_card, 'saved.jpg' ) && false === strpos( $existing_card, 'pdf_thumbnail_inserter_cover=' ), 'Existing card previews are not replaced when covers are enabled' );
unset( $images[42] );
$custom_card = call_private( 'render_pdf_card', 42, $custom_atts );
check( false !== strpos( $custom_card, 'custom.jpg' ) && false === strpos( $custom_card, 'pdf_thumbnail_inserter_cover=' ), 'Custom card previews take priority over title covers' );
$covered_file = call_private( 'render_gutenberg_file_card', $base_url . 'sample.pdf', 'Τίτλος PDF Gutenberg', PDF_THUMBNAIL_INSERTER_URL . 'assets/images/pdf-placeholder.png' );
check( false !== strpos( $covered_file, 'pdf_thumbnail_inserter_cover=1' ), 'Converted Gutenberg file cards use title covers too' );
check( '' === call_private( 'get_document_cover_url', '' ) && '' === call_private( 'get_document_cover_url', $base_url . 'file.docx' ), 'Missing PDFs and Word documents do not receive PDF covers' );
parse_str( parse_url( call_private( 'get_document_cover_url', $base_url . 'agenda_signed.pdf' ), PHP_URL_QUERY ), $filename_query );
check( 'agenda signed' === $filename_query['title'], 'URL-only PDFs use the filename when no title is provided' );
parse_str( parse_url( call_private( 'get_document_cover_url', $base_url . 'sample.pdf?a=1&b=2', 'Τίτλος A&B' ), PHP_URL_QUERY ), $special_query );
check( $base_url . 'sample.pdf?a=1&b=2' === $special_query['file'] && 'Τίτλος A&B' === $special_query['title'], 'PDF URLs and titles with ampersands survive WordPress query encoding' );
$cover_svg = call_private( 'render_document_cover_svg', 'Πρόσκληση συνεδρίασης Δημοτικού Συμβουλίου' );
check( false !== strpos( $cover_svg, '#1a437c' ) && false !== strpos( $cover_svg, '#234567' ) && false !== strpos( $cover_svg, 'Έγγραφο' ), 'Covers reuse existing plugin colors and the saved label' );
$portrait_svg = call_private( 'render_document_cover_svg', 'Πρόσκληση συνεδρίασης Δημοτικού Συμβουλίου', 'portrait' );
check( false !== strpos( $portrait_svg, 'viewBox="0 0 730 960"' ), 'Card covers have portrait dimensions' );
$escaped_svg = call_private( 'render_document_cover_svg', 'R&D <script>alert(1)</script>' );
check( false === strpos( $escaped_svg, '<script>' ) && false !== strpos( $escaped_svg, 'R&amp;D' ), 'PDF cover titles are escaped safely' );
$sanitized = $plugin->sanitize_settings( array( 'default_columns_widescreen' => 99, 'default_columns_mobile' => 0, 'document_cover_label' => '<b>Έγγραφο</b>' ) );
check( 4 === $sanitized['default_columns_widescreen'] && 1 === $sanitized['default_columns_mobile'] && 'Έγγραφο' === $sanitized['document_cover_label'], 'Settings reject out-of-range columns and sanitize cover labels' );
$options = array();
check( '' === call_private( 'get_document_cover_url', $base_url . 'sample.pdf', 'PDF title' ), 'Document covers remain opt-in' );
ob_start();
$plugin->render_settings_page();
$settings_html = ob_get_clean();
foreach ( array( 'default_columns_widescreen', 'default_columns_desktop', 'default_columns_tablet', 'default_columns_mobile', 'use_default_document_cover', 'document_cover_label', 'listing_pages', 'auto_browser_preview_on_upload' ) as $field ) {
    check( false !== strpos( $settings_html, '[' . $field . ']' ), 'Settings form includes ' . $field );
}

check( ! call_private( 'can_save_browser_preview', 42, true ), 'Automatic saves are disabled by default, including for administrators' );
check( call_private( 'can_save_browser_preview', 42 ), 'Admin manual maintenance remains available when automation is off' );
$plugin->enqueue_browser_upload_assets();
check( empty( $enqueued['pdf-thumbnail-browser-upload'] ), 'Upload listener is not loaded when disabled' );
$capabilities = array( 'manage_options' => false );
check( ! call_private( 'can_save_browser_preview', 42 ), 'Non-admin cannot save previews when automation is off' );
$options[PDF_Thumbnail_Inserter::OPTION_NAME] = array( 'auto_browser_preview_on_upload' => 1 );
check( call_private( 'can_save_browser_preview', 42, true ), 'An authorized uploader can save its PDF preview when enabled' );
$plugin->enqueue_browser_upload_assets();
check( isset( $enqueued['pdf-thumbnail-browser-upload'] ) && ! empty( $localized['pdfThumbnailBrowserUpload']['enabled'] ), 'Enabled setting enqueues and configures the upload listener' );
$capabilities['edit_post'] = false;
check( ! call_private( 'can_save_browser_preview', 42, true ), 'Automatic saves require permission to edit the PDF' );
$capabilities = array( 'upload_files' => false );
check( ! call_private( 'can_save_browser_preview', 42, true ), 'Automatic saves require upload permission' );
$capabilities = array();
$metadata[42][PDF_Thumbnail_Inserter::META_PREVIEW_ID] = 99;
$mime_types[99] = 'image/jpeg';
$images[99] = $base_url . 'browser-preview.jpg';
$pdf_attachment = (object) array( 'ID' => 42, 'post_mime_type' => 'application/pdf' );
$media_response = $plugin->add_browser_preview_to_media_response( array( 'url' => $base_url . 'sample.pdf' ), $pdf_attachment, array() );
check( $base_url . 'browser-preview.jpg' === $media_response['sizes']['thumbnail']['url'] && $base_url . 'sample.pdf' === $media_response['url'], 'Media Library response shows the stored browser preview and retains the PDF URL' );
$core_response = array( 'sizes' => array( 'full' => array( 'url' => $base_url . 'core.jpg' ) ) );
check( $core_response === $plugin->add_browser_preview_to_media_response( $core_response, $pdf_attachment, array() ), 'Existing WordPress media previews are never replaced' );
$sanitized = $plugin->sanitize_settings( array( 'auto_browser_preview_on_upload' => 1 ) );
check( 1 === $sanitized['auto_browser_preview_on_upload'] && 0 === $plugin->sanitize_settings( array() )['auto_browser_preview_on_upload'], 'Automatic upload setting can be enabled and disabled' );
$options = $metadata = $mime_types = array();
unset( $images[99] );

if ( $failures ) {
    fwrite( STDERR, implode( "\n", $failures ) . "\n" );
    exit( 1 );
}
if ( in_array( '--cover', $argv, true ) || in_array( '--cover-disabled', $argv, true ) ) {
    $options[PDF_Thumbnail_Inserter::OPTION_NAME] = array( 'use_default_document_cover' => in_array( '--cover', $argv, true ) );
    $_GET[PDF_Thumbnail_Inserter::COVER_QUERY_VAR] = 1;
    if ( ( $argv[2] ?? '' ) !== 'missing' ) $_GET['file'] = $base_url . ( $argv[2] ?? 'sample.pdf' );
    $_GET['title'] = $argv[3] ?? 'Πρόσκληση συνεδρίασης Δημοτικού Συμβουλίου';
    $_GET['shape'] = $argv[4] ?? 'landscape';
    $plugin->maybe_render_document_cover();
} elseif ( in_array( '--grid', $argv, true ) ) {
    echo '<!doctype html><meta charset="utf-8"><title>Responsive columns QA</title>';
    foreach ( array( 1700, 1400, 1000, 500 ) as $width ) {
        $inner = '<link rel="stylesheet" href="/assets/css/pdf-thumbnail.css?v=' . filemtime( dirname( __DIR__ ) . '/assets/css/pdf-thumbnail.css' ) . '">';
        $inner .= '<div class="pti-grid-wrap" style="' . esc_attr( $new_style ) . '"><div class="pti-grid">' . str_repeat( '<div>PDF</div>', 6 ) . '</div></div>';
        $inner .= '<script>addEventListener("load",function(){frameElement.dataset.columns=getComputedStyle(document.querySelector(".pti-grid")).gridTemplateColumns.split(" ").length;});</script>';
        echo '<iframe title="' . $width . 'px" width="' . $width . '" height="90" srcdoc="' . esc_attr( $inner ) . '"></iframe>';
    }
} elseif ( in_array( '--settings', $argv, true ) ) {
    echo '<!doctype html><meta charset="utf-8"><style>body{font:16px Arial;padding:24px;background:#f0f0f1}th{text-align:left;vertical-align:top;width:300px}td{padding-bottom:20px}input[type=number]{width:60px}input[type=text]{min-width:220px}</style>' . $settings_html;
} elseif ( in_array( '--svg', $argv, true ) ) {
    echo $cover_svg;
} elseif ( in_array( '--html', $argv, true ) ) {
    echo '<!doctype html><meta charset="utf-8"><title>PDF preview regression</title>';
    echo '<link rel="stylesheet" href="/assets/css/pdf-thumbnail.css?v=' . filemtime( dirname( __DIR__ ) . '/assets/css/pdf-thumbnail.css' ) . '">';
    echo '<style>body{font-family:Arial;padding:24px}.blog-post{max-width:640px}</style>';
    echo '<h1>PDF previews</h1><h2>PDF card</h2><div class="pti-grid-wrap">' . $card_html . '</div>';
    echo '<h2>Listing with h2 title</h2>' . $plugin->filter_listing_output( listing_card( 101 ) );
    echo '<h2>PDF without preview: title cover</h2><div style="max-width:640px">' . $cover_svg . '</div>';
    echo '<script type="module" src="/assets/js/pdf-thumbnail-preview.js"></script>';
} elseif ( in_array( '--cover-html', $argv, true ) ) {
    echo '<!doctype html><meta charset="utf-8"><title>PDF title cover</title><link rel="stylesheet" href="/assets/css/pdf-thumbnail.css?v=' . filemtime( dirname( __DIR__ ) . '/assets/css/pdf-thumbnail.css' ) . '">';
    echo '<style>body{font:16px Arial;margin:24px}.pti-grid-wrap{max-width:420px}</style><h1>PDF χωρίς προεπισκόπηση</h1><div class="pti-grid-wrap">' . $covered_card . '</div>';
} else {
    echo "PASS: title elements, card previews, PDF title covers, column compatibility, settings and assets.\n";
}
