<?php
/**
 * Destructive fixtures: run ONLY on a disposable WordPress site.
 * Define PTI_TEST_DISPOSABLE=true, optionally PTI_TEST_WP_ROOT and PTI_TEST_ZIP,
 * then include this file from WP-CLI eval-file or a local Playground runner.
 * Run once with PTI_TEST_INSTALL=true, then again in a fresh request without it.
 */
if ( ! defined( 'PTI_TEST_DISPOSABLE' ) || true !== PTI_TEST_DISPOSABLE ) {
    exit( 'Requires an explicitly disposable WordPress installation.' );
}
require ( defined( 'PTI_TEST_WP_ROOT' ) ? PTI_TEST_WP_ROOT : '/wordpress' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
// No WordPress.org update/translation traffic is needed for a local ZIP test.
add_filter( 'pre_http_request', function () { return new WP_Error( 'pti_offline_test', 'Offline integration test' ); } );

function pti_assert( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
    $GLOBALS['pti_assertions'] = ( $GLOBALS['pti_assertions'] ?? 0 ) + 1;
}

$plugin_file = 'pdf-thumbnail-inserter/pdf-thumbnail-inserter.php';
wp_set_current_user( 1 );
if ( defined( 'PTI_TEST_INSTALL' ) && PTI_TEST_INSTALL ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
        deactivate_plugins( $plugin_file );
        pti_assert( true === delete_plugins( array( $plugin_file ) ), 'Could not reset disposable plugin installation' );
    }
    $upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
    $installed = $upgrader->install( PTI_TEST_ZIP );
    pti_assert( true === $installed, 'ZIP installation failed: ' . print_r( $upgrader->skin->result, true ) );
    pti_assert( null === activate_plugin( $plugin_file ), 'Activation failed' );
    pti_assert( is_plugin_active( $plugin_file ), 'Plugin is not active' );
    echo 'PASS: ZIP installation and activation on WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION . "\n";
    return;
}

pti_assert( is_plugin_active( $plugin_file ), 'Plugin must be active at bootstrap' );
$plugin = PDF_Thumbnail_Inserter::instance();
function pti_private( $name, ...$args ) {
    $method = new ReflectionMethod( $GLOBALS['plugin'], $name );
    $method->setAccessible( true );
    return $method->invoke( $GLOBALS['plugin'], ...$args );
}
$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );
pti_assert( $data['Version'] === PDF_Thumbnail_Inserter::VERSION, 'Plugin header and constant mismatch' );
$block = WP_Block_Type_Registry::get_instance()->get_registered( 'pdf-thumbnail-inserter/pdf-thumbnail' );
pti_assert( $block && $block->is_dynamic(), 'Dynamic block is not registered' );
foreach ( array( 'pdf-thumbnail-block-editor', 'pdf-thumbnail-preview-renderer', 'pdf-thumbnail-viewer' ) as $handle ) {
    pti_assert( wp_script_is( $handle, 'registered' ), 'Missing registered script: ' . $handle );
}

// Migration must preserve saved values and must not autoload a duplicate legacy option.
delete_option( PDF_Thumbnail_Inserter::OPTION_NAME );
$legacy = array( 'button_text' => 'Legacy download', 'default_columns_desktop' => 5 );
update_option( PDF_Thumbnail_Inserter::LEGACY_OPTION_NAME, $legacy );
$plugin->register_settings();
pti_assert( $legacy === get_option( PDF_Thumbnail_Inserter::OPTION_NAME ), 'Legacy settings were not preserved' );
global $wpdb;
$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", PDF_Thumbnail_Inserter::OPTION_NAME ) );
pti_assert( in_array( $autoload, array( 'no', 'off', 'auto-off' ), true ), 'Migrated settings unexpectedly autoload' );
$settings = $plugin->sanitize_settings( array( 'button_text' => '<b>Download</b>', 'default_columns_desktop' => 4, 'default_new_tab' => 1 ) );
update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $settings );
pti_assert( 'Download' === get_option( PDF_Thumbnail_Inserter::OPTION_NAME )['button_text'], 'Settings sanitation/save failed' );

$uploads = wp_upload_dir();
$pdf_path = $uploads['path'] . '/pti-integration.pdf';
file_put_contents( $pdf_path, "%PDF-1.1\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 400]/Contents 4 0 R/Resources<<>>>>endobj\n4 0 obj<</Length 28>>stream\n0.2 0.4 0.7 rg 20 20 260 360 re f\nendstream\nendobj\ntrailer<</Root 1 0 R>>\n%%EOF" );
$pdf_url = $uploads['url'] . '/pti-integration.pdf';
$pdf_id = wp_insert_attachment( array( 'post_title' => 'Integration PDF', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), $pdf_path );
pti_assert( ! is_wp_error( $pdf_id ) && $pdf_id > 0, 'PDF attachment fixture failed' );
pti_assert( realpath( $pdf_path ) === realpath( pti_private( 'get_local_upload_pdf_path', $pdf_url ) ), 'Valid upload cannot be resolved' );
foreach ( array( '/../private.pdf', '/%2e%2e/private.pdf', '/%2e%2e%5cprivate.pdf', '/bad%00.pdf' ) as $suffix ) {
    pti_assert( '' === pti_private( 'get_pdfjs_viewer_url', $uploads['baseurl'] . $suffix ), 'Viewer accepted traversal' );
}
$outside = ABSPATH . 'pti-outside.pdf';
file_put_contents( $outside, '%PDF-1.1 outside' );
$symlink = $uploads['path'] . '/pti-symlink.pdf';
if ( symlink( $outside, $symlink ) ) {
    pti_assert( '' === pti_private( 'get_local_upload_pdf_path', $uploads['url'] . '/pti-symlink.pdf' ), 'Symlink escaped uploads' );
    unlink( $symlink );
}
unlink( $outside );
$query_url = $pdf_url . '?download=1&token=a%2Bb';
$viewer_url = pti_private( 'get_pdfjs_viewer_url', $query_url );
parse_str( wp_parse_url( $viewer_url, PHP_URL_QUERY ), $query );
pti_assert( $query_url === $query['file'], 'Viewer URL query did not round-trip through real WordPress' );
$html = do_shortcode( '[pdf_thumbnail_inserter id="' . $pdf_id . '"]' );
pti_assert( false !== strpos( $html, 'data-pdf-url=' ) && false !== strpos( $html, 'Integration PDF' ), 'Shortcode did not render a missing-preview card' );
pti_assert( false !== strpos( do_shortcode( '[pdf_thumbnail id="' . $pdf_id . '"]' ), 'data-pdf-url=' ), 'Legacy shortcode broke' );
pti_assert( false !== strpos( render_block( array( 'blockName' => $block->name, 'attrs' => array( 'id' => $pdf_id ), 'innerBlocks' => array(), 'innerContent' => array() ) ), 'data-pdf-url=' ), 'Dynamic block render failed' );
$settings['use_iframe_pdf_previews'] = 1;
update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $settings );
$embed = '<object class="wp-block-file__embed" data="https://external.test/report.pdf" type="application/pdf"></object>';
pti_assert( $embed === $plugin->replace_pdf_file_block_preview( $embed, array( 'attrs' => array( 'href' => 'https://external.test/report.pdf', 'displayPreview' => true ) ) ), 'Unsupported external embed was replaced by an empty iframe' );

// Real deletion hooks must not cross PDF ownership boundaries.
$image_path = $uploads['path'] . '/pti-preview.png';
copy( WP_PLUGIN_DIR . '/pdf-thumbnail-inserter/assets/images/pdf-placeholder.png', $image_path );
$image_id = wp_insert_attachment( array( 'post_title' => 'Preview', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_parent' => $pdf_id + 9999 ), $image_path );
update_post_meta( $pdf_id, PDF_Thumbnail_Inserter::META_PREVIEW_ID, $image_id );
update_post_meta( $image_id, PDF_Thumbnail_Inserter::META_PARENT_PDF, $pdf_id + 9999 );
$plugin->cleanup_generated_preview( $pdf_id );
pti_assert( null !== get_post( $image_id ) && file_exists( $image_path ), 'Cleanup deleted an unrelated preview' );
wp_update_post( array( 'ID' => $image_id, 'post_parent' => $pdf_id ) );
update_post_meta( $image_id, PDF_Thumbnail_Inserter::META_PARENT_PDF, $pdf_id );
$plugin->cleanup_generated_preview( $pdf_id );
pti_assert( null === get_post( $image_id ) && ! file_exists( $image_path ), 'Owned preview was not deleted' );

// Exercise actual nonce/capability checks and JSON exits, rather than replacing those checks.
define( 'DOING_AJAX', true );
add_filter( 'wp_die_ajax_handler', function () {
    return function ( $message = '' ) { throw new RuntimeException( 'pti-json-exit:' . $message ); };
} );
function pti_ajax( $method, $args, $valid_nonce = true ) {
    $_POST = $args;
    $_REQUEST = array_merge( $args, array( 'nonce' => $valid_nonce ? wp_create_nonce( PDF_Thumbnail_Inserter::NONCE_ACTION ) : 'invalid' ) );
    ob_start();
    try { $GLOBALS['plugin']->$method(); } catch ( RuntimeException $error ) {
        if ( 0 !== strpos( $error->getMessage(), 'pti-json-exit:' ) ) { throw $error; }
    }
    $output = ob_get_clean();
    return json_decode( $output, true );
}
$discovery_ids = array( $pdf_id );
for ( $i = 0; $i < 100; $i++ ) {
    $discovery_ids[] = wp_insert_attachment( array( 'post_title' => 'Pagination ' . $i, 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), $pdf_path );
}
// Scope fixtures so this test can be repeated on the same disposable site.
$scope_discovery = function ( $query ) use ( $discovery_ids ) {
    if ( 'attachment' === $query->get( 'post_type' ) && 'application/pdf' === $query->get( 'post_mime_type' ) ) {
        $query->set( 'post__in', $discovery_ids );
    }
};
add_action( 'pre_get_posts', $scope_discovery );
$first = pti_ajax( 'ajax_list_missing_previews', array( 'page' => 1 ) );
$second = pti_ajax( 'ajax_list_missing_previews', array( 'page' => 2 ) );
pti_assert( $first['success'] && 100 === count( $first['data']['items'] ) && 2 === $first['data']['next_page'], 'First discovery page failed' );
pti_assert( $second['success'] && 1 === count( $second['data']['items'] ) && 0 === $second['data']['next_page'], 'Final discovery page failed' );
pti_assert( ! get_post_meta( $pdf_id, PDF_Thumbnail_Inserter::META_PREVIEW_GENERATED_AT, true ), 'Discovery unexpectedly generated a preview' );
pti_assert( null === pti_ajax( 'ajax_list_missing_previews', array( 'page' => 1 ), false ), 'Invalid nonce was accepted' );
wp_set_current_user( 0 );
$denied = pti_ajax( 'ajax_list_missing_previews', array( 'page' => 1 ) );
pti_assert( false === $denied['success'], 'Anonymous library scan was accepted' );
remove_action( 'pre_get_posts', $scope_discovery );
wp_set_current_user( 1 );
pti_assert( ! pti_private( 'can_save_browser_preview', $pdf_id, true ), 'Automatic saving enabled without setting' );
pti_assert( pti_private( 'can_save_browser_preview', $pdf_id ), 'Admin manual saving was denied' );

// Leave one fixture page available for browser QA; all data lives in the disposable site.
wp_insert_post( array( 'post_title' => 'PDF integration preview', 'post_name' => 'pti-preview', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '[pdf_thumbnail_inserter id="' . $pdf_id . '"]' ) );
$saved = get_option( PDF_Thumbnail_Inserter::OPTION_NAME );
deactivate_plugins( $plugin_file );
pti_assert( ! is_plugin_active( $plugin_file ) && $saved === get_option( PDF_Thumbnail_Inserter::OPTION_NAME ), 'Deactivation changed saved settings' );
pti_assert( null === activate_plugin( $plugin_file ), 'Reactivation failed' );
status_header( 200 );
header( 'Content-Type: text/plain; charset=UTF-8' );
echo 'PASS: ' . $GLOBALS['pti_assertions'] . ' WordPress integration assertions; WP ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION . "\n";
echo 'Browser fixture: ' . get_permalink( get_page_by_path( 'pti-preview' ) ) . "\nViewer fixture: " . $viewer_url . "\n";
