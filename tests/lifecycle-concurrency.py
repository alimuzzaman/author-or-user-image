#!/usr/bin/env python3
"""Real independent PHP workers; explicit disposable site/candidate/scratch paths only."""
import argparse
import base64
import concurrent.futures
import http.client
import json
import os
from pathlib import Path
import socket
import subprocess
import time
import urllib.parse
import uuid

parser = argparse.ArgumentParser()
parser.add_argument('--site', type=Path, required=True)
parser.add_argument('--plugin', type=Path, required=True)
parser.add_argument('--state', type=Path, required=True)
parser.add_argument('--php', default='php')
args = parser.parse_args()
if not (args.site / '.zmai-test-instance').is_file():
    raise SystemExit('Disposable site marker required.')
args.state.mkdir(parents=True, exist_ok=False)
fixture = 'zmailifecycle' + uuid.uuid4().hex[:12]
router = Path(__file__).with_name('lifecycle-router.php').resolve()
png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=')
checks, servers = [], []
executor = concurrent.futures.ThreadPoolExecutor(max_workers=2)

def request(port, mode, fields=None, image=False, **query):
    path = '/?' + urllib.parse.urlencode(dict(fixture=fixture, mode=mode, **query))
    body, headers = None, {}
    if fields is not None:
        boundary = 'zmai' + uuid.uuid4().hex
        body = b''.join(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k, v in fields.items())
        if image:
            field = 'default_avatar' if query.get('default') else 'user_avatar'
            body += f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="fixture.png"\r\nContent-Type: image/png\r\n\r\n'.encode() + png + b'\r\n'
        body += f'--{boundary}--\r\n'.encode()
        headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
    connection = http.client.HTTPConnection('127.0.0.1', port, timeout=30)
    connection.request('POST' if fields is not None else 'GET', path, body, headers)
    response = connection.getresponse()
    raw = response.read()
    connection.close()
    if response.status != 200:
        (args.state / ('http-error-' + mode + '.html')).write_bytes(raw[:32768])
        raise RuntimeError(f'{mode}: HTTP {response.status}; response saved under scratch http-error-{mode}.html; body tail {raw[-1200:]!r}')
    return json.loads(raw)

def check(ok, label, data=None):
    checks.append(dict(passed=bool(ok), label=label, data=data))
    print(('PASS ' if ok else 'FAIL ') + label, flush=True)

def fields(port, action, default=False):
    nonce = request(port, 'nonce', default=int(default), **{'for': 'zmai_' + action})['nonce']
    return dict(_wpnonce=nonce, avatar_type='default' if default else 'user', user_action='delete')

def upload(port, default=False, **query):
    return request(port, 'upload', fields=fields(port, 'save_avatar', default), image=True, default=int(default), **query)

def remove(port, default=False, **query):
    return request(port, 'remove', fields=fields(port, 'remove_avatar', default), default=int(default), **query)

def arrival(name):
    deadline = time.monotonic() + 15
    while time.monotonic() < deadline:
        file = args.state / ('arrival-' + name)
        if file.is_file(): return json.loads(file.read_text())
        time.sleep(.02)
    raise TimeoutError('No barrier ' + name)

def release(name):
    (args.state / ('release-' + name)).write_text('release\n')

def success(result, status):
    return urllib.parse.parse_qs(urllib.parse.urlparse(result.get('location', '')).query).get('zmai_status') == [status]

def busy(result):
    # Existing admin error channel carries a retryable message, without a success status.
    return bool(result.get('location')) and not any(success(result, s) for s in ('uploaded', 'removed', 'users-updated'))

try:
    ports = []
    for index in range(2):
        sock = socket.socket()
        sock.bind(('127.0.0.1', 0))
        ports.append(sock.getsockname()[1])
        sock.close()
        log = (args.state / f'php-{index}.log').open('wb')
        env = dict(os.environ, ZMAI_TEST_PLUGIN=str(args.plugin.resolve()), ZMAI_TEST_STATE=str(args.state.resolve()))
        process = subprocess.Popen([args.php, '-S', f'127.0.0.1:{ports[-1]}', str(router)], cwd=args.site, env=env, stdout=log, stderr=log)
        servers.append((process, log))
    a, b = ports
    for port in ports:
        deadline = time.monotonic() + 10
        while True:
            try:
                snap = request(port, 'snapshot')
                break
            except (ConnectionError, OSError):
                if time.monotonic() > deadline: raise
                time.sleep(.05)
    check(Path(snap['plugin']) == args.plugin.resolve(), 'router loads the requested candidate', snap)
    for default in (False, True):
        label = 'default' if default else 'user'
        remove(a, default)
        upload(a, default)
        old = request(a, 'snapshot', default=int(default))['mapped']
        name = label + 'media'
        pending = executor.submit(upload, a, default, pause='media', barrier=name)
        created = arrival(name)['id']
        before = request(b, 'snapshot', default=int(default))
        denied = upload(b, default)
        after = request(b, 'snapshot', default=int(default))
        check(busy(denied), label + ': second real multipart upload returns busy', denied)
        check(after['owned'] == before['owned'], label + ': busy upload creates no attachment', after)
        removed = remove(b, default)
        check(busy(removed), label + ': concurrent removal reports busy', removed)
        if not default:
            privacy = request(b, 'erase')
            check(privacy.get('done') is False and privacy.get('items_retained') is True and privacy.get('items_removed') is False, 'privacy does not claim success while owner busy', privacy)
            bulk = request(b, 'bulk', fields=fields(b, 'manage_users', True))
            check(busy(bulk), 'bulk delete does not claim success while busy', bulk)
        release(name)
        pending.result(timeout=30)
        final = request(b, 'snapshot', default=int(default))
        check(final['mapped'] == created and set(final['owned']) == {created}, label + ': one mapped and owned attachment after contention', final)
        # A separate request caches the old mapping before a serial replacement completes.
        name = label + 'cache'
        stale = executor.submit(upload, b, default, pause='preflight', barrier=name)
        arrival(name)
        fresh = upload(a, default)
        previous = request(a, 'snapshot', default=int(default))['mapped']
        release(name)
        stale.result(timeout=30)
        final = request(a, 'snapshot', default=int(default))
        check(final['mapped'] != previous and set(final['owned']) == {final['mapped']}, label + ': replacement ignores another worker stale metadata/option cache', final)
        remove(a, default)
        check(request(a, 'snapshot', default=int(default))['owned'] == [], label + ': removal reclaims all exact-owner images')
    # Distinct removal scenario starts with a fresh synthetic upload budget.
    request(a, 'reset-rate-fixture')
    # Reverse race: removal owns the mutex before a user/default upload starts.
    for default in (False, True):
        label = 'default' if default else 'user'
        upload(a, default)
        old = request(a, 'snapshot', default=int(default))['mapped']
        name = label + 'delete'
        pending = executor.submit(remove, a, default, pause='deletion', barrier=name)
        deleting = arrival(name)
        denied = upload(b, default)
        check(busy(denied), label + ': upload cannot race past an in-progress removal', denied)
        check(request(b, 'exists', id=old)['file'] and request(b, 'exists', id=old)['exists'], label + ': removal retains row and file inventory while paused')
        release(name)
        check(success(pending.result(timeout=30), 'removed') and not request(b, 'exists', id=old)['exists'] and not Path(deleting['file']).is_file(), label + ': completed removal deletes the original row and file')
        check(success(upload(b, default), 'uploaded') and len(request(b, 'snapshot', default=int(default))['owned']) == 1, label + ': serial upload succeeds after removal releases the mutex')
        remove(a, default)
    # Independent workers contend on the final available upload attempt.
    request(a, 'prime-rate-fixture')
    name = 'userrate'
    pending = executor.submit(upload, a, False, pause='media', barrier=name)
    arrival(name)
    denied = upload(b)
    check(busy(denied), 'rate: another worker cannot pass the locked fifth attempt', denied)
    release(name)
    check(success(pending.result(timeout=30), 'uploaded'), 'rate: fifth attempt completes in the first worker')
    denied = upload(b)
    snap = request(b, 'snapshot')
    check(busy(denied) and snap['rate']['minute_count'] == 5 and len(snap['owned']) == 1, 'rate: retry is throttled with one owned image and bounded counter', snap)
    remove(a)
    own = request(a, 'seed')
    foreign = request(a, 'seed', foreign=1, map=1)
    erased = request(a, 'erase', page=2)
    check(erased.get('done') is True and request(a, 'snapshot')['owned'] == [] and not request(a, 'exists', id=own['id'])['file'], 'privacy retry page removes pre-existing owned orphan', erased)
    check(request(a, 'exists', id=foreign['id'])['exists'], 'privacy preserves other-owner mapped attachment')
    own = request(a, 'seed', map=1)
    failed = request(a, 'erase', denydelete=1)
    check(failed.get('done') is True and failed.get('items_retained') is True and not failed.get('items_removed'), 'privacy reports a real core attachment-delete failure', failed)
    retried = request(a, 'erase', page=2)
    check(retried.get('done') is True and request(a, 'snapshot')['owned'] == [], 'privacy safely retries failed cleanup on later page', retried)
except Exception as error:
    check(False, 'harness completed without exception', str(error))
finally:
    for name in ('usermedia', 'defaultmedia', 'usercache', 'defaultcache', 'userdelete', 'defaultdelete', 'userrate'):
        release(name)
    executor.shutdown(wait=True)
    for process, log in servers:
        process.terminate()
        try: process.wait(timeout=5)
        except subprocess.TimeoutExpired: process.kill(); process.wait()
        log.close()
    report = dict(fixture=fixture, checks=checks, failures=sum(not x['passed'] for x in checks))
    (args.state / 'result.json').write_text(json.dumps(report, indent=2) + '\n')
print(json.dumps(report, indent=2))
raise SystemExit(bool(report['failures']))
