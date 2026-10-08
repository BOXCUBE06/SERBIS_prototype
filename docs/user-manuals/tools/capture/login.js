// Admin panel sign-in through the UI, with the code step. The capture API runs
// with fake SMS and the local-only OTP bypass, so the code is SERBIS_OTP_BYPASS_CODE
// (555555 in the local Backend .env). The session is saved as storageState.
const fs = require('fs')
const path = require('path')
const { ADMIN_URL, STATE } = require('./common')

const CODE = process.env.SERBIS_CAPTURE_CODE || '555555'
const VIEWPORT = { width: 1280, height: 800 }

function statePath(username) {
  return path.join(STATE, `admin-${username}.json`)
}

// hooks.onPassword(page) runs on the filled-in login form; hooks.onCode(page)
// on the code step. Either may take a screenshot.
async function adminLogin(browser, username, password, hooks = {}) {
  const context = await browser.newContext({ viewport: VIEWPORT, deviceScaleFactor: 2 })
  const page = await context.newPage()
  await page.goto(`${ADMIN_URL}/login`)
  await page.locator('#login-username').fill(username)
  await page.locator('#login-password').fill(password)
  if (hooks.onPassword) await hooks.onPassword(page)
  await page.locator('button[type=submit]').first().click()
  const otp = page.locator('.v-otp-input input:not(.v-otp-input__spacer)').first()
  await otp.waitFor({ timeout: 10000 })
  await otp.focus()
  if (hooks.onCode) await hooks.onCode(page)
  await otp.focus()
  await page.keyboard.type(CODE, { delay: 40 })
  // The panel submits by itself once six digits are in; click only if it did not.
  const verify = page.getByRole('button', { name: 'Verify' })
  if (await verify.isVisible().catch(() => false)) await verify.click().catch(() => {})
  await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 10000 })
  await context.storageState({ path: statePath(username) })
  return { context, page }
}

// Reuses a saved session when it still opens the dashboard; signs in otherwise.
async function adminSession(browser, username, password) {
  const file = statePath(username)
  if (fs.existsSync(file)) {
    const context = await browser.newContext({ viewport: VIEWPORT, deviceScaleFactor: 2, storageState: file })
    const page = await context.newPage()
    await page.goto(`${ADMIN_URL}/`)
    await page.waitForTimeout(1000)
    if (!new URL(page.url()).pathname.startsWith('/login')) return { context, page }
    await context.close()
  }
  return adminLogin(browser, username, password)
}

module.exports = { adminLogin, adminSession, VIEWPORT, CODE }
