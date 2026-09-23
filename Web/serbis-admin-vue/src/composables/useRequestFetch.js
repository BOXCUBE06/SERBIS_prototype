import { ref } from 'vue'
import { API_BASE } from '@/config/api'

export const AMBULANCE_SERVICE_CODE = 'ambulance-medical-response'
export const isAmbulanceRequest = (r) => r.service?.code === AMBULANCE_SERVICE_CODE
export const itemId = (item) => item.request_id || item.id

export function useRequestFetch({ isAmbulance, getHeaders, initialLoad, apiError, formData, selectedRequest, loadAttachments }) {
  const requests = ref([])
  const vehicles = ref([])
  const residents = ref([])
  const services = ref([])
  const responders = ref([])
  const listAbortController = new AbortController()

  const belongsToScope = (r) => isAmbulanceRequest(r) === isAmbulance

  const selectRequest = (item, resetRemarks = true) => {
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
    const fresh = requests.value.find(r => itemId(r) === itemId(selectedRequest.value))
    if (fresh) {selectRequest(fresh, false)}
  }

  const fetchData = async () => {
    try {
      const [reqRes, vehRes, resRes, svcRes, respRes] = await Promise.all([
        fetch(`${API_BASE}/admin/service-requests`, { headers: getHeaders(), signal: listAbortController.signal }),
        fetch(`${API_BASE}/vehicles`, { headers: getHeaders(), signal: listAbortController.signal }),
        fetch(`${API_BASE}/residents/lookup`, { headers: getHeaders(), signal: listAbortController.signal }),
        fetch(`${API_BASE}/services`, { headers: getHeaders(), signal: listAbortController.signal }),
        // 404s for an admin without the Responders section — the picker that
        // needs this list is simply not shown to them, so an empty fallback
        // is correct rather than surfacing an error nobody can act on.
        fetch(`${API_BASE}/responders`, { headers: getHeaders(), signal: listAbortController.signal }).catch(() => null)
      ])
      const reqData = await reqRes.json()
      const vehData = await vehRes.json()
      const resData = await resRes.json()
      const svcData = await svcRes.json()
      const respData = respRes && respRes.ok ? await respRes.json() : []
      const allRequests = reqData.data || reqData
      requests.value = allRequests.filter((r) => belongsToScope(r))
      vehicles.value = vehData.data || vehData
      residents.value = resData.data || resData
      services.value = svcData.data || svcData
      responders.value = respData.data || respData

      selectDefaultOrRefreshSelection()
    } catch (error) {
      if (error.name === 'AbortError') {return}
      console.error('Failed to fetch data:', error)
    } finally {
      initialLoad.value = false
    }
  }

  const fetchRequests = async () => {
    try {
      const reqRes = await fetch(`${API_BASE}/admin/service-requests`, { headers: getHeaders(), signal: listAbortController.signal })
      const reqData = await reqRes.json()
      const allRequests = reqData.data || reqData
      requests.value = allRequests.filter((r) => belongsToScope(r))

      selectDefaultOrRefreshSelection()
    } catch (error) {
      if (error.name === 'AbortError') {return}
      console.error('Failed to fetch service requests:', error)
    }
  }

  return { requests, vehicles, residents, services, responders, listAbortController, fetchData, fetchRequests, selectRequest }
}
