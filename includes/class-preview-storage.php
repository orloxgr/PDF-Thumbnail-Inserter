<?php
/** Preview storage subsystem. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_PreviewStorage {
    /** @var PDF_Thumbnail_Inserter_FeaturedImages */
    private $featured_images;

    /** @var PDF_Thumbnail_Inserter_FileResolver */
    private $file_resolver;

    /** @var PDF_Thumbnail_Inserter_Previews */
    private $previews;

    /** @var PDF_Thumbnail_Inserter_RuntimeState */
    private $state;

    /** Wire the request-local collaborators before registering any hooks. */
    public function set_dependencies( PDF_Thumbnail_Inserter_FeaturedImages $featured_images, PDF_Thumbnail_Inserter_FileResolver $file_resolver, PDF_Thumbnail_Inserter_Previews $previews, PDF_Thumbnail_Inserter_RuntimeState $state ) {
        $this->featured_images = $featured_images;
        $this->file_resolver = $file_resolver;
        $this->previews = $previews;
        $this->state = $state;
    }

        /**
         * Serialize plugin preview writers for a validated, local PDF.
         *
         * The zero-byte lock file is retained: unlinking a flock file permits
         * waiting and newly arriving processes to lock different inodes.
         * OS locks are released on process termination, so crashes cannot leave
         * a permanently busy PDF. WP_Filesystem does not expose stream locks.
         *
         * @param string   $pdf_path Validated real path inside uploads.
         * @param callable $callback Work to perform while holding the lock.
         * @return mixed|WP_Error Callback result, or a lock error.
         */
        public function with_pdf_preview_lock( $pdf_path, $callback ) {
            return $this->with_preview_file_lock( $pdf_path . '.pti-preview.lock', function () use ( $pdf_path, $callback ) {
                // A deletion can finish after a writer validated its path but before it obtained the lock.
                clearstatcache( true, $pdf_path );
                if ( ! is_file( $pdf_path ) || ! is_readable( $pdf_path ) ) {
                    return new WP_Error( 'missing_file', __( 'Original PDF file could not be found.', 'pdf-thumbnail-inserter' ) );
                }
                return call_user_func( $callback );
            } );
        }

        /** Native locks are also used to serialize each administrator's maintenance cursor. */
        public function with_preview_file_lock( $lock_path, $callback ) {
            $lock = $this->acquire_preview_file_lock( $lock_path );
            if ( is_wp_error( $lock ) ) return $lock;
            try {
                return call_user_func( $callback );
            } finally {
                fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Releases the native OS lock.
            }
        }

        public function acquire_preview_file_lock( $lock_path ) {
            if ( is_link( $lock_path ) || ( file_exists( $lock_path ) && ! is_file( $lock_path ) ) ) {
                return new WP_Error( 'preview_lock_failed', __( 'Could not lock PDF preview generation.', 'pdf-thumbnail-inserter' ) );
            }

            // A native stream is required for the OS advisory lock; no credentials or remote filesystem are involved.
            $lock = @fopen( $lock_path, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            if ( false === $lock ) {
                return new WP_Error( 'preview_lock_failed', __( 'Could not lock PDF preview generation.', 'pdf-thumbnail-inserter' ) );
            }

            if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
                fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Failed native lock must be closed.
                return new WP_Error( 'preview_busy', __( 'A preview operation is already running. Please try again shortly.', 'pdf-thumbnail-inserter' ) );
            }
            return $lock;
        }

        /** Publish an owned image only after metadata succeeds; roll back failed writes. */
        public function create_pdf_preview_attachment( $attachment_id, $path, $mime, $title ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $old_preview_id = absint( get_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ID, true ) );
            $preview_id = 0;
            $published = false;
            $metadata = array();
            try {
                clean_post_cache( $attachment_id );
                $parent_file = $this->file_resolver->get_valid_local_pdf_attachment_file( $attachment_id );
                if ( is_wp_error( $parent_file ) ) return $parent_file;
                $inserted = wp_insert_attachment(
                    array(
                        'post_mime_type' => $mime,
                        'post_title'     => $title . ' ' . __( 'Preview', 'pdf-thumbnail-inserter' ),
                        'post_content'   => '',
                        'post_status'    => 'inherit',
                        'post_parent'    => $attachment_id,
                    ),
                    $path,
                    $attachment_id,
                    true
                );
                if ( is_wp_error( $inserted ) ) {
                    return $inserted;
                }
                $preview_id = $inserted;
                $metadata = wp_generate_attachment_metadata( $preview_id, $path );
                if ( is_wp_error( $metadata ) || empty( $metadata['width'] ) || empty( $metadata['height'] ) ) {
                    return new WP_Error( 'preview_metadata_failed', __( 'Could not create preview image metadata.', 'pdf-thumbnail-inserter' ) );
                }
                wp_update_attachment_metadata( $preview_id, $metadata );
                // A false return may mean unchanged data; verify the persisted record instead.
                $stored_metadata = wp_get_attachment_metadata( $preview_id, true );
                if ( ! is_array( $stored_metadata ) || empty( $stored_metadata['file'] ) || empty( $stored_metadata['width'] ) || empty( $stored_metadata['height'] ) ) {
                    return new WP_Error( 'preview_metadata_failed', __( 'Could not save preview image metadata.', 'pdf-thumbnail-inserter' ) );
                }
                $thumbnail_url = $this->previews->get_image_url_with_size_fallback( $preview_id, 'medium' );
                if ( ! get_attached_file( $preview_id, true ) || '' === $thumbnail_url ) {
                    return new WP_Error( 'preview_metadata_failed', __( 'Could not save the preview image file reference.', 'pdf-thumbnail-inserter' ) );
                }
                if ( 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
                    return new WP_Error( 'invalid_pdf', __( 'The original PDF attachment no longer exists.', 'pdf-thumbnail-inserter' ) );
                }
                update_post_meta( $preview_id, PDF_Thumbnail_Inserter::META_PARENT_PDF, $attachment_id );
                update_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ID, $preview_id );
                if ( ! $this->is_owned_pdf_preview( $preview_id, $attachment_id ) || $preview_id !== absint( get_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ID, true ) ) ) {
                    return new WP_Error( 'preview_metadata_failed', __( 'Could not save preview ownership metadata.', 'pdf-thumbnail-inserter' ) );
                }
                $published = true;
                $this->featured_images->invalidate_preview_caches();
                update_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_GENERATED_AT, time() );
                delete_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ERROR );
                if ( $this->is_owned_pdf_preview( $old_preview_id, $attachment_id ) ) {
                    wp_delete_attachment( $old_preview_id, true );
                }
                return array(
                    'generated'      => true,
                    'preview_source' => 'plugin',
                    'preview_id'     => $preview_id,
                    'thumbnail_url'  => esc_url_raw( $thumbnail_url ),
                );
            } catch ( \Throwable $error ) {
                return new WP_Error( 'preview_save_failed', sanitize_text_field( $error->getMessage() ) );
            } finally {
                if ( ! $published ) {
                    if ( $preview_id ) {
                        if ( $preview_id === absint( get_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ID, true ) ) ) {
                            if ( $old_preview_id ) {
                                update_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ID, $old_preview_id );
                            } else {
                                delete_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ID );
                            }
                        }
                        // Metadata writes can fail after sizes are created. Clean the generated
                        // files using the in-memory record before deleting the new attachment.
                        if ( is_array( $metadata ) && ! empty( $metadata ) ) {
                            $generated_file = ! empty( $metadata['file'] ) ? path_join( wp_get_upload_dir()['basedir'], $metadata['file'] ) : $path;
                            wp_delete_attachment_files( $preview_id, $metadata, array(), $generated_file );
                        }
                        wp_delete_attachment( $preview_id, true );
                        wp_delete_file( $path );
                    } else {
                        wp_delete_file( $path );
                    }
                }
            }
        }

        public function is_owned_pdf_preview( $preview_id, $attachment_id ) {
            $preview_id = absint( $preview_id );
            $attachment_id = absint( $attachment_id );
            $preview = $preview_id ? get_post( $preview_id ) : null;

            return $preview && $preview_id !== $attachment_id
                && 'attachment' === $preview->post_type
                && 0 === strpos( (string) get_post_mime_type( $preview_id ), 'image/' )
                && $attachment_id === absint( $preview->post_parent )
                && $attachment_id === absint( get_post_meta( $preview_id, PDF_Thumbnail_Inserter::META_PARENT_PDF, true ) );
        }

        public function cleanup_generated_preview( $attachment_id ) {
            if ( 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
                return;
            }

            $this->featured_images->invalidate_preview_caches();

            $preview_id = absint( get_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ID, true ) );
            if ( $this->is_owned_pdf_preview( $preview_id, $attachment_id ) ) {
                if ( wp_delete_attachment( $preview_id, true ) ) {
                    delete_post_meta( $attachment_id, PDF_Thumbnail_Inserter::META_PREVIEW_ID, $preview_id );
                }
            }
        }

        /** Keep core deletion and preview publication in the same per-PDF critical section. */
        public function protect_pdf_attachment_deletion( $delete, $post, $force_delete ) {
            if ( null !== $delete || 'application/pdf' !== $post->post_mime_type ) return $delete;
            $id = (int) $post->ID;
            if ( isset( $this->state->attachment_delete_locks[ $id ] ) ) return false;
            $file = get_attached_file( $id, true );
            if ( is_string( $file ) && '' !== $file ) {
                $path = realpath( $file );
                if ( false === $path && realpath( dirname( $file ) ) ) $path = realpath( dirname( $file ) ) . '/' . basename( $file );
                $uploads = wp_get_upload_dir();
                $base = ! empty( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
                if ( $path && $base && 'pdf' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
                    $prefix = trailingslashit( wp_normalize_path( $base ) );
                    $path = wp_normalize_path( $path );
                    $inside = '\\' === DIRECTORY_SEPARATOR ? 0 === stripos( $path, $prefix ) : 0 === strpos( $path, $prefix );
                    if ( $inside ) {
                        $lock = $this->acquire_preview_file_lock( $path . '.pti-preview.lock' );
                        if ( is_wp_error( $lock ) ) return false;
                        $this->state->attachment_delete_locks[ $id ] = array( 'path' => $path, 'lock' => $lock );
                    }
                }
            }
            $preview_id = absint( get_post_meta( $id, PDF_Thumbnail_Inserter::META_PREVIEW_ID, true ) );
            if ( $this->is_owned_pdf_preview( $preview_id, $id ) ) {
                $this->cleanup_generated_preview( $id );
                if ( get_post( $preview_id ) ) {
                    $this->release_pdf_deletion_locks( $id );
                    return false; // A third-party deletion veto must not orphan the owned image.
                }
            }
            return $delete;
        }

        public function release_deleted_pdf_lock( $post_id ) {
            if ( isset( $this->state->attachment_delete_locks[ $post_id ] ) ) {
                clearstatcache( true, $this->state->attachment_delete_locks[ $post_id ]['path'] );
                if ( ! file_exists( $this->state->attachment_delete_locks[ $post_id ]['path'] ) ) $this->release_pdf_deletion_locks( $post_id );
            }
        }

        public function release_pdf_deletion_locks( $post_id = 0 ) {
            foreach ( $this->state->attachment_delete_locks as $id => $entry ) {
                if ( $post_id && (int) $post_id !== $id ) continue;
                fclose( $entry['lock'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Releases a native deletion lock.
                unset( $this->state->attachment_delete_locks[ $id ] );
            }
        }
}
