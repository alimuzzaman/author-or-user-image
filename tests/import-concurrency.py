#!/usr/bin/env python3
"""Two real CLI workers contend while core generates destination image metadata."""
import argparse, json, subprocess, time, os, uuid
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--site',required=True);p.add_argument('--plugin',required=True);p.add_argument('--php',required=True);a=p.parse_args()
root=Path('/Users/alim/Sites/git/.scratch/author-or-user-image/v3.1/2b')/('workers-'+uuid.uuid4().hex);root.mkdir()
base=[a.php,'/usr/local/bin/wp','--path='+a.site,'--require='+a.plugin+'/tests/candidate-bootstrap.php','eval-file',a.plugin+'/tests/import-worker.php']
env=dict(os.environ,TMPDIR=str(root));failed=0;checks=0;worker=None
log=(root/'commands.txt').open('w')
def run(mode):
 cmd=base+[mode,str(root)];r=subprocess.run(cmd,text=True,capture_output=True,timeout=90,env=env)
 log.write(json.dumps(cmd)+'\n'+r.stdout+r.stderr+'\nEXIT '+str(r.returncode)+'\n');log.flush()
 if r.returncode:raise RuntimeError('CLI '+mode+' failed; see scratch commands')
 return r.stdout.strip().splitlines()[-1]
def check(ok,label):
 global failed,checks
 checks+=1;failed+=not ok;print(('PASS ' if ok else 'FAIL ')+label,flush=True)
try:
 run('setup')
 worker=subprocess.Popen(base+['hold',str(root)],text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=env)
 deadline=time.monotonic()+25
 while not (root/'ready').exists() and time.monotonic()<deadline and worker.poll() is None:time.sleep(.05)
 check((root/'ready').exists(),'independent importer reaches real metadata staging under migration and owner locks')
 competing=json.loads(run('batch'));check(competing.get('error')=='zmai_busy','second importer worker cannot advance a live batch')
 legacy=json.loads(run('legacy'));check(legacy.get('error')=='zmai_busy','legacy coordinator cannot enter during competitor metadata staging')
 (root/'release').write_text('release')
 out,err=worker.communicate(timeout=60);log.write('PRIMARY\n'+out+err+'\nEXIT '+str(worker.returncode)+'\n');log.flush()
 first=json.loads(out.strip().splitlines()[-1]);check(worker.returncode==0 and first.get('state')=='complete' and first['counts']['imported']==1,'first worker commits exactly one imported owner and terminal revision')
 replay=json.loads(run('verify'));check(replay['job']==first and replay['owned']==1 and replay['mapped']>0 and replay['source_unchanged'],'stale independent replay preserves one owned attachment and source bytes/meta')
finally:
 (root/'release').write_text('release')
 if worker is not None and worker.poll() is None:worker.communicate(timeout=60)
 if (root/'state.json').exists():run('cleanup')
 log.close()
print(f'RESULT {checks} checks, {failed} failed',flush=True)
raise SystemExit(bool(failed))
