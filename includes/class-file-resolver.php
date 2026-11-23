<?php
/** File resolver subsystem. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_FileResolver {
        public function get_valid_local_pdf_attachment_file( $attachment_id ) {
            $attachment_id = absint( $attachment_id );
            if ( ! $attachment_id || 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
                return new WP_Error( 'invalid_pdf', __( 'Attachment is not a valid PDF.', 'pdf-thumbnail-inserter' ) );
            }

            $file_path = get_attached_file( $attachment_id, true );
            if ( ! $file_path || ! file_exists( $file_path ) ) {
                return new WP_Error( 'missing_file', __( 'Original PDF file could not be found.', 'pdf-thumbnail-inserter' ) );
            }

            $file_realpath = realpath( $file_path );
            if ( ! $file_realpath || ! is_file( $file_realpath ) || ! is_readable( $file_realpath ) ) {
                return new WP_Error( 'unreadable_file', __( 'Original PDF file is not readable.', 'pdf-thumbnail-inserter' ) );
            }

            if ( 'pdf' !== strtolower( pathinfo( $file_realpath, PATHINFO_EXTENSION ) ) ) {
                return new WP_Error( 'invalid_extension', __( 'Attachment file is not a PDF.', 'pdf-thumbnail-inserter' ) );
            }

            $uploads = wp_get_upload_dir();
            $uploads_basedir = ! empty( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : '';
            if ( ! $uploads_basedir ) {
                return new WP_Error( 'uploads_missing', __( 'Uploads directory could not be resolved.', 'pdf-thumbnail-inserter' ) );
            }

            $uploads_prefix = trailingslashit( wp_normalize_path( $uploads_basedir ) );
            $file_normalized = wp_normalize_path( $file_realpath );
            $inside_uploads = '\\' === DIRECTORY_SEPARATOR ? 0 === stripos( $file_normalized, $uploads_prefix ) : 0 === strpos( $file_normalized, $uploads_prefix );
            if ( ! $inside_uploads ) {
                return new WP_Error( 'outside_uploads', __( 'PDF file is outside the uploads directory.', 'pdf-thumbnail-inserter' ) );
            }

            $file_url = wp_get_attachment_url( $attachment_id );
            if ( ! $file_url ) {
                return new WP_Error( 'missing_url', __( 'PDF file URL could not be resolved.', 'pdf-thumbnail-inserter' ) );
            }

            $title = get_the_title( $attachment_id );

            return array(
                'path'  => $file_realpath,
                'url'   => $file_url,
                'title' => $title ? $title : __( 'PDF File', 'pdf-thumbnail-inserter' ),
            );
        }

        public function get_url_path( $file_url ) {
            $file_url = html_entity_decode( (string) $file_url, ENT_QUOTES, get_bloginfo( 'charset' ) );
            $file_url = trim( wp_unslash( $file_url ) );

            if ( '' === $file_url ) {
                return '';
            }

            $fallback_path = '';
            if ( preg_match( '~^(?:[a-z][a-z0-9+.-]*:)?//[^/]*(/[^?#]*)~iu', $file_url, $match ) ) {
                $fallback_path = rawurldecode( $match[1] );
            }

            // PHP 7.4 on Windows can replace UTF-8 bytes according to the active
            // locale. Parse an ASCII URI, then decode its path exactly once.
            $parse_url = preg_replace_callback( '/[^\x21-\x7E]/u', function ( $match ) {
                return rawurlencode( $match[0] );
            }, $file_url );
            $path = wp_parse_url( null === $parse_url ? $file_url : $parse_url, PHP_URL_PATH );
            if ( is_string( $path ) && '' !== $path ) {
                $decoded_path = rawurldecode( $path );
                if (
                    '' !== $fallback_path
                    && '' === pathinfo( $decoded_path, PATHINFO_EXTENSION )
                    && '' !== pathinfo( $fallback_path, PATHINFO_EXTENSION )
                ) {
                    return $fallback_path;
                }

                return $decoded_path;
            }

            if ( '' !== $fallback_path ) {
                return $fallback_path;
            }

            if ( 0 === strpos( $file_url, '/' ) ) {
                return rawurldecode( preg_replace( '~[?#].*$~', '', $file_url ) );
            }

            if ( 0 === strpos( $file_url, 'wp-content/' ) ) {
                return '/' . rawurldecode( preg_replace( '~[?#].*$~', '', $file_url ) );
            }

            return rawurldecode( preg_replace( '~[?#].*$~', '', $file_url ) );
        }

        public function encode_url_path_segments( $path ) {
            $path = (string) $path;
            if ( '' === $path ) {
                return '';
            }

            $leading_slash = 0 === strpos( $path, '/' );
            $segments      = explode( '/', ltrim( $path, '/' ) );
            $encoded       = array();

            foreach ( $segments as $segment ) {
                $encoded[] = rawurlencode( rawurldecode( $segment ) );
            }

            return ( $leading_slash ? '/' : '' ) . implode( '/', $encoded );
        }

        public function get_relative_upload_file_variants( $relative_file ) {
            $relative_file = ltrim( (string) $relative_file, '/' );
            if ( '' === $relative_file ) {
                return array();
            }

            return array_values(
                array_unique(
                    array_filter(
                        array(
                            $relative_file,
                            rawurldecode( $relative_file ),
                            ltrim( $this->encode_url_path_segments( rawurldecode( $relative_file ) ), '/' ),
                        )
                    )
                )
            );
        }

        public function get_file_url_identity( $file_url ) {
            return $this->get_url_path( $file_url );
        }

        public function invalidate_attachment_lookup_metadata( $meta_id, $post_id, $meta_key ) {
            if ( '_wp_attached_file' === $meta_key ) wp_cache_set( 'last_changed', microtime(), 'pdf-thumbnail-inserter-lookup' );
        }

        public function get_attachment_lookup_cache_key( $values ) {
            return md5( wp_json_encode( $values ) . wp_cache_get_last_changed( 'posts' ) . wp_cache_get_last_changed( 'pdf-thumbnail-inserter-lookup' ) );
        }

        /**
         * One ranked, cached batch replaces a WP_Query/meta join for every filename/token.
         * A cold substring search still examines attachment metadata; batching removes repeated
         * joins/scans and cache invalidation prevents repeating it for every referring post.
         *
         * @param string[] $needles At most six literal relative paths or filename fragments.
         * @param string   $mode    exact, filename or reference (also search the display title).
         * @return int[]
         */
        public function find_attachment_filename_candidates( $needles, $mode = 'filename' ) {
            global $wpdb;
            $needles = array_slice( array_values( array_unique( array_filter( $needles, static function ( $needle ) { return '' !== $needle; } ) ) ), 0, 6 );
            if ( ! $needles ) return array();
            $key = $this->get_attachment_lookup_cache_key( array( $mode, $needles ) );
            $cached = wp_cache_get( $key, 'pdf-thumbnail-inserter-lookup' );
            if ( is_array( $cached ) ) return $cached;
            $values = array_pad( $needles, 6, '' );
            if ( 'exact' === $mode ) {
                $condition = $wpdb->prepare( "pti_lookup_file.meta_value <> '' AND pti_lookup_file.meta_value IN (%s,%s,%s,%s,%s,%s)",
                    $values[0], $values[1], $values[2], $values[3], $values[4], $values[5] );
                $score = $wpdb->prepare( 'CASE pti_lookup_file.meta_value WHEN %s THEN 6 WHEN %s THEN 5 WHEN %s THEN 4 WHEN %s THEN 3 WHEN %s THEN 2 WHEN %s THEN 1 ELSE 0 END',
                    $values[0], $values[1], $values[2], $values[3], $values[4], $values[5] );
            } else {
                $arguments = array();
                foreach ( $values as $value ) {
                    $pattern = '%' . $wpdb->esc_like( $value ) . '%';
                    $arguments[] = $value;
                    $arguments[] = 'reference' === $mode ? 1 : 0;
                    $arguments[] = $pattern;
                    $arguments[] = $pattern;
                }
                $score = $wpdb->prepare(
                    "(CASE WHEN %s <> '' AND ((%d = 1 AND {$wpdb->posts}.post_title LIKE %s) OR pti_lookup_file.meta_value LIKE %s) THEN 1 ELSE 0 END) +
                    (CASE WHEN %s <> '' AND ((%d = 1 AND {$wpdb->posts}.post_title LIKE %s) OR pti_lookup_file.meta_value LIKE %s) THEN 1 ELSE 0 END) +
                    (CASE WHEN %s <> '' AND ((%d = 1 AND {$wpdb->posts}.post_title LIKE %s) OR pti_lookup_file.meta_value LIKE %s) THEN 1 ELSE 0 END) +
                    (CASE WHEN %s <> '' AND ((%d = 1 AND {$wpdb->posts}.post_title LIKE %s) OR pti_lookup_file.meta_value LIKE %s) THEN 1 ELSE 0 END) +
                    (CASE WHEN %s <> '' AND ((%d = 1 AND {$wpdb->posts}.post_title LIKE %s) OR pti_lookup_file.meta_value LIKE %s) THEN 1 ELSE 0 END) +
                    (CASE WHEN %s <> '' AND ((%d = 1 AND {$wpdb->posts}.post_title LIKE %s) OR pti_lookup_file.meta_value LIKE %s) THEN 1 ELSE 0 END)",
                    $arguments[0], $arguments[1], $arguments[2], $arguments[3], $arguments[4], $arguments[5], $arguments[6], $arguments[7],
                    $arguments[8], $arguments[9], $arguments[10], $arguments[11], $arguments[12], $arguments[13], $arguments[14], $arguments[15],
                    $arguments[16], $arguments[17], $arguments[18], $arguments[19], $arguments[20], $arguments[21], $arguments[22], $arguments[23]
                );
                $condition = '(' . $score . ') > 0';
            }
            $query = new WP_Query();
            $clauses_filter = static function ( $clauses, $current_query ) use ( $query, $condition, $score, $wpdb ) {
                if ( $current_query !== $query ) return $clauses;
                $clauses['join'] .= " INNER JOIN {$wpdb->postmeta} pti_lookup_file ON {$wpdb->posts}.ID = pti_lookup_file.post_id";
                $clauses['where'] .= " AND pti_lookup_file.meta_key = '_wp_attached_file' AND (" . $condition . ')';
                $clauses['groupby'] = "{$wpdb->posts}.ID, {$wpdb->posts}.post_date";
                $clauses['orderby'] = ( '' !== $score ? 'MAX(' . $score . ') DESC, ' : '' ) . "{$wpdb->posts}.post_date DESC, {$wpdb->posts}.ID DESC";
                return $clauses;
            };
            add_filter( 'posts_clauses', $clauses_filter, 10, 2 );
            try {
                $ids = $query->query( array( 'post_type' => 'attachment', 'post_status' => 'inherit',
                    'posts_per_page' => 20, 'fields' => 'ids', 'no_found_rows' => true ) );
            } finally {
                remove_filter( 'posts_clauses', $clauses_filter, 10 );
            }
            $ids = array_map( 'absint', $ids );
            if ( $ids ) {
                // Prime full posts and metadata together before candidate validation/scoring.
                get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post__in' => $ids,
                    'posts_per_page' => 20, 'orderby' => 'post__in', 'no_found_rows' => true ) );
            }
            wp_cache_set( $key, $ids, 'pdf-thumbnail-inserter-lookup', 300 );
            return $ids;
        }

        public function get_attachment_id_from_file_url( $file_url ) {
            $key = $this->get_attachment_lookup_cache_key( array( 'url', $file_url ) );
            $cached = wp_cache_get( $key, 'pdf-thumbnail-inserter-lookup' );
            if ( false !== $cached ) return absint( $cached );
            $id = $this->resolve_attachment_id_from_file_url( $file_url );
            wp_cache_set( $key, $id, 'pdf-thumbnail-inserter-lookup', 300 );
            return $id;
        }

        public function resolve_attachment_id_from_file_url( $file_url ) {
            $attachment_id = absint( attachment_url_to_postid( $file_url ) );
            if ( $attachment_id ) {
                return $attachment_id;
            }

            $uploads     = wp_get_upload_dir();
            $uploads_url = ! empty( $uploads['baseurl'] ) ? $this->get_file_url_identity( $uploads['baseurl'] ) : '';
            $file_path   = $this->get_file_url_identity( $file_url );

            if ( '' === $uploads_url || '' === $file_path || 0 !== strpos( $file_path, trailingslashit( $uploads_url ) ) ) {
                return 0;
            }

            $relative_file = ltrim( substr( $file_path, strlen( trailingslashit( $uploads_url ) ) ), '/' );
            if ( '' === $relative_file ) {
                return 0;
            }

            $variants = $this->get_relative_upload_file_variants( $relative_file );
            $attachment_ids = $this->find_attachment_filename_candidates( $variants, 'exact' );
            foreach ( $variants as $variant ) {
                foreach ( $attachment_ids as $id ) {
                    if ( get_post_meta( $id, '_wp_attached_file', true ) === $variant ) return $id;
                }
            }
            return $attachment_ids ? $attachment_ids[0] : 0;
        }

        public function normalize_pdf_candidate_url( $candidate ) {
            $candidate = html_entity_decode( (string) $candidate, ENT_QUOTES, get_bloginfo( 'charset' ) );
            $candidate = trim( wp_unslash( $candidate ) );

            if ( '' === $candidate ) {
                return '';
            }

            $query = (string) wp_parse_url( $candidate, PHP_URL_QUERY );
            if ( '' !== $query ) {
                parse_str( $query, $query_args );
                if ( ! empty( $query_args['file'] ) ) {
                    $embedded_file = rawurldecode( (string) $query_args['file'] );
                    $embedded_path = $this->get_url_path( $embedded_file );
                    if ( 'pdf' === strtolower( pathinfo( $embedded_path, PATHINFO_EXTENSION ) ) ) {
                        $candidate = $embedded_file;
                    }
                }
            }

            if ( 0 === strpos( $candidate, '//' ) ) {
                $candidate = ( is_ssl() ? 'https:' : 'http:' ) . $candidate;
            } elseif ( 0 === strpos( $candidate, '/' ) ) {
                $candidate = home_url( $candidate );
            } elseif ( 0 === strpos( $candidate, 'wp-content/' ) ) {
                $candidate = home_url( '/' . $candidate );
            }

            $path = $this->get_url_path( $candidate );
            if ( 'pdf' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
                return '';
            }

            return esc_url_raw( $candidate );
        }

        /** Resolve only real PDF files within this site's uploads, never decoded traversal or symlinks outside it. */
        public function get_local_upload_pdf_path( $file_url ) {
            return $this->get_local_upload_file_path( $file_url, 'pdf' );
        }

        public function get_local_upload_file_path( $file_url, $extension = '' ) {
            $uploads = wp_get_upload_dir();
            if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
                return '';
            }

            $file_host = wp_parse_url( $file_url, PHP_URL_HOST );
            $base_host = wp_parse_url( $uploads['baseurl'], PHP_URL_HOST );
            $scheme = wp_parse_url( $file_url, PHP_URL_SCHEME );
            if ( ! $file_host || ! $base_host || strtolower( $file_host ) !== strtolower( $base_host )
                || ! in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true )
                || wp_parse_url( $file_url, PHP_URL_PORT ) !== wp_parse_url( $uploads['baseurl'], PHP_URL_PORT )
                || wp_parse_url( $file_url, PHP_URL_USER ) || wp_parse_url( $file_url, PHP_URL_PASS ) ) {
                return '';
            }

            $file_path = $this->get_file_url_identity( $file_url );
            $base_path = trailingslashit( $this->get_file_url_identity( $uploads['baseurl'] ) );
            if ( 0 !== strpos( $file_path, $base_path ) || ( $extension && $extension !== strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) ) )
                || false !== strpos( $file_path, "\0" ) || false !== strpos( $file_path, '\\' ) ) {
                return '';
            }
            $relative_file = substr( $file_path, strlen( $base_path ) );
            foreach ( explode( '/', $relative_file ) as $segment ) {
                if ( '.' === $segment || '..' === $segment ) {
                    return '';
                }
            }

            $real_uploads = realpath( $uploads['basedir'] );
            $real_file = realpath( trailingslashit( $uploads['basedir'] ) . $relative_file );
            if ( ! $real_uploads || ! $real_file || ! is_file( $real_file ) || ! is_readable( $real_file ) ) {
                return '';
            }
            $prefix = trailingslashit( wp_normalize_path( $real_uploads ) );
            $path = wp_normalize_path( $real_file );
            $inside = '\\' === DIRECTORY_SEPARATOR ? 0 === stripos( $path, $prefix ) : 0 === strpos( $path, $prefix );
            return $inside && ( ! $extension || $extension === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) ? $path : '';
        }
}
