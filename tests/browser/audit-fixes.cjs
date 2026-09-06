// Usage: WARUNG_BASE_URL=http://127.0.0.1:8017 node tests/browser/audit-fixes.cjs
// Generate storage/app/fix-verification/fixture.json with audit-fixture.php first.
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/M.Zulfi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fixture=JSON.parse(fs.readFileSync('storage/app/fix-verification/fixture.json','utf8').replace(/^\uFEFF/,''));
const base=process.env.WARUNG_BASE_URL||'http://127.0.0.1:8017';
const out=path.resolve('docs/fix-verification-2026-09-06');fs.mkdirSync(path.join(out,'screenshots'),{recursive:true});
const result={scenarios:[],renders:[],roles:[],errors:[]};
const save=()=>fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify(result,null,2));
(async()=>{
 const browser=await chromium.launch({channel:'msedge',headless:true});
 async function session(role,width=390){const ctx=await browser.newContext({viewport:{width,height:900}}),p=await ctx.newPage();p.on('pageerror',e=>result.errors.push(e.message));await p.goto(base+'/login');await p.locator('[name=email]').fill(fixture.emails[role]);await p.locator('[name=password]').fill('password');await Promise.all([p.waitForURL(u=>u.pathname!=='/login'),p.locator('button.btn-primary').click()]);return {ctx,p};}
 async function shot(p,name){await p.screenshot({path:path.join(out,'screenshots',name+'.png'),fullPage:true});}
 let {ctx,p}=await session('cashier');
 assert.match(await p.locator(`.product-card[data-id="${fixture.menu}"]`).innerText(),/Stok 7 pcs/);
 await shot(p,'B03-first-pos-stock');result.scenarios.push({id:'B03',passed:true,stockBeforeInventory:7});save();
 await p.locator(`.product-card[data-id="${fixture.menu}"]`).click();await p.locator('[data-service=takeaway]').click();
 let checkoutRequests=0;p.on('request',r=>{if(r.url().endsWith('/kasir/checkout'))checkoutRequests++;});
 for(const value of ['','1000']){
  await p.locator('#paid-amount').fill(value);
  const dialog=p.waitForEvent('dialog').then(async d=>{assert.match(d.message(),/pembayaran kurang/);await d.dismiss();});await p.locator('#checkout-btn').click();await dialog;
  assert.equal(checkoutRequests,0);
 }
 await shot(p,'B01-underpayment-rejected');
 await p.locator('#paid-amount').fill('30000');
 const sent=p.waitForRequest(r=>r.url().endsWith('/kasir/checkout'));const response=p.waitForResponse(r=>r.url().endsWith('/kasir/checkout'));const popup=p.waitForEvent('popup');
 await p.locator('#checkout-btn').click();assert.equal((await sent).postDataJSON().payments[0].amount,30000);assert.equal((await response).status(),200);
 const receipt=await popup;await receipt.waitForURL('**/transaksi/*/print?*');await receipt.waitForLoadState('networkidle');await shot(receipt,'B01-receipt-change');await receipt.close();
 await p.goto(base+'/kasir');await p.locator(`.product-card[data-id="${fixture.menu}"]`).click();await p.locator('[data-service=takeaway]').click();await p.locator('#member-id').selectOption(String(fixture.member));await p.locator('[data-payment=deposit]').click();
 assert.equal(await p.locator('#paid-field').isVisible(),false);
 await p.locator('#deposit-amount').fill('5000');assert.equal(await p.locator('#paid-field').isVisible(),true);await p.locator('#paid-amount').fill('20000');
 const split=p.waitForRequest(r=>r.url().endsWith('/kasir/checkout'));const splitResponse=p.waitForResponse(r=>r.url().endsWith('/kasir/checkout'));await p.locator('#checkout-btn').click();const splitPayload=(await split).postDataJSON();assert.deepEqual(splitPayload.payments.map(x=>[x.method,x.amount]),[['deposit',5000],['cash',20000]]);assert.equal((await splitResponse).status(),200);
 result.scenarios.push({id:'B01',passed:true,blockedInputs:[0,1000],cashSent:30000,split:splitPayload.payments});save();await ctx.close();
 ({ctx,p}=await session('superadmin',1024));await p.goto(base+'/pembelian');
 const row=()=>p.locator('tr').filter({hasText:fixture.purchase_no});
 await Promise.all([p.waitForNavigation(),row().locator('[name=payment_status]').selectOption('dp')]);
 for(const amount of [1000,5000,1000000]){
  await row().locator('[name=dp_amount]').fill(String(amount));
  const sent=p.waitForRequest(r=>r.url().endsWith('/status')&&r.method()==='POST');
  await Promise.all([p.waitForNavigation(),row().locator('[name=dp_amount]').blur()]);
  assert.equal(new URLSearchParams((await sent).postData()).get('dp_amount'),String(amount));
  assert.equal(await row().locator('[name=dp_amount]').inputValue(),new Intl.NumberFormat('id-ID').format(amount));
 }
 await shot(p,'B02-dp-preserved');result.scenarios.push({id:'B02',passed:true,amounts:[1000,5000,1000000]});save();
 await row().locator('.edit-purchase').click();
 assert.equal(await p.locator('#edit-purchase-form [name=unit_cost]').inputValue(),'200.000');
 assert.equal(await p.locator('#edit-purchase-form [name=dp_amount]').inputValue(),'1.000.000');
 await p.locator('#edit-purchase-form [name=supplier_name]').fill('Supplier dikoreksi');await p.locator('#edit-purchase-form [name=notes]').fill('Catatan diperbaiki setelah bahan terpakai');
 await Promise.all([p.waitForNavigation(),p.locator('#edit-purchase-form button').click()]);assert.match(await row().innerText(),/Supplier dikoreksi/);await shot(p,'B05-metadata-corrected');result.scenarios.push({id:'B05',passed:true,supplier:'Supplier dikoreksi'});save();
 await p.goto(base+'/laporan?type=real&period=custom&from=2026-08-01&to=2026-08-15');
 for(const name of ['Non-riil','Laporan riil']){await p.getByRole('link',{name,exact:true}).click();assert.equal(await p.locator('[name=from]').inputValue(),'2026-08-01');assert.equal(await p.locator('[name=to]').inputValue(),'2026-08-15');}
 await shot(p,'B07-date-preserved');result.scenarios.push({id:'B07',passed:true,from:'2026-08-01',to:'2026-08-15'});save();
 const urls=['dashboard','kasir','transaksi','produk','gudang','pembelian','pengeluaran','member','laporan','pengaturan','kasir/tutup-harian'];
 for(const width of [1440,1024,768,390,360]){
  await p.setViewportSize({width,height:900});
  for(const url of urls){const res=await p.goto(base+'/'+url);assert.equal(res.status(),200);const state=await p.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth,logoutVisible:[...document.querySelectorAll('form[action$="/logout"] button')].some(e=>e.getClientRects().length>0)}));assert.ok(state.scrollWidth<=width);assert.equal(state.logoutVisible,true);result.renders.push({url,...state});await shot(p,`${width}-${url.replaceAll('/','-')}`);}
  console.log('Verified viewport '+width);save();
 }
 for(const width of [768,390,360]){await p.setViewportSize({width,height:900});await p.goto(base+'/kasir');await p.locator('.mobile-account button').click();assert.equal(new URL(p.url()).pathname,'/login');assert.equal((await p.goto(base+'/kasir')).url(),base+'/login');await ctx.close();({ctx,p}=await session('superadmin',width));}
 result.scenarios.push({id:'B04',passed:true,logoutWidths:[768,390,360]});save();await ctx.close();
 const permissions={developer:urls.slice(0,10),superadmin:urls.slice(0,10),head_ops:urls.slice(0,9),ops_admin:['kasir','transaksi','produk','gudang','pembelian','pengeluaran','member'],outlet_manager:['kasir','transaksi','gudang','pengeluaran','member'],spv:['kasir','transaksi','gudang','pengeluaran','member'],cashier:['kasir','transaksi','gudang','pengeluaran','member'],transactions_only:['transaksi'],dashboard_only:['dashboard']};
 for(const [role,allowed] of Object.entries(permissions)){
  ({ctx,p}=await session(role,768));const statuses=[];
  for(const url of urls.slice(0,10)){const res=await p.goto(base+'/'+url);assert.equal(res.status(),allowed.includes(url)?200:403,role+' '+url);statuses.push({url,status:res.status()});if(res.status()===200)await shot(p,`role-${role}-${url}`);}
  if(role==='transactions_only'){await p.goto(base+'/transaksi');assert.equal(await p.getByRole('link',{name:'Transaksi baru'}).count(),0);}
  if(role==='dashboard_only'){await p.goto(base+'/dashboard');for(const name of ['Buka kasir','Lihat semua','Semua transaksi'])assert.equal(await p.getByRole('link',{name,exact:true}).count(),0);}
  result.roles.push({role,statuses});save();await ctx.close();console.log('Verified role '+role);
 }
 result.scenarios.push({id:'B06',passed:true,customRoles:['transactions_only','dashboard_only']});assert.deepEqual(result.errors,[]);result.passed=true;save();await browser.close();console.log('All browser checks passed');
})().catch(e=>{result.failure=e.stack;save();console.error(e);process.exit(1)});
