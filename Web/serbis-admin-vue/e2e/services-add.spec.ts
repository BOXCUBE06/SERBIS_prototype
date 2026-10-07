import { expect, test, type Page } from '@playwright/test'

// The Add service dialog on Manage Services, against a mocked API: no backend
// or database needed. Covers the duplicate-name field error, the saved
// service's next steps, and who sees the button.

// Each test loads the lazy Services route on the dev server; with several
// browsers at once that alone can take most of the default 30s.
test.describe.configure({ timeout: 60_000 })

const ROAD = { service_id: 1, code: 'road-clearing', service_name: 'Road Clearing', description: null, category: 'infrastructure', is_active: true }
const TARP = { service_id: 2, code: 'tarpaulin-lending', service_name: 'Tarpaulin Lending', description: null, category: 'relief', is_active: false }

async function signIn(page: Page, sections: string[] | null) {
  await page.addInitScript(() => {
    localStorage.setItem('serbis_token', 'test-token')
    localStorage.setItem('serbis_token_expires_at', String(Date.now() + 3_600_000))
  })
  await page.route('**/api/me', (route) => route.fulfill({ json: { user: { admin_id: 1, first_name: 'Ana', is_super_admin: false }, sections } }))
}

async function mockServices(page: Page) {
  const list = [ROAD]
  const posts: unknown[] = []
  await page.route('**/api/services', async (route) => {
    if (route.request().method() !== 'POST') { return route.fulfill({ json: { data: list } }) }
    const body = route.request().postDataJSON()
    posts.push(body)
    if (body.service_name.toLowerCase().replace(/[^a-z0-9]+/g, '') === 'roadclearing') {
      return route.fulfill({ status: 422, json: { message: 'taken', errors: { service_name: ['A service with this name already exists: Road Clearing.'] } } })
    }
    list.push(TARP)
    return route.fulfill({ status: 201, json: TARP })
  })
  return posts
}

const dialog = (page: Page) => page.getByRole('dialog')

test('a duplicate name is shown on the field, then a new service is added switched off with its next steps', async ({ page }) => {
  await signIn(page, null)
  const posts = await mockServices(page)
  await page.goto('/services-config')

  await page.getByRole('button', { name: 'Add service' }).click()
  await expect(dialog(page).getByText('It is saved switched off')).toBeVisible()

  await dialog(page).getByLabel('Service name').fill('road  clearing')
  await dialog(page).locator('.v-select').click()
  await page.getByRole('option', { name: 'Infrastructure' }).click()
  await dialog(page).getByRole('button', { name: 'Add service' }).click()

  await expect(dialog(page).getByText('A service with this name already exists: Road Clearing.')).toBeVisible()
  await expect(dialog(page)).toBeVisible()

  await dialog(page).getByLabel('Service name').fill('Tarpaulin Lending')
  await dialog(page).getByLabel('Filipino name').fill('Pagpapahiram ng Trapal')
  await dialog(page).locator('.v-select').click()
  await expect(page.getByRole('option', { name: 'Programs' })).toHaveCount(0)
  await page.getByRole('option', { name: 'Relief' }).click()
  await dialog(page).getByRole('button', { name: 'Add service' }).click()

  await expect(dialog(page)).toBeHidden()
  expect(posts.at(-1)).toEqual({ service_name: 'Tarpaulin Lending', service_name_fil: 'Pagpapahiram ng Trapal', category: 'relief', description: null })

  const notice = page.locator('[data-test="service-added"]')
  await expect(notice).toContainText('Tarpaulin Lending was added, switched off.')
  await expect(notice.getByRole('link', { name: 'Open Service Audience' })).toHaveAttribute('href', '/service-audience')
  await expect(notice.getByRole('link', { name: 'Open Service Vehicles' })).toHaveAttribute('href', '/service-vehicles')
  await expect(page.getByRole('row', { name: /Tarpaulin Lending/ })).toContainText('Disabled')
})

test('without the audience and vehicle sections the steps name the pages instead of linking', async ({ page }) => {
  await signIn(page, ['services'])
  await mockServices(page)
  await page.goto('/services-config')

  await page.getByRole('button', { name: 'Add service' }).click()
  await dialog(page).getByLabel('Service name').fill('Tarpaulin Lending')
  await dialog(page).locator('.v-select').click()
  await page.getByRole('option', { name: 'Relief' }).click()
  await dialog(page).getByRole('button', { name: 'Add service' }).click()

  const notice = page.locator('[data-test="service-added"]')
  await expect(notice).toContainText('Ask someone with access to Service Audience.')
  await expect(notice.getByRole('link')).toHaveCount(0)
})

test('staff without the services section never reach the page or the button', async ({ page }) => {
  await signIn(page, ['dashboard'])
  await mockServices(page)
  await page.route('**/api/admin/dashboard**', (route) => route.fulfill({ json: {} }))
  await page.goto('/services-config')

  await expect(page).not.toHaveURL(/services-config/)
  await expect(page.getByRole('button', { name: 'Add service' })).toHaveCount(0)
})

test('the edit form saves the Filipino name with the description', async ({ page }) => {
  await signIn(page, null)
  await mockServices(page)
  let sent: unknown = null
  await page.route('**/api/services/1', (route) => {
    sent = route.request().postDataJSON()
    return route.fulfill({ json: { ...ROAD, service_name_fil: 'Paglilinis ng Daan' } })
  })
  await page.goto('/services-config')

  await page.getByRole('row', { name: /Road Clearing/ }).click()
  await dialog(page).getByLabel('Filipino name').fill('Paglilinis ng Daan')
  await dialog(page).getByRole('button', { name: 'Save changes' }).click()

  await expect.poll(() => sent).toEqual({ service_name_fil: 'Paglilinis ng Daan', description: null })
})
