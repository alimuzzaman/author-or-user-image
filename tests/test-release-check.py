#!/usr/bin/env python3
"""Unit tests for the production release package gate."""

from __future__ import annotations

import importlib.util
import os
import re
import shutil
import tempfile
import unittest
import zipfile
from pathlib import Path
from types import ModuleType
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("release_check", ROOT / "tools" / "check-release.py")
assert SPEC is not None and SPEC.loader is not None
RELEASE_CHECK = importlib.util.module_from_spec(SPEC)
import sys

sys.modules[SPEC.name] = RELEASE_CHECK
SPEC.loader.exec_module(RELEASE_CHECK)


class ReleaseCheckTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp_dir = tempfile.TemporaryDirectory()
        self.scratch = Path(self.temp_dir.name)
        self.project = self.scratch / "plugin"
        self.project.mkdir()
        for relative in RELEASE_CHECK.PRODUCTION_FILES:
            source = ROOT / relative
            destination = self.project / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, destination)
        shutil.copyfile(ROOT / ".distignore", self.project / ".distignore")
        self.output = self.scratch / "package.zip"

    def tearDown(self) -> None:
        self.temp_dir.cleanup()

    def test_rejects_v_prefixed_tag(self) -> None:
        with self.assertRaises(RELEASE_CHECK.ReleaseCheckError):
            RELEASE_CHECK.validate_tag("v3.1.0")

    def test_rejects_mismatched_header_version(self) -> None:
        entry = self.project / "author_image.php"
        source = entry.read_text(encoding="utf-8")
        entry.write_text(source.replace("Version: 3.1.0", "Version: 9.9.9", 1), encoding="utf-8")
        with self.assertRaisesRegex(RELEASE_CHECK.ReleaseCheckError, "version"):
            RELEASE_CHECK.validate_release(self.project, "3.1.0", self.output)

    def test_rejects_env_path_without_opening_it(self) -> None:
        # Inject only the directory entry, so no .env file is ever opened,
        # even for fixture creation. The validator must reject before reading it.
        original_walk = os.walk
        original_open = Path.open

        def walk_with_env(*args: object, **kwargs: object):
            for directory, directories, files in original_walk(*args, **kwargs):
                if Path(directory) == self.project:
                    files = [*files, ".env"]
                yield directory, directories, files

        def guarded_open(path: Path, *args: object, **kwargs: object):
            if path.name == ".env":
                raise AssertionError("validator attempted to open .env")
            return original_open(path, *args, **kwargs)

        with patch.object(Path, "open", guarded_open), patch.object(os, "walk", walk_with_env):
            with self.assertRaisesRegex(RELEASE_CHECK.ReleaseCheckError, r"\.env"):
                RELEASE_CHECK.validate_release(self.project, "3.1.0", self.output)

    def test_rejects_patch_tested_up_to_version(self) -> None:
        readme = self.project / "readme.txt"
        source = re.sub(r"(?m)^Tested up to:.*$", "Tested up to: 7.1.3", readme.read_text(encoding="utf-8"))
        readme.write_text(source, encoding="utf-8")
        with self.assertRaisesRegex(RELEASE_CHECK.ReleaseCheckError, "Tested up to"):
            RELEASE_CHECK.validate_release(self.project, "3.1.0", self.output)

    def test_rejects_missing_production_file(self) -> None:
        (self.project / "uninstall.php").unlink()
        with self.assertRaisesRegex(RELEASE_CHECK.ReleaseCheckError, "uninstall.php"):
            RELEASE_CHECK.validate_release(self.project, "3.1.0", self.output)

    def test_required_lifecycle_classes_are_packaged(self) -> None:
        self.assertTrue({"includes/class-lock.php", "includes/class-migration.php", "includes/class-user-cleanup.php"}.issubset(RELEASE_CHECK.PRODUCTION_FILES))

    def test_avatar_feature_runtime_files_are_packaged(self) -> None:
        required = {
            "assets/css/front-end.css", "assets/js/avatar-upload-editor.js",
            "assets/js/import.js", "blocks/avatar-upload/block.json",
            "includes/class-front-end.php", "includes/class-upload-policy.php",
            "includes/class-image-processor.php", "includes/class-import-sources.php",
            "includes/class-admin-import.php",
        }
        self.assertTrue(required.issubset(RELEASE_CHECK.PRODUCTION_FILES))
        self.assertIn("includes/class-image-metadata.php", RELEASE_CHECK.PRODUCTION_FILES)

    def test_zip_contains_only_the_twenty_four_production_files(self) -> None:
        for directory in (".github", "docs", "tests", "tools", ".wordpress-org"):
            dev_file = self.project / directory / "release-note.txt"
            dev_file.parent.mkdir(parents=True, exist_ok=True)
            dev_file.write_text("development only", encoding="utf-8")

        report = RELEASE_CHECK.validate_release(self.project, "3.1.0", self.output)
        with zipfile.ZipFile(report.zip_path) as archive:
            members = tuple(sorted(archive.namelist()))

        self.assertEqual(len(RELEASE_CHECK.PRODUCTION_FILES), 24)
        self.assertTrue({"includes/class-lock.php", "includes/class-migration.php", "includes/class-user-cleanup.php"}.issubset(members))
        self.assertEqual(members, tuple(sorted(RELEASE_CHECK.PRODUCTION_FILES)))
        self.assertEqual(report.members, members)
        self.assertEqual(len(report.sha256), 64)
        self.assertTrue(report.zip_path.is_file())


if __name__ == "__main__":
    unittest.main()
