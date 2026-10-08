/**
 * The two lines of a list's request-time cell: "Oct 3, 5:27 PM" over
 * "8 hours ago", and the amber "Waiting 3d" a Pending row earns once it is
 * older than WAIT_NOTE_DAYS. Kept free of imports so `node --test` can load it.
 */
const DAY_MS = 86_400_000

const dateTime = new Intl.DateTimeFormat('en-US', {
  timeZone: 'Asia/Manila', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
})

const unit = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'} ago`

export function submittedAt(value: string | null | undefined): string {
  const t = value ? Date.parse(value) : Number.NaN
  return Number.isNaN(t) ? '' : dateTime.format(t)
}

export function timeAgo(value: string | null | undefined, now = Date.now()): string {
  const t = value ? Date.parse(value) : Number.NaN
  if (Number.isNaN(t)) {return ''}
  const minutes = Math.floor(Math.max(0, now - t) / 60_000)
  if (minutes < 1) {return 'just now'}
  if (minutes < 60) {return unit(minutes, 'minute')}
  const hours = Math.floor(minutes / 60)
  if (hours < 24) {return unit(hours, 'hour')}
  return unit(Math.floor(hours / 24), 'day')
}

/** A Pending row waits quietly for two days; after that the status cell says so. */
export const WAIT_NOTE_DAYS = 2

export function waitNote(status: string | null | undefined, createdAt: string | null | undefined, now = Date.now()): string {
  if ((status || 'Pending') !== 'Pending' || !createdAt) {return ''}
  const days = Math.floor((now - Date.parse(createdAt)) / DAY_MS)
  return days > WAIT_NOTE_DAYS ? `Waiting ${days}d` : ''
}
