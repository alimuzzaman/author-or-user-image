#!/usr/bin/env python3
"""Independent PHP exit/fatal tests of request-owned deletion shutdown fences."""
import argparse, json, subprocess
from pathlib import Path
p = argparse.ArgumentParser()
p.add_argument('--site', type=Path, required=True)
p.add_argument('--plugin', type=Path, required=True)
p.add_argument('--php', required=True)
a = p.parse_args()
base = [a.php, '/usr/local/bin/wp', '--path=' + str(a.site), '--require=' + str(a.plugin / 'tests/candidate-bootstrap.php')]
def run(tail):
    return subprocess.run(base + tail, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=90)
checks = []
for mode in ('exit', 'wpdie', 'fatal', 'foreign'):
    created = run(['eval', '$id=wp_insert_user(array("user_login"=>"shutdown-".uniqid(),"user_email"=>uniqid()."@example.invalid","user_pass"=>"public-local-test-fixture")); echo wp_json_encode($id);'])
    if created.returncode: raise RuntimeError(created.stdout)
    uid = int(json.loads(created.stdout))
    worker = subprocess.run([a.php, str(a.plugin / 'tests/deletion-veto-worker.php'), str(a.site), str(uid), mode], text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=90)
    state = run(['eval', f'$id={uid}; echo wp_json_encode(array("exists"=>(bool)get_userdata($id),"lock"=>get_option("zmai_avatar_lock_user_".$id)));'])
    if state.returncode: raise RuntimeError(state.stdout)
    value = json.loads(state.stdout)
    expected = 'replacement-foreign-token' if mode == 'foreign' else False
    ok = value['exists'] and value['lock'] == expected and (mode == 'fatal' or worker.returncode == 0)
    label = 'S2 foreign delete_user ' + mode + ' releases only request-owned successful fences at shutdown'
    print(('PASS ' if ok else 'FAIL ') + label, flush=True)
    checks.append(dict(mode=mode, passed=ok, worker_exit=worker.returncode, state=value, worker_output=worker.stdout))
    # Test-only recovery of this synthetic row, after its worker has ended.
    clean = run(['eval', f'delete_option("zmai_avatar_lock_user_{uid}"); require_once ABSPATH."wp-admin/includes/user.php"; wp_delete_user({uid});'])
    if clean.returncode: raise RuntimeError(clean.stdout)
print(json.dumps(checks))
raise SystemExit(any(not x['passed'] for x in checks))
