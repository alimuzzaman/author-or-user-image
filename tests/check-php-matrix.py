#!/usr/bin/env python3
"""Run multi-version PHP 7.4-8.5 compatibility and disabled-function matrix."""

from __future__ import annotations

import argparse
import base64
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

REPO_ROOT = Path(__file__).resolve().parent.parent
HERD_BIN = Path.home() / "Library" / "Application Support" / "Herd" / "bin"
STATIC_SCRIPT = REPO_ROOT / "tests" / "php-compatibility.php"
WORKER_SCRIPT = REPO_ROOT / "tests" / "disabled-functions-worker.php"

from importlib.util import module_from_spec, spec_from_file_location

_df_spec = spec_from_file_location("check_disabled_functions", REPO_ROOT / "tests" / "check-disabled-functions.py")
assert _df_spec and _df_spec.loader
_df_mod = module_from_spec(_df_spec)
_df_spec.loader.exec_module(_df_mod)

HOST_BASELINE_DISABLE = _df_mod.HOST_BASELINE_DISABLE
HARDENED_OVERLAY_DISABLE = _df_mod.HARDENED_OVERLAY_DISABLE
ISOLATED_DISABLE = _df_mod.ISOLATED_DISABLE

SUPPORTED_BINS = ("php74", "php80", "php81", "php82", "php83", "php84", "php85")


def collect_lint_targets() -> list[Path]:
    targets = [
        REPO_ROOT / "author_image.php",
        REPO_ROOT / "index.php",
        REPO_ROOT / "uninstall.php",
    ]
    for folder in ("includes", "blocks", "tests"):
        base = REPO_ROOT / folder
        if base.is_dir():
            targets.extend(sorted(base.rglob("*.php")))
    return targets


def run_cmd(cmd: list[str]) -> tuple[int, str, str]:
    res = subprocess.run(cmd, cwd=REPO_ROOT, capture_output=True, text=True)
    return res.returncode, res.stdout, res.stderr


def worker_ok(code: int, out: str) -> bool:
    return code == 0 and "RESULT " in out and ", 0 failed" in out and "wp-die-message" not in out


def check_binary(php_bin: str, wp_root: Path | None, symlink_path: Path, advisory: bool) -> bool:
    code, ver_out, ver_err = run_cmd([php_bin, "-d", "pcre.jit=0", "-r", "echo PHP_VERSION;"])
    if code != 0:
        print(f"FAIL {php_bin}: unable to invoke ({ver_err.strip()})")
        return False
    version = ver_out.strip()
    label = f"PHP {version} ({Path(php_bin).name})" + (" [advisory]" if advisory else "")
    print(f"\n=== {label} ===")

    base = [php_bin, "-d", "pcre.jit=0", "-d", "error_reporting=E_ALL"]

    # 1. php -l on every PHP file.
    for php_file in collect_lint_targets():
        lcode, lout, lerr = run_cmd(base + ["-l", str(php_file)])
        if lcode != 0:
            print(f"FAIL lint {php_file}: {lout}{lerr}")
            return False
    print(f"PASS php -l across {len(collect_lint_targets())} files")

    # 2. Static compatibility + self-test.
    for extra, desc in (([], "static compatibility scan"), (["--self-test"], "static self-tests")):
        scode, sout, serr = run_cmd(base + [str(STATIC_SCRIPT)] + extra)
        if sout:
            print(sout, end="" if sout.endswith("\n") else "\n")
        if serr:
            print(serr, file=sys.stderr, end="" if serr.endswith("\n") else "\n")
        if scode != 0:
            print(f"FAIL {desc} on {label}")
            return False

    # 3. Isolated disable_functions worker.
    icode, iout, ierr = run_cmd(base + ["-d", f"disable_functions={ISOLATED_DISABLE}", str(WORKER_SCRIPT), "--isolated"])
    if iout:
        print(iout, end="" if iout.endswith("\n") else "\n")
    if ierr:
        print(ierr, file=sys.stderr, end="" if ierr.endswith("\n") else "\n")
    if not worker_ok(icode, iout):
        print(f"FAIL isolated disabled-functions on {label}")
        return False

    # 4. Full WordPress runtime under HOST_BASELINE_DISABLE & HARDENED_OVERLAY_DISABLE.
    if wp_root is not None:
        profiles = [
            ("host-baseline", HOST_BASELINE_DISABLE, []),
            ("hardened-overlay", HARDENED_OVERLAY_DISABLE, [f"--symlink={symlink_path}"]),
        ]
        for pname, df, extra_flags in profiles:
            wcode, wout, werr = run_cmd(
                base
                + ["-d", f"disable_functions={df}", str(WORKER_SCRIPT), f"--wp-root={wp_root}", f"--profile={pname}"]
                + extra_flags
            )
            if wout:
                print(wout, end="" if wout.endswith("\n") else "\n")
            if werr:
                print(werr, file=sys.stderr, end="" if werr.endswith("\n") else "\n")
            if not worker_ok(wcode, wout):
                print(f"FAIL runtime {pname} on {label}")
                return False

    print(f"PASS {label}")
    return True


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--wp-root", default="", help="Disposable local WordPress site for runtime matrix")
    parser.add_argument("--php", action="append", default=[], help="Override required PHP binary list")
    parser.add_argument("--advisory-php", action="append", default=[], help="Advisory pre-release PHP binaries (e.g. php86)")
    args = parser.parse_args()

    wp_root = Path(args.wp_root).resolve() if args.wp_root else None
    if wp_root is not None and not (wp_root / ".zmai-test-instance").is_file():
        print(f"ERROR: --wp-root missing .zmai-test-instance: {wp_root}", file=sys.stderr)
        return 2

    if args.php:
        required_phps = args.php
    else:
        required_phps = [str(HERD_BIN / name) for name in SUPPORTED_BINS if (HERD_BIN / name).is_file()]
        if len(required_phps) != len(SUPPORTED_BINS):
            missing = [n for n in SUPPORTED_BINS if not (HERD_BIN / n).is_file()]
            print(f"ERROR: missing local Herd PHP binaries: {missing}", file=sys.stderr)
            return 2

    all_ok = True
    summary: list[tuple[str, str]] = []

    with tempfile.TemporaryDirectory(prefix="zmai-php-matrix-") as tmp:
        target = Path(tmp) / "real.png"
        target.write_bytes(base64.b64decode(_df_mod.MIN_PNG_B64))
        sym = Path(tmp) / "sym.png"
        os.symlink(target, sym)

        for php_bin in required_phps:
            ok = check_binary(php_bin, wp_root, sym, advisory=False)
            summary.append((php_bin, "PASS" if ok else "FAIL"))
            if not ok:
                all_ok = False

        for adv_bin in args.advisory_php:
            resolved = adv_bin if Path(adv_bin).is_file() else shutil.which(adv_bin)
            if resolved:
                ok = check_binary(resolved, wp_root, sym, advisory=True)
                summary.append((adv_bin + " [advisory]", "PASS" if ok else "WARN"))

    print("\n=== Compatibility Matrix Summary ===")
    for item, status in summary:
        print(f"{status:5s} {item}")

    return 0 if all_ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
