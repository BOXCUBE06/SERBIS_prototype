import { chromium, test } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

// Throwaway capture spec, part 2, for the 2026-09 UI/UX audit. Captures
// interactive STATES (dialogs, validation errors, empty states, error
// states) that ui-audit.screenshots.spec.ts's base pass didn't reach.
// Per the capture rules for this pass: 1920x1080 light only, except a state
// that involves color/contrast meaning (error text, disabled controls,
// status chips, snackbars), which also gets a dark capture. No 1366x768
// coverage here — that stays limited to the 16 base surfaces already
// captured in part 1. Reuses that spec's storage state; does not touch it
// or playwright.config.ts.
//
// Every wait/click below carries an explicit short timeout — a bare
// .waitFor()/.click() with no timeout inherits whatever's left of the
// *test's* timeout budget, not a fixed 30s, so one wrong selector silently
// eats the rest of the run instead of failing fast.

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const AUTH_FILE = path.join(__dirname, '.auth', 'admin.json')
const OUT_DIR = path.join(__dirname, '..', '..', '..', 'docs', 'ui-audit', 'screenshots')
const VIEWPORT = { width: 1920, height: 1080 }
const T = 5000

test.beforeAll(() => {
  if (!fs.existsSync(AUTH_FILE)) {
    throw new Error(`${AUTH_FILE} is missing — run ui-audit.screenshots.spec.ts first.`)
  }
})

async function gotoThemed(page, urlPath, theme) {
  await page.goto(urlPath)
  await page.evaluate(t => localStorage.setItem('serbis_theme', t), theme)
  await page.reload()
  await page.waitForLoadState('networkidle')
}

async function snap(page, name) {
  await page.waitForTimeout(200)
  await page.screenshot({ path: path.join(OUT_DIR, `${name}--1920x1080.png`), fullPage: true })
}

async function cancelDialog(page) {
  await page.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click({ timeout: T }).catch(() => {})
  await page.waitForTimeout(200)
}

async function closeDialogByLabel(page, label) {
  await page.getByRole('button', { name: label }).click({ timeout: T }).catch(() => {})
  await page.waitForTimeout(200)
}

test('resident requests states', async ({ browser }) => {
  test.setTimeout(120_000)
  const context = await browser.newContext({ storageState: AUTH_FILE, viewport: VIEWPORT })
  const page = await context.newPage()

  for (const theme of ['light', 'dark']) {
    await gotoThemed(page, '/manage-requests', theme)

    // Default selection is the first (Pending) request in the list.
    await page.getByRole('button', { name: 'Disapprove' }).click({ timeout: T })
    await page.getByRole('button', { name: 'Disapprove request' }).waitFor({ timeout: T })
    await snap(page, `resident-requests--reason-dialog--${theme}`)
    await cancelDialog(page)

    // A Responding-status row, for the Resolve confirmation. Matched via the
    // row's compound accessible name ("Name, Service, Responding") — plain
    // text "Responding" also matches the status-filter chip above the list.
    await page.getByRole('button', { name: /, Responding$/ }).first().click({ timeout: T })
    await page.getByRole('button', { name: 'Mark as Resolved' }).click({ timeout: T })
    await snap(page, `resident-requests--resolve-dialog--${theme}`)
    await cancelDialog(page)

    if (theme === 'light') {
      // createDialog is persistent — Escape and backdrop click are both
      // disabled, so it has to be closed via its own X button.
      await page.getByRole('button', { name: 'Log Service Request' }).click({ timeout: T })
      await snap(page, 'resident-requests--create-dialog')
      await closeDialogByLabel(page, 'Close')

      await page.getByRole('button', { name: /, Pending$/ }).first().click({ timeout: T })
      const selectVehicle = page.getByRole('button', { name: /Select Vehicle|Change Vehicle/ })
      if (await selectVehicle.isVisible().catch(() => false)) {
        await selectVehicle.click({ timeout: T })
        await snap(page, 'resident-requests--vehicle-modal')
        await page.keyboard.press('Escape').catch(() => {})
        await page.waitForTimeout(200)
      }

      const attachment = page.locator('img[alt="Valid ID attached by the Head of the Family"]')
      if (await attachment.isVisible().catch(() => false)) {
        await attachment.click({ timeout: T })
        await snap(page, 'resident-requests--lightbox')
        await page.keyboard.press('Escape').catch(() => {})
        await page.waitForTimeout(200)
      }

      await page.getByPlaceholder('Search by name, service, barangay...').fill('zzzznonexistentzzzz')
      await snap(page, 'resident-requests--empty')
    }
  }

  await context.close()
})

test('ambulance dispatch states', async ({ browser }) => {
  test.setTimeout(120_000)
  const context = await browser.newContext({ storageState: AUTH_FILE, viewport: VIEWPORT })
  const page = await context.newPage()

  for (const theme of ['light', 'dark']) {
    await gotoThemed(page, '/conduction-requests', theme)

    await page.getByRole('button', { name: 'Ambulance Day View' }).click({ timeout: T })
    await page.locator('.day-view-scale-label').first().waitFor({ timeout: T }).catch(() => {})
    await snap(page, `ambulance-dispatch--bookings-day-view--${theme}`)
    await page.keyboard.press('Escape').catch(() => {})
    await page.waitForTimeout(200)

    await page.getByRole('tab', { name: 'Trip Logs' }).click({ timeout: T })
    await page.waitForTimeout(300)

    if (theme === 'light') {
      // Also persistent — same X-button-only close as Resident Requests'.
      await page.getByRole('button', { name: 'Ambulance Trip Record' }).click({ timeout: T })
      await snap(page, 'ambulance-dispatch--triplogs-create-dialog')
      await closeDialogByLabel(page, 'Close')

      const firstRow = page.locator('table tbody tr').first()
      if (await firstRow.isVisible().catch(() => false)) {
        await firstRow.click({ timeout: T })
        await snap(page, 'ambulance-dispatch--triplogs-detail-dialog')
        await page.keyboard.press('Escape').catch(() => {})
        await page.waitForTimeout(200)
      }
    }
  }

  // Load-failure state. activeTab resets to 'bookings' on every full
  // navigation, so the route intercept must be armed BEFORE gotoThemed's
  // reload, and the Trip Logs tab clicked AFTER it — clicking beforehand
  // just gets wiped by the reload that follows.
  for (const theme of ['light', 'dark']) {
    await page.unroute('**/api/conduction-requests').catch(() => {})
    await page.route('**/api/conduction-requests', route => route.fulfill({ status: 500, body: '{"message":"Simulated failure for the UI audit"}' }))
    await gotoThemed(page, '/conduction-requests', theme)
    await page.getByRole('tab', { name: 'Trip Logs' }).click({ timeout: T })
    await page.getByText('Could not load ambulance trip records').first().waitFor({ timeout: T })
    await snap(page, `ambulance-dispatch--triplogs-error-state--${theme}`)
    await page.unroute('**/api/conduction-requests').catch(() => {})
  }

  await context.close()
})

test('equipment borrowing states', async ({ browser }) => {
  test.setTimeout(120_000)
  const context = await browser.newContext({ storageState: AUTH_FILE, viewport: VIEWPORT })
  const page = await context.newPage()

  for (const theme of ['light', 'dark']) {
    await gotoThemed(page, '/borrowings', theme)

    const approveBtn = page.getByRole('button', { name: /^Approve /, exact: false }).first()
    if (await approveBtn.isVisible().catch(() => false)) {
      await approveBtn.click({ timeout: T })
      await snap(page, `equipment-borrowing--action-dialog--${theme}`)
      await cancelDialog(page)
    }

    if (theme === 'light') {
      // Record dialog is persistent — close via its own X button.
      const firstRow = page.locator('table tbody tr').first()
      if (await firstRow.isVisible().catch(() => false)) {
        await firstRow.click({ timeout: T })
        await snap(page, 'equipment-borrowing--record-dialog')
        await closeDialogByLabel(page, 'Close details')
      }

      await page.getByPlaceholder('Resident, item or purpose').fill('zzzznonexistentzzzz')
      await snap(page, 'equipment-borrowing--empty')
      await page.getByPlaceholder('Resident, item or purpose').fill('')
    }
  }

  for (const theme of ['light', 'dark']) {
    await page.unroute('**/api/borrowings').catch(() => {})
    await gotoThemed(page, '/borrowings', theme)
    await page.route('**/api/borrowings', route => route.fulfill({ status: 500, body: '{"message":"Simulated failure for the UI audit"}' }))
    await page.reload()
    await page.getByText('Could not load borrowings').first().waitFor({ timeout: T })
    await snap(page, `equipment-borrowing--error-state--${theme}`)
    await page.unroute('**/api/borrowings').catch(() => {})
  }

  await context.close()
})

test('vehicles states', async ({ browser }) => {
  test.setTimeout(120_000)
  const context = await browser.newContext({ storageState: AUTH_FILE, viewport: VIEWPORT })
  const page = await context.newPage()

  for (const theme of ['light', 'dark']) {
    await gotoThemed(page, '/vehicles', theme)

    await page.getByRole('button', { name: 'Add Unit' }).click({ timeout: T })
    if (theme === 'light') {
      await snap(page, 'vehicles--add-dialog')
    }
    await page.getByRole('button', { name: 'Add unit', exact: true }).click({ timeout: T })
    await snap(page, `vehicles--add-validation-error--${theme}`)
    await cancelDialog(page)

    if (theme === 'light') {
      await page.getByRole('button', { name: /^Delete /, exact: false }).first().click({ timeout: T })
      await snap(page, 'vehicles--delete-dialog')
      await cancelDialog(page)
    }
  }

  await context.close()
})

test('residents states', async ({ browser }) => {
  test.setTimeout(120_000)
  const context = await browser.newContext({ storageState: AUTH_FILE, viewport: VIEWPORT })
  const page = await context.newPage()

  for (const theme of ['light', 'dark']) {
    await gotoThemed(page, '/users', theme)

    // Create dialog is persistent — closed via its own X button below.
    await page.getByRole('button', { name: 'Add Head of the Family' }).click({ timeout: T })
    await page.getByRole('button', { name: 'Create Account' }).click({ timeout: T })
    await snap(page, `residents--create-validation-error--${theme}`)
    await closeDialogByLabel(page, 'Close dialog')

    if (theme === 'light') {
      await page.locator('table tbody tr').first().click({ timeout: T })
      await snap(page, 'residents--detail-drawer')

      await page.getByRole('button', { name: 'Delete account' }).click({ timeout: T })
      await snap(page, 'residents--delete-dialog')
      await cancelDialog(page)
    }

    // Deactivate/activate has no confirmation dialog at all (unlike Staff's
    // "Close this account?") — toggle and revert immediately so the seed
    // data ends up exactly as it started.
    await page.locator('table tbody tr').first().click({ timeout: T })
    const toggleBtn = page.getByRole('button', { name: /Deactivate account|Activate account/ })
    await toggleBtn.waitFor({ timeout: T })
    const firstLabel = await toggleBtn.innerText()
    await toggleBtn.click({ timeout: T })
    await page.getByText(firstLabel === 'Deactivate account' ? 'Activate account' : 'Deactivate account').first().waitFor({ timeout: T }).catch(() => {})
    await snap(page, `residents--deactivate-toggle--${theme}`)
    // Revert to the original status.
    await page.locator('table tbody tr').first().click({ timeout: T })
    const revertBtn = page.getByRole('button', { name: /Deactivate account|Activate account/ })
    await revertBtn.waitFor({ timeout: T })
    if ((await revertBtn.innerText()) !== firstLabel) {
      await revertBtn.click({ timeout: T })
      await page.waitForTimeout(500)
    }
  }

  await context.close()
})

test('staff accounts states', async ({ browser }) => {
  test.setTimeout(60_000)
  const context = await browser.newContext({ storageState: AUTH_FILE, viewport: VIEWPORT })
  const page = await context.newPage()

  await gotoThemed(page, '/staff', 'light')
  const closeButtons = page.getByRole('button', { name: /^Close the account of / })
  const count = await closeButtons.count()
  for (let i = 0; i < count; i++) {
    const btn = closeButtons.nth(i)
    if (await btn.isEnabled()) {
      await btn.click({ timeout: T })
      await snap(page, 'staff-accounts--close-dialog')
      await cancelDialog(page)
      break
    }
  }

  await context.close()
})

test('documents states', async ({ browser }) => {
  test.setTimeout(60_000)
  const context = await browser.newContext({ storageState: AUTH_FILE, viewport: VIEWPORT })
  const page = await context.newPage()

  await gotoThemed(page, '/files', 'light')

  const tinyPngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
  const tmpFile = path.join(__dirname, '.tmp-audit-upload.png')
  fs.writeFileSync(tmpFile, Buffer.from(tinyPngBase64, 'base64'))
  await page.locator('input[type="file"]').setInputFiles(tmpFile)
  await snap(page, 'documents--upload-staged')
  await page.getByRole('button', { name: 'close', exact: true }).click({ timeout: T }).catch(() => {})
  fs.unlinkSync(tmpFile)

  const deleteBtn = page.getByRole('button', { name: /^Delete / }).first()
  if (await deleteBtn.isVisible().catch(() => false)) {
    await deleteBtn.click({ timeout: T })
    await snap(page, 'documents--delete-dialog')
    await cancelDialog(page)
  }

  await context.close()
})

test('text blast sms states', async ({ browser }) => {
  test.setTimeout(60_000)
  const context = await browser.newContext({ storageState: AUTH_FILE, viewport: VIEWPORT })
  const page = await context.newPage()

  for (const theme of ['light', 'dark']) {
    await gotoThemed(page, '/sms', theme)

    // Send Blast is :disabled="!isValid" — it can never be clicked while
    // invalid, so the blank-submit banner every other form in this app has
    // is not reachable here at all. The closest equivalent is blurring the
    // required fields to surface their own inline rule text.
    await page.getByPlaceholder(/MDRRMO Alert/).click({ timeout: T })
    await page.locator('body').click({ position: { x: 10, y: 10 }, timeout: T })
    await snap(page, `text-blast-sms--validation-error--${theme}`)

    await page.getByPlaceholder(/MDRRMO Alert/).fill('x'.repeat(161))
    await snap(page, `text-blast-sms--char-limit-error--${theme}`)
  }

  await context.close()
})

test('login invalid credentials', async ({ browser }) => {
  test.setTimeout(60_000)
  const context = await browser.newContext({ viewport: VIEWPORT })
  const page = await context.newPage()

  for (const theme of ['light', 'dark']) {
    await page.goto('/login')
    await page.evaluate(t => localStorage.setItem('serbis_theme', t), theme)
    await page.reload()
    await page.getByPlaceholder('Example@serbis.com').fill('admin@serbis.com')
    await page.getByPlaceholder('********').fill('definitely-wrong-password')
    await page.getByRole('button', { name: 'SIGN IN' }).click({ timeout: T })
    await page.getByRole('alert').waitFor({ timeout: T })
    await snap(page, `login--invalid-credentials--${theme}`)
  }

  await context.close()
})

test('staff accounts validation banner', async ({ browser }) => {
  test.setTimeout(60_000)
  const context = await browser.newContext({ storageState: AUTH_FILE, viewport: VIEWPORT })
  const page = await context.newPage()

  await gotoThemed(page, '/staff', 'light')
  await page.getByRole('button', { name: 'Add staff account' }).click({ timeout: T })
  await page.getByRole('button', { name: 'Create account' }).click({ timeout: T })
  await snap(page, 'staff-accounts--add-validation-error')
  await closeDialogByLabel(page, 'Close')

  await context.close()
})
