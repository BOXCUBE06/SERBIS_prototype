import { computed } from 'vue'
import { displayPhone } from '@/composables/phoneNumber'

export const residentName = (resident) =>
  `${resident?.first_name || ''} ${resident?.last_name || ''}`.trim() || 'Unknown Head of the Family'

export const isWalkIn = (item) => !item?.resident && !item?.resident_id

// request_id is already a unique, never-reused DB primary key — that part is
// fine. It just prints as a bare small integer (1, 2, 3…), which reads like
// a row index rather than a transaction number. Zero-pad it for display only;
// nothing is stored or sent in this format.
export const transactionNo = (requestId) =>
  requestId === null || requestId === undefined ? 'N/A' : `TXN-${String(requestId).padStart(6, '0')}`

// Same reasoning, separate table: tbl_equipment_borrowing has its own PK
// (borrow_id) unrelated to tbl_service_request's request_id, so it needs its
// own prefix rather than reusing TXN- and colliding on the same number.
export const borrowingTransactionNo = (borrowId) =>
  borrowId === null || borrowId === undefined ? 'N/A' : `BOR-${String(borrowId).padStart(6, '0')}`

export const requesterName = (item) =>
  item?.resident ? residentName(item.resident) : (item?.walk_in_name || 'Unknown requester')

export const requesterInitials = (item) => {
  if (item?.resident) {return `${item.resident.first_name?.charAt(0) || ''}${item.resident.last_name?.charAt(0) || ''}`}
  const parts = (item?.walk_in_name || '').trim().split(/\s+/).filter(Boolean)
  return parts.length > 0 ? `${parts[0][0]}${parts[1]?.[0] || ''}`.toUpperCase() : 'W'
}

export const requesterPhone = (item) => displayPhone(item?.resident?.phone_number) || item?.walk_in_contact_number || 'N/A'

export const requesterBarangay = (item) => {
  if (item?.resident) {return item.resident.barangay?.barangay_name || 'Unknown Barangay'}
  return isWalkIn(item) ? 'Walk-in (no account)' : 'Unknown Barangay'
}

export const vehicleName = (v) => v?.unit_identifier || 'Unassigned unit'

export const vehicleIcon = (type) => ({
  ambulance: 'mdi-ambulance',
  'fire truck': 'mdi-fire-truck',
  'rescue vehicle': 'mdi-car-emergency',
  boat: 'mdi-ferry',
}[(type || '').toLowerCase()] || 'mdi-car')

export const getVehicleNameById = (vehicles, vehicleId) => {
  const v = vehicles.find(veh => veh.vehicle_id === vehicleId)
  return v ? `${vehicleName(v)} (${v.type})` : ''
}

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
