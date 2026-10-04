import { ref } from 'vue'
import type { Ref } from 'vue'
import { REFERENCE_TTL_MS, invalidate, useCachedFetch } from '@/composables/useCachedFetch'

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
}

export function useRequestFetch({ isAmbulance, initialLoad, apiError, formData, selectedRequest, loadAttachments }: Options) {
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
  }

  return { requests, vehicles, residents, services, responders, listAbortController, refreshing, fetchData, fetchRequests, selectRequest }
}
