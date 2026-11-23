<?php
require_once __DIR__ . '/component-access.php';
/**
 * Real cross-process locking and crash recovery on a disposable WordPress site.
 * PTI_TEST_DISPOSABLE=true php tests/preview-lock-concurrency.php /path/to/wp
 */
if ( 'cli' !== PHP_SAPI || 'true' !== getenv( 'PTI_TEST_DISPOSABLE' ) || empty( $argv[1] ) ) {
    exit( 'Requires CLI, a disposable WordPress root and PTI_TEST_DISPOSABLE=true.' );
}
require_once rtrim( $argv[1], '/\\' ) . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
    throw new RuntimeException( 'Only local, explicitly isolated sites with cron disabled are accepted.' );
}
$method = new ReflectionMethod( pti_test_target( PDF_Thumbnail_Inserter::instance(), 'with_pdf_preview_lock' ), 'with_pdf_preview_lock' );
$method->setAccessible( true );
if ( isset( $argv[2] ) && 'worker' === $argv[2] ) {
    $pdf = $argv[3];
    $ready = $argv[4];
    $release = $argv[5];
    $hold = 'hold' === $argv[6];
    $result = $method->invoke( pti_test_target( PDF_Thumbnail_Inserter::instance(), $method->getName() ), $pdf, function () use ( $ready, $release, $hold ) {
        file_put_contents( $ready, 'locked' );
        if ( $hold ) {
            $deadline = microtime( true ) + 20;
            while ( ! is_file( $release ) && microtime( true ) < $deadline ) usleep( 20000 );
        }
        return 'acquired';
    } );
    echo is_wp_error( $result ) ? $result->get_error_code() : $result;
    exit;
}

$uploads = wp_upload_dir();
wp_mkdir_p( $uploads['path'] );
$pdf = $uploads['path'] . '/pti-lock-' . bin2hex( random_bytes( 6 ) ) . '.pdf';
file_put_contents( $pdf, '%PDF-1.4 lock-only fixture' );
$ready = $pdf . '.ready';
$release = $pdf . '.release';
$handles = array();
function pti_spawn_lock_worker( $mode, $marker ) {
    $pipes = array();
    $process = proc_open(
        array( PHP_BINARY, '-d', 'xdebug.mode=off', __FILE__, $GLOBALS['argv'][1], 'worker', $GLOBALS['pdf'], $marker, $GLOBALS['release'], $mode ),
        array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
        $pipes, null, null, array( 'bypass_shell' => true )
    );
    if ( ! is_resource( $process ) ) throw new RuntimeException( 'Could not start lock worker' );
    fclose( $pipes[0] );
    $GLOBALS['handles'][] = array( $process, $pipes );
    return array( $process, $pipes );
}
function pti_lock_probe( $marker ) {
    list( $process, $pipes ) = pti_spawn_lock_worker( 'probe', $marker );
    $output = stream_get_contents( $pipes[1] );
    $error = stream_get_contents( $pipes[2] );
    if ( '' !== $error ) throw new RuntimeException( $error );
    return trim( $output );
}
try {
    list( $holder ) = pti_spawn_lock_worker( 'hold', $ready );
    $deadline = microtime( true ) + 10;
    while ( ! is_file( $ready ) && microtime( true ) < $deadline ) {
        if ( ! proc_get_status( $holder )['running'] ) throw new RuntimeException( 'Holder exited before acquiring the lock' );
        usleep( 20000 );
    }
    if ( ! is_file( $ready ) ) throw new RuntimeException( 'Holder did not report lock acquisition' );
    if ( 'preview_busy' !== pti_lock_probe( $ready . '.probe' ) ) throw new RuntimeException( 'Second process entered locked PDF' );
    // Termination simulates a renderer/request crash without running PHP finally.
    proc_terminate( $holder );
    $deadline = microtime( true ) + 5;
    while ( proc_get_status( $holder )['running'] && microtime( true ) < $deadline ) usleep( 20000 );
    if ( proc_get_status( $holder )['running'] ) throw new RuntimeException( 'Could not terminate owned holder' );
    if ( 'acquired' !== pti_lock_probe( $ready . '.recovered' ) ) throw new RuntimeException( 'Crashed writer left PDF locked' );
    if ( ! is_file( $pdf . '.pti-preview.lock' ) ) throw new RuntimeException( 'Retained lock file unexpectedly missing' );
    echo 'PASS: real concurrent processes reject duplicate writer and recover after process termination; WP ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION . "\n";
} finally {
    foreach ( $handles as $handle ) {
        if ( proc_get_status( $handle[0] )['running'] ) proc_terminate( $handle[0] );
        foreach ( array( 1, 2 ) as $index ) if ( is_resource( $handle[1][ $index ] ) ) fclose( $handle[1][ $index ] );
        proc_close( $handle[0] );
    }
    foreach ( array( $pdf, $pdf . '.pti-preview.lock', $ready, $ready . '.probe', $ready . '.recovered', $release ) as $path ) {
        if ( is_file( $path ) ) wp_delete_file( $path );
    }
}
