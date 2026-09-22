/**
 * The helpers every admin view had written out for itself.
 *
 * Eleven views declared their own `getHeaders`, seven their own `notify`, four
 * their own `initials`. They were not quite identical, and that is the reason
 * this file exists rather than a style preference: `EquipmentBorrowingView`'s
 * `initials` omitted the `.toUpperCase()` the other three applied, so the same
 * resident's avatar read "MS" on one page and "mS" on another. A copy that
 * drifts is worse than a copy that does not, and nothing flags the drift.
 *
 * `initials()` is wired into ResidentDetailPanel, StaffView, UsersView and
 * EquipmentBorrowingView (audits/code-duplication.md Finding 6).
 * `authHeaders()` and `useSnackbar()` are not yet adopted anywhere — that
 * migration (Findings 4 and 9) is a separate, larger pass across 11 views.
 */
import { ref } from 'vue'
import type { Ref } from 'vue'
import { getToken } from '@/composables/authToken'

/**
 * Bearer headers for a JSON request.
 *
 * `json: false` drops `Content-Type`, which is what a multipart upload needs —
 * setting it by hand stops the browser from generating the multipart boundary.
 * `FilesView` is the caller that relies on this.
 */
export function authHeaders(json = true): Record<string, string> {
  const headers: Record<string, string> = {
    Authorization: `Bearer ${getToken()}`,
    Accept: 'application/json',
  }

  if (json) headers['Content-Type'] = 'application/json'

  return headers
}

export interface SnackbarState {
  show: boolean
  text: string
  color: string
}

/**
 * A snackbar ref plus the `notify` that writes to it, returned together so a
 * view cannot declare one without the other.
 */
export function useSnackbar(): { snackbar: Ref<SnackbarState>; notify: (text: string, color?: string) => void } {
  const snackbar = ref<SnackbarState>({ show: false, text: '', color: 'success' })

  const notify = (text: string, color = 'success'): void => {
    snackbar.value = { show: true, text, color }
  }

  return { snackbar, notify }
}

interface NamedPerson {
  first_name?: string | null
  last_name?: string | null
}

/**
 * Avatar initials. Uppercased — see the file header for why that is stated
 * rather than assumed.
 */
export function initials(person: NamedPerson | null | undefined): string {
  const first = (person?.first_name || '').charAt(0)
  const last = (person?.last_name || '').charAt(0)

  return `${first}${last}`.toUpperCase()
}

/**
 * Date only. Returns an em dash rather than "Invalid Date" for a null or
 * unparseable value, because these land directly in table cells.
 */
export function fmtDate(value: string | Date | null | undefined): string {
  if (!value) return '—'

  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return '—'

  return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
}

/** Date and time, same null handling as [fmtDate]. */
export function fmtDateTime(value: string | Date | null | undefined): string {
  if (!value) return '—'

  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return '—'

  return d.toLocaleString('en-PH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
}

/**
 * Booked's off-palette violet, for a context that needs the color as a JS
 * value rather than the `.pill-booked` CSS class — currently just
 * DashboardView's feed chip. Must match `.pill-booked` in
 * src/styles/settings.scss by hand; CSS and a `:color` prop value can't
 * share one definition here without introducing custom properties this
 * codebase doesn't otherwise use.
 */
export const BOOKED_COLOR = '#5B21B6'

/**
 * The neutral slate used for a Cancelled/no-arrival outcome — neither
 * success nor failure, so not on the five semantic hues. Same reasoning as
 * `.pill-cancelled` in settings.scss, hand-matched to that rule's tonal
 * value (not EquipmentBorrowing's `#475569`, which is tuned for solid-fill
 * white text, a different rendering mode — see borrowingStatus.ts).
 */
export const CANCELLED_COLOR = '#334155'

/**
 * The Ambulance Dispatch Requests page described one lifecycle two ways:
 * ConductionRequest::getTripStatusAttribute()'s 'Not dispatched' / 'In
 * transit' / 'Completed' for a trip's own checkpoints, versus
 * ServiceRequest's Pending/Booked/Responding/Resolved/Disapproved/Cancelled
 * for the request it was filed against. A trip's three states are a strict
 * subset of the request vocabulary — a trip never starts Pending and never
 * resolves to Disapproved or Cancelled, those are pre-dispatch outcomes —
 * so this maps onto the existing words rather than inventing a fourth
 * wording and a fourth color scale (impeccable review 2026-08-31, item 5).
 */
const TRIP_STATUS_TO_SHARED_STATUS: Record<string, string> = {
  'Not dispatched': 'Booked',
  'In transit': 'Responding',
  'Completed': 'Resolved',
}

/** A raw trip_status value, in the shared Bookings-tab vocabulary — used for pill color only, see tripStatusLabel() below for the text actually shown. */
export function sharedStatusLabel(tripStatus: string): string {
  return TRIP_STATUS_TO_SHARED_STATUS[tripStatus] || tripStatus
}

/**
 * 'Not dispatched' shares sharedStatusLabel()'s color with Bookings' own
 * 'Booked' pill, but the two are not the same fact: a Booked *request* has
 * not been approved or assigned a unit yet, while a 'Not dispatched' *trip*
 * already has both — a vehicle is attached, it just has not left the office.
 * Reusing the word "Booked" for both read as the same status repeated on
 * two tabs rather than two different moments in the same trip's life, so
 * this keeps the shared color but gives that one case its own text. 'In
 * transit'/'Completed' keep the shared words — those genuinely are the same
 * moment as Bookings' Responding/Resolved, not a false match.
 */
const TRIP_STATUS_DISPLAY_LABEL: Record<string, string> = {
  'Not dispatched': 'Awaiting departure',
}

/** The text to show for a raw trip_status value — distinct from sharedStatusLabel() where the two would otherwise collide. */
export function tripStatusLabel(tripStatus: string): string {
  return TRIP_STATUS_DISPLAY_LABEL[tripStatus] || sharedStatusLabel(tripStatus)
}

/**
 * A Resolved outcome splits in two once no_arrival_reason exists: a real
 * arrival, or a trip that closed with a stated reason it never got there.
 * Both are the same 'Resolved' status/trip_status value underneath — this is
 * what tells them apart on screen, without inventing a seventh status value.
 */
export function outcomeLabel(baseLabel: string, noArrivalReason?: string | null): string {
  return baseLabel === 'Resolved' && noArrivalReason ? 'Resolved — no arrival' : baseLabel
}

/** The pill class matching outcomeLabel() above — same split, same inputs. */
export function outcomePillClass(baseLabel: string, noArrivalReason?: string | null): string {
  return baseLabel === 'Resolved' && noArrivalReason ? 'pill-resolved-no-arrival' : statusPillClass(baseLabel)
}

/**
 * The CSS class for the classic .status-pill system, given a status already
 * in the shared vocabulary above. Trivial on its own, but centralized here
 * rather than duplicated per caller.
 */
export function statusPillClass(status: string | null | undefined): string {
  return `pill-${(status || 'Pending').toLowerCase()}`
}

/**
 * A Booked request whose scheduled_at has slipped into the past with nobody
 * moving it off Booked. Nothing server-side watches for this (no worker, no
 * cron on this deploy) and no notification fires either side — this is a
 * client-side-only "someone should look at this" flag, not a guarantee the
 * booking was actually missed. Deliberately blind to approved_at — both an
 * unapproved and an approved-but-never-dispatched booking are equally overdue.
 */
export function isBookingOverdue(status: string | null | undefined, scheduledAt: string | Date | null | undefined): boolean {
  if (status !== 'Booked' || !scheduledAt) return false

  const d = new Date(scheduledAt)
  return !Number.isNaN(d.getTime()) && d.getTime() < Date.now()
}

/**
 * Minutes/hours/days between now and `date`, always positive — the caller
 * already knows the direction (in the future vs. elapsed) from context, this
 * only picks the unit. Shared by every relative-time label in this file so
 * "in 40m" and "25m late" round the same way (MDRRMO feedback, 2026-09-18:
 * relative time alongside absolute, not day-granularity alone).
 */
function relativeMagnitude(diffMs: number): string {
  const abs = Math.abs(diffMs)
  const minutes = Math.round(abs / 60_000)
  if (minutes < 60) return `${Math.max(minutes, 1)}m`
  const hours = Math.round(abs / 3_600_000)
  if (hours < 24) return `${hours}h`
  const days = Math.round(abs / 86_400_000)
  return `${days}d`
}

/**
 * The Booked sub-label a dispatcher needs since the status pill alone cannot
 * tell "scheduled with no unit yet" from "unit waiting" — both are the same
 * DB status, differing only in approved_at (MDRRMO feedback, 2026-09-18).
 * Three shapes:
 *   - overdue (scheduled_at already passed, still Booked): "25m late — not
 *     dispatched", regardless of approval — an approved booking that never
 *     went out is exactly as overdue as one nobody approved.
 *   - approved, still upcoming: "Unit assigned · in 2h"
 *   - not yet approved, still upcoming: "Awaiting unit · in 2h"
 * Null for anything not a live, scheduled Booked request.
 */
export function bookingCountdownLabel(
  status: string | null | undefined,
  scheduledAt: string | Date | null | undefined,
  approvedAt?: string | Date | null | undefined,
): string | null {
  if (status !== 'Booked' || !scheduledAt) return null

  const d = new Date(scheduledAt)
  if (Number.isNaN(d.getTime())) return null

  const diffMs = d.getTime() - Date.now()
  if (diffMs < 0) return `${relativeMagnitude(diffMs)} late — not dispatched`

  const prefix = approvedAt ? 'Unit assigned' : 'Awaiting unit'
  return `${prefix} · in ${relativeMagnitude(diffMs)}`
}

/**
 * How long an untouched Pending call has been waiting — the one status with
 * no scheduled_at at all, so created_at is the only clock it has (MDRRMO
 * feedback, 2026-09-18). An untriaged emergency call sitting for 25 minutes
 * is arguably the single most urgent thing on the board, and until now it
 * carried no time signal at all. Calm wording, existing .pill-pending token
 * — no alarm color, per PRODUCT.md's "never argue from urgency".
 */
export function pendingWaitLabel(status: string | null | undefined, createdAt: string | Date | null | undefined): string | null {
  if (status !== 'Pending' || !createdAt) return null

  const d = new Date(createdAt)
  if (Number.isNaN(d.getTime())) return null

  const diffMs = Date.now() - d.getTime()
  if (diffMs < 0) return null

  return `Waiting ${relativeMagnitude(diffMs)}`
}
