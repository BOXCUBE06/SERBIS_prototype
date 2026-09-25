// Run: npm test
import test from 'node:test'
import assert from 'node:assert/strict'
import { welcomeSentence, sentenceText, WORKLOAD } from './dashboardWelcome.js'

const say = (counts) => sentenceText(welcomeSentence(counts))

test('nothing open', () => {
  assert.equal(say({}), 'No open requests. Everything is handled.')
  // trips alone are not workload
  assert.equal(say({ trips: 2 }), 'No open requests. Everything is handled.')
})

test('one item, singular and plural', () => {
  assert.equal(say({ services: 1 }), 'You have 1 service request.')
  assert.equal(say({ overdue: 2 }), 'You have 2 overdue loans.')
})

test('two items join with "and"', () => {
  assert.equal(say({ overdue: 1, services: 2 }), 'You have 1 overdue loan and 2 service requests.')
})

test('three or more: priority order, max three, Oxford comma', () => {
  assert.equal(
    say({ trips: 1, overdue: 2, ambulance: 1, bookings: 1, services: 1 }),
    'You have 1 ongoing ambulance trip, 2 overdue loans, and 1 pending ambulance request.',
  )
  assert.equal(
    say({ overdue: 2, bookings: 3, services: 3 }),
    'You have 2 overdue loans, 3 pending bookings, and 3 service requests.',
  )
})

test('wording thresholds', () => {
  const at = (n) => say({ services: n })
  assert.equal(at(WORKLOAD.busy - 1), `You have ${WORKLOAD.busy - 1} service requests.`)
  assert.match(at(WORKLOAD.busy), /^Busy shift: /)
  assert.match(at(WORKLOAD.heavy - 1), /^Busy shift: /)
  assert.equal(at(WORKLOAD.heavy), `Heavy backlog: ${WORKLOAD.heavy} service requests. Start with the oldest.`)
})

test('counts carry a tone, connecting words do not', () => {
  const parts = welcomeSentence({ overdue: 5, services: 24 })
  assert.deepEqual(parts.filter((p) => p.tone).map((p) => [p.text, p.tone]), [['5', 'error'], ['24', 'default']])
})
