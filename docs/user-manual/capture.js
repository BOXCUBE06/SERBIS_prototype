// Screenshots for the SERBIS admin user manual.
// Run from the repo root: node docs/user-manual/capture.js
// Needs: API on :8000, admin panel (npm run dev) on :3000.
// Playwright is loaded from the admin panel's node_modules. The installed Edge
// is used because Application Control blocks the bundled Chromium on this box.
const path = require('path')
const fs = require('fs')
const { createRequire } = require('module')
const req = createRequire(path.join(__dirname, '../../Web/serbis-admin-vue/package.json'))
const { chromium } = req('playwright')

const BASE = process.env.SERBIS_ADMIN_URL || 'http://localhost:3000'
const EMAIL = process.env.SERBIS_ADMIN_EMAIL || 'admin@serbis.com'
const PASSWORD = process.env.SERBIS_ADMIN_PASSWORD || 'password123'
const OUT = path.join(__dirname, 'img')
const LOG = path.join(__dirname, 'capture-log.json')

const clickText = (text) => async (page) => {
  await page.getByRole('button', { name: text }).first().click()
}
const clickFirstRow = async (page) => {
  await page.locator('table tbody tr').first().click()
}

// [file, route, optional action that opens a dialog]
const SHOTS = [
  ['01-login', '/login', null, { loggedOut: true }],
  ['02-dashboard', '/'],
  ['03-analytics', '/analytics'],
  ['04-resident-requests', '/manage-requests'],
  ['05-resident-request-detail', '/manage-requests', clickFirstRow],
  ['06-log-service-request', '/manage-requests', clickText(/Log Service Request/)],
  ['07-ambulance-dispatch', '/conduction-requests'],
  ['08-ambulance-request-detail', '/conduction-requests', clickFirstRow],
  ['09-ambulance-log-request', '/conduction-requests', clickText(/Log Service Request/)],
  ['10-ambulance-trip-records', '/conduction-requests', async (p) => { await p.getByRole('tab').nth(1).click() }, { noDialog: true }],
  ['11-equipment-borrowing', '/borrowings'],
  ['12-borrowing-detail', '/borrowings', clickFirstRow],
  ['13-vehicles', '/vehicles'],
  ['14-add-vehicle', '/vehicles', clickText(/Add Unit/)],
  ['15-responders', '/responders'],
  ['16-add-responder', '/responders', clickText(/Add Responder/)],
  ['17-resource-management', '/inventory'],
  ['18-add-equipment', '/inventory', clickText(/Add Equipment/)],
  ['19-procurement-reference', '/procurement'],
  ['20-accounts', '/users'],
  ['21-account-detail', '/users', clickFirstRow],
  ['22-add-account', '/users', clickText(/Add account/)],
  ['23-documents', '/files'],
  ['24-text-blast', '/sms'],
  ['25-text-blast-code', '/sms', clickText(/Text blast code/)],
  ['26-manage-services', '/services-config'],
  ['27-edit-service', '/services-config', clickFirstRow],
  ['28-service-audience', '/service-audience'],
  ['29-service-vehicles', '/service-vehicles'],
  ['30-staff-accounts', '/staff'],
  ['31-add-staff-account', '/staff', clickText(/Add staff account/)],
  ['32-staff-access', '/staff', clickText(/Choose which sections/)],
  ['33-activity-logs', '/logs'],
  ['34-sign-out', '/', async (p) => { await p.locator('.profile-card').click() }],
]

async function settle(page) {
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {})
  await page.waitForTimeout(800)
}

async function shoot(page, [file, route, action, opts]) {
  await page.goto(BASE + route)
  await settle(page)
  // A blank pane on first visit is Vite re-optimising deps; reload once.
  if (!(await page.locator('main').innerText().catch(() => '')).trim() && route !== '/login') {
    await page.reload(); await settle(page)
  }
  if (action) {
    await action(page)
    if (!opts?.noDialog) await page.locator('.v-overlay--active .v-card, .v-overlay--active .v-sheet').first().waitFor({ timeout: 8000 })
    await settle(page)
  }
  await page.screenshot({ path: path.join(OUT, file + '.png') })
}

;(async () => {
  fs.mkdirSync(OUT, { recursive: true })
  const browser = await chromium.launch({ channel: 'msedge' })
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } })
  const page = await ctx.newPage()
  const results = []

  // Logged-out shots first, then sign in once; the token persists in localStorage.
  for (const s of SHOTS.filter((s) => s[3]?.loggedOut)) {
    await shoot(page, s).then(() => results.push({ file: s[0], ok: true }))
      .catch((e) => results.push({ file: s[0], ok: false, error: e.message.split('\n')[0] }))
  }
  await page.goto(BASE + '/login')
  await page.fill('#login-email', EMAIL)
  await page.fill('#login-password', PASSWORD)
  await page.click('button[type=submit]')
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 20000 })

  for (const s of SHOTS.filter((s) => !s[3]?.loggedOut)) {
    let err
    for (let attempt = 1; attempt <= 2; attempt++) {
      try { await shoot(page, s); err = null; break } catch (e) { err = e }
    }
    results.push(err ? { file: s[0], route: s[1], ok: false, error: err.message.split('\n')[0] } : { file: s[0], route: s[1], ok: true })
    console.log(err ? 'SKIP' : 'ok  ', s[0], err ? err.message.split('\n')[0] : '')
  }

  fs.writeFileSync(LOG, JSON.stringify(results, null, 2))
  await browser.close()
})()
