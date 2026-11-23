"""Build the installable ZIP using the existing release's single-folder runtime layout.

Only the distributable root files and Git-tracked assets/blocks are shipped.
Run from any directory: python scripts/package.py
"""
from pathlib import Path
import hashlib
import re
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SLUG = "pdf-thumbnail-inserter"


def build():
    source = (ROOT / f"{SLUG}.php").read_text(encoding="utf-8")
    version = re.search(r"\* Version:\s+(\d+\.\d+\.\d+)", source).group(1)
    assert f"const VERSION = '{version}';" in source, "PHP version mismatch"
    assert f"**Version:** {version}" in (ROOT / "README.md").read_text(encoding="utf-8")
    readme = (ROOT / "readme.txt").read_text(encoding="utf-8")
    assert re.search(rf"^Stable tag:\s*{re.escape(version)}$", readme, re.M), "Stable tag mismatch"
    tracked = subprocess.check_output(["git", "ls-files", "-z", "--", "assets", "blocks"], cwd=ROOT)
    changelog = (ROOT / "changelog.txt").read_text(encoding="utf-8")
    assert re.search(rf"^### {re.escape(version)}$", changelog, re.M), "Changelog version mismatch"
    files = sorted(["LICENSE", "readme.txt", "changelog.txt", f"{SLUG}.php"] + tracked.decode("utf-8").strip("\0").split("\0"))
    assert len(files) == len(set(files)), "Duplicate archive entries"
    assert all(not any(part.startswith(".") or part in ("tests", "node_modules", "vendor") for part in Path(name).parts) for name in files)
    # Match canonical Git text bytes even in a Windows checkout with core.autocrlf.
    eol_records = subprocess.check_output(["git", "ls-files", "--eol", "-z", "--", *files], cwd=ROOT)
    text_files = {record.split(b"\t", 1)[1].decode("utf-8") for record in eol_records.split(b"\0") if record.startswith(b"i/lf ")}
    contents = {name: (ROOT / name).read_bytes() for name in files}
    for name in text_files:
        contents[name] = contents[name].replace(b"\r\n", b"\n")
    output = ROOT / "dist" / f"{SLUG}-{version}.zip"
    output.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name in files:
            entry = zipfile.ZipInfo(f"{SLUG}/{name}", date_time=(1980, 1, 1, 0, 0, 0))
            entry.compress_type = zipfile.ZIP_DEFLATED
            entry.external_attr = 0o100644 << 16
            archive.writestr(entry, contents[name])
    with zipfile.ZipFile(output) as archive:
        assert archive.testzip() is None, "ZIP integrity failure"
        assert archive.namelist() == [f"{SLUG}/{name}" for name in files], "ZIP manifest mismatch"
        for name in files:
            assert archive.read(f"{SLUG}/{name}") == contents[name], f"ZIP content mismatch: {name}"
    print(f"PASS: {output.name}, {len(files)} files, runtime-only manifest and canonical source-byte match")
    print(f"SHA256 {hashlib.sha256(output.read_bytes()).hexdigest()}")


if __name__ == "__main__":
    build()
