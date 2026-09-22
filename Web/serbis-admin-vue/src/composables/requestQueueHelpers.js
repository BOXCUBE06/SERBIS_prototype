import { computed } from 'vue'

const META_TAIL = /^(?:contact:|submitted\b)/i

export function useDescriptionLines(selectedRequest) {
  return computed(() => {
    const raw = selectedRequest.value?.description
    if (!raw) {return []}

    const lines = raw.split('\n').map(line => line.trim()).filter(Boolean)
    const service = (selectedRequest.value?.service?.service_name || '').trim().toLowerCase()

    const kept = [...lines]
    if (service && kept[0]?.toLowerCase() === service) {kept.shift()}

    while (kept.length > 0 && META_TAIL.test(kept.at(-1))) {kept.pop()}

    return kept.length > 0 ? kept : lines
  })
}

export function useSelection(selectedRequest, selectedIds, itemId) {
  const isSelected = (item) => selectedRequest.value && itemId(selectedRequest.value) === itemId(item)

  const toggleSelect = (item) => {
    const id = itemId(item)
    if (selectedIds.has(id)) {selectedIds.delete(id)}
    else {selectedIds.add(id)}
  }

  return { isSelected, toggleSelect }
}
