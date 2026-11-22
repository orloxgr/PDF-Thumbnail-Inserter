<?php
/** Fresh-process lifecycle phases; see README for the required disposable-site sequence. */
if ( 'cli' !== PHP_SAPI || 'true' !== getenv( 'PTI_TEST_DISPOSABLE' ) || empty( $argv[1] ) || empty( $argv[2] ) ) {
    exit( 'Requires CLI, PTI_TEST_DISPOSABLE=true, a fresh local WordPress root and a phase.' );
}
$phase = $argv[2];
require_once rtrim( $argv[1], '/\\' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON
    || '127.0.0.1' !== wp_parse_url( site_url(), PHP_URL_HOST ) ) {
    throw new RuntimeException( 'An explicitly disposable loopback local site with cron disabled is required.' );
}
$plugin_file = 'pdf-thumbnail-inserter/pdf-thumbnail-inserter.php';
$option = 'pdf_thumbnail_inserter_defaults';
$legacy_option = 'pdf_thumbnail_defaults';
$state_path = WP_CONTENT_DIR . '/.pti-lifecycle-state.json';
$assertions = array();
function lifecycle_assert( $condition, $message ) {
    if ( ! $condition ) throw new RuntimeException( $message );
    $GLOBALS['assertions'][] = $message;
}
function lifecycle_private( $name, ...$args ) {
    $method = new ReflectionMethod( PDF_Thumbnail_Inserter::instance(), $name );
    $method->setAccessible( true );
    return $method->invoke( PDF_Thumbnail_Inserter::instance(), ...$args );
}
if ( 'init' === $phase ) {
    lifecycle_assert( ! is_file( WP_PLUGIN_DIR . '/' . $plugin_file ) && ! is_file( $state_path ), 'A genuinely fresh site has no target plugin or previous lifecycle fixture' );
    lifecycle_assert( false === get_option( $option, false ) && false === get_option( $legacy_option, false ), 'Fresh installation has neither current nor legacy settings' );
    $uploads = wp_upload_dir();
    $path = $uploads['path'] . '/pti-lifecycle-' . bin2hex( random_bytes( 6 ) ) . '.pdf';
    copy( __DIR__ . '/fixtures/one-page.pdf', $path );
    $pdf_id = wp_insert_attachment( array( 'post_title' => 'Κύκλος ζωής & PDF', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), $path, 0, true );
    if ( is_wp_error( $pdf_id ) ) throw new RuntimeException( $pdf_id->get_error_message() );
    $content = '[pdf_thumbnail id="' . $pdf_id . '"]' . "\n" . '<!-- wp:pdf-thumbnail-inserter/pdf-thumbnail {"id":' . $pdf_id . ',"columns_desktop":5} /-->';
    $page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Lifecycle content', 'post_content' => $content ), true );
    if ( is_wp_error( $page_id ) ) throw new RuntimeException( $page_id->get_error_message() );
    $state = array( 'pdf_id' => $pdf_id, 'pdf_path' => $path, 'pdf_sha256' => hash_file( 'sha256', $path ),
        'page_id' => $page_id, 'content_sha256' => hash( 'sha256', get_post_field( 'post_content', $page_id ) ) );
    file_put_contents( $state_path, wp_json_encode( $state ) );
    lifecycle_assert( is_file( $path ) && $page_id > 0, 'Synthetic existing PDF and legacy/block content exist before plugin installation' );
} else {
    lifecycle_assert( is_file( $state_path ), 'The phase uses its existing disposable lifecycle state' );
    $state = json_decode( file_get_contents( $state_path ), true );
    lifecycle_assert( is_array( $state ) && ! empty( $state['pdf_id'] ) && get_attached_file( $state['pdf_id'] ) === $state['pdf_path'], 'The fixture identity still matches its original attachment path' );
    lifecycle_assert( is_file( $state['pdf_path'] ) && hash_file( 'sha256', $state['pdf_path'] ) === $state['pdf_sha256'], 'The original PDF bytes are preserved' );
    lifecycle_assert( hash( 'sha256', get_post_field( 'post_content', $state['page_id'] ) ) === $state['content_sha256'], 'Stored legacy shortcode and dynamic block content are unchanged' );

    if ( 'fresh' === $phase ) {
        lifecycle_assert( is_plugin_active( $plugin_file ) && class_exists( 'PDF_Thumbnail_Inserter' ), 'Fresh installed plugin is active in a new WordPress request' );
        $plugin = PDF_Thumbnail_Inserter::instance();
        $plugin->register_settings();
        lifecycle_assert( lifecycle_private( 'get_defaults' ) === lifecycle_private( 'get_settings' ), 'A clean installation exposes all documented defaults' );
        lifecycle_assert( WP_Block_Type_Registry::get_instance()->is_registered( 'pdf-thumbnail-inserter/pdf-thumbnail' ), 'The dynamic block registers after fresh activation' );
        lifecycle_assert( false !== strpos( do_shortcode( '[pdf_thumbnail id="' . $state['pdf_id'] . '"]' ), 'pti-card' ), 'Existing legacy content renders after fresh installation' );
        $state['plugin_version'] = PDF_Thumbnail_Inserter::VERSION;
        file_put_contents( $state_path, wp_json_encode( $state ) );
    } elseif ( 'legacy' === $phase ) {
        lifecycle_assert( is_plugin_active( $plugin_file ), 'Legacy migration runs with the target plugin active' );
        $settings = array( 'button_text' => 'Παλιά ρύθμιση & λήψη', 'default_columns_desktop' => 5, 'legacy_custom_value' => 'Preserve this stored value' );
        update_option( $legacy_option, $settings, false );
        delete_option( $option );
        PDF_Thumbnail_Inserter::instance()->register_settings();
        lifecycle_assert( get_option( $option, false ) === $settings && get_option( $legacy_option, false ) === $settings, 'Migration preserves the exact legacy option, including an unknown stored value' );
        global $wpdb;
        $autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );
        lifecycle_assert( in_array( $autoload, array( 'no', 'off', 'auto-off' ), true ), 'Migrated plugin settings are not autoloaded' );
        $image_path = dirname( $state['pdf_path'] ) . '/pti-lifecycle-owned.png';
        copy( WP_PLUGIN_DIR . '/pdf-thumbnail-inserter/assets/images/pdf-placeholder.png', $image_path );
        $result = lifecycle_private( 'create_pdf_preview_attachment', $state['pdf_id'], $image_path, 'image/png', 'Lifecycle preview' );
        lifecycle_assert( ! is_wp_error( $result ) && $result['generated'], 'The plugin publishes a real owned child preview before lifecycle transitions' );
        $state['preview_id'] = $result['preview_id'];
        $state['preview_path'] = get_attached_file( $result['preview_id'] );
        $state['preview_sha256'] = hash_file( 'sha256', $state['preview_path'] );
        $state['settings'] = $settings;
        file_put_contents( $state_path, wp_json_encode( $state ) );
    } elseif ( in_array( $phase, array( 'inactive', 'uninstalled', 'reinstalled', 'baseline', 'upgraded', 'cleanup' ), true ) ) {
        lifecycle_assert( get_option( $option, false ) === $state['settings'] && get_option( $legacy_option, false ) === $state['settings'], 'Both settings options survive the lifecycle transition exactly' );
        lifecycle_assert( get_post( $state['preview_id'] ) && is_file( $state['preview_path'] )
            && hash_file( 'sha256', $state['preview_path'] ) === $state['preview_sha256'], 'The generated image attachment and its original bytes remain intact' );
        lifecycle_assert( (int) get_post_meta( $state['pdf_id'], '_pdf_thumbnail_preview_id', true ) === $state['preview_id']
            && (int) get_post_meta( $state['preview_id'], '_pdf_thumbnail_parent_pdf_id', true ) === $state['pdf_id'], 'Preview linkage and ownership metadata are preserved' );
        if ( 'inactive' === $phase || 'uninstalled' === $phase ) {
            lifecycle_assert( ! is_plugin_active( $plugin_file ) && ! class_exists( 'PDF_Thumbnail_Inserter', false ), 'An inactive/uninstalled plugin is absent from a fresh request' );
            if ( 'uninstalled' === $phase ) lifecycle_assert( ! is_file( WP_PLUGIN_DIR . '/' . $plugin_file ), 'WordPress uninstall actually removed the plugin files' );
        } else {
            lifecycle_assert( is_plugin_active( $plugin_file ) && class_exists( 'PDF_Thumbnail_Inserter' ), 'Reinstalled/upgraded plugin is active in a fresh request' );
            $version = 'upgraded' === $phase && ! empty( $argv[3] ) ? $argv[3] : $state['plugin_version'];
            $header = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
            lifecycle_assert( $version === PDF_Thumbnail_Inserter::VERSION && $version === $header['Version'], 'Installed header and runtime constant match the expected lifecycle version' );
            $preview = lifecycle_private( 'get_pdf_preview_data', $state['pdf_id'], 'full', false );
            lifecycle_assert( 'plugin' === $preview['preview_source'] && $state['preview_id'] === $preview['preview_id'], 'Reinstalled/upgraded code reuses the existing generated preview' );
            $rendered = do_blocks( do_shortcode( get_post_field( 'post_content', $state['page_id'] ) ) );
            lifecycle_assert( 2 === substr_count( $rendered, 'class="pti-card"' ) && false !== strpos( $rendered, 'Παλιά ρύθμιση &amp; λήψη' ), 'Preserved legacy and block content render with the preserved settings and image' );
            if ( 'baseline' === $phase ) {
                lifecycle_assert( ! empty( $argv[3] ) && is_file( $argv[3] ), 'The published baseline has a concrete local release artifact' );
                $zip = new ZipArchive();
                lifecycle_assert( true === $zip->open( $argv[3] ), 'The published baseline ZIP opens successfully' );
                $source = $zip->getFromName( $plugin_file );
                $zip->close();
                lifecycle_assert( is_string( $source ) && hash( 'sha256', $source ) === hash_file( 'sha256', WP_PLUGIN_DIR . '/' . $plugin_file ), 'The installed baseline entrypoint matches the published ZIP bytes' );
                $state['baseline_zip_sha256'] = hash_file( 'sha256', $argv[3] );
                file_put_contents( $state_path, wp_json_encode( $state ) );
            }
            if ( 'cleanup' === $phase ) {
                wp_delete_attachment( $state['pdf_id'], true );
                lifecycle_assert( ! get_post( $state['preview_id'] ) && ! is_file( $state['preview_path'] ), 'Deleting the original PDF while active removes its owned preview' );
                wp_delete_post( $state['page_id'], true );
                delete_option( $option );
                delete_option( $legacy_option );
                if ( is_file( $state['pdf_path'] . '.pti-preview.lock' ) ) wp_delete_file( $state['pdf_path'] . '.pti-preview.lock' );
                wp_delete_file( $state_path );
            }
        }
    } else {
        throw new RuntimeException( 'Unknown lifecycle phase' );
    }
}
echo wp_json_encode( array( 'status' => 'PASS', 'phase' => $phase, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'assertions' => $assertions ), JSON_PRETTY_PRINT ) . "\n";
