<?php
/** Real WordPress writer/deleter contention. Only on an explicitly disposable local site. */
if ( 'cli' !== PHP_SAPI || 'true' !== getenv( 'PTI_TEST_DISPOSABLE' ) || empty( $argv[1] ) ) exit( 'Requires CLI, PTI_TEST_DISPOSABLE=true and a disposable WordPress root.' );
require_once rtrim( $argv[1], '/\\' ) . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) throw new RuntimeException( 'Local isolated site required' );
function deletion_private( $name, ...$args ) {
    $method = new ReflectionMethod( PDF_Thumbnail_Inserter::instance(), $name );
    $method->setAccessible( true );
    return $method->invoke( PDF_Thumbnail_Inserter::instance(), ...$args );
}
if ( isset( $argv[2] ) && 'hold' === $argv[2] ) {
    $result = deletion_private( 'with_pdf_preview_lock', $argv[3], function () use ( $argv ) {
        file_put_contents( $argv[4], 'locked' );
        $deadline = microtime( true ) + 25;
        while ( ! is_file( $argv[5] ) && microtime( true ) < $deadline ) usleep( 20000 );
        return 'held';
    } );
    echo is_wp_error( $result ) ? $result->get_error_code() : $result;
    exit;
}
if ( isset( $argv[2] ) && 'delete' === $argv[2] ) {
    $id = (int) $argv[3];
    $path = get_attached_file( $id );
    $held_during_database_delete = null;
    add_action( 'deleted_post', function ( $deleted_id ) use ( $id, $path, &$held_during_database_delete ) {
        if ( $id !== $deleted_id ) return;
        $probe = deletion_private( 'with_preview_file_lock', $path . '.pti-preview.lock', function () { return 'unexpected'; } );
        $held_during_database_delete = is_wp_error( $probe ) && 'preview_busy' === $probe->get_error_code();
    } );
    $result = wp_delete_attachment( $id, true );
    $released = deletion_private( 'with_preview_file_lock', $path . '.pti-preview.lock', function () { return 'released'; } );
    echo wp_json_encode( array( 'deleted' => (bool) $result, 'file_present' => is_file( $path ),
        'held_during_database_delete' => $held_during_database_delete, 'released_after_cleanup' => 'released' === $released ) );
    exit;
}
$assertions = array();
function deletion_assert( $condition, $message ) {
    if ( ! $condition ) throw new RuntimeException( $message );
    $GLOBALS['assertions'][] = $message;
}
$uploads = wp_upload_dir();
$prefix = $uploads['path'] . '/pti-deletion-' . bin2hex( random_bytes( 5 ) );
$pdf_path = $prefix . '.pdf';
$image_path = $prefix . '.png';
$unrelated_path = $prefix . '-unrelated.png';
$pdf = $image = $unrelated = 0;
$workers = array();
function deletion_spawn( $mode, ...$args ) {
    $pipes = array();
    $process = proc_open( array_merge( array( PHP_BINARY, '-d', 'xdebug.mode=off', __FILE__, $GLOBALS['argv'][1], $mode ), array_map( 'strval', $args ) ),
        array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, null, array( 'bypass_shell' => true ) );
    if ( ! is_resource( $process ) ) throw new RuntimeException( 'Could not start deletion worker' );
    fclose( $pipes[0] );
    $GLOBALS['workers'][] = array( $process, $pipes );
    return array( $process, $pipes );
}
function deletion_result( $worker ) {
    $output = stream_get_contents( $worker[1][1] );
    $error = stream_get_contents( $worker[1][2] );
    if ( $error ) throw new RuntimeException( $error );
    return trim( $output );
}
try {
    copy( __DIR__ . '/fixtures/one-page.pdf', $pdf_path );
    $pdf = wp_insert_attachment( array( 'post_title' => 'Concurrent deletion', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), $pdf_path );
    copy( WP_PLUGIN_DIR . '/pdf-thumbnail-inserter/assets/images/pdf-placeholder.png', $image_path );
    $published = deletion_private( 'create_pdf_preview_attachment', $pdf, $image_path, 'image/png', 'Owned preview' );
    deletion_assert( ! is_wp_error( $published ), 'A real owned preview is prepared before testing deletion' );
    $image = $published['preview_id'];
    copy( WP_PLUGIN_DIR . '/pdf-thumbnail-inserter/assets/images/pdf-placeholder.png', $unrelated_path );
    $unrelated = wp_insert_attachment( array( 'post_title' => 'Unowned image', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_parent' => $pdf ), $unrelated_path );

    $veto = function ( $delete, $post ) use ( $image ) { return (int) $post->ID === $image ? false : $delete; };
    add_filter( 'pre_delete_attachment', $veto, 20, 2 );
    $vetoed = wp_delete_attachment( $pdf, true );
    remove_filter( 'pre_delete_attachment', $veto, 20 );
    deletion_assert( false === $vetoed && get_post( $pdf ) && get_post( $image ) && is_file( $pdf_path ) && is_file( $image_path ), 'A child-image veto preserves both original PDF and preview' );
    deletion_assert( $image === (int) get_post_meta( $pdf, PDF_Thumbnail_Inserter::META_PREVIEW_ID, true ), 'A rejected child deletion retains its parent linkage' );
    $retry = deletion_private( 'with_pdf_preview_lock', $pdf_path, function () { return 'available'; } );
    deletion_assert( 'available' === $retry, 'A veto releases the temporary deletion lock' );

    $holder = deletion_spawn( 'hold', $pdf_path, $prefix . '.ready', $prefix . '.release' );
    $deadline = microtime( true ) + 15;
    while ( ! is_file( $prefix . '.ready' ) && microtime( true ) < $deadline ) {
        if ( ! proc_get_status( $holder[0] )['running'] ) throw new RuntimeException( 'Holder exited before locking' );
        usleep( 20000 );
    }
    deletion_assert( is_file( $prefix . '.ready' ) && proc_get_status( $holder[0] )['running'], 'An independent preview writer owns the live OS lock' );
    $blocked = json_decode( deletion_result( deletion_spawn( 'delete', $pdf ) ), true );
    deletion_assert( is_array( $blocked ) && ! $blocked['deleted'] && $blocked['file_present'], 'A second process cannot delete the PDF while preview generation owns its lock' );
    file_put_contents( $prefix . '.release', 'release' );
    deletion_assert( 'held' === deletion_result( $holder ), 'The preview writer finishes and releases its lock normally' );
    $deleted = json_decode( deletion_result( deletion_spawn( 'delete', $pdf ) ), true );
    deletion_assert( $deleted['deleted'] && ! $deleted['file_present'] && $deleted['held_during_database_delete'] && $deleted['released_after_cleanup'], 'Retry deletion holds the lock through core deletion and releases it after file cleanup' );
    clean_post_cache( $pdf ); clean_post_cache( $image );
    deletion_assert( ! get_post( $pdf ) && ! get_post( $image ) && ! is_file( $image_path ), 'Successful core deletion removes the owned preview without orphaned attachment/file' );
    deletion_assert( get_post( $unrelated ) && is_file( $unrelated_path ), 'An unowned child image survives deletion of the PDF' );
    $stale = deletion_private( 'with_pdf_preview_lock', $pdf_path, function () { throw new RuntimeException( 'Stale writer must not publish' ); } );
    deletion_assert( is_wp_error( $stale ) && 'missing_file' === $stale->get_error_code(), 'A writer with an obsolete validated path cannot publish after deletion' );
    copy( WP_PLUGIN_DIR . '/pdf-thumbnail-inserter/assets/images/pdf-placeholder.png', $image_path );
    $orphan = deletion_private( 'create_pdf_preview_attachment', $pdf, $image_path, 'image/png', 'Obsolete parent' );
    deletion_assert( is_wp_error( $orphan ) && ! is_file( $image_path ), 'Publication with a deleted parent rolls back its new image file' );
    echo wp_json_encode( array( 'status' => 'PASS', 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'assertions' => $assertions ), JSON_PRETTY_PRINT ) . "\n";
} finally {
    foreach ( $workers as $worker ) {
        if ( proc_get_status( $worker[0] )['running'] ) proc_terminate( $worker[0] );
        foreach ( array( 1, 2 ) as $i ) if ( is_resource( $worker[1][$i] ) ) fclose( $worker[1][$i] );
        proc_close( $worker[0] );
    }
    foreach ( array( $pdf, $image, $unrelated ) as $id ) if ( $id ) wp_delete_attachment( $id, true );
    foreach ( array( $pdf_path, $image_path, $unrelated_path, $pdf_path . '.pti-preview.lock', $prefix . '.ready', $prefix . '.release' ) as $file ) if ( is_file( $file ) ) wp_delete_file( $file );
}
