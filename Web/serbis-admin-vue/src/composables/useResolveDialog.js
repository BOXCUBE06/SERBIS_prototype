import { ref } from 'vue'

export function useResolveDialog(selectedRequest, { requesterName, updateStatus, apiError }) {
  const resolveDialog = ref({ open: false, label: '' })

  const openResolveConfirm = () => {
    const req = selectedRequest.value
    if (!req) {return}
    const who = requesterName(req)
    resolveDialog.value = {
      open: true,
      label: who && !who.startsWith('Unknown') ? `${who}'s request` : 'this request',
    }
  }

  const confirmResolve = async () => {
    await updateStatus('Resolved')
    if (!apiError.value) {
      resolveDialog.value.open = false
    }
  }

  return { resolveDialog, openResolveConfirm, confirmResolve }
}
