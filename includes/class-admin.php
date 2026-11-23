<?php
/** Admin subsystem. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_Admin {
    /** @var PDF_Thumbnail_Inserter */
    private $callbacks;

    /** @var PDF_Thumbnail_Inserter_Maintenance */
    private $maintenance;

    /** @var PDF_Thumbnail_Inserter_Settings */
    private $settings;

    /** @var PDF_Thumbnail_Inserter_Viewer */
    private $viewer;

    /** Wire the request-local collaborators before registering any hooks. */
    public function set_dependencies( PDF_Thumbnail_Inserter $callbacks, PDF_Thumbnail_Inserter_Maintenance $maintenance, PDF_Thumbnail_Inserter_Settings $settings, PDF_Thumbnail_Inserter_Viewer $viewer ) {
        $this->callbacks = $callbacks;
        $this->maintenance = $maintenance;
        $this->settings = $settings;
        $this->viewer = $viewer;
    }

        public function add_plugin_action_links( $links ) {
            $settings_link = sprintf(
                '<a href="%s">%s</a>',
                esc_url( admin_url( 'options-general.php?page=pdf-thumbnail-settings' ) ),
                esc_html__( 'Settings', 'pdf-thumbnail-inserter' )
            );

            array_unshift( $links, $settings_link );

            return $links;
        }

        public function add_settings_menu() {
            add_options_page(
                __( 'PDF Thumbnail Settings', 'pdf-thumbnail-inserter' ),
                __( 'PDF Thumbnail Settings', 'pdf-thumbnail-inserter' ),
                'manage_options',
                'pdf-thumbnail-settings',
                array( $this->callbacks, 'render_settings_page' )
            );
        }

        public function register_settings() {
            $this->settings->maybe_migrate_legacy_options();

            register_setting(
                'pdf_thumbnail_inserter_settings_group',
                PDF_Thumbnail_Inserter::OPTION_NAME,
                array(
                    'type'              => 'array',
                    'sanitize_callback' => array( $this->callbacks, 'sanitize_settings' ),
                    'default'           => $this->settings->get_defaults(),
                )
            );
        }

        public function metadata_has_pdf_preview( $metadata ) {
            if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
                return false;
            }

            foreach ( $metadata['sizes'] as $size_data ) {
                if ( empty( $size_data['file'] ) ) {
                    continue;
                }

                $mime = ! empty( $size_data['mime-type'] ) ? (string) $size_data['mime-type'] : '';
                $file = (string) $size_data['file'];

                if ( ( $mime && 0 === strpos( $mime, 'image/' ) ) || preg_match( '/\.(?:jpe?g|png|gif|webp)$/i', $file ) ) {
                    return true;
                }
            }

            return false;
        }

        public function get_pdf_preview_generation_status( $attachment_id = 0 ) {
            $status = array(
                'available' => false,
                'reason'    => '',
                'editor_ok' => false,
                'editor'    => '',
                'imagick'   => class_exists( 'Imagick' ),
            );

            if ( ! $status['imagick'] ) {
                $status['reason'] = __( 'PDF preview generation is unavailable on this server because the Imagick PHP extension is missing. If Imagick/ImageMagick/Ghostscript is installed later, refresh this page and preview generation will become available automatically.', 'pdf-thumbnail-inserter' );
                return $status;
            }

            if ( $attachment_id ) {
                $file_path = get_attached_file( absint( $attachment_id ), true );
                if ( ! $file_path || ! file_exists( $file_path ) ) {
                    $status['reason'] = __( 'The sample PDF file could not be found on disk.', 'pdf-thumbnail-inserter' );
                    return $status;
                }

                require_once ABSPATH . 'wp-admin/includes/image.php';
                $editor = wp_get_image_editor( $file_path );

                if ( is_wp_error( $editor ) ) {
                    $status['reason'] = sprintf(
                        /* translators: %s: WordPress image editor error message. */
                        __( 'The sample PDF could not be opened by the WordPress image editor: %s', 'pdf-thumbnail-inserter' ),
                        $editor->get_error_message()
                    );
                    return $status;
                }

                $status['available'] = true;
                $status['editor_ok'] = true;
                $status['editor']    = get_class( $editor );
                $status['reason']    = $status['editor'] ? $status['editor'] : __( 'WordPress can generate PDF previews on this server.', 'pdf-thumbnail-inserter' );
                return $status;
            }

            $status['available'] = true;
            $status['reason']    = __( 'Imagick is available to attempt preview generation. Individual PDFs may still fail; see the sample editor test and per-file results.', 'pdf-thumbnail-inserter' );
            return $status;
        }

        public function get_environment_status() {
            $pdf_ids = get_posts(
                array(
                    'post_type'      => 'attachment',
                    'post_mime_type' => 'application/pdf',
                    'post_status'    => 'inherit',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'orderby'        => 'ID',
                    'order'          => 'ASC',
                )
            );

            $status = array(
                'imagick'             => class_exists( 'Imagick' ),
                'editor_ok'           => false,
                'editor_message'      => __( 'No PDF attachment was found to test WordPress preview generation.', 'pdf-thumbnail-inserter' ),
                'generation_available'=> false,
                'generation_message'  => '',
            );

            $sample_id = ! empty( $pdf_ids ) ? (int) $pdf_ids[0] : 0;
            $generation_status = $this->get_pdf_preview_generation_status( $sample_id );
            $capability_status = $this->get_pdf_preview_generation_status();

            $status['generation_available'] = ! empty( $capability_status['available'] );
            $status['generation_message']   = ! empty( $capability_status['reason'] ) ? (string) $capability_status['reason'] : '';

            if ( ! empty( $pdf_ids ) ) {
                if ( ! empty( $generation_status['editor_ok'] ) ) {
                    $status['editor_ok'] = true;
                    $status['editor_message'] = ! empty( $generation_status['editor'] ) ? (string) $generation_status['editor'] : __( 'WordPress image editor loaded successfully.', 'pdf-thumbnail-inserter' );
                } elseif ( ! empty( $generation_status['reason'] ) ) {
                    $status['editor_message'] = (string) $generation_status['reason'];
                }
            } elseif ( ! empty( $generation_status['reason'] ) ) {
                $status['editor_message'] = (string) $generation_status['reason'];
            }

            return $status;
        }

        public function render_settings_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $settings = $this->settings->get_settings();
            $notice = '';
            $environment = $this->get_environment_status();
            $server_job = $this->maintenance->get_server_maintenance_job();
            $server_pending = $server_job && ! $server_job['complete'];
            $report = get_transient( PDF_Thumbnail_Inserter::TRANSIENT_REGEN_REPORT );
            if ( false !== $report ) {
                delete_transient( PDF_Thumbnail_Inserter::TRANSIENT_REGEN_REPORT );
            }
            if ( $server_job ) {
                $report = $server_job['report'];
            }

            if ( is_array( $report ) ) {
                $notice = sprintf(
                    /* translators: 1: WordPress-generated count, 2: plugin-generated count, 3: failed count, 4: already-existing count. */
                    __( 'WordPress previews: %1$d · Plugin previews: %2$d · Failed: %3$d · Already present: %4$d', 'pdf-thumbnail-inserter' ),
                    isset( $report['wordpress_generated'] ) ? absint( $report['wordpress_generated'] ) : 0,
                    isset( $report['plugin_generated'] ) ? absint( $report['plugin_generated'] ) : 0,
                    isset( $report['failed'] ) ? absint( $report['failed'] ) : 0,
                    isset( $report['already_present'] ) ? absint( $report['already_present'] ) : 0
                );
            }
            ?>
            <div class="wrap pdf-thumbnail-settings-wrap">
                <h1><?php echo esc_html__( 'PDF Thumbnail Inserter', 'pdf-thumbnail-inserter' ); ?></h1>
                <p><?php echo esc_html__( 'Set the default presentation for the shortcode and Gutenberg block, and regenerate missing previews when needed.', 'pdf-thumbnail-inserter' ); ?></p>

                <?php if ( $notice ) : ?>
                    <div class="notice <?php echo ! empty( $report['failed'] ) ? 'notice-warning' : ( $server_pending ? 'notice-info' : 'notice-success' ); ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
                <?php endif; ?>

                <form method="post" action="options.php">
                    <?php settings_fields( 'pdf_thumbnail_inserter_settings_group' ); ?>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="pti-button-text"><?php echo esc_html__( 'Default button text', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td><input id="pti-button-text" class="regular-text" type="text" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[button_text]" value="<?php echo esc_attr( $settings['button_text'] ); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-thumbnail-width"><?php echo esc_html__( 'Thumbnail width', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td>
                                    <input id="pti-thumbnail-width" class="regular-text" type="text" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[thumbnail_width]" value="<?php echo esc_attr( $settings['thumbnail_width'] ); ?>">
                                    <p class="description"><?php echo esc_html__( 'Examples: 212px, 16rem, 100%', 'pdf-thumbnail-inserter' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-title-color"><?php echo esc_html__( 'Title color', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td><input id="pti-title-color" class="pti-color-field" type="text" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[title_color]" value="<?php echo esc_attr( $settings['title_color'] ); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-title-size"><?php echo esc_html__( 'Title size', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td><input id="pti-title-size" class="regular-text" type="text" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[title_size]" value="<?php echo esc_attr( $settings['title_size'] ); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-button-color"><?php echo esc_html__( 'Button color', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td><input id="pti-button-color" class="pti-color-field" type="text" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[button_color]" value="<?php echo esc_attr( $settings['button_color'] ); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-button-hover-color"><?php echo esc_html__( 'Button hover color', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td><input id="pti-button-hover-color" class="pti-color-field" type="text" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[button_hover_color]" value="<?php echo esc_attr( $settings['button_hover_color'] ); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-button-text-color"><?php echo esc_html__( 'Button text color', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td><input id="pti-button-text-color" class="pti-color-field" type="text" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[button_text_color]" value="<?php echo esc_attr( $settings['button_text_color'] ); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-button-text-size"><?php echo esc_html__( 'Button text size', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td><input id="pti-button-text-size" class="regular-text" type="text" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[button_text_size]" value="<?php echo esc_attr( $settings['button_text_size'] ); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-default-link-to"><?php echo esc_html__( 'Default link target', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td>
                                    <select id="pti-default-link-to" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[default_link_to]">
                                        <option value="file" <?php selected( $settings['default_link_to'], 'file' ); ?>><?php echo esc_html__( 'PDF file', 'pdf-thumbnail-inserter' ); ?></option>
                                        <option value="attachment" <?php selected( $settings['default_link_to'], 'attachment' ); ?>><?php echo esc_html__( 'Attachment page', 'pdf-thumbnail-inserter' ); ?></option>
                                        <option value="custom" <?php selected( $settings['default_link_to'], 'custom' ); ?>><?php echo esc_html__( 'Custom URL', 'pdf-thumbnail-inserter' ); ?></option>
                                        <option value="none" <?php selected( $settings['default_link_to'], 'none' ); ?>><?php echo esc_html__( 'No link', 'pdf-thumbnail-inserter' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Default visibility', 'pdf-thumbnail-inserter' ); ?></th>
                                <td>
                                    <label><input type="checkbox" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[default_show_title]" value="1" <?php checked( ! empty( $settings['default_show_title'] ) ); ?>> <?php echo esc_html__( 'Show title', 'pdf-thumbnail-inserter' ); ?></label><br>
                                    <label><input type="checkbox" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[default_show_button]" value="1" <?php checked( ! empty( $settings['default_show_button'] ) ); ?>> <?php echo esc_html__( 'Show button', 'pdf-thumbnail-inserter' ); ?></label><br>
                                    <label><input type="checkbox" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[default_new_tab]" value="1" <?php checked( ! empty( $settings['default_new_tab'] ) ); ?>> <?php echo esc_html__( 'Open links in a new tab by default', 'pdf-thumbnail-inserter' ); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Χρήση PDF.js για προεπισκόπηση PDF', 'pdf-thumbnail-inserter' ); ?></th>
                                <td>
                                    <label><input type="checkbox" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[use_iframe_pdf_previews]" value="1" <?php checked( ! empty( $settings['use_iframe_pdf_previews'] ) ); ?>> <?php echo esc_html__( 'Αντικαθιστά τις προεπισκοπήσεις PDF των μπλοκ Αρχείου WordPress με τοπικό PDF.js viewer μέσα σε iframe.', 'pdf-thumbnail-inserter' ); ?></label>
                                    <p class="description"><?php echo esc_html__( 'Επηρεάζονται μόνο PDF blocks στα οποία είναι ήδη ενεργοποιημένη η προεπισκόπηση.', 'pdf-thumbnail-inserter' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-max-pdf-preview-height"><?php echo esc_html__( 'Μέγιστο ύψος προεπισκόπησης PDF', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td>
                                    <input id="pti-max-pdf-preview-height" class="small-text" type="number" min="1" step="1" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[max_pdf_preview_height]" value="<?php echo esc_attr( $settings['max_pdf_preview_height'] ); ?>"> px
                                    <p class="description"><?php echo esc_html__( 'Αν το ύψος του block είναι μικρότερο, διατηρείται το μικρότερο ύψος. Προεπιλογή: 600px.', 'pdf-thumbnail-inserter' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Convert Gutenberg PDF links to cards', 'pdf-thumbnail-inserter' ); ?></th>
                                <td>
                                    <label><input type="checkbox" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[convert_gutenberg_pdf_links_to_cards]" value="1" <?php checked( ! empty( $settings['convert_gutenberg_pdf_links_to_cards'] ) ); ?>> <?php echo esc_html__( 'Convert WordPress File blocks that link to PDFs into PDF Thumbnail Inserter cards.', 'pdf-thumbnail-inserter' ); ?></label>
                                    <p class="description"><?php echo esc_html__( 'Applies to all Gutenberg File blocks that point to PDFs, including blocks that currently show the default WordPress preview.', 'pdf-thumbnail-inserter' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Convert Gutenberg DOC/DOCX links to cards', 'pdf-thumbnail-inserter' ); ?></th>
                                <td>
                                    <label><input type="checkbox" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[convert_gutenberg_doc_links_to_cards]" value="1" <?php checked( ! empty( $settings['convert_gutenberg_doc_links_to_cards'] ) ); ?>> <?php echo esc_html__( 'Convert WordPress File blocks that link to DOC or DOCX files into document cards.', 'pdf-thumbnail-inserter' ); ?></label>
                                    <p class="description"><?php echo esc_html__( 'Word document cards use a separate placeholder image. Saved post content is not modified.', 'pdf-thumbnail-inserter' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Featured image / PDF preview in listings', 'pdf-thumbnail-inserter' ); ?></th>
                                <td>
                                    <label><input type="checkbox" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[use_first_pdf_preview_as_featured_image]" value="1" <?php checked( ! empty( $settings['use_first_pdf_preview_as_featured_image'] ) ); ?>> <?php echo esc_html__( 'Use the first PDF when a listing post has no featured image, and enable the Elementor widget “Featured Image or First PDF Preview”.', 'pdf-thumbnail-inserter' ); ?></label>
                                    <p class="description"><?php echo esc_html__( 'Adds the Elementor widget “Featured Image or First PDF Preview”. A real featured image always takes priority, and no featured image is written to the database.', 'pdf-thumbnail-inserter' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <h2><?php echo esc_html__( 'Automatic browser previews on upload', 'pdf-thumbnail-inserter' ); ?></h2>
                    <p><label><input type="checkbox" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[auto_browser_preview_on_upload]" value="1" <?php checked( ! empty( $settings['auto_browser_preview_on_upload'] ) ); ?>> <?php echo esc_html__( 'Automatically create missing PDF previews in my browser after upload', 'pdf-thumbnail-inserter' ); ?></label></p>
                    <p class="description"><?php echo esc_html__( 'WordPress and server-generated previews are kept first. When a newly uploaded PDF has no preview, this browser renders its first page with local PDF.js and saves a reusable image. Keep the upload tab open until generation finishes. Applies to Media Library uploads and editor media dialogs for users who can upload and edit the PDF.', 'pdf-thumbnail-inserter' ); ?></p>
                    <h2><?php echo esc_html__( 'Defaults for new inserts', 'pdf-thumbnail-inserter' ); ?></h2>
                    <p><?php echo esc_html__( 'New Classic Editor inserts and new Gutenberg blocks use these columns automatically. Previously saved shortcodes and blocks keep their column settings.', 'pdf-thumbnail-inserter' ); ?></p>
                    <table class="form-table" role="presentation"><tbody>
                        <?php foreach ( array( 'widescreen' => __( 'Widescreen columns (1600px and up)', 'pdf-thumbnail-inserter' ), 'desktop' => __( 'Desktop columns (1181–1599px)', 'pdf-thumbnail-inserter' ), 'tablet' => __( 'Tablet columns (768–1180px)', 'pdf-thumbnail-inserter' ), 'mobile' => __( 'Mobile columns (up to 767px)', 'pdf-thumbnail-inserter' ) ) as $device => $column_label ) : ?>
                            <tr>
                                <th scope="row"><label for="pti-columns-<?php echo esc_attr( $device ); ?>"><?php echo esc_html( $column_label ); ?></label></th>
                                <td><input id="pti-columns-<?php echo esc_attr( $device ); ?>" class="small-text" type="number" min="1" max="6" step="1" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[default_columns_<?php echo esc_attr( $device ); ?>]" value="<?php echo esc_attr( $settings[ 'default_columns_' . $device ] ); ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <th scope="row"><label for="pti-listing-pages"><?php echo esc_html__( 'Additional listing pages', 'pdf-thumbnail-inserter' ); ?></label></th>
                            <td><input id="pti-listing-pages" class="regular-text" type="text" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[listing_pages]" value="<?php echo esc_attr( $settings['listing_pages'] ); ?>"><p class="description"><?php echo esc_html__( 'Comma-separated page IDs or slugs, for example: news, announcements. Category archives and the WordPress posts page are included automatically. Leave empty if none.', 'pdf-thumbnail-inserter' ); ?></p></td>
                        </tr>
                    </tbody></table>
                    <h2><?php echo esc_html__( 'Fallback cover for PDFs without previews', 'pdf-thumbnail-inserter' ); ?></h2>
                    <p><?php echo esc_html__( 'When a PDF has no preview image, show a document cover with the PDF title using the existing button and title colors. This applies to PDF cards and listing previews. Posts without a PDF are unchanged. A real preview always takes priority.', 'pdf-thumbnail-inserter' ); ?></p>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Enable default cover', 'pdf-thumbnail-inserter' ); ?></th>
                                <td><label><input type="checkbox" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[use_default_document_cover]" value="1" <?php checked( ! empty( $settings['use_default_document_cover'] ) ); ?>> <?php echo esc_html__( 'Show a cover with the PDF title when the PDF has no preview', 'pdf-thumbnail-inserter' ); ?></label></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pti-document-cover-label"><?php echo esc_html__( 'Document label', 'pdf-thumbnail-inserter' ); ?></label></th>
                                <td><input id="pti-document-cover-label" class="regular-text" type="text" name="<?php echo esc_attr( PDF_Thumbnail_Inserter::OPTION_NAME ); ?>[document_cover_label]" value="<?php echo esc_attr( $settings['document_cover_label'] ); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Saved cover preview', 'pdf-thumbnail-inserter' ); ?></th>
                                <td><div style="max-width:480px"><?php echo $this->viewer->render_document_cover_svg( __( 'PDF title', 'pdf-thumbnail-inserter' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div></td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button(); ?>
                </form>

                <hr>

                <h2><?php echo esc_html__( 'Maintenance', 'pdf-thumbnail-inserter' ); ?></h2>
                <p><?php echo esc_html__( 'Generate previews for existing PDF attachments that do not already have one.', 'pdf-thumbnail-inserter' ); ?></p>
                <p>
                    <strong><?php echo esc_html__( 'Imagick:', 'pdf-thumbnail-inserter' ); ?></strong>
                    <?php echo $environment['imagick'] ? esc_html__( 'Available', 'pdf-thumbnail-inserter' ) : esc_html__( 'Missing', 'pdf-thumbnail-inserter' ); ?>
                    <br>
                    <strong><?php echo esc_html__( 'WordPress PDF editor test:', 'pdf-thumbnail-inserter' ); ?></strong>
                    <?php echo $environment['editor_ok'] ? esc_html__( 'Available', 'pdf-thumbnail-inserter' ) : esc_html__( 'Unavailable', 'pdf-thumbnail-inserter' ); ?>
                    — <?php echo esc_html( $environment['editor_message'] ); ?>
                    <br>
                    <strong><?php echo esc_html__( 'Server preview generation status:', 'pdf-thumbnail-inserter' ); ?></strong>
                    <?php echo ! empty( $environment['generation_available'] ) ? esc_html__( 'Available', 'pdf-thumbnail-inserter' ) : esc_html__( 'Unavailable', 'pdf-thumbnail-inserter' ); ?>
                    — <?php echo esc_html( $environment['generation_message'] ); ?>
                </p>
                <?php if ( ! empty( $report['errors'] ) && is_array( $report['errors'] ) ) : ?>
                    <div class="notice notice-warning inline"><p><?php echo esc_html__( 'Recent preview generation errors:', 'pdf-thumbnail-inserter' ); ?><br><?php echo esc_html( implode( ' | ', array_slice( $report['errors'], 0, 5 ) ) ); ?></p></div>
                <?php endif; ?>
                <?php if ( empty( $environment['generation_available'] ) ) : ?>
                    <div class="notice notice-info inline"><p><?php echo esc_html( $environment['generation_message'] ); ?></p></div>
                <?php endif; ?>
                <form id="pti-server-maintenance-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( PDF_Thumbnail_Inserter::ADMIN_ACTION_REGENERATE_MISSING ); ?>">
                    <input type="hidden" name="job_id" value="<?php echo $server_pending ? esc_attr( $server_job['id'] ) : ''; ?>">
                    <?php wp_nonce_field( PDF_Thumbnail_Inserter::ADMIN_ACTION_REGENERATE_MISSING ); ?>
                    <?php submit_button( $server_pending ? __( 'Continue Generating Previews on Server', 'pdf-thumbnail-inserter' ) : __( 'Generate Missing Previews on Server', 'pdf-thumbnail-inserter' ), 'secondary', 'submit', false, array_merge( array( 'id' => 'pti-server-generate-previews' ), empty( $environment['generation_available'] ) ? array( 'disabled' => 'disabled' ) : array() ) ); ?>
                    <p class="description"><?php echo esc_html__( 'Progress is saved in small batches. Keep this page open to finish automatically; if interrupted, return here to continue. Without JavaScript, use Continue after each batch.', 'pdf-thumbnail-inserter' ); ?></p>
                    <progress id="pti-server-preview-progress" class="pti-browser-preview-progress" hidden></progress>
                    <span id="pti-server-preview-status" class="pti-browser-preview-status" aria-live="polite"></span>
                    <ul id="pti-server-preview-log" class="pti-browser-preview-log" aria-live="polite"></ul>
                    <?php if ( empty( $environment['generation_available'] ) ) : ?>
                        <p class="description"><?php echo esc_html__( 'This server button will automatically become available after the server gains PDF preview support and you refresh the page.', 'pdf-thumbnail-inserter' ); ?></p>
                    <?php endif; ?>
                </form>

                <div class="pti-browser-maintenance" data-pti-browser-maintenance>
                    <h3><?php echo esc_html__( 'Browser PDF.js preview generation', 'pdf-thumbnail-inserter' ); ?></h3>
                    <p><?php echo esc_html__( 'Generate missing previews in this browser with the bundled PDF.js runtime. This does not require server-side PDF rendering support.', 'pdf-thumbnail-inserter' ); ?></p>
                    <p>
                        <strong><?php echo esc_html__( 'Browser PDF.js fallback:', 'pdf-thumbnail-inserter' ); ?></strong>
                        <?php echo esc_html__( 'Available', 'pdf-thumbnail-inserter' ); ?>
                    </p>
                    <button type="button" class="button button-primary" id="pti-browser-generate-previews"><?php echo esc_html__( 'Generate Missing Previews in Browser', 'pdf-thumbnail-inserter' ); ?></button>
                    <progress id="pti-browser-preview-progress" class="pti-browser-preview-progress" value="0" max="1" hidden></progress>
                    <span id="pti-browser-preview-status" class="pti-browser-preview-status" aria-live="polite"></span>
                    <ul id="pti-browser-preview-log" class="pti-browser-preview-log" aria-live="polite"></ul>
                </div>
            </div>
            <?php
        }

        public function render_media_button() {
            if ( ! current_user_can( 'upload_files' ) ) {
                return;
            }

            echo '<button type="button" class="button" id="insert-pdf-thumbnail">' . esc_html__( 'Insert PDF Thumbnail', 'pdf-thumbnail-inserter' ) . '</button>';
        }
}
