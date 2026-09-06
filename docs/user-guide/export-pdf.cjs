const fs=require('fs'),path=require('path');
const {pathToFileURL}=require('url');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/M.Zulfi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const roles={developer:'Developer',superadmin:'Superadmin',head_ops:'Head of Ops',ops_admin:'Ops Admin',outlet_manager:'Outlet Manager',spv:'SPV',cashier:'Kasir'};
const out=path.join(__dirname,'pdf');fs.mkdirSync(out,{recursive:true});
(async()=>{
 const browser=await chromium.launch({channel:'msedge',headless:true});
 for(const [role,label] of Object.entries(roles)){
  const page=await browser.newPage();await page.goto(pathToFileURL(path.join(__dirname,role+'.html')).href);
  await page.evaluate(async()=>{
   document.querySelectorAll('img').forEach(i=>i.loading='eager');
   await Promise.all([...document.images].map(i=>i.decode()));await document.fonts.ready;
   document.querySelectorAll('button,nav,header>a').forEach(e=>e.remove());
   document.querySelectorAll('article').forEach(article=>{
    const screenshots=article.querySelector('.screens');
    const appendix=document.createElement('div');appendix.className='screenshot-pages';
    screenshots.querySelectorAll('img').forEach(img=>{
     const mmWidth=img.naturalWidth<500?100:170;
     const mmHeight=img.naturalHeight/img.naturalWidth*mmWidth;
     const count=Math.ceil(mmHeight/225);
     for(let n=0;n<count;n++){
      const figure=document.createElement('section');figure.className='pdf-screenshot';
      const caption=document.createElement('h3');caption.textContent=article.querySelector('h2').textContent+' — '+img.alt+(count>1?' · bagian '+(n+1)+'/'+count:'');
      const viewport=document.createElement('div');viewport.className='image-window';viewport.style.cssText=`width:${mmWidth}mm;height:${Math.min(225,mmHeight-225*n)}mm`;
      const copy=img.cloneNode();copy.loading='eager';copy.style.cssText=`width:${mmWidth}mm;height:${mmHeight}mm;top:${-225*n}mm`;
      viewport.append(copy);figure.append(caption,viewport);appendix.append(figure);
     }
    });
    screenshots.remove();article.append(appendix);
   });
   document.querySelectorAll('a').forEach(a=>{if(!a.getAttribute('href')?.startsWith('#'))a.replaceWith(document.createTextNode(a.textContent));});
  });
  await page.addStyleTag({content:`@media print {
   @page{size:A4}body{font-size:10pt;line-height:1.55;color:#233249}main{width:auto;margin:0;padding:0}h1{font-size:28pt}h2{font-size:20pt}h3{font-size:12pt;break-after:avoid}p,li{orphans:3;widows:3}li{margin:5px 0}header.card{padding:12mm 0 8mm}.card{padding:0;border:0;margin:5mm 0}article{break-before:page;margin:0;padding:0;border:0}article>h2{margin-top:0}.note{break-inside:avoid}.pdf-screenshot{break-before:page;break-inside:avoid;margin:0;padding:0}.pdf-screenshot h3{margin:0 0 5mm;font-size:11pt}.image-window{position:relative;overflow:hidden;margin:auto}.image-window img{position:absolute;left:0;max-width:none;object-fit:fill;border:0;border-radius:0}footer.card{break-before:page}.meta{font-size:9pt}
  }`});
  await page.pdf({path:path.join(out,`User-Guide-${label.replaceAll(' ','-')}.pdf`),format:'A4',printBackground:true,margin:{top:'15mm',bottom:'17mm',left:'18mm',right:'18mm'},displayHeaderFooter:true,headerTemplate:'<div></div>',footerTemplate:`<div style="font:9px Arial;width:100%;padding:0 18mm;color:#65758a;display:flex;justify-content:space-between"><span>WarungKita · ${label} · 6 September 2026</span><span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>`,tagged:true,outline:true});
  await page.close();console.log('PDF created: '+label);
 }
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
