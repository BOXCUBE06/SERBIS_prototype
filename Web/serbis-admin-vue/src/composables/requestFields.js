// What can be printed or exported, per list. One catalog feeds the customize
// dialog, the print layout and the CSV/XLSX writer, so a column added here
// shows up in all three.
//
// Field groups drive the detail print: `time` and `actor` fields are always
// printed there (every timestamp and who did it), the rest follow the chosen
// columns. Reference numbers (Transaction No., Trip No.) are on by default;
// internal foreign-key ids are `off` until someone ticks them.
import { displayPhone } from '@/composables/phoneNumber'
import { isWalkIn, requesterName, transactionNo, borrowingTransactionNo } from '@/composables/requestDisplay'

const TZ = 'Asia/Manila'
const dateTimeFormat = new Intl.DateTimeFormat('en-PH', { timeZone: TZ, dateStyle: 'medium', timeStyle: 'short' })
const dateFormat = new Intl.DateTimeFormat('en-PH', { timeZone: TZ, dateStyle: 'medium' })
const dayFormat = new Intl.DateTimeFormat('en-CA', { timeZone: TZ }) // YYYY-MM-DD

export const manilaDateTime = (v) => (v ? dateTimeFormat.format(new Date(v)) : '')
// A bare `Y-m-d` column is a calendar date, not an instant: pin it to Manila so it never slides a day.
export const manilaDate = (v) => (v ? dateFormat.format(new Date(/^\d{4}-\d\d-\d\d$/.test(v) ? `${v}T00:00:00+08:00` : v)) : '')
export const manilaDay = (v) => (v ? dayFormat.format(new Date(v)) : '')

const adminName = (a) => (a ? `${a.first_name || ''} ${a.last_name || ''}`.trim() : '')
const phone = (r) => displayPhone(r.resident?.phone_number) || r.walk_in_contact_number || ''
const barangay = (r) => r.resident?.barangay?.barangay_name || (isWalkIn(r) ? 'Walk-in' : '')
const peopleOf = (r, role) => (r.conduction_requests?.[0]?.people || r.people || []).filter((p) => p.role === role).map((p) => p.name).join(', ')

// group: id | core | time | actor
const f = (key, label, get, group = 'core', off = false) => ({ key, label, get, group, off })

const requesterFields = [
  f('requester', 'Requester', requesterName),
  f('phone', 'Phone', phone),
  f('barangay', 'Barangay', barangay),
  f('address', 'Home address', (r) => r.resident?.street_address || '', 'core', true),
]

const requestFields = [
  f('request_id', 'Transaction No.', (r) => transactionNo(r.request_id), 'id'),
  f('status', 'Status', (r) => r.status || 'Pending'),
  f('service', 'Service', (r) => r.service?.service_name || 'Other'),
  ...requesterFields,
  f('description', 'Description', (r) => r.description || ''),
  f('preferred_date', 'Preferred date', (r) => manilaDate(r.preferred_date)),
  f('landmark', 'Landmark', (r) => r.landmark || '', 'core', true),
  f('fulfillment', 'Fulfillment', (r) => r.fulfillment_method || '', 'core', true),
  f('delivery_address', 'Delivery address', (r) => r.delivery_address || '', 'core', true),
  f('unit', 'Vehicle unit', (r) => r.vehicle?.unit_identifier || ''),
  f('unit_type', 'Vehicle type', (r) => r.vehicle?.type || '', 'core', true),
  f('responders', 'Responders', (r) => (r.responders || []).map((x) => x.name).join(', '), 'core', true),
  f('remarks', 'Remarks', (r) => r.remarks || ''),
  f('internal_notes', 'Internal notes', (r) => r.internal_notes || '', 'core', true),
  f('created_at', 'Submitted', (r) => manilaDateTime(r.created_at), 'time'),
  f('first_responded_at', 'First response', (r) => manilaDateTime(r.first_responded_at), 'time', true),
  f('resolved_at', 'Resolved / disapproved', (r) => manilaDateTime(r.resolved_at), 'time'),
  f('processed_by', 'Processed by', (r) => adminName(r.admin), 'actor'),
  f('resident_id', 'Resident ID (internal)', (r) => r.resident_id ?? '', 'id', true),
  f('vehicle_id', 'Vehicle ID (internal)', (r) => r.vehicle_id ?? '', 'id', true),
]

const requestPhotos = (r) => [
  r.has_valid_id && { label: 'Valid ID', path: `/service-requests/${r.request_id}/valid-id` },
  r.has_site_photo && { label: 'Landmark', path: `/service-requests/${r.request_id}/site-photo` },
  r.has_letter && { label: 'Request letter', path: `/service-requests/${r.request_id}/letter` },
]

const bookingFields = [
  f('request_id', 'Transaction No.', (r) => transactionNo(r.request_id), 'id'),
  f('status', 'Status', (r) => r.status || 'Pending'),
  ...requesterFields,
  f('patient_name', 'Patient', (r) => r.patient_name || ''),
  f('patient_age', 'Patient age', (r) => r.patient_age ?? ''),
  f('patient_address', 'Patient address', (r) => r.patient_address || '', 'core', true),
  f('patient_contact_number', 'Patient contact', (r) => r.patient_contact_number || '', 'core', true),
  f('pickup_location', 'Pickup', (r) => r.pickup_location || ''),
  f('destination', 'Destination', (r) => r.destination || ''),
  f('condition_notes', 'Condition notes', (r) => r.condition_notes || ''),
  f('unit', 'Ambulance unit', (r) => r.vehicle?.unit_identifier || ''),
  f('driver', 'Driver', (r) => peopleOf(r, 'driver'), 'core', true),
  f('responders', 'Responders', (r) => (r.responders || []).map((x) => x.name).join(', '), 'core', true),
  f('remarks', 'Remarks', (r) => r.remarks || ''),
  f('internal_notes', 'Internal notes', (r) => r.internal_notes || '', 'core', true),
  f('scheduled_at', 'Scheduled', (r) => manilaDateTime(r.scheduled_at), 'time'),
  f('scheduled_end', 'Scheduled end', (r) => manilaDateTime(r.scheduled_end), 'time', true),
  f('approved_at', 'Approved', (r) => manilaDateTime(r.approved_at), 'time'),
  f('created_at', 'Submitted', (r) => manilaDateTime(r.created_at), 'time'),
  f('resolved_at', 'Resolved / disapproved', (r) => manilaDateTime(r.resolved_at), 'time'),
  f('processed_by', 'Processed by', (r) => adminName(r.admin), 'actor'),
  f('resident_id', 'Resident ID (internal)', (r) => r.resident_id ?? '', 'id', true),
  f('vehicle_id', 'Vehicle ID (internal)', (r) => r.vehicle_id ?? '', 'id', true),
]

const borrowerName = (r) => `${r.resident?.first_name || ''} ${r.resident?.last_name || ''}`.trim()

const borrowingFields = [
  f('borrow_id', 'Transaction No.', (r) => borrowingTransactionNo(r.borrow_id), 'id'),
  f('status', 'Status', (r) => r.status),
  f('item', 'Item', (r) => r.equipment?.item_name || r.other_equipment_text || ''),
  f('quantity', 'Quantity', (r) => r.quantity),
  f('borrower', 'Borrower', borrowerName),
  f('borrower_type', 'Borrower type', (r) => r.borrower_type || '', 'core', true),
  f('organization_name', 'Organization', (r) => r.organization_name || ''),
  f('phone', 'Phone', (r) => displayPhone(r.resident?.phone_number)),
  f('barangay', 'Barangay', (r) => r.resident?.barangay?.barangay_name || ''),
  f('purpose', 'Purpose', (r) => r.purpose || ''),
  f('fulfillment', 'Fulfillment', (r) => r.fulfillment_method || '', 'core', true),
  f('delivery_address', 'Delivery address', (r) => r.delivery_address || '', 'core', true),
  f('return_condition', 'Return condition', (r) => r.return_condition || '', 'core', true),
  f('return_condition_note', 'Return note', (r) => r.return_condition_note || '', 'core', true),
  f('denial_reason', 'Denial reason', (r) => r.denial_reason || ''),
  f('created_at', 'Requested', (r) => manilaDateTime(r.created_at), 'time'),
  f('due_date', 'Due date', (r) => manilaDate(r.due_date), 'time'),
  f('released_at', 'Released', (r) => manilaDateTime(r.released_at), 'time'),
  f('returned_at', 'Returned', (r) => manilaDateTime(r.returned_at), 'time'),
  f('resident_id', 'Resident ID (internal)', (r) => r.resident_id ?? '', 'id', true),
  f('equipment_id', 'Equipment ID (internal)', (r) => r.equipment_id ?? '', 'id', true),
]

const tripFields = [
  f('conduction_request_id', 'Trip No.', (r) => r.conduction_request_id, 'id'),
  f('trip_status', 'Status', (r) => r.trip_status || ''),
  f('unit', 'Vehicle', (r) => r.vehicle || ''),
  f('patient_name', 'Patient', (r) => r.patient_name || ''),
  f('patient_age', 'Patient age', (r) => r.patient_age ?? ''),
  f('patient_address', 'Patient address', (r) => r.patient_address || '', 'core', true),
  f('patient_contact_number', 'Patient contact', (r) => r.patient_contact_number || ''),
  f('medical_diagnosis', 'Diagnosis', (r) => r.medical_diagnosis || ''),
  f('origin', 'From', (r) => r.origin || ''),
  f('destination', 'To', (r) => r.destination || ''),
  f('driver', 'Driver', (r) => peopleOf(r, 'driver')),
  f('passengers', 'Passengers', (r) => peopleOf(r, 'passenger'), 'core', true),
  f('relatives', 'Relatives', (r) => peopleOf(r, 'relative'), 'core', true),
  f('odometer_start', 'Odometer start', (r) => r.odometer_start ?? '', 'core', true),
  f('odometer_end', 'Odometer end', (r) => r.odometer_end ?? '', 'core', true),
  f('no_arrival_reason', 'No-arrival reason', (r) => r.no_arrival_reason || '', 'core', true),
  f('vehicle_override_reason', 'Vehicle override reason', (r) => r.vehicle_override_reason || '', 'core', true),
  f('others', 'Others', (r) => r.others || '', 'core', true),
  f('created_at', 'Logged', (r) => manilaDateTime(r.created_at), 'time'),
  f('departed_office_at', 'Departed office', (r) => manilaDateTime(r.departed_office_at), 'time'),
  f('arrived_destination_at', 'Arrived at destination', (r) => manilaDateTime(r.arrived_destination_at), 'time'),
  f('departed_destination_at', 'Departed destination', (r) => manilaDateTime(r.departed_destination_at), 'time'),
  f('returned_office_at', 'Returned to office', (r) => manilaDateTime(r.returned_office_at), 'time'),
  f('vehicle_id', 'Vehicle ID (internal)', (r) => r.vehicle_id ?? '', 'id', true),
  f('service_request_id', 'Booking No.', (r) => r.service_request_id ?? '', 'id', true),
]

const vehicleFields = [
  f('unit_identifier', 'Unit', (r) => r.unit_identifier),
  f('type', 'Type', (r) => r.type),
  f('specification', 'Specification', (r) => r.specification || ''),
  f('status', 'Status', (r) => r.status),
]

// approver: a function gives the name printed under "Approved by" (blank line
// when empty); `false` drops the line, for records nobody approves.
export const EXPORT_TYPES = {
  request: {
    title: 'Service Requests', noun: 'service request', file: 'service-requests',
    fields: requestFields, idOf: (r) => r.request_id, statusOf: (r) => r.status || 'Pending',
    dateOf: (r) => r.created_at, approver: (r) => adminName(r.admin), photos: requestPhotos,
  },
  booking: {
    title: 'Ambulance Bookings', noun: 'ambulance booking', file: 'ambulance-bookings',
    fields: bookingFields, idOf: (r) => r.request_id, statusOf: (r) => r.status || 'Pending',
    dateOf: (r) => r.scheduled_at || r.created_at, approver: (r) => adminName(r.admin),
    photos: (r) => [r.has_valid_id && { label: 'Valid ID', path: `/service-requests/${r.request_id}/valid-id` }],
  },
  borrowing: {
    title: 'Equipment Borrowing', noun: 'borrowing request', file: 'equipment-borrowing',
    fields: borrowingFields, idOf: (r) => r.borrow_id, statusOf: (r) => r.status,
    dateOf: (r) => r.created_at, approver: false,
    photos: (r) => [
      r.has_release_photo && { label: 'Release photo', path: `/borrowings/${r.borrow_id}/photo/release` },
      r.has_return_photo && { label: 'Return photo', path: `/borrowings/${r.borrow_id}/photo/return` },
    ],
  },
  trip: {
    title: 'Ambulance Trip Logs', noun: 'trip record', file: 'trip-logs',
    fields: tripFields, idOf: (r) => r.conduction_request_id, statusOf: (r) => r.trip_status,
    dateOf: (r) => r.departed_office_at || r.created_at, approver: () => '', photos: () => [],
  },
  vehicle: {
    title: 'Vehicles', noun: 'vehicle', file: 'vehicles',
    fields: vehicleFields, idOf: (r) => r.vehicle_id, statusOf: (r) => r.status,
    dateOf: (r) => r.created_at, approver: false, photos: () => [],
  },
}

export const defaultColumns = (type) => EXPORT_TYPES[type].fields.filter((x) => !x.off).map((x) => x.key)

// The chosen columns, in catalog order (not click order) so every output reads the same.
export const columnsFor = (type, keys) => EXPORT_TYPES[type].fields.filter((x) => keys.includes(x.key))

const SORTS = {
  newest: (t) => (a, b) => new Date(t.dateOf(b) || 0) - new Date(t.dateOf(a) || 0),
  oldest: (t) => (a, b) => new Date(t.dateOf(a) || 0) - new Date(t.dateOf(b) || 0),
  status: (t) => (a, b) => String(t.statusOf(a)).localeCompare(String(t.statusOf(b))) || new Date(t.dateOf(b) || 0) - new Date(t.dateOf(a) || 0),
  none: () => () => 0, // keep the list's own order
  id: (t) => (a, b) => Number(t.idOf(a)) - Number(t.idOf(b)),
}
export const SORT_OPTIONS = [
  { value: 'newest', title: 'Newest first' },
  { value: 'oldest', title: 'Oldest first' },
  { value: 'status', title: 'By status' },
  { value: 'id', title: 'By reference number' },
]

/** Rows inside the Manila date range (either end optional), in the chosen order. */
export function prepareRows(type, rows, { sort = 'newest', from = '', to = '' } = {}) {
  const t = EXPORT_TYPES[type]
  const inRange = (r) => {
    if (!from && !to) { return true }
    const day = manilaDay(t.dateOf(r))
    return !!day && (!from || day >= from) && (!to || day <= to)
  }

  return rows.filter((r) => inRange(r)).slice().sort(SORTS[sort](t))
}

/** `serbis-<type>-<range or today>.<ext>`, so a file says what it holds without opening it. */
export function exportFilename(type, { from = '', to = '' } = {}, ext) {
  const span = from || to ? `${from || 'start'}_to_${to || 'today'}` : manilaDay(new Date())

  return `serbis-${EXPORT_TYPES[type].file}-${span}.${ext}`
}
