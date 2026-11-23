<?php
/** Test-only access to internal components; also accepts the published monolithic baseline. */
function pti_test_target( $plugin, $method ) {
    if ( method_exists( $plugin, $method ) ) return $plugin;
    $targets = array();
    foreach ( ( new ReflectionObject( $plugin ) )->getProperties() as $property ) {
        if ( $property->isStatic() ) continue;
        $property->setAccessible( true );
        $component = $property->getValue( $plugin );
        if ( is_object( $component ) && 0 === strpos( get_class( $component ), 'PDF_Thumbnail_Inserter_' ) && method_exists( $component, $method ) ) {
            $targets[] = $component;
        }
    }
    if ( 1 !== count( $targets ) ) throw new RuntimeException( 'Expected one component owning ' . $method );
    return $targets[0];
}

function pti_test_state( $plugin ) {
    $reflection = new ReflectionObject( $plugin );
    if ( ! $reflection->hasProperty( 'state' ) ) return $plugin;
    $property = $reflection->getProperty( 'state' );
    $property->setAccessible( true );
    return $property->getValue( $plugin );
}
