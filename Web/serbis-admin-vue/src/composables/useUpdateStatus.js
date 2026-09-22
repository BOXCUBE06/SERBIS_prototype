import { API_BASE } from '@/config/api'

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
        const firstError = errData.errors ? Object.values(errData.errors)[0]?.[0] : null
        throw new Error(firstError || errData.message || 'Failed to update request')
      }

      await fetchRequests()

      if (newStatus === 'Responding' && onResponding) {onResponding()}

      reasonDialog.value.open = false
    } catch (error) {
      apiError.value = error.message
      reasonDialog.value.error = error.message
    } finally {
      loading.value = false
    }
  }

  return { updateStatus }
}
