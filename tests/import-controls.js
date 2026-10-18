/* Native submit controls stay disabled during and after an ambiguous import. */
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const controls = [{tag:'input', type:'submit', disabled:false}, {tag:'button', disabled:false}, {tag:'select', disabled:false}];
let submit;
const root = {
  querySelector: selector => selector === '[data-zmai-message]' ? {textContent:''} : null,
  querySelectorAll: selector => controls.filter(c => selector.includes(c.tag) || (c.tag === 'input' && selector.includes('input[type="submit"]'))),
  addEventListener: (event, callback) => { submit = callback; },
  appendChild: () => {},
  getAttribute: () => 'Stop'
};
const document = {querySelector: () => root, createElement: () => ({addEventListener: () => {}, remove: () => {}})};
const FormData = class {set() {}};
const window = {fetch: true, FormData, URLSearchParams, location:{reload:() => {}}};
vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/import.js', 'utf8'), {document, window, FormData, fetch: async () => {throw Error('ambiguous');}});
(async () => {
  await submit({target:{matches:() => true, getAttribute:() => '/wp-admin/admin-post.php'}, preventDefault:() => {}});
  assert.ok(controls.every(c => c.disabled), 'native input submit, buttons and select must stay disabled after ambiguous response');
  console.log('PASS all import mutation controls disabled during failure');
})().catch(error => { console.error('FAIL ' + error.message); process.exitCode = 1; });
