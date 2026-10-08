const fs=require('node:fs');
const {chromium}=require('C:/Users/M.Zulfi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
(async()=>{
 const browser=await chromium.launch({channel:'msedge',headless:true});
 const page=await browser.newPage({viewport:{width:768,height:900},userAgent:'Mozilla/5.0 Android 12'});
 const html=fs.readFileSync('storage/app/rawbt-preview.html','utf8')
  .replaceAll('Xantri BT-58D Pro','PANDA Kasir')
  .replace(/<script src="([^"]+)"><\/script>/g,(tag,src)=>src.includes('rawbt-printer.js')?'<script>'+fs.readFileSync('public/js/rawbt-printer.js','utf8')+'</script>':'');
 await page.setContent(html);
 await page.screenshot({path:'tmp/pdfs/rawbt-user-preview.png',fullPage:true});
 await browser.close();
})();
