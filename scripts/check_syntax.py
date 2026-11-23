"""Check every first-party/test PHP file and every distributed/test JS module."""
import argparse
import json
from pathlib import Path
import subprocess

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--php", default="php", help="PHP executable; use the minimum supported PHP for compatibility")
parser.add_argument("--report", type=Path, help="Optional JSON evidence file")
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
php_files = [root / "pdf-thumbnail-inserter.php", *sorted((root / "tests").glob("*.php"))]
php_files += sorted((root / "includes").rglob("*.php"))
php_files += sorted(file for file in (root / "tools/phpstan").glob("*")
                    if file.is_file() and file.suffix in (".php", ".stub"))
js_files = sorted(file for directory in ("assets", "blocks") for file in (root / directory).rglob("*")
                  if file.is_file() and file.suffix in (".js", ".mjs"))
js_files += sorted((root / "tests").glob("*.cjs"))
results = []
for file in php_files + js_files:
    command = [args.php, "-d", "xdebug.mode=off", "-l", str(file)] if file in php_files else ["node", "--check", str(file)]
    result = subprocess.run(command, capture_output=True, text=True, encoding="utf-8", errors="replace")
    results.append({"file": file.relative_to(root).as_posix(), "status": "PASS" if result.returncode == 0 else "FAIL",
                    "exit": result.returncode, "diagnostic": (result.stdout + result.stderr)[:2000] if result.returncode else ""})
if args.report:
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(results, indent=2) + "\n", encoding="utf-8")
failed = [item for item in results if item["status"] != "PASS"]
print(json.dumps({"checks": len(results), "php_files": len(php_files), "javascript_files": len(js_files), "failures": failed}))
raise SystemExit(bool(failed))
