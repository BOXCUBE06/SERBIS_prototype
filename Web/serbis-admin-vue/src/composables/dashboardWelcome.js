// The dashboard's welcome sentence, built from counts the page already loaded.
// Pure: returns parts, the view decides how to draw a count.

// Total open workload at which the wording changes. Tune here.
export const WORKLOAD = { busy: 11, heavy: 31 }

// Priority order; only the first three non-zero ones are mentioned.
// tone colours the count: error = red, warning = amber, default = plain.
const ITEMS = [
  { key: 'trips', one: 'ongoing ambulance trip', many: 'ongoing ambulance trips', tone: 'default' },
  { key: 'overdue', one: 'overdue loan', many: 'overdue loans', tone: 'error' },
  { key: 'ambulance', one: 'pending ambulance request', many: 'pending ambulance requests', tone: 'warning' },
  { key: 'bookings', one: 'pending booking', many: 'pending bookings', tone: 'warning' },
  { key: 'services', one: 'service request', many: 'service requests', tone: 'default' },
  { key: 'borrowing', one: 'borrow request', many: 'borrow requests', tone: 'default' },
]
const MAX_ITEMS = 3

// Ongoing trips are work in motion, not work waiting, so they stay out of the total.
const WORKLOAD_KEYS = ['services', 'ambulance', 'bookings', 'borrowing', 'overdue']

const LEADS = [
  [WORKLOAD.heavy, 'Heavy backlog: ', '. Start with the oldest.'],
  [WORKLOAD.busy, 'Busy shift: ', '.'],
  [1, 'You have ', '.'],
]

// "a", "a and b", "a, b, and c"
const joinParts = (phrases) => phrases.flatMap((p, i) => {
  if (i === 0) {
    return p
  }
  const last = i === phrases.length - 1
  const glue = last ? (phrases.length === 2 ? ' and ' : ', and ') : ', '
  return [{ text: glue }, ...p]
})

/** counts: { trips, overdue, ambulance, bookings, services, borrowing } -> [{ text, tone? }] */
export const welcomeSentence = (counts) => {
  const total = WORKLOAD_KEYS.reduce((sum, k) => sum + (counts[k] || 0), 0)
  if (total === 0) {
    return [{ text: 'No open requests. Everything is handled.' }]
  }

  const phrases = ITEMS.filter((i) => counts[i.key] > 0).slice(0, MAX_ITEMS).map((i) => [
    { text: String(counts[i.key]), tone: i.tone },
    { text: ` ${counts[i.key] === 1 ? i.one : i.many}` },
  ])
  const [, lead, tail] = LEADS.find(([min]) => total >= min)
  return [{ text: lead }, ...joinParts(phrases), { text: tail }]
}

export const sentenceText = (parts) => parts.map((p) => p.text).join('')
