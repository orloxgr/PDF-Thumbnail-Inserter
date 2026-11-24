"""Require annotation-free runtime PHP and run the WordPress security sniffs."""
import argparse
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--phpcs', required=True)
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    files = [ROOT / 'pdf-thumbnail-inserter.php', *sorted((ROOT / 'includes').rglob('*.php'))]
    for path in files:
        if re.search(r'phpcs\s*:\s*(?:ignore|disable|enable)|@codingStandardsIgnore', path.read_text(encoding='utf-8'), re.I):
            raise SystemExit('FAIL: coding-standard suppression in ' + str(path.relative_to(ROOT)))
    result = subprocess.run([args.php, '-dxdebug.mode=off', args.phpcs, '--standard=WordPress',
        '--sniffs=WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification,WordPress.WP.AlternativeFunctions',
        '--ignore-annotations', '--severity=1', '--report=json', *map(str, files)], cwd=ROOT, capture_output=True)
    if result.stderr:
        raise SystemExit(result.stderr.decode('utf-8', 'replace'))
    report = json.loads(result.stdout)
    report['runtime_files'] = len(files)
    report['suppressions'] = 0
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    if result.returncode or report['totals']['errors'] or report['totals']['warnings']:
        print(json.dumps(report, indent=2))
        raise SystemExit(1)
    print(f'PASS: {len(files)} runtime PHP files; 0 suppressions; WPCS escaping, input validation/sanitization, nonce and alternative-function checks: 0 errors / 0 warnings (annotations ignored).')

if __name__ == '__main__':
    main()
