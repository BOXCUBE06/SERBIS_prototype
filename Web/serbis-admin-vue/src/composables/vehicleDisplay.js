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
