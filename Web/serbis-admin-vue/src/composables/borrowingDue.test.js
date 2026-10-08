import test from 'node:test'
import assert from 'node:assert/strict'
import { statusNote } from './borrowingDue.ts'

const today = new Date(2026, 9, 8, 15, 0) // Oct 8, mid-afternoon local time
const released = (due) => ({ status: 'Released', due_date: due, created_at: '2026-09-20T00:00:00Z' })

test('a Released loan due today reads amber "Due today"', () => {
  assert.deepEqual(statusNote(released('2026-10-08'), today), { text: 'Due today', class: 'text-warning-strong' })
})

test('a Released loan due tomorrow reads amber "Due tomorrow"', () => {
  assert.deepEqual(statusNote(released('2026-10-09'), today), { text: 'Due tomorrow', class: 'text-warning-strong' })
})

test('a Released loan past its date reads red "N days overdue"', () => {
  assert.deepEqual(statusNote(released('2026-10-05'), today), { text: '3 days overdue', class: 'text-error font-weight-bold' })
})

test('everything else stays empty', () => {
  assert.equal(statusNote(released('2026-10-12'), today), null)
  assert.equal(statusNote({ status: 'Returned', due_date: '2026-10-01' }, today), null)
  assert.equal(statusNote({ status: 'Approved', due_date: '2026-10-08', created_at: new Date(2026, 9, 7).toISOString() }, today), null)
})
