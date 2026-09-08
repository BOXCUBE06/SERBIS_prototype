import { ref, type Ref } from 'vue'

import { authHeaders } from './adminUi'
import { API_BASE } from '../config/api'

/**
 * The borrowings list, fetched once and shared by every view that needs it.
 *
 * `GET /borrowings` is `EquipmentBorrowingController::index`, which is a bare
 * `$query->get()` — no pagination, every row in the table, with
 * `resident.barangay` and `equipment` eager-loaded. One view pulling that is
 * already the heaviest read in the panel; two views each pulling their own copy
 * is twice that for the same rows, which is why the state below is module-level
 * and not per-component.
 *
 * Callers: EquipmentBorrowingView (the board) and ProcurementReferenceView (the
 * uncatalogued requests). The second is read-only and derives everything it
 * shows from these same rows.
 */
export type BorrowingRow = Record<string, any>

const rows: Ref<BorrowingRow[]> = ref([])
const loadError = ref('')
const initialLoad = ref(true)
const reloading = ref(false)

/**
 * Held so two components mounting in the same tick — or a refresh landing on
 * top of a mount — share one request rather than racing two identical ones
 * into the same `rows`.
 */
let inFlight: Promise<void> | null = null

async function fetchRows(): Promise<void> {
  reloading.value = true

  try {
    const res = await fetch(`${API_BASE}/borrowings`, { headers: authHeaders() })

    // A non-2xx used to fall straight through: `res.json()` on an error body
    // assigns whatever came back to the list, so a 401 or a 500 rendered as an
    // empty board rather than as a failure.
    if (!res.ok) {
      const errData = await res.json().catch(() => ({}))
      throw new Error(errData.message || `Request failed (${res.status})`)
    }

    const data = await res.json()
    const list = data.data || data
    if (!Array.isArray(list)) throw new Error('The server returned an unexpected response')

    rows.value = list
    loadError.value = ''
  } catch (error) {
    console.error('Failed to fetch borrowings:', error)
    loadError.value = (error as Error).message || 'Could not reach the server'
  } finally {
    initialLoad.value = false
    reloading.value = false
  }
}

export function useBorrowingsList() {
  /**
   * `{ ifEmpty: true }` reuses whatever is already loaded and fetches only on a
   * cold start — what a second view wants on mount, so opening it does not
   * re-pull the whole table the board just pulled. Every write path calls
   * `load()` plain, because a mutation has to be read back.
   */
  const load = async ({ ifEmpty = false } = {}): Promise<void> => {
    if (ifEmpty && rows.value.length > 0) {
      initialLoad.value = false
      return
    }

    if (inFlight) return inFlight

    inFlight = fetchRows().finally(() => {
      inFlight = null
    })

    return inFlight
  }

  return { rows, loadError, initialLoad, reloading, load }
}
