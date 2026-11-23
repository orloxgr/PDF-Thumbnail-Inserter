<?php
/** Translations subsystem. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_Translations {
        public function load_textdomain() {
            global $wp_textdomain_registry;
            if ( $wp_textdomain_registry instanceof WP_Textdomain_Registry ) {
                // Modern core loads the catalogue just in time and handles locale switching.
                $wp_textdomain_registry->set_custom_path( 'pdf-thumbnail-inserter', PDF_THUMBNAIL_INSERTER_PATH . 'languages' );
                return;
            }
            // WP 6.0 JIT knows only WP_LANG_DIR; retain GitHub-local catalogues on that core.
            get_translations_for_domain( 'pdf-thumbnail-inserter' );
            if ( ! is_textdomain_loaded( 'pdf-thumbnail-inserter' ) ) {
                $locale = determine_locale();
                if ( preg_match( '/^[A-Za-z0-9_-]+$/D', $locale ) ) {
                    $language_pack = WP_LANG_DIR . '/plugins/pdf-thumbnail-inserter-' . $locale . '.mo';
                    if ( is_readable( $language_pack ) && load_textdomain( 'pdf-thumbnail-inserter', $language_pack ) ) return;
                    $catalogue = PDF_THUMBNAIL_INSERTER_PATH . 'languages/pdf-thumbnail-inserter-' . $locale . '.mo';
                    if ( is_readable( $catalogue ) ) load_textdomain( 'pdf-thumbnail-inserter', $catalogue );
                }
            }
        }
}
