import { displayPhone } from '@/composables/phoneNumber'

export const residentName = (resident) =>
  `${resident?.first_name || ''} ${resident?.last_name || ''}`.trim() || 'Unknown Head of the Family'

export const isWalkIn = (item) => !item?.resident && !item?.resident_id

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
