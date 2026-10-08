// Checks the URLs are local, creates the output folders and renders the
// sample images (no real photos or IDs) the seed script and uploads use.
// Run: node docs/user-manuals/tools/capture/setup.js
const path = require('path')
const { SAMPLES, assertLocal, ensureDirs, launch } = require('./common')

const card = (bg, body) => `<!doctype html><html><body style="margin:0;width:800px;height:600px;background:${bg};font-family:Segoe UI,Arial,sans-serif;display:flex;align-items:center;justify-content:center">${body}</body></html>`

const SAMPLE_PAGES = {
  'site-photo.png': card('linear-gradient(#9cc9e8 0 45%,#6b8f4e 45% 100%)', `
    <svg width="800" height="600" viewBox="0 0 800 600">
      <polygon points="300,600 500,600 430,270 370,270" fill="#8a8a85"/>
      <rect x="250" y="360" width="320" height="34" rx="16" fill="#6b4a2b" transform="rotate(-14 410 377)"/>
      <circle cx="560" cy="330" r="70" fill="#3f6b2f"/><circle cx="610" cy="300" r="55" fill="#4d7d38"/>
      <text x="24" y="580" font-size="26" fill="#fff" font-family="Segoe UI">SAMPLE PHOTO - fallen tree on road</text>
    </svg>`),
  'handover-release.png': card('#e9e4da', `
    <div style="text-align:center;color:#333"><div style="width:260px;height:200px;margin:0 auto 24px;background:#555;border-radius:12px;position:relative">
      <div style="position:absolute;bottom:-30px;left:40px;width:50px;height:50px;border-radius:50%;border:10px solid #333"></div>
      <div style="position:absolute;bottom:-30px;right:40px;width:50px;height:50px;border-radius:50%;border:10px solid #333"></div></div>
      <div style="font-size:30px;margin-top:40px">SAMPLE PHOTO - item at release</div></div>`),
  'handover-return.png': card('#dfe7e4', `
    <div style="text-align:center;color:#333"><div style="width:260px;height:200px;margin:0 auto 24px;background:#4a6a60;border-radius:12px"></div>
      <div style="font-size:30px">SAMPLE PHOTO - item on return</div></div>`),
  'profile-photo.png': card('#d8e6df', `
    <svg width="400" height="400" viewBox="0 0 400 400">
      <circle cx="200" cy="150" r="80" fill="#5f8f7b"/><rect x="80" y="250" width="240" height="150" rx="110" fill="#5f8f7b"/>
      <text x="200" y="390" font-size="22" text-anchor="middle" fill="#fff" font-family="Segoe UI">SAMPLE PHOTO</text>
    </svg>`),
  'sample-id.png': card('#cfd8dc', `
    <div style="width:640px;height:400px;background:#fff;border-radius:18px;box-shadow:0 4px 16px #0003;padding:28px;box-sizing:border-box;font-size:22px;color:#333">
      <div style="font-size:30px;font-weight:700;color:#16483A">SAMPLE ID - NOT A REAL DOCUMENT</div>
      <div style="display:flex;gap:28px;margin-top:28px"><div style="width:150px;height:180px;background:#b0bec5;border-radius:8px"></div>
      <div>Name: JUAN DELA CRUZ (SAMPLE)<br><br>ID No.: 0000-0000-0000<br><br>Address: Echague, Isabela</div></div></div>`),
}

const LETTER = `<!doctype html><html><body style="font-family:Times New Roman,serif;padding:60px;font-size:16px">
  <p>To: MDRRMO Echague</p><p>Subject: Request for a DRRM seminar (SAMPLE LETTER)</p>
  <p>This is a sample request letter used only for the SERBIS user manual screenshots.</p><p>Signed,<br>Sample Barangay Hall</p></body></html>`

;(async () => {
  assertLocal()
  ensureDirs()
  const browser = await launch()
  const page = await browser.newPage({ viewport: { width: 800, height: 600 } })
  for (const [name, html] of Object.entries(SAMPLE_PAGES)) {
    await page.setContent(html)
    await page.screenshot({ path: path.join(SAMPLES, name) })
  }
  await page.setContent(LETTER)
  await page.pdf({ path: path.join(SAMPLES, 'request-letter.pdf'), format: 'A4' })
  await browser.close()
  console.log('Samples written to', SAMPLES)
})().catch((e) => { console.error(e); process.exit(1) })
