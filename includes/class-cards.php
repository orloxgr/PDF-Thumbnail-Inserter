<?php
/** Cards subsystem. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_Cards {
    /** @var PDF_Thumbnail_Inserter_Discovery */
    private $discovery;

    /** @var PDF_Thumbnail_Inserter_FileResolver */
    private $file_resolver;

    /** @var PDF_Thumbnail_Inserter_ListingFilters */
    private $listing_filters;

    /** @var PDF_Thumbnail_Inserter_Previews */
    private $previews;

    /** @var PDF_Thumbnail_Inserter_ReferenceLookup */
    private $reference_lookup;

    /** @var PDF_Thumbnail_Inserter_Settings */
    private $settings;

    /** @var PDF_Thumbnail_Inserter_Viewer */
    private $viewer;

    /** Wire the request-local collaborators before registering any hooks. */
    public function set_dependencies( PDF_Thumbnail_Inserter_Discovery $discovery, PDF_Thumbnail_Inserter_FileResolver $file_resolver, PDF_Thumbnail_Inserter_ListingFilters $listing_filters, PDF_Thumbnail_Inserter_Previews $previews, PDF_Thumbnail_Inserter_ReferenceLookup $reference_lookup, PDF_Thumbnail_Inserter_Settings $settings, PDF_Thumbnail_Inserter_Viewer $viewer ) {
        $this->discovery = $discovery;
        $this->file_resolver = $file_resolver;
        $this->listing_filters = $listing_filters;
        $this->previews = $previews;
        $this->reference_lookup = $reference_lookup;
        $this->settings = $settings;
        $this->viewer = $viewer;
    }

        public function normalize_bool_attr( $value, $default = false ) {
            if ( ! is_scalar( $value ) && null !== $value ) {
                return (bool) $default;
            }
            if ( is_bool( $value ) ) {
                return $value;
            }

            if ( null === $value || '' === $value ) {
                return (bool) $default;
            }

            return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
        }

        public function build_link_data( $atts, $preview_data ) {
            $link_to = $this->settings->sanitize_link_to( $atts['link_to'] );
            $target_url = '';

            switch ( $link_to ) {
                case 'attachment':
                    $target_url = $preview_data['attachment_url'];
                    break;
                case 'custom':
                    $target_url = esc_url_raw( $atts['url'] );
                    break;
                case 'none':
                    $target_url = '';
                    break;
                case 'file':
                default:
                    $target_url = $preview_data['file_url'];
                    break;
            }

            return array(
                'link_to'    => $link_to,
                'target_url' => $target_url,
            );
        }

        public function parse_attachment_ids( $atts ) {
            $ids = array();

            if ( isset( $atts['ids'] ) && ! empty( $atts['ids'] ) ) {
                $raw_ids = is_array( $atts['ids'] ) ? $atts['ids'] : preg_split( '/[\s,]+/', (string) $atts['ids'] );

                foreach ( (array) $raw_ids as $raw_id ) {
                    $id = absint( $raw_id );
                    $file_data = $id ? $this->discovery->get_pdf_file_data_from_attachment_id( $id ) : array();
                    if ( ! empty( $file_data['is_pdf'] ) && ! empty( $file_data['attachment_id'] ) ) {
                        $ids[] = absint( $file_data['attachment_id'] );
                    }
                }
            }

            if ( empty( $ids ) && ! empty( $atts['id'] ) ) {
                $id = absint( $atts['id'] );
                $file_data = $id ? $this->discovery->get_pdf_file_data_from_attachment_id( $id ) : array();
                if ( ! empty( $file_data['is_pdf'] ) && ! empty( $file_data['attachment_id'] ) ) {
                    $ids[] = absint( $file_data['attachment_id'] );
                }
            }

            return array_values( array_unique( array_filter( $ids ) ) );
        }

        public function clamp_columns( $value, $default = 1 ) {
            $value = is_scalar( $value ) && preg_match( '/^\d+$/', (string) $value ) ? (int) $value : 0;

            if ( $value < 1 || $value > 6 ) {
                $value = absint( $default );
            }

            if ( $value < 1 || $value > 6 ) {
                $value = 1;
            }

            return $value;
        }

        public function get_grid_style( $atts ) {
            $styles = array(
                '--pti-thumbnail-width:' . $this->settings->sanitize_css_dimension( $atts['thumbnail_width'], $this->settings->get_defaults()['thumbnail_width'] ),
                '--pti-title-color:' . $this->settings->sanitize_hex_color_with_fallback( $atts['title_color'], $this->settings->get_defaults()['title_color'] ),
                '--pti-title-size:' . $this->settings->sanitize_css_dimension( $atts['title_size'], $this->settings->get_defaults()['title_size'] ),
                '--pti-button-bg:' . $this->settings->sanitize_hex_color_with_fallback( $atts['button_color'], $this->settings->get_defaults()['button_color'] ),
                '--pti-button-bg-hover:' . $this->settings->sanitize_hex_color_with_fallback( $atts['button_hover_color'], $this->settings->get_defaults()['button_hover_color'] ),
                '--pti-button-color:' . $this->settings->sanitize_hex_color_with_fallback( $atts['button_text_color'], $this->settings->get_defaults()['button_text_color'] ),
                '--pti-button-size:' . $this->settings->sanitize_css_dimension( $atts['button_text_size'], $this->settings->get_defaults()['button_text_size'] ),
                '--pti-columns-desktop:' . $this->clamp_columns( isset( $atts['columns_desktop'] ) ? $atts['columns_desktop'] : 3, 3 ),
                '--pti-columns-widescreen:' . $this->clamp_columns( ! empty( $atts['columns_widescreen'] ) ? $atts['columns_widescreen'] : ( $atts['columns_desktop'] ?? 3 ), 3 ),
                '--pti-columns-laptop:' . $this->clamp_columns( isset( $atts['columns_laptop'] ) ? $atts['columns_laptop'] : 2, 2 ),
                '--pti-columns-mobile:' . $this->clamp_columns( isset( $atts['columns_mobile'] ) ? $atts['columns_mobile'] : 1, 1 ),
            );

            return implode( ';', $styles );
        }

        public function render_card_thumbnail( $preview_data ) {
            $thumbnail_url = $preview_data['thumbnail_url'];
            $file_url      = $preview_data['file_url'];

            // Cards need the same browser fallback as listing previews when the
            // server cannot generate an image. Keep saved/custom images first.
            if (
                PDF_THUMBNAIL_INSERTER_URL . 'assets/images/pdf-placeholder.png' === $thumbnail_url
                && 'pdf' === strtolower( pathinfo( $this->file_resolver->get_url_path( $file_url ), PATHINFO_EXTENSION ) )
            ) {
                $thumbnail_url = $this->viewer->get_document_cover_url( $file_url, $preview_data['title'], 'portrait' );
                if ( '' === $thumbnail_url ) {
                    return $this->viewer->render_pdf_canvas_preview( $file_url, $preview_data['title'], 'pti-card__thumb' );
                }
            }

            return sprintf(
                '<img class="pti-card__thumb" src="%1$s" alt="%2$s" title="%2$s">',
                esc_url( $thumbnail_url ),
                esc_attr( $preview_data['title'] )
            );
        }

        public function render_pdf_card( $attachment_id, $atts, $context = array() ) {
            $context = wp_parse_args(
                $context,
                array(
                    'size'                   => 'medium',
                    'show_title'             => true,
                    'show_button'            => true,
                    'new_tab'                => true,
                    'button_text'            => '',
                    'rel_attr'               => '',
                    'target_attr'            => '',
                    'allow_custom_overrides' => true,
                )
            );

            $preview_data = $this->previews->get_pdf_preview_data( $attachment_id, $context['size'] ? $context['size'] : 'medium' );
            $effective_atts = $atts;

            if ( ! $context['allow_custom_overrides'] ) {
                $effective_atts['thumbnail'] = '';
                $effective_atts['title']     = '';

                if ( 'custom' === $this->settings->sanitize_link_to( $effective_atts['link_to'] ) ) {
                    $effective_atts['link_to'] = 'file';
                    $effective_atts['url']     = '';
                }
            }

            if ( ! empty( $effective_atts['thumbnail'] ) ) {
                $preview_data['thumbnail_url'] = esc_url_raw( $effective_atts['thumbnail'] );
            }

            if ( ! empty( $effective_atts['title'] ) ) {
                $preview_data['title'] = sanitize_text_field( $effective_atts['title'] );
            }

            $link_data = $this->build_link_data( $effective_atts, $preview_data );
            $link_attributes = PDF_Thumbnail_Inserter_HTML::link_attributes( $context );
            $target_url = $link_data['target_url'];
            $link_to = $link_data['link_to'];
            $button_should_render = $context['show_button'] && 'none' !== $link_to && ! empty( $target_url );
            $button_title = trim( $context['button_text'] . ' ' . $preview_data['title'] );

            ob_start();
            ?>
            <div class="pti-card-wrap">
                <figure class="pti-card">
                    <?php if ( ! empty( $target_url ) ) : ?>
                        <a class="pti-card__thumb-link" href="<?php echo esc_url( $target_url ); ?>"<?php if ( '' !== $link_attributes['target'] ) : ?> target="<?php echo esc_attr( $link_attributes['target'] ); ?>"<?php endif; ?><?php if ( '' !== $link_attributes['rel'] ) : ?> rel="<?php echo esc_attr( $link_attributes['rel'] ); ?>"<?php endif; ?> aria-label="<?php echo esc_attr( $preview_data['title'] ); ?>" title="<?php echo esc_attr( $preview_data['title'] ); ?>">
                            <?php echo wp_kses( $this->render_card_thumbnail( $preview_data ), PDF_Thumbnail_Inserter_HTML::media() ); ?>
                        </a>
                    <?php else : ?>
                        <span class="pti-card__thumb-link pti-card__thumb-link--static" aria-hidden="true" title="<?php echo esc_attr( $preview_data['title'] ); ?>">
                            <?php echo wp_kses( $this->render_card_thumbnail( $preview_data ), PDF_Thumbnail_Inserter_HTML::media() ); ?>
                        </span>
                    <?php endif; ?>

                    <?php if ( $context['show_title'] ) : ?>
                        <figcaption class="pti-card__caption" title="<?php echo esc_attr( $preview_data['title'] ); ?>">
                            <?php if ( ! empty( $target_url ) ) : ?>
                                <a class="pti-card__title pti-card__title-link" href="<?php echo esc_url( $target_url ); ?>"<?php if ( '' !== $link_attributes['target'] ) : ?> target="<?php echo esc_attr( $link_attributes['target'] ); ?>"<?php endif; ?><?php if ( '' !== $link_attributes['rel'] ) : ?> rel="<?php echo esc_attr( $link_attributes['rel'] ); ?>"<?php endif; ?> title="<?php echo esc_attr( $preview_data['title'] ); ?>"><?php echo esc_html( $preview_data['title'] ); ?></a>
                            <?php else : ?>
                                <span class="pti-card__title" title="<?php echo esc_attr( $preview_data['title'] ); ?>"><?php echo esc_html( $preview_data['title'] ); ?></span>
                            <?php endif; ?>
                        </figcaption>
                    <?php endif; ?>

                    <?php if ( $button_should_render ) : ?>
                        <a class="pti-card__button" href="<?php echo esc_url( $target_url ); ?>"<?php if ( '' !== $link_attributes['target'] ) : ?> target="<?php echo esc_attr( $link_attributes['target'] ); ?>"<?php endif; ?><?php if ( '' !== $link_attributes['rel'] ) : ?> rel="<?php echo esc_attr( $link_attributes['rel'] ); ?>"<?php endif; ?> title="<?php echo esc_attr( $button_title ); ?>"><?php echo esc_html( $context['button_text'] ); ?></a>
                    <?php endif; ?>
                </figure>
            </div>
            <?php

            return (string) ob_get_clean();
        }

        public function render_legacy_url_card( $atts, $context = array() ) {
            $context = wp_parse_args(
                $context,
                array(
                    'show_title'  => true,
                    'show_button' => true,
                    'button_text' => '',
                    'rel_attr'    => '',
                    'target_attr' => '',
                )
            );

            $file_url = esc_url_raw( $atts['url'] );

            if ( '' === $file_url ) {
                return '';
            }

            $thumbnail_url = esc_url_raw( $atts['thumbnail'] );

            if ( '' === $thumbnail_url ) {
                $thumbnail_url = PDF_THUMBNAIL_INSERTER_URL . 'assets/images/pdf-placeholder.png';
            }

            $title = sanitize_text_field( $atts['title'] );

            if ( '' === $title ) {
                $path     = $this->file_resolver->get_url_path( $file_url );
                $filename = rawurldecode( pathinfo( basename( $path ), PATHINFO_FILENAME ) );
                $title    = sanitize_text_field( trim( preg_replace( '/[-_]+/u', ' ', $filename ) ) );

                if ( '' === $title ) {
                    $title = __( 'PDF File', 'pdf-thumbnail-inserter' );
                }
            }

            $preview_data = array(
                'title'          => $title,
                'file_url'       => $file_url,
                'attachment_url' => $file_url,
                'thumbnail_url'  => $thumbnail_url,
            );

            $link_data = $this->build_link_data( $atts, $preview_data );
            $link_attributes = PDF_Thumbnail_Inserter_HTML::link_attributes( $context );
            $target_url = $link_data['target_url'];
            $link_to = $link_data['link_to'];
            $button_should_render = $context['show_button'] && 'none' !== $link_to && ! empty( $target_url );
            $button_title = trim( $context['button_text'] . ' ' . $preview_data['title'] );
            $legacy_style = $this->get_grid_style( $atts ) . ';width:' . $this->settings->sanitize_css_dimension( $atts['thumbnail_width'], $this->settings->get_defaults()['thumbnail_width'] ) . ';margin:10px;display:inline-block;text-align:center';

            ob_start();
            ?>
            <div class="pti-card-wrap" style="<?php echo esc_attr( $legacy_style ); ?>">
                <figure class="pti-card">
                    <?php if ( ! empty( $target_url ) ) : ?>
                        <a class="pti-card__thumb-link" href="<?php echo esc_url( $target_url ); ?>"<?php if ( '' !== $link_attributes['target'] ) : ?> target="<?php echo esc_attr( $link_attributes['target'] ); ?>"<?php endif; ?><?php if ( '' !== $link_attributes['rel'] ) : ?> rel="<?php echo esc_attr( $link_attributes['rel'] ); ?>"<?php endif; ?> aria-label="<?php echo esc_attr( $preview_data['title'] ); ?>" title="<?php echo esc_attr( $preview_data['title'] ); ?>">
                            <?php echo wp_kses( $this->render_card_thumbnail( $preview_data ), PDF_Thumbnail_Inserter_HTML::media() ); ?>
                        </a>
                    <?php else : ?>
                        <span class="pti-card__thumb-link pti-card__thumb-link--static" aria-hidden="true" title="<?php echo esc_attr( $preview_data['title'] ); ?>">
                            <?php echo wp_kses( $this->render_card_thumbnail( $preview_data ), PDF_Thumbnail_Inserter_HTML::media() ); ?>
                        </span>
                    <?php endif; ?>

                    <?php if ( $context['show_title'] ) : ?>
                        <figcaption class="pti-card__caption" title="<?php echo esc_attr( $preview_data['title'] ); ?>">
                            <?php if ( ! empty( $target_url ) ) : ?>
                                <a class="pti-card__title pti-card__title-link" href="<?php echo esc_url( $target_url ); ?>"<?php if ( '' !== $link_attributes['target'] ) : ?> target="<?php echo esc_attr( $link_attributes['target'] ); ?>"<?php endif; ?><?php if ( '' !== $link_attributes['rel'] ) : ?> rel="<?php echo esc_attr( $link_attributes['rel'] ); ?>"<?php endif; ?> title="<?php echo esc_attr( $preview_data['title'] ); ?>"><?php echo esc_html( $preview_data['title'] ); ?></a>
                            <?php else : ?>
                                <span class="pti-card__title" title="<?php echo esc_attr( $preview_data['title'] ); ?>"><?php echo esc_html( $preview_data['title'] ); ?></span>
                            <?php endif; ?>
                        </figcaption>
                    <?php endif; ?>

                    <?php if ( $button_should_render ) : ?>
                        <a class="pti-card__button" href="<?php echo esc_url( $target_url ); ?>"<?php if ( '' !== $link_attributes['target'] ) : ?> target="<?php echo esc_attr( $link_attributes['target'] ); ?>"<?php endif; ?><?php if ( '' !== $link_attributes['rel'] ) : ?> rel="<?php echo esc_attr( $link_attributes['rel'] ); ?>"<?php endif; ?> title="<?php echo esc_attr( $button_title ); ?>"><?php echo esc_html( $context['button_text'] ); ?></a>
                    <?php endif; ?>
                </figure>
            </div>
            <?php

            return (string) ob_get_clean();
        }

        public function get_shortcode_atts( $atts ) {
            $settings = $this->settings->get_settings();

            $defaults = array(
                'id'                 => 0,
                'ids'                => '',
                'thumbnail'          => '',
                'title'              => '',
                'url'                => '',
                'link_to'            => $settings['default_link_to'],
                'show_title'         => $settings['default_show_title'] ? 'yes' : 'no',
                'show_button'        => $settings['default_show_button'] ? 'yes' : 'no',
                'button_text'        => $settings['button_text'],
                'new_tab'            => $settings['default_new_tab'] ? 'yes' : 'no',
                'size'               => 'medium',
                'thumbnail_width'    => $settings['thumbnail_width'],
                'title_color'        => $settings['title_color'],
                'title_size'         => $settings['title_size'],
                'button_color'       => $settings['button_color'],
                'button_hover_color' => $settings['button_hover_color'],
                'button_text_color'  => $settings['button_text_color'],
                'button_text_size'   => $settings['button_text_size'],
                'columns_desktop'    => 3,
                'columns_widescreen' => '',
                'columns_laptop'     => 2,
                'columns_mobile'     => 1,
                'class'              => '',
                'rel'                => '',
            );

            $atts = is_array( $atts ) ? $atts : array();
            foreach ( $atts as $key => $value ) {
                if ( 'ids' === $key && is_array( $value ) ) {
                    $atts[ $key ] = array_filter( $value, 'is_scalar' );
                } elseif ( ! is_scalar( $value ) ) {
                    unset( $atts[ $key ] );
                }
            }
            return shortcode_atts( $defaults, $atts, 'pdf_thumbnail_inserter' );
        }

        public function get_doc_placeholder_url() {
            return PDF_THUMBNAIL_INSERTER_URL . 'assets/images/doc-placeholder.png';
        }

        public function render_gutenberg_file_card( $file_url, $title, $thumbnail_url ) {
            $settings = $this->settings->get_settings();
            $atts = $this->get_shortcode_atts(
                array(
                    'thumbnail'   => $thumbnail_url,
                    'title'       => $title,
                    'url'         => $file_url,
                    'link_to'     => 'custom',
                    'show_title'  => ! empty( $settings['default_show_title'] ) ? 'yes' : 'no',
                    'show_button' => ! empty( $settings['default_show_button'] ) ? 'yes' : 'no',
                    'button_text' => $settings['button_text'],
                    'new_tab'     => ! empty( $settings['default_new_tab'] ) ? 'yes' : 'no',
                )
            );

            $show_title  = $this->normalize_bool_attr( $atts['show_title'], true );
            $show_button = $this->normalize_bool_attr( $atts['show_button'], true );
            $new_tab     = $this->normalize_bool_attr( $atts['new_tab'], true );
            $target_attr = $new_tab ? ' target="_blank"' : '';
            $rel_values  = $new_tab ? array( 'noopener', 'noreferrer' ) : array();
            $rel_attr    = ! empty( $rel_values ) ? ' rel="' . esc_attr( implode( ' ', array_unique( $rel_values ) ) ) . '"' : '';

            return $this->render_legacy_url_card(
                $atts,
                array(
                    'show_title'  => $show_title,
                    'show_button' => $show_button,
                    'button_text' => $atts['button_text'],
                    'rel_attr'    => $rel_attr,
                    'target_attr' => $target_attr,
                )
            );
        }

        public function render_gutenberg_pdf_block_as_card( $block_content, $block, $file_data ) {
            $attachment_id = ! empty( $file_data['attachment_id'] ) ? absint( $file_data['attachment_id'] ) : 0;
            $file_url      = $file_data['file_url'];
            $title         = $this->discovery->get_gutenberg_file_block_title( $block_content, $attachment_id, $file_url, __( 'PDF File', 'pdf-thumbnail-inserter' ) );
            $thumbnail_url = PDF_THUMBNAIL_INSERTER_URL . 'assets/images/pdf-placeholder.png';

            if ( $attachment_id && 'application/pdf' === get_post_mime_type( $attachment_id ) ) {
                $preview_data  = $this->previews->get_pdf_preview_data( $attachment_id, 'medium' );
                $thumbnail_url = ! empty( $preview_data['thumbnail_url'] ) ? esc_url_raw( $preview_data['thumbnail_url'] ) : $thumbnail_url;
            }

            return $this->render_gutenberg_file_card( $file_url, $title, $thumbnail_url );
        }

        public function render_gutenberg_doc_block_as_card( $block_content, $block, $file_data ) {
            $attachment_id = ! empty( $file_data['attachment_id'] ) ? absint( $file_data['attachment_id'] ) : 0;
            $file_url      = $file_data['file_url'];
            $title         = $this->discovery->get_gutenberg_file_block_title( $block_content, $attachment_id, $file_url, __( 'Document File', 'pdf-thumbnail-inserter' ) );

            return $this->render_gutenberg_file_card( $file_url, $title, $this->get_doc_placeholder_url() );
        }

        public function render_shortcode( $atts ) {
            $list_settings = $this->settings->get_settings();
            if ( ! empty( $list_settings['use_first_pdf_preview_as_featured_image'] ) && $this->listing_filters->is_attachment_list_render_context() ) {
                return '';
            }

            wp_enqueue_style( 'pdf-thumbnail-frontend', PDF_THUMBNAIL_INSERTER_URL . 'assets/css/pdf-thumbnail.css', array(), PDF_Thumbnail_Inserter::VERSION );

            $atts = $this->get_shortcode_atts( $atts );
            $attachment_ids = $this->parse_attachment_ids( $atts );
            $size = sanitize_key( $atts['size'] );
            $show_title = $this->normalize_bool_attr( $atts['show_title'], true );
            $show_button = $this->normalize_bool_attr( $atts['show_button'], true );
            $new_tab = $this->normalize_bool_attr( $atts['new_tab'], true );
            $custom_classes = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $atts['class'] ) ) );
            $rel = sanitize_text_field( $atts['rel'] );

            if ( empty( $attachment_ids ) && ( ! empty( $atts['id'] ) || ! empty( $atts['ids'] ) || ! empty( $atts['title'] ) ) ) {
                $resolved_file_data = $this->reference_lookup->get_first_pdf_from_reference_atts( $atts );

                if ( ! empty( $resolved_file_data['attachment_id'] ) ) {
                    $attachment_ids = array( absint( $resolved_file_data['attachment_id'] ) );
                } elseif ( ! empty( $resolved_file_data['file_url'] ) ) {
                    $atts['url'] = esc_url_raw( $resolved_file_data['file_url'] );

                    if ( empty( $atts['thumbnail'] ) ) {
                        $resolved_preview_url = $this->previews->get_pdf_preview_url_from_file_url( $atts['url'], false );
                        if ( '' !== $resolved_preview_url ) {
                            $atts['thumbnail'] = $resolved_preview_url;
                        }
                    }
                }
            }

            $legacy_url_mode = empty( $attachment_ids ) && '' !== esc_url_raw( $atts['url'] );

            if ( empty( $attachment_ids ) && ! $legacy_url_mode ) {
                return '';
            }

            $target_attr = $new_tab ? ' target="_blank"' : '';
            $rel_values = array_filter( array_map( 'trim', explode( ' ', $rel ) ) );

            if ( $new_tab ) {
                $rel_values[] = 'noopener';
                $rel_values[] = 'noreferrer';
            }

            $rel_attr = $rel_values ? ' rel="' . esc_attr( implode( ' ', array_unique( $rel_values ) ) ) . '"' : '';
            $wrapper_style = $this->get_grid_style( $atts );
            $classes = trim( 'pti-grid-wrap ' . implode( ' ', $custom_classes ) );
            $button_text = sanitize_text_field( $atts['button_text'] );

            if ( '' === $button_text ) {
                $button_text = $this->settings->get_settings()['button_text'];
            }

            if ( $legacy_url_mode ) {
                return $this->render_legacy_url_card(
                    $atts,
                    array(
                        'show_title'  => $show_title,
                        'show_button' => $show_button,
                        'button_text' => $button_text,
                        'rel_attr'    => $rel_attr,
                        'target_attr' => $target_attr,
                    )
                );
            }

            $allow_custom_overrides = 1 === count( $attachment_ids );

            ob_start();
            ?>
            <div class="<?php echo esc_attr( $classes ); ?>" style="<?php echo esc_attr( $wrapper_style ); ?>">
                <div class="pti-grid">
                    <?php foreach ( $attachment_ids as $attachment_id ) : ?>
                        <?php
                        $card_html = $this->render_pdf_card(
                            $attachment_id,
                            $atts,
                            array(
                                'size'                   => $size ? $size : 'medium',
                                'show_title'             => $show_title,
                                'show_button'            => $show_button,
                                'new_tab'                => $new_tab,
                                'button_text'            => $button_text,
                                'rel_attr'               => $rel_attr,
                                'target_attr'            => $target_attr,
                                'allow_custom_overrides' => $allow_custom_overrides,
                            )
                        );
                        // All variable output is escaped for its context inside render_pdf_card().
                        echo wp_kses( $card_html, PDF_Thumbnail_Inserter_HTML::media() );
                        ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php

            return (string) ob_get_clean();
        }
}
