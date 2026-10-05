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
import { BORROWING_STATUSES } from './borrowingStatus.ts'

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

// Vehicles, Responders and Resource Management share one map: available is
// green, out on a job or running low is amber, off the roster or in the shop
// is neutral. Red is kept for what blocks a request (an item with none left).
const RESOURCE_ACCENTS: Record<string, string> = {
  Available: '#297A67',
  Dispatched: accentOf('Pending'),
  Deployed: accentOf('Pending'),
  'Low stock': accentOf('Pending'),
  Maintenance: accentOf('Cancelled'),
  Depleted: accentOf('Denied'),
  Unavailable: accentOf('Denied'),
  'Off duty': accentOf('Cancelled'),
  // Accounts: a signed-up account is green, one switched off or opted out of
  // texts is neutral. (Pending is the amber the request statuses already have.)
  Active: '#297A67',
  Deactivated: accentOf('Cancelled'),
  'Opted out': accentOf('Cancelled'),
  // The account-type chip in Accounts: a label, not a state, so neutral.
  'Head of the Family': accentOf('Cancelled'),
  Barangay: accentOf('Cancelled'),
  Organization: accentOf('Cancelled'),
  // The profile dialog: SMS blasts on, and how a returned item came back.
  Receiving: '#297A67',
  Good: '#297A67',
  Bad: accentOf('Pending'),
  // Text blast delivery counts (Pending is the amber above).
  Queued: accentOf('Cancelled'),
  Sent: '#297A67',
  Failed: accentOf('Denied'),
  // Activity log actions (the server's own words, capitalised).
  Created: '#297A67',
  Updated: accentOf('Approved'),
  Deleted: accentOf('Denied'),
  Login: '#5B21B6',
  Exported: '#0F766E',
  Printed: '#0F766E',
}

const ALL_ACCENTS: Record<string, string> = { ...SERVICE_REQUEST_ACCENTS, ...BORROWING_ACCENTS, ...RESOURCE_ACCENTS }

export function pillAccent(status?: string | null): string {
  return ALL_ACCENTS[status || ''] || '#64748B'
}

// Status-filter tab values (SegmentedTabs) and the pill key whose accent the
// selected tab takes, so a tab always matches the pills in the table under it.
// A whitelist, not every accent key: "All", and non-status tabs that happen to
// share a word with the table (account types), keep the primary style.
const TAB_PILL_KEYS: Record<string, string> = {
  Pending: 'Pending',
  Booked: 'Booked',
  Responding: 'Responding',
  Resolved: 'Resolved',
  Disapproved: 'Disapproved',
  Cancelled: 'Cancelled',
  Approved: 'Approved',
  Released: 'Released',
  Returned: 'Returned',
  Denied: 'Denied',
  // Trip logs filter on the raw trip_status; its pill shows the shared status.
  'Not dispatched': 'Booked',
  'In transit': 'Responding',
  Completed: 'Resolved',
  'No arrival': 'Resolved — no arrival',
  // Vehicles, Responders (snake_case values) and Services.
  Available: 'Available',
  Dispatched: 'Dispatched',
  Maintenance: 'Maintenance',
  available: 'Available',
  deployed: 'Deployed',
  off_duty: 'Off duty',
  Active: 'Active',
  Disabled: 'Deactivated',
}

/**
 * The selected tab's accent for a status-filter value, or null for "All" and
 * any non-status tab. Overdue is not a pill (an overdue loan still shows
 * Released); it takes the theme's error red, as the due date and the
 * dashboard's Overdue tile already do.
 */
export function tabAccent(value?: string | number | null): string | null {
  if (value === 'Overdue') { return 'rgb(var(--v-theme-error))' }
  const key = TAB_PILL_KEYS[String(value ?? '')]
  return key ? pillAccent(key) : null
}
