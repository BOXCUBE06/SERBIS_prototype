// Admin panel screenshots (ADM-xx) for docs/user-manuals/SHOT-LIST.md.
// Needs: API on :8000 against serbis_capture (fake SMS, local OTP bypass),
// admin panel on :3000, setup.js and seed-capture.php already run.
// Run: node docs/user-manuals/tools/capture/admin.js [ADM-01 ADM-09 ...]
const fs = require('fs')
const { OUT_ROOT, ADMIN_URL, SAMPLES, assertLocal, ensureDirs, save, launch } = require('./common')
const { adminLogin, adminSession, VIEWPORT } = require('./login')
const { measure, writeBoxes } = require('./admin-boxes')
const path = require('path')

const args = process.argv.slice(2)
const FORCE = args.includes('--force')
const SEND_BLAST = args.includes('--send-blast')
const ONLY = new Set(args.filter((a) => !a.startsWith('--')))
const results = []

const settle = async (page, ms = 700) => {
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.locator('.v-skeleton-loader').first().waitFor({ state: 'detached', timeout: 5000 }).catch(() => {})
  // DataTablePage draws its own .skel placeholder rows while a list loads.
  await page.locator('.skel').first().waitFor({ state: 'detached', timeout: 10000 }).catch(() => {})
  await page.waitForTimeout(ms)
}
const go = async (page, route) => { await page.goto(ADMIN_URL + route); await settle(page, 1000) }
const overlay = (page) => page.locator('.v-overlay--active .v-overlay__content').last()
const btn = (page, name) => page.getByRole('button', { name, exact: typeof name === 'string' }).last()
const row = (page, ...texts) => texts.reduce((loc, t) => loc.filter({ hasText: t }), page.locator('tr:visible'))
// The detail drawer docks on the right at this width.
const DRAWER = { x: 760, y: 0, width: 520, height: 800 }
const MAIN = { x: 284, y: 0, width: 996, height: 800 }
// The two request queues are too wide for 1280: their columns and the
// "Sorted by" line are cut off. They are taken at 1440x900 instead.
const WIDE = { width: 1440, height: 900 }
const WIDE_MAIN = { x: 284, y: 0, width: 1156, height: 900 }
const cutOff = (p) => p.evaluate(() => [...document.querySelectorAll('body *')].some((el) => el.children.length === 0 && el.textContent.trim() && el.getBoundingClientRect().right > innerWidth + 1 && el.getBoundingClientRect().left < innerWidth))

// Where the last image starts and how big it is, in CSS pixels, for the boxes.
let lastShot = null
const shot = async (page, target) => {
  await page.waitForTimeout(500)
  if (!target) { lastShot = { origin: { x: 0, y: 0 }, size: page.viewportSize() }; return page.screenshot() }
  if (target.x !== undefined) { lastShot = { origin: { x: target.x, y: target.y }, size: { width: target.width, height: target.height } }; return page.screenshot({ clip: target }) }
  await target.scrollIntoViewIfNeeded()
  const b = await target.boundingBox()
  lastShot = { origin: { x: b.x, y: b.y }, size: { width: b.width, height: b.height } }
  return target.screenshot()
}

const openDetail = async (page, route, ...texts) => {
  await go(page, route)
  await row(page, ...texts).first().click()
  await settle(page)
}

// [ID, user, action returning the image]
const SHOTS = [
  ['ADM-04', 'maria.pascual', async (p) => { await go(p, '/'); return shot(p) }],
  ['ADM-05', 'admin', async (p) => { await go(p, '/'); await btn(p, 'Sign out').click(); await settle(p); return shot(p) }],
  ['ADM-06', 'admin', async (p) => { await go(p, '/'); return shot(p, MAIN) }],
  ['ADM-07', 'admin', async (p) => { await go(p, '/analytics'); return shot(p, MAIN) }],
  ['ADM-08', 'admin', async (p) => {
    await go(p, '/analytics')
    const title = p.getByText('Requests by month and service', { exact: false }).first()
    await title.scrollIntoViewIfNeeded()
    await p.getByRole('button', { name: /^(Show as )?table$/i }).first().click().catch(() => {})
    await settle(p)
    // The section has no single wrapper: clip from its title to the bottom of its table.
    await title.evaluate((el) => el.scrollIntoView({ block: 'start' }))
    await p.waitForTimeout(400)
    const t = await title.boundingBox()
    const table = await title.locator('xpath=following::table[1]').boundingBox()
    const bottom = table ? Math.min(800, table.y + table.height + 16) : 800
    return shot(p, { x: MAIN.x, y: Math.max(0, t.y - 16), width: MAIN.width, height: bottom - Math.max(0, t.y - 16) })
  }],
  ['ADM-09', 'admin', async (p) => {
    await p.setViewportSize(WIDE); await go(p, '/manage-requests')
    if (await cutOff(p)) console.warn('ADM-09: text still runs past the right edge')
    return shot(p, WIDE_MAIN)
  }],
  ['ADM-10', 'admin', async (p) => {
    await openDetail(p, '/manage-requests', 'TXN-000066')
    // Scrolled so Assignment (Select vehicle) clears the action row; the header stays put.
    await p.getByRole('button', { name: 'Select vehicle' }).last().evaluate((el) => el.scrollIntoView({ block: 'center' }))
    await p.waitForTimeout(400)
    return shot(p, DRAWER)
  }],
  ['ADM-11', 'admin', async (p) => {
    await openDetail(p, '/manage-requests', 'TXN-000066')
    await btn(p, 'Select vehicle').click(); await settle(p)
    await p.getByText('Rescue Vehicle (Hilux) 2').last().click({ force: true })
    return shot(p, DRAWER)
  }],
  ['ADM-12', 'admin', async (p) => {
    await openDetail(p, '/manage-requests', 'TXN-000066')
    await btn(p, 'Approve & assign').click(); await settle(p)
    return shot(p, overlay(p))
  }],
  ['ADM-13', 'admin', async (p) => {
    await openDetail(p, '/manage-requests', 'TXN-000066')
    await btn(p, 'Disapprove').click(); await settle(p)
    await overlay(p).locator('textarea').first().fill('The road is under DPWH; we have referred it to their district office.')
    return shot(p, overlay(p))
  }],
  ['ADM-14', 'admin', async (p) => { await openDetail(p, '/manage-requests', 'TXN-000067'); return shot(p, DRAWER) }],
  ['ADM-15', 'admin', async (p) => { await go(p, '/manage-requests'); await btn(p, 'Log service request').click(); await settle(p); return shot(p, overlay(p)) }],
  ['ADM-16', 'admin', async (p) => {
    // Taller window so the whole export dialog fits.
    await p.setViewportSize(WIDE); await go(p, '/manage-requests')
    await btn(p, 'Print / Export').click(); await p.waitForTimeout(400)
    await p.getByText(/^Export all \d+ matching/).click(); await settle(p)
    return shot(p, overlay(p))
  }],
  ['ADM-17', 'admin', async (p) => {
    // TXN-000072: a "now" request filed through the resident API (no scheduled time).
    await openDetail(p, '/conduction-requests', 'TXN-000072')
    // Scrolled so the patient section is in view with the route and the time.
    await p.locator('h3', { hasText: 'PATIENT' }).last().evaluate((el) => el.scrollIntoView({ block: 'center' }))
    await p.waitForTimeout(400)
    return shot(p, DRAWER)
  }],
  ['ADM-18', 'admin', async (p) => {
    await openDetail(p, '/conduction-requests', 'TXN-000072')
    await btn(p, 'Select unit').click(); await settle(p)
    await p.getByText('Ambulance 1', { exact: true }).first().click()
    return shot(p, DRAWER)
  }],
  // ADM-47/48: bookings filed through the resident API, so they arrive Booked
  // with no unit. ADM-47 stops before Done; ADM-48 assigns one and saves.
  ['ADM-47', 'admin', async (p) => {
    // TXN-000074: a second API-filed booking, left unassigned so Select unit shows.
    await openDetail(p, '/conduction-requests', 'TXN-000074')
    await btn(p, 'Select unit').click(); await settle(p)
    await p.getByText('Ambulance 3', { exact: true }).first().click()
    return shot(p, DRAWER)
  }],
  ['ADM-48', 'admin', async (p) => {
    await openDetail(p, '/conduction-requests', 'TXN-000073')
    if (await btn(p, 'Select unit').isVisible().catch(() => false)) {
      await btn(p, 'Select unit').click(); await settle(p)
      await p.getByText('Ambulance 2', { exact: true }).first().click()
      await btn(p, 'Done').click(); await settle(p, 1500)
    }
    // Scrolled so Assignment and Disapprove this booking sit above the footer.
    await p.getByRole('button', { name: 'Disapprove this booking' }).evaluate((el) => el.scrollIntoView({ block: 'end' }))
    await p.waitForTimeout(400)
    return shot(p, DRAWER)
  }],
  ['ADM-19', 'admin', async (p) => {
    await openDetail(p, '/conduction-requests', 'TXN-000026')
    await btn(p, 'Reschedule').click(); await settle(p)
    await overlay(p).locator('textarea').first().fill('The unit is needed for an earlier dialysis trip. Moved two hours later.')
    return shot(p, overlay(p))
  }],
  ['ADM-20', 'admin', async (p) => {
    await go(p, '/conduction-requests')
    await btn(p, 'Day view').click(); await settle(p)
    for (let i = 0; i < 3; i++) { await btn(p, 'Next day').click(); await p.waitForTimeout(500) }
    await settle(p)
    return shot(p, overlay(p))
  }],
  ['ADM-21', 'admin', async (p) => {
    await go(p, '/conduction-requests')
    await p.getByText('Trip logs', { exact: true }).click(); await settle(p)
    await row(p, 'TXN-000014').first().click(); await settle(p)
    await btn(p, 'Update trip log').click(); await settle(p)
    return shot(p, DRAWER)
  }],
  ['ADM-22', 'admin', async (p) => {
    await p.setViewportSize(WIDE); await go(p, '/conduction-requests')
    if (await cutOff(p)) console.warn('ADM-22: text still runs past the right edge')
    await p.getByText('Trip logs', { exact: true }).click(); await settle(p)
    await row(p, 'TXN-000014').first().click(); await settle(p)
    return shot(p)
  }],
  ['ADM-23', 'admin', async (p) => {
    await go(p, '/conduction-requests')
    await p.getByText('Trip logs', { exact: true }).click(); await settle(p)
    await row(p, 'TXN-000014').first().click(); await settle(p)
    const [form] = await Promise.all([p.context().waitForEvent('page'), btn(p, 'Print').click()])
    await form.setViewportSize({ width: 820, height: 1160 })
    await form.emulateMedia({ media: 'print' })
    await form.waitForLoadState('load'); await form.waitForTimeout(1000)
    const buf = await form.screenshot({ fullPage: true })
    await form.close()
    return buf
  }],
  ['ADM-24', 'admin', async (p) => { await go(p, '/borrowings'); return shot(p, MAIN) }],
  ['ADM-25', 'admin', async (p) => { await openDetail(p, '/borrowings', 'Water Purifier', 'Ernesto'); return shot(p, DRAWER) }],
  ['ADM-26', 'admin', async (p) => {
    await go(p, '/borrowings')
    await btn(p, 'Deny Water Purifier for Ernesto Dumlao').click(); await settle(p)
    return shot(p, overlay(p))
  }],
  ['ADM-27', 'admin', async (p) => {
    await go(p, '/borrowings')
    // The due date is set when approving. Release on a row that has one acts
    // at once, with no dialog, so it is never clicked here.
    await btn(p, 'Approve Water Purifier for Ernesto Dumlao').click(); await settle(p)
    return shot(p, overlay(p))
  }],
  ['ADM-28', 'admin', async (p) => { await openDetail(p, '/borrowings', 'Generator Set', 'Rodolfo'); return shot(p, DRAWER) }],
  ['ADM-29', 'admin', async (p) => { await go(p, '/vehicles'); await btn(p, 'Add unit').click(); await settle(p); return shot(p, overlay(p)) }],
  ['ADM-30', 'admin', async (p) => { await go(p, '/responders'); await btn(p, 'Add responder').click(); await settle(p); return shot(p, overlay(p)) }],
  ['ADM-31', 'admin', async (p) => { await go(p, '/inventory'); await btn(p, 'Add equipment').click(); await settle(p); return shot(p, overlay(p)) }],
  ['ADM-32', 'admin', async (p) => { await go(p, '/procurement'); return shot(p, MAIN) }],
  ['ADM-33', 'admin', async (p) => { await go(p, '/users'); return shot(p, MAIN) }],
  ['ADM-34', 'admin', async (p) => {
    await go(p, '/users')
    await p.getByRole('tab', { name: /^Organization/ }).click(); await settle(p)
    await row(p, 'Corazon').first().locator('td').nth(1).click(); await settle(p)
    return shot(p, overlay(p))
  }],
  ['ADM-35', 'admin', async (p) => {
    await go(p, '/files')
    const [chooser] = await Promise.all([p.waitForEvent('filechooser'), btn(p, /Upload a file/).click()])
    await chooser.setFiles(path.join(SAMPLES, 'request-letter.pdf'))
    await settle(p)
    await p.getByLabel(/Title shown to residents/).fill('Typhoon Preparedness Reminders')
    return shot(p, MAIN)
  }],
  ['ADM-36', 'admin', async (p) => {
    await go(p, '/sms')
    await fillBlast(p)
    // Cropped below the page header: its subtitle is the server's SkySMS key
    // status, which says nothing to a reader of the manual.
    const card = p.locator('.v-card').filter({ hasText: 'New blast' }).first()
    const top = (await card.boundingBox()).y - 16
    return shot(p, { ...MAIN, y: top, height: MAIN.height - top })
  }],
  ['ADM-37', 'admin', async (p) => {
    await go(p, '/sms')
    await fillBlast(p)
    await btn(p, 'Send blast').click(); await settle(p)
    await overlay(p).getByLabel('Text blast code').fill('123456')
    const buf = await shot(p, overlay(p))
    // Send it (SMS is faked locally) so Recent blasts and SMS history have a row.
    if (SEND_BLAST) { await overlay(p).getByRole('button', { name: 'Send blast' }).click(); await settle(p, 1000) }
    return buf
  }],
  ['ADM-38', 'admin', async (p) => {
    await go(p, '/sms')
    await btn(p, 'Text blast code').click(); await settle(p)
    const inputs = overlay(p).locator('input')
    const n = await inputs.count()
    for (let i = 0; i < n; i++) await inputs.nth(i).fill(i === 0 ? '123456' : '246813')
    return shot(p, overlay(p))
  }],
  ['ADM-39', 'admin', async (p) => { await go(p, '/services-config'); await btn(p, 'Edit Road Clearing').click(); await settle(p); return shot(p, overlay(p)) }],
  ['ADM-40', 'admin', async (p) => { await go(p, '/service-audience'); return shot(p, MAIN) }],
  ['ADM-41', 'admin', async (p) => { await go(p, '/service-vehicles'); return shot(p, MAIN) }],
  ['ADM-42', 'admin', async (p) => { await go(p, '/hotlines'); await btn(p, 'Add hotline').click(); await settle(p); return shot(p, overlay(p)) }],
  ['ADM-43', 'admin', async (p) => { await go(p, '/staff'); await btn(p, 'Add staff account').click(); await settle(p); return shot(p, overlay(p)) }],
  ['ADM-44', 'admin', async (p) => {
    await go(p, '/staff')
    await btn(p, 'More actions for Maria Elena Pascual').click(); await p.waitForTimeout(400)
    await p.getByText('Manage access', { exact: true }).click(); await settle(p)
    return shot(p, overlay(p))
  }],
  ['ADM-45', 'admin', async (p) => {
    await go(p, '/staff')
    await p.getByRole('checkbox', { name: 'Select Ramil Cabacungan' }).check({ force: true }); await settle(p)
    await btn(p, 'More actions for Ramil Cabacungan').click(); await settle(p)
    return shot(p, MAIN)
  }],
  ['ADM-46', 'admin', async (p) => { await go(p, '/logs'); return shot(p, MAIN) }],
]

// ADM-36/37: a calm, link-free notice to San Fabian. ADM-37 sends it only
// with --send-blast; SMS is faked locally.
async function fillBlast(p) {
  await p.getByPlaceholder('Select one or more barangays').click({ force: true })
  await p.keyboard.type('San Fabian')
  await p.waitForTimeout(500)
  await p.locator('.v-overlay--active .v-list-item').filter({ hasText: 'San Fabian' }).first().click()
  await p.keyboard.press('Escape')
  await p.locator('textarea').first().fill('MDRRMO Echague: The barangay road clearing operation is scheduled for Saturday, 8 AM. Please keep vehicles off the road shoulder. Thank you.')
  await settle(p)
}

// Fills annotations.json for one shot from the live page; missing elements are reported, not guessed.
const notFound = []
async function annotate(id, page, at) {
  const [boxes, missing] = await measure(id, page, at.origin, at.size)
  writeBoxes(id, boxes)
  if (missing.length) notFound.push(`${id}: ${missing.join(', ')}`)
}

const PASSWORDS = { admin: 'password123', 'maria.pascual': 'password123' }

;(async () => {
  assertLocal()
  ensureDirs()
  const browser = await launch()
  const exists = (id) => fs.existsSync(path.join(OUT_ROOT, 'web', `${id}.png`))
  const want = (id) => (ONLY.size === 0 || ONLY.has(id)) && (FORCE || !exists(id))

  // ADM-01/02: the sign-in itself, captured on the way in.
  if (want('ADM-01') || want('ADM-02')) {
    const { context } = await adminLogin(browser, 'admin', 'password123', {
      onPassword: async (p) => { await p.waitForTimeout(500); results.push(['ADM-01', save('ADM-01', await p.screenshot())]); await annotate('ADM-01', p, { origin: { x: 0, y: 0 }, size: VIEWPORT }) },
      onCode: async (p) => { await p.waitForTimeout(500); results.push(['ADM-02', save('ADM-02', await p.screenshot())]); await annotate('ADM-02', p, { origin: { x: 0, y: 0 }, size: VIEWPORT }) },
    })
    await context.close()
  }

  // ADM-03: a staff account on a temporary password lands on Change password.
  if (want('ADM-03')) {
    const { context, page } = await adminLogin(browser, 'ramil.cabacungan', 'TempPass123')
    await settle(page)
    const pw = page.locator('input[type=password]')
    await pw.nth(0).fill('TempPass123')
    await pw.nth(1).fill('NewPassw0rd!')
    await pw.nth(2).fill('NewPassw0rd!')
    // Let the floating labels finish moving before the shot.
    await page.locator('h1').click(); await page.waitForTimeout(1500)
    results.push(['ADM-03', save('ADM-03', await page.screenshot())])
    await annotate('ADM-03', page, { origin: { x: 0, y: 0 }, size: VIEWPORT })
    await context.close()
  }

  const sessions = {}
  for (const [id, user, run] of SHOTS) {
    if (!want(id)) continue
    try {
      sessions[user] ??= await adminSession(browser, user, PASSWORDS[user])
      const page = await sessions[user].context.newPage()
      page.setDefaultTimeout(5000)
      page.setDefaultNavigationTimeout(10000)
      lastShot = null
      const buf = await run(page)
      results.push([id, save(id, buf)])
      if (lastShot) await annotate(id, page, lastShot)
      await page.close()
    } catch (e) {
      results.push([id, `FAILED: ${e.message.split('\n')[0]}`])
    }
  }
  await browser.close()
  for (const [id, r] of results) console.log(id.padEnd(7), r)
  if (notFound.length) console.log('Elements not found: ' + notFound.join('; '))
})().catch((e) => { console.error(e); process.exit(1) })
