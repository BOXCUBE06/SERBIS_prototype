// The MOB shots in capture order, grouped by the account they need.
// Each element list follows the box numbers in SHOT-LIST.md.
const { execFileSync, spawn } = require('child_process')
const path = require('path')
const m = require('./mobile')
const { a, shot, capture, fill, login, signedOut, launch, enterCode, seed, NEW_PHONE, ACCOUNTS, PASSWORD } = m

const edit = (i) => m.editAt(i)
const sample = (name) => `/sdcard/Pictures/${name}`

// Puts the sample files where the app's pickers can see them.
function pushSamples() {
  for (const f of ['sample-id.png', 'site-photo.png', 'profile-photo.png']) {
    a.adb('push', path.join(__dirname, 'samples', f), sample(f))
    a.sh(`am broadcast -a android.intent.action.MEDIA_SCANNER_SCAN_FILE -d file://${sample(f)}`)
  }
  a.adb('push', path.join(__dirname, 'samples', 'request-letter.pdf'), '/sdcard/Download/request-letter.pdf')
}

// Back to Home with no form open.
async function home() {
  await launch()
  for (let i = 0; i < 8; i++) {
    if (a.find('Home', { minY: 2200 })) { await a.tap('Home', { minY: 2200 }); return }
    if (a.find(/^Close$/)) { await a.tap(/^Close$/); if (a.find(/^Discard$/)) await a.tap(/^Discard$/); continue }
    await a.sleep(1000)
  }
  throw new Error('could not reach Home')
}
// Taps something only after scrolling it clear of the bottom tab bar.
async function tapClear(match) { const n = await a.scrollTo(match, 6, 2150); await a.tapXY(Math.round((n.x1 + n.x2) / 2), Math.round((n.y1 + Math.min(n.y2, 2200)) / 2)) }
async function tabTo(name) { await home(); await a.tap(name, { minY: 2200 }) }
function bottomTabs() {
  const nodes = a.dump()
  return ['Home', 'Ambulance', 'Services', 'Borrow', 'Track'].map((t) => a.find(t, { minY: 2200, nodes })).filter(Boolean)
}
// Taps the middle-upper part of a tall code field, where the six boxes are drawn.
async function tapCode() {
  const e = edit(0)
  const h = e.y2 - e.y1
  await a.tapXY(Math.round((e.x1 + e.x2) / 2), Math.round(h > 400 ? e.y1 + h * 0.35 : (e.y1 + e.y2) / 2))
}

// Picks a file in the system picker by its name.
async function pickFile(name) {
  for (let i = 0; i < 4; i++) {
    if (a.find(name)) { await a.tap(name); return }
    const folder = a.find(/^(Images|Photos|Pictures|Browse|Downloads)$/)
    if (folder) await a.tap(new RegExp(`^${folder.text || folder.desc}$`))
    else await a.sleep(1000)
  }
  throw new Error(`file not offered by the picker: ${name}`)
}

// The API on :8000, for MOB-39 (stopped, then started again).
function apiPid() {
  const out = execFileSync('netstat', ['-ano']).toString()
  const line = out.split('\n').find((l) => /127\.0\.0\.1:8000\s.*LISTENING/.test(l))
  return line ? line.trim().split(/\s+/).pop() : null
}
function stopApi() { const pid = apiPid(); if (pid) execFileSync('taskkill', ['/PID', pid, '/F', '/T']) }
async function startApi() {
  const backend = path.join(__dirname, '../../../../Backend/SERBIS-Backend')
  spawn('php', ['artisan', 'serve', '--host=127.0.0.1', '--port=8000'], { cwd: backend, env: { ...process.env, DB_DATABASE: 'serbis_capture' }, detached: true, stdio: 'ignore' }).unref()
  for (let i = 0; i < 15 && !apiPid(); i++) await a.sleep(1000)
}

module.exports = [
  {
    // Signed out, then registering the Head of the Family.
    ids: ['MOB-05', 'MOB-40', 'MOB-01', 'MOB-02', 'MOB-03', 'MOB-04'],
    async run() {
      await signedOut()
      await shot('MOB-05', async () => capture('MOB-05', [edit(0), edit(1), /^Log in$/, 'Forgot password?', /^Register$/]))
      await shot('MOB-40', async () => {
        await a.scrollTo('Emergency hotlines'); await a.tap('Emergency hotlines')
        await capture('MOB-40', [/^Echague Rescue Hotline$/, /Landline/])
        await a.back()
      })
      if (!['MOB-01', 'MOB-02', 'MOB-03', 'MOB-04'].some(m.want)) return
      await signedOut()
      await a.tap(/^Register$/)
      await fill('First name', 'Juan'); await fill('Last name', 'Dela Cruz'); await fill('Mobile number', NEW_PHONE); await a.hideKeyboard()
      await shot('MOB-01', async () => capture('MOB-01', ['Registering as', ['First name', 'Last name', /^Juan$/, /^Dela Cruz$/], /^0917/, 'Next: Where you live']))
      await a.tap('Next: Where you live')
      await fill(0, 'San Fab'); await a.tap(/^San Fabian$/)
      await fill('Street / Purok', 'Purok 2, Rizal St'); await a.hideKeyboard()
      await shot('MOB-02', async () => capture('MOB-02', [['Barangay', /^San Fabian$/], ['Street / Purok', /^Purok 2/], /Your barangay is where/]))
      await a.tap('Next: Password and review')
      await fill(/^Password$/, PASSWORD); await fill('Confirm password', PASSWORD); await a.hideKeyboard()
      await a.scrollTo('I agree'); await a.tap('I agree')
      await shot('MOB-03', async () => capture('MOB-03', [edit(0), edit(1), [/^Head of the Family$/, /^Address$/], /^Create account$/]))
      await a.tap(/^Create account$/)
      await a.waitFor(/Check your messages/)
      await shot('MOB-04', async () => capture('MOB-04', [edit(0), /^Verify$/, /Resend code in|Send a new code/, /Back to log in/]))
      await enterCode()
    },
  },
  {
    // The Head of the Family from group 1, with history from seed-capture.php --resident.
    ids: ['MOB-06', 'MOB-09', 'MOB-41', 'MOB-10', 'MOB-11', 'MOB-12', 'MOB-15', 'MOB-16', 'MOB-17', 'MOB-18', 'MOB-19', 'MOB-20', 'MOB-21', 'MOB-22', 'MOB-23',
      'MOB-24', 'MOB-43', 'MOB-25', 'MOB-26', 'MOB-27', 'MOB-28', 'MOB-29', 'MOB-30', 'MOB-31', 'MOB-32', 'MOB-33', 'MOB-34', 'MOB-44', 'MOB-47', 'MOB-46',
      'MOB-35', 'MOB-36', 'MOB-45', 'MOB-37', 'MOB-38', 'MOB-39', 'MOB-07'],
    async run() {
      pushSamples()
      // Signing in is MOB-06.
      await signedOut()
      await fill(0, NEW_PHONE); await fill(1, PASSWORD); await a.hideKeyboard(); await a.tap(/^Log in$/)
      await a.waitFor(/Verify/)
      await shot('MOB-06', async () => capture('MOB-06', [edit(0), /^Verify$/]))
      await enterCode()

      await shot('MOB-09', async () => { await home(); await capture('MOB-09', [/^Your latest request/, [/^Patient transport/, /^All services/], /^Announcements$/, 'Notifications', bottomTabs()]) })
      await shot('MOB-41', async () => { await home(); await capture('MOB-41', ['Emergency? Call the hotline']) })
      await shot('MOB-10', async () => { await home(); await a.tap('Notifications'); await capture('MOB-10', [/^New$/, /^Earlier$/, 'MDRRMO advisories']); await a.back() })
      await shot('MOB-11', async () => { await tabTo('Services'); await capture('MOB-11', [/^Infrastructure$/, /^Road clearing$/]) })
      await shot('MOB-12', async () => {
        await tabTo('Services'); await a.tap(/^Road clearing$/)
        if (a.find('Choose file', { index: 1 })) { await a.tap('Choose file', { index: 1 }); await pickFile(/^site-photo.png,/) }
        await a.scrollTo('Send request'); await a.tap('Send request'); await a.scrollUp(2000)
        await capture('MOB-12', [/Please check/, /[Rr]equired/, 'Attachments', 'Send request'])
      })
      await shot('MOB-15', async () => {
        await tabTo('Services'); await a.scrollTo(/^MDRRMO certification$/); await a.tap(/^MDRRMO certification$/)
        await fill(0, 'Certification for our household disaster preparedness plan.'); await a.hideKeyboard()
        await capture('MOB-15', [edit(0), /letter/i])
      })
      await shot('MOB-16', async () => {
        await tabTo('Services'); await a.scrollTo(/^Others?$/); await a.tap(/^Others?$/)
        await fill(0, 'Vehicle to carry chairs and tents for the purok meeting.'); await a.hideKeyboard()
        await capture('MOB-16', [edit(0), /date/i])
        await a.scrollTo('Send request'); await a.tap('Send request')
        await a.waitFor('Request sent')
      })
      await shot('MOB-17', async () => { await capture('MOB-17', [/TXN-/, 'View in Track', /^Done$/]); await a.tap(/^Done$/) })

      // Ambulance, steps 1-5, then leaving the form.
      if (['MOB-18', 'MOB-19', 'MOB-20', 'MOB-21', 'MOB-22', 'MOB-23'].some(m.want)) {
        await tabTo('Ambulance')
        await a.tap('Someone else')
        await fill(0, 'Lola Felicidad Dela Cruz'); await fill(1, '72'); await a.hideKeyboard()
        await shot('MOB-18', async () => capture('MOB-18', [/^Step 1 of 5/, edit(0), edit(1), 'Next: Trip']))
        await a.tap('Next: Trip')
        await shot('MOB-19', async () => capture('MOB-19', [/^From/, /^To/, 'Next: Condition']))
        await a.tap('Next: Condition').catch(() => {})
        await fill(0, 'Regular dialysis, three times a week. Needs wheelchair assist.').catch(() => {}); await a.hideKeyboard()
        await shot('MOB-20', async () => capture('MOB-20', [edit(0), /relative/i]))
        await a.tap('Next: Schedule and ID').catch(() => {})
        await a.tap(/^Scheduled$/).catch(() => {})
        await shot('MOB-21', async () => capture('MOB-21', [[/Now, when available/, /^Scheduled$/], /date|time/i, /available|unit/i, /Valid ID|ID photo/i]))
        await a.tap('Next: Review').catch(() => {})
        await shot('MOB-22', async () => capture('MOB-22', [/Patient/, /^Edit$/, /Send request/]))
        await a.tap(/^Close$/)
        await shot('MOB-23', async () => capture('MOB-23', [/^Discard$/, /^Keep editing$/]))
        if (a.find(/^Discard$/)) await a.tap(/^Discard$/)
      }

      await shot('MOB-24', async () => { await tabTo('Track'); await capture('MOB-24', [/^In progress$/, /^Under review$|^Booked$/, /^Updated /, /^Past requests$/]) })
      await shot('MOB-43', async () => { await tabTo('Track'); await a.scrollTo(/^Borrowed items/); await capture('MOB-43', [/^Borrowed items/, /Wheel Chair/]) })
      await shot('MOB-25', async () => {
        await tabTo('Track'); await a.tap(/^Under review$/)
        await capture('MOB-25', [/^Under review$/, /^Sent$/, /MDRRMO is checking|What happens next/, /^Cancel request$/])
      })
      await shot('MOB-26', async () => {
        await tabTo('Track'); await a.tap(/^Under review$/).catch(() => {}); await a.scrollTo(/^Cancel request$/); await a.tap(/^Cancel request$/)
        await a.waitFor(/^Keep request$/)
        await capture('MOB-26', [/^Cancel request$/, /^Keep request$/]); await a.tap(/^Keep request$/)
      })
      await shot('MOB-27', async () => { await tabTo('Borrow'); await capture('MOB-27', [edit(0), /^Modular Tent/, /^Borrow$/, /Need something else/]) })
      await shot('MOB-28', async () => {
        await tabTo('Borrow'); await a.scrollTo(/^Wheel Chair/)
        const row = a.find(/^Wheel Chair/)
        const b = a.dump().find((n) => /^Borrow$/.test(a.label(n)) && n.y1 >= row.y1 - 10 && n.y2 <= row.y2 + 10)
        await a.tapXY(Math.round((b.x1 + b.x2) / 2), Math.round((b.y1 + b.y2) / 2))
        await fill(/purpose|What is it for/i, 'Post-surgery recovery at home').catch(() => {}); await a.hideKeyboard()
        await capture('MOB-28', [/Quantity|How many/i, /Pickup|Pick up/, /purpose|for\?/i, /^Send request$/]); await a.back()
      })
      await shot('MOB-29', async () => {
        await tabTo('Borrow'); await a.scrollTo(/Need something else/); await a.tap(/Need something else/)
        await fill(0, 'Folding stretcher'); await a.hideKeyboard()
        await capture('MOB-29', [edit(0), /Quantity|How many/i, /^Send request$/]); await a.back()
      })
      await shot('MOB-30', async () => {
        await tabTo('Borrow'); await a.tap(/^My requests/)
        await capture('MOB-30', [/^Picked up$|^Delivered$/, /Please return it by/, /photo/i, /^Cancel request$/, /^Borrow again$/])
      })
      await shot('MOB-31', async () => {
        await home(); await tapClear(/^Safety guides/)
        if (a.find(/^Download/)) await a.tap(/^Download/)
        await capture('MOB-31', [/^Emergency hotlines$/, /First aid|Flood|Earthquake/, /^Saved$|^Download/])
      })
      await shot('MOB-32', async () => { await home(); await tapClear(/^Safety guides/); await a.tap(/Flood Preparedness|Basic first aid|First aid/); await capture('MOB-32', [/Flood Preparedness|first aid/i, /^Back$/]) })
      await shot('MOB-33', async () => { await home(); await tapClear(/^Safety guides/); await a.scrollTo(/^Emergency hotlines$/); await capture('MOB-33', [/^Echague Rescue Hotline/, /Landline/]) })
      await shot('MOB-34', async () => {
        await home(); await a.tap('My profile')
        await capture('MOB-34', [/^Edit my details$/, /^09\d+ · /, /^Language/, /^MDRRMO text alerts/, /^Offline materials/, /^Log out$/])
      })
      await shot('MOB-44', async () => { await home(); await a.tap('My profile'); await a.tap(/^Offline materials/); await capture('MOB-44', [/Downloaded documents/, /Included in the app/]); await a.back() })
      await shot('MOB-47', async () => { await home(); await a.tap('My profile'); await a.scrollTo(/^Contact MDRRMO/); await capture('MOB-47', [/^Contact MDRRMO/]) })
      await shot('MOB-46', async () => {
        await home(); await a.tap('My profile'); await a.tap(/Change profile photo/)
        await a.tap(/^Choose a photo$/); await pickFile(/^profile-photo.png,/); await a.sleep(1000)
        await a.tap(/Change profile photo/); await a.waitFor(/^Remove photo$/)
        await capture('MOB-46', [/^Choose a photo$/, /^Remove photo$/]); await a.back()
      })
      await shot('MOB-35', async () => {
        await home(); await a.tap('My profile'); await a.tap(/^Edit my details$/)
        await capture('MOB-35', [[/^First name$/, /^Last name$/], [/^Barangay$/, /^San Fabian$/], /^Save changes$/]); await a.back()
      })
      if (['MOB-36', 'MOB-45'].some(m.want)) {
        await home(); await a.tap('My profile'); await a.tap(/^Edit my details$/); await a.tap(/^Change number$/)
        await fill(0, '09175550199'); await fill(1, PASSWORD); await a.hideKeyboard()
        await shot('MOB-36', async () => capture('MOB-36', [edit(0), edit(1), /^Send code$/]))
        await a.tap(/^Send code$/); await a.waitFor(/Enter the code/)
        // Typed but never confirmed: confirming would move the login to the new number.
        await tapCode(); await a.type(m.CODE); await a.hideKeyboard()
        await shot('MOB-45', async () => capture('MOB-45', [edit(0), /Resend code in|Send a new code/, /^Confirm new number$/]))
        await a.back(); await a.back()
      }
      await shot('MOB-37', async () => { await home(); await a.tap('My profile'); await a.tap(/^Language/); await capture('MOB-37', [/^English$/, /^Filipino$/]); await a.back() })
      await shot('MOB-38', async () => {
        await tabTo('Track')
        a.sh('cmd connectivity airplane-mode enable')
        try { await a.waitFor(/No connection to MDRRMO/); await capture('MOB-38', [/No connection to MDRRMO/, /Last updated/]) }
        finally { a.sh('cmd connectivity airplane-mode disable'); await a.sleep(1000) }
      })
      await shot('MOB-39', async () => {
        stopApi()
        try {
          await tabTo('Services'); await a.swipe(585, 700, 585, 1700); await a.waitFor(/Try again/)
          await capture('MOB-39', [/could not be loaded|Couldn/, /^Try again$/])
        } finally { await startApi() }
      })
      await shot('MOB-07', async () => {
        await signedOut(); await a.tap('Forgot password?')
        await fill(0, NEW_PHONE); await a.hideKeyboard(); await a.tap(/^Send code$/)
        await enterCode()
        await a.waitFor(/Choose a new password/)
        await fill(/^New password$/, 'Passw0rd!456'); await fill(/^Confirm new password$/, 'Passw0rd!456'); await a.hideKeyboard()
        await capture('MOB-07', [/^New password$/, /^Confirm new password$/, /^Change password$/])
      })
    },
  },
  {
    // Barangay hall: relief goods and the trainings form.
    ids: ['MOB-13', 'MOB-14'],
    async run() {
      await login(ACCOUNTS.hall)
      await shot('MOB-13', async () => {
        await tabTo('Services'); await a.scrollTo(/^Relief goods/i); await a.tap(/^Relief goods/i)
        await capture('MOB-13', [/^Step 1 of 3/, /Household/, /^Next: /])
      })
      await shot('MOB-14', async () => {
        await tabTo('Services'); await a.scrollTo(/^DRRM trainings/i); await a.tap(/^DRRM trainings/i)
        await capture('MOB-14', [/date/i, /letter/i, /^Send request$/])
      })
    },
  },
  {
    ids: ['MOB-08'],
    async run() {
      await login(ACCOUNTS.pendingOrg)
      await shot('MOB-08', async () => { await a.waitFor(/Awaiting MDRRMO approval/); await capture('MOB-08', [/Awaiting MDRRMO approval/, /^Check again$/]) })
    },
  },
  {
    // Activated organization with Ambulance unticked for organizations.
    ids: ['MOB-42'],
    async run() {
      seed('--hide-ambulance-for-organizations')
      try {
        await login(ACCOUNTS.org)
        await shot('MOB-42', async () => { await tabTo('Ambulance'); await capture('MOB-42', [/^Not available/, /not offered to this account type/]) })
      } finally { seed('--restore-audience') }
    },
  },
]
module.exports.home = home; module.exports.tabTo = tabTo; module.exports.pickFile = pickFile
