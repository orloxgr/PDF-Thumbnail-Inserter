# 1.11.51 — directory source documentation and icon

This release adds the source/build documentation requested by WordPress.org Guideline 4 and the owner-selected text-document icon. `readme.txt` now directly links to the plugin's release source and packaging instructions, PDF.js 6.3.289 readable source and build tools, its official prebuilt distribution and the exact supplemental QuickJS license-notice source. Public upstream tags/files were checked through GitHub's API.

The icon is supplied in `.wordpress-org/` as 128 × 128 and 256 × 256 PNG files, respectively 10,970 and 41,253 bytes. The original generated raster, selected prompt, distribution license and WordPress.org SVN upload instructions are retained there. The two icon exports were visually inspected and their PNG signatures, dimensions and sizes checked. Directory artwork stays outside the installable ZIP. Publishing this GitHub release does not submit the plugin or artwork to WordPress.org.

## Executed verification

- Official **Plugin Check 2.2.0: 0 errors / 0 warnings**, all 36 default checks and five runtime resource checks, active installed ZIP, early runner initialized. [Command, output and coverage](plugin-check-1.11.51.json).
- **41 PHP/JavaScript syntax checks**, using PHP 7.4.27 for PHP; all six JavaScript regression suites; preview/security/readiness mock suites on PHP 7.4.27 and 8.2.30. [Commands and results](evidence/directory-1.11.51/checks.json).
- **PHPStan 2.3.7: zero findings**, unchanged level 5 and PHP 7.4 target with the existing source-backed dependency contracts. All seven deliberately invalid arguments remain rejected in the negative contract probe.
- **PHPCompatibilityWP: zero errors / warnings**, PHP 7.4–8.4 static analysis. The previous tool location was unavailable; a separate Composer-locked installation supplied PHPCompatibilityWP 3.0.0-alpha3, PHPCompatibility `dev-develop` at the exact commit in the lock file and PHPCS 4.0.4. Initial tool-path/dependency-resolution failures were resolved before the passing run. No existing Plugin Check dependencies or runtime code were changed. [Requirements](evidence/directory-1.11.51/compatibility-composer.json), [exact dependency lock](evidence/directory-1.11.51/compatibility-composer.lock), [result](evidence/directory-1.11.51/compatibility.json).
- **269 native readiness assertions** on WordPress 7.1.5 / PHP 8.2.30 without Imagick. [Result](evidence/directory-1.11.51/readiness.json).
- Two new disposable WordPress 7.1.5 / SQLite sites with core checksum verification: **59 fresh lifecycle assertions** and **56 published 1.11.50 → 1.11.51 upgrade assertions**. Actual activation, deactivation, uninstall/file removal and reinstall pass; settings, media bytes, ownership and stored shortcode/block content survive. [Lifecycle evidence](evidence/directory-1.11.51/lifecycle.json).
- Reproducible packaging, ZIP integrity, 229-file manifest and every installed file byte checked. Only `README.md`, `readme.txt` and the main PHP file differ from the previous ZIP. The PHP file matches the previous release exactly after replacing its version strings; all 225 assets/blocks are unchanged. [Scope proof](evidence/directory-1.11.51/scope.json), [manifest](release-manifest-1.11.51.json).

No new ignore comments, skipped checks, baselines or lowered analysis levels were added. No new unit tests were needed for documentation, artwork and version metadata. The existing packaging and lifecycle checks protect the changed deliverable.

## Scope and limits

The full minimum WordPress 6.0, native Imagick, concurrency, HTTP and browser verification remains the [1.11.50 functional baseline](warnings-1.11.50.md). Those environments were not all rerun for this documentation/artwork release; unchanged code/assets were verified rather than claimed as newly browser-tested. The previously accepted Gutenberg 7.1 visual iframe limitation remains. A clean Plugin Check does not constitute directory-team approval or prove name/account ownership.

The installable ZIP SHA-256 is `b7a3c6ac15d8871ee0a63f51be835cd6f8ac3d7528fe838dd5c6f97e76d85117`. The release's separate verification asset records the final remote commit, tag, downloaded artifacts and hashes. [Machine-readable checkpoint](directory-preparation-1.11.51.json).
