"""Build the installable WordPress plugin ZIP with 7-Zip."""

from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import zipfile


ROOT = Path(__file__).resolve().parent.parent
PLUGIN = "gvn-wordpress-abilities"
DIST = ROOT / "dist"
ARCHIVE = DIST / f"{PLUGIN}.zip"
# Explicit distribution list: do not ship development files or local secrets.
FILES = (
    "gvn-wordpress-abilities.php",
    "admin.php",
    "admin.css",
    "htmlpress.php",
    "plugin-files.php",
    "README.md",
    "plugin-update-checker/plugin-update-checker.php",
    "plugin-update-checker/load-v6p0.php",
    "plugin-update-checker/license.txt",
) + tuple(
    path.relative_to(ROOT).as_posix()
    for directory in ("Puc", "vendor", "css", "js", "languages")
    for path in sorted((ROOT / "plugin-update-checker" / directory).rglob("*"))
    if path.is_file()
)


def main():
    seven_zip = shutil.which("7z") or shutil.which("7za")
    if not seven_zip:
        seven_zip = next(
            (
                str(path)
                for path in (
                    Path("C:/Program Files/7-Zip/7z.exe"),
                    Path("C:/Program Files (x86)/7-Zip/7z.exe"),
                )
                if path.is_file()
            ),
            None,
        )
    if not seven_zip:
        print("7-Zip não encontrado no PATH (esperado: 7z ou 7za).", file=sys.stderr)
        return 1

    DIST.mkdir(exist_ok=True)
    try:
        # Same filesystem as the final ZIP, so replacement is atomic.
        with tempfile.TemporaryDirectory(dir=DIST) as temporary:
            staging = Path(temporary)
            plugin_dir = staging / PLUGIN
            plugin_dir.mkdir()
            for name in FILES:
                (plugin_dir / name).parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(ROOT / name, plugin_dir / name)

            archive = staging / ARCHIVE.name
            subprocess.run(
                [seven_zip, "a", "-tzip", str(archive), PLUGIN],
                cwd=staging,
                check=True,
            )
            with zipfile.ZipFile(archive) as package:
                expected = {f"{PLUGIN}/{name}" for name in FILES}
                actual = {entry.filename for entry in package.infolist() if not entry.is_dir()}
                if actual != expected or package.testzip() is not None:
                    raise ValueError("Conteúdo ou integridade do ZIP inválidos.")
            archive.replace(ARCHIVE)

        for old_zip in DIST.glob("*.zip"):
            if old_zip != ARCHIVE:
                old_zip.unlink()
    except (OSError, subprocess.CalledProcessError, zipfile.BadZipFile, ValueError) as error:
        print(f"Falha ao gerar o ZIP: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
