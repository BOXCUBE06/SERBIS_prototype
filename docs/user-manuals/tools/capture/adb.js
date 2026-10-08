// Small adb driver for the mobile shots: read the screen through
// `uiautomator dump`, tap and type with `input`, capture with screencap.
// Coordinates are device pixels, which are also the screenshot's pixels.
const { execFileSync } = require('child_process')
const path = require('path')
const os = require('os')

const SDK = process.env.ANDROID_SDK_ROOT || path.join(os.homedir(), 'AppData/Local/Android/Sdk')
const ADB = path.join(SDK, 'platform-tools', process.platform === 'win32' ? 'adb.exe' : 'adb')

const adb = (...args) => execFileSync(ADB, args, { maxBuffer: 64 * 1024 * 1024, timeout: 20000, stdio: ['ignore', 'pipe', 'ignore'] })
const sh = (cmd) => adb('shell', cmd).toString()
const sleep = (ms) => new Promise((r) => setTimeout(r, Math.min(ms, 1000)))

const decode = (s) => s.replace(/&#10;/g, '\n').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&apos;/g, "'")

function dump() {
  let xml = ''
  for (let i = 0; i < 3 && !xml.includes('<node'); i++) {
    try { sh('uiautomator dump /sdcard/ui.xml'); xml = adb('exec-out', 'cat', '/sdcard/ui.xml').toString() } catch { xml = '' }
  }
  const nodes = []
  for (const m of xml.matchAll(/<node ([^>]*?)\/?>/g)) {
    const a = {}
    for (const kv of m[1].matchAll(/([\w-]+)="([^"]*)"/g)) a[kv[1]] = decode(kv[2])
    const b = (a.bounds || '').match(/\[(\d+),(\d+)\]\[(\d+),(\d+)\]/)
    if (!b) continue
    const [x1, y1, x2, y2] = b.slice(1).map(Number)
    nodes.push({ text: a.text || '', desc: a['content-desc'] || '', cls: a.class || '', clickable: a.clickable === 'true', focused: a.focused === 'true', x1, y1, x2, y2 })
  }
  return nodes
}

const label = (n) => `${n.text} ${n.desc}`.trim()
// First node whose text or description matches (string: contains; RegExp: test).
function find(match, { nodes = dump(), index = 0, minY = 0 } = {}) {
  const hits = nodes.filter((n) => n.x2 > n.x1 && n.y2 > n.y1 && n.y1 >= minY && (match instanceof RegExp ? match.test(label(n)) : label(n).includes(match)))
  return hits[index] || null
}

async function waitFor(match, tries = 8) {
  for (let i = 0; i < tries; i++) {
    const n = find(match)
    if (n) return n
    await sleep(1000)
  }
  throw new Error(`not on screen: ${match}`)
}

const center = (n) => [Math.round((n.x1 + n.x2) / 2), Math.round((n.y1 + n.y2) / 2)]
async function tapXY(x, y) { sh(`input tap ${x} ${y}`); await sleep(900) }
async function tap(match, opts = {}) {
  const n = opts.noWait ? find(match, opts) : (find(match, opts) || await waitFor(match))
  if (!n) throw new Error(`not on screen: ${match}`)
  await tapXY(...center(n))
  return n
}
// adb `input text`: single-quoted for the device shell, spaces as %s.
async function type(text) {
  const safe = text.replace(/ /g, '%s').replace(/'/g, "'\''")
  sh(`input text '${safe}'`)
  await sleep(600)
}
async function key(code) { sh(`input keyevent ${code}`); await sleep(600) }
const back = () => key(4)
// Back closes the keyboard; only sent while the keyboard is up, or it would leave the screen.
async function hideKeyboard() { if (/mInputShown=true/.test(sh('dumpsys input_method'))) await key(4) }
async function swipe(x1, y1, x2, y2, ms = 300) { sh(`input swipe ${x1} ${y1} ${x2} ${y2} ${ms}`); await sleep(900) }
const scrollDown = (amount = 900) => swipe(585, 1800, 585, 1800 - amount)
const scrollUp = (amount = 900) => swipe(585, 900, 585, 900 + amount)
// Scrolls until the match is fully on screen, or the page stops moving.
async function scrollTo(match, max = 6, maxY = 2300) {
  let lastY = null
  for (let i = 0; i < max; i++) {
    const n = find(match)
    if (n && n.y2 <= 2532 && n.y1 > 150 && (n.y2 < maxY || n.y1 === lastY)) return n
    lastY = n ? n.y1 : null
    await scrollDown(700)
  }
  throw new Error(`could not scroll to: ${match}`)
}
const screencap = () => adb('exec-out', 'screencap', '-p')

module.exports = { adb, sh, sleep, dump, find, waitFor, tap, tapXY, type, key, back, hideKeyboard, swipe, scrollDown, scrollUp, scrollTo, screencap, label }
