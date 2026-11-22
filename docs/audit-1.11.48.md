# WordPress code audit — 1.11.48

Audit date: 2026-11-22. Baseline: `d9ef6c2ac84af19322026b8f997c17c5efb387bf` (`main` and `origin/main` after fetch). The existing untracked 1.11.33–1.11.44 ZIPs were preserved. No applicable `AGENTS.md`, CI configuration, build script or branch protection was present; previous releases were published from `main` with `v1.11.*` tags and one installable ZIP.

## Skills and compatibility

All 20 skills from [WordPress/agent-skills](https://github.com/WordPress/agent-skills) were installed locally in `.agents/skills/`, from source commit `7374e6f1c913b3b02b25465d266d9c0e0a04fc96`. The installer helper failed in its Git fallback, so the complete skills directory was copied from a successful shallow clone. Skills used: `wordpress-router`, `wp-project-triage`, `wp-plugin-development` (security, lifecycle, settings and data), `wp-block-development`, `wp-performance`, `wp-wpcli-and-ops`, `wp-playground` and `blueprint` references for the disposable runtime. Skills and their source clone are local tools, excluded from commits and release ZIPs.

The plugin still supports WordPress 6.0+ and PHP 7.4+. The dynamic block retains API version 2: the skills target WordPress 7.0, while [block API version 3 was introduced in 6.3](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/). No newer-only APIs, REST routes, abilities, schema changes or block serialization changes were introduced. The triage scanner's Interactivity API/WP-CLI signals came from the locally installed skills, not plugin runtime code.

## Findings and changes

| Priority | Evidence in baseline | Resolution and verification |
| --- | --- | --- |
| High | `pdf-thumbnail-inserter.php::get_pdf_preview_url_from_file_url()` mapped decoded URL paths directly under `basedir`, accepted foreign hosts with matching paths, and checked only a lexical prefix after filesystem access. `get_local_pdf_viewer_file_url()` accepted dot segments and arbitrary same-host ports. | Shared realpath-based local PDF validation checks URL authority, extension, readable regular files, traversal and uploads containment before scanning/rendering. Tests cover encoded traversal, Greek filenames, query strings, NULs, foreign hosts/ports and real symlinks outside uploads. |
| High | `cleanup_generated_preview()` and browser replacement trusted any truthy `_pdf_thumbnail_parent_pdf_id`; a stale cross-PDF reference could delete another PDF's generated attachment. Replacement deleted the old image before insertion succeeded. | Deletion now requires an image attachment whose actual parent and ownership marker both equal this PDF. Browser replacements are published before old-image cleanup. Boundary and real WordPress deletion tests prove unrelated images remain and owned images are removed. |
| Medium | `get_pdfjs_viewer_url()` passed an unencoded nested URL to `add_query_arg()`, allowing `&` to split its query; unsupported `core/file` previews could be replaced by an empty iframe URL. | Encode the nested URL and retain the original embed when the local viewer cannot handle it. Real WordPress URL round-trip and browser rendering verified. |
| Medium / performance | `ajax_list_missing_previews()` selected every PDF and called `get_pdf_preview_data()` with its implicit standalone-generation side effect. A browser-maintenance discovery request could attempt server rendering across the entire library before returning. | Browser discovery requests pages of at most 100 PDF IDs and explicitly disables standalone generation during discovery/save checks. Existing unpaged consumers retain their response shape, with additive `next_page`. Both PHP and JS regressions cover continuation, including pages with no missing previews. Real WordPress test splits 101 PDFs into 100 + 1. This is a bounded-work improvement; no production timing claim is made. |
| Medium | `assets/pdfjs-viewer/viewer.js` kept its loading task/worker after rendering or failure. | Destroy the loading task in `finally`; success/load-failure/render-failure tests verify cleanup. Card and shared upload renderer cleanup were already present and preserved. |
| Low | Metadata generation temporarily removed an upload hook without `finally`; failed server attachment insertion left the newly generated JPEG orphaned. | Restore the hook in `finally` and delete that new file when insertion returns `WP_Error`. PHP syntax/static review passed; native Imagick execution was unavailable. |
| Release | Packaging existed only as a ZIP under `dist/`, with no reproducible script. | `scripts/package.py` reproduces the existing 226-file runtime layout using a strict root allowlist and Git-tracked asset/block paths, fixed ZIP timestamps, version checks, archive integrity and byte-for-byte verification. |

## Reviewed surfaces retained

- Bootstrap registers hooks and shortcodes without database writes. No rewrite rules, custom tables, activation/deactivation tasks, cron events or background queue exist. Heavy maintenance remains explicitly requested by administrators. Deactivation and uninstall retain settings and reusable media; no new destructive uninstall policy was added.
- Settings use the Settings API, `manage_options`, nonce fields, sanitization callbacks and escaped output. The legacy option migration keeps saved values and writes the new option with autoload disabled. Existing small frontend settings remain available through the same option keys.
- AJAX actions require nonces and capabilities. Fetch/save also require upload/edit permission; automatic saving remains opt-in. Uploads use WordPress MIME/extension checking, an 8 MB limit and `wp_handle_upload()`. There are no custom REST endpoints, direct SQL queries or outbound HTTP requests in plugin runtime code; attachment searches use WordPress query APIs.
- Existing shortcode aliases, public PHP methods, block name/attributes, responsive defaults, saved options and real/custom image priority remain intact. Existing per-request post/PDF caches and standalone-generation failure transients remain intact. Frontend/browser assets retain their conditional enqueue paths and bundled PDF.js version.

## Executed validation

- PHP lint and the PHP rendering/security suites on native PHP 7.4.27 and 8.4.23.
- Node editor, automatic upload and browser-maintenance/viewer-cleanup regressions.
- Installation and activation of the packaged ZIP in isolated Playground WordPress 6.0.19/PHP 7.4.33 and WordPress 7.0.9/PHP 8.4.27; real WordPress settings migration, non-autoload migration, sanitization/save, registered block/scripts, canonical/legacy shortcodes, dynamic rendering, symlink/traversal rejection, image deletion, actual nonce/capability checks, 101-PDF pagination, deactivation/settings retention and reactivation.
- Real browser PDF.js first-page card rendering and the full viewer with a nested query-string PDF URL on the WordPress 6.0 fixture.
- Rebuilt ZIP integrity, exact 226-file runtime manifest and canonical Git source-byte match (text line endings normalized across Windows/Unix); final published ZIP/hash and Git refs are verified during release publication.

Playground runs must disable automatic core updates to keep compatibility tests pinned: use `--wp=6.0.19 --php=7.4` or `--wp=7.0.9 --php=8.4`, plus `--define-bool AUTOMATIC_UPDATER_DISABLED true --define-bool WP_AUTO_UPDATE_CORE false --define-bool DISABLE_WP_CRON true`. An initial unpinned test instance auto-upgraded; the minimum-version final ZIP installation was repeated in a fresh instance with updates disabled.

## Limits and remaining operational observations

Playground uses SQLite and has no native Imagick/Ghostscript PDF renderer. The installed WAMP site and Docker daemon were stopped; neither was modified. Native server rendering, a full browser upload/save cycle, the complete Gutenberg/Classic media-dialog UI, Elementor Pro/WGL on a real theme, multisite and production load were not exercised. Their existing mocks/static review are not substitutes for those environment checks. The existing synchronous server-maintenance action and compatibility-only unpaged discovery can still be expensive on very large libraries; the shipped browser workflow uses bounded discovery. No PHPCS/PHPStan/PHPUnit setup existed, so no claim is made that those tools passed.
