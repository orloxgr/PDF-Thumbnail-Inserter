<?php
/** Deliberately invalid, analysis-only calls. Never invoke this function. */
function pti_verify_analysis_parameter_contracts() {
    wp_generate_attachment_metadata( 'invalid-id', array() );
    wp_get_attachment_metadata( array(), 'invalid-bool' );
    register_block_type( array(), array( 'api_version' => 'invalid-version' ) );
    shortcode_parse_atts( array() );
}
