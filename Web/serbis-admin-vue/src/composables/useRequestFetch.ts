import { computed, ref, watch } from 'vue'
import type { Ref } from 'vue'
import { REFERENCE_TTL_MS, invalidate, useCachedFetch } from '@/composables/useCachedFetch'
import { pulse, refreshPulse } from '@/composables/usePulse'
import { pulseDiff } from '@/composables/pulseDiff'

type Row = Record<string, any>

export const AMBULANCE_SERVICE_CODE = 'ambulance-medical-response'
export const isAmbulanceRequest = (r: Row) => r.service?.code === AMBULANCE_SERVICE_CODE
export const itemId = (item: Row) => item.request_id || item.id

// The request list is not a reference list: it always revalidates, the cached copy only covers the wait.

const unwrap = (body: unknown): Row[] => ((body as Row)?.data || body) as Row[]

interface Options {
  isAmbulance: boolean
  initialLoad: Ref<boolean>
  apiError: Ref<string>
  formData: Ref<Row>
  selectedRequest: Ref<Row | null>
  loadAttachments: (item: Row) => void
  /** No drawer or dialog open: the list may be swapped without the notice. */
  isIdle?: () => boolean
}

export function useRequestFetch({ isAmbulance, initialLoad, apiError, formData, selectedRequest, loadAttachments, isIdle }: Options) {
  const requests = ref<Row[]>([])
  const vehicles = ref<Row[]>([])
  const residents = ref<Row[]>([])
  const services = ref<Row[]>([])
  const responders = ref<Row[]>([])
  const listAbortController = new AbortController()
  const { get, refreshing } = useCachedFetch()

  const belongsToScope = (r: Row) => isAmbulanceRequest(r) === isAmbulance
  // The request keeps running after a page is left and still fills the cache; this
  // only stops it writing into refs nobody reads.
  const gone = () => listAbortController.signal.aborted

  const selectRequest = (item: Row | undefined, resetRemarks = true) => {
    if (!item) {return}
    apiError.value = ''
    selectedRequest.value = item
    formData.value = {
      remarks: resetRemarks ? (item.remarks || '') : formData.value.remarks,
      internal_notes: item.internal_notes || '',
      vehicle_id: item.vehicle_id || null
    }
    loadAttachments(item)
  }

  const selectDefaultOrRefreshSelection = () => {
    if (!selectedRequest.value) {return}
    const fresh = requests.value.find(r => itemId(r) === itemId(selectedRequest.value as Row))
    if (fresh) {selectRequest(fresh, false)}
  }

  const takeRequests = (body: unknown) => {
    if (gone()) {return}
    requests.value = unwrap(body).filter((r) => belongsToScope(r))
    initialLoad.value = false
    selectDefaultOrRefreshSelection()
  }

  const take = (target: Ref<Row[]>) => (body: unknown) => {
    if (!gone()) {target.value = unwrap(body)}
  }

  const fetchData = async () => {
    try {
      await Promise.all([
        get('/admin/service-requests', { onData: takeRequests }),
        get('/vehicles', { onData: take(vehicles) }),
        get('/residents/lookup', { ttl: REFERENCE_TTL_MS, onData: take(residents) }),
        get('/services', { ttl: REFERENCE_TTL_MS, onData: take(services) }),
        // 404s for an admin without the Responders section — the picker that
        // needs this list is simply not shown to them, so an empty fallback
        // is correct rather than surfacing an error nobody can act on.
        get('/responders', { ttl: REFERENCE_TTL_MS, onData: take(responders) }).catch(() => null),
      ])
    } catch (error) {
      if (!gone()) {console.error('Failed to fetch data:', error)}
    } finally {
      initialLoad.value = false
    }
  }

  // Called when a unit/responder picker opens: the page-load copies can be minutes old,
  // and `busy` is judged against this request (its own unit and crew are not busy to it).
  const refreshFleet = async (requestId: string | number) => {
    const query = `?for_request=${requestId}`
    await Promise.all([
      get('/vehicles' + query, { fresh: true, onData: take(vehicles) }),
      // Ambulance staff may lack the Responders section, and only the response picker needs it.
      isAmbulance ? null : get('/responders' + query, { fresh: true, onData: take(responders) }),
    ]).catch((error) => {
      if (!gone()) {console.error('Failed to refresh units:', error)}
    })
  }

  // Only called after a write. A write can also move a vehicle (Dispatched), so
  // both are dropped: the refetch must not flash the pre-write copy.
  const fetchRequests = async () => {
    invalidate('/admin/service-requests')
    invalidate('/vehicles')
    try {
      await get('/admin/service-requests', { fresh: true, onData: takeRequests })
    } catch (error) {
      if (!gone()) {console.error('Failed to fetch service requests:', error)}
    }
    refreshPulse()
  }

  // Requests filed elsewhere (the mobile app, another staff member) since the
  // list loaded. The page shows a notice; its Show button is fetchRequests.
  const listUpdate = computed(() => pulseDiff(initialLoad.value ? undefined : pulse.value?.[isAmbulance ? 'ambulance' : 'requests'], requests.value))

  // Idle page: refetch by itself (also when it turns idle with a change pending).
  // Busy page: the notice stays. `changed &&` short-circuits, so isIdle is never
  // called before the caller has declared the refs it reads.
  if (isIdle) {
    watch(() => listUpdate.value.changed && isIdle(), (go) => { if (go) {fetchRequests()} })
  }

  return { requests, vehicles, residents, services, responders, listAbortController, refreshing, fetchData, fetchRequests, refreshFleet, selectRequest, listUpdate }
}
