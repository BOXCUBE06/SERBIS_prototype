import { computed, ref } from 'vue'
import { API_BASE } from '@/config/api'
import { requestBarangayName } from '@/composables/requestDisplay'
import { matchesTransaction } from '@/composables/transactionSearch'

export function useFilteredRequestList(requests, filters, search, { requesterName, secondaryFn, decorate }) {
  // The full list, so a barangay with no requests yet is still pickable. The
  // endpoint is public; on failure the options fall back to the loaded rows.
  const allBarangays = ref([])
  fetch(`${API_BASE}/barangays`)
    .then(r => (r.ok ? r.json() : []))
    .then(rows => { allBarangays.value = rows.map(b => b.barangay_name) })
    .catch(() => {})

  const barangayOptions = computed(() => {
    const names = new Set([...allBarangays.value, ...requests.value.map(r => requestBarangayName(r)).filter(Boolean)])
    return ['All', ...Array.from(names).sort()]
  })

  // A tab wins; on All, the Status select (statusPick, Resident queue only) applies.
  const activeStatus = computed(() => (filters.status === 'All' ? (filters.statusPick || 'All') : filters.status))

  const requestCounts = computed(() => {
    const counts = { All: requests.value.length, Pending: 0, Booked: 0, Responding: 0, Resolved: 0, Disapproved: 0, Cancelled: 0 }
    for (const req of requests.value) {
      const status = req.status || 'Pending'
      if (counts[status] !== undefined) {counts[status]++}
    }
    return counts
  })

  const filteredAndSortedRequests = computed(() => {
    const searchLower = search.value.toLowerCase()
    const currentStatus = activeStatus.value

    return requests.value.filter(r => {
      if (currentStatus !== 'All' && (r.status || 'Pending') !== currentStatus) {return false}

      if (filters.barangay !== 'All' && requestBarangayName(r) !== filters.barangay) {return false}

      if (filters.unit !== 'All') {
        const unit = r.vehicle?.unit_identifier || ''
        if (filters.unit === 'Unassigned' ? unit : unit !== filters.unit) {return false}
      }

      if (!searchLower) {return true}
      return requesterName(r).toLowerCase().includes(searchLower) ||
             (r.service?.service_name || '').toLowerCase().includes(searchLower) ||
             requestBarangayName(r).toLowerCase().includes(searchLower) ||
             (r.resident?.phone_number || r.walk_in_contact_number || '').includes(searchLower) ||
             matchesTransaction(r.request_id, search.value)
    }).map(r => ({
      ...r,
      _requesterName: requesterName(r),
      _secondary: secondaryFn(r),
      _unit: r.vehicle?.unit_identifier || '',
      ...decorate?.(r),
    })).slice().sort((a, b) => {
      const statusA = a.status || 'Pending', statusB = b.status || 'Pending'
      if (statusA === 'Pending' && statusB !== 'Pending') {return -1}
      if (statusB === 'Pending' && statusA !== 'Pending') {return 1}
      // Newest first within a tier: a request just filed must show at the top,
      // or staff think the submit did nothing. The wait-days chip still flags
      // the old ones.
      return new Date(b.created_at) - new Date(a.created_at)
    })
  })

  const emptyListMessage = computed(() => {
    if (search.value) {return `No requests match "${search.value}"`}
    if (activeStatus.value !== 'All') {return `No ${activeStatus.value.toLowerCase()} requests`}
    return 'No requests yet'
  })

  const activeFilters = computed(() => {
    const out = []
    if (activeStatus.value !== 'All') {out.push({ key: 'status', label: `Status: ${activeStatus.value}` })}
    if (filters.barangay !== 'All') {out.push({ key: 'barangay', label: `Barangay: ${filters.barangay}` })}
    if (filters.unit !== 'All') {out.push({ key: 'unit', label: `Unit: ${filters.unit}` })}
    return out
  })

  const clearFilter = (key) => {
    switch (key) {
      case 'status': { filters.status = 'All'; filters.statusPick = 'All'; break }
      case 'barangay': { filters.barangay = 'All'; break }
      case 'unit': { filters.unit = 'All'; break }
    }
  }

  const clearAllFilters = () => {
    filters.status = 'All'
    filters.statusPick = 'All'
    filters.barangay = 'All'
    filters.unit = 'All'
  }

  return { barangayOptions, requestCounts, filteredAndSortedRequests, emptyListMessage, activeFilters, clearFilter, clearAllFilters }
}
