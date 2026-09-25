// Run: npm test
import test from 'node:test'
import assert from 'node:assert/strict'
import { welcomeSentence, sentenceText } from './dashboardWelcome.js'

const say = (counts) => sentenceText(welcomeSentence(counts))

test('all zero', () => {
  assert.equal(say({}), 'There are currently no ongoing ambulance trips, overdue loans, or pending ambulance requests.')
})

test('zero items read "no", non-zero keep their count', () => {
  assert.equal(say({ overdue: 2 }), 'There are currently no ongoing ambulance trips, 2 overdue loans, and no pending ambulance requests.')
})

test('singular and plural', () => {
  assert.equal(
    say({ trips: 1, overdue: 1, ambulance: 1 }),
    'There are currently 1 ongoing ambulance trip, 1 overdue loan, and 1 pending ambulance request.',
  )
})

test('counts carry a tone, zeros and connecting words do not', () => {
  const parts = welcomeSentence({ overdue: 5, ambulance: 24 })
  assert.deepEqual(parts.filter((p) => p.tone).map((p) => [p.text, p.tone]), [['5', 'error'], ['24', 'warning']])
})
