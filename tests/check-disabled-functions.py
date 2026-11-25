#!/usr/bin/env python3
"""Run static + runtime -d disable_functions checks across PHP versions."""

from __future__ import annotations

import argparse
import base64
import os
from pathlib import Path
import subprocess
import sys
import tempfile

REPO_ROOT = Path(__file__).resolve().parent.parent
STATIC_SCRIPT = REPO_ROOT / "tests" / "php-compatibility.php"
WORKER_SCRIPT = REPO_ROOT / "tests" / "disabled-functions-worker.php"

HERD_BIN = Path.home() / "Library" / "Application Support" / "Herd" / "bin"

HOST_BASELINE_DISABLE = (
    "exec,shell_exec,system,passthru,popen,proc_open,proc_close,proc_get_status,"
    "proc_nice,proc_terminate,pcntl_exec,pcntl_fork,pcntl_signal,pcntl_alarm,"
    "pcntl_waitpid,pcntl_wait,pcntl_wifexited,pcntl_wifstopped,pcntl_wifsignaled,"
    "pcntl_wexitstatus,pcntl_wtermsig,pcntl_wstopsig,pcntl_signal_dispatch,dl,"
    "show_source,highlight_file,phpinfo,php_uname,posix_kill,posix_mkfifo,"
    "posix_setpgid,posix_setsid,posix_setuid,posix_getpwuid,posix_uname,putenv,"
    "apache_child_terminate,apache_setenv,symlink,link,readlink,linkinfo,"
    "disk_free_space,disk_total_space,parse_ini_file,escapeshellarg,"
    "escapeshellcmd,curl_exec,curl_multi_exec,fsockopen,pfsockopen,"
    "stream_socket_server,mail,set_time_limit,ignore_user_abort,ini_alter,"
    "ini_restore,ini_get_all,chown,chgrp,lchgrp,lchown,fpassthru,readfile,"
    "getmyuid,getmypid,getrusage,get_current_user"
)

HARDENED_OVERLAY_DISABLE = (
    HOST_BASELINE_DISABLE + ",scandir,error_log,lstat,exif_read_data"
)

ISOLATED_DISABLE = (
    HARDENED_OVERLAY_DISABLE + ",mb_substr,ini_get,ini_set,glob,chmod"
)

MIN_PNG_B64 = (
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII="
)


def default_phps() -> list[str]:
    candidates = [str(HERD_BIN / "php74"), str(HERD_BIN / "php85")]
    found = [p for p in candidates if Path(p).is_file()]
    return found if found else ["php"]


def run_step(cmd: list[str], label: str, require_worker_result: bool = False) -> None:
    print(f"=== {label} ===")
    res = subprocess.run(cmd, cwd=REPO_ROOT, capture_output=True, text=True)
    if res.stdout:
        print(res.stdout, end="" if res.stdout.endswith("\n") else "\n")
    if res.stderr:
        print(res.stderr, file=sys.stderr, end="" if res.stderr.endswith("\n") else "\n")
    if res.returncode != 0:
        raise RuntimeError(f"Step failed ({res.returncode}): {label}")
    if require_worker_result:
        if "RESULT " not in res.stdout or ", 0 failed" not in res.stdout or "wp-die-message" in res.stdout:
            raise RuntimeError(f"Worker output failed assertion check: {label}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--php", action="append", default=[], help="PHP binary path (repeatable)")
    parser.add_argument("--wp-root", default="", help="Optional disposable local WordPress root for full WP runtime tests")
    args = parser.parse_args()

    phps = args.php if args.php else default_phps()
    wp_root = Path(args.wp_root).resolve() if args.wp_root else None
    if wp_root is not None and not (wp_root / ".zmai-test-instance").is_file():
        print(f"ERROR: --wp-root missing .zmai-test-instance: {wp_root}", file=sys.stderr)
        return 2

    with tempfile.TemporaryDirectory(prefix="zmai-df-symlinks-") as tmp:
        target = Path(tmp) / "real.png"
        target.write_bytes(base64.b64decode(MIN_PNG_B64))
        sym = Path(tmp) / "symlink.png"
        os.symlink(target, sym)

        for php_bin in phps:
            base = [php_bin, "-d", "pcre.jit=0", "-d", "error_reporting=E_ALL"]
            run_step(base + [str(STATIC_SCRIPT)], f"{php_bin}: static compat & host-disabled guard scan")
            run_step(base + [str(STATIC_SCRIPT), "--self-test"], f"{php_bin}: static checker negative/positive self-tests")
            run_step(
                base + ["-d", f"disable_functions={ISOLATED_DISABLE}", str(WORKER_SCRIPT), "--isolated"],
                f"{php_bin}: isolated runtime with ini_get/ini_set/glob/chmod/scandir/error_log/lstat/exif_read_data/mb_substr disabled",
                require_worker_result=True,
            )
            if wp_root is not None:
                run_step(
                    base
                    + [
                        "-d",
                        f"disable_functions={HOST_BASELINE_DISABLE}",
                        str(WORKER_SCRIPT),
                        f"--wp-root={wp_root}",
                        "--profile=host-baseline",
                    ],
                    f"{php_bin}: WordPress runtime under HOST_BASELINE_DISABLE",
                    require_worker_result=True,
                )
                run_step(
                    base
                    + [
                        "-d",
                        f"disable_functions={HARDENED_OVERLAY_DISABLE}",
                        str(WORKER_SCRIPT),
                        f"--wp-root={wp_root}",
                        "--profile=hardened-overlay",
                        f"--symlink={sym}",
                    ],
                    f"{php_bin}: WordPress runtime under HARDENED_OVERLAY_DISABLE",
                    require_worker_result=True,
                )

    print("PASS all disabled-functions checks")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
