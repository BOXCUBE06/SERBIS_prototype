import test from 'node:test'
import assert from 'node:assert/strict'
import { submittedAt, timeAgo, waitNote } from './submittedTime.ts'

const now = Date.parse('2026-10-04T01:27:00Z') // Oct 4, 9:27 AM in Manila

test('the first line is the Manila date and time', () => {
  assert.equal(submittedAt('2026-10-03T09:27:00Z'), 'Oct 3, 5:27 PM')
  assert.equal(submittedAt(null), '')
})

test('the second line counts minutes, then hours, then days', () => {
  assert.equal(timeAgo('2026-10-04T01:26:40Z', now), 'just now')
  assert.equal(timeAgo('2026-10-04T01:26:00Z', now), '1 minute ago')
  assert.equal(timeAgo('2026-10-03T17:27:00Z', now), '8 hours ago')
  assert.equal(timeAgo('2026-10-03T01:27:00Z', now), '1 day ago')
  assert.equal(timeAgo('2026-09-29T01:27:00Z', now), '5 days ago')
})

test('only a Pending row older than two days gets a wait note', () => {
  assert.equal(waitNote('Pending', '2026-10-02T01:27:00Z', now), '')
  assert.equal(waitNote('Pending', '2026-10-01T01:27:00Z', now), 'Waiting 3d')
  assert.equal(waitNote(null, '2026-09-24T01:27:00Z', now), 'Waiting 10d')
  assert.equal(waitNote('Approved', '2026-09-24T01:27:00Z', now), '')
})
