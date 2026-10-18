const {test} = require('@playwright/test');
const {expect,avatarPage,managePage,login,upload,front,expectAvatar} = require('./helpers');
test.beforeEach(async ({page}) => {
  await page.route(/https?:\/\/([^/]*\.)?gravatar\.com\//, route => route.abort());
});
const fs = require('node:fs');
const path = require('node:path');
const destination = path.resolve(__dirname,'../../.wordpress-org');
async function capture(page,number) {
  await page.mouse.move(1270,890);
  await page.evaluate(()=>document.fonts.ready);
  await page.screenshot({path:path.join(destination,`screenshot-${number}.png`),animations:'disabled'});
}
test('@screenshots clean WordPress.org gallery',async({page})=>{
  test.setTimeout(120000);
  fs.mkdirSync(destination,{recursive:true});
  await login(page);
  await upload(page);
  await page.goto(avatarPage);
  await capture(page,1);
  await login(page,'admin');
  await upload(page,'default');
  await upload(page,'default','default_avatar');
  await page.goto(managePage);
  await page.getByRole('row').filter({hasText:'Maya Chen'}).getByRole('checkbox').check();
  await page.locator('#zmai-bulk-action').selectOption('block');
  await capture(page,2);
  await page.goto(`${managePage}&tab=settings`);
  await capture(page,3);
  await page.context().clearCookies();
  await front(page);
  await expectAvatar(page,'.aoui-e2e-author-card img','maya');
  await expectAvatar(page,'.wp-block-comment-template .wp-block-avatar img','maya');
  await page.locator('.aoui-e2e-author-card').scrollIntoViewIfNeeded();
  await capture(page,4);
  await login(page,'admin');
  await page.setViewportSize({width:1280,height:1000});
  await page.goto(avatarPage);
  await expect(page.getByRole('heading',{name:'Site default avatar',exact:true})).toBeVisible();
  await capture(page,5);
});
