<?php
/** Bootstrap/API/hook contract regression, without a WordPress installation. */
if ( 'cli' !== PHP_SAPI ) exit;
define( 'ABSPATH', __DIR__ . '/' );
$pti_hooks = array();
function plugin_dir_path( $file ) { return str_replace( '\\', '/', dirname( $file ) ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.invalid/wp-content/plugins/pdf-thumbnail-inserter/'; }
function plugin_basename( $file ) { return 'pdf-thumbnail-inserter/' . basename( $file ); }
function pti_record_hook( $kind, $args ) {
    if ( ! is_callable( $args[1] ) ) throw new RuntimeException( 'Non-callable hook: ' . $args[0] );
    $args[1] = array( get_class( $args[1][0] ), $args[1][1] );
    $GLOBALS['pti_hooks'][] = array( $kind, $args );
}
function add_action( ...$args ) { pti_record_hook( 'action', $args ); }
function add_filter( ...$args ) { pti_record_hook( 'filter', $args ); }
function add_shortcode( ...$args ) { pti_record_hook( 'shortcode', $args ); }

$entry = getenv( 'PTI_TEST_PLUGIN' ) ?: dirname( __DIR__ ) . '/pdf-thumbnail-inserter.php';
ob_start();
require $entry;
$first = PDF_Thumbnail_Inserter::instance();
require $entry;
$output = ob_get_clean();
if ( '' !== $output || $first !== PDF_Thumbnail_Inserter::instance() ) throw new RuntimeException( 'Bootstrap output or duplicate instance' );
if ( realpath( $entry ) !== realpath( PDF_THUMBNAIL_INSERTER_FILE )
    || plugin_dir_path( $entry ) !== PDF_THUMBNAIL_INSERTER_PATH
    || plugin_dir_url( $entry ) !== PDF_THUMBNAIL_INSERTER_URL ) throw new RuntimeException( 'Bootstrap paths no longer refer to the plugin root' );

$class = new ReflectionClass( $first );
$api = array();
foreach ( $class->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
    $parameters = array();
    foreach ( $method->getParameters() as $parameter ) {
        $parameters[] = array(
            'name' => $parameter->getName(),
            'type' => $parameter->getType() ? $parameter->getType()->getName() : '',
            'nullable' => $parameter->getType() ? $parameter->getType()->allowsNull() : true,
            'optional' => $parameter->isOptional(),
            'variadic' => $parameter->isVariadic(),
            'reference' => $parameter->isPassedByReference(),
            'default' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
        );
    }
    $api[ $method->getName() ] = array( 'static' => $method->isStatic(), 'return' => $method->getReturnType() ? $method->getReturnType()->getName() : '', 'parameters' => $parameters );
}
ksort( $api );
$constants = $class->getConstants();
unset( $constants['VERSION'] );
$contract = array( 'final' => $class->isFinal(), 'constants' => $constants, 'public_api' => $api, 'hooks' => $pti_hooks );
if ( in_array( '--snapshot', $argv, true ) ) {
    echo json_encode( $contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    exit;
}
$expected = json_decode( file_get_contents( __DIR__ . '/fixtures/plugin-contract-2.01.01.json' ), true );
$expected['hooks'][] = array( 'filter', array( 'elementor/image_size/get_attachment_image_html', array( 'PDF_Thumbnail_Inserter_Elementor', 'filter_posts_thumbnail_html' ), 20, 4 ) );
if ( $contract !== $expected ) throw new RuntimeException( 'Public API or ordered WordPress callbacks differ from release 2.01.01' );
echo 'PASS: ' . count( $api ) . ' public method signatures, ' . count( $pti_hooks ) . " ordered callbacks, constants, root paths and idempotent bootstrap.\n";
