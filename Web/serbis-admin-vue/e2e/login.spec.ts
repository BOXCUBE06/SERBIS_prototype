import { expect, test } from '@playwright/test'

test('login page renders the sign-in form', async ({ page }) => {
  await page.goto('/login')

  await expect(page.getByRole('heading', { name: 'SERBIS' })).toBeVisible()
  await expect(page.getByPlaceholder('Example@serbis.com')).toBeVisible()
  await expect(page.getByRole('button', { name: 'SIGN IN' })).toBeVisible()
})
