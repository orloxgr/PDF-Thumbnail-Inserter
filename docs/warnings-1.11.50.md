# 1.11.50 — warning corrections and verification

**Plugin Check 2.2.0: 0 errors / 0 warnings. PHPStan 2.3.7: 0 findings**, level 5, PHP target 7.4. The exact ZIP was installed and compared byte-for-byte on four disposable sites. No new suppression comments, ignore patterns, baseline, excluded runtime code, skip flags or lowered thresholds were introduced. Existing unrelated annotations from 1.11.49 were not expanded to cover these warnings. The previous 27 Plugin Check and 18 PHPStan findings remain available in the immutable [1.11.49 audit](https://github.com/orloxgr/PDF-Thumbnail-Inserter/blob/v1.11.49/docs/automated-findings.md).

Version: 1.11.50. ZIP: `pdf-thumbnail-inserter-1.11.50.zip`, 229 runtime files. SHA-256: `506df36dad8d57287ddf32aab99a77db2fc3ac1a8c83407234bceed2ba2cccbf`. [Full manifest](release-manifest-1.11.50.json), [machine-readable result](warnings-1.11.50.json). The version tag identifies the release commit; the release's separate verification asset records the final remote/tag/download checks.

## All 27 Plugin Check warnings

| Previous rule/count | Root correction | Executed evidence |
| --- | --- | --- |
| `NonceVerification.Recommended`, viewer ×6 and cover ×12 | Public read-only resources now have explicitly registered WordPress query variables and one input-validation boundary in `register_preview_query_vars` / `parse_public_preview_request`. Handlers consume validated state, not raw GET globals. Routing-only fields are removed before the main content query; variables already registered by another plugin are preserved. Existing public URLs and anonymous access remain. A session nonce is not added to cacheable public resources; mutation handlers retain their real nonce/object-capability checks. | Real anonymous HTTP against both WordPress versions: Greek/ampersand PDF URL, viewer CSP, valid SVG/XML and uppercase portrait input; foreign, traversal and nested viewer inputs return 404; empty/nested cover selectors return 404. An ordinary page with incidental file/title arguments still renders its own content. Settings and attachment metadata are unchanged after requests; no image/error marker is created. |
| `SlowDBQuery.meta_key/meta_value` ×6 | Repeated per-variant and per-token queries are replaced by one scoped, prepared and ranked WP_Query batch. Exact variants retain caller priority; substring patterns escape literal `%` and `_`. Candidates' posts/metadata are primed together. Object-cache keys include core post changes and an attachment-path generation; add/update/delete metadata events invalidate matches and misses. Scoped clauses are removed in finally. | On the same filename-only fixture on WP 6.0/PHP 7.4 and WP 7.1.5/PHP 8.2: **15 → 5 cold queries and 6 → 1 metadata LIKE searches**. WP 6.0 warm calls improve **12 → 0 queries**; WP 7.1 was already 0 warm queries and stays 0. Real filename rename/restore, deletion, literal wildcard/injection strings, exact-variant precedence and unrelated-query isolation tests pass. |
| `load_plugin_textdomainFound` ×1 | Modern core's public textdomain registry provides just-in-time loading. The WP 6.0 compatibility path uses its actual supported APIs, gives installed language packs priority and loads a plugin-local MO only as fallback. Locale-change handling preserves both switching and restoration. The public loader method remains available. | Fresh PHP/WordPress processes, real temporary MO files, both supported endpoints: language-pack priority, GitHub-local fallback and absent catalogues; each switches to Greek and restores English twice. All 30 assertions pass. The initial WP 6.0 priority failure was corrected, not ignored. Fixtures refuse to replace existing files and are removed afterward. |
| `NonPrefixedHooknameFound` ×2 | Normal automatic excerpts now clean attachment sources inside the existing core excerpt content pipeline. Core applies its own excerpt_length/excerpt_more hooks and trims once; the plugin no longer reapplies those hooks itself. Nested excerpt contexts preserve the actual requested post. Manual excerpts remain unchanged. The old public callback is retained as an adapter for explicit callers. | Actual WordPress hooks on both sites: custom three-word length and custom suffix, attachment-label removal, original post/object retention, exactly one the_content and one wp_trim_excerpt pass, nested/manual excerpts, and restoration of the outer context. This avoids a second Elementor/theme content pass. |

The filename correction is a measured reduction in repeated queries/joins, with reusable cache results; it does **not** claim that a cold substring search has become indexed or examines zero metadata rows. It introduces no custom table, persistent option, cron job or eager full-library index build.

## PHPStan: source changes and accurate contracts

The clean result uses the tracked [unchanged-level configuration](../tools/phpstan/phpstan.neon) and [source-backed contract corrections](../tools/phpstan/runtime-contracts.md). This is explicitly disclosed; the vendor's inaccurate PHPDocs were not treated as runtime truth.

- Core metadata deferral removes its temporary filter before final publication, replacing the captured mutable boolean. Actual write-rejection, exception, corruption and rollback scenarios still pass with real Imagick/Ghostscript on both endpoint environments.
- Redundant method checks on established Imagick/Elementor APIs were removed. Constant capability checks, nullable component/document initialization guards and Throwable/finally cleanup remain. The Elementor edit/preview methods are public since 1.0.0.
- WordPress 7.1.2 vendor stubs incorrectly describe block api_version as string; the corrected signature uses integer and keeps the rest of its detailed parameter shape. Both actual core versions register the expected integer API version.
- Metadata generation is filterable and persisted metadata can actually be a scalar; its analysis return contract is mixed so runtime boundary checks remain necessary. The supported WP 6.0 shortcode parser explicitly returns array|string. Elementor's singleton/editor/preview/current document can actually be null before initialization. The correction retains the actual concrete class types plus null, rather than broadening them to arbitrary objects.
- A separate negative analysis probe deliberately passes seven wrong arguments. All seven still produce argument.type errors. This confirms that return/property corrections did not disable argument validation. [Probe result](evidence/warnings-1.11.50/phpstan-contract-probe.json).

No vendor source package was edited. Tools/stubs/probes are development files excluded by the packaging allowlist. [Raw clean PHPStan result](phpstan-1.11.50.json).

## Final installed-ZIP checks

| Check | Result |
| --- | --- |
| Official Plugin Check on exact active ZIP | **0 errors / 0 warnings**, all 36 default checks, early runner initialized and all five runtime resource checks selected. [Output and coverage](plugin-check-1.11.50.json). |
| Native readiness matrix | **1,102 assertions pass**: WP 6.0/PHP 7.4.27, 268 without Imagick / 282 with; WP 7.1.5/PHP 8.2.30, 269 without / 283 with. |
| New warning-specific contracts | 17 native assertions and 13 actual public HTTP assertions per environment; 15 real translation assertions per environment; filename query-budget/invalidation regressions pass. |
| Existing regressions | All PHP boundary suites, actual two-process lock/deletion/crash recovery, discovery budget, server maintenance, authenticated uploads, 35 authenticated maintenance HTTP assertions and all six Node suites pass. |
| Fresh exact-ZIP lifecycle | **59 assertions pass** on a separate local WP 7.1.5: absent plugin/options, install/activate/defaults, migration, deactivate, actual uninstall/file removal, reinstall and cleanup. Official core checksums pass. |
| Actual published upgrade | A new isolated site first installs the immutable published 1.11.49 ZIP (SHA-256 `ad067fd7ea7c6def25abc8f298c3cfd940c43bdc2473363b625f848f5fec59c8`). Baseline bytes/settings/media/content/ownership are verified, then the exact 1.11.50 ZIP is installed. Upgrade and cleanup assertions pass. |
| Syntax | **41 files pass** on minimum PHP and Node: 19 PHP/stub/probe files and 22 JS/MJS/CJS files. No bundled runtime JS is excluded. |
| PHPCompatibilityWP 7.4–8.4 | **0 errors / 0 warnings** on distributed PHP. |
| Dependencies and packaging | All 208 upstream library/resource/notice comparisons pass; npm advisory result is empty. Every assets/blocks byte is unchanged from verified 1.11.49. The deterministic ZIP manifest/integrity and four installed copies match. |

Raw records: [evidence/warnings-1.11.50](evidence/warnings-1.11.50/), [commands and results](evidence/warnings-1.11.50/tests.json). Sites use isolated databases, local environment type and disabled cron/automatic updates. Imagick 3.8.1 / ImageMagick 7.1.1-46 / Ghostscript 10.04.0 are loaded per process. The latest site retains actual Elementor/Pro 4.4.1 and TheGov 2.0.20/Core 2.0.17. No live site or global WAMP configuration was changed; existing user ZIPs are preserved.

## Reproduction

From the repository root, with its owned disposable sites and existing pinned tooling:

```powershell
python scripts/check_syntax.py --php C:/wamp64/bin/php/php7.4.27/php.exe
python scripts/check_phpstan_contracts.py --php C:/wamp64/bin/php/php8.2.30/php.exe
& C:/wamp64/bin/php/php8.2.30/php.exe '-dxdebug.mode=off' '-dmemory_limit=1G' dist/readiness/tooling/vendor/phpstan/phpstan/phpstan analyse --configuration=tools/phpstan/phpstan.neon --no-progress --error-format=json
& C:/wamp64/bin/php/php8.2.30/php.exe '-dxdebug.mode=off' '-dmemory_limit=512M' C:/wpcli/wp-cli.phar plugin check pdf-thumbnail-inserter --path=dist/readiness/wp-7.1.5 --require=dist/readiness/runtime-proof.php --format=json
python scripts/package.py
```

`runtime-proof.php` loads the installed Plugin Check cli.php before WordPress and records its runner/check selection; it changes no rules. `PTI_TEST_DISPOSABLE=true` is mandatory for the native/HTTP tests listed in README; pass only an explicitly owned disposable root. Metadata fault tests require the documented Imagick extension and Ghostscript process PATH. Never run these fixture tests on production.

The plugin-development, directory-guidelines/Plugin Check, performance and PHPStan skills guided the corrections. Core source was checked against the declared minimum instead of blindly applying newer APIs. Measurements preceded the query rewrite; exact-ZIP tests followed it.

## Verification limits

The original [69-requirement baseline matrix](https://github.com/orloxgr/PDF-Thumbnail-Inserter/blob/v1.11.49/docs/requirements-verification.json) and [full audit](https://github.com/orloxgr/PDF-Thumbnail-Inserter/blob/v1.11.49/docs/readiness-audit.md) retain their precise recorded layers. Real prior browser evidence is reused only for unchanged JS/CSS assets; new server/routing/excerpt behavior has the new actual WordPress/HTTP tests, not a claimed new browser session. The previously accepted WordPress 7.1 Gutenberg visual iframe exception remains. Windows real symlink creation, multisite, all browser engines and PHP versions beyond 7.4.27/8.2.30 were not executed; compatibility through 8.4 is static. A clean tool result does not expand those claims.
