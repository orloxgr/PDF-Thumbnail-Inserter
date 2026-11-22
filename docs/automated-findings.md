# Automated findings — audit checkpoint, not release approval

The candidate is an **unreleased** 1.11.48 audit build, not the existing GitHub artifact. Its 229-file ZIP SHA-256 is `7b699966072dd8d67209afcfc46c9550abc589faecb27e3ecab84b96575d9045`. Source changes or the final version bump require a new package/check. Results remain visible; no check exclusions, reduced thresholds, PHPStan baseline or ignore rules were added.

## Official Plugin Check 2.2.0

Result: **FAIL — 1 ERROR / 27 WARNING**, despite exit code 0. The exact installed candidate was checked on WordPress 7.1.5/PHP 8.2.30 with the target plugin active. The early runtime runner selected 36 checks, including all five enqueued-resource runtime checks. [Full findings and runtime selection](plugin-check-checkpoint.json).

Command, from the repository root, using the owned disposable site:

```powershell
& C:/wamp64/bin/php/php8.2.30/php.exe '-dxdebug.mode=off' '-dmemory_limit=512M' C:/wpcli/wp-cli.phar plugin check pdf-thumbnail-inserter --path=dist/readiness/wp-7.1.5 --require=dist/readiness/runtime-proof.php --format=json
```

`runtime-proof.php` loads the installed `wp-content/plugins/plugin-check/cli.php` before WordPress and records the initialized runner/check names. It does not alter check selection or findings.

All code locations below refer to `pdf-thumbnail-inserter.php` in this candidate.

| Rule | Severity/count | Locations | Disposition and evidence |
| --- | --- | --- | --- |
| `outdated_tested_upto_header` | ERROR ×1 | `readme.txt`, header | Still open. The local build retains Tested up to 7.0. Set 7.1 only with the final version/verified compatibility record, including the expressly accepted Gutenberg browser limitation, and rerun PCP. An exit code does not waive this error. |
| `PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound` | WARNING ×1 | 117 | Retained for GitHub ZIP installations and plugin-local translations. The warning specifically concerns automatic WordPress.org language packs; this project is distributed on GitHub. A real synthetic Greek `.mo` file in the plugin's `languages/` path is loaded by the public method on native WordPress, without installing a WordPress.org language pack; the test removes it afterward. Source: `load_textdomain`; test: `tests/readiness-wordpress.php`, “Plugin-local MO translations”. |
| `WordPress.DB.SlowDBQuery.slow_db_query_meta_key` / `slow_db_query_meta_value` | WARNING ×6 | 2410–2411, 2733/2744, 2829–2830 | Retained compatibility lookups of WordPress's `_wp_attached_file`, not a custom metadata store. URL lookup tries canonical relative-path variants and returns one ID. Legacy filename lookup returns at most 10 candidates per variant; loose reference lookup uses at most six tokens and two searches per token, at most 20 candidates each. Real native tests resolve the correct unique Greek filename and query-bearing URL through all three paths. Explicit IDs bypass these fallback searches, and first-PDF resolutions are cached within the request. `LIKE` predicates can still scan matching metadata rows on large libraries: result limits are **not** claimed to bound rows examined, and no unmeasured speed claim is made. Altering core indexes or deleting legacy reference support is outside this correction. The separate paginated maintenance discovery was measured at 3 queries/100 attachments. |
| `WordPress.Security.NonceVerification.Recommended` | WARNING ×6 | 3227–3228 and 3235 | Public read-only viewer query fields. Values are scalar-checked, unslashed, sanitized and passed through origin/port, extension, readable-file and realpath/uploads-containment validation. The handler outputs a viewer with same-origin CSP; it performs no mutation based on these parameters. Requiring a login-bound nonce would break public embedded/shared viewing without providing CSRF protection for a write. Native traversal/foreign-origin tests and a real two-page browser viewer verify the path. |
| `WordPress.Security.NonceVerification.Recommended` | WARNING ×12 | 3546–3552 | Public read-only generated SVG cover request fields. File/shape/title are validated/sanitized and escaped for their individual SVG contexts. The endpoint streams the SVG; it does not save files, options or attachments. Covers must be usable by public image elements without session-bound URLs. Boundary injection/shape tests and actual native/frontend cover tests verify this behavior. Mutation endpoints separately enforce nonces and capabilities; authenticated HTTP tests reject invalid nonces and unauthorized object access. |
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound` | WARNING ×2 | 4139–4140 | These are existing **core** hooks, `excerpt_length` and `excerpt_more`, applied when rebuilding an attachment-free excerpt. Prefixing them would disconnect the site's existing excerpt customization. They do not declare new plugin globals. Source uses the core hook names deliberately; normal/category/Elementor listing tests exercise the rebuilt excerpts. |

These dispositions explain retained warnings; they do not turn the overall PCP result into a pass while the header error remains.

## PHPStan

PHPStan 2.3.7, WordPress stubs 7.1.2, WordPress extension 2.0.4, level 5, `phpVersion: 70400`, actual Elementor/Pro 4.4.1 scan directories: **17 visible findings**, reduced from 33 by removing unused/redundant code. This is a reviewed finding set, **not a clean PHPStan run**. No suppressions/baseline were added. [Exact messages](phpstan-checkpoint.json).

| Location / identifier | Assessment |
| --- | --- |
| 398 / `argument.type` | Core/stub documentation describes `api_version` as a string, while block metadata uses an integer. The plugin intentionally registers integer 2 on WP 6.0 and integer 3 on WP ≥6.3. Both actual registered `WP_Block_Type` objects are asserted in the native tests. Casting to a string to satisfy this annotation would be the wrong correction. |
| 1195 / `function.impossibleType`; 1243 / `function.alreadyNarrowedType` | `wp_generate_attachment_metadata` is filterable. A filter can return `WP_Error` even though the stub promises an array. A real native injected `WP_Error` verifies that these branches preserve the previous preview and remove the failed new image/sizes. The cleanup array guard must remain safe when metadata is an error. |
| 1589 / `function.alreadyNarrowedType` | The server path also normalizes externally generated/filtered attachment metadata before reading/updating sizes. Retained as a boundary guard rather than trusting an annotation for arbitrary plugin filters. Actual core and direct-Imagick generation/reuse run in both native environments. |
| 1687, 1691 / `function.alreadyNarrowedType` | Optional Imagick method/constant checks preserve fallback behavior when extension capabilities differ. The plugin declares no minimum Imagick version. Native Imagick 3.8.1 exercises the available-method path; absence of these methods on an older extension is not claimed as a separately executed environment. |
| 1764 / `booleanNot.alwaysFalse`; 1834 / `booleanAnd.rightAlwaysTrue` | Stored WordPress metadata may be absent or malformed despite its stub type. Native tests write a string metadata value and an invalid `sizes` value and verify that neither becomes a WordPress preview. Missing/corrupt image files and CDN-filtered image URLs are independently tested. |
| 2901 / `function.alreadyNarrowedType` | The 7.1 stub describes the modern `shortcode_parse_atts` array return. Supported WordPress **6.0** explicitly returns `array|string` and returns a string for unmatched input (`wp-includes/shortcodes.php:531`). Retain the array guard for the declared minimum version. |
| 4048, 4052, 4061 / `isset.property`; 4053, 4062 / `booleanAnd.rightAlwaysTrue`; 4054, 4063 / `function.alreadyNarrowedType` | Optional Elementor singleton/editor/preview capability guards are deliberately broader than the 4.4.1 PHPDocs scanned by this run. They prevent a frontend fallback from assuming an initialized editor/preview object or method merely because a class is loaded. Actual latest Elementor/Pro HTTP initialization and an environment without Elementor are covered; arbitrary historical Elementor releases are not claimed as tested. |

The additional retained `booleanAnd.leftAlwaysTrue` report at line 4025 concerns `get_current()`: Elementor 4.4.1 annotates `Document`, but its manager stores an uninitialized/null `current_doc` and explicitly notes that null state during document switching. The actual native archive-context test with no current Elementor document verifies that the null guard is required. It was restored during final diff review rather than removing it to satisfy the annotation.

## Other checks

- PHPCompatibilityWP, PHP 7.4–8.4: zero errors/warnings on distributed PHP.
- PHP/JavaScript syntax: 29 individually enumerated files, all pass.
- Native and boundary regression commands and their coverage are recorded in [the working audit](readiness-audit.md) and [machine-readable checkpoint](readiness-checkpoint.json).
- `git diff --check` reports four trailing-space lines restored verbatim from the upstream Liberation license. They remain visible and are intentionally retained for exact license-artifact identity; no first-party code whitespace exception was added.
