<?php
/** Discovery subsystem. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_Discovery {
    /** @var PDF_Thumbnail_Inserter_FileResolver */
    private $file_resolver;

    /** @var PDF_Thumbnail_Inserter_PreviewGeneration */
    private $preview_generation;

    /** @var PDF_Thumbnail_Inserter_Previews */
    private $previews;

    /** @var PDF_Thumbnail_Inserter_ReferenceLookup */
    private $reference_lookup;

    /** @var PDF_Thumbnail_Inserter_RuntimeState */
    private $state;

    /** Wire the request-local collaborators before registering any hooks. */
    public function set_dependencies( PDF_Thumbnail_Inserter_FileResolver $file_resolver, PDF_Thumbnail_Inserter_PreviewGeneration $preview_generation, PDF_Thumbnail_Inserter_Previews $previews, PDF_Thumbnail_Inserter_ReferenceLookup $reference_lookup, PDF_Thumbnail_Inserter_RuntimeState $state ) {
        $this->file_resolver = $file_resolver;
        $this->preview_generation = $preview_generation;
        $this->previews = $previews;
        $this->reference_lookup = $reference_lookup;
        $this->state = $state;
    }

        public function is_word_document_mime( $mime ) {
            $mime = (string) $mime;

            return in_array(
                $mime,
                array(
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ),
                true
            );
        }

        public function get_file_title_from_url( $file_url, $fallback_label ) {
            $path     = $this->file_resolver->get_url_path( $file_url );
            $filename = rawurldecode( pathinfo( basename( $path ), PATHINFO_FILENAME ) );
            $title    = sanitize_text_field( trim( preg_replace( '/[-_]+/u', ' ', $filename ) ) );

            if ( '' === $title ) {
                $title = $fallback_label;
            }

            return $title;
        }

        public function get_gutenberg_file_block_title( $block_content, $attachment_id, $file_url, $fallback_label ) {
            if ( preg_match_all( '~<a\b([^>]*)>(.*?)</a>~is', $block_content, $matches, PREG_SET_ORDER ) ) {
                foreach ( $matches as $match ) {
                    $attrs = $match[1];

                    if ( false !== strpos( $attrs, 'wp-block-file__button' ) ) {
                        continue;
                    }

                    $title = wp_strip_all_tags( html_entity_decode( (string) $match[2], ENT_QUOTES, get_bloginfo( 'charset' ) ) );
                    $title = sanitize_text_field( trim( preg_replace( '/\s+/u', ' ', $title ) ) );

                    if ( '' !== $title ) {
                        return $title;
                    }
                }
            }

            if ( $attachment_id ) {
                $attachment_title = sanitize_text_field( get_the_title( $attachment_id ) );
                if ( '' !== $attachment_title ) {
                    return $attachment_title;
                }
            }

            return $this->get_file_title_from_url( $file_url, $fallback_label );
        }

        public function get_file_url_from_block_markup( $markup ) {
            $markup = (string) $markup;
            if ( '' === trim( $markup ) ) {
                return '';
            }

            if ( preg_match_all( '~<a\b([^>]*)\bhref=(["\'])(.*?)\2[^>]*>~is', $markup, $matches, PREG_SET_ORDER ) ) {
                foreach ( $matches as $match ) {
                    $tag = $match[0];
                    if ( false !== strpos( $tag, 'wp-block-file__button' ) ) {
                        continue;
                    }

                    $candidate = esc_url_raw( html_entity_decode( $match[3], ENT_QUOTES, get_bloginfo( 'charset' ) ) );
                    if ( '' !== $candidate ) {
                        return $candidate;
                    }
                }
            }

            if ( preg_match( '~<(?:object|embed)\b[^>]*(?:data|src)=(["\'])(.*?)\1~is', $markup, $match ) ) {
                return esc_url_raw( html_entity_decode( $match[2], ENT_QUOTES, get_bloginfo( 'charset' ) ) );
            }

            return '';
        }

        public function get_gutenberg_file_block_markup( $block, $block_content = '' ) {
            $markup = (string) $block_content;

            if ( '' === $markup && ! empty( $block['innerHTML'] ) ) {
                $markup = (string) $block['innerHTML'];
            }

            if ( '' === $markup && ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
                $markup = implode( '', array_map( 'strval', $block['innerContent'] ) );
            }

            return $markup;
        }

        public function is_gutenberg_file_preview_markup( $markup ) {
            $markup = (string) $markup;
            if ( '' === trim( $markup ) ) {
                return false;
            }

            return (bool) preg_match( '~<(?:object|embed)\b[^>]*\bclass=(["\'])[^"\']*\bwp-block-file__embed\b[^"\']*\1[^>]*(?:data|src)=(["\'])(.*?)\2~is', $markup )
                || (bool) preg_match( '~<(?:object|embed)\b[^>]*(?:data|src)=(["\'])(.*?)\1[^>]*\btype=(["\'])application/pdf\3~is', $markup );
        }

        public function get_gutenberg_file_block_data( $block, $block_content = '' ) {
            $attributes        = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
            $attachment_id     = isset( $attributes['id'] ) ? absint( $attributes['id'] ) : 0;
            $block_file_url    = ! empty( $attributes['href'] ) ? esc_url_raw( $attributes['href'] ) : '';
            $attachment_url    = '';
            $file_url          = '';
            $mime              = '';
            $markup            = $this->get_gutenberg_file_block_markup( $block, $block_content );
            $display_preview   = ! empty( $attributes['displayPreview'] ) || $this->is_gutenberg_file_preview_markup( $markup );

            if ( '' === $block_file_url ) {
                $block_file_url = $this->get_file_url_from_block_markup( $markup );
            }

            if ( $attachment_id && get_post( $attachment_id ) ) {
                $attachment_url = (string) wp_get_attachment_url( $attachment_id );
                $mime           = (string) get_post_mime_type( $attachment_id );
            }

            if ( '' !== $block_file_url ) {
                $file_url = $block_file_url;

                if (
                    '' === $attachment_url
                    || $this->file_resolver->get_file_url_identity( $attachment_url ) !== $this->file_resolver->get_file_url_identity( $block_file_url )
                ) {
                    $resolved_attachment_id = $this->file_resolver->get_attachment_id_from_file_url( $block_file_url );
                    $attachment_id          = $resolved_attachment_id;
                    $mime                   = $resolved_attachment_id ? (string) get_post_mime_type( $resolved_attachment_id ) : '';
                }
            } elseif ( '' !== $attachment_url ) {
                $file_url = $attachment_url;
            }

            if ( '' === $file_url ) {
                return array(
                    'attachment_id'   => $attachment_id,
                    'file_url'        => '',
                    'mime'            => '',
                    'extension'       => '',
                    'is_pdf'          => false,
                    'is_doc'          => false,
                    'display_preview' => $display_preview,
                );
            }

            $path      = $this->file_resolver->get_url_path( $file_url );
            $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

            if ( '' === $mime ) {
                $file_type = wp_check_filetype( $path );
                $mime      = ! empty( $file_type['type'] ) ? (string) $file_type['type'] : '';
            }

            $is_pdf = 'application/pdf' === $mime || 'pdf' === $extension;
            $is_doc = $this->is_word_document_mime( $mime ) || in_array( $extension, array( 'doc', 'docx' ), true );

            return array(
                'attachment_id'   => $attachment_id,
                'file_url'        => $file_url,
                'mime'            => $mime,
                'extension'       => $extension,
                'is_pdf'          => $is_pdf,
                'is_doc'          => $is_doc,
                'display_preview' => $display_preview,
            );
        }

        public function get_pdf_file_data_from_url( $file_url ) {
            $file_url = $this->file_resolver->normalize_pdf_candidate_url( $file_url );
            if ( '' === $file_url ) {
                return array();
            }

            $attachment_id = $this->file_resolver->get_attachment_id_from_file_url( $file_url );

            return array(
                'attachment_id'   => $attachment_id,
                'file_url'        => $file_url,
                'mime'            => $attachment_id ? (string) get_post_mime_type( $attachment_id ) : 'application/pdf',
                'extension'       => 'pdf',
                'is_pdf'          => true,
                'is_doc'          => false,
                'display_preview' => false,
            );
        }

        public function get_pdf_file_data_from_attachment_id( $attachment_id, $follow_parent = true ) {
            $attachment_id = absint( $attachment_id );
            $attachment    = $attachment_id ? get_post( $attachment_id ) : null;

            if ( ! $attachment ) {
                return array();
            }

            $file_url = (string) wp_get_attachment_url( $attachment_id );
            $mime      = (string) get_post_mime_type( $attachment_id );
            $path      = $this->file_resolver->get_url_path( $file_url );
            $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

            if ( 'application/pdf' !== $mime && 'pdf' !== $extension ) {
                if ( $follow_parent && ! empty( $attachment->post_parent ) ) {
                    $parent_data = $this->get_pdf_file_data_from_attachment_id( $attachment->post_parent, false );
                    if ( ! empty( $parent_data['is_pdf'] ) ) {
                        return $parent_data;
                    }
                }

                return array();
            }

            if ( '' === $file_url ) {
                return array();
            }

            return array(
                'attachment_id'   => $attachment_id,
                'file_url'        => esc_url_raw( $file_url ),
                'mime'            => '' !== $mime ? $mime : 'application/pdf',
                'extension'       => 'pdf',
                'is_pdf'          => true,
                'is_doc'          => false,
                'display_preview' => false,
            );
        }

        public function find_first_pdf_in_post_content( $post_content ) {
            $post_content = (string) $post_content;
            if ( '' === trim( $post_content ) ) {
                return array();
            }

            $candidates = array();

            if ( preg_match_all( '/\[(?:pdf_thumbnail_inserter|pdf_thumbnail)\b([^\]]*)\]/i', $post_content, $shortcodes, PREG_OFFSET_CAPTURE ) ) {
                foreach ( $shortcodes[0] as $index => $whole_match ) {
                    $raw_atts = isset( $shortcodes[1][ $index ][0] ) ? $shortcodes[1][ $index ][0] : '';
                    $atts     = shortcode_parse_atts( $raw_atts );
                    $data     = $this->reference_lookup->get_first_pdf_from_reference_atts( is_array( $atts ) ? $atts : array() );

                    if ( ! empty( $data['is_pdf'] ) ) {
                        $candidates[] = array(
                            'offset' => (int) $whole_match[1],
                            'data'   => $data,
                        );
                    }
                }
            }

            if ( preg_match_all( '~<!--\s+wp:pdf-thumbnail-inserter/pdf-thumbnail\s+(\{.*?\})\s*(?:/)?-->~is', $post_content, $block_comments, PREG_OFFSET_CAPTURE ) ) {
                foreach ( $block_comments[0] as $index => $whole_match ) {
                    $json = isset( $block_comments[1][ $index ][0] ) ? $block_comments[1][ $index ][0] : '';
                    $atts = json_decode( html_entity_decode( $json, ENT_QUOTES, get_bloginfo( 'charset' ) ), true );
                    $data = $this->reference_lookup->get_first_pdf_from_reference_atts( is_array( $atts ) ? $atts : array() );

                    if ( ! empty( $data['is_pdf'] ) ) {
                        $candidates[] = array(
                            'offset' => (int) $whole_match[1],
                            'data'   => $data,
                        );
                    }
                }
            }

            $patterns = array(
                '~\b(?:href|data|src)\s*=\s*(["\'])(.*?)\1~is',
                '~"href"\s*:\s*"([^"]+)"~is',
                '~(?:(?:https?:)?//|/|wp-content/)[^\s"\'<>]+?\.pdf(?:\?[^\s"\'<>]*)?~iu',
            );

            foreach ( $patterns as $pattern_index => $pattern ) {
                if ( ! preg_match_all( $pattern, $post_content, $matches, PREG_OFFSET_CAPTURE ) ) {
                    continue;
                }

                foreach ( $matches[0] as $index => $whole_match ) {
                    if ( 0 === $pattern_index ) {
                        $candidate = isset( $matches[2][ $index ][0] ) ? $matches[2][ $index ][0] : '';
                    } elseif ( 1 === $pattern_index ) {
                        $candidate = isset( $matches[1][ $index ][0] ) ? $matches[1][ $index ][0] : '';
                    } else {
                        $candidate = $whole_match[0];
                    }

                    $candidate = str_replace( '\\/', '/', $candidate );
                    $data      = $this->get_pdf_file_data_from_url( $candidate );
                    if ( empty( $data['is_pdf'] ) ) {
                        continue;
                    }

                    $candidates[] = array(
                        'offset' => (int) $whole_match[1],
                        'data'   => $data,
                    );
                }
            }

            if ( ! empty( $candidates ) ) {
                usort(
                    $candidates,
                    static function ( $left, $right ) {
                        return $left['offset'] <=> $right['offset'];
                    }
                );

                return $candidates[0]['data'];
            }

            return $this->find_first_pdf_block_data( parse_blocks( $post_content ) );
        }

        public function find_first_preview_pdf_in_post_content( $post_content ) {
            $post_content = (string) $post_content;
            if ( '' === trim( $post_content ) ) {
                return array();
            }

            $file_data = $this->find_first_preview_pdf_block_data( parse_blocks( $post_content ) );
            if ( ! empty( $file_data['is_pdf'] ) ) {
                return $file_data;
            }

            if ( preg_match_all( '~<(?:object|embed)\b[^>]*(?:data|src)=(["\'])(.*?)\1[^>]*>~is', $post_content, $matches, PREG_OFFSET_CAPTURE ) ) {
                foreach ( $matches[0] as $index => $whole_match ) {
                    $markup = $whole_match[0];
                    if ( ! $this->is_gutenberg_file_preview_markup( $markup ) ) {
                        continue;
                    }

                    $candidate = isset( $matches[2][ $index ][0] ) ? $matches[2][ $index ][0] : '';
                    $data      = $this->get_pdf_file_data_from_url( $candidate );
                    if ( empty( $data['is_pdf'] ) ) {
                        continue;
                    }

                    $data['display_preview'] = true;
                    return $data;
                }
            }

            return array();
        }

        public function find_first_pdf_block_data( $blocks ) {
            if ( ! is_array( $blocks ) ) {
                return array();
            }

            foreach ( $blocks as $block ) {
                if ( ! is_array( $block ) ) {
                    continue;
                }

                $block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

                if ( 'core/file' === $block_name ) {
                    $file_data = $this->get_gutenberg_file_block_data( $block );
                    if ( ! empty( $file_data['is_pdf'] ) ) {
                        return $file_data;
                    }
                }

                if ( 'pdf-thumbnail-inserter/pdf-thumbnail' === $block_name ) {
                    $file_data = $this->reference_lookup->get_first_pdf_from_reference_atts( isset( $block['attrs'] ) ? $block['attrs'] : array() );
                    if ( ! empty( $file_data['is_pdf'] ) ) {
                        return $file_data;
                    }
                }

                if ( ! empty( $block['innerBlocks'] ) ) {
                    $file_data = $this->find_first_pdf_block_data( $block['innerBlocks'] );
                    if ( ! empty( $file_data ) ) {
                        return $file_data;
                    }
                }
            }

            return array();
        }

        public function find_first_preview_pdf_block_data( $blocks ) {
            if ( ! is_array( $blocks ) ) {
                return array();
            }

            foreach ( $blocks as $block ) {
                if ( ! is_array( $block ) ) {
                    continue;
                }

                $block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

                if ( 'core/file' === $block_name ) {
                    $file_data = $this->get_gutenberg_file_block_data( $block );
                    if ( ! empty( $file_data['is_pdf'] ) && ! empty( $file_data['display_preview'] ) ) {
                        return $file_data;
                    }
                }

                if ( ! empty( $block['innerBlocks'] ) ) {
                    $file_data = $this->find_first_preview_pdf_block_data( $block['innerBlocks'] );
                    if ( ! empty( $file_data ) ) {
                        return $file_data;
                    }
                }
            }

            return array();
        }

        /**
         * Resolve the first PDF, preserving the public API's legacy generation default.
         * Frontend renderers explicitly opt out; uploads and maintenance own generation.
         */
        public function resolve_first_pdf_for_post( int $post_id, $size = 'full', bool $generate_if_missing = true ) {
            $post_id = absint( $post_id );
            if ( ! $post_id ) {
                return false;
            }

            $cache_key = $post_id . ':' . md5( wp_json_encode( $size ) ) . ':' . (int) $generate_if_missing;
            if ( array_key_exists( $cache_key, $this->state->first_pdf_resolver_cache ) ) {
                return $this->state->first_pdf_resolver_cache[ $cache_key ];
            }

            $this->state->first_pdf_resolver_cache[ $cache_key ] = false;

            $post = get_post( $post_id );
            if ( ! $post || 'post' !== $post->post_type || '' === trim( (string) $post->post_content ) ) {
                return false;
            }

            $file_data = $this->find_first_preview_pdf_in_post_content( $post->post_content );
            if ( empty( $file_data['is_pdf'] ) ) {
                $file_data = $this->find_first_pdf_in_post_content( $post->post_content );
            }

            if ( empty( $file_data['is_pdf'] ) || empty( $file_data['file_url'] ) ) {
                return false;
            }

            $pdf_url     = esc_url_raw( $file_data['file_url'] );
            $pdf_id      = ! empty( $file_data['attachment_id'] ) ? absint( $file_data['attachment_id'] ) : 0;
            $preview_url = '';
            $preview_id  = 0;

            if ( $pdf_id && 'application/pdf' === get_post_mime_type( $pdf_id ) ) {
                $preview_data = $this->previews->get_pdf_preview_data( $pdf_id, $size, $generate_if_missing );

                if (
                    $generate_if_missing && 'placeholder' === $preview_data['preview_source']
                    && ! get_post_meta( $pdf_id, PDF_Thumbnail_Inserter::META_PREVIEW_ERROR, true )
                ) {
                    $this->preview_generation->ensure_pdf_preview( $pdf_id );
                    $preview_data = $this->previews->get_pdf_preview_data( $pdf_id, $size, $generate_if_missing );
                }

                if ( ! empty( $preview_data['thumbnail_url'] ) && 'placeholder' !== $preview_data['preview_source'] ) {
                    $preview_url = esc_url_raw( $preview_data['thumbnail_url'] );
                    $preview_id  = ! empty( $preview_data['preview_id'] ) ? absint( $preview_data['preview_id'] ) : $pdf_id;
                }
            }

            if ( '' === $preview_url ) {
                $preview_url = $this->previews->get_pdf_preview_url_from_file_url( $pdf_url, $generate_if_missing );
            }

            $result = array(
                'pdf_url'       => $pdf_url,
                'attachment_id' => $pdf_id,
                'preview_url'   => esc_url_raw( $preview_url ),
                'preview_id'    => $preview_id,
            );

            $this->state->first_pdf_resolver_cache[ $cache_key ] = $result;

            return $result;
        }
}
