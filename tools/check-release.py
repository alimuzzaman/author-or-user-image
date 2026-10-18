#!/usr/bin/env python3
"""Validate and package the exact production files for a WordPress.org release."""

from __future__ import annotations

import argparse
from dataclasses import dataclass
import hashlib
import os
from pathlib import Path
import re
import stat
import sys
import zipfile


class ReleaseCheckError(ValueError):
    """Raised when release metadata or package contents are invalid."""


@dataclass(frozen=True)
class ReleaseReport:
    version: str
    zip_path: Path
    sha256: str
    members: tuple[str, ...]


PRODUCTION_FILES: tuple[str, ...] = (
    "LICENSE.txt",
    "assets/css/admin.css",
    "assets/js/admin.js",
    "author_image.php",
    "includes/class-admin.php",
    "includes/class-image-store.php",
    "includes/class-lock.php",
    "includes/class-migration.php",
    "includes/class-plugin.php",
    "includes/class-privacy.php",
    "includes/class-user-cleanup.php",
    "index.php",
    "readme.txt",
    "uninstall.php",
)
EXPECTED_DISTIGNORE: frozenset[str] = frozenset(
    {
        "/.git",
        "/.github",
        "/.gitignore",
        "/.distignore",
        "/composer.json",
        "/composer.lock",
        "/node_modules",
        "/phpcs.xml.dist",
        "/vendor",
        "/tests",
        "/tools",
        "/docs",
        "/.wordpress-org",
    }
)
EXCLUDED_DIRECTORIES: frozenset[str] = frozenset(
    {".git", ".github", ".wordpress-org", "node_modules", "vendor", "tests", "tools", "docs"}
)
EXCLUDED_FILES: frozenset[str] = frozenset(
    {".gitignore", ".distignore", "composer.json", "composer.lock", "phpcs.xml.dist"}
)
VERSION_PATTERN = re.compile(r"^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$")
PLUGIN_NAME = "Author or User Image"
TEXT_DOMAIN = "author-or-user-image"
LICENSE = "GPL-2.0-or-later"
LICENSE_URI = "https://www.gnu.org/licenses/gpl-2.0.html"
REQUIRES_AT_LEAST = "6.6"
REQUIRES_PHP = "7.4"
TESTED_UP_TO = "7.1"


def validate_tag(tag: str) -> str:
    """Validate and return a bare three-part semantic version tag."""
    if VERSION_PATTERN.fullmatch(tag) is None:
        raise ReleaseCheckError(f"tag must be a bare semantic version such as 3.0.1, got {tag!r}")
    return tag


def _read_text(path: Path, label: str) -> str:
    try:
        return path.read_text(encoding="utf-8")
    except (OSError, UnicodeError) as error:
        raise ReleaseCheckError(f"cannot read {label}: {error}") from error


def _single_match(pattern: str, source: str, label: str) -> str:
    matches = re.findall(pattern, source, flags=re.MULTILINE | re.IGNORECASE)
    if len(matches) != 1:
        raise ReleaseCheckError(f"expected exactly one {label}, found {len(matches)}")
    return matches[0].strip()


def _parse_readme_headers(source: str) -> dict[str, str]:
    headers: dict[str, str] = {}
    for line in source.splitlines():
        match = re.match(r"^([A-Za-z][A-Za-z ]*):\s*(.*?)\s*$", line)
        if match:
            key = match.group(1).strip().lower()
            if key in headers:
                raise ReleaseCheckError(f"readme has duplicate {match.group(1).strip()} metadata")
            headers[key] = match.group(2)
    return headers


def _validate_metadata(root: Path, tag: str) -> None:
    entry = _read_text(root / "author_image.php", "author_image.php")
    readme = _read_text(root / "readme.txt", "readme.txt")
    header = {
        "plugin name": _single_match(r"^\s*\*\s*Plugin Name:\s*(.*?)\s*$", entry, "Plugin Name"),
        "version": _single_match(r"^\s*\*\s*Version:\s*(.*?)\s*$", entry, "PHP header Version"),
        "requires at least": _single_match(r"^\s*\*\s*Requires at least:\s*(.*?)\s*$", entry, "Requires at least"),
        "requires php": _single_match(r"^\s*\*\s*Requires PHP:\s*(.*?)\s*$", entry, "Requires PHP"),
        "license": _single_match(r"^\s*\*\s*License:\s*(.*?)\s*$", entry, "PHP header License"),
        "license uri": _single_match(r"^\s*\*\s*License URI:\s*(.*?)\s*$", entry, "PHP header License URI"),
        "text domain": _single_match(r"^\s*\*\s*Text Domain:\s*(.*?)\s*$", entry, "Text Domain"),
    }
    version_constant = _single_match(
        r"define\s*\(\s*['\"]ZMAI_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)\s*;",
        entry,
        "ZMAI_VERSION",
    )
    readme_headers = _parse_readme_headers(readme)
    title = _single_match(r"^===\s*(.*?)\s*===$", readme, "readme title")
    readme_version = readme_headers.get("stable tag", "")
    readme_license = readme_headers.get("license", "")
    readme_license_uri = readme_headers.get("license uri", "")
    tested_up_to = readme_headers.get("tested up to", "")
    requires_at_least = readme_headers.get("requires at least", "")
    requires_php = readme_headers.get("requires php", "")

    versions = {
        "tag": tag,
        "PHP header": header["version"],
        "ZMAI_VERSION": version_constant,
        "readme Stable tag": readme_version,
    }
    if any(VERSION_PATTERN.fullmatch(version) is None for version in versions.values()):
        raise ReleaseCheckError("tag, PHP header version, ZMAI_VERSION, and readme Stable tag must be bare semantic versions")
    if len(set(versions.values())) != 1:
        raise ReleaseCheckError("release version mismatch: " + ", ".join(f"{name}={value}" for name, value in versions.items()))

    if header["plugin name"] != PLUGIN_NAME or title != PLUGIN_NAME:
        raise ReleaseCheckError(f"public plugin name must be exactly {PLUGIN_NAME!r}")
    if header["text domain"] != TEXT_DOMAIN:
        raise ReleaseCheckError(f"Text Domain must be exactly {TEXT_DOMAIN!r}")
    if header["license"] != LICENSE or readme_license != LICENSE:
        raise ReleaseCheckError(f"PHP header and readme License must both be {LICENSE!r}")
    if header["license uri"] != LICENSE_URI or readme_license_uri != LICENSE_URI:
        raise ReleaseCheckError(f"PHP header and readme License URI must both be {LICENSE_URI!r}")
    if (header["requires at least"], requires_at_least) != (REQUIRES_AT_LEAST, REQUIRES_AT_LEAST):
        raise ReleaseCheckError(f"PHP header and readme Requires at least must both be {REQUIRES_AT_LEAST}")
    if (header["requires php"], requires_php) != (REQUIRES_PHP, REQUIRES_PHP):
        raise ReleaseCheckError(f"PHP header and readme Requires PHP must both be {REQUIRES_PHP}")
    if tested_up_to != TESTED_UP_TO:
        raise ReleaseCheckError(f"readme Tested up to must be {TESTED_UP_TO}")


def _validate_distignore(root: Path) -> None:
    source = _read_text(root / ".distignore", ".distignore")
    entries = [line.strip() for line in source.splitlines() if line.strip() and not line.lstrip().startswith("#")]
    if len(entries) != len(set(entries)):
        raise ReleaseCheckError(".distignore contains duplicate paths")
    unexpected = sorted(set(entries) - EXPECTED_DISTIGNORE)
    missing = sorted(EXPECTED_DISTIGNORE - set(entries))
    if unexpected or missing:
        details = []
        if unexpected:
            details.append("unexpected entries: " + ", ".join(unexpected))
        if missing:
            details.append("missing expected entries: " + ", ".join(missing))
        raise ReleaseCheckError(".distignore must list only the known development paths; " + "; ".join(details))


def _scan_tree(root: Path) -> None:
    found: set[str] = set()

    def on_walk_error(error: OSError) -> None:
        raise ReleaseCheckError(f"cannot inspect release tree: {error}") from error

    for directory, directory_names, file_names in os.walk(root, topdown=True, followlinks=False, onerror=on_walk_error):
        current = Path(directory)
        relative_dir = current.relative_to(root)
        for name in tuple(directory_names):
            path = current / name
            if path.is_symlink():
                raise ReleaseCheckError(f"symlink is not allowed in release tree: {path.relative_to(root).as_posix()}")
            if relative_dir == Path(".") and name in EXCLUDED_DIRECTORIES:
                directory_names.remove(name)
        for name in file_names:
            path = current / name
            relative = path.relative_to(root).as_posix()
            if path.is_symlink():
                raise ReleaseCheckError(f"symlink is not allowed in release tree: {relative}")
            if relative in EXCLUDED_FILES:
                continue
            if relative not in PRODUCTION_FILES:
                raise ReleaseCheckError(f"unexpected distributable path: {relative}")
            if not path.is_file():
                raise ReleaseCheckError(f"production file is not a regular file: {relative}")
            found.add(relative)

    missing = sorted(set(PRODUCTION_FILES) - found)
    if missing:
        raise ReleaseCheckError("missing production file(s): " + ", ".join(missing))


def _check_output_path(root: Path, zip_output: Path) -> Path:
    output = zip_output.expanduser().resolve()
    if output == root or root in output.parents:
        raise ReleaseCheckError("ZIP output path must be outside the plugin worktree")
    if output.exists():
        raise ReleaseCheckError(f"ZIP output already exists: {output}")
    return output


def _write_zip(root: Path, output: Path) -> tuple[tuple[str, ...], str]:
    members = tuple(sorted(PRODUCTION_FILES))
    try:
        output.parent.mkdir(parents=True, exist_ok=True)
        with zipfile.ZipFile(output, mode="x", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            for relative in members:
                source = root / relative
                info = zipfile.ZipInfo(relative, date_time=(1980, 1, 1, 0, 0, 0))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = (stat.S_IFREG | 0o644) << 16
                archive.writestr(info, source.read_bytes())
        digest = hashlib.sha256(output.read_bytes()).hexdigest()
    except (OSError, zipfile.BadZipFile) as error:
        raise ReleaseCheckError(f"could not create production ZIP: {error}") from error
    return members, digest


def validate_release(root: Path, tag: str, zip_output: Path) -> ReleaseReport:
    """Validate the release tree and write its production-only ZIP."""
    version = validate_tag(tag)
    plugin_root = root.expanduser().resolve(strict=True)
    if not plugin_root.is_dir():
        raise ReleaseCheckError(f"plugin root is not a directory: {plugin_root}")
    _validate_distignore(plugin_root)
    _scan_tree(plugin_root)
    _validate_metadata(plugin_root, version)
    output = _check_output_path(plugin_root, zip_output)
    members, digest = _write_zip(plugin_root, output)
    return ReleaseReport(version=version, zip_path=output, sha256=digest, members=members)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--tag", required=True, help="bare semantic version tag, for example 3.0.1")
    parser.add_argument("--zip-output", required=True, type=Path, help="new ZIP path outside the plugin worktree")
    arguments = parser.parse_args(argv)
    root = Path(__file__).resolve().parents[1]
    try:
        report = validate_release(root, arguments.tag, arguments.zip_output)
    except (ReleaseCheckError, OSError) as error:
        print(f"Release check failed: {error}", file=sys.stderr)
        return 1
    print(f"Release check passed for {report.version}")
    print(f"ZIP: {report.zip_path}")
    print(f"SHA-256: {report.sha256}")
    print(f"Members ({len(report.members)}):")
    for member in report.members:
        print(member)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
