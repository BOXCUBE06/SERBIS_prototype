const csvCell = (value) => `"${String(value ?? '').replace(/"/g, '""')}"`

export function buildRequestsCsv(rows, { itemId, requesterName, isWalkIn, requesterPhone, vehicleName, formatDateTime }) {
  const header = ['Request ID', 'Head of the Family', 'Barangay', 'Phone', 'Service', 'Status', 'Vehicle', 'Submitted', 'Remarks', 'Description']
  const body = rows.map(r => [
    itemId(r),
    requesterName(r),
    r.resident?.barangay?.barangay_name || (isWalkIn(r) ? 'Walk-in' : ''),
    requesterPhone(r),
    r.service?.service_name || '',
    r.status || 'Pending',
    r.vehicle ? vehicleName(r.vehicle) : '',
    formatDateTime(r.created_at),
    r.remarks || '',
    r.description || '',
  ])

  return '﻿' + [header, ...body].map(row => row.map((cell) => csvCell(cell)).join(',')).join('\r\n')
}

export function downloadCsv(csv, filename) {
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }))
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}
