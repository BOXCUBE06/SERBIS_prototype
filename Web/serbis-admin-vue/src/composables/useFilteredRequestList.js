import { computed } from 'vue'

export function useFilteredRequestList(requests, filters, search, { requesterName, secondaryFn, decorate }) {
  const barangayOptions = computed(() => {
    const names = new Set(requests.value.map(r => r.resident?.barangay?.barangay_name).filter(Boolean))
    return ['All', ...Array.from(names).slice().sort()]
  })

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
    const currentStatus = filters.status

    return requests.value.filter(r => {
      if (currentStatus !== 'All' && (r.status || 'Pending') !== currentStatus) {return false}

      if (filters.barangay !== 'All' && (r.resident?.barangay?.barangay_name || '') !== filters.barangay) {return false}

      if (filters.unit !== 'All') {
        const unit = r.vehicle?.unit_identifier || ''
        if (filters.unit === 'Unassigned' ? unit : unit !== filters.unit) {return false}
      }

      if (!searchLower) {return true}
      return requesterName(r).toLowerCase().includes(searchLower) ||
             (r.service?.service_name || '').toLowerCase().includes(searchLower) ||
             (r.resident?.barangay?.barangay_name || '').toLowerCase().includes(searchLower) ||
             (r.resident?.phone_number || r.walk_in_contact_number || '').includes(searchLower) ||
             String(r.request_id ?? '').toLowerCase().includes(searchLower)
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
      // Oldest first within a tier: the longest-waiting request is the one that
      // most needs attention, so it surfaces at the top rather than sinking to
      // the bottom of the page behind whatever was just filed.
      return new Date(a.created_at) - new Date(b.created_at)
    })
  })

  const emptyListMessage = computed(() => {
    if (search.value) {return `No requests match "${search.value}"`}
    if (filters.status !== 'All') {return `No ${filters.status.toLowerCase()} requests`}
    return 'No requests yet'
  })

  const activeFilters = computed(() => {
    const out = []
    if (filters.status !== 'All') {out.push({ key: 'status', label: `Status: ${filters.status}` })}
    if (filters.barangay !== 'All') {out.push({ key: 'barangay', label: `Barangay: ${filters.barangay}` })}
    if (filters.unit !== 'All') {out.push({ key: 'unit', label: `Unit: ${filters.unit}` })}
    return out
  })

  const clearFilter = (key) => {
    switch (key) {
      case 'status': { filters.status = 'All'; break }
      case 'barangay': { filters.barangay = 'All'; break }
      case 'unit': { filters.unit = 'All'; break }
    }
  }

  const clearAllFilters = () => {
    filters.status = 'All'
    filters.barangay = 'All'
    filters.unit = 'All'
  }

  return { barangayOptions, requestCounts, filteredAndSortedRequests, emptyListMessage, activeFilters, clearFilter, clearAllFilters }
}
