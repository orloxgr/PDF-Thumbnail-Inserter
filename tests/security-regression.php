<?php
/** Run with php tests/security-regression.php. Uses real files and the plugin with WP boundary doubles. */
if ( 'cli' !== PHP_SAPI ) {
    exit;
}
require __DIR__ . '/preview-regression.php';
set_error_handler( function ( $severity, $message, $file, $line ) {
    throw new ErrorException( $message, 0, $severity, $file, $line );
} );

class WP_Error {
    private $message;
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
class Test_Json_Response extends Exception {
    public $payload;
    public $status;
    public function __construct( $success, $data, $status ) {
        $this->payload = array( 'success' => $success, 'data' => $data );
        $this->status = $status;
    }
}
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function wp_getimagesize( $path ) { return getimagesize( $path ); }
function wp_delete_attachment( $id, $force ) { $GLOBALS['deleted'][] = $id; return get_post( $id ); }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['metadata'][ $id ][ $key ] ); }
function check_ajax_referer( $action, $key ) {
    if ( ( $_POST[ $key ] ?? '' ) !== 'valid' ) {
        throw new Test_Json_Response( false, array(), 403 );
    }
}
function wp_send_json_success( $data ) { throw new Test_Json_Response( true, $data, 200 ); }
function wp_send_json_error( $data, $status ) { throw new Test_Json_Response( false, $data, $status ); }
function ajax_call( $method ) {
    try { $GLOBALS['plugin']->$method(); } catch ( Test_Json_Response $response ) { return $response; }
    throw new RuntimeException( 'AJAX did not return JSON' );
}

$fixture = sys_get_temp_dir() . '/pti-security-' . bin2hex( random_bytes( 6 ) );
mkdir( $fixture );
mkdir( $fixture . '/uploads' );
$uploads = array( 'basedir' => $fixture . '/uploads', 'baseurl' => 'https://example.test/wp-content/uploads' );
$created = array();
function fixture_file( $relative, $contents ) {
    $path = $GLOBALS['fixture'] . '/' . $relative;
    file_put_contents( $path, $contents );
    $GLOBALS['created'][] = $path;
    return $path;
}
try {
    $pdf = fixture_file( 'uploads/δοκιμή & agenda.pdf', '%PDF-1.4 test' );
    fixture_file( 'outside.pdf', '%PDF-1.4 outside' );
    fixture_file( 'uploads/broken.pdf', '%PDF-1.4 broken preview' );
    fixture_file( 'uploads/broken-pdf.jpg', 'not an image' );
    fixture_file( 'uploads/δοκιμή & agenda-pdf.png', base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4c8AAAAASUVORK5CYII=' ) );
    $url = $uploads['baseurl'] . '/' . rawurlencode( 'δοκιμή & agenda.pdf' );
    check( wp_normalize_path( realpath( $pdf ) ) === call_private( 'get_local_upload_pdf_path', $url ), 'Encoded Greek filenames and ampersands resolve locally' );
    check( false !== strpos( call_private( 'get_pdf_preview_url_from_file_url', $url ), rawurlencode( 'δοκιμή & agenda-pdf.png' ) ), 'Valid local preview URLs encode each filename segment' );
    check( '' === call_private( 'get_pdf_preview_url_from_file_url', $uploads['baseurl'] . '/broken.pdf' ), 'Corrupt image candidates are never returned as previews' );
    foreach ( array(
        $uploads['baseurl'] . '/../outside.pdf',
        $uploads['baseurl'] . '/%2e%2e/outside.pdf',
        $uploads['baseurl'] . '/%2e%2e%5coutside.pdf',
        $uploads['baseurl'] . '/bad%00.pdf',
        $uploads['baseurl'] . '/missing.pdf',
        str_replace( 'example.test', 'attacker.test', $url ),
        str_replace( 'example.test', 'example.test:9999', $url ),
        str_replace( 'example.test', 'user@example.test', $url ),
        str_replace( 'https:', 'ftp:', $url ),
        $uploads['baseurl'] . '-other/example.pdf',
    ) as $invalid ) {
        check( '' === call_private( 'get_local_upload_pdf_path', $invalid ), 'Reject unsafe local path: ' . $invalid );
        check( '' === call_private( 'get_pdfjs_viewer_url', $invalid ), 'Reject unsafe viewer URL: ' . $invalid );
    }
    $query_url = $url . '?download=1&token=a%2Bb';
    parse_str( parse_url( call_private( 'get_pdfjs_viewer_url', $query_url ), PHP_URL_QUERY ), $viewer_query );
    check( $query_url === $viewer_query['file'], 'Viewer preserves the complete URL and nested query encoding' );

    $metadata[42][PDF_Thumbnail_Inserter::META_PREVIEW_ID] = 99;
    $metadata[99][PDF_Thumbnail_Inserter::META_PARENT_PDF] = 43;
    $posts[99] = (object) array( 'ID' => 99, 'post_type' => 'attachment', 'post_parent' => 43 );
    $mime_types[99] = 'image/jpeg';
    $deleted = array();
    $plugin->cleanup_generated_preview( 42 );
    check( array() === $deleted, 'Deleting a PDF never deletes another PDF child image via stale metadata' );
    $metadata[99][PDF_Thumbnail_Inserter::META_PARENT_PDF] = 42;
    $plugin->cleanup_generated_preview( 42 );
    check( array() === $deleted, 'Ownership marker alone cannot override the actual attachment parent' );
    $posts[99]->post_parent = 42;
    $mime_types[99] = 'application/pdf';
    $plugin->cleanup_generated_preview( 42 );
    check( array() === $deleted, 'A PDF cannot be deleted as a preview' );
    $mime_types[99] = 'image/jpeg';
    $plugin->cleanup_generated_preview( 42 );
    check( array( 99 ) === $deleted, 'Deleting a PDF cleans up its own generated image' );
    check( empty( $metadata[42][PDF_Thumbnail_Inserter::META_PREVIEW_ID] ), 'Cleanup removes the parent reference' );

    $query_posts = function ( $args ) {
        $GLOBALS['queries'][] = $args;
        return 1 === ( $args['paged'] ?? 1 ) ? range( 100, 199 ) : array( 200 );
    };
    $queries = array();
    $_POST = array( 'nonce' => 'valid', 'page' => 1 );
    $response = ajax_call( 'ajax_list_missing_previews' );
    check( 100 === $queries[0]['posts_per_page'] && 2 === $response->payload['data']['next_page'], 'Discovery bounds the scan to 100 PDFs and provides continuation' );
    $_POST['page'] = 2;
    $response = ajax_call( 'ajax_list_missing_previews' );
    check( 0 === $response->payload['data']['next_page'], 'Discovery terminates after the final page' );
    unset( $_POST['page'] );
    ajax_call( 'ajax_list_missing_previews' );
    check( -1 === $queries[2]['posts_per_page'], 'Legacy unpaged discovery remains available' );
    foreach ( array( 'ajax_fetch_pdf_data', 'ajax_save_browser_preview' ) as $method ) {
        foreach ( array( array( 42 ), array( 'nested' => array( 42 ) ) ) as $invalid_id ) {
            $_POST['attachment_id'] = $invalid_id;
            $response = ajax_call( $method );
            check( 400 === $response->status && 'Invalid attachment ID.' === $response->payload['data']['message'], 'Malformed attachment ID is rejected before object access: ' . $method );
        }
    }
    unset( $_POST['attachment_id'] );
    $capabilities = array( 'manage_options' => false );
    check( 403 === ajax_call( 'ajax_list_missing_previews' )->status, 'Only administrators can scan the library' );
    $capabilities = array();
    $_POST['nonce'] = 'invalid';
    check( 403 === ajax_call( 'ajax_list_missing_previews' )->status, 'Discovery requires a valid nonce' );
} finally {
    foreach ( array_reverse( $created ) as $path ) { unlink( $path ); }
    rmdir( $fixture . '/uploads' );
    rmdir( $fixture );
}
restore_error_handler();
if ( $failures ) {
    fwrite( STDERR, implode( "\n", $failures ) . "\n" );
    exit( 1 );
}
echo "PASS: local upload boundaries, viewer URL round trips, corrupt previews, ownership, paginated discovery and permissions.\n";
