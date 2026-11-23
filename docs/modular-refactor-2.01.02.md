# Modular PHP refactor — 2.01.02

The entrypoint now contains 26 lines instead of 4739. Eighteen internal subsystem classes own the former business logic; a request-state object holds the shared caches/locks/contexts, and a coordinator preserves the public `PDF_Thumbnail_Inserter` facade. There are 20 PHP includes; the largest is 525 lines. The responsibility map is in [development.txt](../development.txt#php-architecture).

## Review and implementation

| Finding | Evidence / resolution |
| --- | --- |
| One class mixed UI, file validation, storage, generation, discovery, rendering and integrations | All 150 business methods moved to the corresponding subsystem under `includes/`. Typed dependency wiring completes before hook registration. No magic dispatch, dependency framework or service locator. |
| Moving methods can silently change callback identity | The facade remains the registered callback, including late settings/block/buffering callbacks and metadata-hook removal/restoration. Native tests assert exact settings/block identity and observe the upload callback being absent during core metadata generation, then restored at priority 20. |
| Root-relative paths can break after extraction | Root constants stay in the guarded entrypoint. Action links and language catalogues use the plugin root. The bootstrap contract and fresh-process translation tests execute these paths. |
| Splitting caches across classes can create stale results or lose deletion locks | A single request-state object is shared explicitly. Existing cache, lookup invalidation, category visual, excerpt nesting and cross-process writer/deleter tests pass on both runtime versions. No option/meta/schema migration is introduced. |
| Existing tools/package would miss new files | Syntax checks and PHPStan now traverse `includes/`; packaging includes Git-tracked runtime files and refuses an unstaged PHP include. Every installed/archive byte is checked. |
| Multi-file PHPStan needs independently discoverable path constants and self-contained stubs | An analysis-only bootstrap describes the three WordPress-computed strings. Existing source-backed stubs now declare their referenced classes and explicit nested array element types. Level 5 / PHP 7.4 target retained; seven wrong-argument probes still fail as intended. No baseline, ignores, skips or lower thresholds. |

The 54 public method signatures/defaults (including `instance()`), all non-version constants and 45 ordered hook registrations match a contract captured from the actual published 2.01.01 ZIP. All 150 moved method bodies match that release after only explicit ownership/state/callback/constant/translation-path substitutions. Inline markup and all 225 assets/block files are unchanged. Private test access now resolves the owning component; production does not expose a service lookup API.

This remains a modular WordPress plugin with synchronous request-local collaborators. Existing dependency cycles are explicitly wired; it is not described as independently deployable services. The benefit is separation of responsibilities and smaller files. No speed or memory improvement is claimed.

## Executed verification

- WordPress 6.0 / PHP 7.4.27 / MariaDB 11.4.12: 270 assertions without Imagick, 286 with real Imagick/Ghostscript.
- WordPress 7.1.5 / PHP 8.2.30 / SQLite integration 3.1.3, Elementor/Pro 4.4.1 and TheGov 2.0.20/Core 2.0.17: 271 without Imagick, 287 with it. Total: **1,114 native assertions**.
- Both runtimes: architecture, preview, security and readiness regression suites; real cross-process preview/deletion locks, bounded discovery, authenticated upload HTTP, lookup query budgets/invalidation, excerpt/routing/SQL pattern regressions, anonymous viewer/cover HTTP, locale switching and resumable maintenance.
- Real maintenance HTTP: 35 assertions. All six Node suites pass (editor, browser upload/maintenance, preview renderer, server maintenance, viewer); their browser/PDF boundaries are mocked.
- Fresh official WordPress 7.1.5 sites, checksum-verified: **59 fresh lifecycle assertions** across install/activate, legacy options, deactivate, uninstall, reinstall and cleanup; **56 upgrade assertions** using the actual published 2.01.01 ZIP, retaining settings, media and serialized content.
- PHP 7.4 syntax / all distributed and test JS: 64 checks. PHPStan 2.3.7 level 5: 0 findings; seven negative contract probes pass. PHPCompatibilityWP targeting 7.4–8.4: 0 errors / 0 warnings across all 21 runtime PHP files.
- **Official Plugin Check 2.2.0: 0 errors / 0 warnings**, on the exact installed ZIP with the plugin active. Early runtime initialization selected all 36 default checks, including five runtime checks. No exclusions or new suppressions.
- Direct execution of the entrypoint and all 20 includes without ABSPATH: no output or errors. Repeated normal bootstrap creates no duplicate instance/hooks.
- 250-file ZIP: deterministic rebuild, integrity, exact source bytes and installed bytes on both labs, five allowed root files, all runtime includes present; README.md, tests, tools, skills and other development files excluded.
- Gitleaks 8.30.1: no findings in tracked-file snapshot, extracted ZIP or Git history. Private infrastructure/deployment patterns absent from the ZIP. Unchanged official PDF.js virtual filesystem defaults remain provenance-verified.

Commands and full results are in [the evidence directory](evidence/modular-2.01.02/) and [machine-readable report](modular-refactor-2.01.02.json). The main commands were `python scripts/check_syntax.py --php C:/wamp64/bin/php/php7.4.27/php.exe`, both native PHP executables against the suites under `tests/`, six `node tests/*-regression.cjs` runs, `phpstan analyse --configuration=tools/phpstan/phpstan.neon --no-progress --error-format=json`, `python scripts/check_phpstan_contracts.py`, `phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 7.4-8.4 --report=json pdf-thumbnail-inserter.php includes`, `wp plugin check pdf-thumbnail-inserter --require=<early-runtime-loader> --format=json`, and `python scripts/package.py`. Every WordPress mutation was confined to disposable loopback labs with cron/automatic updates disabled.

## Limits and artifact

No new browser session was run. Prior browser evidence applies to the unchanged assets; the previously accepted WordPress 7.1 Gutenberg visual iframe limitation remains. PHP 7.4.27 and 8.2.30 were executed; the remaining PHP compatibility range was checked statically. Optional integration results cover the pinned installed versions, not every theme/plugin combination. No live sites were modified and no WordPress.org submission is implied.

ZIP SHA-256: `cbff51970675dda721c13450572a5d6526a95939b8630bd6f6a80b8fa16f857c`. [Manifest](release-manifest-2.01.02.json) and [Plugin Check selection/output](plugin-check-2.01.02.json). Remote commit, annotated tag and downloaded release-asset verification will be attached to the GitHub Release as `release-verification.json`.
