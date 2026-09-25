// The dashboard's welcome sentence, built from counts the page already loaded.
// Pure: returns parts, the view decides how to draw a count.

// Fixed order, always all three. tone colours a non-zero count:
// error = red, warning = amber, default = plain.
const ITEMS = [
  { key: 'trips', one: 'ongoing ambulance trip', many: 'ongoing ambulance trips', tone: 'default' },
  { key: 'overdue', one: 'overdue loan', many: 'overdue loans', tone: 'error' },
  { key: 'ambulance', one: 'pending ambulance request', many: 'pending ambulance requests', tone: 'warning' },
]

/** counts: { trips, overdue, ambulance } -> [{ text, tone? }] */
export const welcomeSentence = (counts) => {
  const n = (i) => counts[i.key] || 0
  if (ITEMS.every((i) => n(i) === 0)) {
    return [{ text: `There are currently no ${ITEMS.map((i) => i.many).join(', ').replace(/, ([^,]+)$/, ', or $1')}.` }]
  }

  const phrases = ITEMS.map((i) => (n(i) > 0
    ? [{ text: String(n(i)), tone: i.tone }, { text: ` ${n(i) === 1 ? i.one : i.many}` }]
    : [{ text: `no ${i.many}` }]))
  const [first, second, last] = phrases
  return [{ text: 'There are currently ' }, ...first, { text: ', ' }, ...second, { text: ', and ' }, ...last, { text: '.' }]
}

export const sentenceText = (parts) => parts.map((p) => p.text).join('')
