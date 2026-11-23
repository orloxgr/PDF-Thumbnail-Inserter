<?php
/** Featured images subsystem. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_FeaturedImages {
    /** @var PDF_Thumbnail_Inserter_Discovery */
    private $discovery;

    /** @var PDF_Thumbnail_Inserter_ListingFilters */
    private $listing_filters;

    /** @var PDF_Thumbnail_Inserter_Settings */
    private $settings;

    /** @var PDF_Thumbnail_Inserter_RuntimeState */
    private $state;

    /** @var PDF_Thumbnail_Inserter_Viewer */
    private $viewer;

    /** Wire the request-local collaborators before registering any hooks. */
    public function set_dependencies( PDF_Thumbnail_Inserter_Discovery $discovery, PDF_Thumbnail_Inserter_ListingFilters $listing_filters, PDF_Thumbnail_Inserter_Settings $settings, PDF_Thumbnail_Inserter_RuntimeState $state, PDF_Thumbnail_Inserter_Viewer $viewer ) {
        $this->discovery = $discovery;
        $this->listing_filters = $listing_filters;
        $this->settings = $settings;
        $this->state = $state;
        $this->viewer = $viewer;
    }

        public function invalidate_preview_caches() {
            $this->state->first_pdf_resolver_cache = array();
            $this->state->pdf_featured_image_cache = array();
        }

        public function get_pdf_featured_image_fallback_data( $post_id, $size = 'full' ) {
            $post_id = absint( $post_id );
            if ( ! $post_id ) {
                return array(
                    'id'  => 0,
                    'url' => '',
                );
            }

            $cache_key = $post_id . ':' . md5( wp_json_encode( $size ) );
            if ( array_key_exists( $cache_key, $this->state->pdf_featured_image_cache ) ) {
                return $this->state->pdf_featured_image_cache[ $cache_key ];
            }

            $fallback = array(
                'id'      => 0,
                'url'     => '',
                'pdf_url' => '',
            );
            $this->state->pdf_featured_image_cache[ $cache_key ] = $fallback;

            $resolved = $this->discovery->resolve_first_pdf_for_post( $post_id, $size, false );
            if ( empty( $resolved ) || empty( $resolved['pdf_url'] ) ) {
                $this->state->pdf_featured_image_cache[ $cache_key ] = $fallback;
                return $fallback;
            }

            $fallback['pdf_url'] = esc_url_raw( $resolved['pdf_url'] );
            $fallback['url']     = ! empty( $resolved['preview_url'] ) ? esc_url_raw( $resolved['preview_url'] ) : '';

            if ( '' !== $fallback['url'] ) {
                $preview_id = ! empty( $resolved['preview_id'] ) ? absint( $resolved['preview_id'] ) : 0;
                $pdf_id     = ! empty( $resolved['attachment_id'] ) ? absint( $resolved['attachment_id'] ) : 0;

                if ( $preview_id && 0 === strpos( (string) get_post_mime_type( $preview_id ), 'image/' ) ) {
                    $fallback['id'] = $preview_id;
                } elseif ( $pdf_id && 'application/pdf' === get_post_mime_type( $pdf_id ) ) {
                    $fallback['id'] = $pdf_id;
                }
            }

            if ( '' === $fallback['url'] ) {
                $pdf_title = ! empty( $resolved['attachment_id'] ) ? get_the_title( $resolved['attachment_id'] ) : '';
                $fallback['url'] = $this->viewer->get_document_cover_url( $fallback['pdf_url'], $pdf_title );
            }

            $this->state->pdf_featured_image_cache[ $cache_key ] = $fallback;

            return $fallback;
        }

        public function get_pdf_featured_image_fallback_id( $post_id ) {
            $fallback = $this->get_pdf_featured_image_fallback_data( $post_id, 'medium_large' );

            return ! empty( $fallback['id'] ) ? absint( $fallback['id'] ) : 0;
        }

        public function should_use_first_pdf_preview_as_featured_image( $post ) {
            $settings = $this->settings->get_settings();
            if ( empty( $settings['use_first_pdf_preview_as_featured_image'] ) ) {
                return false;
            }

            if ( $this->listing_filters->should_skip_frontend_list_filters() ) {
                return false;
            }

            $post = get_post( $post );
            if ( ! $post || 'post' !== $post->post_type ) {
                return false;
            }

            // Keep this limited to listing contexts. Single posts render their attachments normally.
            return is_category() || is_home() || $this->listing_filters->is_news_page_request();
        }

        public function category_visual_has_rendered( $post_id ) {
            return ! empty( $this->state->category_visual_rendered[ absint( $post_id ) ] );
        }

        public function mark_category_visual_rendered( $post_id ) {
            $post_id = absint( $post_id );
            if ( $post_id ) {
                $this->state->category_visual_rendered[ $post_id ] = true;
            }
        }

        public function has_real_featured_image_id( $post_id ) {
            return absint( get_post_meta( absint( $post_id ), '_thumbnail_id', true ) );
        }

        public function use_first_pdf_preview_as_featured_image( $thumbnail_id, $post ) {
            if ( $thumbnail_id || ! $this->should_use_first_pdf_preview_as_featured_image( $post ) ) {
                return $thumbnail_id;
            }

            $post        = get_post( $post );
            $fallback_id = $this->get_pdf_featured_image_fallback_id( $post->ID );

            return $fallback_id ? $fallback_id : $thumbnail_id;
        }

        public function has_first_pdf_preview_as_featured_image( $has_thumbnail, $post, $thumbnail_id ) {
            if ( $has_thumbnail || $thumbnail_id || ! $this->should_use_first_pdf_preview_as_featured_image( $post ) ) {
                return $has_thumbnail;
            }

            $post = get_post( $post );
            if ( ! $post ) {
                return $has_thumbnail;
            }

            $fallback = $this->get_pdf_featured_image_fallback_data( $post->ID, 'medium_large' );

            return ( ! empty( $fallback['url'] ) || ! empty( $fallback['pdf_url'] ) ) ? true : $has_thumbnail;
        }

        public function render_first_pdf_preview_as_featured_image( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
            $post_id = absint( $post_id );

            if ( ! $this->should_use_first_pdf_preview_as_featured_image( $post_id ) ) {
                return $html;
            }

            if ( '' !== $html ) {
                $this->mark_category_visual_rendered( $post_id );
                return $html;
            }

            if ( $this->category_visual_has_rendered( $post_id ) ) {
                return '';
            }

            $fallback = $this->get_pdf_featured_image_fallback_data( $post_id, $size );
            if ( empty( $fallback['url'] ) && empty( $fallback['pdf_url'] ) ) {
                return $html;
            }

            if ( ! empty( $fallback['id'] ) ) {
                $image_html = wp_get_attachment_image( absint( $fallback['id'] ), $size, false, $attr );
                if ( $image_html ) {
                    $this->mark_category_visual_rendered( $post_id );
                    return $image_html;
                }
            }

            if ( empty( $fallback['url'] ) && ! empty( $fallback['pdf_url'] ) ) {
                $canvas_html = $this->viewer->render_pdf_canvas_preview( $fallback['pdf_url'], get_the_title( $post_id ) );
                if ( '' !== $canvas_html ) {
                    $this->mark_category_visual_rendered( $post_id );
                    return $canvas_html;
                }
            }

            $attributes = wp_parse_args( $attr, array() );
            $size_class = is_string( $size ) ? sanitize_html_class( $size ) : 'medium_large';
            $classes    = array( 'attachment-' . $size_class, 'size-' . $size_class, 'wp-post-image', 'pti-featured-image-or-pdf-preview' );

            if ( ! empty( $attributes['class'] ) ) {
                $classes[] = $attributes['class'];
            }

            $attributes['class']    = implode( ' ', array_filter( $classes ) );
            $attributes['src']      = esc_url_raw( $fallback['url'] );
            $attributes['alt']      = isset( $attributes['alt'] ) ? $attributes['alt'] : get_the_title( $post_id );
            $attributes['decoding'] = isset( $attributes['decoding'] ) ? $attributes['decoding'] : 'async';

            $html_attributes = '';
            foreach ( $attributes as $name => $value ) {
                if ( false === $value || null === $value || '' === $value ) {
                    continue;
                }

                $html_attributes .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
            }

            $this->mark_category_visual_rendered( $post_id );
            return '<img' . $html_attributes . '>';
        }

        public function get_current_render_post_id() {
            $candidate_id = absint( get_the_ID() );
            if ( $candidate_id && 'post' === get_post_type( $candidate_id ) ) {
                return $candidate_id;
            }

            global $post;

            if ( $post instanceof \WP_Post && 'post' === $post->post_type ) {
                return absint( $post->ID );
            }

            return 0;
        }

        public function render_listing_pdf_preview_media( $post_id, $preview_url, $pdf_url = '' ) {
            $post_id     = absint( $post_id );
            $preview_url = esc_url_raw( $preview_url );
            $pdf_url     = esc_url_raw( $pdf_url );
            if ( ! $post_id || ( '' === $preview_url && '' === $pdf_url ) ) {
                return '';
            }

            $title     = get_the_title( $post_id );
            $permalink = get_permalink( $post_id );
            $image     = '';

            if ( '' !== $preview_url ) {
                $image = sprintf(
                    '<img class="pti-featured-image-or-pdf-preview wp-post-image" src="%1$s" alt="%2$s" loading="lazy" decoding="async">',
                    esc_url( $preview_url ),
                    esc_attr( $title )
                );
            } elseif ( '' !== $pdf_url ) {
                $image = $this->viewer->render_pdf_canvas_preview( $pdf_url, $title );
            }

            if ( '' === $image ) {
                return '';
            }

            if ( $permalink ) {
                $image = sprintf(
                    '<a class="pti-featured-media-link" href="%1$s">%2$s</a>',
                    esc_url( $permalink ),
                    $image
                );
            }

            return '<div class="blog-post_media pti-listing-pdf-preview">' . $image . '</div>';
        }
}
