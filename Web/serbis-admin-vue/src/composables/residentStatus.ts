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

/**
 * `tbl_residents.sms_opt_in` — the resident's own decision about receiving
 * MDRRMO text blasts, which is not the same fact as `status`.
 *
 * `SmsController::sendBlast()` needs all three: an Active account, a phone
 * number, and this switch on. So the two columns answer different halves of
 * "why did that blast reach fewer numbers than I expected", and they are
 * deliberately not merged into one "will receive" column — an opted-in
 * resident whose account is deactivated is a different problem with a
 * different fix from one who opted out, and the office can only act on the
 * first.
 */
export function residentSmsOptIn(resident?: { sms_opt_in?: unknown } | null): boolean {
  const value = resident?.sms_opt_in

  // Absent reads as opted in, matching the column default and the mobile
  // client's AppUser.fromJson. Absent-as-false would print "Opted out" against
  // a resident the blast still reaches, and send an operator looking for a
  // choice nobody made. 0/'0' are handled because the boolean cast lives on
  // the model, not in the column.
  if (value === undefined || value === null) return true
  return value !== false && value !== 0 && value !== '0'
}

/**
 * Only the pill's own vocabulary. "Opted out" is stated in words, not carried
 * by the grey alone — the same rule the borrowing board's status chips follow.
 */
export function residentSmsLabel(optedIn: boolean): string {
  return optedIn ? 'Receiving' : 'Opted out'
}

/** Reuses the status pill classes; opting out is neutral, not an error. */
export function residentSmsPillClass(optedIn: boolean): string {
  return optedIn ? 'pill-active' : 'pill-inactive'
}

export function residentSmsDotClass(optedIn: boolean): string {
  return optedIn ? 'dot-active' : 'dot-inactive'
}
