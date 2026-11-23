# 1.11.52 — documentation separation and publication safety

`README.md` now ends with only the requested development/changelog references. Development commands, environment requirements and fixture guidance are in `development.txt`. All 43 historical release entries are retained in `changelog.txt`, including 1.11.2 and 1.11.3 which were previously present only in `readme.txt`. The WordPress readme uses direct links to these files at this release tag and retains the public plugin/PDF.js source and build references.

## WordPress.org ZIP

The packaging allowlist ships only these root files: `LICENSE`, `readme.txt`, `changelog.txt` and `pdf-thumbnail-inserter.php`. The remaining 225 files are runtime assets/blocks and their resources/licenses. README.md, development.txt, tests, scripts, tools, audit evidence, skills, directory artwork, Git metadata, local archives and deployment configuration are excluded. The packager checks the changelog version.

The 229-file ZIP was installed over the previous version on disposable WordPress 7.1.5 / PHP 8.2.30. Every installed byte matches, and the previous installed README.md is removed. Rebuilding produces the same hash. [Manifest](release-manifest-1.11.52.json).

## Private-data and endpoint review

- Official **Gitleaks 8.30.1**, download checksum verified: **zero findings** in tracked current files, the exact extracted ZIP and all available Git history/refs. Default rules, redacted reports, no custom exclusions, baseline or allow-comments. [Scopes and commands](evidence/documentation-1.11.52/publication-safety.json).
- Additional pattern/manual review found no private production hostnames/IP addresses, workstation/deployment paths, URL credentials or private keys in the ZIP. First-party PHP/JS network paths use WordPress-generated AJAX/REST URLs and local bundled modules. Public author/repository/license metadata is intentional.
- Five `/home/web_user` matches in official PDF.js decoder/worker/source-map resources are Emscripten browser virtual-filesystem defaults. Each containing file matches its documented upstream bytes. The fresh provenance check passes **all 208 files**, artifact integrity and the npm advisory query. [Evidence](evidence/documentation-1.11.52/pdfjs-provenance.json).
- Existing audit/test records contain local tool paths; they remain outside the ZIP. Scanning and manual review cannot guarantee detection of every possible secret format.

## Executed checks and limits

**Plugin Check 2.2.0: 0 errors / 0 warnings**, active exact ZIP, early runner, all 36 default checks and five runtime resource checks. [Output/coverage](plugin-check-1.11.52.json). **41 syntax checks pass**, PHP checked on 7.4.27; **PHPStan 2.3.7 level 5 / PHP 7.4 target: zero findings**; existing Composer-locked **PHPCompatibilityWP PHP 7.4–8.4: zero errors/warnings**. [Commands/results](evidence/documentation-1.11.52/checks.json).

PHP matches 1.11.51 apart from its two version strings; all 225 assets/blocks match byte-for-byte. Packaging/install/removal checks cover this changed deliverable. No new browser session or full minimum/Imagick/concurrency matrix is claimed; prior evidence and the accepted Gutenberg visual limitation remain in the [functional baseline](warnings-1.11.50.md).

ZIP SHA-256: `fa0b1bb839cc5c0ef75b100b01c12ebc00a920af56e00eddb968035acad89d53`. [Checkpoint](documentation-1.11.52.json). The release verification asset records final remote/tag/download comparisons.
