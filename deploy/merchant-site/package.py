"""Build the plugin on a Linux deployment host without PowerShell."""
from hashlib import sha256
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED

root = Path(__file__).resolve().parents[2]
paths = [root / p for p in ("forus-woocommerce.php", "README.md", "CHANGELOG.md", "LICENSE")]
for name in ("includes", "assets", "docs"):
    paths.extend(p for p in (root / name).rglob("*") if p.is_file())
output = root / "dist"
output.mkdir(exist_ok=True)
archive = output / "forus-woocommerce-v1.1.0.zip"
with ZipFile(archive, "w", compression=ZIP_DEFLATED) as z:
    for path in sorted(paths):
        relative = path.relative_to(root).as_posix()
        entry = ZipInfo("forus-woocommerce/" + relative, date_time=(2020, 1, 1, 0, 0, 0))
        entry.compress_type = ZIP_DEFLATED
        entry.external_attr = 0o100644 << 16
        z.writestr(entry, path.read_bytes())
with ZipFile(archive) as z:
    assert len(z.namelist()) == len(paths)
    for path in paths:
        assert z.read("forus-woocommerce/" + path.relative_to(root).as_posix()) == path.read_bytes()
digest = sha256(archive.read_bytes()).hexdigest()
archive.with_suffix(".zip.sha256").write_text(digest + "  " + archive.name + "\n", encoding="utf-8")
print(f"Validated {len(paths)} plugin files; SHA256 {digest}")
