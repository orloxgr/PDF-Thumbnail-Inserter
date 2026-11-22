<?php
/** Measure real legacy filename resolution and invalidation on a disposable site. */
if ( 'true' !== getenv( 'PTI_TEST_DISPOSABLE' ) || empty( $argv[1] ) ) exit( 'Disposable CLI site required.' );
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! DISABLE_WP_CRON ) throw new RuntimeException( 'Local site required.' );
$plugin = PDF_Thumbnail_Inserter::instance();
$method = new ReflectionMethod( $plugin, 'get_pdf_file_data_from_loose_reference_title' );
$method->setAccessible( true );
$uploads = wp_upload_dir();
$stem = 'alpha-bravo-charlie-delta-foxtrot-' . bin2hex( random_bytes( 6 ) );
$path = $uploads['path'] . '/' . $stem . '.pdf';
copy( __DIR__ . '/fixtures/one-page.pdf', $path );
$id = wp_insert_attachment( array( 'post_title' => 'Different display title', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), $path );
$statements = array();
$observe = function ( $sql ) use ( &$statements ) { $statements[] = $sql; return $sql; };
try {
    wp_cache_flush();
    add_filter( 'query', $observe );
    $cold = $method->invoke( $plugin, $stem );
    $cold_count = count( $statements );
    $cold_meta_scans = count( array_filter( $statements, function ( $sql ) { return false !== strpos( $sql, 'meta_value' ) && false !== strpos( $sql, 'LIKE' ); } ) );
    $statements = array();
    $warm = $method->invoke( $plugin, $stem );
    $warm_count = count( $statements );
    remove_filter( 'query', $observe );
    if ( empty( $cold['attachment_id'] ) || $id !== $cold['attachment_id'] || $id !== $warm['attachment_id'] ) throw new RuntimeException( 'Filename-only lookup failed.' );
    if ( ! in_array( '--baseline', $argv, true ) && ( $cold_count > 5 || $warm_count > 0 || $cold_meta_scans > 1 ) ) throw new RuntimeException( 'Legacy lookup exceeds its measured query/scan budget: ' . wp_json_encode( array( $cold_count, $warm_count, $cold_meta_scans ) ) );
    update_post_meta( $id, '_wp_attached_file', dirname( get_post_meta( $id, '_wp_attached_file', true ) ) . '/completely-renamed.pdf' );
    $removed = $method->invoke( $plugin, $stem );
    if ( ! empty( $removed['attachment_id'] ) ) throw new RuntimeException( 'Changing the filename left a stale cached match.' );
    update_attached_file( $id, $path );
    $restored = $method->invoke( $plugin, $stem );
    if ( empty( $restored['attachment_id'] ) || $id !== $restored['attachment_id'] ) throw new RuntimeException( 'A cached miss was not invalidated by a filename update.' );
    echo wp_json_encode( array( 'status' => 'PASS', 'mode' => in_array( '--baseline', $argv, true ) ? 'baseline' : 'regression', 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'cold_queries' => $cold_count, 'warm_queries' => $warm_count, 'cold_metadata_like_scans' => $cold_meta_scans, 'invalidation' => 'PASS' ), JSON_PRETTY_PRINT ) . "\n";
} finally {
    remove_filter( 'query', $observe );
    update_attached_file( $id, $path );
    wp_delete_attachment( $id, true );
    if ( is_file( $path . '.pti-preview.lock' ) ) wp_delete_file( $path . '.pti-preview.lock' );
}
