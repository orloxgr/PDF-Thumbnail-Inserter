<?php
require_once __DIR__ . '/component-access.php';
/** Real resumable server maintenance: PTI_TEST_DISPOSABLE=true php this-file.php /local/wp */
if ( 'cli' !== PHP_SAPI || 'true' !== getenv( 'PTI_TEST_DISPOSABLE' ) || empty( $argv[1] ) ) {
    exit( 'Requires CLI, PTI_TEST_DISPOSABLE=true and a disposable WordPress root.' );
}
require_once rtrim( $argv[1], '/\\' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || ! class_exists( 'Imagick' ) ) {
    throw new RuntimeException( 'An isolated local site with cron disabled and real Imagick is required.' );
}
$plugin = PDF_Thumbnail_Inserter::instance();
$methods = array();
function maintenance_private( $method, ...$args ) {
    global $plugin, $methods;
    if ( ! isset( $methods[ $method ] ) ) {
        $methods[ $method ] = new ReflectionMethod( pti_test_target( $plugin, $method ), $method );
        $methods[ $method ]->setAccessible( true );
    }
    return $methods[ $method ]->invoke( pti_test_target( $plugin, $methods[ $method ]->getName() ), ...$args );
}
$assertions = array();
function maintenance_assert( $condition, $message ) {
    if ( ! $condition ) throw new RuntimeException( $message );
    $GLOBALS['assertions'][] = $message;
}
$previous_user = get_current_user_id();
wp_set_current_user( 1 );
maintenance_assert( current_user_can( 'manage_options' ), 'The fixture administrator can manage this disposable site' );
$key = maintenance_private( 'get_server_maintenance_job_key' );
$previous_job = get_transient( $key );
$uploads = wp_upload_dir();
$prefix = 'pti-maintenance-' . bin2hex( random_bytes( 5 ) );
$created = array();
$paths = array();
$scope_ids = array();
$queries = array();
$subscriber = 0;
$scope = function ( $query ) use ( &$scope_ids, &$queries ) {
    if ( $query->get( 'pti_preview_maintenance' ) ) {
        $query->set( 'post__in', $scope_ids ? $scope_ids : array( 0 ) );
        $queries[] = $query->get( 'posts_per_page' );
    }
};
$make_pdf = function ( $number, $kind = 'valid' ) use ( $uploads, $prefix, &$created, &$paths, &$scope_ids ) {
    $path = $uploads['path'] . '/' . $prefix . '-' . $number . '.pdf';
    if ( 'missing' !== $kind ) {
        file_put_contents( $path, 'valid' === $kind ? file_get_contents( __DIR__ . '/fixtures/one-page.pdf' ) : '%PDF-1.4 broken fixture' );
    }
    $id = wp_insert_attachment( array( 'post_title' => 'Maintenance ' . $number, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'application/pdf' ), $path, 0, true );
    if ( is_wp_error( $id ) ) throw new RuntimeException( $id->get_error_message() );
    $created[] = $id;
    $scope_ids[] = $id;
    $paths[] = $path;
    $paths[] = $path . '.pti-preview.lock';
    return $id;
};
try {
    $fixture_ids = array( $make_pdf( 'bad', 'broken' ) );
    for ( $i = 1; $i <= 22; $i++ ) $fixture_ids[] = $make_pdf( $i );
    add_action( 'pre_get_posts', $scope );
    $first = maintenance_private( 'run_server_maintenance_batch', '' );
    maintenance_assert( ! is_wp_error( $first ) && ! $first['complete'], 'A large job yields before the full library is processed' );
    maintenance_assert( $first['batch_attempts'] <= 10 && $first['batch_processed'] <= 100, 'The first request respects decoder and scan limits' );
    maintenance_assert( strlen( $first['job_id'] ) === 36, 'The server issues a fresh unguessable job identity' );
    $job_id = $first['job_id'];
    $new_pdf = $make_pdf( 'arrived-later' );
    wp_delete_attachment( $fixture_ids[15], true );
    $result = $first;
    $requests = 1;
    while ( ! $result['complete'] && $requests < 30 ) {
        $result = maintenance_private( 'run_server_maintenance_batch', $job_id );
        maintenance_assert( ! is_wp_error( $result ), 'The saved job resumes successfully' );
        maintenance_assert( $result['batch_attempts'] <= 10 && $result['batch_processed'] <= 100, 'Continuation respects decoder and scan limits' );
        $requests++;
    }
    maintenance_assert( $result['complete'], 'The resumable job finishes' );
    $report = $result['report'];
    maintenance_assert( 21 === $report['wordpress_generated'] + $report['plugin_generated'] && 1 === $report['failed'], 'One broken PDF is reported and all remaining valid PDFs are generated despite a concurrent deletion' );
    maintenance_assert( ! get_post_meta( $new_pdf, '_wp_attachment_metadata', true ) && ! get_post_meta( $new_pdf, PDF_Thumbnail_Inserter::META_PREVIEW_ID, true ), 'The high-water mark excludes uploads arriving after the job started' );
    $replay = maintenance_private( 'run_server_maintenance_batch', $job_id );
    maintenance_assert( $replay['complete'] && $replay['report'] === $report && 0 === $replay['batch_processed'], 'Replaying a completed request cannot generate or count PDFs twice' );
    $wrong_job = maintenance_private( 'run_server_maintenance_batch', wp_generate_uuid4() );
    maintenance_assert( is_wp_error( $wrong_job ) && 'expired_job' === $wrong_job->get_error_code(), 'A stale or different job token is rejected' );

    $lock_path = trailingslashit( $uploads['basedir'] ) . '.pti-server-maintenance-1.lock';
    $busy = maintenance_private( 'with_preview_file_lock', $lock_path, function () use ( $job_id ) { return maintenance_private( 'run_server_maintenance_batch', $job_id ); } );
    maintenance_assert( is_wp_error( $busy ) && 'preview_busy' === $busy->get_error_code(), 'Two requests cannot mutate the same administrator cursor simultaneously' );

    $subscriber = wp_insert_user( array( 'user_login' => $prefix, 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) );
    if ( is_wp_error( $subscriber ) ) throw new RuntimeException( $subscriber->get_error_message() );
    wp_set_current_user( $subscriber );
    $forbidden = maintenance_private( 'run_server_maintenance_batch', $job_id );
    maintenance_assert( is_wp_error( $forbidden ) && 'maintenance_forbidden' === $forbidden->get_error_code(), 'A subscriber cannot run or resume an administrator job' );
    wp_set_current_user( 1 );

    $scope_ids = array();
    for ( $i = 0; $i < 25; $i++ ) $make_pdf( 'missing-' . $i, 'missing' );
    $result = maintenance_private( 'run_server_maintenance_batch', '' );
    $requests_with_errors = 1;
    while ( ! $result['complete'] && $requests_with_errors < 10 ) {
        $result = maintenance_private( 'run_server_maintenance_batch', $result['job_id'] );
        $requests_with_errors++;
    }
    maintenance_assert( $result['complete'] && 25 === $result['report']['failed'], 'All missing files are reported without aborting later batches' );
    maintenance_assert( 20 === count( $result['report']['errors'] ) && strlen( serialize( maintenance_private( 'get_server_maintenance_job' ) ) ) < 15000, 'The persistent error log stays bounded even for a wholly broken library' );
    $scope_ids = array();
    $empty = maintenance_private( 'run_server_maintenance_batch', '' );
    maintenance_assert( ! is_wp_error( $empty ) && $empty['complete'] && 0 === $empty['batch_processed'] && 0 === $empty['batch_attempts'], 'An empty PDF library completes without attempting a decode' );
    $reject_state = function () { return false; };
    add_filter( 'pre_set_transient_' . $key, $reject_state );
    $store_failure = maintenance_private( 'run_server_maintenance_batch', '' );
    remove_filter( 'pre_set_transient_' . $key, $reject_state );
    maintenance_assert( is_wp_error( $store_failure ) && 'maintenance_store_failed' === $store_failure->get_error_code(), 'Rejected progress writes fail explicitly instead of pretending to advance' );
    maintenance_assert( max( $queries ) <= 101, 'No maintenance query requests an unbounded PDF ID list' );
    echo wp_json_encode( array( 'status' => 'PASS', 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'requests' => $requests, 'assertions' => $assertions ), JSON_PRETTY_PRINT ) . "\n";
} finally {
    remove_action( 'pre_get_posts', $scope );
    foreach ( $created as $id ) wp_delete_attachment( $id, true );
    foreach ( $paths as $path ) if ( is_file( $path ) ) wp_delete_file( $path );
    if ( $subscriber && ! is_wp_error( $subscriber ) ) wp_delete_user( $subscriber );
    if ( false === $previous_job ) delete_transient( $key );
    else set_transient( $key, $previous_job, HOUR_IN_SECONDS );
    wp_set_current_user( $previous_user );
}
