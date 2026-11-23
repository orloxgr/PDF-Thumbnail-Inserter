# 1.02.01 — requested readme layout

The release number is explicitly chosen by the owner. PHP version_compare treats 1.02.01 as lower than 1.11.52, so installations on the previous numbering require a manual ZIP installation rather than an automatic version upgrade.

The GitHub README starts with the plugin description and has no version/author/license banner. Both readmes end with exactly these plain-text references, without Markdown links:

```text
For development checks see development.txt
For changelog see changelog.txt
```

The entire source/build section moved from readme.txt to development.txt, with all upstream source, build and license provenance retained. **development.txt, readme.txt and changelog.txt are included inside the plugin ZIP. README.md is excluded.** The five root files are these three text files, LICENSE and the PHP entrypoint; 225 assets/blocks complete the 230-file package.

Executed on the exact ZIP: successful install/activation on disposable WordPress 7.1.5/PHP 8.2.30; every installed file compared; **Plugin Check 2.2.0: zero errors/warnings**, all 36 default checks and five runtime checks; 41 syntax checks; PHPStan level 5/PHP 7.4 target and PHPCompatibilityWP 7.4–8.4 clean. Gitleaks 8.30.1 has zero findings in current tracked files, the exact extracted ZIP and Git history. Known official PDF.js virtual paths retain verified upstream bytes. Packaging rebuild is identical, source instructions are preserved verbatim apart from the release tag, and both plain-reference/banner requirements were asserted.

Only version strings change in PHP; all 225 assets/blocks match 1.11.52. No new browser or full minimum/Imagick matrix is claimed; prior functional evidence remains applicable. Secret scans cannot guarantee detection of every possible format.

[Checks and scope](readme-1.02.01.json), [Plugin Check output](plugin-check-1.02.01.json), [manifest](release-manifest-1.02.01.json). The release verification asset records final remote/tag/download identity.
