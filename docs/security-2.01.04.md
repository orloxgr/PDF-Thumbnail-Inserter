# Annotation-free security and file operations — 2.01.04

## Findings and scope

The published 2.01.03 ZIP contains 27 `phpcs:ignore` annotations. Rechecking that exact artifact with annotations ignored and severity 1 reports **23 errors and 5 warnings**: 22 escaping findings, one raw-upload input finding and five native-file-operation recommendations. The earlier clean Plugin Check reports honored those annotations; they must not be treated as evidence of annotation-free checks. The static findings are not 28 confirmed exploitable vulnerabilities. [Exact baseline and rules](evidence/security-2.01.04/baseline.json).

## Corrections

| Files | Cause | Implemented correction |
| --- | --- | --- |
| `includes/class-cards.php`, `class-html.php` | Raw target/rel fragments reached HTML output; buffered media/card markup relied on producer escaping and suppressions. | Parse the legacy fragments into allowed target and rel values, reject malformed fragments, add protective tokens for new tabs, and escape individual values at the output. Apply a narrow real WordPress KSES schema at media/card echo boundaries. The public facade/shortcode API is retained. |
| `includes/class-elementor.php`, `class-html.php` | Buffered media HTML was echoed with annotations. | Apply the same explicit KSES schema at both widget media outputs. Preserve responsive images, accessible wrappers, local PDF URL data and canvas; prohibit event attributes, executable elements and unrelated attributes. No global KSES filters or permissions are changed. |
| `includes/class-viewer.php`, `class-admin.php` | Buffered SVG output needed an auditable output boundary. | Use a fixed SVG template with escaping for each text/attribute/integer. The existing string-returning cover helper buffers this same template; admin and public resource routes render it directly. Actual WordPress 7.1.5 testing caught HTML KSES lowercasing the case-sensitive `viewBox`; direct template output preserves geometry without whitelisting arbitrary SVG input. |
| `includes/class-media.php` | The complete raw upload array crossed the AJAX boundary before downstream validation. | Read and shape-check only the five expected single-file fields and sanitize each value at ingress. Keep downstream JPEG/PNG type, size, dimension, genuine HTTP upload and object/capability/nonce checks. Do not unslash `$_FILES`, which WordPress does not slash. Actual missing/nested multipart uploads return HTTP 400. |
| `includes/class-preview-storage.php`, `class-runtime-state.php` | Native resource operations required file-function annotations. | Manage persistent OS advisory locks through `SplFileObject`, explicit unlocking and destruction on success, contention, exceptions and deletion cleanup. Persistent lock-file/inode semantics remain; real cross-process writer/deletion tests pass. |
| `includes/class-preview-generation.php` | Direct rename was annotated; core move can copy on failure, and newer core recursively pre-deletes destinations when overwrite is requested. | Use the core local filesystem move without overwrite through an adapter that refuses copying. Remove only a corrupt regular preview under the PDF lock; reject directory/symlink destinations. Failure cleanup removes the temporary image. Real corruption replacement, destination preservation, server generation, publication rollback and concurrency paths pass. |
| `scripts/check_security.py` | The release gate did not reject existing suppression annotations. | Reject every runtime PHPCS suppression annotation, then run the four relevant WPCS rule groups with annotations ignored and severity 1. No custom escaper/sanitizer registration, baseline, threshold reduction or file exclusion. |

## Executed verification on the final ZIP

- **0 runtime PHPCS suppressions**, all 22 PHP files; WPCS escaping, validated/sanitized input, nonce and alternative-function checks: **0 errors / 0 warnings**.
- Official **Plugin Check 2.2.0: 0 errors / 0 warnings**, exact installed 251-file ZIP, active plugin, all 36 default checks including five correctly initialized runtime checks. [Coverage and result](plugin-check-2.01.04.json).
- **62 new real WordPress security assertions** (31 per environment): hostile attribute fragments and shortcode fields, dangerous URL protocols, safe new/same-tab options, real KSES removal of executable media markup, intact accessible canvases/status/data, responsive images, valid portrait/landscape SVG geometry and escaped text.
- **1,116 native assertions**, including replacement of corrupt standalone previews and failed atomic-move destination preservation/temporary cleanup, real concurrent writers/deletions, HTTP uploads/public resources/translations/queries/maintenance; **37 actual HTTP maintenance assertions**, including missing/nested upload rejection; **33 real Elementor Pro skin assertions**. All six Node suites pass. The boundary doubles in the older standalone tests are distinct from the new actual WordPress KSES tests.
- **59 fresh lifecycle** and **56 upgrade assertions from the actual published 2.01.03 ZIP**, on new checksum-verified WordPress 7.1.5 sites; settings/media/content retained.
- **67 syntax checks**, PHPStan level 5 with zero findings and seven negative argument-contract probes; PHPCompatibilityWP 7.4–8.4: zero errors/warnings.
- Reproducible ZIP, installation and byte identity in both labs; clean Gitleaks scans and private deployment-pattern review. Development tools/tests/evidence/README.md are excluded from the ZIP.

Environments: WordPress 6.0 / PHP 7.4.27 / MariaDB 11.4.12 and WordPress 7.1.5 / PHP 8.2.30 / SQLite integration 3.1.3, each without/with Imagick and Ghostscript; Elementor/Pro 4.4.1. Commands and outputs: [evidence](evidence/security-2.01.04/), [machine-readable report](security-2.01.04.json).

No new full-browser visual session was performed for this release. The previous 2.01.03 visual proof remains available; this release verifies changed output with real WordPress KSES, DOM/XML parsing, real skins and HTTP viewer/cover responses. The user-accepted WordPress 7.1 Gutenberg editor iframe limitation remains. Production sites were not modified. Static compatibility checks do not replace execution on every PHP version, and clean tools are not proof against every possible vulnerability.

ZIP SHA-256: `d9c3f8a3a358e0448c368ba5f03a2b6cdce6c1f5cf2198bd622410c2b39b553e`. [Per-file manifest](release-manifest-2.01.04.json). The release's `release-verification.json` records remote main/tag identity and verification of the re-downloaded published asset.
