/**
 * The helpers every admin view had written out for itself.
 *
 * Eleven views declared their own `getHeaders`, seven their own `notify`, four
 * their own `initials`. They were not quite identical, and that is the reason
 * this file exists rather than a style preference: `EquipmentBorrowingView`'s
 * `initials` omitted the `.toUpperCase()` the other three applied, so the same
 * resident's avatar read "MS" on one page and "mS" on another. A copy that
 * drifts is worse than a copy that does not, and nothing flags the drift.
 *
 * `initials()` is wired into ResidentDetailPanel, StaffView, UsersView and
 * EquipmentBorrowingView (audits/code-duplication.md Finding 6).
 * `authHeaders()` and `useSnackbar()` are not yet adopted anywhere — that
 * migration (Findings 4 and 9) is a separate, larger pass across 11 views.
 */
import { ref } from 'vue'
import type { Ref } from 'vue'
import { getToken } from '@/composables/authToken'

/**
 * Bearer headers for a JSON request.
 *
 * `json: false` drops `Content-Type`, which is what a multipart upload needs —
 * setting it by hand stops the browser from generating the multipart boundary.
 * `FilesView` is the caller that relies on this.
 */
export function authHeaders(json = true): Record<string, string> {
  const headers: Record<string, string> = {
    Authorization: `Bearer ${getToken()}`,
    Accept: 'application/json',
  }

  if (json) headers['Content-Type'] = 'application/json'

  return headers
}

export interface SnackbarState {
  show: boolean
  text: string
  color: string
}

/**
 * A snackbar ref plus the `notify` that writes to it, returned together so a
 * view cannot declare one without the other.
 */
export function useSnackbar(): { snackbar: Ref<SnackbarState>; notify: (text: string, color?: string) => void } {
  const snackbar = ref<SnackbarState>({ show: false, text: '', color: 'success' })

  const notify = (text: string, color = 'success'): void => {
    snackbar.value = { show: true, text, color }
  }

  return { snackbar, notify }
}

interface NamedPerson {
  first_name?: string | null
  last_name?: string | null
}

/**
 * Avatar initials. Uppercased — see the file header for why that is stated
 * rather than assumed.
 */
export function initials(person: NamedPerson | null | undefined): string {
  const first = (person?.first_name || '').charAt(0)
  const last = (person?.last_name || '').charAt(0)

  return `${first}${last}`.toUpperCase()
}

/**
 * Date only. Returns an em dash rather than "Invalid Date" for a null or
 * unparseable value, because these land directly in table cells.
 */
export function fmtDate(value: string | Date | null | undefined): string {
  if (!value) return '—'

  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return '—'

  return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
}

/** Date and time, same null handling as [fmtDate]. */
export function fmtDateTime(value: string | Date | null | undefined): string {
  if (!value) return '—'

  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return '—'

  return d.toLocaleString('en-PH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
}
