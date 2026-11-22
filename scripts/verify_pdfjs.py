"""Compare every bundled PDF.js file with its pinned official npm artifact."""
import base64
import hashlib
import io
import json
from pathlib import Path
import tarfile
from urllib.request import Request, urlopen

root = Path(__file__).resolve().parents[1]
version = (root / "assets/pdfjs/VERSION.txt").read_text(encoding="utf-8-sig").splitlines()[0].removeprefix("PDF.js ")
registry = f"https://registry.npmjs.org/pdfjs-dist/{version}"
with urlopen(registry, timeout=60) as response:
    metadata = json.load(response)
with urlopen(metadata["dist"]["tarball"], timeout=60) as response:
    artifact = response.read()
algorithm, expected = metadata["dist"]["integrity"].split("-", 1)
actual = base64.b64encode(hashlib.new(algorithm, artifact).digest()).decode()
if actual != expected:
    raise SystemExit("FAIL: npm artifact integrity mismatch")
checked = []
mismatches = []
# 6.3.289 omitted these notices; upstream repaired the distribution in 6.5.
supplemental = {
    "wasm/LICENSE_QUICKJS": "https://raw.githubusercontent.com/mozilla/pdf.js/v6.5.438/external/quickjs/LICENSE_QUICKJS",
    "wasm/LICENSE_PDFJS_QUICKJS": "https://raw.githubusercontent.com/mozilla/pdf.js/v6.5.438/external/quickjs/LICENSE_PDFJS_QUICKJS",
}
with tarfile.open(fileobj=io.BytesIO(artifact), mode="r:gz") as archive:
    for local in sorted((root / "assets/pdfjs").rglob("*")):
        if not local.is_file() or local.name == "VERSION.txt":
            continue
        relative = local.relative_to(root / "assets/pdfjs").as_posix()
        upstream = {"pdf.min.js": "build/pdf.min.mjs", "pdf.worker.min.js": "build/pdf.worker.min.mjs"}.get(relative, relative)
        if relative in supplemental:
            with urlopen(supplemental[relative], timeout=60) as response:
                remote = response.read()
        else:
            remote = archive.extractfile("package/" + upstream).read()
        # Git canonicalizes text line endings on Windows; binary bytes must be exact.
        data = local.read_bytes()
        if data != remote and (b"\0" in data or data.replace(b"\r\n", b"\n") != remote.replace(b"\r\n", b"\n")):
            mismatches.append(relative)
        checked.append({"file": relative, "sha256": hashlib.sha256(remote).hexdigest(),
                        "source": supplemental.get(relative, "package/" + upstream)})
if any(not (root / "assets/pdfjs" / name).is_file() for name in supplemental):
    raise SystemExit("FAIL: a required supplemental license is missing")
request = Request("https://registry.npmjs.org/-/npm/v1/security/advisories/bulk",
                  data=json.dumps({"pdfjs-dist": [version]}).encode(),
                  headers={"Content-Type": "application/json"}, method="POST")
with urlopen(request, timeout=60) as response:
    advisories = json.load(response)
print(json.dumps({"status": "FAIL" if advisories or mismatches else "PASS", "version": version,
                  "registry": registry, "npm_integrity": metadata["dist"]["integrity"],
                  "tarball_sha256": hashlib.sha256(artifact).hexdigest(),
                  "checked_files": len(checked), "supplemental_licenses": supplemental,
                  "mismatches": mismatches, "advisories": advisories, "files": checked}, indent=2))
if advisories or mismatches:
    raise SystemExit(1)
