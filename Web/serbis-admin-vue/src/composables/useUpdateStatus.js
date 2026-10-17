import { API_BASE } from '@/config/api'
import { friendlyError, responseMessage } from '@/composables/adminUi'

export function useUpdateStatus({ selectedRequest, loading, apiError, formData, itemId, getHeaders, fetchRequests, reasonDialog, onResponding }) {
  const updateStatus = async (newStatus, targetRequest = selectedRequest.value) => {
    loading.value = true
    apiError.value = ''
    const id = itemId(targetRequest)

    try {
      const res = await fetch(`${API_BASE}/service-requests/${id}`, {
        method: 'PUT',
        headers: getHeaders(),
        body: JSON.stringify({
          status: newStatus,
          remarks: formData.value.remarks,
          internal_notes: formData.value.internal_notes,
          vehicle_id: formData.value.vehicle_id || targetRequest.vehicle_id
        })
      })

      if (!res.ok) {
        const errData = await res.json().catch(() => ({}))
        throw new Error(responseMessage(res.status, errData, 'Failed to update request'))
      }

      await fetchRequests()

      if (newStatus === 'Responding' && onResponding) {onResponding()}

      reasonDialog.value.open = false
    } catch (error) {
      apiError.value = friendlyError(error)
      reasonDialog.value.error = friendlyError(error)
    } finally {
      loading.value = false
    }
  }

  return { updateStatus }
}
