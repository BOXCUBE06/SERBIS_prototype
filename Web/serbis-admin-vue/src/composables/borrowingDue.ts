/**
 * Due dates on a loan: the drawer header's countdown and the note under a list
 * row's status pill read the same numbers from here. `today` is a parameter
 * only so the tests can fix it.
 */
import { BORROWING_STATUSES } from './borrowingStatus.ts'
import { waitNote } from './submittedTime.ts'

interface Loan { status?: string | null; due_date?: string | null; created_at?: string | null }
export interface Note { text: string; class: string }

const TERMINAL = new Set(BORROWING_STATUSES.filter((s) => s.terminal).map((s) => s.status))
const DAY_MS = 86_400_000

const midnight = (d = new Date()) => {
  const copy = new Date(d)
  copy.setHours(0, 0, 0, 0)
  return copy
}

/** A bare calendar date ("2026-08-10"), read as that local day, not as UTC midnight. */
export function parseDay(value: string | null | undefined): Date | null {
  if (!value) {return null}
  const [y, m, d] = String(value).slice(0, 10).split('-').map(Number)
  if (!y || !m || !d) {return null}
  return new Date(y, m - 1, d)
}

/** Whole days from today to the due date, both at local midnight: due later today reads 0. */
export function dueDelta(item: Loan | null | undefined, today = new Date()): number | null {
  const due = parseDay(item?.due_date)
  if (!due) {return null}
  return Math.round((due.getTime() - midnight(today).getTime()) / DAY_MS)
}

/** A returned, denied or cancelled record cannot be overdue: the item is back, or it never left. */
export function isOverdue(item: Loan | null | undefined, today = new Date()): boolean {
  if (!item || TERMINAL.has(item.status as string)) {return false}
  const delta = dueDelta(item, today)
  return delta !== null && delta < 0
}

export function dueLabel(item: Loan | null | undefined, today = new Date()): string {
  const delta = dueDelta(item, today)
  if (delta === null) {return ''}
  if (delta < 0) {return `${-delta} day${delta === -1 ? '' : 's'} overdue`}
  if (delta === 0) {return 'Due today'}
  if (delta === 1) {return 'Due tomorrow'}
  return `Due in ${delta} days`
}

/** Red once overdue, amber inside the 1-day reminder window (SendReturnDueReminders' own), quiet otherwise. */
export function dueClass(item: Loan | null | undefined, today = new Date()): string {
  if (isOverdue(item, today)) {return 'text-error font-weight-bold'}
  return ['Due today', 'Due tomorrow'].includes(dueLabel(item, today)) ? 'text-warning-strong' : 'text-medium-emphasis'
}

/**
 * The note under a list row's status pill, or null: red when overdue, amber
 * when a Released loan is due today or tomorrow, amber when Pending more than
 * two days. The filing time has its own column.
 */
export function statusNote(item: Loan, today = new Date()): Note | null {
  if (isOverdue(item, today)) {return { text: dueLabel(item, today), class: 'text-error font-weight-bold' }}
  if (item.status === 'Released') {
    const label = dueLabel(item, today)
    if (label === 'Due today' || label === 'Due tomorrow') {return { text: label, class: 'text-warning-strong' }}
  }
  const wait = waitNote(item.status, item.created_at, today.getTime())
  return wait ? { text: wait, class: 'text-warning-strong' } : null
}
