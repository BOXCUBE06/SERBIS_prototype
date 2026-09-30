// Screenshots for the SERBIS mobile (resident) user manual.
// Run from the repo root: node docs/user-manual/mobile-capture.js <group> [<group>...]
// Needs: API on :8000 (local, SERBIS_OTP_BYPASS_CODE=555555, SERBIS_SMS_FAKE=true)
// and the Flutter release web build served on :5000. Never point this at production.
// Playwright comes from the scratch install passed in PLAYWRIGHT_DIR; the installed
// Edge is used because Application Control blocks the bundled Chromium here.
// Flutter draws to a canvas, so steps are mouse taps at phone coordinates (dp).
const path = require('path')
const { chromium } = require(path.join(process.env.PLAYWRIGHT_DIR, 'playwright'))

const OUT = path.join(__dirname, 'mobile-img')
const BASE = 'http://localhost:5000/'
const PASSWORD = 'Passw0rd!123'
const OTP = '555555'

async function open() {
  const browser = await chromium.launch({ channel: 'msedge' })
  const ctx = await browser.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 2 })
  const p = await ctx.newPage()
  await p.goto(BASE)
  await p.bringToFront()
  await p.waitForTimeout(9000)
  await p.mouse.move(206, 400)
  return { browser, p }
}

const h = (p) => ({
  tap: async (x, y, wait = 1500) => { await p.mouse.move(x, y); await p.mouse.down(); await p.mouse.up(); await p.waitForTimeout(wait) },
  wheel: async (dy, x = 206, y = 450) => { await p.mouse.move(x, y); await p.mouse.wheel(0, dy); await p.waitForTimeout(1000) },
  fill: async (text, sel = 'input:focus, textarea:focus') => { const l = p.locator(sel).first(); await l.waitFor({ state: 'attached', timeout: 8000 }); await l.fill(text, { force: true, timeout: 8000 }); await p.waitForTimeout(400) },
  key: async (k, wait = 500) => { await p.keyboard.press(k); await p.waitForTimeout(wait) },
  shot: async (name) => { await p.screenshot({ path: path.join(OUT, name + '.png') }); console.log('shot', name) },
  wait: (ms) => p.waitForTimeout(ms),
})

async function login(p, phone, password = PASSWORD) {
  const a = h(p)
  await a.key('Tab')
  await a.fill(phone)
  await a.key('Tab')
  await a.fill(password)
  await a.key('Tab'); await a.key('Tab'); await a.key('Enter', 5000)
  await a.key('Tab')
  await a.fill(OTP)
  await a.key('Tab'); await a.key('Enter', 9000)
}

const GROUPS = {}
module.exports = { open, h, login, GROUPS }

if (require.main === module) {
  require('./mobile-steps.js')
  ;(async () => {
    for (const g of process.argv.slice(2)) {
      const { browser, p } = await open()
      try { await GROUPS[g](p, h(p)) } catch (e) { console.log('FAILED', g, e.message) }
      await browser.close()
    }
  })()
}
