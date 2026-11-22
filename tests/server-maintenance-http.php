<?php
/** Authenticated HTTP maintenance contracts. Run only against an owned loopback test server. */
if ( 'cli' !== PHP_SAPI || 'true' !== getenv( 'PTI_TEST_DISPOSABLE' ) || empty( $argv[1] ) ) {
    exit( 'Requires CLI, PTI_TEST_DISPOSABLE=true and a disposable WordPress root.' );
}
require_once rtrim( $argv[1], '/\\' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
$base = site_url();
if ( 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON
    || '127.0.0.1' !== wp_parse_url( $base, PHP_URL_HOST ) || 'http' !== wp_parse_url( $base, PHP_URL_SCHEME ) ) {
    throw new RuntimeException( 'Requires an explicitly disposable loopback HTTP site with cron disabled.' );
}
$assertions = array();
function maintenance_http_assert( $condition, $message ) {
    if ( ! $condition ) throw new RuntimeException( $message );
    $GLOBALS['assertions'][] = $message;
}
$previous_user = get_current_user_id();
$previous_settings = get_option( PDF_Thumbnail_Inserter::OPTION_NAME, false );
$users = array();
$sessions = array();
$created = array();
$paths = array();
$prefix = 'pti-http-' . bin2hex( random_bytes( 5 ) );
$identity = function ( $role, $suffix = '' ) use ( $prefix, &$users, &$sessions ) {
    $id = wp_insert_user( array( 'user_login' => $prefix . '-' . $role . $suffix, 'user_pass' => wp_generate_password( 40 ), 'role' => $role ) );
    if ( is_wp_error( $id ) ) throw new RuntimeException( $id->get_error_message() );
    $users[] = $id;
    $expiration = time() + HOUR_IN_SECONDS;
    $token = WP_Session_Tokens::get_instance( $id )->create( $expiration );
    $sessions[ $id ] = $token;
    wp_set_current_user( $id );
    $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $id, $expiration, 'logged_in', $token );
    return array(
        'id' => $id,
        'nonce' => wp_create_nonce( PDF_Thumbnail_Inserter::ADMIN_ACTION_REGENERATE_MISSING ),
        'ajax_nonce' => wp_create_nonce( PDF_Thumbnail_Inserter::NONCE_ACTION ),
        'cookie' => AUTH_COOKIE . '=' . wp_generate_auth_cookie( $id, $expiration, 'auth', $token )
            . '; ' . LOGGED_IN_COOKIE . '=' . $_COOKIE[ LOGGED_IN_COOKIE ],
    );
};
$request = function ( $identity, $overrides = array() ) use ( $base ) {
    $body = array_merge( array( 'action' => PDF_Thumbnail_Inserter::ADMIN_ACTION_REGENERATE_MISSING,
        '_wpnonce' => $identity['nonce'], 'pti_ajax' => '1', 'job_id' => '' ), $overrides );
    $response = wp_remote_post( $base . '/wp-admin/admin-post.php', array(
        'timeout' => 60, 'redirection' => 0, 'headers' => array( 'Cookie' => $identity['cookie'] ), 'body' => $body,
    ) );
    if ( is_wp_error( $response ) ) throw new RuntimeException( $response->get_error_message() );
    return array( 'status' => wp_remote_retrieve_response_code( $response ),
        'location' => wp_remote_retrieve_header( $response, 'location' ),
        'json' => json_decode( wp_remote_retrieve_body( $response ), true ) );
};
$ajax = function ( $identity, $action, $fields = array(), $image_name = null ) use ( $base ) {
    $body = array_merge( array( 'action' => 'pdf_thumbnail_inserter_' . $action, 'nonce' => $identity['ajax_nonce'] ), $fields );
    $headers = array( 'Cookie' => $identity['cookie'] );
    if ( null !== $image_name ) {
        $boundary = 'pti' . bin2hex( random_bytes( 16 ) );
        $multipart = '';
        foreach ( $body as $name => $value ) $multipart .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $name . "\"\r\n\r\n" . $value . "\r\n";
        $multipart .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"preview\"; filename=\"" . $image_name . "\"\r\nContent-Type: image/png\r\n\r\n"
            . file_get_contents( WP_PLUGIN_DIR . '/pdf-thumbnail-inserter/assets/images/pdf-placeholder.png' ) . "\r\n--" . $boundary . "--\r\n";
        $body = $multipart;
        $headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
    }
    $response = wp_remote_post( $base . '/wp-admin/admin-ajax.php', array( 'timeout' => 60, 'redirection' => 0, 'headers' => $headers, 'body' => $body ) );
    if ( is_wp_error( $response ) ) throw new RuntimeException( $response->get_error_message() );
    return array( 'status' => wp_remote_retrieve_response_code( $response ), 'json' => json_decode( wp_remote_retrieve_body( $response ), true ) );
};
try {
    $admin = $identity( 'administrator' );
    $subscriber = $identity( 'subscriber' );
    $forbidden = $request( $subscriber );
    maintenance_http_assert( 403 === $forbidden['status'], 'A valid subscriber session and nonce cannot run administrator maintenance' );
    $invalid_nonce = $request( $admin, array( '_wpnonce' => 'invalid' ) );
    maintenance_http_assert( 403 === $invalid_nonce['status'], 'An authenticated administrator request with an invalid nonce is rejected' );
    $anonymous = $request( array( 'nonce' => 'invalid', 'cookie' => '' ) );
    maintenance_http_assert( 400 === $anonymous['status'], 'The maintenance action is unavailable to unauthenticated requests' );
    $malformed = $request( $admin, array( 'job_id' => array( 'nested' ) ) );
    maintenance_http_assert( 400 === $malformed['status'] && false === $malformed['json']['success'], 'A nested job token is rejected through the real HTTP endpoint' );

    $uploads = wp_upload_dir();
    for ( $i = 0; $i < 12; $i++ ) {
        $path = $uploads['path'] . '/' . $prefix . '-' . $i . '.pdf';
        copy( __DIR__ . '/fixtures/one-page.pdf', $path );
        $id = wp_insert_attachment( array( 'post_title' => $prefix, 'post_author' => $admin['id'], 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), $path, 0, true );
        if ( is_wp_error( $id ) ) throw new RuntimeException( $id->get_error_message() );
        $created[] = $id;
        $paths[] = $path;
        $paths[] = $path . '.pti-preview.lock';
    }
    $author = $identity( 'author' );
    $other_author = $identity( 'author', '-other' );
    $author_pdf = end( $created );
    wp_update_post( array( 'ID' => $author_pdf, 'post_author' => $author['id'] ) );
    $settings = is_array( $previous_settings ) ? $previous_settings : array();
    $settings['auto_browser_preview_on_upload'] = 0;
    update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $settings, false );
    $denied = $ajax( $author, 'save_browser_preview', array( 'attachment_id' => $author_pdf, 'automatic' => 1 ), 'preview.png' );
    maintenance_http_assert( 403 === $denied['status'], 'An author cannot save automatic browser previews while the setting is off' );
    $settings['auto_browser_preview_on_upload'] = 1;
    update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $settings, false );
    $denied = $ajax( $other_author, 'save_browser_preview', array( 'attachment_id' => $author_pdf, 'automatic' => 1 ), 'preview.png' );
    maintenance_http_assert( 403 === $denied['status'], 'An enabled upload setting does not let an author overwrite another author PDF' );
    $denied = $ajax( $subscriber, 'save_browser_preview', array( 'attachment_id' => $author_pdf ), 'preview.png' );
    maintenance_http_assert( 403 === $denied['status'], 'A subscriber cannot save previews through multipart HTTP' );
    $denied = $ajax( $other_author, 'fetch_pdf_data', array( 'attachment_id' => $author_pdf ) );
    maintenance_http_assert( 403 === $denied['status'], 'Object-level access also protects PDF data lookup' );
    $denied = $ajax( $author, 'list_missing_previews', array( 'page' => 1 ) );
    maintenance_http_assert( 403 === $denied['status'], 'An author cannot scan the full library through maintenance discovery' );
    $bad_upload = $ajax( $author, 'save_browser_preview', array( 'attachment_id' => $author_pdf, 'automatic' => 1 ), 'preview.php' );
    maintenance_http_assert( 400 === $bad_upload['status'], 'A real HTTP image upload with a PHP extension is rejected' );
    $uploaded = $ajax( $author, 'save_browser_preview', array( 'attachment_id' => $author_pdf, 'automatic' => 1 ), 'preview.png' );
    maintenance_http_assert( 200 === $uploaded['status'] && ! empty( $uploaded['json']['data']['generated'] ), 'The owner can save an actual multipart image when the automatic setting is on' );
    $repeated = $ajax( $author, 'save_browser_preview', array( 'attachment_id' => $author_pdf, 'automatic' => 1 ), 'preview.png' );
    maintenance_http_assert( 200 === $repeated['status'] && ! empty( $repeated['json']['data']['skipped'] )
        && $uploaded['json']['data']['preview_id'] === $repeated['json']['data']['preview_id'], 'Repeated multipart saves preserve the existing preview attachment' );
    $media = $ajax( $author, 'unused', array( 'action' => 'get-attachment', 'id' => $author_pdf ) );
    maintenance_http_assert( 200 === $media['status'] && isset( $media['json']['data']['sizes']['full']['url'] ), 'A subsequent real core media-model response exposes the saved image for editor reuse' );
    $first = $request( $admin, array( 'pti_ajax' => '0' ) );
    maintenance_http_assert( 302 === $first['status'] && false !== strpos( $first['location'], 'page=pdf-thumbnail-settings' ), 'The no-JavaScript form performs one batch and redirects to settings' );
    $key = PDF_Thumbnail_Inserter::TRANSIENT_SERVER_JOB . '_' . $admin['id'];
    // This CLI request started before HTTP updated the option; invalidate its local cache.
    wp_cache_delete( '_transient_' . $key, 'options' );
    $job = get_transient( $key );
    maintenance_http_assert( is_array( $job ) && ! $job['complete'], 'A no-JavaScript request saves a pending continuation instead of running the whole library' );
    $settings = wp_remote_get( $base . '/wp-admin/options-general.php?page=pdf-thumbnail-settings', array(
        'timeout' => 60, 'headers' => array( 'Cookie' => $admin['cookie'] ),
    ) );
    maintenance_http_assert( ! is_wp_error( $settings ) && false !== strpos( wp_remote_retrieve_body( $settings ), 'Continue Generating Previews on Server' )
        && false !== strpos( wp_remote_retrieve_body( $settings ), 'value="' . $job['id'] . '"' ), 'Returning to settings exposes the saved continuation token and Continue control' );
    $requests = 1;
    do {
        $response = $request( $admin, array( 'job_id' => $job['id'] ) );
        maintenance_http_assert( 200 === $response['status'] && true === $response['json']['success'], 'The authenticated AJAX endpoint resumes its saved job' );
        $result = $response['json']['data'];
        maintenance_http_assert( $result['batch_processed'] <= 100 && $result['batch_attempts'] <= 10, 'Actual HTTP requests respect both per-request bounds' );
        $requests++;
    } while ( ! $result['complete'] && $requests < 20 );
    maintenance_http_assert( $result['complete'], 'HTTP continuation reaches completion' );
    foreach ( $created as $id ) {
        wp_cache_delete( $id, 'post_meta' );
        $meta = wp_get_attachment_metadata( $id );
        $preview = isset( $meta['sizes']['full']['file'] ) ? dirname( get_attached_file( $id ) ) . '/' . $meta['sizes']['full']['file'] : '';
        if ( ! $preview ) $preview = get_attached_file( get_post_meta( $id, PDF_Thumbnail_Inserter::META_PREVIEW_ID, true ) );
        maintenance_http_assert( $preview && is_file( $preview ) && is_array( getimagesize( $preview ) ), 'The server saved a real reusable image for HTTP fixture ' . $id );
    }
    $replay = $request( $admin, array( 'job_id' => $job['id'] ) );
    maintenance_http_assert( $replay['json']['data']['report'] === $result['report'] && 0 === $replay['json']['data']['batch_processed'], 'A repeated completed HTTP request leaves the report unchanged' );
    $stale = $request( $admin, array( 'job_id' => wp_generate_uuid4() ) );
    maintenance_http_assert( 409 === $stale['status'] && true === $stale['json']['data']['restart'], 'The HTTP endpoint explicitly identifies an expired or replaced job' );
    echo wp_json_encode( array( 'status' => 'PASS', 'wordpress' => get_bloginfo( 'version' ), 'php_client' => PHP_VERSION,
        'requests' => $requests, 'assertions' => $assertions ), JSON_PRETTY_PRINT ) . "\n";
} finally {
    foreach ( $created as $id ) { wp_cache_delete( $id, 'post_meta' ); wp_delete_attachment( $id, true ); }
    foreach ( $paths as $path ) if ( is_file( $path ) ) wp_delete_file( $path );
    foreach ( $users as $id ) {
        WP_Session_Tokens::get_instance( $id )->destroy( $sessions[ $id ] );
        delete_transient( PDF_Thumbnail_Inserter::TRANSIENT_SERVER_JOB . '_' . $id );
        wp_delete_user( $id );
    }
    if ( false === $previous_settings ) delete_option( PDF_Thumbnail_Inserter::OPTION_NAME );
    else update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $previous_settings, false );
    unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
    wp_set_current_user( $previous_user );
}
