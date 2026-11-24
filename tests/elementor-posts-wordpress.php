<?php
/** Actual Elementor Pro skins on a disposable local WordPress site, without WP-CLI context. */
if ( 'cli' !== PHP_SAPI || 'true' !== getenv( 'PTI_TEST_DISPOSABLE' ) || empty( $argv[1] ) ) exit( 'Disposable CLI site required.' );
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
if ( 'local' !== wp_get_environment_type() || ! DISABLE_WP_CRON || ! class_exists( '\ElementorPro\Modules\Posts\Widgets\Posts' ) || ! class_exists( 'Imagick' ) ) throw new RuntimeException( 'Local site with Elementor Pro and Imagick required.' );
$plugin = PDF_Thumbnail_Inserter::instance();
$original_settings = get_option( PDF_Thumbnail_Inserter::OPTION_NAME, false );
$previous_post = $GLOBALS['post'] ?? null;
$previous_query = $GLOBALS['wp_query'];
$ids = array(); $paths = array(); $assertions = array();
function ep_assert( $condition, $message ) {
    if ( ! $condition ) throw new RuntimeException( $message );
    $GLOBALS['assertions'][] = $message;
}
function ep_render( $skin_name, $widget_name = 'posts', $overrides = array() ) {
    $settings = array_merge( array( '_skin' => $skin_name, $skin_name . '_thumbnail' => 'top', $skin_name . '_thumbnail_size_size' => 'full', $skin_name . '_show_badge' => '', $skin_name . '_show_avatar' => '' ), $overrides );
    $widget = \Elementor\Plugin::instance()->elements_manager->create_element_instance( array( 'id' => 'pti-posts-test', 'elType' => 'widget', 'widgetType' => $widget_name, 'settings' => $settings ) );
    if ( ! $widget ) throw new RuntimeException( 'Actual widget is unavailable: ' . $widget_name );
    $skin = $widget->get_skin( $skin_name );
    if ( ! $skin ) throw new RuntimeException( 'Actual skin is unavailable: ' . $skin_name );
    $skin->set_parent( $widget );
    $method = new ReflectionMethod( $skin, 'render_thumbnail' );
    $method->setAccessible( true );
    ob_start();
    try { $method->invoke( $skin ); return ob_get_contents(); }
    finally { ob_end_clean(); }
}
try {
    update_option( PDF_Thumbnail_Inserter::OPTION_NAME, array( 'use_first_pdf_preview_as_featured_image' => 1 ), false );
    $uploads = wp_upload_dir(); wp_mkdir_p( $uploads['path'] );
    $path = $uploads['path'] . '/pti-elementor-' . bin2hex( random_bytes( 5 ) ) . '.pdf';
    copy( __DIR__ . '/fixtures/one-page.pdf', $path ); $paths[] = $path; $paths[] = $path . '.pti-preview.lock';
    $pdf_id = wp_insert_attachment( array( 'post_title' => 'Elementor PDF fixture', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), $path ); $ids[] = $pdf_id;
    $metadata = wp_generate_attachment_metadata( $pdf_id, $path ); wp_update_attachment_metadata( $pdf_id, $metadata );
    ep_assert( ! empty( $metadata['sizes']['full']['file'] ) && ! wp_attachment_is_image( $pdf_id ), 'A real core-generated preview exists while the attachment remains application/pdf' );
    $post_id = wp_insert_post( array( 'post_title' => 'First PDF card', 'post_type' => 'post', 'post_status' => 'publish', 'post_content' => '[pdf_thumbnail_inserter id="' . $pdf_id . '"]' ) ); $ids[] = $post_id;
    $GLOBALS['wp_query'] = new WP_Query(); $GLOBALS['wp_query']->is_category = true;
    $GLOBALS['post'] = get_post( $post_id ); setup_postdata( $GLOBALS['post'] );
    ep_assert( $pdf_id === get_post_thumbnail_id( $post_id ) && ! get_post_meta( $post_id, '_thumbnail_id', true ), 'The virtual featured ID is the PDF; no stored featured image was assigned' );
    $before = array( get_post_meta( $pdf_id ), get_post_meta( $post_id ), glob( substr( $path, 0, -4 ) . '*' ) );
    $skins = array( 'cards', 'classic', 'full_content', 'archive_cards', 'archive_classic', 'archive_full_content' );
    foreach ( $skins as $skin ) {
        $widget = 0 === strpos( $skin, 'archive_' ) ? 'archive-posts' : 'posts';
        $html = ep_render( $skin, $widget );
        if ( in_array( '--baseline', $argv, true ) ) {
            ep_assert( '' === trim( $html ), $skin . ': reproduces missing thumbnail despite a real PDF preview' );
            continue;
        }
        ep_assert( false !== strpos( $html, '<img' ) && false !== strpos( $html, $metadata['sizes']['full']['file'] ), $skin . ': renders the real PDF preview through the actual Elementor image API' );
        ep_assert( false !== strpos( ep_render( $skin, $widget ), '<img' ), $skin . ': repeated widgets retain their own thumbnail' );
        $off = false !== strpos( $skin, 'full_content' ) ? '' : 'none';
        ep_assert( '' === trim( ep_render( $skin, $widget, array( $skin . '_thumbnail' => $off ) ) ), $skin . ': the image-off control remains effective' );
    }
    if ( ! in_array( '--baseline', $argv, true ) ) {
        $settings = array( '_skin' => 'cards', 'cards_thumbnail_size' => array( 'id' => $pdf_id ), 'cards_thumbnail_size_size' => 'full' );
        $image_api = '\Elementor\Group_Control_Image_Size';
        $custom = '<img src="https://example.invalid/custom.jpg" alt="">';
        ep_assert( $custom === apply_filters( 'elementor/image_size/get_attachment_image_html', $custom, $settings, 'cards_thumbnail_size', 'cards_thumbnail_size' ), 'Nonempty output from Elementor or another extension is preserved verbatim' );
        ep_assert( '' === apply_filters( 'elementor/image_size/get_attachment_image_html', '', array( 'image' => array( 'id' => $pdf_id ) ), 'image', 'image' ), 'Unrelated image controls are unaffected' );
        $explicit = $settings; $explicit['cards_thumbnail_size']['url'] = 'https://example.invalid/explicit.jpg';
        ep_assert( '' === apply_filters( 'elementor/image_size/get_attachment_image_html', '', $explicit, 'cards_thumbnail_size', 'cards_thumbnail_size' ), 'Explicit image URLs are never replaced by a post fallback' );
        $explicit = $settings; $explicit['cards_thumbnail_size']['id'] = $pdf_id + 1000000;
        ep_assert( '' === apply_filters( 'elementor/image_size/get_attachment_image_html', '', $explicit, 'cards_thumbnail_size', 'cards_thumbnail_size' ), 'An unrelated selected attachment is never replaced by a post fallback' );
        $thumbnail = $plugin->resolve_first_pdf_for_post( $post_id, 'thumbnail', false );
        ep_assert( false !== strpos( ep_render( 'cards', 'posts', array( 'cards_thumbnail_size_size' => 'thumbnail' ) ), esc_url( $thumbnail['preview_url'] ) ), 'The configured Elementor image size selects the corresponding existing PDF preview' );
        $image_path = $uploads['path'] . '/pti-elementor-image-' . bin2hex( random_bytes( 5 ) ) . '.png';
        copy( WP_PLUGIN_DIR . '/pdf-thumbnail-inserter/assets/images/pdf-placeholder.png', $image_path ); $paths[] = $image_path;
        $image_id = wp_insert_attachment( array( 'post_title' => 'Original featured image', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $image_path ); $ids[] = $image_id;
        wp_update_attachment_metadata( $image_id, wp_generate_attachment_metadata( $image_id, $image_path ) );
        set_post_thumbnail( $post_id, $image_id );
        ep_assert( false !== strpos( ep_render( 'cards' ), 'wp-image-' . $image_id ) && $image_id === get_post_thumbnail_id( $post_id ), 'A real stored featured image remains the actual Elementor thumbnail' );
        delete_post_thumbnail( $post_id );
        $plain_id = wp_insert_post( array( 'post_title' => 'No document', 'post_type' => 'post', 'post_status' => 'publish', 'post_content' => 'Plain content' ) ); $ids[] = $plain_id;
        $GLOBALS['post'] = get_post( $plain_id ); setup_postdata( $GLOBALS['post'] );
        ep_assert( '' === trim( ep_render( 'cards' ) ), 'A post without a PDF remains without an invented thumbnail' );
        $GLOBALS['post'] = get_post( $post_id ); setup_postdata( $GLOBALS['post'] );
        update_option( PDF_Thumbnail_Inserter::OPTION_NAME, array( 'use_first_pdf_preview_as_featured_image' => 0 ), false );
        ep_assert( '' === trim( ep_render( 'cards' ) ), 'Disabling the plugin fallback removes the Posts thumbnail' );
        update_option( PDF_Thumbnail_Inserter::OPTION_NAME, array( 'use_first_pdf_preview_as_featured_image' => 1 ), false );
        $GLOBALS['wp_query']->is_category = false; $GLOBALS['wp_query']->is_single = true;
        ep_assert( '' === trim( ep_render( 'cards' ) ), 'Single-post contexts do not acquire a listing fallback' );
        $GLOBALS['wp_query']->is_single = false; $GLOBALS['wp_query']->is_category = true;
        $core_files = array();
        foreach ( $metadata['sizes'] as $size ) {
            $image_file = dirname( $path ) . '/' . $size['file'];
            if ( is_file( $image_file ) ) $core_files[ $image_file ] = file_get_contents( $image_file );
        }
        foreach ( $core_files as $image_file => $bytes ) unlink( $image_file );
        delete_post_meta( $pdf_id, '_wp_attachment_metadata' );
        $plugin->invalidate_attachment_lookup_metadata( 0, $pdf_id, '_wp_attached_file' );
        // New request-local caches are invalidated by the same publication/deletion boundary.
        require_once __DIR__ . '/component-access.php';
        $invalidate = pti_test_target( $plugin, 'invalidate_preview_caches' ); $invalidate->invalidate_preview_caches();
        try {
            foreach ( array( 'cards', 'classic' ) as $skin ) {
                $html = ep_render( $skin );
                ep_assert( false !== strpos( $html, 'pti-pdf-canvas-preview' ) && false !== strpos( $html, 'data-pdf-url=' ), $skin . ': absent server preview retains the browser-rendered PDF fallback' );
            }
            ep_assert( wp_script_is( 'pdf-thumbnail-preview-renderer', 'enqueued' ), 'Browser-only thumbnails enqueue the local preview renderer' );
        } finally {
            foreach ( $core_files as $image_file => $bytes ) file_put_contents( $image_file, $bytes );
            wp_update_attachment_metadata( $pdf_id, $metadata );
        }
        $invalidate->invalidate_preview_caches();
    }
    ep_assert( $before === array( get_post_meta( $pdf_id ), get_post_meta( $post_id ), glob( substr( $path, 0, -4 ) . '*' ) ), 'Rendering leaves stored featured metadata, PDF metadata and preview files unchanged' );
    echo wp_json_encode( array( 'status' => 'PASS', 'baseline' => in_array( '--baseline', $argv, true ), 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'elementor' => ELEMENTOR_VERSION, 'elementor_pro' => ELEMENTOR_PRO_VERSION, 'assertions' => $assertions ), JSON_PRETTY_PRINT ) . "\n";
} finally {
    foreach ( array_reverse( $ids ) as $id ) {
        if ( 'attachment' === get_post_type( $id ) ) wp_delete_attachment( $id, true );
        else wp_delete_post( $id, true );
    }
    foreach ( $paths as $path ) if ( is_file( $path ) ) unlink( $path );
    if ( false === $original_settings ) delete_option( PDF_Thumbnail_Inserter::OPTION_NAME ); else update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $original_settings, false );
    $GLOBALS['post'] = $previous_post; $GLOBALS['wp_query'] = $previous_query; wp_reset_postdata();
}
