const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs=require('fs');
(async()=>{
const c=JSON.parse(fs.readFileSync('storage/framework/testing/browser-credentials.json','utf8'));
const browser=await chromium.launch({headless:true,channel:'msedge'});
const page=await browser.newPage({viewport:{width:1440,height:1000}});
page.on('response',async r=>{if(new URL(r.url()).pathname.includes('upload-file')){console.log('Upload HTTP',r.status());try{const j=await r.json();console.log('Upload response message',j.message || '',j.errors || '');}catch{}}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
await page.goto('http://127.0.0.1:8001/login');
await page.getByLabel('Email',{exact:true}).fill(c.candidate);await page.getByLabel('Password',{exact:true}).fill(c.password);
await page.getByRole('button',{name:'Masuk ke portal'}).click();await page.waitForURL('**/portal');
await page.screenshot({path:'storage/app/candidate-desktop.png',fullPage:true});
await page.goto('http://127.0.0.1:8001/portal/submissions/'+c.submission);
await page.getByLabel('Catatan',{exact:true}).fill('Catatan pengujian browser');
await page.getByRole('button',{name:'Simpan draf',exact:true}).click();
await page.getByRole('status').filter({hasText:'Draf berhasil disimpan'}).waitFor();
await page.reload();await page.getByLabel('Catatan',{exact:true}).waitFor();
if(await page.getByLabel('Catatan',{exact:true}).inputValue()!=='Catatan pengujian browser')throw Error('Draft did not persist');
while(await page.getByRole('button',{name:'Hapus unggahan',exact:true}).count()) { await page.getByRole('button',{name:'Hapus unggahan',exact:true}).first().click(); await page.waitForTimeout(300); }
await page.locator('input[type=file]').setInputFiles({name:'portfolio-browser.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF')});
await page.getByText('Menunggu pemeriksaan keamanan',{exact:true}).waitFor({timeout:15000}).catch(async e=>{console.log('Upload errors:',await page.locator('.errors,.field-error').allTextContents());console.log('JS errors:',errors);await page.screenshot({path:'storage/app/upload-failure.png',fullPage:true});throw e;});
console.log('Private upload accepted and waits for scanning.');
await page.screenshot({path:'storage/app/upload-desktop.png',fullPage:true});
await page.setViewportSize({width:390,height:844});await page.screenshot({path:'storage/app/upload-mobile.png',fullPage:true});
console.log('Candidate draft persists; mobile overflow:',await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth));
console.log('JavaScript errors:',errors);
await browser.close();
})().catch(e=>{console.error(e.message);process.exit(1)});



