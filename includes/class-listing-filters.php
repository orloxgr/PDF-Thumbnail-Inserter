<?php
/** Listing filters subsystem. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_ListingFilters {
    /** @var PDF_Thumbnail_Inserter */
    private $callbacks;

    /** @var PDF_Thumbnail_Inserter_FeaturedImages */
    private $featured_images;

    /** @var PDF_Thumbnail_Inserter_Settings */
    private $settings;

    /** @var PDF_Thumbnail_Inserter_RuntimeState */
    private $state;

    /** Wire the request-local collaborators before registering any hooks. */
    public function set_dependencies( PDF_Thumbnail_Inserter $callbacks, PDF_Thumbnail_Inserter_FeaturedImages $featured_images, PDF_Thumbnail_Inserter_Settings $settings, PDF_Thumbnail_Inserter_RuntimeState $state ) {
        $this->callbacks = $callbacks;
        $this->featured_images = $featured_images;
        $this->settings = $settings;
        $this->state = $state;
    }

        public function is_news_or_category_list_context() {
            $settings = $this->settings->get_settings();
            if ( empty( $settings['use_first_pdf_preview_as_featured_image'] ) ) {
                return false;
            }

            if ( $this->should_skip_frontend_list_filters() ) {
                return false;
            }

            if ( is_category() || is_home() || $this->is_news_page_request() ) {
                return true;
            }

            return false;
        }

        public function is_news_page_request() {
            $settings = $this->settings->get_settings();
            $pages = array_filter( array_map( 'trim', explode( ',', $settings['listing_pages'] ) ) );
            if ( empty( $pages ) ) {
                return false;
            }
            if ( is_page( $pages ) ) {
                return true;
            }

            $request_path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
            $request_path = trim( $request_path, '/' );

            foreach ( $pages as $page ) {
                if ( ! ctype_digit( $page ) && ( $page === $request_path || 0 === strpos( $request_path, $page . '/page/' ) ) ) {
                    return true;
                }
            }
            return false;
        }

        public function is_attachment_list_render_context() {
            return $this->is_news_or_category_list_context() && (bool) $this->featured_images->get_current_render_post_id();
        }

        public function should_suppress_elementor_post_content( $post_id ) {
            if ( ! $this->is_attachment_list_render_context() || ! class_exists( '\\Elementor\\Plugin' ) ) {
                return false;
            }

            $document = \Elementor\Plugin::instance()->documents->get_current();
            // Suppress only the post's own attachment widget when its content is used
            // as an excerpt. A Loop Grid template has a different document ID.
            return $document && absint( $document->get_main_id() ) === absint( $post_id );
        }

        public function should_skip_frontend_list_filters() {
            if ( is_admin() || wp_doing_ajax() ) {
                return true;
            }

            if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
                return true;
            }

            if ( defined( 'WP_CLI' ) && WP_CLI ) {
                return true;
            }

            if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
                return true;
            }

            if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance ) ) {
                $elementor = \Elementor\Plugin::$instance;

                if (
                    isset( $elementor->editor )
                    && $elementor->editor->is_edit_mode()
                ) {
                    return true;
                }

                if (
                    isset( $elementor->preview )
                    && $elementor->preview->is_preview_mode()
                ) {
                    return true;
                }
            }

            return false;
        }

        public function strip_attachment_sources_from_content( $content ) {
            $content = (string) $content;
            if ( '' === $content ) {
                return '';
            }

            $patterns = array(
                '~<!--\s+wp:file\b.*?-->.*?<!--\s+/wp:file\s+-->~is',
                '~<!--\s+wp:pdf-thumbnail-inserter/pdf-thumbnail\b.*?/-->~is',
                '~<!--\s+wp:pdf-thumbnail-inserter/pdf-thumbnail\b.*?-->.*?<!--\s+/wp:pdf-thumbnail-inserter/pdf-thumbnail\s+-->~is',
                '/\[(?:pdf_thumbnail_inserter|pdf_thumbnail)\b[^\]]*\]/i',
                '~<div\b(?=[^>]*\bclass=(?:"[^"]*\bwp-block-file\b[^"]*"|\'[^\']*\bwp-block-file\b[^\']*\'))[^>]*>.*?</div>~is',
                '~<object\b[^>]*(?:application/pdf|\.pdf(?:[?"\']))[^>]*>.*?</object>~is',
                '~<iframe\b[^>]*(?:\.pdf(?:[?"\'])|pdfjs-viewer)[^>]*>.*?</iframe>~is',
                '~<a\b[^>]*\bhref=(?:"[^\"]+\.(?:pdf|docx?)(?:\?[^\"]*)?"|\'[^\']+\.(?:pdf|docx?)(?:\?[^\']*)?\')[^>]*>.*?</a>~is',
            );

            $content = preg_replace( $patterns, ' ', $content );
            // Naked URLs belong to text nodes. Matching across HTML attributes can
            // truncate cover-image src URLs whose query contains a PDF filename.
            $parts = wp_html_split( (string) $content );
            foreach ( $parts as &$part ) {
                if ( '' !== $part && '<' !== $part[0] ) {
                    $part = preg_replace( '~(?:(?:https?:)?//|/|wp-content/)[^\s"\'<>]+?\.(?:pdf|docx?)(?:\?[^\s"\'<>]*)?~iu', ' ', $part );
                }
            }
            unset( $part );
            $content = implode( '', $parts );
            $content = preg_replace( '~<!--\s*/?wp:[^>]+-->~', ' ', (string) $content );

            return (string) $content;
        }

        public function strip_rendered_attachment_markup( $content ) {
            $content = $this->strip_attachment_sources_from_content( $content );
            $content = preg_replace( '~<p>\s*</p>~i', '', $content );

            return trim( (string) $content );
        }

        public function begin_excerpt_context( $excerpt, $post ) {
            $this->state->excerpt_posts[] = get_post( $post );
            return $excerpt;
        }

        public function end_excerpt_context( $excerpt ) {
            array_pop( $this->state->excerpt_posts );
            return $excerpt;
        }

        /** Clean source before core trims once, preserving its filters and content pipeline. */
        public function filter_excerpt_attachment_source( $content ) {
            if ( ! doing_filter( 'get_the_excerpt' ) || ! $this->is_news_or_category_list_context() ) return $content;
            $post = end( $this->state->excerpt_posts );
            if ( ! $post || 'post' !== $post->post_type || '' !== trim( (string) $post->post_excerpt ) ) return $content;
            return $this->strip_attachment_sources_from_content( $content );
        }

        /** Legacy public callback retained for callers explicitly invoking the old adapter. */
        public function filter_list_excerpt_attachment_text( $excerpt, $post ) {
            if ( ! $this->is_news_or_category_list_context() ) {
                return $excerpt;
            }

            $post = get_post( $post );
            if ( ! $post || 'post' !== $post->post_type ) {
                return $excerpt;
            }

            // Respect a deliberately written manual excerpt. Only rebuild automatic excerpts,
            // whose file-link labels otherwise become strings such as "PDF title Download".
            if ( '' !== trim( (string) $post->post_excerpt ) ) {
                return $excerpt;
            }

            $clean_content = $this->strip_attachment_sources_from_content( $post->post_content );
            $clean_content = strip_shortcodes( $clean_content );
            $clean_content = wp_strip_all_tags( $clean_content, true );
            $clean_content = preg_replace( '/\s+/u', ' ', (string) $clean_content );
            $clean_content = trim( (string) $clean_content );

            if ( '' === $clean_content ) {
                return '';
            }

            $excerpt_post = clone $post;
            $excerpt_post->post_content = $clean_content;
            return wp_trim_excerpt( '', $excerpt_post );
        }

        public function filter_category_archive_excerpt_output( $excerpt ) {
            if ( ! $this->is_attachment_list_render_context() ) {
                return $excerpt;
            }

            $post_id = $this->featured_images->get_current_render_post_id();
            if ( ! $post_id ) {
                return $excerpt;
            }

            return $this->strip_rendered_attachment_markup( $excerpt );
        }

        public function filter_category_archive_content( $content ) {
            if ( ! $this->is_attachment_list_render_context() ) {
                return $content;
            }

            $post_id = $this->featured_images->get_current_render_post_id();
            if ( ! $post_id ) {
                return $content;
            }

            return $this->strip_rendered_attachment_markup( $content );
        }

        public function start_listing_output_buffer() {
            $settings = $this->settings->get_settings();
            if ( empty( $settings['use_first_pdf_preview_as_featured_image'] ) || ! $this->is_news_or_category_list_context() ) {
                return;
            }

            ob_start( array( $this->callbacks, 'filter_listing_output' ) );
        }

        public function filter_listing_output( $html ) {
            $html = (string) $html;
            if ( '' === $html ) {
                return $html;
            }

            $filtered_html = preg_replace_callback(
                '~(<div\s+class=(["\'])(?=[^"\']*\bblog-post\b)(?=[^"\']*\bformat-no_featured\b)([^"\']*)\2[^>]*>\s*<div\s+class=(["\'])blog-post_wrapper\4>\s*)(<div\s+class=(["\'])blog-post_content\6>(?:(?!<div\s+class=(["\'])(?=[^"\']*\bblog-post\b)).)*?<[a-z][a-z0-9]*\b[^>]*\bclass=(["\'])blog-post_title\8[^>]*>\s*<a\s+[^>]*href=(["\'])(.*?)\9)~is',
                function ( $matches ) {
                    $permalink = esc_url_raw( html_entity_decode( $matches[10], ENT_QUOTES, get_bloginfo( 'charset' ) ) );
                    $post_id   = $permalink ? absint( url_to_postid( $permalink ) ) : 0;
                    if ( ! $post_id || 'post' !== get_post_type( $post_id ) || $this->featured_images->has_real_featured_image_id( $post_id ) ) {
                        return $matches[0];
                    }

                    $fallback = $this->featured_images->get_pdf_featured_image_fallback_data( $post_id, 'large' );
                    if ( empty( $fallback['url'] ) && empty( $fallback['pdf_url'] ) ) {
                        return $matches[0];
                    }

                    $wrapper_start = str_replace( 'format-no_featured', 'format-has-post-thumbnail has-post-thumbnail', $matches[1] );
                    $media_html    = $this->featured_images->render_listing_pdf_preview_media(
                        $post_id,
                        ! empty( $fallback['url'] ) ? $fallback['url'] : '',
                        ! empty( $fallback['pdf_url'] ) ? $fallback['pdf_url'] : ''
                    );

                    return $wrapper_start . $media_html . $matches[5];
                },
                $html
            );

            return is_string( $filtered_html ) ? $filtered_html : $html;
        }
}
