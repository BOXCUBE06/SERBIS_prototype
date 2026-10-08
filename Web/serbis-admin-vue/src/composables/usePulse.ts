/**
 * The panel's one poll: `GET /admin/pulse` every 30 seconds while the tab is
 * visible, and once on returning to it. It reports a `{count, latest}` per list
 * (and the pending-account count), never the lists themselves; pages compare it
 * against their rows (pulseDiff) and offer a refresh instead of swapping rows
 * under the cursor. The server returns only the kinds the account's sections
 * cover. Started by the sidebar, so it runs exactly while someone is signed in.
 *
 * ponytail: 30s poll, move to push (Reverb/SSE) if staff need changes instantly.
 */
import { ref } from 'vue'
import { getToken } from '@/composables/authToken'
import { onClear, useCachedFetch } from '@/composables/useCachedFetch'
import type { PulseStamp } from '@/composables/pulseDiff'

/** `waiting`: rows waiting on a staff decision, the sidebar badge. */
type WaitingStamp = PulseStamp & { waiting: number }

export interface Pulse {
  requests?: WaitingStamp
  ambulance?: WaitingStamp
  trips?: PulseStamp
  borrowings?: WaitingStamp
  residents?: PulseStamp & { pending: number; pending_organizations: number }
}

const PULSE_MS = 30_000

export const pulse = ref<Pulse | null>(null)

// Its own instance: a pulse in flight must not dim any table.
const { get } = useCachedFetch()
let timer: ReturnType<typeof setInterval> | undefined

/** Also called after a page's own write, so the pulse catches up with the rows at once. */
export function refreshPulse(): void {
  if (!timer || document.hidden || !getToken()) {return}
  get<Pulse>('/admin/pulse')
    .then((body) => { pulse.value = body })
    .catch((error) => {
      // An account holding none of the sections it reports on: nothing to watch.
      if (error?.status === 403) {stopPulse()}
    })
}

export function startPulse(): void {
  if (timer) {return}
  timer = setInterval(refreshPulse, PULSE_MS)
  document.addEventListener('visibilitychange', refreshPulse)
  refreshPulse()
}

export function stopPulse(): void {
  clearInterval(timer)
  timer = undefined
  document.removeEventListener('visibilitychange', refreshPulse)
}

onClear(() => { pulse.value = null })
