"""Confirm that source-corrected return contracts still reject seven invalid inputs."""
import argparse
import json
from pathlib import Path
import subprocess

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--php', default='php')
parser.add_argument('--report', type=Path)
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
command = [args.php, '-dxdebug.mode=off', '-dmemory_limit=1G',
           str(root / 'dist/readiness/tooling/vendor/phpstan/phpstan/phpstan'), 'analyse',
           '--configuration=' + str(root / 'tools/phpstan/phpstan.neon'),
           '--error-format=json', '--no-progress', str(root / 'tools/phpstan/contract-probe.php')]
result = subprocess.run(command, cwd=root, capture_output=True, text=True, encoding='utf-8', timeout=120)
data = json.loads(result.stdout)
messages = [m for f in data['files'].values() for m in f['messages']]
passed = result.returncode == 1 and len(messages) == 7 and all(m['identifier'] == 'argument.type' for m in messages) and not data['errors']
record = {'status': 'PASS' if passed else 'FAIL', 'expected_rejected_arguments': 7,
          'actual_rejected_arguments': len(messages), 'identifiers': [m['identifier'] for m in messages]}
if args.report:
    args.report.write_text(json.dumps(record, indent=2) + '\n', encoding='utf-8')
print(json.dumps(record))
raise SystemExit(0 if passed else 1)
