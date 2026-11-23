# PDF Thumbnail Inserter — current readiness

## Version 1.11.52

**Plugin Check: 0 errors / 0 warnings.** Development checks and history now live in `development.txt` / `changelog.txt`. The WordPress.org ZIP excludes README.md and development documentation. Current tracked files, the exact ZIP and Git history have clean redacted Gitleaks scans plus manual endpoint/deployment review.

- [Changes, privacy review, checks and limits](documentation-1.11.52.md).
- [Exact installed-ZIP Plugin Check](plugin-check-1.11.52.json), [manifest](release-manifest-1.11.52.json) and [checkpoint](documentation-1.11.52.json).
- [Secret-scan, source-provenance, syntax and analysis evidence](evidence/documentation-1.11.52/).

## Directory preparation 1.11.51

**Plugin Check: 0 errors / 0 warnings.** This documentation/artwork release adds direct source/build references to `readme.txt` and the selected WordPress.org text-document icon. Its PHP behavior and all distributed assets/blocks match the 1.11.50 functional baseline; only version metadata changes in PHP.

- [Current changes, executed checks and verification limits](directory-preparation-1.11.51.md).
- [Exact installed-ZIP Plugin Check and runtime selection](plugin-check-1.11.51.json).
- [229-file manifest](release-manifest-1.11.51.json) and [machine-readable checkpoint](directory-preparation-1.11.51.json).
- [New syntax, regressions, analysis, native WordPress and fresh/upgrade lifecycle evidence](evidence/directory-1.11.51/).
- [Directory icon, original source, generation prompt and upload instructions](../.wordpress-org/README.md).

## Functional baseline 1.11.50

**Plugin Check: 0 errors / 0 warnings. PHPStan: 0 findings** at unchanged level 5 / PHP 7.4 target, with source-verified dependency contracts. No new ignores, suppressions, baseline, exclusions, skip flags or lowered thresholds.

- [Full correction report, measurements, commands and verification limits](warnings-1.11.50.md).
- [Exact Plugin Check output and 36-check / five-runtime-check selection](plugin-check-1.11.50.json).
- [PHPStan result](phpstan-1.11.50.json) and [actual contract corrections](../tools/phpstan/runtime-contracts.md).
- [Current ZIP manifest and hashes](release-manifest-1.11.50.json).
- [Native/HTTP/Node, lifecycle and actual published-upgrade evidence](evidence/warnings-1.11.50/).
- [Machine-readable release verification checkpoint](warnings-1.11.50.json).

The 1.11.50 native matrix passes 1,102 assertions on WordPress 6.0/PHP 7.4.27 and WordPress 7.1.5/PHP 8.2.30, both without/with Imagick. Fresh final-ZIP lifecycle passes 59 assertions; the actual published 1.11.49 ZIP upgrades with settings, media and content retained. New warning-specific tests cover real HTTP routes, repeated locale switching, core excerpt hooks/nesting and measured lookup/invalidation. All JS/CSS asset bytes are unchanged from the browser-tested baseline.

## Historical full audit baseline

The [full 1.11.49 audit](readiness-audit-1.11.49.md), [69-requirement baseline matrix](requirements-verification.json), earlier tool checkpoints and evidence are preserved with their original version/counts. Their old warnings were subsequently corrected as documented above. The immutable v1.11.49 tag is the authoritative source for their old line numbers.

The previously accepted Gutenberg 7.1 visual iframe exception remains. The current report distinguishes new real WordPress/HTTP tests, unchanged-asset prior browser evidence, mocks, static compatibility and environment limitations; clean automated tools do not imply every browser/PHP/theme combination was executed.
