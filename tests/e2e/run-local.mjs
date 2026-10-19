import {spawn,execFileSync} from 'node:child_process';
import net from 'node:net';
import {createHash} from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
const site=process.env.E2E_WP_PATH;
const artifacts=process.env.E2E_ARTIFACT_DIR;
const php=process.env.E2E_PHP || '/Users/alim/Library/Application Support/Herd/bin/php';
if(!site || !artifacts || !fs.existsSync(path.join(site,'.zmai-test-instance'))) throw Error('Set E2E_WP_PATH to the marked disposable fixture and E2E_ARTIFACT_DIR.');
const wp=(...args)=>execFileSync(php,['/usr/local/bin/wp',`--path=${site}`,...args],{encoding:'utf8'}).trim();
if(wp('eval','echo wp_get_environment_type();')!=='local') throw Error('Fixture must be local.');
const socket=net.createServer();
await new Promise(resolve=>socket.listen(0,'127.0.0.1',resolve));
const port=socket.address().port;
await new Promise(resolve=>socket.close(resolve));
const url=`http://127.0.0.1:${port}`;
const oldHome=wp('option','get','home');
const oldSite=wp('option','get','siteurl');
fs.mkdirSync(artifacts,{recursive:true});
const provenance={wordpress:wp('core','version'),php:execFileSync(php,['-r','echo PHP_VERSION;'],{encoding:'utf8'}),pluginVersion:wp('eval','echo ZMAI_VERSION;'),pluginSource:wp('eval',String.raw`echo (new ReflectionClass('AuthorOrUserImage\Image_Store'))->getFileName();`),database:wp('eval',String.raw`echo get_class($GLOBALS['wpdb']);`),baseURL:url,startedAt:new Date().toISOString()};
const sourceRoot=path.dirname(path.dirname(provenance.pluginSource));
const sourceFiles=['author_image.php',...fs.readdirSync(path.join(sourceRoot,'includes')).filter(name=>name.endsWith('.php')).map(name=>`includes/${name}`)];
const hashSource=()=>Object.fromEntries(sourceFiles.map(name=>[name,createHash('sha256').update(fs.readFileSync(path.join(sourceRoot,name))).digest('hex')]));
provenance.sourceHashesBefore=hashSource();
fs.writeFileSync(path.join(artifacts,'runtime-provenance.json'),JSON.stringify(provenance,null,2)+'\n');
const log=fs.openSync(path.join(artifacts,'php-server.log'),'a');
let server;
let stopped=false;
function cleanup(){
 if(stopped)return;stopped=true;
 if(server)server.kill('SIGTERM');
 for (const [key,value] of [['home',oldHome],['siteurl',oldSite]]) {
  try { wp('option','update',key,value); } catch(error) { console.error(`Could not restore ${key}: ${error.message}`); process.exitCode=1; }
 }
 fs.closeSync(log);
}
for(const signal of ['SIGINT','SIGTERM'])process.on(signal,()=>{cleanup();process.exit(130);});
try{
 fs.writeFileSync(path.join(artifacts,'runner-recovery.json'),JSON.stringify({site,home:oldHome,siteurl:oldSite,url},null,2)+'\n');
 wp('option','update','home',url);wp('option','update','siteurl',url);
 // The API test router impersonates users and emits JSON; use PHP's normal
 // document-root routing here so real WordPress login/forms remain intact.
 server=spawn(php,['-d','upload_max_filesize=12M','-d','post_max_size=16M','-S',`127.0.0.1:${port}`,'-t',site],{cwd:site,stdio:['ignore',log,log]});
 for(let n=0;n<100;n++){
  try{const response=await fetch(`${url}/wp-login.php`);if(response.ok)break;}catch{}
  if(n===99)throw Error('PHP server did not become ready');
  await new Promise(resolve=>setTimeout(resolve,100));
 }
 if (server.exitCode !== null) throw Error('PHP exited before the test run');
 console.log(`Ephemeral fixture: ${url}`);
 const screenshot=process.argv.includes('--screenshots');
 const args=['playwright','test',screenshot?'--grep':'--grep-invert','@screenshots'];
 const child=spawn('npx',args,{cwd:import.meta.dirname,stdio:'inherit',env:{...process.env,E2E_BASE_URL:url,PLAYWRIGHT_BROWSERS_PATH:process.env.PLAYWRIGHT_BROWSERS_PATH || path.join(process.env.HOME,'Library/Caches/ms-playwright')}});
 process.exitCode=await new Promise((resolve,reject)=>{child.once('error',reject);child.once('exit',code=>resolve(code??1));});
}finally{
 cleanup();
 provenance.sourceHashesAfter=hashSource();
 provenance.sourceUnchanged=JSON.stringify(provenance.sourceHashesBefore)===JSON.stringify(provenance.sourceHashesAfter);
 provenance.finishedAt=new Date().toISOString();
 fs.writeFileSync(path.join(artifacts,'runtime-provenance.json'),JSON.stringify(provenance,null,2)+'\n');
 if(!provenance.sourceUnchanged){console.error('Plugin PHP changed during the run; results need revalidation.');process.exitCode=1;}
}
