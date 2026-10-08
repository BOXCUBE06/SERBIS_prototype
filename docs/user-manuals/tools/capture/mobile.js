// Mobile app screenshots (MOB-xx) on the Android emulator, driven with adb.
// See MOBILE-CAPTURE.md for setup (API on serbis_capture, adb reverse, the
// release APK built with API_BASE_URL=http://localhost:8000/api).
// Run: node docs/user-manuals/tools/capture/mobile.js [MOB-05 MOB-40 ...] [--force]
// Shots run in groups, one account per group; IDs not asked for are skipped,
// but the steps that set up later shots in the same group still run.
const fs = require('fs')
const path = require('path')
const { execFileSync, spawn } = require('child_process')
const a = require('./adb')
const { OUT_ROOT, REPO, ensureDirs, save } = require('./common')
const { writeBoxes } = require('./admin-boxes')

const PKG = 'ph.gov.echague.serbis'
const CODE = '555555'
const PASSWORD = 'Passw0rd!123'
// The Head of the Family registered through the app (MOB-01..04).
const NEW_PHONE = process.env.SERBIS_NEW_PHONE || '09175550101'
const ACCOUNTS = {
  hall: '09172000000', // Barangay hall, San Fabian
  org: '09272111423', // Activated organization
  pendingOrg: '09752259987', // Organization awaiting approval
}

const args = process.argv.slice(2)
const FORCE = args.includes('--force')
const ONLY = new Set(args.filter((x) => !x.startsWith('--')))
const exists = (id) => fs.existsSync(path.join(OUT_ROOT, 'mobile', `${id}.png`))
const want = (id) => (ONLY.size === 0 || ONLY.has(id)) && (FORCE || !exists(id))
const results = []
const notFound = []

const seed = (...opts) => execFileSync('php', [path.join(__dirname, 'seed-capture.php'), ...opts], { env: { ...process.env, DB_DATABASE: 'serbis_capture' } }).toString().trim()
const e164 = (local) => '+63' + local.slice(1)

// ---- app control -------------------------------------------------------
async function launch() {
  a.sh(`am force-stop ${PKG}`)
  a.sh(`monkey -p ${PKG} -c android.intent.category.LAUNCHER 1`)
  for (let i = 0; i < 12; i++) { await a.sleep(1000); if (a.dump().some((n) => a.label(n))) break }
  await a.sleep(1000)
}
async function signedOut() {
  a.sh(`pm clear ${PKG}`)
  a.sh(`pm grant ${PKG} android.permission.POST_NOTIFICATIONS`)
  await launch()
}
const edits = () => a.dump().filter((n) => /EditText/.test(n.cls)).sort((x, y) => x.y1 - y.y1)
// fill(0, ...) types into the first text field on screen; fill('Last name', ...)
// scrolls that label into view and types into the field under it.
async function fill(which, text) {
  let e
  if (typeof which === 'number') e = edits()[which]
  else {
    const lab = await a.scrollTo(which, 6, 1500)
    e = edits().find((n) => n.y1 >= lab.y1 - 10)
  }
  if (!e) throw new Error(`no text field ${which}`)
  await a.tapXY(Math.round((e.x1 + e.x2) / 2), Math.round((e.y1 + e.y2) / 2))
  await a.type(text)
}
// The six code boxes sit in one tall, invisible text field; its top third is
// where the boxes are drawn, so tap there.
async function enterCode() {
  await a.waitFor(/Verify/)
  const e = edits()[0]
  if (!e) throw new Error('no code field')
  const h = e.y2 - e.y1
  await a.tapXY(Math.round((e.x1 + e.x2) / 2), Math.round(h > 400 ? e.y1 + h * 0.35 : (e.y1 + e.y2) / 2))
  await a.type(CODE)
  await a.hideKeyboard()
  await a.tap(/^Verify$/)
  await a.sleep(1000)
}
async function login(phone) {
  await signedOut()
  await fill('Mobile number', phone)
  await fill(/^Password$/, PASSWORD)
  await a.hideKeyboard()
  await a.tap(/^Log in$/)
  await enterCode()
  for (let i = 0; i < 8 && !a.find(/^Home/); i++) await a.sleep(1000)
}

// ---- capture -------------------------------------------------------------
// Each element: a matcher (string/RegExp), a node, or an array of those
// (boxed together). Missing ones are reported, never guessed.
function boxesFor(id, elements) {
  const nodes = a.dump()
  const boxes = []
  const missing = []
  elements.forEach((el, i) => {
    const parts = (Array.isArray(el) ? el : [el]).map((m) => (m && m.x1 !== undefined ? m : a.find(m, { nodes })))
    const hit = parts.filter(Boolean)
    if (!hit.length) { missing.push(i + 1); return }
    const x1 = Math.min(...hit.map((n) => n.x1)) - 8, y1 = Math.min(...hit.map((n) => n.y1)) - 8
    const x2 = Math.max(...hit.map((n) => n.x2)) + 8, y2 = Math.max(...hit.map((n) => n.y2)) + 8
    boxes.push({ x: Math.max(0, x1), y: Math.max(0, y1), w: Math.min(1170, x2) - Math.max(0, x1), h: Math.min(2532, y2) - Math.max(0, y1), label: i + 1 })
  })
  return [boxes, missing]
}
async function capture(id, elements = []) {
  await a.sleep(800)
  const buf = a.screencap()
  const [boxes, missing] = boxesFor(id, elements)
  results.push([id, save(id, buf)])
  writeBoxes(id, boxes)
  if (missing.length) notFound.push(`${id}: ${missing.join(', ')}`)
}
// Runs one shot with its setup; a failure is logged and the group goes on.
async function shot(id, run) {
  if (!want(id)) return run.always ? run.always() : undefined
  try { await run() } catch (e) { results.push([id, `FAILED: ${e.message.split('\n')[0]}`]) }
}
const editAt = (i) => edits()[i]

module.exports = { a, launch, signedOut, fill, enterCode, login, capture, shot, edits, editAt, seed, e164, want, NEW_PHONE, ACCOUNTS, PASSWORD, CODE, results, notFound }

if (require.main === module) {
  const groups = require('./mobile-shots')
  ;(async () => {
    ensureDirs()
    for (const group of groups) {
      const ids = group.ids.filter(want)
      if (!ids.length) continue
      try { await group.run() } catch (e) { for (const id of ids) if (!results.some((r) => r[0] === id)) results.push([id, `FAILED: ${e.message.split('\n')[0]}`]) }
    }
    a.sh('cmd connectivity airplane-mode disable')
    for (const [id, r] of results) console.log(id.padEnd(7), r)
    if (notFound.length) console.log('Elements not found: ' + notFound.join('; '))
  })().catch((e) => { console.error(e); process.exit(1) })
}
