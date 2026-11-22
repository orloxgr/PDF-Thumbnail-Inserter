# Automated findings — release candidate 1.11.49

The exact 229-file candidate ZIP SHA-256 is `ad067fd7ea7c6def25abc8f298c3cfd940c43bdc2473363b625f848f5fec59c8`. It is a new version; the published 1.11.48 artifact is unchanged. All errors/warnings remain visible. No check exclusions, reduced thresholds, PHPStan baseline or ignore rules were added.

## Official Plugin Check 2.2.0

Result: **0 ERROR / 27 justified WARNING**, despite exit code 0. The exact installed candidate was checked on WordPress 7.1.5/PHP 8.2.30 with the target plugin active. The early runtime runner selected 36 checks, including all five enqueued-resource runtime checks. [Full findings and runtime selection](plugin-check-checkpoint.json).

Command, from the repository root, using the owned disposable site:

```powershell
& C:/wamp64/bin/php/php8.2.30/php.exe '-dxdebug.mode=off' '-dmemory_limit=512M' C:/wpcli/wp-cli.phar plugin check pdf-thumbnail-inserter --path=dist/readiness/wp-7.1.5 --require=dist/readiness/runtime-proof.php --format=json
```

`runtime-proof.php` loads the installed `wp-content/plugins/plugin-check/cli.php` before WordPress and records the initialized runner/check names. It does not alter check selection or findings.

All code locations below refer to `pdf-thumbnail-inserter.php` in this candidate.

| Rule | Severity/count | Locations | Disposition and evidence |
| --- | --- | --- | --- |
| `PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound` | WARNING ×1 | 123 | Retained for GitHub ZIP installations and plugin-local translations. The warning specifically concerns automatic WordPress.org language packs; this project is distributed on GitHub. A real synthetic Greek `.mo` file in the plugin's `languages/` path is loaded by the public method on native WordPress, without installing a WordPress.org language pack; the test removes it afterward. Source: `load_textdomain`; test: `tests/readiness-wordpress.php`, “Plugin-local MO translations”. |
| `WordPress.DB.SlowDBQuery.slow_db_query_meta_key` / `slow_db_query_meta_value` | WARNING ×6 | 2549–2550, 2872/2883, 2968–2969 | Retained compatibility lookups of WordPress's `_wp_attached_file`, not a custom metadata store. URL lookup tries canonical relative-path variants and returns one ID. Legacy filename lookup returns at most 10 candidates per variant; loose reference lookup uses at most six tokens and two searches per token, at most 20 candidates each. Real native tests resolve the correct unique Greek filename and query-bearing URL through all three paths. Explicit IDs bypass these fallback searches, and first-PDF resolutions are cached within the request. `LIKE` predicates can still scan matching metadata rows on large libraries: result limits are **not** claimed to bound rows examined, and no unmeasured speed claim is made. Altering core indexes or deleting legacy reference support is outside this correction. The separate paginated maintenance discovery was measured at 3 queries/100 attachments. |
| `WordPress.Security.NonceVerification.Recommended` | WARNING ×6 | 3368–3369 and 3376 | Public read-only viewer query fields. Values are scalar-checked, unslashed, sanitized and passed through origin/port, extension, readable-file and realpath/uploads-containment validation. The handler outputs a viewer with same-origin CSP; it performs no mutation based on these parameters. Requiring a login-bound nonce would break public embedded/shared viewing without providing CSRF protection for a write. Native traversal/foreign-origin tests and a real two-page browser viewer verify the path. |
| `WordPress.Security.NonceVerification.Recommended` | WARNING ×12 | 3690–3696 | Public read-only generated SVG cover request fields. File/shape/title are validated/sanitized and escaped for their individual SVG contexts. The endpoint streams the SVG; it does not save files, options or attachments. Covers must be usable by public image elements without session-bound URLs. Boundary injection/shape tests and actual native/frontend cover tests verify this behavior. Mutation endpoints separately enforce nonces and capabilities; authenticated HTTP tests reject invalid nonces and unauthorized object access. |
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound` | WARNING ×2 | 4283–4284 | These are existing **core** hooks, `excerpt_length` and `excerpt_more`, applied when rebuilding an attachment-free excerpt. Prefixing them would disconnect the site's existing excerpt customization. They do not declare new plugin globals. Source uses the core hook names deliberately; normal/category/Elementor listing tests exercise the rebuilt excerpts. |

The earlier Tested up to header error is resolved by the executed WP 7.1.5 verification and accepted visual exception. There are no remaining PCP errors. The release gate accepts these precisely justified warnings; it does not describe the tool output as warning-free.

## PHPStan

PHPStan 2.3.7, WordPress stubs 7.1.2, WordPress extension 2.0.4, level 5, `phpVersion: 70400`, actual Elementor/Pro 4.4.1 scan directories: **18 visible findings**, reduced from 33 by removing unused/redundant code. This is a reviewed finding set, **not a clean PHPStan run**. No suppressions/baseline were added. [Exact messages](phpstan-checkpoint.json).

| Location / identifier | Assessment |
| --- | --- |
| 404 / `argument.type` | Core/stub documentation describes `api_version` as a string, while block metadata uses an integer. The plugin intentionally registers integer 2 on WP 6.0 and integer 3 on WP ≥6.3. Both actual registered `WP_Block_Type` objects are asserted in the native tests. Casting to a string to satisfy this annotation would be the wrong correction. |
| 1220 / `function.impossibleType`; 1268 / `function.alreadyNarrowedType` | `wp_generate_attachment_metadata` is filterable. A filter can return `WP_Error` even though the stub promises an array. A real native injected `WP_Error` verifies that these branches preserve the previous preview and remove the failed new image/sizes. The cleanup array guard must remain safe when metadata is an error. |
| 1679 / `function.alreadyNarrowedType` | The server path also normalizes externally generated/filtered attachment metadata before reading/updating sizes. Retained as a boundary guard rather than trusting an annotation for arbitrary plugin filters. Actual core and direct-Imagick generation/reuse run in both native environments. |
| 1826, 1830 / `function.alreadyNarrowedType` | Optional Imagick method/constant checks preserve fallback behavior when extension capabilities differ. The plugin declares no minimum Imagick version. Native Imagick 3.8.1 exercises the available-method path; absence of these methods on an older extension is not claimed as a separately executed environment. |
| 1903 / `booleanNot.alwaysFalse`; 1973 / `booleanAnd.rightAlwaysTrue` | Stored WordPress metadata may be absent or malformed despite its stub type. Native tests write a string metadata value and an invalid `sizes` value and verify that neither becomes a WordPress preview. Missing/corrupt image files and CDN-filtered image URLs are independently tested. |
| 3040 / `function.alreadyNarrowedType` | The 7.1 stub describes the modern `shortcode_parse_atts` array return. Supported WordPress **6.0** explicitly returns `array|string` and returns a string for unmatched input (`wp-includes/shortcodes.php:531`). Retain the array guard for the declared minimum version. |
| 4192, 4196, 4205 / `isset.property`; 4197, 4206 / `booleanAnd.rightAlwaysTrue`; 4198, 4207 / `function.alreadyNarrowedType` | Optional Elementor singleton/editor/preview capability guards are deliberately broader than the 4.4.1 PHPDocs scanned by this run. They prevent a frontend fallback from assuming an initialized editor/preview object or method merely because a class is loaded. Actual latest Elementor/Pro HTTP initialization and an environment without Elementor are covered; arbitrary historical Elementor releases are not claimed as tested. |

The additional retained `booleanAnd.leftAlwaysTrue` report at line 4172 concerns `get_current()`: Elementor 4.4.1 annotates `Document`, but its manager stores an uninitialized/null `current_doc` and explicitly notes that null state during document switching. The actual native archive-context test with no current Elementor document verifies that the null guard is required. It was restored during final diff review rather than removing it to satisfy the annotation.

## Other checks

- PHPCompatibilityWP, PHP 7.4–8.4: zero errors/warnings on distributed PHP.
- PHP/JavaScript syntax: 35 individually enumerated files, all pass.
- Native and boundary regression commands and their coverage are recorded in [the working audit](readiness-audit.md) and [machine-readable checkpoint](readiness-checkpoint.json).
- `git diff --check` reports four trailing-space lines restored verbatim from the upstream Liberation license. They remain visible and are intentionally retained for exact license-artifact identity; no first-party code whitespace exception was added.

The additional finding at **1672 / booleanAnd.leftAlwaysTrue** concerns the captured `$defer_writes` flag in the temporary metadata filter. It is true while core builds sizes, and explicitly becomes false before final publication. PHPStan's closure-flow inference treats it as its initial value. The real core metadata rejection/exception/corruption tests on both WordPress versions require this distinction and verify final publication and rollback. The flag and scoped filter must remain; removing the guard to silence the warning would prevent final persistence.
