<?php
/** Previews subsystem. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_Previews {
    /** @var PDF_Thumbnail_Inserter_FileResolver */
    private $file_resolver;

    /** @var PDF_Thumbnail_Inserter_PreviewGeneration */
    private $preview_generation;

    /** Wire the request-local collaborators before registering any hooks. */
    public function set_dependencies( PDF_Thumbnail_Inserter_FileResolver $file_resolver, PDF_Thumbnail_Inserter_PreviewGeneration $preview_generation ) {
        $this->file_resolver = $file_resolver;
        $this->preview_generation = $preview_generation;
    }

        public function is_available_preview_url( $url ) {
            if ( ! $url ) {
                return false;
            }
            $uploads = wp_get_upload_dir();
            $home = home_url( '/' );
            // Leave CDN/offloaded URLs to WordPress filters; do not probe them over HTTP.
            if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) )
                || wp_parse_url( $url, PHP_URL_PORT ) !== wp_parse_url( $home, PHP_URL_PORT )
                || empty( $uploads['baseurl'] )
                || 0 !== strpos( $this->file_resolver->get_file_url_identity( $url ), trailingslashit( $this->file_resolver->get_file_url_identity( $uploads['baseurl'] ) ) ) ) {
                return true;
            }
            $path = $this->file_resolver->get_local_upload_file_path( $url );
            return '' !== $path && false !== wp_getimagesize( $path );
        }

        public function get_image_url_with_size_fallback( $attachment_id, $preferred_size = 'medium' ) {
            $attachment_id = absint( $attachment_id );
            if ( ! $attachment_id ) {
                return '';
            }

            $size_candidates = array_filter(
                array_unique(
                    array_merge(
                        array( $preferred_size ),
                        array( 'full', 'large', 'medium_large', 'medium', 'thumbnail' )
                    )
                )
            );

            foreach ( $size_candidates as $candidate_size ) {
                $url = wp_get_attachment_image_url( $attachment_id, $candidate_size );
                if ( $this->is_available_preview_url( $url ) ) {
                    return $url;
                }
            }

            $metadata = wp_get_attachment_metadata( $attachment_id, true );
            if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
                return '';
            }

            $base_url = wp_get_attachment_url( $attachment_id );
            if ( ! $base_url ) {
                return '';
            }

            $base_dir_url = trailingslashit( dirname( $base_url ) );
            if ( './' === $base_dir_url || '.' === $base_dir_url ) {
                $uploads = wp_get_upload_dir();
                $base_dir_url = trailingslashit( $uploads['baseurl'] );
            }

            foreach ( $size_candidates as $candidate_size ) {
                if ( empty( $metadata['sizes'][ $candidate_size ]['file'] ) ) {
                    continue;
                }

                $url = trailingslashit( $base_dir_url ) . ltrim( $metadata['sizes'][ $candidate_size ]['file'], '/' );
                if ( $this->is_available_preview_url( $url ) ) {
                    return $url;
                }
            }

            foreach ( $metadata['sizes'] as $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $url = trailingslashit( $base_dir_url ) . ltrim( $size_data['file'], '/' );
                    if ( $this->is_available_preview_url( $url ) ) {
                        return $url;
                    }
                }
            }

            return '';
        }

        public function get_wordpress_pdf_preview_url( $attachment_id, $preferred_size = 'medium' ) {
            $attachment_id = absint( $attachment_id );
            $file_url      = $attachment_id ? (string) wp_get_attachment_url( $attachment_id ) : '';
            $extension     = strtolower( pathinfo( $this->file_resolver->get_url_path( $file_url ), PATHINFO_EXTENSION ) );

            if ( ! $attachment_id || ( 'application/pdf' !== get_post_mime_type( $attachment_id ) && 'pdf' !== $extension ) ) {
                return '';
            }

            $preview_url = $this->get_image_url_with_size_fallback( $attachment_id, $preferred_size );

            if ( $preview_url ) {
                return $preview_url;
            }

            $metadata = wp_get_attachment_metadata( $attachment_id, true );
            if ( is_array( $metadata ) ) {
                $base_url = wp_get_attachment_url( $attachment_id );
                if ( $base_url ) {
                    $base_dir_url = trailingslashit( dirname( $base_url ) );

                    if ( ! empty( $metadata['file'] ) && preg_match( '/\.(?:jpe?g|png|gif|webp)$/i', (string) $metadata['file'] ) ) {
                        $relative_file = wp_basename( (string) $metadata['file'] );
                        if ( $relative_file ) {
                            $url = trailingslashit( $base_dir_url ) . ltrim( $relative_file, '/' );
                            if ( $this->is_available_preview_url( $url ) ) {
                                return $url;
                            }
                        }
                    }
                }

                if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                    foreach ( $metadata['sizes'] as $size_data ) {
                        if ( empty( $size_data['file'] ) ) {
                            continue;
                        }

                        if ( ! empty( $size_data['mime-type'] ) && 0 === strpos( (string) $size_data['mime-type'], 'image/' ) ) {
                            if ( ! empty( $base_dir_url ) ) {
                                $url = trailingslashit( $base_dir_url ) . ltrim( $size_data['file'], '/' );
                                if ( $this->is_available_preview_url( $url ) ) {
                                    return $url;
                                }
                            }
                        }
                    }
                }
            }

            $thumbnail_id = absint( get_post_meta( $attachment_id, '_thumbnail_id', true ) );
            if ( $thumbnail_id && 'image/' === substr( (string) get_post_mime_type( $thumbnail_id ), 0, 6 ) ) {
                $thumb_url = $this->get_image_url_with_size_fallback( $thumbnail_id, $preferred_size );
                if ( $thumb_url ) {
                    return $thumb_url;
                }
            }

            return '';
        }

        public function get_pdf_preview_data( $attachment_id, $size = 'medium', $generate_if_missing = false ) {
            $attachment_id = absint( $attachment_id );
            $title = get_the_title( $attachment_id );
            $file_url = wp_get_attachment_url( $attachment_id );
            $attachment_url = get_attachment_link( $attachment_id );

            $thumbnail_url = '';
            $preview_id = 0;
            $source = 'placeholder';

            $core_preview = $this->get_wordpress_pdf_preview_url( $attachment_id, $size );
            if ( $core_preview ) {
                $thumbnail_url = $core_preview;
                $source = 'wordpress';
            }

            if ( ! $thumbnail_url ) {
                $preview_id = absint( get_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ID, true ) );
                if ( $preview_id && 'image/' === substr( (string) get_post_mime_type( $preview_id ), 0, 6 ) ) {
                    $plugin_preview = $this->get_image_url_with_size_fallback( $preview_id, $size );
                    if ( ! $plugin_preview ) {
                        $plugin_preview = wp_get_attachment_url( $preview_id );
                    }
                    if ( $this->is_available_preview_url( $plugin_preview ) ) {
                        $thumbnail_url = $plugin_preview;
                        $source = 'plugin';
                    }
                }
            }

            if ( ! $thumbnail_url && $file_url ) {
                $standalone_preview = $this->get_pdf_preview_url_from_file_url( $file_url, $generate_if_missing );
                if ( $standalone_preview ) {
                    $thumbnail_url = $standalone_preview;
                    $source        = 'standalone';
                }
            }

            if ( ! $thumbnail_url ) {
                $thumbnail_url = PDF_THUMBNAIL_INSERTER_URL . 'assets/images/pdf-placeholder.png';
            }

            return array(
                'title'          => $title ? $title : __( 'PDF File', 'pdf-thumbnail-inserter' ),
                'file_url'       => $file_url ? $file_url : '',
                'attachment_url' => $attachment_url ? $attachment_url : '',
                'thumbnail_url'  => esc_url_raw( $thumbnail_url ),
                'preview_id'     => $preview_id,
                'preview_source' => $source,
            );
        }

        public function get_pdf_preview_url_from_file_url( $file_url, $generate_if_missing = false ) {
            $file_url = esc_url_raw( $file_url );
            $pdf_path = $this->file_resolver->get_local_upload_pdf_path( $file_url );
            if ( '' === $pdf_path ) {
                return '';
            }

            $uploads = wp_get_upload_dir();

            $path_info = pathinfo( $pdf_path );
            $dir_path  = ! empty( $path_info['dirname'] ) ? wp_normalize_path( $path_info['dirname'] ) : '';
            $filename  = ! empty( $path_info['filename'] ) ? (string) $path_info['filename'] : '';

            if ( '' === $dir_path || '' === $filename ) {
                return '';
            }

            $preferred_candidates = array(
                $dir_path . '/' . $filename . '-pdf.jpg',
                $dir_path . '/' . $filename . '-pdf.jpeg',
                $dir_path . '/' . $filename . '-pdf.png',
                $dir_path . '/' . $filename . '-pdf.webp',
                $dir_path . '/' . $filename . '-pdf-preview.jpg',
                $dir_path . '/' . $filename . '-pdf-preview.jpeg',
                $dir_path . '/' . $filename . '-pdf-preview.png',
                $dir_path . '/' . $filename . '-pdf-preview.webp',
            );

            $candidate_paths = array();
            foreach ( $preferred_candidates as $candidate_path ) {
                if ( file_exists( $candidate_path ) ) {
                    $candidate_paths[] = wp_normalize_path( $candidate_path );
                }
            }

            // The directory and filename are literal filesystem data, not glob patterns.
            $glob_prefix = strtr( $dir_path . '/' . $filename, array( '[' => '[[]', ']' => '[]]', '?' => '[?]', '*' => '[*]' ) );
            foreach ( array( 'jpg', 'jpeg', 'png', 'webp' ) as $extension ) {
                $matches = glob( $glob_prefix . '-pdf*.' . $extension );
                if ( empty( $matches ) ) {
                    continue;
                }

                foreach ( $matches as $match ) {
                    if ( file_exists( $match ) ) {
                        $candidate_paths[] = wp_normalize_path( $match );
                    }
                }
            }

            $candidate_paths = array_values( array_unique( $candidate_paths ) );
            $preview_path = '';
            $largest_area = 0;
            $uploads_basedir = trailingslashit( wp_normalize_path( realpath( $uploads['basedir'] ) ) );

            foreach ( $candidate_paths as $candidate_path ) {
                $candidate_realpath = realpath( $candidate_path );
                if ( ! $candidate_realpath || 0 !== strpos( wp_normalize_path( $candidate_realpath ), $uploads_basedir ) ) {
                    continue;
                }
                $image_size = wp_getimagesize( $candidate_path );
                if ( ! is_array( $image_size ) || empty( $image_size[0] ) || empty( $image_size[1] ) ) {
                    continue;
                }

                $area = absint( $image_size[0] ) * absint( $image_size[1] );
                if ( $area > $largest_area ) {
                    $largest_area = $area;
                    $preview_path = $candidate_path;
                }
            }

            // Invalid existing images must not prevent regeneration of a missing usable preview.
            if ( '' === $preview_path && $generate_if_missing ) {
                $preview_path = $this->preview_generation->generate_standalone_pdf_preview( $pdf_path, $dir_path, $filename, $uploads['basedir'] );
            }
            if ( '' === $preview_path ) {
                return '';
            }

            $relative_preview = ltrim( substr( $preview_path, strlen( $uploads_basedir ) ), '/' );
            if ( '' === $relative_preview ) {
                return '';
            }

            return esc_url_raw( trailingslashit( $uploads['baseurl'] ) . $this->file_resolver->encode_url_path_segments( $relative_preview ) );
        }
}
