<?php
/** Boundary regressions for the full readiness audit. Run with PHP 7.4 or later. */
if ( 'cli' !== PHP_SAPI ) {
    exit;
}

function is_admin() { return false; }
function wp_doing_ajax() { return false; }

require __DIR__ . '/preview-regression.php';
$failures = array();

// Reject nested values before calling WordPress scalar sanitizers or casting them.
$defaults = call_private( 'get_defaults' );
foreach ( array( array( 'bad' ), (object) array( 'bad' => 1 ) ) as $invalid ) {
    foreach ( $defaults as $key => $default ) {
        try {
            $result = $plugin->sanitize_settings( array( $key => $invalid ) );
            $is_checkbox = in_array( $key, array( 'default_new_tab', 'default_show_title', 'default_show_button', 'use_iframe_pdf_previews', 'convert_gutenberg_pdf_links_to_cards', 'convert_gutenberg_doc_links_to_cards', 'use_first_pdf_preview_as_featured_image', 'use_default_document_cover', 'auto_browser_preview_on_upload' ), true );
            $expected = $is_checkbox ? 0 : $default;
            check( $expected === $result[ $key ], 'Malformed setting must fall back safely: ' . $key );
        } catch ( Throwable $error ) {
            check( false, 'Malformed setting throws for ' . $key . ': ' . $error->getMessage() );
        }
    }
}

// Loading the module must preserve CSP, integrity, strategy and extension attributes.
foreach ( array( null, 'text/javascript', 'module' ) as $type ) {
    $attributes = array( 'src' => '/preview.js', 'id' => 'pdf-thumbnail-viewer-js', 'nonce' => 'csp-test', 'integrity' => 'sha256-example', 'crossorigin' => 'anonymous', 'data-extension' => 'kept' );
    if ( null !== $type ) $attributes['type'] = $type;
    $result = $plugin->add_module_attributes_to_preview_renderer( $attributes );
    check( 'module' === $result['type'], 'Module type is set' );
    foreach ( array( 'src', 'id', 'nonce', 'integrity', 'crossorigin', 'data-extension' ) as $key ) {
        check( $attributes[ $key ] === $result[ $key ], 'Module preserves ' . $key );
    }
    $attributes['id'] = 'other-plugin-js';
    check( $attributes === $plugin->add_module_attributes_to_preview_renderer( $attributes ), 'Other script handles remain untouched' );
}

// A core/file PDF with its preview enabled is explicitly covered by the setting.
$pdf_url = wp_get_attachment_url( 42 );
$embed = '<div class="wp-block-file"><object class="wp-block-file__embed" data="' . esc_attr( $pdf_url ) . '" type="application/pdf"></object><a href="' . esc_attr( $pdf_url ) . '">PDF</a></div>';
foreach ( array( false, true ) as $convert ) {
    foreach ( array( false, true ) as $iframe ) {
        $options[PDF_Thumbnail_Inserter::OPTION_NAME] = array(
            'convert_gutenberg_pdf_links_to_cards' => $convert,
            'use_iframe_pdf_previews' => $iframe,
        );
        $result = $plugin->replace_pdf_file_block_preview( $embed, array( 'attrs' => array( 'id' => 42, 'displayPreview' => true ) ) );
        if ( $convert ) {
            check( false !== strpos( $result, 'pti-card' ) && false === strpos( $result, '<object' ), 'PDF conversion includes existing previews, with iframe=' . (int) $iframe );
        } else {
            // No readable local file in this boundary fixture: viewer must preserve embed.
            check( $embed === $result, 'Conversion off retains an unsupported original embed' );
        }
    }
}
$options = array();

foreach ( array( -2, '-2', '2.5', array( 2 ), (object) array( 'column' => 2 ) ) as $invalid ) {
    check( 3 === call_private( 'clamp_columns', $invalid, 3 ), 'Columns reject negative, fractional and nested values' );
}
$atts = call_private( 'get_shortcode_atts', array( 'id' => 42 ) );
foreach ( array_keys( $atts ) as $key ) {
    $malformed = call_private( 'get_shortcode_atts', array( $key => array( array( 'nested' ) ) ) );
    check( 'ids' === $key ? array() === $malformed[ $key ] : is_scalar( $malformed[ $key ] ), 'Shortcode rejects nested attribute ' . $key );
}
$options[PDF_Thumbnail_Inserter::OPTION_NAME] = array( 'title_color' => array( 'bad' ), 'button_text' => (object) array( 'bad' => 1 ) );
$stored = call_private( 'get_settings' );
check( '#333333' === $stored['title_color'] && is_string( $stored['button_text'] ), 'Malformed previously stored options cannot break rendering' );
$options = array();

if ( $failures ) {
    fwrite( STDERR, implode( "\n", $failures ) . "\n" );
    exit( 1 );
}
echo "PASS: malformed settings, module attributes and PDF preview conversion combinations (boundary tests).\n";
