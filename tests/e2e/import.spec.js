/* Integrator-owned browser run. Setup importer fixtures using local WP-CLI only.
 * E2E_IMPORT_FIXTURE_JSON points to a scratch JSON file produced by
 * tests/import-e2e-fixture.php: {source,sourceUserIds,frontUrl,avatarSelector,
 * expectedRGB:[0,200,50],missingPath,repairPath}. Never create these via HTTP.
 * Fixture must contain >40 users and one missing URL-only source file.
 */
const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const {login} = require('./helpers');
const importer = '/wp-admin/users.php?page=author-or-user-image-import';
const fixturePath = process.env.E2E_IMPORT_FIXTURE_JSON;
const data = fixturePath ? JSON.parse(fs.readFileSync(fixturePath, 'utf8')) : null;
test.describe('competitor importer', () => {
  test.skip(!data, 'Integrator must prepare isolated local import fixtures with WP-CLI.');
  test.beforeEach(async ({page}) => {
    await page.route(/https?:\/\/([^/]*\.)?gravatar\.com\//, route => route.abort());
  });
  test('admin starts, leaves, resumes, repairs and sees copied avatar pixels', async ({page}) => {
    test.setTimeout(120000);
    expect(data.sourceUserIds.length).toBeGreaterThan(40);
    await login(page, 'admin');
    await page.goto(importer);
    await page.getByLabel('Source plugin').selectOption(data.source);
    // Freeze after one batch by navigating away once persisted progress changes.
    let batches = 0;
    await page.route('**/wp-admin/admin-post.php', async route => {
      const body = new URLSearchParams(route.request().postData() || '');
      if (body.get('action') === 'zmai_import_batch' && ++batches > 1) {
        await route.abort(); return;
      }
      await route.continue();
    });
    await page.getByRole('button', {name:'Import avatars', exact:true}).click();
    await expect(page.locator('[data-zmai-count="inspected"]')).not.toHaveText('0');
    await page.goto('/wp-admin/');
    await page.unroute('**/wp-admin/admin-post.php');
    await page.goto(importer);
    await expect(page.locator('[data-zmai-count="inspected"]')).not.toHaveText('0');
    await page.getByRole('button', {name:/Continue|Resume/, exact:true}).click();
    await expect(page.locator('[data-zmai-state]')).toHaveText('paused_with_failures', {timeout:90000});
    await expect(page.locator('[data-zmai-count="failed"]')).not.toHaveText('0');
    // Missing file restoration is a local fixture action; source metadata stays intact.
    fs.copyFileSync(data.repairPath, data.missingPath);
    await page.getByRole('button', {name:'Retry repaired sources', exact:true}).click();
    await expect(page.locator('[data-zmai-state]')).toHaveText('complete', {timeout:90000});
    await expect(page.locator('[data-zmai-count="failed"]')).toHaveText('0');
    await page.goto(data.frontUrl);
    const image = page.locator(data.avatarSelector).first();
    await expect(image).toHaveAttribute('src', /zmai-import-/);
    const rgb = await image.evaluate(async img => {
      await img.decode();
      const canvas = document.createElement('canvas'); canvas.width = img.naturalWidth; canvas.height = img.naturalHeight;
      const ctx = canvas.getContext('2d'); ctx.drawImage(img,0,0);
      return [...ctx.getImageData(Math.floor(canvas.width/2),Math.floor(canvas.height/2),1,1).data].slice(0,3);
    });
    expect(rgb).toEqual(data.expectedRGB);
  });
  test('subscriber cannot view importer or execute admin-post', async ({page}) => {
    await login(page);
    const response = await page.goto(importer);
    expect(response.status()).toBe(403);
    const result = await page.request.post('/wp-admin/admin-post.php', {form:{action:'zmai_import_start', source:data.source, revision:'0', run_token:'bad', site_id:'1'}});
    expect(result.status()).toBe(403);
  });
  test('JavaScript disabled exposes native bounded Continue forms', async ({browser, baseURL}) => {
    const context = await browser.newContext({baseURL, javaScriptEnabled:false});
    const page = await context.newPage();
    // JS-off login bypasses the shared helper's core autofocus expectation.
    await page.goto('/wp-login.php');
    await page.locator('#user_login').fill('morganreed');
    await page.locator('#user_pass').fill(process.env.E2E_PASSWORD || 'Local-avatar-demo-2026!');
    await page.locator('#wp-submit').click();
    await page.goto(importer);
    await expect(page.locator('form[action$="admin-post.php"]')).not.toHaveCount(0);
    const button = page.getByRole('button', {name:/Continue|Resume|Retry repaired sources|Import avatars/, exact:true}).first();
    await expect(button).toBeVisible();
    // Fresh fixture or a persisted running job are both explicit operator actions.
    await button.click();
    await expect(page).toHaveURL(/author-or-user-image-import/);
    await expect(page.locator('[aria-live="polite"]')).toBeVisible();
    await context.close();
  });
});
