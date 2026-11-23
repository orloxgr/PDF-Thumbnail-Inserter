<?php
/** Reference lookup subsystem. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_ReferenceLookup {
    /** @var PDF_Thumbnail_Inserter_Discovery */
    private $discovery;

    /** @var PDF_Thumbnail_Inserter_FileResolver */
    private $file_resolver;

    /** Wire the request-local collaborators before registering any hooks. */
    public function set_dependencies( PDF_Thumbnail_Inserter_Discovery $discovery, PDF_Thumbnail_Inserter_FileResolver $file_resolver ) {
        $this->discovery = $discovery;
        $this->file_resolver = $file_resolver;
    }

        public function normalize_reference_pdf_title( $title ) {
            $title = html_entity_decode( (string) $title, ENT_QUOTES, get_bloginfo( 'charset' ) );
            $title = wp_strip_all_tags( $title );
            $title = preg_replace( '/\.pdf$/iu', '', $title );
            $title = preg_replace( '/\s+/u', ' ', $title );

            return trim( $title );
        }

        public function normalize_reference_pdf_lookup_text( $text ) {
            $text = html_entity_decode( (string) $text, ENT_QUOTES, get_bloginfo( 'charset' ) );
            $text = rawurldecode( $text );
            $text = wp_strip_all_tags( $text );
            $text = preg_replace( '/\.pdf$/iu', '', $text );

            if ( function_exists( 'mb_strtolower' ) ) {
                $text = mb_strtolower( $text, get_bloginfo( 'charset' ) );
            } else {
                $text = strtolower( $text );
            }

            $text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
            $text = preg_replace( '/\s+/u', ' ', $text );

            return trim( $text );
        }

        public function get_reference_pdf_title_tokens( $title ) {
            $normalized = $this->normalize_reference_pdf_lookup_text( $title );
            if ( '' === $normalized ) {
                return array();
            }

            $tokens = preg_split( '/\s+/u', $normalized );
            $tokens = array_filter(
                array_unique( array_map( 'trim', (array) $tokens ) ),
                function ( $token ) {
                    $length = function_exists( 'mb_strlen' ) ? mb_strlen( $token, get_bloginfo( 'charset' ) ) : strlen( $token );
                    return $length >= 3;
                }
            );

            usort(
                $tokens,
                function ( $left, $right ) {
                    $left_length  = function_exists( 'mb_strlen' ) ? mb_strlen( $left, get_bloginfo( 'charset' ) ) : strlen( $left );
                    $right_length = function_exists( 'mb_strlen' ) ? mb_strlen( $right, get_bloginfo( 'charset' ) ) : strlen( $right );
                    return $right_length <=> $left_length;
                }
            );

            return $tokens;
        }

        public function score_reference_pdf_candidate( $attachment_id, $tokens ) {
            $attachment_id = absint( $attachment_id );
            $tokens        = array_values( array_filter( (array) $tokens ) );
            if ( ! $attachment_id || empty( $tokens ) ) {
                return 0;
            }

            $file_url = (string) wp_get_attachment_url( $attachment_id );
            $haystack = implode(
                ' ',
                array_filter(
                    array(
                        get_the_title( $attachment_id ),
                        basename( $this->file_resolver->get_url_path( $file_url ) ),
                        get_post_meta( $attachment_id, '_wp_attached_file', true ),
                    )
                )
            );
            $haystack = $this->normalize_reference_pdf_lookup_text( $haystack );

            if ( '' === $haystack ) {
                return 0;
            }

            $score = 0;
            foreach ( $tokens as $token ) {
                if ( false !== strpos( $haystack, $token ) ) {
                    $score++;
                }
            }

            return $score;
        }

        public function get_pdf_file_data_from_loose_reference_title( $title ) {
            $tokens = $this->get_reference_pdf_title_tokens( $title );
            if ( empty( $tokens ) ) {
                return array();
            }

            $attachment_ids = $this->file_resolver->find_attachment_filename_candidates( array_slice( $tokens, 0, 6 ), 'reference' );

            $best_data  = array();
            $best_score = 0;
            foreach ( array_values( array_unique( array_filter( $attachment_ids ) ) ) as $attachment_id ) {
                $file_data = $this->discovery->get_pdf_file_data_from_attachment_id( $attachment_id );
                if ( empty( $file_data['is_pdf'] ) ) {
                    continue;
                }

                $score = $this->score_reference_pdf_candidate( $attachment_id, $tokens );
                if ( $score > $best_score ) {
                    $best_score = $score;
                    $best_data  = $file_data;
                }
            }

            $minimum_score = min( 3, max( 2, (int) ceil( count( $tokens ) / 3 ) ) );
            return $best_score >= $minimum_score ? $best_data : array();
        }

        public function get_pdf_file_data_from_reference_title( $title ) {
            $title = $this->normalize_reference_pdf_title( $title );
            if ( '' === $title ) {
                return array();
            }

            $attachment_ids = array();
            $query_sets     = array(
                array( 'title' => $title ),
                array( 's' => $title ),
            );

            foreach ( $query_sets as $query_args ) {
                $found_ids = get_posts(
                    array_merge(
                        array(
                            'post_type'      => 'attachment',
                            'post_status'    => 'inherit',
                            'posts_per_page' => 10,
                            'fields'         => 'ids',
                            'no_found_rows'  => true,
                        ),
                        $query_args
                    )
                );

                if ( ! empty( $found_ids ) ) {
                    $attachment_ids = array_merge( $attachment_ids, array_map( 'absint', $found_ids ) );
                }
            }

            $file_variants = array_values(
                array_unique(
                    array_filter(
                        array(
                            $title,
                            str_replace( ' ', '-', $title ),
                            str_replace( ' ', '_', $title ),
                            rawurlencode( $title ),
                            rawurlencode( str_replace( ' ', '-', $title ) ),
                            rawurlencode( str_replace( ' ', '_', $title ) ),
                        )
                    )
                )
            );

            $attachment_ids = array_merge( $attachment_ids, $this->file_resolver->find_attachment_filename_candidates( $file_variants ) );

            foreach ( array_values( array_unique( array_filter( $attachment_ids ) ) ) as $attachment_id ) {
                $file_data = $this->discovery->get_pdf_file_data_from_attachment_id( $attachment_id );
                if ( ! empty( $file_data['is_pdf'] ) ) {
                    return $file_data;
                }
            }

            return $this->get_pdf_file_data_from_loose_reference_title( $title );
        }

        public function get_first_pdf_from_reference_atts( $atts ) {
            if ( ! is_array( $atts ) ) {
                return array();
            }

            if ( ! empty( $atts['url'] ) ) {
                $file_data = $this->discovery->get_pdf_file_data_from_url( $atts['url'] );
                if ( ! empty( $file_data['is_pdf'] ) ) {
                    return $file_data;
                }
            }

            $raw_ids = array();
            if ( ! empty( $atts['ids'] ) ) {
                $raw_ids = is_array( $atts['ids'] ) ? $atts['ids'] : preg_split( '/[\s,]+/', (string) $atts['ids'] );
            }

            if ( ! empty( $atts['id'] ) ) {
                array_unshift( $raw_ids, $atts['id'] );
            }

            foreach ( (array) $raw_ids as $raw_id ) {
                $file_data = $this->discovery->get_pdf_file_data_from_attachment_id( $raw_id );
                if ( ! empty( $file_data['is_pdf'] ) ) {
                    return $file_data;
                }
            }

            if ( ! empty( $atts['title'] ) ) {
                $file_data = $this->get_pdf_file_data_from_reference_title( $atts['title'] );
                if ( ! empty( $file_data['is_pdf'] ) ) {
                    return $file_data;
                }
            }

            return array();
        }
}
