"""Run with: python tests/build-zip-check.py (requires 7-Zip)."""

import importlib.util
from pathlib import Path
import subprocess
import tempfile
from unittest.mock import patch
import zipfile


spec = importlib.util.spec_from_file_location(
    "build_zip", Path(__file__).resolve().parents[1] / "scripts" / "build-zip.py"
)
build = importlib.util.module_from_spec(spec)
spec.loader.exec_module(build)

assert {
    "plugin-update-checker/plugin-update-checker.php",
    "plugin-update-checker/load-v6p0.php",
    "plugin-update-checker/Puc/v6/PucFactory.php",
    "plugin-update-checker/vendor/PucReadmeParser.php",
}.issubset(build.FILES)

with tempfile.TemporaryDirectory() as temporary:
    dist = Path(temporary)
    archive = dist / build.ARCHIVE.name
    with patch.object(build, "DIST", dist), patch.object(build, "ARCHIVE", archive):
        old = dist / "old.zip"
        old.write_bytes(b"previous release")
        with patch.object(
            build.subprocess, "run",
            side_effect=subprocess.CalledProcessError(1, "7z"),
        ):
            assert build.main() == 1
        assert old.read_bytes() == b"previous release"
        assert not archive.exists()

        assert build.main() == 0
        assert list(dist.glob("*.zip")) == [archive]
        with zipfile.ZipFile(archive) as package:
            assert package.testzip() is None
            assert {n for n in package.namelist() if not n.endswith("/")} == {
                f"{build.PLUGIN}/{name}" for name in build.FILES
            }

        previous = archive.read_bytes()
        with patch.object(build.subprocess, "run"):
            assert build.main() == 1
        assert archive.read_bytes() == previous

print("ZIP checks passed.")
