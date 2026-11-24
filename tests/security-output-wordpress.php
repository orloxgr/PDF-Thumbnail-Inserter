<?php
/** Real WordPress KSES and plugin rendering, never a live site. */
if ( 'cli' !== PHP_SAPI || 'true' !== getenv( 'PTI_TEST_DISPOSABLE' ) || empty( $argv[1] ) ) exit( 'Disposable CLI site required.' );
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
require_once __DIR__ . '/component-access.php';
if ( 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) throw new RuntimeException( 'Isolated local WordPress required.' );
$plugin = PDF_Thumbnail_Inserter::instance();
$saved = get_option( PDF_Thumbnail_Inserter::OPTION_NAME, false );
$assertions = array();
function so_assert( $condition, $message ) {
    if ( ! $condition ) throw new RuntimeException( $message );
    $GLOBALS['assertions'][] = $message;
}
function so_dom( $html ) {
    $dom = new DOMDocument();
    $prior = libxml_use_internal_errors( true );
    $dom->loadHTML( '<!DOCTYPE html><html><body>' . $html . '</body></html>' );
    libxml_clear_errors(); libxml_use_internal_errors( $prior );
    return $dom;
}
function so_safe( $html, $message ) {
    $dom = so_dom( $html );
    $xpath = new DOMXPath( $dom );
    so_assert( 0 === $xpath->query( '//script|//iframe|//object|//embed|//@*[starts-with(translate(name(), "ON", "on"), "on")]' )->length, $message . ': no executable nodes or event attributes' );
    foreach ( $xpath->query( '//@href|//@src|//@data-pdf-url' ) as $attr ) {
        so_assert( ! preg_match( '/^\s*(?:javascript|vbscript|data):/i', $attr->value ), $message . ': safe URL protocol' );
    }
}
try {
    update_option( PDF_Thumbnail_Inserter::OPTION_NAME, array(), false );
    $cards = pti_test_target( $plugin, 'render_legacy_url_card' );
    $atts = $cards->get_shortcode_atts( array( 'url' => home_url( '/fixture.pdf' ), 'thumbnail' => home_url( '/fixture.jpg' ), 'title' => '\"><img src=x onerror=alert(1)> R&D', 'button_text' => '<script>alert(1)</script>Download' ) );
    $html = $cards->render_legacy_url_card( $atts, array( 'target_attr' => ' target="_blank" onclick="alert(1)"', 'rel_attr' => ' rel="noopener" onmouseover="alert(1)"', 'button_text' => '<img src=x onerror=alert(1)>' ) );
    so_safe( $html, 'Hostile compatibility context' );
    so_assert( false === strpos( $html, 'target=' ) && false === strpos( $html, 'rel=' ), 'Malformed attribute fragments are rejected as a whole' );
    $html = $cards->render_legacy_url_card( $atts, array( 'target_attr' => ' target="_blank"', 'rel_attr' => ' rel="nofollow sponsored"' ) );
    $xpath = new DOMXPath( so_dom( $html ) );
    so_assert( 3 === $xpath->query( '//a[@target="_blank" and contains(@rel,"nofollow") and contains(@rel,"sponsored") and contains(@rel,"noopener") and contains(@rel,"noreferrer")]' )->length, 'Valid legacy new-tab/rel options and protective tokens survive on all three card links' );
    $html = $cards->render_legacy_url_card( $atts, array( 'target_attr' => ' target="_self"', 'rel_attr' => ' rel="nofollow"' ) );
    so_assert( false !== strpos( $html, 'target="_self"' ) && false === strpos( $html, 'noopener' ), 'Existing same-tab target remains supported' );
    $html = $plugin->render_shortcode( array( 'url' => 'javascript:alert(1)', 'thumbnail' => 'javascript:alert(2)', 'title' => 'Bad URL' ) );
    so_assert( '' === $html, 'Shortcode rejects a JavaScript document URL' );
    $html = $plugin->render_shortcode( array( 'url' => home_url( '/fixture.pdf' ), 'thumbnail' => 'javascript:alert(2)', 'title' => '\"><script>alert(1)</script>', 'class' => 'good" onclick="alert(1)', 'rel' => 'nofollow" onclick="alert(1)' ) );
    so_safe( $html, 'Hostile shortcode fields' );
    $viewer = pti_test_target( $plugin, 'render_pdf_canvas_preview' );
    $canvas = $viewer->render_pdf_canvas_preview( home_url( '/fixture.pdf?x=1&y=2' ), 'R&D "Title"', 'pti-card__thumb' );
    $filtered = wp_kses( $canvas, PDF_Thumbnail_Inserter_HTML::media() );
    $xpath = new DOMXPath( so_dom( $filtered ) );
    so_assert( 1 === $xpath->query( '//canvas[@aria-hidden="true"]' )->length, 'Real KSES preserves the browser-rendering canvas' );
    so_assert( 1 === $xpath->query( '//span[@data-pdf-url and @role="img" and @aria-label]' )->length, 'Real KSES preserves the PDF URL and accessible fallback wrapper' );
    so_assert( false !== strpos( $filtered, 'pti-pdf-canvas-preview__status' ), 'Real KSES preserves loading/error status markup' );
    $attack = '<script>alert(1)</script><iframe src="https://example.invalid"></iframe><img src="javascript:alert(1)" onerror="alert(1)"><span onclick="alert(1)" data-secret="x">safe</span>';
    $clean = wp_kses( $attack, PDF_Thumbnail_Inserter_HTML::media() );
    so_safe( $clean, 'Media output schema' );
    so_assert( false === strpos( $clean, 'data-secret' ), 'Media schema rejects unrelated data attributes' );
    $responsive = '<picture><source srcset="' . esc_url( home_url( '/a.webp' ) ) . ' 600w" type="image/webp" media="(min-width: 600px)"><img src="' . esc_url( home_url( '/a.jpg' ) ) . '" srcset="' . esc_url( home_url( '/b.jpg' ) ) . ' 1200w" sizes="100vw" width="600" height="800" loading="lazy" decoding="async" fetchpriority="low" alt="PDF"></picture>';
    $clean = wp_kses( $responsive, PDF_Thumbnail_Inserter_HTML::media() );
    $xpath = new DOMXPath( so_dom( $clean ) );
    so_assert( 1 === $xpath->query( '//picture/source[@srcset and @type and @media]' )->length && 1 === $xpath->query( '//img[@srcset and @sizes and @width and @height and @loading and @decoding and @fetchpriority]' )->length, 'Real KSES preserves responsive images and browser loading attributes' );
    foreach ( array( 'portrait', 'landscape' ) as $shape ) {
        $svg = $viewer->render_document_cover_svg( 'R&D <script>alert(1)</script> "Title"', $shape );
        $clean = $svg;
        $xml = new DOMDocument();
        so_assert( $xml->loadXML( $clean ) && 'http://www.w3.org/2000/svg' === $xml->documentElement->namespaceURI, $shape . ': cover remains valid standalone SVG' );
        so_assert( $xml->documentElement->hasAttribute( 'viewBox' ) && $xml->documentElement->hasAttribute( 'aria-label' ), $shape . ': geometry and accessible label survive rendering' );
        so_assert( $xml->getElementsByTagName( 'path' )->length > 0 && $xml->getElementsByTagName( 'tspan' )->length > 0 && false !== strpos( $clean, 'R&amp;D' ), $shape . ': document shapes and correctly encoded title survive rendering' );
        so_safe( $clean, $shape . ' escaped SVG template' );
        so_assert( false === strpos( $clean, 'foreignObject' ) && false === strpos( $clean, '<a ' ), $shape . ': no foreign content or links in the fixed template' );
    }
    echo wp_json_encode( array( 'status' => 'PASS', 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'assertions' => $assertions ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
} finally {
    if ( false === $saved ) delete_option( PDF_Thumbnail_Inserter::OPTION_NAME );
    else update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $saved, false );
}
