import { ref } from 'vue'

import { authHeaders } from './adminUi'
import { ADMIN_SECTIONS } from './adminSections'
import { getToken } from './authToken'
import { API_BASE } from '../config/api'

/**
 * Which sections the signed-in admin may open, read from `/me`.
 *
 * Nothing about the account is stored client-side (authToken.ts keeps only the
 * token), so this is fetched once per session and shared by the menu, the
 * router guard and the pages. It only decides what to draw: the server refuses
 * a section the account does not hold whatever the panel shows, so being wrong
 * here means a menu item that leads to a refusal, never access.
 *
 * `null` means "not known yet", and is treated as allowed. A failed `/me` must
 * not blank the whole menu; the server is still the one saying no.
 */
const sections = ref<string[] | null>(null)
const isSuperAdmin = ref(false)

/** The token the state above was read for, so a new sign-in never inherits the last one's menu. */
let loadedFor: string | null = null
let inFlight: Promise<void> | null = null

export function resetCurrentAdmin(): void {
  sections.value = null
  isSuperAdmin.value = false
  loadedFor = null
}

export async function loadCurrentAdmin(force = false): Promise<void> {
  const token = getToken()

  if (!token) {
    resetCurrentAdmin()
    return
  }

  if (loadedFor !== null && loadedFor !== token) resetCurrentAdmin()
  if (!force && loadedFor === token && sections.value !== null) return
  if (inFlight) return inFlight

  inFlight = (async () => {
    try {
      const res = await fetch(`${API_BASE}/me`, { headers: authHeaders() })
      if (!res.ok) return

      const data = await res.json()
      sections.value = Array.isArray(data.sections) ? data.sections : null
      isSuperAdmin.value = !!data.user?.is_super_admin
      loadedFor = token
    } catch {
      // Unreachable server: leave the state as it was. Every page has its own
      // error for that, and the menu staying put is the less confusing one.
    } finally {
      inFlight = null
    }
  })()

  return inFlight
}

export function can(section: string): boolean {
  const meta = ADMIN_SECTIONS.find((s) => s.key === section)

  // A super-admin-only section is never assumed: an unknown account is not
  // shown Staff Accounts on the strength of a request that has not answered.
  if (meta?.superAdminOnly) return sections.value?.includes(section) === true

  return sections.value === null || sections.value.includes(section)
}

/** Where to send someone who asked for a page they may not open: the first one they may. */
export function firstAllowedPath(): string | null {
  return ADMIN_SECTIONS.find((s) => can(s.key))?.to ?? null
}

export function useCurrentAdmin() {
  return { sections, isSuperAdmin, can, loadCurrentAdmin, firstAllowedPath }
}
