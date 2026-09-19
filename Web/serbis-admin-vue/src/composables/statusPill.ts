/**
 * StatusPill now renders these as a light tint with darkened text (see
 * StatusPill.vue); the contrast notes below date from the solid fill.
 *
 * One accent-color table for every status pill in the admin
 * panel — the ServiceRequest family (Pending/Booked/Responding/Resolved/
 * Disapproved/Cancelled, the Resolved-no-arrival split, and Trip Logs' own
 * Awaiting departure/In transit/Completed labels from adminUi.ts's
 * tripStatusLabel()) plus the Equipment Borrowing family
 * (borrowingStatus.ts's BORROWING_STATUSES).
 *
 * Pending and Cancelled reuse BORROWING_STATUSES' own hex directly rather
 * than restating it — that file already hand-tuned both for AA contrast
 * under white text on a solid fill, which is the same rendering mode this
 * pill uses. Booked/Responding/Resolved/Disapproved have no Borrowing
 * equivalent to borrow from, so they're hand-picked here: Responding reuses
 * Approved's exact blue (#1D4ED8, already proven AA-safe white-on-solid in
 * EquipmentBorrowingView), Resolved reuses the primary/success green
 * (#297A67, proven 4.5:1+ white-on-primary in plugins/vuetify.ts),
 * Disapproved reuses Denied's red (#B91C1C), and Booked keeps the violet
 * this app already uses for it (#5B21B6, the darker of the two shades the
 * old tint pill used — the text color there, now the fill here — measures
 * ~9:1 for white text).
 */
import { BORROWING_STATUSES } from './borrowingStatus'

const accentOf = (status: string): string =>
  BORROWING_STATUSES.find((s) => s.status === status)?.accent || '#64748B'

const SERVICE_REQUEST_ACCENTS: Record<string, string> = {
  Pending: accentOf('Pending'),
  Booked: '#5B21B6',
  Responding: '#1D4ED8',
  Resolved: '#297A67',
  'Resolved — no arrival': accentOf('Cancelled'),
  Disapproved: accentOf('Denied'),
  Cancelled: accentOf('Cancelled'),
  // Trip Logs' own display labels (adminUi.ts's tripStatusLabel) — same
  // hues as the request statuses they mirror via sharedStatusLabel.
  'Awaiting departure': '#5B21B6',
  'In transit': '#1D4ED8',
  Completed: '#297A67',
}

const BORROWING_ACCENTS: Record<string, string> = Object.fromEntries(
  BORROWING_STATUSES.map((s) => [s.status, s.accent]),
)

const ALL_ACCENTS: Record<string, string> = { ...SERVICE_REQUEST_ACCENTS, ...BORROWING_ACCENTS }

export function pillAccent(status?: string | null): string {
  return ALL_ACCENTS[status || ''] || '#64748B'
}
