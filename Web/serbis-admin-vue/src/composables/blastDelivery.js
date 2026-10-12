// What Recent blasts and its detail panel share: the delivery states, how a
// status word maps to a StatusPill accent, and the timestamp format.
//
// Pending and Queued are not delivery: the API keeps them apart from Sent, and
// so does everything that reads this list.

// `pill` is the StatusPill accent each count is tinted with.
export const DELIVERY_STATES = [
  { key: 'queued', label: 'Queued', pill: 'Queued' },
  { key: 'pending', label: 'Pending', pill: 'Pending' },
  { key: 'sent', label: 'Sent', pill: 'Sent' },
  { key: 'failed', label: 'Failed', pill: 'Failed' },
  { key: 'unconfirmed', label: 'Unconfirmed', pill: 'Pending' },
  { key: 'other', label: 'Other status', pill: 'Pending' },
]

// The accent for a status word as the API sends it (a recipient's status).
export const pillFor = (status) =>
  DELIVERY_STATES.find(state => state.label === status || state.key === String(status).toLowerCase())?.pill ?? 'Pending'

// "Sep 21, 2026, 6:11 AM"
export const formatWhen = (value) => (value
  ? new Date(value).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
  : '')

// Shorter, for a list row: "Sep 21, 6:11 AM"
export const formatShort = (value) => (value
  ? new Date(value).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
  : '')
