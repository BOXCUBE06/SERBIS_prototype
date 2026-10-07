/**
 * Which actions a Staff accounts row offers, and what a bulk action applies to.
 * Mirrors AdminController: the server refuses the same cases, this only spares
 * the round trip and says why up front.
 */

export const idOf = (account) => account?.admin_id ?? account?.id ?? null

/** Closed only when it says Inactive; a null status is a hand-inserted row, still open. */
export const isClosed = (account) => String(account?.status ?? '').toLowerCase() === 'inactive'

export const fullName = (account) => (account ? `${account.first_name} ${account.last_name}` : '')

export const isSelf = (account, selfId) => idOf(account) !== null && idOf(account) === selfId

/** Why Close account is unavailable on this row, or null. The same three refusals as AdminController::closeOne(). */
export const closeBlockedReason = (account, accounts, selfId) => {
  if (isSelf(account, selfId)) {return "You can't close your own account"}

  const open = accounts.filter((a) => !isClosed(a))
  if (open.length <= 1) {return 'This is the only active account'}
  if (account.is_super_admin && open.filter((a) => a.is_super_admin).length <= 1) {return 'This is the only active super admin'}

  return null
}

/**
 * The More menu, top to bottom. Reset password is never offered on your own row
 * (change it from your profile) or on a closed account (reactivate it first);
 * a closed account offers Reactivate in place of Close account.
 */
export const rowMenu = (account, accounts, selfId) => {
  const items = [{ key: 'access', label: 'Manage access' }]

  if (!isClosed(account) && !isSelf(account, selfId)) {items.push({ key: 'reset', label: 'Reset password' })}

  items.push({ key: 'divider', divider: true })

  if (isClosed(account)) {
    items.push({ key: 'reactivate', label: 'Reactivate' })
  } else {
    const hint = closeBlockedReason(account, accounts, selfId)
    items.push({ key: 'close', label: 'Close account', danger: true, disabled: hint !== null, hint })
  }

  return items
}

/** Your own account can never be ticked, so no bulk action can reach it. */
export const canSelect = (account, selfId) => !isSelf(account, selfId)

/** The ticked accounts, split by what each bulk action may touch. */
export const bulkSplit = (ids, accounts) => {
  const wanted = new Set(ids)
  const picked = accounts.filter((a) => wanted.has(idOf(a)))

  return { picked, open: picked.filter((a) => !isClosed(a)), closed: picked.filter((a) => isClosed(a)) }
}

/** The access every account in the list shares, or null when they differ. */
export const sharedAccess = (accounts) => {
  if (accounts.length === 0) {return null}

  const key = (a) => JSON.stringify([Boolean(a.is_super_admin), a.permissions === null || a.permissions === undefined ? null : [...a.permissions].sort()])
  const first = key(accounts[0])

  return accounts.every((a) => key(a) === first) ? accounts[0] : null
}

export const closeTitle = (accounts) => (accounts.length === 1
  ? `Close ${fullName(accounts[0])}'s account?`
  : `Close ${accounts.length} accounts?`)

export const closeLabel = (count) => (count === 1 ? 'Close account' : `Close ${count} accounts`)

/** "Couldn't close 2 accounts: Lorna Agbayani (reason); Jonas Tumaneng (reason)." */
export const failureSummary = (failed, verb, accounts) => {
  if (failed.length === 0) {return ''}

  const named = failed.map((f) => {
    const name = f.name || fullName(accounts.find((a) => idOf(a) === f.id)) || `Account #${f.id}`
    return `${name} (${f.message})`
  })

  return `Couldn't ${verb} ${failed.length === 1 ? '1 account' : `${failed.length} accounts`}: ${named.join('; ')}.`
}
