// Run: npm test
import test from 'node:test'
import assert from 'node:assert/strict'
import { rowMenu, closeBlockedReason, canSelect, bulkSplit, sharedAccess, closeTitle, closeLabel, failureSummary } from './staffActions.js'

const account = (id, extra = {}) => ({ admin_id: id, first_name: `First${id}`, last_name: `Last${id}`, status: 'Active', is_super_admin: false, permissions: [], ...extra })
const me = account(1, { is_super_admin: true, permissions: null })
const lorna = account(2, { first_name: 'Lorna', last_name: 'Agbayani' })
const mark = account(3, { status: 'Inactive' })
const all = [me, lorna, mark]
const keys = (items) => items.map((i) => i.key)

test('an active colleague gets Manage access, Reset password, then Close account', () => {
  const items = rowMenu(lorna, all, 1)
  assert.deepEqual(keys(items), ['access', 'reset', 'divider', 'close'])
  assert.equal(items.at(-1).danger, true)
  assert.equal(items.at(-1).disabled, false)
})

test('a deactivated account gets Manage access, then Reactivate, and no reset', () => {
  assert.deepEqual(keys(rowMenu(mark, all, 1)), ['access', 'divider', 'reactivate'])
})

test('my own account cannot be reset or closed from here, and says why', () => {
  const items = rowMenu(me, all, 1)
  assert.deepEqual(keys(items), ['access', 'divider', 'close'])
  assert.equal(items.at(-1).disabled, true)
  assert.equal(items.at(-1).hint, "You can't close your own account")
})

test('the last active account and the last active super admin cannot be closed', () => {
  // Seen by another super admin looking at a list where only one account is open.
  assert.equal(closeBlockedReason(lorna, [lorna, mark], 99), 'This is the only active account')
  const boss = account(4, { is_super_admin: true })
  assert.equal(closeBlockedReason(boss, [boss, lorna], 99), 'This is the only active super admin')
  // A closed super admin does not count as a way back in.
  assert.equal(closeBlockedReason(boss, [boss, lorna, account(5, { is_super_admin: true, status: 'Inactive' })], 99), 'This is the only active super admin')
  assert.equal(closeBlockedReason(boss, [boss, lorna, account(6, { is_super_admin: true })], 99), null)
})

test('my own account cannot be selected', () => {
  assert.equal(canSelect(me, 1), false)
  assert.equal(canSelect(lorna, 1), true)
})

test('bulk close touches only the open accounts, bulk reactivate only the closed ones', () => {
  const { picked, open, closed } = bulkSplit([2, 3], all)
  assert.equal(picked.length, 2)
  assert.deepEqual(open.map((a) => a.admin_id), [2])
  assert.deepEqual(closed.map((a) => a.admin_id), [3])
})

test('the access dialog starts from shared access only when every account has the same', () => {
  assert.equal(sharedAccess([lorna, account(7)]), lorna)
  assert.equal(sharedAccess([lorna, account(8, { permissions: ['requests'] })]), null)
  assert.equal(sharedAccess([account(9, { permissions: ['sms', 'requests'] }), account(10, { permissions: ['requests', 'sms'] })]).admin_id, 9)
  assert.equal(sharedAccess([lorna, account(11, { permissions: null })]), null)
  assert.equal(sharedAccess([]), null)
})

test('the confirm names one account or counts several', () => {
  assert.equal(closeTitle([lorna]), "Close Lorna Agbayani's account?")
  assert.equal(closeTitle([lorna, mark, me]), 'Close 3 accounts?')
  assert.equal(closeLabel(1), 'Close account')
  assert.equal(closeLabel(3), 'Close 3 accounts')
})

test('a partial failure names each account and why', () => {
  assert.equal(failureSummary([], 'close', all), '')
  assert.equal(
    failureSummary([{ id: 2, name: null, message: 'Refused' }, { id: 42, name: null, message: 'Admin not found' }], 'close', all),
    "Couldn't close 2 accounts: Lorna Agbayani (Refused); Account #42 (Admin not found).",
  )
  assert.equal(failureSummary([{ id: 3, name: 'Mark Dumlao', message: 'Nope' }], 'reactivate', all), "Couldn't reactivate 1 account: Mark Dumlao (Nope).")
})
