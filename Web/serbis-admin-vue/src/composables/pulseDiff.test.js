import test from 'node:test'
import assert from 'node:assert/strict'
import { pulseDiff } from './pulseDiff.ts'

const rows = [
  { updated_at: '2026-10-08T01:00:00.000000Z' },
  { updated_at: '2026-10-08T02:00:00.000000Z' },
]
const stamp = (count, latest) => ({ count, latest })

test('no pulse yet, or a pulse matching the rows, is no change', () => {
  assert.deepEqual(pulseDiff(undefined, rows), { changed: false, added: 0 })
  assert.deepEqual(pulseDiff(stamp(2, '2026-10-08T02:00:00.000000Z'), rows), { changed: false, added: 0 })
})

test('a request filed elsewhere is counted as new', () => {
  assert.deepEqual(pulseDiff(stamp(3, '2026-10-08T03:00:00.000000Z'), rows), { changed: true, added: 1 })
})

test('an edit elsewhere is a change with nothing added', () => {
  assert.deepEqual(pulseDiff(stamp(2, '2026-10-08T03:00:00.000000Z'), rows), { changed: true, added: 0 })
})

test('a delete elsewhere leaves the newest row and drops the count', () => {
  assert.deepEqual(pulseDiff(stamp(1, '2026-10-08T02:00:00.000000Z'), rows.slice(0, 2)), { changed: true, added: 0 })
})

test('a pulse taken before this page\'s own write is not a change', () => {
  const afterOwnWrite = [...rows, { updated_at: '2026-10-08T04:00:00.000000Z' }]
  assert.deepEqual(pulseDiff(stamp(2, '2026-10-08T02:00:00.000000Z'), afterOwnWrite), { changed: false, added: 0 })
})

test('an empty table and an empty server agree', () => {
  assert.deepEqual(pulseDiff(stamp(0, null), []), { changed: false, added: 0 })
  assert.deepEqual(pulseDiff(stamp(1, '2026-10-08T01:00:00.000000Z'), []), { changed: true, added: 1 })
})
