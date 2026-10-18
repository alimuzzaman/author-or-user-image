/* Real login and native submission on the marked, disposable single-site fixture. */
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const artifactDir = process.env.E2E_ARTIFACT_DIR;
const fixture = JSON.parse(fs.readFileSync(path.join(artifactDir, 'front-fixture.json'), 'utf8'));
const wp = (code) => execFileSync(process.env.E2E_PHP || '/Users/alim/Library/Application Support/Herd/bin/php85', [
  '/usr/local/bin/wp', `--path=${process.env.E2E_WP_PATH}`, 'eval', code
], { encoding: 'utf8' }).trim();
const image = (name) => path.join(__dirname, 'fixtures', `${name}.png`);
const wrapper = (page) => page.locator('.zmai-front-end').first();
async function login(page) {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(fixture.login);
  await page.locator('#user_pass').fill('Local-avatar-demo-2026!');
  await page.locator('#wp-submit').click();
  await expect(page.locator('#wpadminbar')).toBeVisible();
}
async function reset() {
  wp(`$s=new AuthorOrUserImage\\Image_Store(); $s->set_blocked(${fixture.user},false); $s->delete_user_avatar(${fixture.user}); delete_user_meta(${fixture.user},AuthorOrUserImage\\Image_Store::user_meta_key(AuthorOrUserImage\\Image_Store::UPLOAD_RATE_META)); delete_option(AuthorOrUserImage\\Upload_Policy::OPTION);`);
}
async function upload(page, name = 'maya') {
  const form = wrapper(page).locator('.zmai-upload-form');
  await form.locator('input[type=file]').setInputFiles(image(name));
  const response = page.waitForResponse(r => r.url().includes('/wp-admin/admin-post.php') && r.request().method() === 'POST');
  await form.getByRole('button', { name: 'Upload image', exact: true }).click();
  const result = await response;
  expect(result.status()).toBe(303);
  await expect(wrapper(page).getByRole('status')).toContainText('The image was uploaded.');
  const preview = wrapper(page).locator('.zmai-avatar-preview');
  await preview.scrollIntoViewIfNeeded();
  await expect(preview).toBeVisible();
  await expect.poll(() => preview.evaluate(el => el.complete && el.naturalWidth > 0)).toBe(true);
  return preview.getAttribute('src');
}
async function fields(page, kind = 'save') {
  return wrapper(page).locator(kind === 'save' ? '.zmai-upload-form' : '.zmai-remove-form').locator('input[type=hidden]').evaluateAll(nodes => Object.fromEntries(nodes.map(el => [el.name, el.value])));
}
async function post(page, values) {
  return page.request.post('/wp-admin/admin-post.php', { form: values, maxRedirects: 0 });
}
test.use({ javaScriptEnabled: false });
test('oversized native upload returns a clear error after PHP discards the body', async ({ page }) => {
  await login(page);
  await page.goto(`/?p=${fixture.post}`);
  const form = wrapper(page).locator('.zmai-upload-form');
  expect(await form.getAttribute('action')).toContain('action=zmai_save_avatar');
  await form.locator('input[type=file]').setInputFiles({name:'too-large.jpg', mimeType:'image/jpeg', buffer:Buffer.alloc(17 * 1024 * 1024)});
  const response = page.waitForResponse(r => r.url().includes('/wp-admin/admin-post.php') && r.request().method() === 'POST');
  await form.getByRole('button', {name:'Upload image', exact:true}).click();
  expect((await response).status()).toBe(303);
  await expect(wrapper(page).getByRole('status')).toContainText('The upload exceeds the server request limit. Choose a smaller image.');
  expect(page.url()).toContain(`p=${fixture.post}`);
  expect(wp(`echo (new AuthorOrUserImage\\Image_Store())->get_user_attachment_id(${fixture.user});`)).toBe('0');
});
test.describe.configure({ mode: 'serial' });
test.beforeEach(async ({ page }) => {
  await page.route('**/*', route => new URL(route.request().url()).hostname === '127.0.0.1' ? route.continue() : route.abort());
  await reset();
});
test('guest sees login prompts for the block and shortcode, with no personalized data', async ({ page }) => {
  for (const url of [`/?p=${fixture.post}`, `/?page_id=${fixture.page}`]) {
    await page.goto(url);
    await expect(wrapper(page).getByRole('link', { name: 'Log in to manage your avatar.' })).toBeVisible();
    await expect(page.locator('.zmai-front-end input[name=_wpnonce]')).toHaveCount(0);
    await expect(page.locator('.zmai-front-end input[type=file]')).toHaveCount(0);
  }
});
test('block uploads, replaces and removes without JavaScript, with genuine get_avatar output', async ({ page }) => {
  await login(page);
  const response = await page.goto(`/?p=${fixture.post}`);
  expect(response.headers()['cache-control']).toMatch(/no-cache|no-store/);
  const first = await upload(page);
  expect(page.url()).toContain(`p=${fixture.post}`);
  expect(wp(`echo get_avatar(${fixture.user},96);`)).toContain(first);
  const firstId = wp(`echo (new AuthorOrUserImage\\Image_Store())->get_user_attachment_id(${fixture.user});`);
  const second = await upload(page, 'maya-new');
  expect(second).not.toBe(first);
  expect(wp(`echo get_post(${Number(firstId)}) ? 'present' : 'gone';`)).toBe('gone');
  const result = page.waitForResponse(r => r.url().includes('/wp-admin/admin-post.php') && r.request().method() === 'POST');
  await wrapper(page).getByRole('button', { name: 'Remove image', exact: true }).click();
  expect((await result).status()).toBe(303);
  await expect(wrapper(page).getByRole('status')).toContainText('The image was removed.');
  await expect(wrapper(page).locator('.zmai-avatar-preview')).toHaveCount(0);
});
test('shortcode page delivers scoped CSS after the head and supports multiple forms at phone width', async ({ page }) => {
  await login(page);
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto(`/?page_id=${fixture.page}`);
  await expect(page.locator('.zmai-front-end')).toHaveCount(2);
  const css = page.locator('link[href*="assets/css/front-end.css"]');
  expect(await css.count()).toBeGreaterThan(0);
  const cssResponse = await page.request.get(await css.first().getAttribute('href'));
  expect(cssResponse.ok()).toBe(true);
  expect(await cssResponse.text()).toContain('.zmai-front-end');
  expect(await wrapper(page).evaluate(el => getComputedStyle(el).borderTopWidth)).toBe('1px');
  const ids = await page.locator('.zmai-front-end [id]').evaluateAll(nodes => nodes.map(el => el.id));
  expect(new Set(ids).size).toBe(ids.length);
  const input = wrapper(page).getByLabel('Choose your avatar image');
  await input.focus();
  await expect(input).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(wrapper(page).getByRole('button', { name: 'Upload image', exact: true })).toBeFocused();
  await upload(page);
  expect(page.url()).toContain(`page_id=${fixture.page}`);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
test('role exclusion keeps removal available and rejects a valid pre-policy upload', async ({ page }) => {
  await login(page);
  await page.goto(`/?p=${fixture.post}`);
  await upload(page);
  const valid = await fields(page);
  wp(`update_option(AuthorOrUserImage\\Upload_Policy::OPTION,array('schema'=>1,'mode'=>'roles','roles'=>array('administrator')),false);`);
  const denied = await post(page, valid);
  expect(denied.status()).toBe(303);
  expect(denied.headers().location).toContain('zmai_status=denied');
  await page.reload();
  await expect(wrapper(page)).toContainText('Your role is not allowed');
  await expect(wrapper(page).locator('input[type=file]')).toHaveCount(0);
  await wrapper(page).getByRole('button', { name: 'Remove image', exact: true }).click();
  await expect(wrapper(page).getByRole('status')).toContainText('The image was removed.');
});
test('per-user block rejects an existing valid nonce and shows the blocked reason', async ({ page }) => {
  await login(page);
  await page.goto(`/?p=${fixture.post}`);
  const valid = await fields(page);
  wp(`(new AuthorOrUserImage\\Image_Store())->set_blocked(${fixture.user},true);`);
  const denied = await post(page, valid);
  expect(denied.status()).toBe(303);
  expect(denied.headers().location).toContain('zmai_status=denied');
  await page.reload();
  await expect(wrapper(page)).toContainText('An administrator has disabled');
  await expect(wrapper(page).locator('input[type=file]')).toHaveCount(0);
});
test('sixth real upload attempt is rate limited and retains the fifth avatar', async ({ page }) => {
  await login(page);
  await page.goto(`/?p=${fixture.post}`);
  let retained;
  for (let n = 0; n < 5; ++n) retained = await upload(page, n % 2 ? 'maya-new' : 'maya');
  await wrapper(page).locator('input[type=file]').setInputFiles(image('maya-new'));
  const response = page.waitForResponse(r => r.url().includes('/wp-admin/admin-post.php') && r.request().method() === 'POST');
  await wrapper(page).getByRole('button', { name: 'Upload image', exact: true }).click();
  expect((await response).status()).toBe(303);
  await expect(wrapper(page).getByRole('status')).toContainText('Too many avatar uploads');
  await expect(wrapper(page).locator('.zmai-avatar-preview')).toHaveAttribute('src', retained);
});
test('missing, wrong-user and expired nonces fail without changing an existing avatar', async ({ page }) => {
  await login(page);
  await page.goto(`/?p=${fixture.post}`);
  const retained = await upload(page);
  const valid = await fields(page, 'remove');
  const wrongUser = wp(`wp_set_current_user(1);echo wp_create_nonce('zmai_remove_avatar');`);
  // Use this browser session's token and an old nonce tick. Pass synthetic auth
  // material through stdin so it never appears in a process argument or log.
  const cookies = await page.context().cookies();
  const cookie = cookies.find(item => item.name.startsWith('wordpress_logged_in_'));
  const token = decodeURIComponent(cookie.value).split('|')[2];
  expect(token).toBeTruthy();
  const expired = execFileSync(process.env.E2E_PHP || '/Users/alim/Library/Application Support/Herd/bin/php85', [
    '/usr/local/bin/wp', `--path=${process.env.E2E_WP_PATH}`, 'eval-file', '/dev/stdin'
  ], { encoding: 'utf8', input: `<?php wp_set_current_user(${fixture.user}); $t=wp_nonce_tick('zmai_remove_avatar')-3; echo substr(wp_hash($t.'|zmai_remove_avatar|${fixture.user}|'.${JSON.stringify(token)},'nonce'),-12,10);` }).trim();
  for (const nonce of [undefined, 'invalid', wrongUser, expired]) {
    const values = { ...valid };
    if (nonce === undefined) delete values._wpnonce; else values._wpnonce = nonce;
    expect((await post(page, values)).status()).toBe(403);
  }
  await page.reload();
  await expect(wrapper(page).locator('.zmai-avatar-preview')).toHaveAttribute('src', retained);
  const get = await page.request.get(`/wp-admin/admin-post.php?${new URLSearchParams(valid)}`);
  expect(get.status()).toBe(405);
});
test('front handlers ignore forged owners, reject malformed/default types and restrict redirect destinations', async ({ page }) => {
  await login(page);
  await page.goto(`/?p=${fixture.post}`);
  const retained = await upload(page);
  const save = await fields(page);
  for (const extra of [{ avatar_type: 'default' }, { avatar_type: 'unknown' }, { 'avatar_type[]': 'user', avatar_type: undefined }, { zmai_context: 'unknown' }]) {
    const values = { ...save, ...extra };
    for (const key of Object.keys(values)) if (values[key] === undefined) delete values[key];
    expect((await post(page, values)).status()).toBe(400);
  }
  const remove = await fields(page, 'remove');
  const home = new URL('/', page.url()).href;
  for (const url of ['https://evil.example/', `${home}wp-admin/admin-post.php?action=logout`, `${home}%2e%2e/wp-admin/`]) {
    const result = await post(page, { ...remove, zmai_return: url, user_id: '1' });
    expect(result.status()).toBe(303);
    const location = new URL(result.headers().location);
    expect(location.origin).toBe(new URL(home).origin);
    expect(location.pathname).toBe('/');
    expect(location.searchParams.get('zmai_status')).toBe('removed');
  }
  expect(wp(`echo (new AuthorOrUserImage\\Image_Store())->get_user_attachment_id(${fixture.user});`)).toBe('0');
  expect(retained).toContain('/uploads/');
});

test.describe('block editor', () => {
  test.use({ javaScriptEnabled: true });
  test('editor insertion saves a dynamic block and reloads without storing a form or nonce', async ({ page }) => {
    const draft = Number(wp(`echo wp_insert_post(array('post_type'=>'page','post_title'=>'Phase 2A editor insertion','post_status'=>'draft','post_author'=>1));`));
    try {
      await page.goto('/wp-login.php');
      await expect(page.locator('#user_login')).toBeFocused();
      await page.locator('#user_login').fill('morganreed');
      await page.locator('#user_pass').fill('Local-avatar-demo-2026!');
      await page.locator('#wp-submit').click();
      await expect(page.locator('#wpadminbar')).toBeVisible();
      await page.goto(`/wp-admin/post.php?post=${draft}&action=edit`);
      await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks && window.wp?.blocks?.getBlockType('author-or-user-image/avatar-upload'));
      const close = page.getByRole('dialog').getByRole('button', { name: 'Close', exact: true });
      if (await close.count() && await close.first().isVisible()) await close.first().click();
      await page.evaluate(() => wp.data.dispatch('core/block-editor').insertBlocks(wp.blocks.createBlock('author-or-user-image/avatar-upload')));
      await expect(page.frameLocator('iframe[name="editor-canvas"]').getByText('On the published page, signed-in visitors can manage their own avatar.', { exact: false })).toBeVisible();
      await page.getByRole('button', { name: 'Save draft', exact: true }).click();
      await expect.poll(() => wp(`echo get_post_field('post_content',${draft});`)).toContain('wp:author-or-user-image/avatar-upload');
      const saved = wp(`echo get_post_field('post_content',${draft});`);
      expect(saved).not.toContain('_wpnonce');
      expect(saved).not.toContain('<form');
      await page.reload();
      await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().some(block => block.name === 'author-or-user-image/avatar-upload'));
    } finally {
      wp(`wp_delete_post(${draft},true);`);
    }
  });
});
