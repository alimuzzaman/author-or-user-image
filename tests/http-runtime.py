"""Exercise real HTTP multipart uploads in a disposable WordPress instance."""
import argparse
import base64
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import struct
import zlib

parser = argparse.ArgumentParser()
parser.add_argument("--wp-root", required=True, type=Path)
parser.add_argument("--php", required=True)
parser.add_argument("--scratch", type=Path, default=Path(os.environ.get("TMPDIR", tempfile.gettempdir())))
options = parser.parse_args()
assert (options.wp_root / ".zmai-test-instance").is_file(), "Disposable instance required"
router = Path(__file__).with_name("http-router.php").resolve()
with socket.socket() as sock:
    sock.bind(("127.0.0.1", 0))
    port = sock.getsockname()[1]
base = f"http://127.0.0.1:{port}"
checks = []


def check(condition, name):
    checks.append((bool(condition), name))
    print(("PASS " if condition else "FAIL ") + name, flush=True)


def request(action="snapshot", role="subscriber", fields=None, image=None, **query):
    url = base + "/?" + urllib.parse.urlencode(dict(action=action, role=role, **query))
    headers = {}
    data = None
    if fields is not None:
        boundary = "zmai-local-multipart-boundary"
        chunks = []
        for key, value in fields.items():
            chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
        if image:
            field, filename, content = image
            chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + content + b"\r\n")
        chunks.append(f"--{boundary}--\r\n".encode())
        data = b"".join(chunks)
        headers["Content-Type"] = "multipart/form-data; boundary=" + boundary
    try:
        response = urllib.request.urlopen(urllib.request.Request(url, data=data, headers=headers), timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, json.loads(response.read())


def nonce(action, role="subscriber"):
    return request("nonce", role, **{"for": action})[1]["nonce"]


png = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=")
with tempfile.TemporaryFile(dir=options.scratch) as server_log:
    server = subprocess.Popen([options.php, "-S", f"127.0.0.1:{port}", str(router)], cwd=options.wp_root, stdout=server_log, stderr=server_log, env={"PATH": "/usr/bin:/bin:/usr/local/bin", "TMPDIR": str(options.scratch), "LC_ALL": "C"})
    try:
        for attempt in range(50):
            try:
                before = request()[1]
                break
            except urllib.error.URLError:
                time.sleep(0.1)
        else:
            raise RuntimeError("Local PHP server did not become ready")
        request("reset-rate-fixture")
        status, _ = request("zmai_save_avatar", fields={"_wpnonce": "invalid", "avatar_type": "user"}, image=("user_avatar", "avatar.png", png))
        check(status == 403 and request()[1]["id"] == before["id"], "invalid upload nonce cannot change avatar")
        status, _ = request("zmai_save_avatar", fields={"_wpnonce": nonce("zmai_save_avatar"), "avatar_type": "default"}, image=("default_avatar", "avatar.png", png))
        check(status == 403, "subscriber cannot change site default")
        status, result = request("zmai_save_avatar", fields={"_wpnonce": nonce("zmai_save_avatar"), "avatar_type": "user"}, image=("user_avatar", "avatar.png", png))
        first = request()[1]
        check(status == 200 and "zmai_status=uploaded" in result.get("location", "") and first["id"] > 0, "subscriber uploads a genuine image without upload_files capability")
        request("zmai_save_avatar", fields={"_wpnonce": nonce("zmai_save_avatar"), "avatar_type": "user"}, image=("user_avatar", "next.png", png))
        second = request()[1]
        check(second["id"] > 0 and second["id"] != first["id"] and second["count"] == first["count"], "replacement removes previous managed attachment")
        status, result = request("zmai_save_avatar", fields={"_wpnonce": nonce("zmai_save_avatar"), "avatar_type": "user"}, image=("user_avatar", "fake.png", b"not an image"))
        check("zmai_status=upload-error" in result.get("location", "") and request()[1]["id"] == second["id"], "invalid image is rejected without replacing current avatar")
        # A real multipart APNG rejection preserves the previous avatar.
        animation = struct.pack(">II", 2, 0)
        chunk = struct.pack(">I", len(animation)) + b"acTL" + animation
        chunk += struct.pack(">I", zlib.crc32(b"acTL" + animation) & 0xffffffff)
        apng = png[:33] + chunk + png[33:]
        status, result = request("zmai_save_avatar", fields={"_wpnonce": nonce("zmai_save_avatar"), "avatar_type": "user"}, image=("user_avatar", "animation.png", apng))
        check("zmai_status=upload-error" in result.get("location", "") and request()[1]["id"] == second["id"], "animated PNG multipart upload preserves the existing avatar")
        request("block-fixture")
        status, _ = request("zmai_save_avatar", fields={"_wpnonce": nonce("zmai_save_avatar"), "avatar_type": "user"}, image=("user_avatar", "blocked.png", png))
        check(status == 403 and request()[1]["id"] == 0, "blocked subscriber cannot upload")
        request("unblock-fixture")
        status, result = request("zmai_save_avatar", "admin", fields={"_wpnonce": nonce("zmai_save_avatar", "admin"), "avatar_type": "default"}, image=("default_avatar", "default.png", png))
        check("zmai_status=uploaded" in result.get("location", "") and request()[1]["default"] > 0, "administrator uploads the site default")
        status, _ = request("zmai_save_settings", fields={"_wpnonce": nonce("zmai_save_settings"), "upload_policy_mode": "roles"})
        check(status == 403, "subscriber cannot save upload policy")
        status, _ = request("zmai_save_settings", "admin", fields={"_wpnonce": "invalid", "upload_policy_mode": "roles"})
        check(status == 403, "policy settings require a valid administrator nonce")
        status, _ = request("zmai_save_settings", "admin", **{"_wpnonce": nonce("zmai_save_settings", "admin")})
        check(status == 405, "policy settings reject GET even with valid nonce")
        fields = {"_wpnonce": nonce("zmai_save_settings", "admin"), "upload_policy_mode": "roles", "avatar_size": "150"}
        status, result = request("zmai_save_settings", "admin", fields=fields)
        check("zmai_status=settings-saved" in result.get("location", ""), "administrator saves an explicit empty role list")
        status, _ = request("zmai_save_avatar", fields={"_wpnonce": nonce("zmai_save_avatar"), "avatar_type": "user"}, image=("user_avatar", "denied.png", png))
        check(status == 403, "new policy denies direct authenticated upload with preexisting nonce")
        status, result = request("zmai_remove_avatar", fields={"_wpnonce": nonce("zmai_remove_avatar"), "avatar_type": "user"})
        check("zmai_status=removed" in result.get("location", ""), "role exclusion preserves self removal")
        fields["upload_policy_mode"] = "bad-mode"
        request("zmai_save_settings", "admin", fields=fields)
        check(request("policy-fixture")[1]["mode"] == "roles", "invalid settings preserve prior policy")
        request("zmai_save_settings", "admin", fields={"_wpnonce": nonce("zmai_save_settings", "admin"), "avatar_size": "150"})
        check(request("policy-fixture")[1]["mode"] == "roles", "partial older settings POST preserves policy")
        fields["upload_policy_mode"] = "legacy"
        request("zmai_save_settings", "admin", fields=fields)
        request("reset-rate-fixture")
        locations = []
        for _ in range(6):
            status, result = request("zmai_save_avatar", fields={"_wpnonce": nonce("zmai_save_avatar"), "avatar_type": "user"}, image=("user_avatar", "rate.png", png))
            locations.append(result.get("location", ""))
        check(all("zmai_status=uploaded" in url for url in locations[:5]) and "zmai_status=upload-error" in locations[5], "real multipart sixth authorized attempt is throttled")
        request("reset-rate-fixture")

    finally:
        server.terminate()
        server.wait(timeout=10)
print(json.dumps({"checks": len(checks), "failures": [name for ok, name in checks if not ok]}))
raise SystemExit(0 if all(ok for ok, _ in checks) else 1)
