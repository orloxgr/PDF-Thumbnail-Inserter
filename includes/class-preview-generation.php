<?php
/** Preview generation subsystem. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_PreviewGeneration {
    /** @var PDF_Thumbnail_Inserter_Admin */
    private $admin;

    /** @var PDF_Thumbnail_Inserter */
    private $callbacks;

    /** @var PDF_Thumbnail_Inserter_FeaturedImages */
    private $featured_images;

    /** @var PDF_Thumbnail_Inserter_FileResolver */
    private $file_resolver;

    /** @var PDF_Thumbnail_Inserter_PreviewStorage */
    private $preview_storage;

    /** @var PDF_Thumbnail_Inserter_Previews */
    private $previews;

    /** Wire the request-local collaborators before registering any hooks. */
    public function set_dependencies( PDF_Thumbnail_Inserter_Admin $admin, PDF_Thumbnail_Inserter $callbacks, PDF_Thumbnail_Inserter_FeaturedImages $featured_images, PDF_Thumbnail_Inserter_FileResolver $file_resolver, PDF_Thumbnail_Inserter_PreviewStorage $preview_storage, PDF_Thumbnail_Inserter_Previews $previews ) {
        $this->admin = $admin;
        $this->callbacks = $callbacks;
        $this->featured_images = $featured_images;
        $this->file_resolver = $file_resolver;
        $this->preview_storage = $preview_storage;
        $this->previews = $previews;
    }

        public function maybe_generate_core_pdf_preview( $attachment_id, $force = false ) {
            $attachment_id = absint( $attachment_id );
            if ( ! $attachment_id || 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
                return new WP_Error( 'invalid_pdf', __( 'Attachment is not a valid PDF.', 'pdf-thumbnail-inserter' ) );
            }

            $generation_status = $this->admin->get_pdf_preview_generation_status();
            if ( empty( $generation_status['available'] ) ) {
                $message = ! empty( $generation_status['reason'] ) ? (string) $generation_status['reason'] : __( 'PDF preview generation is unavailable on this server.', 'pdf-thumbnail-inserter' );
                update_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ERROR, $message );
                return new WP_Error( 'generation_unavailable', $message );
            }

            $existing_meta = wp_get_attachment_metadata( $attachment_id, true );
            if ( ! $force && $this->admin->metadata_has_pdf_preview( $existing_meta ) ) {
                return array(
                    'generated'      => false,
                    'preview_source' => 'wordpress',
                    'preview_id'     => 0,
                );
            }

            $file_path = get_attached_file( $attachment_id, true );
            if ( ! $file_path || ! file_exists( $file_path ) ) {
                update_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ERROR, __( 'Original PDF file could not be found.', 'pdf-thumbnail-inserter' ) );
                return new WP_Error( 'missing_file', __( 'Original PDF file could not be found.', 'pdf-thumbnail-inserter' ) );
            }

            require_once ABSPATH . 'wp-admin/includes/image.php';

            $literal_prefix = strtr( dirname( $file_path ) . '/' . pathinfo( $file_path, PATHINFO_FILENAME ), array( '[' => '[[]', ']' => '[]]', '?' => '[?]', '*' => '[*]' ) );
            $existing_files = glob( $literal_prefix . '-pdf*.*' );
            $existing_files = $existing_files ? array_map( 'wp_normalize_path', $existing_files ) : array();
            $generated_files = array();
            $capture_metadata = function ( $data, $id ) use ( $attachment_id, $file_path, &$generated_files ) {
                if ( (int) $id === $attachment_id ) {
                    $generated_files = array_unique( array_merge( $generated_files, $this->get_core_preview_files( $file_path, $data ) ) );
                }
                return $data;
            };
            $published = false;
            $metadata = array();
            $failure = '';
            $defer_metadata = function ( $check, $id, $key ) use ( $attachment_id ) {
                // Core builds sub-size metadata in memory; publish once after every size is ready.
                return (int) $id === $attachment_id && '_wp_attachment_metadata' === $key ? false : $check;
            };
            add_filter( 'wp_update_attachment_metadata', $capture_metadata, PHP_INT_MIN, 2 );
            add_filter( 'update_post_metadata', $defer_metadata, PHP_INT_MAX, 3 );
            remove_filter( 'wp_generate_attachment_metadata', array( $this->callbacks, 'maybe_generate_pdf_preview_on_upload' ), 20 );
            try {
                $metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
                if ( is_array( $metadata ) ) {
                    remove_filter( 'update_post_metadata', $defer_metadata, PHP_INT_MAX );
                    wp_update_attachment_metadata( $attachment_id, $metadata );
                    wp_cache_delete( $attachment_id, 'post_meta' );
                    $stored = wp_get_attachment_metadata( $attachment_id, true );
                    $published = $this->admin->metadata_has_pdf_preview( $metadata ) && is_array( $stored )
                        && isset( $stored['sizes'] ) && $stored['sizes'] === $metadata['sizes']
                        && '' !== $this->previews->get_wordpress_pdf_preview_url( $attachment_id, 'full' );
                    if ( $this->admin->metadata_has_pdf_preview( $metadata ) && ! $published ) {
                        $failure = __( 'Could not save WordPress PDF preview metadata.', 'pdf-thumbnail-inserter' );
                    }
                }
            } catch ( \Throwable $error ) {
                $failure = sanitize_text_field( $error->getMessage() );
            } finally {
                remove_filter( 'wp_update_attachment_metadata', $capture_metadata, PHP_INT_MIN );
                remove_filter( 'update_post_metadata', $defer_metadata, PHP_INT_MAX );
                add_filter( 'wp_generate_attachment_metadata', array( $this->callbacks, 'maybe_generate_pdf_preview_on_upload' ), 20, 3 );
                if ( ! $published ) {
                    wp_cache_delete( $attachment_id, 'post_meta' );
                    if ( wp_get_attachment_metadata( $attachment_id, true ) !== $existing_meta ) {
                        try {
                            // Restore exact stored values, without reapplying the transformation that failed.
                            if ( false === $existing_meta ) delete_post_meta( $attachment_id, '_wp_attachment_metadata' );
                            else update_post_meta( $attachment_id, '_wp_attachment_metadata', $existing_meta );
                        } catch ( \Throwable $rollback_error ) {
                            $failure = __( 'Could not restore the previous PDF preview metadata.', 'pdf-thumbnail-inserter' );
                        }
                    }
                    wp_cache_delete( $attachment_id, 'post_meta' );
                    $retained_meta = wp_get_attachment_metadata( $attachment_id, true );
                    if ( $retained_meta !== $existing_meta ) $failure = __( 'Could not restore the previous PDF preview metadata.', 'pdf-thumbnail-inserter' );
                    $referenced_files = $this->get_core_preview_files( $file_path, $retained_meta );
                    foreach ( array_diff( $generated_files, $existing_files, $referenced_files ) as $generated_file ) wp_delete_file( $generated_file );
                }
            }

            if ( $published ) {
                delete_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ERROR );
                $this->featured_images->invalidate_preview_caches();
                return array( 'generated' => true, 'preview_source' => 'wordpress', 'preview_id' => 0 );
            }
            if ( $failure ) {
                update_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ERROR, $failure );
                return new WP_Error( 'core_preview_metadata_failed', $failure );
            }

            require_once ABSPATH . 'wp-includes/media.php';
            $editor = wp_get_image_editor( $file_path );
            $message = is_wp_error( $editor )
                /* translators: %s: WordPress image editor error message. */
                ? sprintf( __( 'WordPress could not generate a PDF preview: %s', 'pdf-thumbnail-inserter' ), $editor->get_error_message() )
                : __( 'WordPress did not generate preview metadata for this PDF.', 'pdf-thumbnail-inserter' );

            update_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ERROR, $message );

            return new WP_Error( 'core_preview_failed', $message );
        }

        /** Paths returned by core's own PDF metadata, never arbitrary uploaded metadata paths. */
        public function get_core_preview_files( $pdf_path, $metadata ) {
            $files = array();
            if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) return $files;
            $directory = wp_normalize_path( realpath( dirname( $pdf_path ) ) );
            $prefix = pathinfo( $pdf_path, PATHINFO_FILENAME ) . '-pdf';
            foreach ( $metadata['sizes'] as $size ) {
                if ( ! is_array( $size ) || empty( $size['file'] ) || ! is_string( $size['file'] )
                    || basename( $size['file'] ) !== $size['file'] || 0 !== strpos( $size['file'], $prefix ) ) continue;
                $path = realpath( $directory . '/' . $size['file'] );
                if ( $path && wp_normalize_path( dirname( $path ) ) === $directory && wp_getimagesize( $path ) ) $files[] = wp_normalize_path( $path );
            }
            return $files;
        }

        /**
         * @param int  $attachment_id Attachment ID.
         * @param bool $allow_retry   Whether to retry generation when a preview is missing.
         * @return array<string,mixed>|WP_Error
         */
        public function ensure_pdf_preview( $attachment_id, $allow_retry = false ) {
            $attachment_id = absint( $attachment_id );
            if ( ! $attachment_id || 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
                return new WP_Error( 'invalid_pdf', __( 'Attachment is not a valid PDF.', 'pdf-thumbnail-inserter' ) );
            }

            $file_data = $this->file_resolver->get_valid_local_pdf_attachment_file( $attachment_id );
            if ( is_wp_error( $file_data ) ) {
                return $file_data;
            }
            return $this->preview_storage->with_pdf_preview_lock(
                $file_data['path'],
                function () use ( $attachment_id, $allow_retry ) {
                    wp_cache_delete( $attachment_id, 'post_meta' );
                    return $this->generate_pdf_attachment_preview( $attachment_id, $allow_retry );
                }
            );
        }

        public function generate_pdf_attachment_preview( $attachment_id, $allow_retry ) {
            clean_post_cache( $attachment_id );
            $current_file = $this->file_resolver->get_valid_local_pdf_attachment_file( $attachment_id );
            if ( is_wp_error( $current_file ) ) return $current_file;
            $existing = $this->previews->get_pdf_preview_data( $attachment_id, 'full', false );
            if ( 'placeholder' !== $existing['preview_source'] && ! $allow_retry ) {
                return array(
                    'generated'      => false,
                    'preview_source' => $existing['preview_source'],
                    'preview_id'     => ! empty( $existing['preview_id'] ) ? absint( $existing['preview_id'] ) : 0,
                );
            }

            $core_result = $this->maybe_generate_core_pdf_preview( $attachment_id, $allow_retry );
            $existing = $this->previews->get_pdf_preview_data( $attachment_id, 'full', false );
            if ( 'placeholder' !== $existing['preview_source'] ) {
                return array(
                    'generated'      => ! is_wp_error( $core_result ),
                    'preview_source' => $existing['preview_source'],
                    'preview_id'     => ! empty( $existing['preview_id'] ) ? absint( $existing['preview_id'] ) : 0,
                );
            }

            $file_path = get_attached_file( $attachment_id, true );
            if ( ! $file_path || ! file_exists( $file_path ) ) {
                update_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ERROR, __( 'Original PDF file could not be found.', 'pdf-thumbnail-inserter' ) );
                return new WP_Error( 'missing_file', __( 'Original PDF file could not be found.', 'pdf-thumbnail-inserter' ) );
            }

            if ( ! class_exists( 'Imagick' ) ) {
                $message = is_wp_error( $core_result ) ? $core_result->get_error_message() : __( 'Imagick is not available on this server.', 'pdf-thumbnail-inserter' );
                update_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ERROR, $message );
                return new WP_Error( 'imagick_missing', $message );
            }

            $path_info = pathinfo( $file_path );
            $dir_path = $path_info['dirname'];
            $filename = $path_info['filename'];
            $preview_filename = wp_unique_filename( $dir_path, $filename . '-pdf-preview.jpg' );
            $preview_path = trailingslashit( $dir_path ) . $preview_filename;

            $imagick = null;
            try {
                $imagick = new Imagick();
                $imagick->setResolution( 150, 150 );
                $imagick->readImage( $file_path . '[0]' );
                $imagick->setImageFormat( 'jpeg' );
                $imagick->setImageBackgroundColor( 'white' );

                if ( defined( 'Imagick::ALPHACHANNEL_REMOVE' ) ) {
                    $imagick->setImageAlphaChannel( Imagick::ALPHACHANNEL_REMOVE );
                }

                if ( defined( 'Imagick::LAYERMETHOD_FLATTEN' ) ) {
                    $flattened = $imagick->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
                    $imagick->clear();
                    $imagick->destroy();
                    $imagick = $flattened;
                }

                $imagick->setImageCompressionQuality( 82 );
                $imagick->thumbnailImage( 1200, 2000, true, false );
                $imagick->writeImage( $preview_path );
            } catch ( \Throwable $e ) {
                wp_delete_file( $preview_path );
                update_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ERROR, sanitize_text_field( $e->getMessage() ) );
                return new WP_Error( 'imagick_failed', sanitize_text_field( $e->getMessage() ) );
            } finally {
                if ( $imagick instanceof \Imagick ) {
                    $imagick->clear();
                    $imagick->destroy();
                }
            }

            if ( ! file_exists( $preview_path ) ) {
                update_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ERROR, __( 'Preview image could not be written to disk.', 'pdf-thumbnail-inserter' ) );
                return new WP_Error( 'preview_missing', __( 'Preview image could not be written to disk.', 'pdf-thumbnail-inserter' ) );
            }

            $result = $this->preview_storage->create_pdf_preview_attachment( $attachment_id, $preview_path, 'image/jpeg', get_the_title( $attachment_id ) );
            if ( is_wp_error( $result ) ) {
                update_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ERROR, $result->get_error_message() );
            }
            return $result;
        }

        public function generate_standalone_pdf_preview( $pdf_path, $dir_path, $filename, $uploads_basedir ) {
            if ( ! class_exists( 'Imagick' ) || '' === $pdf_path || ! file_exists( $pdf_path ) ) {
                return '';
            }

            $real_pdf     = realpath( $pdf_path );
            $real_uploads = realpath( $uploads_basedir );
            if ( false === $real_pdf || false === $real_uploads ) {
                return '';
            }

            $real_pdf     = wp_normalize_path( $real_pdf );
            $real_uploads = trailingslashit( wp_normalize_path( $real_uploads ) );
            $inside_uploads = '\\' === DIRECTORY_SEPARATOR ? 0 === stripos( $real_pdf, $real_uploads ) : 0 === strpos( $real_pdf, $real_uploads );
            if ( ! $inside_uploads || 'pdf' !== strtolower( pathinfo( $real_pdf, PATHINFO_EXTENSION ) ) ) {
                return '';
            }

            $preview_path = wp_normalize_path( trailingslashit( $dir_path ) . $filename . '-pdf-preview.jpg' );
            if ( is_file( $preview_path ) && wp_getimagesize( $preview_path ) ) {
                return $preview_path;
            }

            $failure_key = 'pdf_thumbnail_inserter_preview_fail_' . md5( $real_pdf . ':' . (string) filemtime( $real_pdf ) );
            if ( get_transient( $failure_key ) ) {
                return '';
            }

            $result = $this->preview_storage->with_pdf_preview_lock(
                $real_pdf,
                function () use ( $real_pdf, $preview_path, $failure_key ) {
                    clearstatcache( true, $preview_path );
                    if ( is_file( $preview_path ) && wp_getimagesize( $preview_path ) ) {
                        return $preview_path;
                    }
                    $temp_path = $preview_path . '.tmp.jpg';
                    $imagick = null;
                    try {
                        $imagick = new \Imagick();
                        $imagick->setResolution( 150, 150 );
                        $imagick->readImage( $real_pdf . '[0]' );
                        $imagick->setImageFormat( 'jpeg' );
                        $imagick->setImageBackgroundColor( 'white' );
                        $imagick->setImageAlphaChannel( \Imagick::ALPHACHANNEL_REMOVE );
                        $flattened = $imagick->mergeImageLayers( \Imagick::LAYERMETHOD_FLATTEN );
                        $imagick->clear();
                        $imagick->destroy();
                        $imagick = $flattened;
                        $imagick->setImageCompressionQuality( 82 );
                        $imagick->thumbnailImage( 1200, 2000, true, false );
                        if ( ! $imagick->writeImage( $temp_path ) || ! wp_getimagesize( $temp_path ) ) {
                            return '';
                        }
                        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
                        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
                        // Core's local move uses rename first. A copy fallback would expose a partial image.
                        $filesystem = new class( null ) extends WP_Filesystem_Direct {
                            public function copy( $source, $destination, $overwrite = false, $mode = false ) {
                                return false;
                            }
                        };
                        // Only a corrupt regular preview may be removed. Never recursively delete a destination.
                        // Newer core move(overwrite=true) pre-deletes its destination; use no-overwrite publication.
                        if ( is_link( $preview_path ) || ( file_exists( $preview_path ) && ! is_file( $preview_path ) ) ) {
                            return '';
                        }
                        if ( is_file( $preview_path ) && ! $filesystem->delete( $preview_path, false, 'f' ) ) {
                            return '';
                        }
                        if ( ! $filesystem->move( $temp_path, $preview_path ) ) {
                            return '';
                        }
                        $this->featured_images->invalidate_preview_caches();
                        $filesystem->chmod( $preview_path, 0644 );
                        delete_transient( $failure_key );
                        return $preview_path;
                    } catch ( \Throwable $error ) {
                        return '';
                    } finally {
                        if ( $imagick instanceof \Imagick ) {
                            $imagick->clear();
                            $imagick->destroy();
                        }
                        if ( is_file( $temp_path ) ) {
                            wp_delete_file( $temp_path );
                        }
                        if ( ! is_file( $preview_path ) ) {
                            set_transient( $failure_key, 1, 6 * HOUR_IN_SECONDS );
                        }
                    }
                }
            );
            return is_wp_error( $result ) ? '' : $result;
        }
}
