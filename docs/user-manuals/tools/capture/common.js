// Shared paths and checks for the manual screenshot scripts.
// Playwright comes from the admin panel's devDependencies. The installed Edge
// is used: Application Control blocks the bundled Chromium on this machine.
const path = require('path')
const fs = require('fs')
const os = require('os')
const { createRequire } = require('module')

const REPO = path.resolve(__dirname, '../../../..')
const req = createRequire(path.join(REPO, 'Web/serbis-admin-vue/package.json'))
const { chromium } = req('playwright')

const ADMIN_URL = process.env.SERBIS_ADMIN_URL || 'http://localhost:3000'
const API_URL = process.env.SERBIS_API_URL || 'http://localhost:8000/api'
const MOBILE_URL = process.env.SERBIS_MOBILE_URL || 'http://localhost:5000'

const OUT_ROOT = path.join(os.homedir(), 'Downloads', 'SERBIS-screenshots')
const RAW = path.join(REPO, 'docs/user-manuals/screenshots/raw')
const SAMPLES = path.join(__dirname, 'samples')
const STATE = path.join(__dirname, '.state')

// Refuse anything but this machine: the capture must never touch a deployed API.
function assertLocal() {
  for (const url of [ADMIN_URL, API_URL, MOBILE_URL]) {
    const host = new URL(url).hostname
    if (!['localhost', '127.0.0.1', '::1'].includes(host)) {
      throw new Error(`Refusing to capture: ${url} is not localhost.`)
    }
  }
}

function ensureDirs() {
  for (const dir of [path.join(OUT_ROOT, 'web'), path.join(OUT_ROOT, 'mobile'), RAW, SAMPLES, STATE]) {
    fs.mkdirSync(dir, { recursive: true })
  }
}

// Writes <ID>.png to Downloads and copies it to screenshots/raw.
function save(id, buffer) {
  const kind = id.startsWith('MOB') ? 'mobile' : 'web'
  const file = path.join(OUT_ROOT, kind, `${id}.png`)
  fs.writeFileSync(file, buffer)
  fs.copyFileSync(file, path.join(RAW, `${id}.png`))
  return file
}

function launch() {
  return chromium.launch({ channel: 'msedge', headless: true })
}

module.exports = { REPO, ADMIN_URL, API_URL, MOBILE_URL, OUT_ROOT, RAW, SAMPLES, STATE, assertLocal, ensureDirs, save, launch }
