<?php
/** Elementor subsystem. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_Elementor {
    /** @var PDF_Thumbnail_Inserter_FeaturedImages */
    private $featured_images;

    /** @var PDF_Thumbnail_Inserter_Settings */
    private $settings;

    /** @var PDF_Thumbnail_Inserter_Viewer */
    private $viewer;

    /** Wire the request-local collaborators before registering any hooks. */
    public function set_dependencies( PDF_Thumbnail_Inserter_FeaturedImages $featured_images, PDF_Thumbnail_Inserter_Settings $settings, PDF_Thumbnail_Inserter_Viewer $viewer ) {
        $this->featured_images = $featured_images;
        $this->settings = $settings;
        $this->viewer = $viewer;
    }

    /**
     * Supply Posts skin media when Elementor rejects a virtual PDF featured ID.
     * Its image control intentionally accepts image attachments only, unlike core.
     *
     * @param string $html Existing image output.
     * @param array  $settings Widget settings passed to the Elementor image control.
     * @param string $image_size_key Image-size control name.
     * @param string $image_key Image value control name.
     * @return string
     */
    public function filter_posts_thumbnail_html( $html, $settings, $image_size_key, $image_key ) {
        if ( '' !== $html ) return $html;

        $skin = isset( $settings['_skin'] ) ? $settings['_skin'] : '';
        $skins = array( 'cards', 'classic', 'full_content', 'archive_cards', 'archive_classic', 'archive_full_content' );
        if ( ! in_array( $skin, $skins, true ) || $skin . '_thumbnail_size' !== $image_size_key || $image_key !== $image_size_key ) return $html;

        $image = isset( $settings[ $image_key ] ) && is_array( $settings[ $image_key ] ) ? $settings[ $image_key ] : array();
        if ( ! empty( $image['url'] ) ) return $html;

        $post_id = $this->featured_images->get_current_render_post_id();
        if ( ! $post_id || ! $this->featured_images->should_use_first_pdf_preview_as_featured_image( $post_id )
            || $this->featured_images->has_real_featured_image_id( $post_id ) ) return $html;

        $size_key = $image_size_key . '_size';
        $size = isset( $settings[ $size_key ] ) && is_string( $settings[ $size_key ] ) ? $settings[ $size_key ] : 'large';
        $fallback = $this->featured_images->get_pdf_featured_image_fallback_data( $post_id, $size );
        if ( ! empty( $image['id'] ) && absint( $image['id'] ) !== absint( $fallback['id'] ) ) return $html;

        if ( ! empty( $fallback['url'] ) ) {
            return sprintf(
                '<img class="pti-featured-image-or-pdf-preview wp-post-image" src="%1$s" alt="%2$s" loading="lazy" decoding="async">',
                esc_url( $fallback['url'] ),
                esc_attr( get_the_title( $post_id ) )
            );
        }

        return ! empty( $fallback['pdf_url'] ) ? $this->viewer->render_pdf_canvas_preview( $fallback['pdf_url'], get_the_title( $post_id ) ) : $html;
    }

        public function get_elementor_featured_image_fallback_value( $post_id = 0 ) {
            $post_id = absint( $post_id ? $post_id : $this->featured_images->get_current_render_post_id() );
            if ( ! $post_id ) {
                return array();
            }

            $real_thumbnail_id = absint( get_post_meta( $post_id, '_thumbnail_id', true ) );
            if ( $real_thumbnail_id ) {
                $real_image = wp_get_attachment_image_src( $real_thumbnail_id, 'full' );
                if ( is_array( $real_image ) && ! empty( $real_image[0] ) ) {
                    return array(
                        'id'  => $real_thumbnail_id,
                        'url' => esc_url_raw( $real_image[0] ),
                    );
                }

                return array();
            }

            if ( ! $this->featured_images->should_use_first_pdf_preview_as_featured_image( $post_id ) ) {
                return array();
            }

            $fallback = $this->featured_images->get_pdf_featured_image_fallback_data( $post_id, 'full' );
            if ( empty( $fallback['url'] ) ) {
                return array();
            }

            return array(
                'id'  => ! empty( $fallback['id'] ) ? absint( $fallback['id'] ) : 0,
                'url' => esc_url_raw( $fallback['url'] ),
            );
        }

        public function get_elementor_featured_image_widget_value( $post_id = 0 ) {
            $post_id = absint( $post_id ? $post_id : $this->featured_images->get_current_render_post_id() );
            if ( ! $post_id ) {
                return array();
            }

            $real_thumbnail_id = absint( get_post_meta( $post_id, '_thumbnail_id', true ) );
            if ( $real_thumbnail_id ) {
                $real_image = wp_get_attachment_image_src( $real_thumbnail_id, 'full' );
                if ( is_array( $real_image ) && ! empty( $real_image[0] ) ) {
                    return array(
                        'id'  => $real_thumbnail_id,
                        'url' => esc_url_raw( $real_image[0] ),
                    );
                }

                return array();
            }

            $settings = $this->settings->get_settings();
            if ( empty( $settings['use_first_pdf_preview_as_featured_image'] ) ) {
                return array();
            }

            $fallback = $this->featured_images->get_pdf_featured_image_fallback_data( $post_id, 'full' );
            if ( empty( $fallback['url'] ) && empty( $fallback['pdf_url'] ) ) {
                return array();
            }

            return array(
                'id'      => ! empty( $fallback['id'] ) ? absint( $fallback['id'] ) : 0,
                'url'     => ! empty( $fallback['url'] ) ? esc_url_raw( $fallback['url'] ) : '',
                'pdf_url' => ! empty( $fallback['pdf_url'] ) ? esc_url_raw( $fallback['pdf_url'] ) : '',
            );
        }

        public function register_elementor_featured_image_fallback_tag( $dynamic_tags ) {
            $settings = $this->settings->get_settings();
            if ( empty( $settings['use_first_pdf_preview_as_featured_image'] ) ) {
                return;
            }

            if (
                ! class_exists( '\\Elementor\\Core\\DynamicTags\\Data_Tag' )
                || ! is_object( $dynamic_tags )
                || ! method_exists( $dynamic_tags, 'register' )
            ) {
                return;
            }

            $dynamic_tags->register(
                new class() extends \Elementor\Core\DynamicTags\Data_Tag {
                    public function get_name() {
                        return 'pdf-thumbnail-featured-image-or-first-pdf';
                    }

                    public function get_title() {
                        return __( 'Featured Image or First PDF Preview', 'pdf-thumbnail-inserter' );
                    }

                    public function get_group() {
                        return 'post';
                    }

                    public function get_categories() {
                        return array( 'image' );
                    }

                    public function get_value( array $options = array() ) {
                        return \PDF_Thumbnail_Inserter::instance()->get_elementor_featured_image_fallback_value();
                    }
                }
            );
        }

        public function register_elementor_featured_image_fallback_widget( $widgets_manager ) {
            $settings = $this->settings->get_settings();
            if ( empty( $settings['use_first_pdf_preview_as_featured_image'] ) ) {
                return;
            }

            if (
                ! class_exists( '\\ElementorPro\\Modules\\ThemeBuilder\\Widgets\\Post_Featured_Image' )
                || ! class_exists( '\\Elementor\\Plugin' )
                || ! is_object( $widgets_manager )
                || ! method_exists( $widgets_manager, 'register' )
            ) {
                return;
            }

            $widgets_manager->register(
                new class() extends \ElementorPro\Modules\ThemeBuilder\Widgets\Post_Featured_Image {
                    public function get_name() {
                        return 'pdf-thumbnail-featured-image-or-first-pdf-widget';
                    }

                    public function get_title() {
                        return __( 'Featured Image or First PDF Preview', 'pdf-thumbnail-inserter' );
                    }

                    public function get_icon() {
                        return 'eicon-featured-image';
                    }

                    public function get_style_depends(): array {
                        return array_values( array_unique( array_merge( parent::get_style_depends(), array( 'pdf-thumbnail-frontend' ) ) ) );
                    }

                    protected function is_dynamic_content(): bool {
                        return true;
                    }

                    protected function render() {
                        $post_id = \PDF_Thumbnail_Inserter::instance()->get_current_render_post_id();
                        if ( ! $post_id || \PDF_Thumbnail_Inserter::instance()->should_suppress_elementor_post_content( $post_id ) ) {
                            return;
                        }

                        wp_enqueue_style( 'pdf-thumbnail-frontend' );

                        $image = \PDF_Thumbnail_Inserter::instance()->get_elementor_featured_image_widget_value( $post_id );
                        if ( empty( $image['url'] ) && empty( $image['pdf_url'] ) ) {
                            return;
                        }

                        $settings   = parent::get_settings_for_display();
                        $image_size = ! empty( $settings['image_size'] ) ? $settings['image_size'] : 'full';
                        $media_html = '';

                        if ( ! empty( $image['url'] ) ) {
                            if ( ! empty( $image['id'] ) ) {
                                $media_html = wp_get_attachment_image(
                                    absint( $image['id'] ),
                                    $image_size,
                                    false,
                                    array(
                                        'class' => 'pti-featured-image-or-pdf-preview wp-post-image',
                                        'alt'   => get_the_title( $post_id ),
                                    )
                                );
                            }

                            if ( '' === $media_html ) {
                                $media_html = sprintf(
                                    '<img class="pti-featured-image-or-pdf-preview wp-post-image" src="%1$s" alt="%2$s" loading="lazy" decoding="async">',
                                    esc_url( $image['url'] ),
                                    esc_attr( get_the_title( $post_id ) )
                                );
                            }
                        } elseif ( ! empty( $image['pdf_url'] ) ) {
                            $media_html = \PDF_Thumbnail_Inserter::instance()->render_pdf_canvas_preview_for_post( $image['pdf_url'], get_the_title( $post_id ) );
                        }

                        if ( '' === $media_html ) {
                            return;
                        }

                        $link = array();
                        if ( ! empty( $settings['link_to'] ) && 'file' === $settings['link_to'] ) {
                            $link = array( 'url' => ! empty( $image['pdf_url'] ) ? $image['pdf_url'] : $image['url'] );
                        } elseif ( ! empty( $settings['link_to'] ) && 'custom' === $settings['link_to'] && ! empty( $settings['link']['url'] ) ) {
                            $link = $settings['link'];
                        }

                        if ( ! empty( $link['url'] ) ) {
                            $this->add_link_attributes( 'link', $link );
                            echo '<a class="pti-featured-media-link" ';
                            $this->print_render_attribute_string( 'link' );
                            echo '>' . wp_kses( $media_html, PDF_Thumbnail_Inserter_HTML::media() ) . '</a>';
                            return;
                        }

                        echo wp_kses( $media_html, PDF_Thumbnail_Inserter_HTML::media() );
                    }
                }
            );
        }
}
