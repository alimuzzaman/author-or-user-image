const { test } = require('@playwright/test');
const {resetUploadRates, expect, artifacts, fixture, avatarPage, managePage, login, upload, remove, front, expectAvatar} = require('./helpers');
test.beforeEach(async ({page}) => {
  resetUploadRates();
  await page.route(/https?:\/\/([^/]*\.)?gravatar\.com\//, route => route.abort());
});
const fs = require('node:fs');
const path = require('node:path');

test('subscriber logs in, uploads and sees avatar in profile, admin bar and front end', async ({page}) => {
  await login(page);
  await upload(page);
  await expectAvatar(page, '.zmai-avatar-preview', 'maya');
  await expectAvatar(page, '#wp-admin-bar-my-account img', 'maya');
  await page.goto('/wp-admin/profile.php');
  await expectAvatar(page, '#profile-page .avatar', 'maya');
  await front(page);
  await expectAvatar(page, '.aoui-e2e-author-card img', 'maya');
  await expectAvatar(page, '.wp-block-comment-template .wp-block-avatar img', 'maya');
});

test('replacing an avatar changes its URL and front-end image', async ({page}) => {
  await login(page);
  const before = await upload(page);
  const after = await upload(page,'maya-new');
  expect(after).not.toBe(before);
  await front(page);
  await expectAvatar(page,'.aoui-e2e-author-card img','maya-new');
});

test('admin default avatar is used after subscriber removes own image', async ({page}) => {
  await login(page,'admin');
  await upload(page,'default','default_avatar');
  await login(page);
  await upload(page);
  await remove(page);
  await expect(page.locator('.zmai-card')).toContainText('No image is set.');
  await expect(page.getByRole('button',{name:'Remove image',exact:true})).toHaveCount(0);
  await front(page);
  await expectAvatar(page,'.aoui-e2e-author-card img','default');
  await login(page,'admin');
  await page.goto(avatarPage);
  await remove(page,'default');
  await front(page);
  await expect(page.locator('.aoui-e2e-author-card img')).toHaveAttribute('src', /gravatar/);
});

test('invalid file type shows an error and preserves the existing avatar', async ({page}) => {
  await login(page);
  const original = await upload(page);
  await page.locator('#user_avatar').setInputFiles({name:'invalid.txt',mimeType:'text/plain',buffer:Buffer.from('This is not an image.')});
  await page.locator('#user_avatar').locator('..').getByRole('button',{name:'Upload image',exact:true}).click();
  await expect(page.locator('.notice-error')).toContainText('Choose a JPEG, PNG, or static WebP image.');
  await expect(page.locator('.zmai-avatar-preview')).toHaveAttribute('src',original);
});

test('image over 8 MiB shows the upload-limit error and preserves avatar', async ({page}) => {
  await login(page);
  const original = await upload(page);
  // Valid PNG with trailing padding: size rejection runs before image decoding.
  const png = fs.readFileSync(path.join(__dirname,'fixtures','maya.png'));
  const bytes = Buffer.concat([png,Buffer.alloc(8*1024*1024+1-png.length)]);
  await page.locator('#user_avatar').setInputFiles({name:'oversize.png',mimeType:'image/png',buffer:bytes});
  await page.locator('#user_avatar').locator('..').getByRole('button',{name:'Upload image',exact:true}).click();
  await expect(page.locator('.notice-error')).toContainText('upload limit');
  await expect(page.locator('.zmai-avatar-preview')).toHaveAttribute('src',original);
});

test('admin blocks uploads, removes avatar, then unblocks the subscriber', async ({page}) => {
  await login(page);
  const uploadedURL = await upload(page);
  const nonce = await page.locator('input[name="_wpnonce"]').first().inputValue();
  const subscriberState = await page.context().storageState();
  await login(page,'admin');
  await page.goto(managePage);
  const row = page.getByRole('row').filter({hasText:'Maya Chen'});
  await expect(row.locator('img')).toHaveAttribute('src',uploadedURL);
  await row.getByRole('checkbox').check();
  await page.locator('#zmai-bulk-action').selectOption('block');
  page.once('dialog',dialog=>dialog.accept());
  await page.getByRole('button',{name:'Apply',exact:true}).click();
  await expect(page.locator('.notice-success')).toContainText('selected users were updated');
  await page.goto(`${managePage}&tab=blocked`);
  await expect(page.getByRole('row').filter({hasText:'Maya Chen'})).toBeVisible();
  await page.context().clearCookies();
  await page.context().addCookies(subscriberState.cookies);
  await page.goto(avatarPage);
  await expect(page.locator('.notice-warning')).toContainText('disabled local avatar uploads');
  await expect(page.locator('#user_avatar')).toHaveCount(0);
  await front(page);
  await expect(page.locator('.aoui-e2e-author-card img')).not.toHaveAttribute('src',/\/uploads\//);
  const rejected = await page.request.post('/wp-admin/admin-post.php',{multipart:{action:'zmai_save_avatar',avatar_type:'user',_wpnonce:nonce,user_avatar:{name:'blocked.png',mimeType:'image/png',buffer:fs.readFileSync(path.join(__dirname,'fixtures','maya.png'))}}});
  expect(rejected.status()).toBe(403);
  expect(await rejected.text()).toContain('uploads are disabled');
  await login(page,'admin');
  await page.goto(`${managePage}&tab=blocked`);
  await page.getByRole('row').filter({hasText:'Maya Chen'}).getByRole('checkbox').check();
  await page.locator('#zmai-bulk-action').selectOption('unblock');
  await page.getByRole('button',{name:'Apply',exact:true}).click();
  await expect(page.locator('.notice-success')).toContainText('selected users were updated');
  await login(page);
  await upload(page,'maya-new');
});

test('admin saves avatar size and sees persisted settings', async ({page}) => {
  await login(page,'admin');
  await page.goto(`${managePage}&tab=settings`);
  const originalSize = await page.getByLabel('Avatar image size').inputValue();
  const deleteData = await page.locator('input[name="delete_data"]').isChecked();
  try {
    await page.getByLabel('Avatar image size').fill('192');
    await page.getByRole('button',{name:'Save settings',exact:true}).click();
    await expect(page.locator('.notice-success')).toContainText('Settings saved.');
    await page.reload();
    await expect(page.getByLabel('Avatar image size')).toHaveValue('192');
    await expect(page.locator('input[name="delete_data"]')).toBeChecked({checked:deleteData});
  } finally {
    await page.goto(`${managePage}&tab=settings`);
    await page.getByLabel('Avatar image size').fill(originalSize);
    await page.locator('input[name="delete_data"]').setChecked(deleteData);
    await page.getByRole('button',{name:'Save settings',exact:true}).click();
  }
});

test('subscriber cannot view admin controls or submit admin-only actions', async ({page}) => {
  await login(page);
  await upload(page);
  await page.goto(avatarPage);
  await expect(page.getByRole('heading',{name:'Site default avatar',exact:true})).toHaveCount(0);
  const removeNonce = await page.locator('form').filter({has:page.locator('input[name="action"][value="zmai_remove_avatar"]')}).locator('input[name="_wpnonce"]').inputValue();
  const nonce = await page.locator('input[name="_wpnonce"]').first().inputValue();
  const protectedPage = await page.goto(managePage);
  expect(protectedPage.status()).toBe(403);
  await expect(page.locator('body')).toContainText(/not allowed|higher level of permission/);
  for (const action of ['zmai_manage_users','zmai_save_settings']) {
    const response = await page.request.post('/wp-admin/admin-post.php',{form:{action,_wpnonce:nonce,avatar_size:'1024',user_action:'block','user_ids[]':String(fixture.subscriberId)}});
    expect(response.status()).toBe(403);
    expect(await response.text()).toContain('not allowed');
  }
  const removeDenied = await page.request.post('/wp-admin/admin-post.php',{form:{action:'zmai_remove_avatar',avatar_type:'default',_wpnonce:removeNonce}});
  expect(removeDenied.status()).toBe(403);
  expect(await removeDenied.text()).toContain('not allowed to change the default');
  const response = await page.request.post('/wp-admin/admin-post.php',{multipart:{action:'zmai_save_avatar',avatar_type:'default',_wpnonce:nonce,default_avatar:{name:'default.png',mimeType:'image/png',buffer:fs.readFileSync(path.join(__dirname,'fixtures','default.png'))}}});
  expect(response.status()).toBe(403);
  expect(await response.text()).toContain('not allowed to change the default');
});

test('missing or invalid nonces reject upload, remove, management and settings', async ({page}) => {
  await login(page,'admin');
  const original = await upload(page);
  for (const action of ['zmai_save_avatar','zmai_remove_avatar','zmai_manage_users','zmai_save_settings']) {
    for (const nonce of ['', 'invalid-e2e-nonce']) {
      const response = await page.request.post('/wp-admin/admin-post.php',{form:{action,_wpnonce:nonce,avatar_type:'user',avatar_size:'1024',user_action:'block','user_ids[]':String(fixture.subscriberId)}});
      expect(response.status(),`${action} ${nonce || 'missing'}`).toBe(403);
      expect(await response.text()).toContain(action === 'zmai_save_avatar' || action === 'zmai_remove_avatar' ? 'The security check failed.' : 'link you followed has expired');
    }
  }
  await page.goto(avatarPage);
  await expect(page.locator('.zmai-card').first().locator('img')).toHaveAttribute('src',original);
});
