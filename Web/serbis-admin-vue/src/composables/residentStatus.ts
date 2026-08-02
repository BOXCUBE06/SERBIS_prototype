/**
 * The `tbl_residents.status` vocabulary.
 *
 * The column carries three values, not two. Self-registration writes
 * 'Inactive' (AuthController::register); the admin toggle writes
 * 'Deactivated'. Both are excluded from SMS blasts, but they mean opposite
 * things: 'Inactive' is waiting for an admin to switch it on, 'Deactivated'
 * was switched off by one.
 *
 * The Status filter used to offer only All / Active / Deactivated, so a
 * self-registered resident matched neither named option — and that is
 * precisely the account an admin has to find in order to activate it.
 *
 * The stored values stay exactly as the API sends them. Only the labels are
 * ours: 'Inactive' reads like a disabled account, which is the wrong story
 * for someone who has just signed up.
 */

export const RESIDENT_STATUS = {
  active: 'Active',
  pending: 'Inactive',
  deactivated: 'Deactivated',
} as const

export type ResidentStatus = (typeof RESIDENT_STATUS)[keyof typeof RESIDENT_STATUS]

const LABELS: Record<string, string> = {
  [RESIDENT_STATUS.active]: 'Active',
  [RESIDENT_STATUS.pending]: 'Pending',
  [RESIDENT_STATUS.deactivated]: 'Deactivated',
}

/** Falls back to the raw value so an unknown status is visible, not hidden. */
export function residentStatusLabel(status?: string | null): string {
  if (!status) return '—'
  return LABELS[status] ?? status
}

/** `title`/`value` pairs so the select can label a value it must not rewrite. */
export const RESIDENT_STATUS_FILTER_ITEMS = [
  { title: 'All', value: 'All' },
  { title: 'Active', value: RESIDENT_STATUS.active },
  { title: 'Pending', value: RESIDENT_STATUS.pending },
  { title: 'Deactivated', value: RESIDENT_STATUS.deactivated },
]

/**
 * Pending gets its own pill rather than sharing the grey one: an admin
 * scanning the list needs to tell "not activated yet" from "turned off"
 * without opening the row.
 */
export function residentStatusPillClass(status?: string | null): string {
  if (status === RESIDENT_STATUS.active) return 'pill-active'
  if (status === RESIDENT_STATUS.pending) return 'pill-pending'
  return 'pill-inactive'
}

export function residentStatusDotClass(status?: string | null): string {
  if (status === RESIDENT_STATUS.active) return 'dot-active'
  if (status === RESIDENT_STATUS.pending) return 'dot-pending'
  return 'dot-inactive'
}
