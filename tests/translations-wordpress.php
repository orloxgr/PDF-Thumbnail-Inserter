<?php
/** Fresh-process real MO/JIT/locale-switch tests on an explicitly disposable site. */
if ( 'true' !== getenv( 'PTI_TEST_DISPOSABLE' ) || empty( $argv[1] ) ) exit( 'Disposable site required.' );
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! DISABLE_WP_CRON ) throw new RuntimeException( 'Local site required.' );
if ( isset( $argv[2] ) && 'worker' === $argv[2] ) {
    $assertions = array();
    if ( 'Download PDF' !== __( 'Download PDF', 'pdf-thumbnail-inserter' ) ) throw new RuntimeException( 'Expected initial English locale.' );
    $assertions[] = 'Initial English catalogue state is correct';
    for ( $i = 0; $i < 2; $i++ ) {
        if ( ! switch_to_locale( 'el' ) ) throw new RuntimeException( 'Real locale switch failed.' );
        if ( $argv[3] !== __( 'Download PDF', 'pdf-thumbnail-inserter' ) ) throw new RuntimeException( 'Wrong Greek translation after locale switch: ' . __( 'Download PDF', 'pdf-thumbnail-inserter' ) );
        $assertions[] = 'Greek catalogue selection and translation are correct';
        if ( ! restore_previous_locale() || 'Download PDF' !== __( 'Download PDF', 'pdf-thumbnail-inserter' ) ) throw new RuntimeException( 'Restoring English retained a stale translation.' );
        $assertions[] = 'Restoring the locale restores English without a stale catalogue';
    }
    echo wp_json_encode( array( 'status' => 'PASS', 'assertions' => $assertions ), JSON_PRETTY_PRINT );
    exit;
}
require_once ABSPATH . WPINC . '/pomo/mo.php';
$local = WP_PLUGIN_DIR . '/pdf-thumbnail-inserter/languages/pdf-thumbnail-inserter-el.mo';
$global = WP_LANG_DIR . '/plugins/pdf-thumbnail-inserter-el.mo';
$core = WP_LANG_DIR . '/el.mo';
$paths = array( $local, $global, $core ); $directories = array(); $records = array();
foreach ( $paths as $path ) if ( file_exists( $path ) ) throw new RuntimeException( 'Refusing to replace an existing translation: ' . basename( $path ) );
try {
    foreach ( $paths as $path ) {
        $directory = dirname( $path );
        if ( ! is_dir( $directory ) ) { wp_mkdir_p( $directory ); $directories[] = $directory; }
        $mo = new MO(); $mo->set_headers( array( 'Content-Type' => 'text/plain; charset=UTF-8', 'Language' => 'el' ) );
        if ( $path !== $core ) $mo->add_entry( new Translation_Entry( array( 'singular' => 'Download PDF', 'translations' => array( $path === $global ? 'Λήψη πακέτου' : 'Λήψη τοπική' ) ) ) );
        if ( ! $mo->export_to_file( $path ) ) throw new RuntimeException( 'Could not create a synthetic MO fixture.' );
    }
    foreach ( array( 'language-pack-priority' => 'Λήψη πακέτου', 'local-fallback' => 'Λήψη τοπική', 'missing-catalogue' => 'Download PDF' ) as $mode => $expected ) {
        if ( 'local-fallback' === $mode ) wp_delete_file( $global );
        if ( 'missing-catalogue' === $mode ) wp_delete_file( $local );
        $process = proc_open( array( PHP_BINARY, '-dxdebug.mode=off', __FILE__, $argv[1], 'worker', $expected ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
        fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
        $result = json_decode( $stdout, true );
        if ( 0 !== $exit || ! is_array( $result ) || 'PASS' !== $result['status'] ) throw new RuntimeException( 'Translation mode failed: ' . $mode . '; ' . $stderr . '; ' . substr( wp_strip_all_tags( $stdout ), 0, 200 ) );
        $records[ $mode ] = $result;
    }
    echo wp_json_encode( array( 'status' => 'PASS', 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'modes' => $records ), JSON_PRETTY_PRINT ) . "\n";
} finally {
    foreach ( $paths as $path ) if ( is_file( $path ) ) wp_delete_file( $path );
    foreach ( array_reverse( $directories ) as $directory ) if ( is_dir( $directory ) && 2 === count( scandir( $directory ) ) ) rmdir( $directory );
}
