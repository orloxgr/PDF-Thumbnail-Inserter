<?php
/** Shared caches and contexts for a single request. @internal */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PDF_Thumbnail_Inserter_RuntimeState {
        /**
         * Request-local cache for PDF featured-image fallbacks.
         *
         * @var array<string,array{id:int,url:string}>
         */
        public $pdf_featured_image_cache = array();

        /**
         * Request-local cache for the first PDF found in post content.
         *
         * @var array<string,array|false>
         */
        public $first_pdf_resolver_cache = array();

        /**
         * Tracks whether a category archive has already rendered its one visual for each post.
         *
         * @var array<int,bool>
         */
        public $category_visual_rendered = array();

        /** @var array<int,array{path:string,lock:resource}> Locks held through core attachment deletion. */
        public $attachment_delete_locks = array();

        /** @var array<string,string> Validated public read-only request parameters. */
        public $public_preview_request = array();

        /** @var string[] Legacy parameters introduced by this plugin, not other routes. */
        public $preview_query_vars = array();

        /** @var array<WP_Post|null> Nested core excerpt contexts. */
        public $excerpt_posts = array();

}
