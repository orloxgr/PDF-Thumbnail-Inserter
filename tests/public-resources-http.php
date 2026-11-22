<?php
/** Actual public viewer/cover routing, input validation and read-only HTTP contracts. */
if ( 'true' !== getenv( 'PTI_TEST_DISPOSABLE' ) || empty( $argv[1] ) ) exit( 'Disposable site required.' );
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! DISABLE_WP_CRON || '127.0.0.1' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) throw new RuntimeException( 'Owned loopback site required.' );
$plugin = PDF_Thumbnail_Inserter::instance(); $assertions = array();
$old_settings = get_option( PDF_Thumbnail_Inserter::OPTION_NAME, false );
$uploads = wp_upload_dir(); $path = $uploads['path'] . '/pti-public-' . bin2hex( random_bytes( 6 ) ) . '-Ελλάδα & δοκιμή.pdf';
copy( __DIR__ . '/fixtures/one-page.pdf', $path );
$id = wp_insert_attachment( array( 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit', 'post_title' => 'Public resource' ), $path );
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Public routing guard', 'post_content' => 'pti-public-routing-marker' ) );
function resource_assert( $ok, $message ) {
    if ( ! $ok ) throw new RuntimeException( $message );
    $GLOBALS['assertions'][] = $message;
}
$request = static function ( $args ) {
    $response = wp_remote_get( add_query_arg( $args, home_url( '/' ) ), array( 'timeout' => 60, 'redirection' => 0 ) );
    if ( is_wp_error( $response ) ) throw new RuntimeException( $response->get_error_message() );
    return $response;
};
try {
    $settings = is_array( $old_settings ) ? $old_settings : array(); $settings['use_default_document_cover'] = 1;
    update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $settings, false );
    $pdf = wp_get_attachment_url( $id ); $before = get_post_meta( $id );
    $viewer = $request( array( PDF_Thumbnail_Inserter::VIEWER_QUERY_VAR => 1, 'file' => rawurlencode( $pdf ) ) );
    resource_assert( 200 === wp_remote_retrieve_response_code( $viewer ) && false !== strpos( wp_remote_retrieve_body( $viewer ), 'id="viewer"' ), 'An anonymous legacy viewer URL still renders the actual viewer for a Greek/ampersand filename' );
    resource_assert( false !== strpos( wp_remote_retrieve_header( $viewer, 'content-security-policy' ), "frame-ancestors 'self'" ), 'The public viewer retains its same-origin CSP' );
    foreach ( array( 'https://example.invalid/foreign.pdf', $uploads['baseurl'] . '/../outside.pdf', array( 'nested' ) ) as $invalid ) {
        $response = $request( array( PDF_Thumbnail_Inserter::VIEWER_QUERY_VAR => 1, 'file' => $invalid ) );
        resource_assert( 404 === wp_remote_retrieve_response_code( $response ), 'The actual viewer rejects a foreign/traversal/nested file argument' );
    }
    $title = 'Ελλάδα & <b>δοκιμή</b> "τίτλος"';
    $cover_args = array( PDF_Thumbnail_Inserter::COVER_QUERY_VAR => 1, 'file' => rawurlencode( $pdf ), 'title' => rawurlencode( $title ), 'shape' => 'PORTRAIT' );
    $cover = $request( $cover_args ); $body = wp_remote_retrieve_body( $cover );
    resource_assert( 200 === wp_remote_retrieve_response_code( $cover ) && false !== strpos( wp_remote_retrieve_header( $cover, 'content-type' ), 'image/svg+xml' ), 'An anonymous legacy cover URL returns a real SVG resource' );
    $svg = new DOMDocument();
    resource_assert( $svg->loadXML( $body ) && '730' === $svg->documentElement->getAttribute( 'width' ), 'The routed cover is valid XML and preserves case-insensitive portrait selection' );
    resource_assert( false !== strpos( $body, 'Ελλάδα &amp; δοκιμή' ) && false === strpos( $body, '<b>' ), 'Routed Greek/title markup is sanitized and escaped for SVG output' );
    foreach ( array( '', array( 'nested' ) ) as $invalid ) {
        $cover_args[ PDF_Thumbnail_Inserter::COVER_QUERY_VAR ] = $invalid;
        resource_assert( 404 === wp_remote_retrieve_response_code( $request( $cover_args ) ), 'A present but invalid cover selector returns 404 rather than a normal page' );
    }
    $normal = $request( array( 'page_id' => $page, 'file' => 'unrelated', 'title' => 'Not a content query' ) );
    resource_assert( 200 === wp_remote_retrieve_response_code( $normal ) && false !== strpos( wp_remote_retrieve_body( $normal ), 'pti-public-routing-marker' ), 'Registering legacy resource parameters does not hijack an ordinary page request' );
    wp_cache_delete( $id, 'post_meta' );
    resource_assert( $before === get_post_meta( $id ) && $settings === get_option( PDF_Thumbnail_Inserter::OPTION_NAME ), 'Public viewer/cover requests do not change attachment metadata or settings' );
    resource_assert( ! get_post_meta( $id, PDF_Thumbnail_Inserter::META_PREVIEW_ID, true ) && ! get_post_meta( $id, PDF_Thumbnail_Inserter::META_PREVIEW_ERROR, true ), 'Public resource access does not generate an image or save an error marker' );
    echo wp_json_encode( array( 'status' => 'PASS', 'wordpress' => get_bloginfo( 'version' ), 'php_client' => PHP_VERSION, 'assertions' => $assertions ), JSON_PRETTY_PRINT ) . "\n";
} finally {
    wp_delete_attachment( $id, true ); wp_delete_post( $page, true );
    foreach ( array( $path, $path . '.pti-preview.lock' ) as $file ) if ( is_file( $file ) ) wp_delete_file( $file );
    if ( false === $old_settings ) delete_option( PDF_Thumbnail_Inserter::OPTION_NAME );
    else update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $old_settings, false );
}
