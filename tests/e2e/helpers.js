const { expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const uploadedURLs = new Map();
const artifacts = process.env.E2E_ARTIFACT_DIR;
if (!artifacts) throw new Error('Set E2E_ARTIFACT_DIR. See README.md.');
const fixture = JSON.parse(fs.readFileSync(path.join(artifacts, 'fixture.json')));
fixture.postId ??= fixture.post?.id;
fixture.subscriberId ??= fixture.subscriber?.id;
if (!Number.isInteger(fixture.postId) || !Number.isInteger(fixture.subscriberId)) throw new Error('fixture.json needs numeric post.id and subscriber.id');
const avatarPage = '/wp-admin/profile.php?page=author-or-user-image';
const managePage = '/wp-admin/users.php?page=author-or-user-image-manage';
async function login(page, role = 'subscriber') {
  await page.context().clearCookies();
  await page.goto('/wp-login.php');
  // Core wp_attempt_focus() focuses/selects username after a 200 ms timer.
  // Wait for that focus before filling either field so it cannot steal input.
  await expect(page.locator('#user_login')).toBeFocused();
  await page.locator('#user_login').fill(role === 'admin' ? 'morganreed' : 'mayachen');
  await page.locator('#user_pass').fill(process.env.E2E_PASSWORD || 'Local-avatar-demo-2026!');
  await page.locator('#wp-submit').click();
  await expect(page.locator('#wpadminbar')).toBeVisible();
}
async function upload(page, name = 'maya', field = 'user_avatar') {
  await page.goto(avatarPage);
  const input = page.locator(`#${field}`);
  await input.setInputFiles(path.join(__dirname, 'fixtures', `${name}.png`));
  await input.locator('..').getByRole('button', { name: 'Upload image', exact: true }).click();
  await expect(page.locator('.notice-success')).toContainText('The image was uploaded.');
  const card = field === 'default_avatar' ? page.locator('.zmai-card').filter({has:page.getByRole('heading',{name:'Site default avatar',exact:true})}) : page.locator('.zmai-card').filter({has:page.getByRole('heading',{name:'Your avatar',exact:true})});
  const image = card.locator('.zmai-avatar-preview');
  await expect(image).toBeVisible();
  await expect.poll(() => image.evaluate(img => img.complete && img.naturalWidth > 0)).toBe(true);
  const url = await image.getAttribute('src');
  uploadedURLs.set(name, url);
  return url;
}
async function remove(page, type = 'user') {
  const form = page.locator('form').filter({has:page.locator(`input[name="avatar_type"][value="${type}"]`)}).filter({has:page.getByRole('button',{name:'Remove image',exact:true})});
  await form.getByRole('button',{name:'Remove image',exact:true}).click();
  await expect(page.locator('.notice-success')).toContainText('The image was removed.');
}
async function front(page) { await page.goto(`/?p=${fixture.postId}`); }
async function expectAvatar(page, selector, filename) {
  const img = page.locator(selector).first();
  const expectedURL = uploadedURLs.get(filename);
  expect(expectedURL, `upload URL for ${filename}`).toBeTruthy();
  await expect(img).toHaveAttribute('src', expectedURL);
  await img.scrollIntoViewIfNeeded();
  await expect(img).toBeVisible();
  await expect.poll(() => img.evaluate(el => el.complete && el.naturalWidth > 0)).toBe(true);
}
function resetUploadRates() {
  const ids = [fixture.subscriberId, fixture.admin?.id].filter(Number.isInteger);
  execFileSync(process.env.E2E_PHP || '/Users/alim/Library/Application Support/Herd/bin/php85', [
    '/usr/local/bin/wp', `--path=${process.env.E2E_WP_PATH}`, 'eval',
    `$ids=json_decode('${JSON.stringify(ids)}',true);foreach($ids as $id){delete_user_meta($id,AuthorOrUserImage\\Image_Store::user_meta_key(AuthorOrUserImage\\Image_Store::UPLOAD_RATE_META));}`
  ], {encoding:'utf8'});
}
module.exports = {resetUploadRates, expect, artifacts, fixture, avatarPage, managePage, login, upload, remove, front, expectAvatar};
