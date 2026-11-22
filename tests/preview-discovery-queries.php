<?php
/** Real AJAX discovery query budget on a disposable local WordPress site. */
if ( 'cli' !== PHP_SAPI || 'true' !== getenv( 'PTI_TEST_DISPOSABLE' ) || empty( $argv[1] ) ) {
    exit( 'Requires CLI, PTI_TEST_DISPOSABLE=true and a disposable WordPress root.' );
}
define( 'DOING_AJAX', true );
require_once rtrim( $argv[1], '/\\' ) . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) throw new RuntimeException( 'Local test site required' );
wp_set_current_user( 1 );
$uploads = wp_upload_dir();
wp_mkdir_p( $uploads['path'] );
$path = $uploads['path'] . '/pti-query-' . bin2hex( random_bytes( 6 ) ) . '.pdf';
file_put_contents( $path, '%PDF-1.4 discovery does not decode this file' );
$ids = array();
$scope = null;
try {
    for ( $i = 0; $i < 100; $i++ ) {
        $ids[] = wp_insert_attachment( array( 'post_title' => 'Discovery ' . $i, 'post_status' => 'inherit', 'post_mime_type' => 'application/pdf' ), $path );
    }
    $scope = function ( $query ) use ( $ids ) {
        if ( 'attachment' === $query->get( 'post_type' ) && 'application/pdf' === $query->get( 'post_mime_type' ) ) $query->set( 'post__in', $ids );
    };
    add_action( 'pre_get_posts', $scope );
    foreach ( $ids as $id ) clean_post_cache( $id );
    $_POST = array( 'page' => 1 );
    $_REQUEST = array( 'nonce' => wp_create_nonce( PDF_Thumbnail_Inserter::NONCE_ACTION ) );
    add_filter( 'wp_die_ajax_handler', function () {
        return function () { throw new RuntimeException( 'pti-query-json-complete' ); };
    } );
    global $wpdb;
    $before = $wpdb->num_queries;
    ob_start();
    try {
        PDF_Thumbnail_Inserter::instance()->ajax_list_missing_previews();
    } catch ( RuntimeException $error ) {
        if ( 'pti-query-json-complete' !== $error->getMessage() ) throw $error;
    }
    $response = json_decode( ob_get_clean(), true );
    $queries = $wpdb->num_queries - $before;
    if ( empty( $response['success'] ) || 100 !== count( $response['data']['items'] ) ) throw new RuntimeException( 'Discovery lost fixture PDFs' );
    echo wp_json_encode( array( 'wp' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'attachments' => 100, 'cold_queries' => $queries, 'budget' => 10 ) ) . "\n";
    if ( $queries > 10 ) throw new RuntimeException( 'Paged discovery performs per-attachment database queries: ' . $queries );
    echo "PASS: 100 cold attachments discovered within constant query budget, without image generation.\n";
} finally {
    if ( $scope ) remove_action( 'pre_get_posts', $scope );
    foreach ( $ids as $id ) wp_delete_attachment( $id, true );
    if ( is_file( $path ) ) wp_delete_file( $path );
}
