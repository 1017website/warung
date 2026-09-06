const {chromium}=require('C:/Users/M.Zulfi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs=require('fs'),assert=require('node:assert/strict');
(async()=>{
 const out='docs/setup-verification-2026-09-06';fs.mkdirSync(out,{recursive:true});
 const browser=await chromium.launch({channel:'msedge',headless:true});
 const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const renders=[];
 async function shots(step){for(const width of [1440,1024,768,390,360]){
  await page.setViewportSize({width,height:900});
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`Overflow step ${step} width ${width}`);
  fs.writeFileSync(`${out}/step-${step}-${width}.png`,await page.screenshot({fullPage:true}));renders.push({step,width,passed:true});
 }}
 await page.goto('http://127.0.0.1:8017/login');
 await page.locator('[name=email]').fill('wizard.audit@setup.test');await page.locator('[name=password]').fill('password');
 await page.locator('button.btn-primary').click();await page.waitForURL('**/setup');await shots(1);
 await page.locator('[name=business_name]').fill('Usaha Uji Wizard');await page.locator('[name=store_name]').fill('Cabang Pusat');await page.locator('[name=address]').fill('Jl. Pengujian No. 12');
 await page.getByRole('button',{name:'Simpan & lanjutkan'}).click();await page.getByRole('heading',{name:'Tambahkan menu pertama'}).waitFor();await shots(2);
 await page.reload();assert(await page.locator('[name=product_name]').count());
 await page.locator('[name=category]').fill('Makanan');await page.locator('[name=product_name]').fill('Nasi Goreng Uji');await page.locator('[name=selling_price]').fill('15000');await page.locator('[name=quantity]').fill('12.5');
 await page.getByRole('button',{name:'Simpan & periksa kesiapan'}).click();await page.getByRole('heading',{name:'Data awal sudah lengkap'}).waitFor();await shots(3);
 await page.locator('[name=confirm]').check();await page.getByRole('button',{name:'Selesai & masuk POS Warung'}).click();await page.waitForURL('**/dashboard');
 await page.goto('http://127.0.0.1:8017/kasir');assert((await page.locator('body').innerText()).includes('Nasi Goreng Uji'));
 assert.equal(errors.length,0);fs.writeFileSync(`${out}/browser-results.json`,JSON.stringify({renders,flow:'login ? identity ? reload/resume ? product ? finish ? dashboard ? POS',errors},null,2));
 await browser.close();console.log(JSON.stringify({passed:true,renders:renders.length,errors}));
})().catch(e=>{console.error(e);process.exit(1)});
