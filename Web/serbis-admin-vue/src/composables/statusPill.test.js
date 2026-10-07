import test from 'node:test'
import assert from 'node:assert/strict'
import { pillAccent, tabAccent } from './statusPill.ts'

test('a status tab takes the accent of the pill shown for that status', () => {
  for (const s of ['Pending', 'Booked', 'Responding', 'Resolved', 'Disapproved', 'Cancelled', 'Approved', 'Released', 'Returned', 'Denied']) {
    assert.equal(tabAccent(s), pillAccent(s), s)
  }
})

test('raw tab values map onto the pill the table shows for them', () => {
  assert.equal(tabAccent('Not dispatched'), pillAccent('Booked'))
  assert.equal(tabAccent('In transit'), pillAccent('Responding'))
  assert.equal(tabAccent('Completed'), pillAccent('Resolved'))
  assert.equal(tabAccent('No arrival'), pillAccent('Resolved — no arrival'))
  assert.equal(tabAccent('off_duty'), pillAccent('Off duty'))
  assert.equal(tabAccent('Disabled'), pillAccent('Deactivated'))
})

test('the same status is the same colour on every page', () => {
  assert.equal(tabAccent('Pending'), tabAccent('Dispatched'))
  assert.equal(tabAccent('Resolved'), tabAccent('Returned'))
  assert.equal(tabAccent('Cancelled'), tabAccent('No arrival'))
})

test('Overdue is the theme error red, apart from Denied', () => {
  assert.equal(tabAccent('Overdue'), 'rgb(var(--v-theme-error))')
  assert.notEqual(tabAccent('Overdue'), tabAccent('Denied'))
})

test('All and non-status tabs get no accent', () => {
  for (const v of ['All', 'all', 'services', 'board', 'head_of_family', 'Head of the Family', 'Needs attention', '', null, undefined]) {
    assert.equal(tabAccent(v), null, String(v))
  }
})
