import { ref } from 'vue'
import { API_BASE } from '@/config/api'

export function useInternalNote(selectedRequest, { itemId, getHeaders, formData, apiError, fetchRequests }) {
  const noteSaving = ref(false)
  const noteSaved = ref(false)
  let noteSavedTimer = null

  const saveInternalNote = async () => {
    if (!selectedRequest.value) {return}
    noteSaving.value = true
    apiError.value = ''
    const id = itemId(selectedRequest.value)

    try {
      const res = await fetch(`${API_BASE}/service-requests/${id}`, {
        method: 'PUT',
        headers: getHeaders(),
        body: JSON.stringify({ internal_notes: formData.value.internal_notes }),
      })
      if (!res.ok) {
        const errData = await res.json()
        throw new Error(errData.message || 'Failed to save the note')
      }
      await fetchRequests()
      noteSaved.value = true
      clearTimeout(noteSavedTimer)
      noteSavedTimer = setTimeout(() => { noteSaved.value = false }, 2000)
    } catch (error) {
      apiError.value = error.message
    } finally {
      noteSaving.value = false
    }
  }

  return { noteSaving, noteSaved, saveInternalNote }
}
