/* Sequential import POSTs stay bound to the initiating job and source. */
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
async function scenario(name, operation, responses, expectedActions) {
  const controls = [{tag:'input', disabled:false}, {tag:'button', disabled:false}, {tag:'select', disabled:false}];
  const message = {textContent:''};
  let submit, reloads = 0;
  const root = {
    querySelector: selector => selector === '[data-zmai-message]' ? message : (selector.includes('data-zmai-operation') ? null : {textContent:''}),
    querySelectorAll: () => controls,
    addEventListener: (event, callback) => { submit = callback; },
    appendChild: () => {}, getAttribute: () => 'Reload before continuing'
  };
  const document = {querySelector: () => root, createElement: () => ({addEventListener: () => {}, remove: () => {}})};
  class FormData extends Map { constructor(form) { super(form ? Object.entries(form.fields) : []); } }
  const window = {fetch:true, FormData, URLSearchParams, location:{reload:() => { reloads++; }}};
  const actions = [];
  const fetch = async (url, request) => {
    assert.equal(url, '/wp-admin/admin-post.php');
    actions.push(request.body.get('action'));
    const job = responses[actions.length - 1];
    if (!job) { throw Error('ambiguous'); }
    return {ok:true, json:async () => ({success:true, data:{job, nonces:{batch:'batch-nonce', pause:'pause-nonce'}}})};
  };
  vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/import.js', 'utf8'), {document, window, FormData, fetch});
  const fields = {action:'zmai_import_' + operation, site_id:'1', source:'one-user-avatar', run_token:'requested-id', job_id:'requested-id'};
  await submit({target:{fields, matches:() => true, getAttribute:key => key === 'action' ? '/wp-admin/admin-post.php' : operation}, preventDefault:() => {}});
  assert.deepEqual(actions, expectedActions, name + ': follow-up POSTs');
  assert.ok(controls.every(c => c.disabled), name + ': controls remain disabled');
  assert.equal(reloads, expectedActions.length > 1 && responses[responses.length - 1].state === 'complete' ? 1 : 0, name + ': reload only after accepted terminal response');
  if (!reloads) { assert.ok(message.textContent, name + ': refresh required'); }
  console.log('PASS ' + name);
}
const job = (id='requested-id', source='one-user-avatar', state='running', site_id=1) => ({id, source, state, site_id, revision:1, counts:{imported:0}});
(async () => {
  await scenario('ambiguous failure keeps mutation controls disabled', 'start', [], ['zmai_import_start']);
  await scenario('stale Start never batches a different id', 'start', [job('other-id')], ['zmai_import_start']);
  await scenario('stale Start never batches a different source', 'start', [job('requested-id', 'wp-user-avatars')], ['zmai_import_start']);
  await scenario('Start never batches another site', 'start', [job('requested-id', 'one-user-avatar', 'running', 2)], ['zmai_import_start']);
  await scenario('same-token Start continues its source', 'start', [job(), job('requested-id', 'one-user-avatar', 'complete')], ['zmai_import_start','zmai_import_batch']);
  await scenario('later batch cannot switch jobs', 'start', [job(), job('other-id')], ['zmai_import_start','zmai_import_batch']);
  await scenario('Resume remains bound to submitted id', 'resume', [job('other-id')], ['zmai_import_resume']);
  await scenario('Resume binds source for every later response', 'resume', [job(), job('requested-id','wp-user-avatars')], ['zmai_import_resume','zmai_import_batch']);
})().catch(error => { console.error('FAIL ' + error.message); process.exitCode = 1; });
