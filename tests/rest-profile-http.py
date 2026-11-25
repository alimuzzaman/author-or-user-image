"""Exercise real HTTP multipart uploads through Profile_Admin and REST_API."""
import argparse
import base64
import json
import os
from pathlib import Path
import socket
import struct
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zlib

parser = argparse.ArgumentParser()
parser.add_argument("--wp-root", required=True, type=Path)
parser.add_argument("--php", required=True)
parser.add_argument("--scratch", type=Path, default=Path(os.environ.get("TMPDIR", tempfile.gettempdir())))
parser.add_argument("--blog-id", type=int, default=0)
options = parser.parse_args()
assert (options.wp_root / ".zmai-test-instance").is_file(), "Disposable instance required"

router = Path(__file__).with_name("rest-profile-router.php").resolve()
with socket.socket() as sock:
    sock.bind(("127.0.0.1", 0))
    port = sock.getsockname()[1]
base = f"http://127.0.0.1:{port}"
checks = []


def check(condition, name):
    checks.append((bool(condition), name))
    print(("PASS " if condition else "FAIL ") + name, flush=True)


def request(action="snapshot", role="sub", fields=None, image=None, **query):
    params = dict(action=action, role=role, **query)
    if options.blog_id > 0:
        params["blog_id"] = options.blog_id
    url = base + "/?" + urllib.parse.urlencode(params)
    headers = {}
    data = None
    if fields is not None or image is not None:
        boundary = "zmai-rest-profile-boundary"
        chunks = []
        for key, value in (fields or {}).items():
            chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
        if image:
            field, filename, content = image
            chunks.append(
                f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{filename}"\r\nContent-Type: image/png\r\n\r\n'.encode()
                + content
                + b"\r\n"
            )
        chunks.append(f"--{boundary}--\r\n".encode())
        data = b"".join(chunks)
        headers["Content-Type"] = "multipart/form-data; boundary=" + boundary
    try:
        response = urllib.request.urlopen(urllib.request.Request(url, data=data, headers=headers), timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, json.loads(response.read())


png = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=")
text_payload = b"comment\x00PRIVATE-PHOTO-V32"
text_chunk = struct.pack(">I", len(text_payload)) + b"tEXt" + text_payload
text_chunk += struct.pack(">I", zlib.crc32(b"tEXt" + text_payload) & 0xFFFFFFFF)
tagged_png = png[:33] + text_chunk + png[33:]

with tempfile.TemporaryFile(dir=options.scratch) as server_log:
    server = subprocess.Popen(
        [options.php, "-S", f"127.0.0.1:{port}", str(router)],
        cwd=options.wp_root,
        stdout=server_log,
        stderr=server_log,
        env={"PATH": "/usr/bin:/bin:/usr/local/bin", "TMPDIR": str(options.scratch), "LC_ALL": "C"},
    )
    try:
        for _ in range(50):
            try:
                snap = request()[1]
                break
            except urllib.error.URLError:
                time.sleep(0.1)
        else:
            raise RuntimeError("Local PHP server did not become ready")

        sub_id = snap["sub_id"]
        request("set-policy", mode="legacy")
        request("set-blocked", state="0")
        request("reset-rate")

        # 1. Profile_Admin::save_profile() self-upload as subscriber (strips metadata).
        nonce_sub = request("nonce", role="sub", target_id=sub_id)[1]["nonce"]
        st, res = request(
            "profile-save",
            role="sub",
            target_id=sub_id,
            fields={"zmai_profile_nonce": nonce_sub},
            image=("zmai_profile_avatar", "self.png", tagged_png),
        )
        inv = request("metadata-inventory")[1]
        check(st == 200 and not res["errors"] and res["sub_att"] > 0 and inv["clean"], "Profile_Admin self-upload saves avatar and strips metadata")

        # 2. Profile_Admin::save_profile() admin upload for subscriber while role policy denies subscribers.
        request("set-policy", mode="admin-only")
        nonce_admin = request("nonce", role="admin", target_id=sub_id)[1]["nonce"]
        prev_att = res["sub_att"]
        st, res = request(
            "profile-save",
            role="admin",
            target_id=sub_id,
            fields={"zmai_profile_nonce": nonce_admin},
            image=("zmai_profile_avatar", "admin-edit.png", tagged_png),
        )
        inv = request("metadata-inventory")[1]
        check(st == 200 and not res["errors"] and res["sub_att"] > 0 and res["sub_att"] != prev_att and inv["clean"], "Profile_Admin admin upload overrides subscriber role restriction and strips metadata")

        # 3. Profile_Admin::save_profile() admin upload for blocked user is rejected.
        request("set-blocked", state="1")
        st, res = request(
            "profile-save",
            role="admin",
            target_id=sub_id,
            fields={"zmai_profile_nonce": nonce_admin},
            image=("zmai_profile_avatar", "blocked.png", tagged_png),
        )
        check(st == 200 and "zmai_blocked" in res["errors"] and res["sub_att"] == 0, "Profile_Admin rejects admin upload for blocked user")
        request("set-blocked", state="0")

        # 4. REST_API POST /avatars/{self} as subscriber (strips metadata; 6th rapid upload -> 429).
        request("set-policy", mode="legacy")
        request("reset-rate")
        statuses = []
        for _ in range(5):
            s, body = request(
                "rest",
                role="sub",
                method="POST",
                route=f"/avatars/{sub_id}",
                fields={},
                image=("file", "rest-self.png", tagged_png),
            )
            statuses.append(s)
        inv = request("metadata-inventory")[1]
        s6, body6 = request(
            "rest",
            role="sub",
            method="POST",
            route=f"/avatars/{sub_id}",
            fields={},
            image=("file", "rest-rate.png", tagged_png),
        )
        check(all(s == 200 for s in statuses) and inv["clean"] and s6 == 429, "REST self-upload strips metadata and throttles 6th rapid upload with 429")

        # 5. REST_API POST /avatars/{subscriber} as admin while role-denied; subscriber cross-upload -> 403.
        request("set-policy", mode="admin-only")
        s_sub_other, _ = request(
            "rest",
            role="sub",
            method="POST",
            route=f"/avatars/{snap['admin_id']}",
            fields={},
            image=("file", "forbidden.png", tagged_png),
        )
        s_adm, body_adm = request(
            "rest",
            role="admin",
            method="POST",
            route=f"/avatars/{sub_id}",
            fields={},
            image=("file", "rest-admin.png", tagged_png),
        )
        inv = request("metadata-inventory")[1]
        check(s_sub_other == 403 and s_adm == 200 and body_adm.get("has_local_avatar") and inv["clean"], "REST admin upload for role-denied subscriber succeeds and strips metadata while subscriber cross-upload returns 403")

        # 6. REST_API POST /default as admin (200, metadata stripped); subscriber -> 403.
        s_def_sub, _ = request(
            "rest",
            role="sub",
            method="POST",
            route="/default",
            fields={},
            image=("file", "def-sub.png", tagged_png),
        )
        s_def_adm, body_def = request(
            "rest",
            role="admin",
            method="POST",
            route="/default",
            fields={},
            image=("file", "def-admin.png", tagged_png),
        )
        inv_def = request("metadata-inventory", default="1")[1]
        check(s_def_sub == 403 and s_def_adm == 200 and body_def.get("has_default_avatar") and inv_def["clean"], "REST default upload succeeds for admin with metadata stripped and returns 403 for subscriber")

        request("cleanup-fixture")
    finally:
        server.terminate()
        server.wait(timeout=10)

print(json.dumps({"checks": len(checks), "failures": [name for ok, name in checks if not ok]}))
raise SystemExit(0 if all(ok for ok, _ in checks) else 1)
