import { ref, reactive, computed, watch } from 'vue'
import { API_BASE } from '@/config/api'

export function useRequestAttachments(selectedRequest, { itemId, getHeaders }) {
  const createAttachment = (segment, failureMessage) => {
    const state = reactive({ url: '', type: '', loading: false, error: '', for: null })

    const release = () => {
      if (state.url) {URL.revokeObjectURL(state.url)}
      state.url = ''
      state.type = ''
    }

    const load = async (item, present) => {
      const id = item ? itemId(item) : null
      if (id === state.for) {return}

      release()
      state.for = id
      state.error = ''
      if (!present) {return}

      state.loading = true
      try {
        const res = await fetch(`${API_BASE}/service-requests/${id}/${segment}`, { headers: getHeaders() })
        if (!res.ok) {throw new Error(failureMessage)}
        const blob = await res.blob()
        if (state.for !== id) {return}
        state.type = blob.type
        state.url = URL.createObjectURL(blob)
      } catch (error) {
        if (state.for === id) {state.error = error.message}
      } finally {
        if (state.for === id) {state.loading = false}
      }
    }

    return { state, load, release }
  }

  const validId = createAttachment('valid-id', 'Could not load the attached ID.')
  const sitePhoto = createAttachment('site-photo', 'Could not load the landmark photo.')
  const letter = createAttachment('letter', 'Could not load the request letter.')

  const lightbox = ref({ open: false, key: null })

  const attachments = computed(() => {
    const req = selectedRequest.value
    if (!req) {return []}
    return [
      {
        key: 'site-photo',
        label: 'Landmark',
        present: !!req.has_site_photo,
        state: sitePhoto.state,
        alt: 'Landmark photo attached by the Head of the Family',
      },
      {
        key: 'letter',
        label: 'Request letter',
        present: !!req.has_letter,
        state: letter.state,
        alt: 'Request letter attached by the requesting barangay or organization',
      },
      {
        key: 'valid-id',
        label: 'Valid ID',
        present: !!req.has_valid_id,
        state: validId.state,
        alt: 'Valid ID attached by the Head of the Family',
      },
    ].filter(a => a.present)
  })

  const lightboxAttachment = computed(() =>
    attachments.value.find(a => a.key === lightbox.value.key) || null
  )

  const openLightbox = (a) => { lightbox.value = { open: true, key: a.key } }

  const loadAttachments = (item) => {
    validId.load(item, !!item?.has_valid_id)
    sitePhoto.load(item, !!item?.has_site_photo)
    letter.load(item, !!item?.has_letter)
  }

  const releaseAttachments = () => {
    validId.release()
    sitePhoto.release()
    letter.release()
  }

  watch(selectedRequest, () => { lightbox.value = { open: false, key: null } })

  return { attachments, lightbox, lightboxAttachment, openLightbox, loadAttachments, releaseAttachments }
}
