# PDF Thumbnail Inserter 1.11.49 — readiness audit

Audit date: 2026-11-22. Release gate: **PASS with the documented warning dispositions and the user's accepted Gutenberg 7.1 visual exception**. Publication is a separate final step; the immutable version tag identifies the release commit. This report supersedes the earlier incremental checkpoints.

Baseline: `695d8cfa3a72554b1f1ebac1391942e309c7dfa8` / published v1.11.48. Work branch: `codex/full-readiness-audit`; release branch: `main`. Fetch confirmed no upstream divergence; main is unprotected. Existing user ZIPs 1.11.33–1.11.44 were preserved and excluded from commits/packages. No applicable AGENTS.md was found. Production sites were not modified; integration packages were obtained read-only and used only in the disposable lab.

## Artifact and environments

The candidate `pdf-thumbnail-inserter-1.11.49.zip` contains 229 files in one `pdf-thumbnail-inserter/` directory. SHA-256: `ad067fd7ea7c6def25abc8f298c3cfd940c43bdc2473363b625f848f5fec59c8`. [Manifest and file hashes](release-manifest.json) verify the exact extracted bytes in all four installations. Packaging uses sorted entries, fixed timestamps and canonical Git text bytes; only LICENSE, README, readme, the PHP bootstrap and tracked runtime assets/blocks are included. Tests, this report, tools, skills, Git metadata, temporary data, other ZIPs and credentials are excluded.

| Environment | Executed coverage |
| --- | --- |
| WordPress 6.0 / PHP 7.4.27 / MariaDB 11.4.12 | Declared minimum; 268 readiness assertions without Imagick and 282 with Imagick; actual Gutenberg and Classic browser flows, native jobs/concurrency and authenticated HTTP uploads. |
| WordPress 7.1.5 / PHP 8.2.30 / SQLite Integration 3.1.3 | Current stable endpoint; 269 readiness assertions without Imagick and 283 with Imagick; Elementor/Pro 4.4.1, TheGov 2.0.20 / Core 2.0.17, WGL/Loop Grid browser flows, native jobs/concurrency and HTTP uploads. |
| Separate fresh WordPress 7.1.5 lifecycle site | Official core checksums; absent plugin/options → fresh activation → legacy migration → deactivation → actual uninstall/file removal → reinstall; then immutable published 1.11.48 ZIP → final 1.11.49 ZIP. Settings, image/PDF bytes, ownership and stored legacy/block content survive. |

Sites use local environment type, disabled cron/automatic updates and separate databases. Imagick 3.8.1, ImageMagick 7.1.1-46 Q16 and Ghostscript 10.04.0 were loaded per process; the global WAMP configuration was not changed. Browser evidence is from the available Codex embedded browser. PHP 8.4 was statically checked, not executed. Core version/checksum and installed dependency evidence were inspected before testing; the current [official update API](https://api.wordpress.org/core/version-check/1.7/) and [Plugin Check](https://wordpress.org/plugins/plugin-check/) were consulted.

## Findings and root-cause corrections

| Severity / location | Evidence, cause and final behavior |
| --- | --- |
| High — PHP preview publication and ownership | Inconsistent browser/server write paths permitted races and partial metadata publication. One canonical native per-PDF lock now serializes writers and core deletion. Publication validates stored ownership/path/metadata before replacing the previous image; failed new attachments and sizes are removed. Real forced-write/exception tests and two-process contention/crash recovery pass. Zero-byte lock files are intentionally retained to avoid inode races. |
| High — PHP core preview metadata | The actual published 1.11.48 returned generated=true after rejected metadata writes and left three untracked JPEGs. Core sub-size writes are now deferred within this exact PDF operation, metadata is captured in memory, final persistence/readback is verified and failed generated files are removed without deleting existing/referenced files. Rejected, throwing and corrupting filters preserve old metadata/images in both native endpoint environments. [Before-fix proof](evidence/published-core-rejection-proof.json). |
| High — PHP deletion | A preview writer could publish after the parent PDF had been deleted. Core deletion now acquires the same lock through file cleanup; stale writers revalidate the parent/file under that lock. Busy deletion returns false; retry succeeds. A third-party veto on the owned child's deletion retains the parent/pointer. Unowned images are never removed. Twelve actual assertions per environment pass. |
| High — PHP server maintenance / admin.js | Whole-library synchronous decoding was unbounded. Persisted per-user cursors/high-water marks now limit scans to 100 IDs plus one lookahead and conversion attempts to 10/request; a five-second budget applies between PDFs. Native locks, bounded errors, replay/stale-token handling and no-JavaScript continuation preserve the existing action. Actual browser verification caught the hidden `action` input shadowing form.action; the client uses getAttribute instead. Native 20 assertions per environment and 35 real HTTP assertions pass. |
| High — PHP frontend resolver paths | Ordinary rendering could generate images and write failure metadata. Frontend/media reads now reuse previews or select the configured cover/canvas. Upload/maintenance generation remains. The public resolver keeps its legacy generation default via an optional third boolean; read-only callers explicitly disable it. Cache modes/invalidation are separated. Real WGL painting with Imagick available leaves no generated image/error metadata. |
| Medium — PHP settings/attachment inputs | Nested arrays/objects could reach scalar functions or malformed IDs. Shape validation, unslashing, sanitization and context escaping preserve valid stored settings and aliases. Traversal/origin/port/realpath checks, MIME/extension, 8 MiB and 4096px upload bounds protect saved images. Nonces and object capabilities are independently tested through actual HTTP. |
| Medium — PHP PDF lookup | Windows PHP 7.4 parsing of Greek paths failed; literal glob metacharacters selected unrelated images (`report[1]` matched `report1`). ASCII URI parsing and literal glob escaping now retain Greek/query URL support and correct filename identity. Native bracket/Greek/exact/loose reference regressions pass. |
| Medium — PHP/media preview reuse | Missing/corrupt local images were treated as valid previews and blocked repair. Shared image/path validation removes broken local media sizes while preserving filtered external CDN URLs without remote probing. Existing/custom/WordPress/plugin previews retain their priority. |
| Medium — core/file and dynamic block | Existing embeds were excluded despite the conversion setting's description. PDF/DOC/iframe combinations now follow their switches. API 3 applies on WP ≥6.3 and API 2 on minimum WP 6.0. New insertion defaults are separate from old schema defaults; wrapper/custom classes and inert editor preview accessibility survive. Real minimum Gutenberg/Classic save/reload and native tests pass. |
| Medium — Elementor/WGL | Category suppression and containing-template resolution could hide or select the wrong post image. Current-document/current-post checks preserve real featured images, dedicated Loop Grid widgets and category behavior. Actual TheGov/WGL and Elementor Pro browser cases pass; the null-document guard is retained because the actual manager can return null despite its PHPDoc. |
| Medium — viewer.js / viewer.css | Eager full-document canvases retained unbounded pixels. Pages near the viewport are rendered serially with ≤1200×2000 backing pixels; offscreen canvases are zeroed/removed, revisits render again, and navigation disposes the worker. Placeholder batches are 50, avoiding eager allocation from a malformed huge page count. Real 100-page/thumbnail/extreme/broken-file browser checkpoints and explicit cancellation/resize/fallback/batching mocks substantiate the separate claims. The final batching adjustment is behaviorally tested, not represented as a second real 100-page run. |
| Performance — preview discovery / pdf-runtime.js | Real cold discovery decreased from 201 to 3 queries for 100 attachments. Shared lazy PDF.js loading avoids importing the 458,705-byte uncompressed library when there is no canvas work. Two shared preview decoders and bounded pixels are behavioral tests. These are query/load/work bounds, not claimed latency benchmarks. |
| Licensing — assets/pdfjs | Restored the exact upstream Liberation notice and two missing upstream QuickJS MIT notices. All 208 library/resource/notice files match their declared sources and npm artifact integrity. Bundled 6.3.289 is not claimed to be latest; the current npm advisory result is empty. Individual notices and corresponding source/build links remain distributed. Four original Liberation trailing-space lines are intentionally retained for license byte identity. |

## Whole-distribution review and skills applied

The review covers all 229 distributed files: the PHP singleton/bootstrap and every hook/callback; README/readme/LICENSE; block.json/index.js/editor.css; admin.js/admin.css, Classic insertion, browser upload/maintenance/renderer/runtime and frontend preview/CSS; the viewer's JS/CSS; both placeholder images; PDF.js VERSION/provenance and every upstream runtime/resource/notice file. [Exact file list](release-manifest.json). No bundled directory was excluded to obtain a clean check.

The plugin adds no REST route, custom table, rewrite rule or cron event. Core REST uploads are observed by its JavaScript middleware. There is no activation-time flush/work requirement. No uninstall erasure hook is introduced: saved settings, original PDFs and reusable generated media intentionally survive uninstall; explicit active PDF deletion removes only owned previews. Settings use the core Settings API; legacy migration is idempotent and non-autoloaded. Queries use core APIs/prepared SQL and bounded compatibility candidates; custom core metadata indexes were not added.

Skills were applied to their actual subsystems: router/triage classified the repository and requirements; plugin-development security/settings/lifecycle/data references guided shape/object authorization, publication and retained data; block-development guided registration/default serialization and wrappers; performance guided measured query/load bounds and read/write separation; plugin-directory-guidelines guided exact ZIP Plugin Check and license/provenance; phpstan guided unchanged level 5/PHP 7.4 analysis with actual optional integration source; wp-wpcli-and-ops guided owned disposable sites, installs and lifecycle. No recommendation requiring a newer core API was applied blindly to the minimum WP 6.0 environment.

## Required checks and reproducibility

- `python scripts/check_syntax.py --php C:/wamp64/bin/php/php7.4.27/php.exe`: 35 individually enumerated files (13 PHP / 22 JS, MJS and CJS), PASS.
- Both PHP versions: `tests/preview-regression.php`, `security-regression.php`, `readiness-regression.php`, PASS. All six Node suites documented in README pass. Boundary mocks are not labeled native browser tests.
- Native readiness wrappers set PTI_TEST_DISPOSABLE, PTI_TEST_WP_ROOT and PTI_TEST_REQUIRE_IMAGICK and include `tests/readiness-wordpress.php` directly; all 1,102 assertions pass across four configurations. See [raw evidence](evidence/).
- With PTI_TEST_DISPOSABLE=true and a disposable root argument: `preview-lock-concurrency.php`, `deletion-concurrency.php`, `preview-discovery-queries.php`, `server-maintenance-wordpress.php`, `server-maintenance-http.php`, `upload-http.php`, PASS. The server-maintenance native suite requires Imagick; HTTP suites require their owned loopback servers. The original WordPress integration suite also passes 31 assertions.
- Lifecycle phases are documented in README; [actual new-version upgrade](evidence/lifecycle-upgraded.json) passes after the published baseline's entrypoint byte identity was checked. Cleanup is recorded separately after the independent integration suite's settings were restored to its retained synthetic fixture. The final ZIP also completed a fourth, genuinely fresh site's full install/migration/deactivation/uninstall/reinstall/cleanup sequence (59 assertions), with official core checksums. A README-only correction was followed by byte-for-byte reinstallation on all four sites and a repeated exact-ZIP Plugin Check; runtime PHP/JS bytes did not change from the full matrix. The cleanup helper's version argument was corrected, rather than hiding its initial harness failure.
- PHPCompatibilityWP testVersion=7.4–8.4 on distributed PHP: zero errors/warnings. PHPStan 2.3.7 / WP stubs 7.1.2 / extension 2.0.4 / level 5 / phpVersion 70400: **18 reviewed findings, not a clean run**. No baseline, excludes, ignore rules or lowered level. [All tool messages and dispositions](automated-findings.md).
- Official Plugin Check 2.2.0 on the installed exact ZIP, plugin active, early runner initialized: **0 errors / 27 justified warnings**, all 36 default checks including five runtime resource checks. Exit code alone is not treated as success. No skip/ignore flags or reduced thresholds. [Exact findings](plugin-check-checkpoint.json).
- `python scripts/verify_pdfjs.py`: 208 comparisons and artifact/advisory checks PASS. `python scripts/package.py`: runtime-only manifest, source bytes, ZIP integrity and deterministic packaging PASS. [Final machine checkpoint](readiness-checkpoint.json).

## Actual browser scope and limits

Executed browser flows include Media Add New with automatic saved JPEG and no server Imagick; manual settings generation; WP 6.0 Gutenberg select/multiselect/replace/remove and inspector toggles in both states, save/reload; Classic Visual/Text insertion and Media Library shortcode/status; real WGL/Loop Grid saved preview/featured-image/cover/canvas precedence and switches/slugs. Responsive and visual computed CSS tests used actual plugin markup/stylesheet in srcdoc at 400/1000/1300/1700px; they are isolated stylesheet tests, not four device/theme sessions. The 100-page viewer checkpoint shows only two live canvases on pages 99/100 and no first-page canvas; thumbnail, extreme dimensions and invalid PDF results are recorded separately.

WordPress 7.1's Gutenberg blob iframe would not load in the available embedded browser, including an independent empty blob without the plugin/theme. **Its visual editor remains NOT_VERIFIED under the user's expressly accepted best-effort exception**. Native 7.1 block registration, server rendering, settings and REST upload contracts were executed. A later attempt to reacquire the browser's crashed tab was denied by the browser policy; no bypass was attempted. Final small batching behavior was checked with the explicit viewer mocks. Automatic REST/media-dialog event detection is a behavioral test combined with real HTTP upload/save contracts; actual automatic PDF canvas painting and persistence were executed in Media Add New. Windows denied optional real symlink creation, so that case is not passed; realpath containment has native path and mocked symlink coverage. Multisite, arbitrary older Elementor/themes, all browser engines and PHP versions beyond the two recorded executables are not claimed as executed.

## Requirement matrix

PASS means executed coverage at the stated layer, not every possible cross-product of WordPress, themes and browsers. Risk-based combinations include all eight title/button/tab combinations across all four link modes, PDF/iframe/conversion switches, upload on/off with object roles, real vs absent/corrupt previews, current-post integration contexts and concurrent publication/deletion. [Machine-readable steps, preconditions, expected outcomes and evidence for all 69 requirements](requirements-verification.json).

| ID | Control / scenario | Result | Evidence |
| --- | --- | --- | --- |
| S01 | `button_text` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S02 | `thumbnail_width` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S03 | `title_color` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S04 | `title_size` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S05 | `button_color` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S06 | `button_hover_color` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S07 | `button_text_color` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S08 | `button_text_size` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S09 | `default_link_to` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S10 | `default_new_tab` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S11 | `default_show_title` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S12 | `default_show_button` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S13 | `use_iframe_pdf_previews` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S14 | `max_pdf_preview_height` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S15 | `convert_gutenberg_pdf_links_to_cards` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S16 | `convert_gutenberg_doc_links_to_cards` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S17 | `use_first_pdf_preview_as_featured_image` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S18 | `use_default_document_cover` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S19 | `auto_browser_preview_on_upload` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S20 | `document_cover_label` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S21 | `listing_pages` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S22 | `default_columns_widescreen` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S23 | `default_columns_desktop` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S24 | `default_columns_tablet` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| S25 | `default_columns_mobile` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A01 | `id` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A02 | `ids` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A03 | `thumbnail` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A04 | `title` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A05 | `url` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A06 | `link_to` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A07 | `show_title` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A08 | `show_button` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A09 | `button_text` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A10 | `new_tab` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A11 | `size` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A12 | `thumbnail_width` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A13 | `title_color` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A14 | `title_size` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A15 | `button_color` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A16 | `button_hover_color` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A17 | `button_text_color` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A18 | `button_text_size` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A19 | `columns_desktop` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A20 | `columns_widescreen` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A21 | `columns_laptop` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A22 | `columns_mobile` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A23 | `class` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| A24 | `rel` | PASS | readiness-expanded-{minimum,latest}{,-imagick}.json; editor-regression.cjs; readiness-regression.php |
| F01 | Single, multiple and URL-only PDF cards; canonical and legacy aliases | PASS | native readiness; preview-regression.php; Classic/Gutenberg browser records |
| F02 | Existing WordPress, plugin, standalone and custom image priority; missing/corrupt PDF behavior | PASS | native readiness: WordPress/plugin/custom/standalone priority and corrupt files |
| F03 | All editor selection/replace/remove actions, inspector controls and insertion defaults | PASS_WITH_ACCEPTED_VISUAL_EXCEPTION | WP 6.0 real Gutenberg/Classic browser records; editor-regression.cjs; native registration/rendering on 7.1.5 |
| F04 | Responsive widescreen/desktop/tablet/mobile and visual CSS controls | PASS | responsive-css-browser.json; visual-css-browser.json; native card matrix |
| F05 | PDF/DOC conversion, iframe on/off and setting combinations | PASS | native readiness and readiness-regression.php: PDF/DOC/iframe combinations |
| F06 | Portrait/landscape title covers, label/colors and escaped Greek titles | PASS | native cover tests; actual WGL/Loop Grid cover browser records |
| F07 | Listing eligibility, featured-image priority, manual excerpts, no-PDF and single-post preservation | PASS | native listing/excerpt tests; real WGL browser switch/slug/priority checks |
| F08 | Elementor widget and dynamic tag on real supported versions | PASS | native actual Elementor null-document test; actual Pro Loop Grid browser records |
| F09 | WGL cards on a real supported theme | PASS | actual TheGov/WGL listing browser records |
| F10 | Actual upload/core Imagick rendering, failures and reuse | PASS | four native readiness reports, actual Ghostscript/Imagick core/direct paths |
| F11 | Server maintenance enable/disable, generation, report and failure continuation | PASS | server-maintenance-{minimum,latest}.json; server-maintenance-http.json; actual settings browser run |
| F12 | Browser maintenance discovery, render, actual multipart save, replay and duplicate concurrency | PASS | server-maintenance-http.json; browser-maintenance-regression.cjs; actual browser generation |
| F13 | Automatic media-dialog, REST/drop and Media > Add New uploads on/off | PASS | upload-http-{minimum,latest}.json; browser-upload-regression.cjs; actual Media Add New browser upload |
| F14 | Media Library copyable shortcode/status and saved preview response | PASS | native readiness; real core media-model HTTP response; actual Classic media dialog |
| F15 | PDF deletion cleans only owned image; failures leave no orphans or lost existing previews | PASS | deletion-concurrency-{minimum,latest}.json; native injected metadata failures |
| F16 | Fresh install, upgrade, migration, deactivation/reactivation, uninstall data retention | PASS | lifecycle phase reports, actual published ZIP baseline and new ZIP upgrade; original integration 31 assertions |
| F17 | Capabilities/nonces, input shapes, traversal/symlinks, invalid images, repeated/concurrent requests | PASS | security-regression.php; native readiness; two-process locks; real authenticated HTTP |
| F18 | Query count, generation attempts, caches, bounded scans and conditional resources | PASS | 3 queries/100 cold attachments; server bounds; renderer/viewer behavioral suites; 100-page actual browser checkpoint |
| F19 | Bundled dependency provenance, licenses, security and source availability | PASS | pdfjs-provenance.json: all 208 resource/notice files and npm advisory result |
| F20 | Final ZIP consistency, reproducibility, installation/upgrade and Plugin Check | PASS | release-manifest.json; exact ZIP installs/upgrade; plugin-check-checkpoint.json; reproducible package |
