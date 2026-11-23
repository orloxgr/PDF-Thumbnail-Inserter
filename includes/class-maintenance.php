<?php
/** Maintenance subsystem. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_Maintenance {
    /** @var PDF_Thumbnail_Inserter_Admin */
    private $admin;

    /** @var PDF_Thumbnail_Inserter_PreviewGeneration */
    private $preview_generation;

    /** @var PDF_Thumbnail_Inserter_PreviewStorage */
    private $preview_storage;

    /** @var PDF_Thumbnail_Inserter_Previews */
    private $previews;

    /** Wire the request-local collaborators before registering any hooks. */
    public function set_dependencies( PDF_Thumbnail_Inserter_Admin $admin, PDF_Thumbnail_Inserter_PreviewGeneration $preview_generation, PDF_Thumbnail_Inserter_PreviewStorage $preview_storage, PDF_Thumbnail_Inserter_Previews $previews ) {
        $this->admin = $admin;
        $this->preview_generation = $preview_generation;
        $this->preview_storage = $preview_storage;
        $this->previews = $previews;
    }

        public function handle_regenerate_missing_previews() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Insufficient permissions.', 'pdf-thumbnail-inserter' ), '', array( 'response' => 403 ) );
            }

            check_admin_referer( PDF_Thumbnail_Inserter::ADMIN_ACTION_REGENERATE_MISSING );

            $ajax = isset( $_POST['pti_ajax'] ) && is_scalar( $_POST['pti_ajax'] ) && '1' === sanitize_key( wp_unslash( $_POST['pti_ajax'] ) );
            if ( isset( $_POST['job_id'] ) && ! is_scalar( $_POST['job_id'] ) ) {
                $result = new WP_Error( 'invalid_job', __( 'Invalid maintenance job.', 'pdf-thumbnail-inserter' ), array( 'status' => 400 ) );
            } else {
                $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
                $result = $this->run_server_maintenance_batch( $job_id );
            }
            if ( is_wp_error( $result ) ) {
                $data = $result->get_error_data();
                $status = 'preview_busy' === $result->get_error_code() ? 409 : ( isset( $data['status'] ) ? $data['status'] : 500 );
                if ( $ajax ) {
                    wp_send_json_error( array( 'message' => $result->get_error_message(), 'restart' => 'expired_job' === $result->get_error_code() ), $status );
                }
                wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => absint( $status ), 'back_link' => true ) );
            }
            if ( $ajax ) {
                wp_send_json_success( $result );
            }
            wp_safe_redirect( add_query_arg( 'page', 'pdf-thumbnail-settings', admin_url( 'options-general.php' ) ) );
            exit;
        }

        public function get_server_maintenance_job_key( $user_id = 0 ) {
            return PDF_Thumbnail_Inserter::TRANSIENT_SERVER_JOB . '_' . absint( $user_id ? $user_id : get_current_user_id() );
        }

        public function get_server_maintenance_job( $user_id = 0 ) {
            $job = get_transient( $this->get_server_maintenance_job_key( $user_id ) );
            if ( ! is_array( $job ) || empty( $job['id'] ) || ! is_string( $job['id'] )
                || ! isset( $job['complete'], $job['report'] ) || ! is_bool( $job['complete'] ) || ! is_array( $job['report'] ) ) {
                return false;
            }
            foreach ( array( 'cursor', 'upper', 'busy_id', 'busy_attempts' ) as $key ) {
                if ( ! isset( $job[ $key ] ) || ! is_int( $job[ $key ] ) || $job[ $key ] < 0 ) {
                    return false;
                }
            }
            foreach ( array( 'wordpress_generated', 'plugin_generated', 'already_present', 'failed' ) as $key ) {
                if ( ! isset( $job['report'][ $key ] ) || ! is_int( $job['report'][ $key ] ) || $job['report'][ $key ] < 0 ) {
                    return false;
                }
            }
            return $job['cursor'] <= $job['upper'] && isset( $job['report']['errors'] ) && is_array( $job['report']['errors'] ) ? $job : false;
        }

        /** Seek by ID instead of OFFSET, so deletions cannot skip remaining PDFs. */
        public function get_server_maintenance_ids( $cursor, $upper, $limit, $order = 'ASC' ) {
            global $wpdb;
            $query = new WP_Query();
            $where_filter = function ( $where, $current_query ) use ( $query, $cursor, $upper, $wpdb ) {
                if ( $current_query !== $query ) {
                    return $where;
                }
                // The core posts table identifier is trusted; %i is unavailable on WP 6.0.
                return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d AND {$wpdb->posts}.ID <= %d", $cursor, $upper );
            };
            add_filter( 'posts_where', $where_filter, 10, 2 );
            try {
                return $query->query( array(
                    'post_type' => 'attachment', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit',
                    'fields' => 'ids', 'posts_per_page' => $limit, 'orderby' => 'ID', 'order' => $order,
                    'no_found_rows' => true, 'pti_preview_maintenance' => true,
                ) );
            } finally {
                remove_filter( 'posts_where', $where_filter, 10 );
            }
        }

        public function store_server_maintenance_job( $user_id, $job ) {
            $key = $this->get_server_maintenance_job_key( $user_id );
            set_transient( $key, $job, HOUR_IN_SECONDS );
            return get_transient( $key ) === $job;
        }

        public function run_server_maintenance_batch( $job_id ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                return new WP_Error( 'maintenance_forbidden', __( 'Insufficient permissions.', 'pdf-thumbnail-inserter' ), array( 'status' => 403 ) );
            }
            $capability = $this->admin->get_pdf_preview_generation_status();
            if ( ! $capability['available'] ) {
                return new WP_Error( 'maintenance_unavailable', $capability['reason'], array( 'status' => 503 ) );
            }
            $uploads = wp_upload_dir();
            if ( ! empty( $uploads['error'] ) ) {
                return new WP_Error( 'maintenance_uploads', __( 'The uploads directory is unavailable.', 'pdf-thumbnail-inserter' ) );
            }
            $user_id = get_current_user_id();
            return $this->preview_storage->with_preview_file_lock(
                trailingslashit( $uploads['basedir'] ) . '.pti-server-maintenance-' . $user_id . '.lock',
                function () use ( $job_id, $user_id ) { return $this->process_server_maintenance_batch( $job_id, $user_id ); }
            );
        }

        public function process_server_maintenance_batch( $job_id, $user_id ) {
            $job = $this->get_server_maintenance_job( $user_id );
            if ( '' === $job_id ) {
                $last = $this->get_server_maintenance_ids( 0, PHP_INT_MAX, 1, 'DESC' );
                $job = array(
                    'id' => wp_generate_uuid4(), 'cursor' => 0, 'upper' => $last ? absint( $last[0] ) : 0,
                    'complete' => false, 'busy_id' => 0, 'busy_attempts' => 0,
                    'report' => array( 'wordpress_generated' => 0, 'plugin_generated' => 0, 'already_present' => 0, 'failed' => 0, 'errors' => array() ),
                );
            } elseif ( ! is_array( $job ) || empty( $job['id'] ) || ! hash_equals( $job['id'], $job_id ) ) {
                return new WP_Error( 'expired_job', __( 'This maintenance job expired or was replaced. Start a new job.', 'pdf-thumbnail-inserter' ), array( 'status' => 409 ) );
            }
            $processed = 0;
            $attempts = 0;
            $retry_after = 0;
            if ( ! $job['complete'] ) {
                $ids = $this->get_server_maintenance_ids( $job['cursor'], $job['upper'], PDF_Thumbnail_Inserter::SERVER_SCAN_LIMIT + 1 );
                $more = count( $ids ) > PDF_Thumbnail_Inserter::SERVER_SCAN_LIMIT;
                $ids = array_slice( $ids, 0, PDF_Thumbnail_Inserter::SERVER_SCAN_LIMIT );
                if ( $ids ) {
                    _prime_post_caches( $ids, false, true );
                }
                $started = microtime( true );
                foreach ( $ids as $pdf_id ) {
                    $error = '';
                    try {
                        $before = $this->previews->get_pdf_preview_data( $pdf_id, 'full', false );
                        if ( 'placeholder' !== $before['preview_source'] ) {
                            $job['report']['already_present']++;
                        } else {
                            $attempts++;
                            $result = $this->preview_generation->ensure_pdf_preview( $pdf_id, true );
                            if ( is_wp_error( $result ) && 'preview_busy' === $result->get_error_code() ) {
                                $job['busy_attempts'] = $job['busy_id'] === $pdf_id ? $job['busy_attempts'] + 1 : 1;
                                $job['busy_id'] = $pdf_id;
                                if ( $job['busy_attempts'] < 3 ) {
                                    $retry_after = 1;
                                    break;
                                }
                            }
                            $after = $this->previews->get_pdf_preview_data( $pdf_id, 'full', false );
                            if ( is_wp_error( $result ) && 'preview_busy' === $result->get_error_code() && 'placeholder' !== $after['preview_source'] ) {
                                $job['report']['already_present']++;
                            } elseif ( is_wp_error( $result ) ) {
                                $error = $result->get_error_message();
                            } elseif ( 'placeholder' !== $after['preview_source'] && empty( $result['generated'] ) ) {
                                $job['report']['already_present']++;
                            } elseif ( 'wordpress' === $after['preview_source'] ) {
                                $job['report']['wordpress_generated']++;
                            } elseif ( in_array( $after['preview_source'], array( 'plugin', 'standalone' ), true ) ) {
                                $job['report']['plugin_generated']++;
                            } else {
                                $error = __( 'No usable preview was generated.', 'pdf-thumbnail-inserter' );
                            }
                        }
                    } catch ( \Throwable $exception ) {
                        $error = $exception->getMessage();
                    }
                    if ( $error ) {
                        $job['report']['failed']++;
                        if ( count( $job['report']['errors'] ) < 20 ) {
                            $job['report']['errors'][] = '#' . absint( $pdf_id ) . ': ' . wp_html_excerpt( sanitize_text_field( $error ), 200, '…' );
                        }
                    }
                    $job['busy_id'] = 0;
                    $job['busy_attempts'] = 0;
                    $job['cursor'] = absint( $pdf_id );
                    $processed++;
                    // Persist after each PDF: an interrupted request resumes without starting over.
                    if ( ! $this->store_server_maintenance_job( $user_id, $job ) ) {
                        return new WP_Error( 'maintenance_store_failed', __( 'Could not save maintenance progress. Please try again.', 'pdf-thumbnail-inserter' ) );
                    }
                    // A soft time budget is checked between PDFs; a single decoder uses the server's own limits.
                    if ( $attempts >= PDF_Thumbnail_Inserter::SERVER_RENDER_LIMIT || microtime( true ) - $started >= 5 ) {
                        break;
                    }
                }
                $job['complete'] = ! $retry_after && ! $more && ( ! $ids || $job['cursor'] >= end( $ids ) );
                if ( ! $this->store_server_maintenance_job( $user_id, $job ) ) {
                    return new WP_Error( 'maintenance_store_failed', __( 'Could not save maintenance progress. Please try again.', 'pdf-thumbnail-inserter' ) );
                }
            }
            return array( 'job_id' => $job['id'], 'complete' => $job['complete'], 'report' => $job['report'], 'batch_processed' => $processed, 'batch_attempts' => $attempts, 'retry_after' => $retry_after );
        }
}
