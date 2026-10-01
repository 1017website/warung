// Screenshot panduan revisi 1 Oktober 2026 (login, reservasi, upload menu & stok, printer).
// Usage: WARUNG_BASE_URL=http://127.0.0.1:8765 node tests/browser/october-guide.cjs
// Jalankan terhadap schema review terpisah yang sudah di-seed demo (bukan database operasional).
const fs=require('fs'),path=require('path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/M.Zulfi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const base=process.env.WARUNG_BASE_URL||'http://127.0.0.1:8765';
const out=path.resolve('docs/revision-2026-10-01/screenshots');fs.mkdirSync(out,{recursive:true});
const errors=[];
(async()=>{
 const browser=await chromium.launch({channel:'msedge',headless:true});
 async function session(email,width=768){const ctx=await browser.newContext({viewport:{width,height:900}}),p=await ctx.newPage();p.on('pageerror',e=>errors.push(e.message));await p.goto(base+'/login');await p.locator('[name=email]').fill(email);await p.locator('[name=password]').fill('password');await Promise.all([p.waitForURL(u=>u.pathname!=='/login'),p.locator('button.btn-primary').click()]);return {ctx,p};}
 // Navigasi bawah mobile bersifat fixed; pada screenshot satu halaman penuh ia menutupi baris tabel.
 const shot=async(p,name,fullPage=true)=>{await p.waitForTimeout(300);if(fullPage)await p.addStyleTag({content:'.mobile-nav{display:none!important}'});fs.writeFileSync(path.join(out,name+'.png'),await p.screenshot({fullPage}));console.log('saved '+name);};

 for(const width of [768,390]){const ctx=await browser.newContext({viewport:{width,height:900}}),p=await ctx.newPage();await p.goto(base+'/login');await p.locator('[name=email]').fill('kasir.melati@warungkita.id');await shot(p,`login-${width}`,false);await ctx.close();}

 let {ctx,p}=await session('kasir.melati@warungkita.id');
 await shot(p,'login-kasir-landing-768',false);
 await p.goto(base+'/reservasi');await shot(p,'reservasi-768');
 await p.locator('button:has-text("Reservasi baru")').click();
 await p.locator('#reservation-form [name=customer_name]').fill('Keluarga Wibowo');await p.locator('#reservation-form [name=phone]').fill('085600223344');
 await p.locator('#reservation-form [name=guests]').fill('8');await p.locator('#reservation-form [name=table_number]').fill('B-03');
 await p.locator('#reservation-form [name=dp_amount]').fill('100000');await p.locator('#reservation-form [name=dp_method]').selectOption('qris');await p.locator('#reservation-form [name=dp_provider]').fill('QRIS BCA');
 await shot(p,'reservasi-form-768',false);
 await p.goto(base+'/reservasi');await p.locator('.cancel-reservation').first().click();await shot(p,'reservasi-batal-768',false);
 await p.goto(base+'/kasir');await p.locator('button[title^="Reservasi hari ini"]').click();await shot(p,'kasir-reservasi-pilih-768',false);
 await p.locator('#reservation-modal .list-item:has-text("Keluarga Wibowo")').click();
 const cards=p.locator('.product-card:not([disabled])');for(let i=0;i<8;i++){await cards.nth(0).click();const picker=p.locator('#price-picker-modal.open, #price-picker-modal.show');if(await picker.count())await p.locator('.price-choice').first().click();}
 await p.locator(".product-card:not([disabled])").nth(1).click();
 // Katalog berisi ratusan menu; cukup potret panel keranjang.
 await p.locator('#paid-amount').fill('20000');await p.addStyleTag({content:'.topbar{position:static!important}.mobile-nav{display:none!important}'});fs.writeFileSync(path.join(out,'kasir-reservasi-dp-768.png'),await p.locator('.cart').screenshot());console.log('saved kasir-reservasi-dp-768');
 await p.goto(base+'/kasir/tutup-harian');await shot(p,'tutup-kasir-dp-768');
 await ctx.close();

 ({ctx,p}=await session('kasir.melati@warungkita.id',390));await p.goto(base+'/reservasi');await shot(p,'reservasi-390');await ctx.close();

 ({ctx,p}=await session('superadmin@warungkita.id'));
 await shot(p,'login-superadmin-landing-768',false);
 await p.goto(base+'/produk');await p.locator('button:has-text("Import Excel")').click();await shot(p,'produk-import-768',false);
 await p.goto(base+'/produk?q=BA-04');await shot(p,'produk-sku-bersama-768');
 await p.goto(base+'/pengaturan');await p.evaluate(()=>openDeviceForm());
 await p.locator('#device-form [name=name]').fill('Printer Kasir Melati');await p.locator('#device-driver').selectOption('epson_epos');await p.evaluate(()=>toggleDeviceFields());
 await p.locator('#device-form [name=epos_host]').fill('192.168.1.50');await shot(p,'printer-epson-form-768',false);
 await p.goto(base+'/pengaturan');const device=p.locator('text=Printer Kasir Melati').first();await device.scrollIntoViewIfNeeded();await p.evaluate(()=>window.scrollBy(0,-120));await shot(p,'printer-perangkat-list-768',false);
 await p.goto(base+'/transaksi/1/print');await shot(p,'printer-struk-browser-768',false);
 await ctx.close();
 await browser.close();
 fs.writeFileSync(path.join(out,'..','browser-errors.json'),JSON.stringify(errors,null,2));
 console.log(errors.length?'page errors: '+errors.join(' | '):'no page errors');
})().catch(e=>{console.error(e);process.exit(1)});
