/**
 * Whether a list on screen is behind the server, from one kind of
 * `GET /admin/pulse` (`{count, latest}`) and the rows the page holds.
 *
 * Only a pulse ahead of the rows counts: a newer `updated_at` than any row, or
 * the same newest row with a different count (added or deleted elsewhere). A
 * pulse older than the rows is one taken before this page's own write, and
 * must not read as someone else's change. Kept free of imports so
 * `node --test` can load it.
 */
export interface PulseStamp {
  count: number
  latest: string | null
}

export interface ListUpdate {
  changed: boolean
  /** How many more rows the server has than the page; 0 for an edit. */
  added: number
}

const time = (value: unknown) => (typeof value === 'string' ? Date.parse(value) || 0 : 0)

export function pulseDiff(stamp: PulseStamp | undefined, rows: Array<{ updated_at?: string | null }>): ListUpdate {
  if (!stamp) {return { changed: false, added: 0 }}

  const server = time(stamp.latest)
  const onScreen = rows.reduce((max, row) => Math.max(max, time(row.updated_at)), 0)
  const changed = server > onScreen || (server === onScreen && stamp.count !== rows.length)

  return { changed, added: changed ? Math.max(0, stamp.count - rows.length) : 0 }
}
