import { chromium, test } from '@playwright/test'
import { execSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

// Throwaway capture spec for the 2026-09 UI/UX audit (docs/ui-audit/). Not
// part of the regular suite — delete once the audit ships. Does not touch
// login.spec.ts or playwright.config.ts.

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const AUTH_FILE = path.join(__dirname, '.auth', 'admin.json')
const OUT_DIR = path.join(__dirname, '..', '..', '..', 'docs', 'ui-audit', 'screenshots')
const BACKEND_DIR = path.resolve(process.cwd(), '..', '..', 'Backend', 'SERBIS-Backend')
const ADMIN_EMAIL = 'admin@serbis.com'
const ADMIN_PASSWORD = 'password123'

const VIEWPORTS = [
  { name: '1920x1080', width: 1920, height: 1080 },
  { name: '1366x768', width: 1366, height: 768 },
]
const THEMES = ['light', 'dark'] as const

const ROUTES = [
  { slug: 'dashboard', path: '/' },
  { slug: 'resident-requests', path: '/manage-requests' },
  { slug: 'ambulance-dispatch', path: '/conduction-requests' },
  { slug: 'equipment-borrowing', path: '/borrowings' },
  { slug: 'vehicles', path: '/vehicles' },
  { slug: 'resource-management', path: '/inventory' },
  { slug: 'text-blast-sms', path: '/sms' },
  { slug: 'manage-services', path: '/services-config' },
  { slug: 'residents', path: '/users' },
  { slug: 'staff-accounts', path: '/staff' },
  { slug: 'documents', path: '/files' },
  { slug: 'activity-logs', path: '/logs' },
  { slug: '404', path: '/this-route-does-not-exist' },
]

// The admin's TOTP secret is never stored — it is derived from admin_id +
// APP_KEY (see Backend/SERBIS-Backend/app/Services/Totp.php). Piping the
// derivation through the backend's own tinker shell, rather than
// reimplementing it in JS, means this test can never drift from the real
// algorithm and never has to know APP_KEY itself.
function currentAdminTotpCode(): string {
  const php = [
    `$a = App\\Models\\User::where('email_address', '${ADMIN_EMAIL}')->firstOrFail();`,
    '$secret = app(App\\Services\\Totp::class)->secretFor($a->admin_id);',
    "echo 'OTP:' . (new PragmaRX\\Google2FA\\Google2FA())->getCurrentOtp($secret) . PHP_EOL;",
  ].join('\n')

  const output = execSync('php artisan tinker', { cwd: BACKEND_DIR, input: php, encoding: 'utf8' })
  const match = output.match(/OTP:(\d{6})/)
  if (!match) throw new Error(`Could not read a TOTP code from tinker output: ${output}`)
  return match[1]
}

test.beforeAll(async () => {
  test.setTimeout(120_000)
  fs.mkdirSync(path.dirname(AUTH_FILE), { recursive: true })
  fs.mkdirSync(OUT_DIR, { recursive: true })

  const browser = await chromium.launch()
  const context = await browser.newContext()
  const page = await context.newPage()

  await page.goto('/login')
  await page.getByPlaceholder('Example@serbis.com').fill(ADMIN_EMAIL)
  await page.getByPlaceholder('********').fill(ADMIN_PASSWORD)
  await page.getByRole('button', { name: 'SIGN IN' }).click()

  // MFA is currently disabled server-side (AuthController::adminLogin, since
  // 2026-08-30) so this normally goes straight to a token. The branch is kept
  // so this spec doesn't silently break the day MFA is turned back on.
  await Promise.race([
    page.waitForFunction(() => localStorage.getItem('serbis_token') !== null),
    page.getByText(/6-digit code/).waitFor(),
  ])

  if (await page.getByText(/6-digit code/).isVisible().catch(() => false)) {
    const otpBoxes = page.locator('.v-otp-input input')
    const code = currentAdminTotpCode()
    for (let i = 0; i < 6; i++) {
      await otpBoxes.nth(i).fill(code[i])
    }
    await page.getByRole('button', { name: 'VERIFY' }).click()
    await page.waitForFunction(() => localStorage.getItem('serbis_token') !== null)
  }

  await context.storageState({ path: AUTH_FILE })
  await browser.close()
})

test('capture every audited surface', async ({ browser }) => {
  test.setTimeout(15 * 60 * 1000)
  const context = await browser.newContext({ storageState: AUTH_FILE })
  const page = await context.newPage()

  const setTheme = async (theme: (typeof THEMES)[number]) => {
    await page.evaluate(t => localStorage.setItem('serbis_theme', t), theme)
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height })

    for (const theme of THEMES) {
      await page.goto('/')
      await setTheme(theme)

      for (const route of ROUTES) {
        await page.goto(route.path)
        await page.waitForLoadState('networkidle')
        await page.waitForTimeout(500)
        const file = `${route.slug}--${viewport.name}--${theme}.png`
        await page.screenshot({ path: path.join(OUT_DIR, file), fullPage: true })
      }

      // Notification menu: a dashboard bell dropdown, not its own route.
      await page.goto('/')
      await setTheme(theme)
      await page.reload()
      await page.getByRole('button', { name: 'System notifications' }).click()
      await page.getByText('System Logs').waitFor()
      await page.waitForTimeout(200)
      await page.screenshot({
        path: path.join(OUT_DIR, `dashboard-notification-menu--${viewport.name}--${theme}.png`),
        fullPage: true,
      })

      // "Profile/account settings" resolves to the sidebar profile card,
      // which opens a logout confirmation dialog — there is no dedicated
      // profile or settings page in this app (see inventory.md).
      await page.goto('/')
      await setTheme(theme)
      await page.reload()
      await page.locator('.profile-card').click()
      await page.getByText('Confirm Logout').waitFor()
      await page.waitForTimeout(200)
      await page.screenshot({
        path: path.join(OUT_DIR, `logout-confirm-dialog--${viewport.name}--${theme}.png`),
        fullPage: true,
      })
      await page.getByRole('button', { name: 'Cancel' }).click().catch(() => {})
    }
  }

  await context.close()
})

test('capture the unauthenticated login page', async ({ browser }) => {
  // Separate, storageState-free context on purpose: the router guard bounces
  // an authenticated session straight from /login to /, so this can't reuse
  // the logged-in context above.
  const context = await browser.newContext()
  const page = await context.newPage()

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height })

    for (const theme of THEMES) {
      await page.goto('/login')
      await page.evaluate(t => localStorage.setItem('serbis_theme', t), theme)
      await page.reload()
      await page.waitForLoadState('networkidle')
      await page.screenshot({
        path: path.join(OUT_DIR, `login--${viewport.name}--${theme}.png`),
        fullPage: true,
      })
    }
  }

  await context.close()
})
