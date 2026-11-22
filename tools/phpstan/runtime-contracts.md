# Runtime contracts, not ignored findings

These analysis-only declarations correct demonstrably inaccurate vendor PHPDocs for the supported WordPress range and the actual Elementor source. They do not execute at runtime, disable a rule, exclude code, introduce an ignore pattern or create a baseline. The PHPStan level remains 5, targeting PHP 7.4. Preserve the real Elementor/Pro scan directories; the stubs amend only the listed declarations.

| Declaration | Source evidence and retained precision |
| --- | --- |
| `register_block_type` argument `api_version` | Core `WP_Block_Type::$api_version` is an integer; the block metadata schema and actual WP 6.0 / 7.1 registrations use integers 2 / 3. The vendor stub incorrectly says string. The rest of its detailed argument shape, first argument and return type are copied unchanged from wordpress-stubs 7.1.2. |
| `wp_generate_attachment_metadata` result | WP 6.0 `wp-admin/includes/image.php:485` ends by returning `apply_filters('wp_generate_attachment_metadata', ...)`. The callback result is not runtime constrained to array. The plugin's native tests inject WP_Error, rejected writes and exceptions and verify safe rollback. The stub retains integer attachment/string file parameters and models the filter result as mixed; runtime validation is required. |
| `wp_get_attachment_metadata` result | WP 6.0 `wp-includes/post.php:6524` reads `get_post_meta` and returns it directly when unfiltered=true; otherwise it applies a filter. Serialized metadata is not schema-constrained. Real malformed-string and malformed-sizes tests require the guards preserved by this mixed return contract. Integer/bool parameters remain checked. |
| `shortcode_parse_atts` result | Supported WP 6.0 `wp-includes/shortcodes.php:531` explicitly documents and returns array|string. The modern stub's array-only result cannot describe this supported minimum version. The string input and array element types remain checked. |
| `Elementor\Plugin::$instance`, `$editor`, `$preview` | Elementor 4.4.1 `includes/plugin.php:57` initializes instance=null. Its editor/preview properties at 243/255 have no initial value and remain null until components initialize. The stub adds only null to the actual concrete class types, rather than widening them to arbitrary objects. The plugin retains initialization guards. |
| `Elementor\Core\Documents_Manager::get_current` | Elementor 4.4.1 `core/documents-manager.php:60` declares an uninitialized current_doc; get_current at 719 returns it directly. Its own switching code preserves a null initial state. The result is Document|null, not Document. A real native archive-context regression verifies this null state and the plugin's guard. |

Separate source corrections remove genuinely redundant method checks on established APIs: Elementor's edit/preview methods are public since 1.0.0; the existing nullable-component checks remain. Imagick calls retain the corresponding constant capability guards and Throwable/finally failure cleanup. Core PDF metadata deferral now removes its temporary filter before final publication instead of mutating a boolean captured by reference.

Run the pinned existing tools from the repository root:

```powershell
& C:/wamp64/bin/php/php8.2.30/php.exe '-dxdebug.mode=off' '-dmemory_limit=1G' dist/readiness/tooling/vendor/phpstan/phpstan/phpstan analyse --configuration=tools/phpstan/phpstan.neon --no-progress --error-format=json
```

The development tools/source packages are deliberately outside the installable ZIP. This configuration binds to the disposable audit tooling (PHPStan 2.3.7, WordPress extension 2.0.4, wordpress-stubs 7.1.2) and the separately obtained Elementor/Pro 4.4.1 source. Source paths and APIs must be rechecked when those dependencies change. No vendor package was edited.

`python scripts/check_phpstan_contracts.py --php C:/wamp64/bin/php/php8.2.30/php.exe` analyzes the deliberately invalid calls in `contract-probe.php`. It succeeds only when all seven wrong parameter values still produce argument.type errors. This guards against accidentally weakening argument validation while correcting return/property contracts. The probe function is never called or shipped. `scripts/check_syntax.py` includes both the probe and the stub in minimum-PHP syntax checks.
