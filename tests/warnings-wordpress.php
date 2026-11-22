<?php
/** Native regressions for warning corrections; only an owned disposable site. */
if ( 'true' !== getenv( 'PTI_TEST_DISPOSABLE' ) || empty( $argv[1] ) ) exit( 'Disposable site required.' );
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! DISABLE_WP_CRON ) throw new RuntimeException( 'Local site required.' );
$plugin = PDF_Thumbnail_Inserter::instance();
$assertions = array(); $ids = array(); $paths = array();
$old_settings = get_option( PDF_Thumbnail_Inserter::OPTION_NAME, false );
$old_query = $GLOBALS['wp_query'] ?? null; $old_post = $GLOBALS['post'] ?? null;
function warning_assert( $ok, $message ) {
    if ( ! $ok ) throw new RuntimeException( $message );
    $GLOBALS['assertions'][] = $message;
}
$find = new ReflectionMethod( $plugin, 'find_attachment_filename_candidates' ); $find->setAccessible( true );
$length = static function () { return 3; }; $more = static function () { return ' [custom-more]'; };
try {
    $vars = $plugin->register_preview_query_vars( array( 'p', 'custom' ) );
    warning_assert( ! array_diff( array( PDF_Thumbnail_Inserter::VIEWER_QUERY_VAR, PDF_Thumbnail_Inserter::COVER_QUERY_VAR, 'file', 'title', 'shape' ), $vars ), 'WordPress explicitly registers every public preview request field' );
    $parsed = $plugin->parse_public_preview_request( array( 'p' => 123, 'custom' => 'keep', 'file' => '/report.pdf', 'title' => 'Cover', 'shape' => 'portrait' ) );
    warning_assert( 123 === $parsed['p'] && 'keep' === $parsed['custom'] && ! isset( $parsed['file'], $parsed['title'], $parsed['shape'] ), 'Preview-only parameters cannot change the main WordPress content query' );
    $plugin->register_preview_query_vars( array( 'p', 'title' ) );
    $parsed = $plugin->parse_public_preview_request( array( 'title' => 'Other plugin route', 'file' => '/report.pdf' ) );
    warning_assert( 'Other plugin route' === $parsed['title'] && ! isset( $parsed['file'] ), 'Existing third-party query variables retain their routing semantics' );

    $settings = is_array( $old_settings ) ? $old_settings : array();
    $settings['use_first_pdf_preview_as_featured_image'] = 1;
    update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $settings, false );
    $GLOBALS['wp_query'] = new WP_Query(); $GLOBALS['wp_query']->is_category = true;
    $content = 'Πρώτη δεύτερη τρίτη τέταρτη πέμπτη <a href="https://example.org/report.pdf">Attachment Download</a>';
    $post_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Excerpt hooks', 'post_content' => $content ) );
    $ids[] = $post_id; $post = get_post( $post_id ); $GLOBALS['post'] = $post;
    add_filter( 'excerpt_length', $length ); add_filter( 'excerpt_more', $more );
    $excerpt = $plugin->filter_list_excerpt_attachment_text( 'Original automatic excerpt', $post );
    warning_assert( 'Πρώτη δεύτερη τρίτη [custom-more]' === $excerpt, 'Core excerpt generation retains both real excerpt_length and excerpt_more filters while removing attachment labels' );
    warning_assert( $post->post_content === $content && get_post_field( 'post_content', $post_id ) === $content, 'Excerpt generation never mutates the real post object or its stored content' );
    $counts = array( 'content' => 0, 'trim' => 0 );
    $count_content = static function ( $text ) use ( &$counts ) { $counts['content']++; return $text; };
    $count_trim = static function ( $text ) use ( &$counts ) { $counts['trim']++; return $text; };
    add_filter( 'the_content', $count_content ); add_filter( 'wp_trim_excerpt', $count_trim );
    try {
        warning_assert( 'Πρώτη δεύτερη τρίτη [custom-more]' === get_the_excerpt( $post ), 'The real core excerpt pipeline removes attachment labels before applying length and more filters' );
        warning_assert( array( 'content' => 1, 'trim' => 1 ) === $counts, 'Normal excerpt generation runs the core content and trim pipelines exactly once' );
    } finally {
        remove_filter( 'the_content', $count_content ); remove_filter( 'wp_trim_excerpt', $count_trim );
    }
    $nested_seen = false;
    $nested = clone $post; $nested->post_excerpt = 'Nested manual';
    $nested_filter = static function ( $text ) use ( &$nested_seen, $nested, $plugin, $content ) {
        if ( ! $nested_seen ) {
            $nested_seen = true;
            warning_assert( 'Nested manual' === get_the_excerpt( $nested ), 'A nested manual excerpt is preserved by the real core pipeline' );
            warning_assert( false === strpos( $plugin->filter_excerpt_attachment_source( $content ), 'Attachment Download' ), 'Returning from a nested excerpt restores the outer source-cleaning context' );
        }
        return $text;
    };
    add_filter( 'the_content', $nested_filter, 12 );
    try {
        warning_assert( 'Πρώτη δεύτερη τρίτη [custom-more]' === get_the_excerpt( $post ), 'Nested excerpt callbacks do not change the outer excerpt result' );
    } finally {
        remove_filter( 'the_content', $nested_filter, 12 );
    }
    $manual = clone $post; $manual->post_excerpt = 'Manual excerpt';
    warning_assert( 'Preserve this' === $plugin->filter_list_excerpt_attachment_text( 'Preserve this', $manual ), 'A manually written excerpt is not regenerated' );

    $uploads = wp_upload_dir(); $stem = 'pti-literal-' . bin2hex( random_bytes( 6 ) );
    foreach ( array( '_100%_literal', 'X100ZZliteral' ) as $suffix ) {
        $path = $uploads['path'] . '/' . $stem . $suffix . '.pdf';
        copy( __DIR__ . '/fixtures/one-page.pdf', $path ); $paths[] = $path;
        $ids[] = wp_insert_attachment( array( 'post_title' => 'Different display title', 'post_status' => 'inherit', 'post_mime_type' => 'application/pdf' ), $path );
    }
    $literal_id = $ids[1];
    $matches = $find->invoke( $plugin, array( $stem . '_100%_' ) );
    warning_assert( array( $literal_id ) === $matches, 'Percent and underscore in filenames remain literal data, not SQL wildcards' );
    warning_assert( array() === $find->invoke( $plugin, array( "' OR 1=1 --" ) ), 'A SQL-looking filename fragment cannot broaden the attachment query' );
    $two = $find->invoke( $plugin, array( $stem . '_100%_', $stem . 'X100' ) );
    warning_assert( 2 === count( $two ) && in_array( $literal_id, $two, true ), 'Short needle lists are padded without manufacturing additional matches' );
    wp_update_post( array( 'ID' => $literal_id, 'post_date' => '2000-01-01 00:00:00' ) );
    $exact = $find->invoke( $plugin, array( get_post_meta( $literal_id, '_wp_attached_file', true ), get_post_meta( $ids[2], '_wp_attached_file', true ) ), 'exact' );
    warning_assert( $literal_id === $exact[0], 'Exact URL variants retain caller priority even when a lower-priority candidate is newer' );
    $ordinary = get_posts( array( 'post_type' => 'post', 'post__in' => array( $post_id ), 'fields' => 'ids' ) );
    warning_assert( array( $post_id ) === $ordinary, 'Scoped filename query clauses are removed before unrelated WordPress queries' );
    wp_delete_attachment( $literal_id, true );
    warning_assert( array() === $find->invoke( $plugin, array( $stem . '_100%_' ) ), 'Attachment deletion invalidates a previously cached filename result' );
    echo wp_json_encode( array( 'status' => 'PASS', 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'assertions' => $assertions ), JSON_PRETTY_PRINT ) . "\n";
} finally {
    remove_filter( 'excerpt_length', $length ); remove_filter( 'excerpt_more', $more );
    $GLOBALS['wp_query'] = $old_query; $GLOBALS['post'] = $old_post;
    foreach ( $ids as $id ) wp_delete_post( $id, true );
    foreach ( $paths as $path ) foreach ( array( $path, $path . '.pti-preview.lock' ) as $file ) if ( is_file( $file ) ) wp_delete_file( $file );
    if ( false === $old_settings ) delete_option( PDF_Thumbnail_Inserter::OPTION_NAME );
    else update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $old_settings, false );
}
