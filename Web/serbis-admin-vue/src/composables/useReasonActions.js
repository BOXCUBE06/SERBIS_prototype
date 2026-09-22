import { ref } from 'vue'
import { API_BASE } from '@/config/api'

export const emptyReasonDialog = () => ({ open: false, kind: 'disapprove', reason: '', error: '' })

export function useReasonActions(reasonDialog, { formData, apiError, bulkLoading, requests, selectedIds, itemId, getHeaders, updateStatus, fetchRequests }) {
  const noteExpanded = ref(false)

  const openReason = (kind) => {
    noteExpanded.value = false
    reasonDialog.value = {
      ...emptyReasonDialog(),
      open: true,
      kind,
      reason: '',
    }
  }

  const clearReason = () => { reasonDialog.value = emptyReasonDialog(); noteExpanded.value = false }

  const bulkDisapprove = async (reason) => {
    bulkLoading.value = true
    apiError.value = ''
    const targets = requests.value.filter(r => selectedIds.has(itemId(r)))
    try {
      await Promise.all(targets.map(async (r) => {
        const res = await fetch(`${API_BASE}/service-requests/${itemId(r)}`, {
          method: 'PUT',
          headers: getHeaders(),
          body: JSON.stringify({ status: 'Disapproved', remarks: reason, vehicle_id: r.vehicle_id })
        })
        if (!res.ok) {throw new Error('Failed to update one or more requests')}
      }))
      selectedIds.clear()
      await fetchRequests()
      reasonDialog.value.open = false
    } catch {
      apiError.value = 'Failed to update one or more requests'
      reasonDialog.value.error = 'Failed to update one or more requests'
    } finally {
      bulkLoading.value = false
    }
  }

  const confirmReason = () => {
    const { kind, reason } = reasonDialog.value
    const trimmed = reason.trim()
    if (kind !== 'approve' && !trimmed) {
      reasonDialog.value.error = 'Give a reason — the Head of the Family is shown this'
      return
    }
    if (kind === 'bulk') {return bulkDisapprove(trimmed)}
    formData.value.remarks = trimmed
    return updateStatus(kind === 'approve' ? 'Responding' : 'Disapproved')
  }

  return { noteExpanded, openReason, clearReason, confirmReason, bulkDisapprove }
}
