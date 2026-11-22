<?php
/** Real REST uploads and admin asset gating; never use outside an owned disposable site. */
if ( 'cli' !== PHP_SAPI || 'true' !== getenv( 'PTI_TEST_DISPOSABLE' ) || empty( $argv[1] ) ) exit( 'Disposable CLI site required.' );
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( 'local' !== wp_get_environment_type() || '127.0.0.1' !== wp_parse_url( site_url(), PHP_URL_HOST ) || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) throw new RuntimeException( 'Isolated loopback site required.' );
$old = get_option( PDF_Thumbnail_Inserter::OPTION_NAME, false );
$ids = array(); $paths = array(); $assertions = array();
function upload_http_assert( $ok, $message ) {
    if ( ! $ok ) throw new RuntimeException( $message );
    $GLOBALS['assertions'][] = $message;
}
$user = wp_insert_user( array( 'user_login' => 'pti-upload-' . bin2hex( random_bytes( 6 ) ), 'user_pass' => wp_generate_password( 40 ), 'role' => 'author' ) );
if ( is_wp_error( $user ) ) throw new RuntimeException( $user->get_error_message() );
$expiration = time() + HOUR_IN_SECONDS;
$token = WP_Session_Tokens::get_instance( $user )->create( $expiration );
wp_set_current_user( $user );
$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user, $expiration, 'logged_in', $token );
$cookie = AUTH_COOKIE . '=' . wp_generate_auth_cookie( $user, $expiration, 'auth', $token ) . '; ' . LOGGED_IN_COOKIE . '=' . $_COOKIE[ LOGGED_IN_COOKIE ];
try {
    foreach ( array( 0, 1 ) as $enabled ) {
        $settings = is_array( $old ) ? $old : array();
        $settings['auto_browser_preview_on_upload'] = $enabled;
        update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $settings, false );
        $page = wp_remote_get( admin_url( 'media-new.php' ), array( 'headers' => array( 'Cookie' => $cookie ), 'timeout' => 60 ) );
        upload_http_assert( ! is_wp_error( $page ) && 200 === wp_remote_retrieve_response_code( $page ), 'Authenticated Media Add New loads, setting=' . $enabled );
        upload_http_assert( (bool) $enabled === ( false !== strpos( wp_remote_retrieve_body( $page ), '/assets/js/browser-upload.js' ) ), 'Browser upload module is emitted only when enabled, setting=' . $enabled );
        $response = wp_remote_post( rest_url( 'wp/v2/media' ), array( 'timeout' => 60,
            'headers' => array( 'Cookie' => $cookie, 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ), 'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="pti-rest-' . bin2hex( random_bytes( 6 ) ) . '.pdf"' ),
            'body' => file_get_contents( __DIR__ . '/fixtures/one-page.pdf' ) ) );
        upload_http_assert( ! is_wp_error( $response ) && 201 === wp_remote_retrieve_response_code( $response ), 'Real core REST raw PDF upload succeeds, setting=' . $enabled );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $id = isset( $data['id'] ) ? (int) $data['id'] : 0;
        if ( $id ) { $ids[] = $id; $paths[] = get_attached_file( $id ) . '.pti-preview.lock'; }
        upload_http_assert( $id && 'application/pdf' === $data['mime_type'] && $user === (int) $data['author'] && is_file( get_attached_file( $id ) ), 'REST upload retains correct MIME, owner, model ID and local PDF bytes' );
        $request = wp_remote_post( admin_url( 'admin-ajax.php' ), array( 'timeout' => 60, 'headers' => array( 'Cookie' => $cookie ),
            'body' => array( 'action' => 'pdf_thumbnail_inserter_fetch_pdf_data', 'nonce' => wp_create_nonce( PDF_Thumbnail_Inserter::NONCE_ACTION ), 'attachment_id' => $id, 'automatic' => 1 ) ) );
        $lookup = is_wp_error( $request ) ? array() : json_decode( wp_remote_retrieve_body( $request ), true );
        upload_http_assert( ! empty( $lookup['success'] ) && isset( $lookup['data']['attachment_id'] ) && $id === (int) $lookup['data']['attachment_id'], 'The upload event model ID resolves through the actual authenticated plugin endpoint' );
    }
    echo wp_json_encode( array( 'status' => 'PASS', 'wordpress' => get_bloginfo( 'version' ), 'php_client' => PHP_VERSION, 'assertions' => $assertions ), JSON_PRETTY_PRINT ) . "\n";
} finally {
    foreach ( $ids as $id ) wp_delete_attachment( $id, true );
    foreach ( $paths as $path ) if ( is_file( $path ) ) wp_delete_file( $path );
    WP_Session_Tokens::get_instance( $user )->destroy( $token );
    wp_delete_user( $user );
    if ( false === $old ) delete_option( PDF_Thumbnail_Inserter::OPTION_NAME );
    else update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $old, false );
    unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
}
