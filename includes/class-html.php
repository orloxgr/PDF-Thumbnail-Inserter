<?php
/** Narrow output schemas for the plugin's generated markup. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_HTML {
    /** @return array<string, array<string, bool>> */
    public static function media() {
        $common = array( 'class' => true, 'title' => true, 'role' => true, 'aria-label' => true, 'aria-hidden' => true );
        return array(
            'div' => $common,
            'figure' => $common,
            'figcaption' => $common,
            'a' => array_merge( $common, array( 'href' => true, 'target' => true, 'rel' => true ) ),
            'span' => array_merge( $common, array( 'data-pdf-url' => true ) ),
            'canvas' => array( 'aria-hidden' => true, 'width' => true, 'height' => true ),
            'img' => array_merge( $common, array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'srcset' => true, 'sizes' => true, 'loading' => true, 'decoding' => true, 'fetchpriority' => true ) ),
            'picture' => $common,
            'source' => array( 'srcset' => true, 'sizes' => true, 'type' => true, 'media' => true ),
        );
    }

    /** Preserve the legacy context API without printing caller-supplied attribute fragments. @return array<string, string> */
    public static function link_attributes( array $context ) {
        $target = '';
        $rel = '';
        if ( isset( $context['target_attr'] ) && is_string( $context['target_attr'] )
            && preg_match( '/^\s*target=([\x22\x27])(_blank|_self|_parent|_top)\1\s*$/D', $context['target_attr'], $match ) ) {
            $target = $match[2];
        }
        if ( isset( $context['rel_attr'] ) && is_string( $context['rel_attr'] )
            && preg_match( '/^\s*rel=([\x22\x27])([a-zA-Z0-9_\-\s]*)\1\s*$/D', $context['rel_attr'], $match ) ) {
            $rel = implode( ' ', array_unique( array_filter( array_map( 'sanitize_key', preg_split( '/\s+/', trim( $match[2] ) ) ) ) ) );
        }
        if ( '_blank' === $target ) {
            $rel = implode( ' ', array_unique( array_filter( explode( ' ', $rel . ' noopener noreferrer' ) ) ) );
        }
        return array( 'target' => $target, 'rel' => $rel );
    }
}
