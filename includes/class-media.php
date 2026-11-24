<?php
/** Media subsystem. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_Media {
    /** @var PDF_Thumbnail_Inserter_Admin */
    private $admin;

    /** @var PDF_Thumbnail_Inserter_FileResolver */
    private $file_resolver;

    /** @var PDF_Thumbnail_Inserter_PreviewGeneration */
    private $preview_generation;

    /** @var PDF_Thumbnail_Inserter_PreviewStorage */
    private $preview_storage;

    /** @var PDF_Thumbnail_Inserter_Previews */
    private $previews;

    /** @var PDF_Thumbnail_Inserter_Settings */
    private $settings;

    /** Wire the request-local collaborators before registering any hooks. */
    public function set_dependencies( PDF_Thumbnail_Inserter_Admin $admin, PDF_Thumbnail_Inserter_FileResolver $file_resolver, PDF_Thumbnail_Inserter_PreviewGeneration $preview_generation, PDF_Thumbnail_Inserter_PreviewStorage $preview_storage, PDF_Thumbnail_Inserter_Previews $previews, PDF_Thumbnail_Inserter_Settings $settings ) {
        $this->admin = $admin;
        $this->file_resolver = $file_resolver;
        $this->preview_generation = $preview_generation;
        $this->preview_storage = $preview_storage;
        $this->previews = $previews;
        $this->settings = $settings;
    }

        public function ajax_fetch_pdf_data() {
            check_ajax_referer( PDF_Thumbnail_Inserter::NONCE_ACTION, 'nonce' );

            if ( ! current_user_can( 'upload_files' ) ) {
                wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'pdf-thumbnail-inserter' ) ), 403 );
            }

            $attachment_id = isset( $_POST['attachment_id'] ) && is_scalar( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
            if ( ! $attachment_id ) {
                wp_send_json_error( array( 'message' => __( 'Invalid attachment ID.', 'pdf-thumbnail-inserter' ) ), 400 );
            }

            if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'pdf-thumbnail-inserter' ) ), 403 );
            }

            if ( 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Selected file is not a PDF.', 'pdf-thumbnail-inserter' ) ), 400 );
            }

            $preview_data = $this->previews->get_pdf_preview_data( $attachment_id, 'medium' );

            wp_send_json_success(
                array(
                    'attachment_id' => $attachment_id,
                    'thumbnail_url' => $preview_data['thumbnail_url'],
                    'file_url'      => $preview_data['file_url'],
                    'file_title'    => $preview_data['title'],
                    'preview_source' => $preview_data['preview_source'],
                    'shortcode'     => sprintf( '[pdf_thumbnail_inserter id="%d"]', $attachment_id ),
                )
            );
        }

        public function ajax_list_missing_previews() {
            check_ajax_referer( PDF_Thumbnail_Inserter::NONCE_ACTION, 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'pdf-thumbnail-inserter' ) ), 403 );
            }

            // The browser opts into bounded discovery; keep the legacy unpaged response available.
            $page = isset( $_POST['page'] ) && is_scalar( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 0;
            $pdf_ids = get_posts(
                array(
                    'post_type'      => 'attachment',
                    'post_mime_type' => 'application/pdf',
                    'post_status'    => 'inherit',
                    'posts_per_page' => $page ? 100 : -1,
                    'paged'          => $page ? $page : 1,
                    'fields'         => 'ids',
                    'orderby'        => 'ID',
                    'order'          => 'ASC',
                )
            );

            $items = array();
            $skipped = 0;

            // An IDs-only WP_Query does not prime post/meta caches on WP 6.0.
            // Prime only the bounded page; the legacy unpaged response stays compatible.
            if ( $page && $pdf_ids ) {
                _prime_post_caches( $pdf_ids, false, true );
            }

            foreach ( $pdf_ids as $pdf_id ) {
                $pdf_id = absint( $pdf_id );
                // Discovery must not start expensive server-side rendering for every PDF.
                $preview_data = $this->previews->get_pdf_preview_data( $pdf_id, 'full', false );
                if ( 'placeholder' !== $preview_data['preview_source'] ) {
                    continue;
                }

                $file_data = $this->file_resolver->get_valid_local_pdf_attachment_file( $pdf_id );
                if ( is_wp_error( $file_data ) ) {
                    $skipped++;
                    continue;
                }

                $items[] = array(
                    'id'       => $pdf_id,
                    'title'    => $file_data['title'],
                    'file_url' => esc_url_raw( $file_data['url'] ),
                );
            }

            wp_send_json_success(
                array(
                    'items'   => $items,
                    'total'   => count( $items ),
                    'skipped' => $skipped,
                    'next_page' => $page && 100 === count( $pdf_ids ) ? $page + 1 : 0,
                )
            );
        }

        public function can_save_browser_preview( $attachment_id, $automatic = false ) {
            if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
                return false;
            }
            $settings = $this->settings->get_settings();
            if ( $automatic || ! current_user_can( 'manage_options' ) ) {
                return ! empty( $settings['auto_browser_preview_on_upload'] );
            }
            return true;
        }

        public function ajax_save_browser_preview() {
            check_ajax_referer( PDF_Thumbnail_Inserter::NONCE_ACTION, 'nonce' );

            $attachment_id = isset( $_POST['attachment_id'] ) && is_scalar( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
            if ( ! $attachment_id ) {
                wp_send_json_error( array( 'message' => __( 'Invalid attachment ID.', 'pdf-thumbnail-inserter' ) ), 400 );
            }

            if ( ! $this->can_save_browser_preview( $attachment_id, ! empty( $_POST['automatic'] ) ) ) {
                wp_send_json_error( array( 'message' => __( 'Insufficient permissions or automatic browser previews are disabled.', 'pdf-thumbnail-inserter' ) ), 403 );
            }

            $file_data = $this->file_resolver->get_valid_local_pdf_attachment_file( $attachment_id );
            if ( is_wp_error( $file_data ) ) {
                wp_send_json_error( array( 'message' => $file_data->get_error_message() ), 400 );
            }

            // Read only the single-file schema. $_FILES is not slashed by WordPress.
            if ( ! isset( $_FILES['preview']['name'], $_FILES['preview']['tmp_name'], $_FILES['preview']['type'], $_FILES['preview']['error'], $_FILES['preview']['size'] )
                || ! is_string( $_FILES['preview']['name'] ) || ! is_string( $_FILES['preview']['tmp_name'] )
                || ! is_string( $_FILES['preview']['type'] ) || ! is_scalar( $_FILES['preview']['error'] ) || ! is_scalar( $_FILES['preview']['size'] ) ) {
                wp_send_json_error( array( 'message' => __( 'Invalid preview image upload.', 'pdf-thumbnail-inserter' ) ), 400 );
            }
            $uploaded_file = array(
                'name' => sanitize_file_name( $_FILES['preview']['name'] ),
                'tmp_name' => sanitize_text_field( $_FILES['preview']['tmp_name'] ),
                'type' => sanitize_mime_type( $_FILES['preview']['type'] ),
                'error' => absint( $_FILES['preview']['error'] ),
                'size' => absint( $_FILES['preview']['size'] ),
            );
            $result = $this->preview_storage->with_pdf_preview_lock(
                $file_data['path'],
                function () use ( $attachment_id, $file_data, $uploaded_file ) {
                    return $this->save_browser_preview( $attachment_id, $file_data, $uploaded_file );
                }
            );
            if ( is_wp_error( $result ) ) {
                $details = $result->get_error_data();
                $status = 'preview_busy' === $result->get_error_code() ? 409 : ( isset( $details['status'] ) ? $details['status'] : 500 );
                wp_send_json_error( array( 'message' => $result->get_error_message() ), $status );
            }
            wp_send_json_success( $result );
        }

        public function save_browser_preview( $attachment_id, $file_data, $uploaded_file ) {
            clean_post_cache( $attachment_id );
            $current_file = $this->file_resolver->get_valid_local_pdf_attachment_file( $attachment_id );
            if ( is_wp_error( $current_file ) ) return $current_file;
            wp_cache_delete( $attachment_id, 'post_meta' );
            $current_preview = $this->previews->get_pdf_preview_data( $attachment_id, 'full', false );
            if ( 'placeholder' !== $current_preview['preview_source'] ) {
                return array(
                        'skipped'        => true,
                        'preview_source' => $current_preview['preview_source'],
                        'preview_id'     => ! empty( $current_preview['preview_id'] ) ? absint( $current_preview['preview_id'] ) : 0,
                );
            }

            if ( ! is_array( $uploaded_file ) ) {
                return new WP_Error( 'browser_preview_failed', __( 'Missing preview image upload.', 'pdf-thumbnail-inserter' ), array( 'status' => 400 ) );
            }

            foreach ( array( 'name', 'tmp_name', 'type', 'error', 'size' ) as $field ) {
                if ( ! isset( $uploaded_file[ $field ] ) || ! is_scalar( $uploaded_file[ $field ] ) ) {
                    return new WP_Error( 'browser_preview_failed', __( 'Invalid preview image upload.', 'pdf-thumbnail-inserter' ), array( 'status' => 400 ) );
                }
            }

            $upload_error = absint( $uploaded_file['error'] );
            if ( UPLOAD_ERR_OK !== $upload_error ) {
                return new WP_Error( 'browser_preview_failed', __( 'Preview image upload failed.', 'pdf-thumbnail-inserter' ), array( 'status' => 400 ) );
            }

            $uploaded_size = absint( $uploaded_file['size'] );
            if ( ! $uploaded_size || $uploaded_size > 8 * MB_IN_BYTES ) {
                return new WP_Error( 'browser_preview_failed', __( 'Preview image upload is too large or empty.', 'pdf-thumbnail-inserter' ), array( 'status' => 400 ) );
            }

            $uploaded_name = sanitize_file_name( (string) $uploaded_file['name'] );
            if ( ! $uploaded_name ) {
                $uploaded_name = sprintf( 'pdf-%d-browser-preview.jpg', $attachment_id );
            }
            $uploaded_file['name'] = $uploaded_name;

            $allowed_mimes = array(
                'jpg|jpeg|jpe' => 'image/jpeg',
                'png'          => 'image/png',
            );
            $filetype = wp_check_filetype_and_ext(
                $uploaded_file['tmp_name'],
                $uploaded_name,
                $allowed_mimes
            );

            if ( empty( $filetype['type'] ) || ! in_array( $filetype['type'], array( 'image/jpeg', 'image/png' ), true ) ) {
                return new WP_Error( 'browser_preview_failed', __( 'Preview upload must be a JPEG or PNG image.', 'pdf-thumbnail-inserter' ), array( 'status' => 400 ) );
            }

            // Compressed upload size alone does not bound image decoding memory.
            $dimensions = wp_getimagesize( $uploaded_file['tmp_name'] );
            if ( ! $dimensions || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 4096 || $dimensions[1] > 4096 ) {
                return new WP_Error( 'browser_preview_failed', __( 'Preview image dimensions must be between 1 and 4096 pixels.', 'pdf-thumbnail-inserter' ), array( 'status' => 400 ) );
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $upload = wp_handle_upload(
                $uploaded_file,
                array(
                    'test_form' => false,
                    'mimes'     => $allowed_mimes,
                )
            );

            if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) || empty( $upload['type'] ) ) {
                $message = ! empty( $upload['error'] ) ? $upload['error'] : __( 'WordPress could not store the preview image.', 'pdf-thumbnail-inserter' );
                return new WP_Error( 'browser_preview_failed', sanitize_text_field( $message ), array( 'status' => 500 ) );
            }

            return $this->preview_storage->create_pdf_preview_attachment( $attachment_id, $upload['file'], $upload['type'], $file_data['title'] );
        }

        public function add_browser_preview_to_media_response( $response, $attachment, $metadata ) {
            if ( 'application/pdf' !== $attachment->post_mime_type ) {
                return $response;
            }
            if ( ! empty( $response['sizes'] ) && is_array( $response['sizes'] ) ) {
                foreach ( $response['sizes'] as $size => $image ) {
                    if ( ! is_array( $image ) || empty( $image['url'] ) || ! $this->previews->is_available_preview_url( $image['url'] ) ) {
                        unset( $response['sizes'][ $size ] );
                    }
                }
            }
            if ( ! empty( $response['sizes']['full'] ) ) {
                return $response;
            }
            $preview_id = absint( get_post_meta( $attachment->ID, PDF_Thumbnail_Inserter::META_PREVIEW_ID, true ) );
            if ( ! $preview_id || 0 !== strpos( (string) get_post_mime_type( $preview_id ), 'image/' ) ) {
                return $response;
            }
            foreach ( array( 'thumbnail', 'medium', 'large', 'full' ) as $size ) {
                $image = wp_get_attachment_image_src( $preview_id, $size );
                if ( ! $image || ! $this->previews->is_available_preview_url( $image[0] ) ) {
                    continue;
                }
                $response['sizes'][ $size ] = array(
                    'url' => esc_url_raw( $image[0] ), 'width' => $image[1], 'height' => $image[2],
                    'orientation' => $image[2] > $image[1] ? 'portrait' : 'landscape',
                );
            }
            return $response;
        }

        public function add_attachment_fields( $form_fields, $post ) {
            if ( 'application/pdf' !== get_post_mime_type( $post ) ) {
                return $form_fields;
            }

            $preview_data = $this->previews->get_pdf_preview_data( $post->ID, 'medium' );
            $status = $preview_data['preview_source'];
            $status_label = array(
                'wordpress'   => __( 'WordPress preview', 'pdf-thumbnail-inserter' ),
                'plugin'      => __( 'Plugin-generated preview', 'pdf-thumbnail-inserter' ),
                'standalone'  => __( 'Plugin-generated preview', 'pdf-thumbnail-inserter' ),
                'placeholder' => __( 'Placeholder only', 'pdf-thumbnail-inserter' ),
            );

            $form_fields['pdf_thumbnail_shortcode'] = array(
                'label' => __( 'PDF thumbnail shortcode', 'pdf-thumbnail-inserter' ),
                'input' => 'html',
                'html'  => '<input type="text" class="widefat code" readonly value="' . esc_attr( sprintf( '[pdf_thumbnail_inserter id="%d"]', $post->ID ) ) . '">',
                'helps' => __( 'Copy and paste this shortcode into posts, pages, or widgets.', 'pdf-thumbnail-inserter' ),
            );

            $form_fields['pdf_thumbnail_preview_status'] = array(
                'label' => __( 'Preview status', 'pdf-thumbnail-inserter' ),
                'input' => 'html',
                'html'  => '<span>' . esc_html( isset( $status_label[ $status ] ) ? $status_label[ $status ] : $status ) . '</span>',
                'helps' => __( 'This shows whether the preview comes from WordPress, the plugin, or the placeholder image.', 'pdf-thumbnail-inserter' ),
            );

            return $form_fields;
        }

        public function maybe_generate_pdf_preview_on_upload( $metadata, $attachment_id, $context ) {
            if ( 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
                return $metadata;
            }

            if ( 'create' !== $context && 'update' !== $context ) {
                return $metadata;
            }

            if ( $this->admin->metadata_has_pdf_preview( $metadata ) ) {
                return $metadata;
            }

            $generation_status = $this->admin->get_pdf_preview_generation_status();
            if ( empty( $generation_status['available'] ) ) {
                return $metadata;
            }

            $this->preview_generation->ensure_pdf_preview( $attachment_id );

            return $metadata;
        }
}
