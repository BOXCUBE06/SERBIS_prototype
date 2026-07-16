<template>
  <v-container fluid class="fill-height align-start px-6 px-md-10 pt-4 pb-10 page-background">
    <v-row>
      <v-col cols="12">
        <div class="d-flex justify-space-between align-center mb-6">
          <h2 class="text-h4 font-weight-bold text-high-emphasis tracking-tight">Fleet Management</h2>
          
          <v-btn 
            color="primary" 
            variant="flat" 
            rounded="xl" 
            class="px-6 text-none font-weight-bold btn-soft-shadow"
            height="48"
          >
            <v-icon start size="20">mdi-plus</v-icon> Add Unit
          </v-btn>
        </div>

        <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6 soft-alert" density="compact" rounded="xl">
          {{ apiError }}
        </v-alert>

        <template v-if="loading">
          <v-card elevation="0" rounded="xl" class="mb-8 pa-8 group-card">
            <v-skeleton-loader type="heading" width="200" class="mb-6 bg-transparent"></v-skeleton-loader>
            <v-row>
              <v-col v-for="n in 4" :key="n" cols="12" sm="6" md="4" lg="3">
                <v-skeleton-loader 
                  type="list-item-avatar, text, text" 
                  elevation="0" 
                  class="rounded-xl border vehicle-card"
                ></v-skeleton-loader>
              </v-col>
            </v-row>
          </v-card>
        </template>

        <template v-else>
          <v-card 
            v-for="(units, type) in groupedVehicles" 
            :key="type" 
            elevation="0" 
            rounded="xl" 
            class="mb-8 pa-8 group-card"
          >
            <div class="d-flex align-center mb-6">
              <h3 class="text-h4 font-weight-black text-high-emphasis mr-5 text-capitalize tracking-tight">{{ type }}s</h3>
              <v-chip
                color="primary"
                variant="tonal"
                size="large"
                class="font-weight-bold px-4 text-body-1"
                rounded="lg"
              >
                {{ units.length }} Unit{{ units.length !== 1 ? 's' : '' }}
              </v-chip>
            </div>

            <v-row>
              <v-col v-for="vehicle in units" :key="vehicle.id || vehicle.vehicle_id" cols="12" sm="6" md="4" lg="3">
                <v-card 
                  elevation="0" 
                  rounded="xl" 
                  :class="['vehicle-card', getCardTint(vehicle.status)]"
                >
                  <v-card-text class="pa-5">
                    <div class="d-flex justify-space-between align-start mb-3">
                      <div>
                        <div class="text-h6 font-weight-bold text-high-emphasis">{{ vehicle.unit_identifier }}</div>
                        <div class="text-body-2 text-medium-emphasis font-weight-medium mt-1">{{ vehicle.specification || 'Standard Unit' }}</div>
                      </div>
                      <div :class="['icon-wrapper', getIconBgColor(vehicle.status)]">
                        <v-icon :color="getIconColor(vehicle.status)" size="24">
                          {{ getVehicleIcon(type) }}
                        </v-icon>
                      </div>
                    </div>

                    <div class="mt-5">
                      <div class="text-overline font-weight-bold text-medium-emphasis mb-2 tracking-widest">Status</div>
                      <v-select
                        v-model="vehicle.status"
                        :items="statusOptions"
                        variant="flat"
                        density="comfortable"
                        hide-details
                        rounded="lg"
                        class="status-select"
                        :class="getSelectClass(vehicle.status)"
                        @update:model-value="promptStatusChange(vehicle, $event)"
                      >
                        <template v-slot:selection="{ item }">
                          <span class="font-weight-bold text-uppercase" :class="`text-${getIconColor(item.value)}`">
                            {{ item.title }}
                          </span>
                        </template>
                      </v-select>
                    </div>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>
          </v-card>
        </template>
        
      </v-col>
    </v-row>

    <v-dialog v-model="statusDialog.show" max-width="420" persistent>
      <v-card class="soft-dialog pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis d-flex align-center">
          <div class="icon-wrapper bg-warning-lighten-5 mr-4">
            <v-icon color="warning" size="24">mdi-alert-outline</v-icon>
          </div>
          Update Status
        </v-card-title>
        
        <v-card-text class="px-6 py-4 text-body-1 text-medium-emphasis">
          Are you sure you want to change the status of 
          <span class="font-weight-bold text-high-emphasis">{{ statusDialog.vehicle?.unit_identifier }}</span> to 
          <span class="font-weight-bold text-uppercase" :class="`text-${getIconColor(statusDialog.newStatus)}`">{{ statusDialog.newStatus }}</span>?
        </v-card-text>
        
        <v-card-actions class="pa-6 pt-2 d-flex justify-end gap-3">
          <v-btn 
            color="grey-darken-2" 
            variant="text" 
            rounded="lg" 
            class="px-5 text-none font-weight-medium" 
            @click="cancelStatusChange" 
            :disabled="statusDialog.loading"
          >
            Cancel
          </v-btn>
          <v-btn 
            color="primary" 
            variant="flat" 
            rounded="lg" 
            class="px-6 text-none font-weight-bold btn-soft-shadow" 
            @click="executeStatusChange" 
            :loading="statusDialog.loading"
          >
            Confirm
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'

const vehicles = ref([])
const loading = ref(false)
const apiError = ref('')
const statusOptions = [
  { title: 'Available', value: 'Available' },
  { title: 'Dispatched', value: 'Dispatched' },
  { title: 'Maintenance', value: 'Maintenance' }
]

const statusDialog = ref({
  show: false,
  vehicle: null,
  newStatus: '',
  loading: false
})

const getHeaders = () => ({
  'Authorization': `Bearer ${localStorage.getItem('serbis_token')}`,
  'Content-Type': 'application/json',
  'Accept': 'application/json'
})

const formatStatus = (status) => {
  if (!status) return 'Available'
  const s = status.toLowerCase()
  if (s === 'dispatched') return 'Dispatched'
  if (s === 'maintenance') return 'Maintenance'
  return 'Available'
}

const fetchVehicles = async () => {
  loading.value = true
  try {
    const res = await fetch('http://localhost:8000/api/vehicles', { headers: getHeaders() })
    const data = await res.json()
    if (!res.ok) throw new Error(data.message || 'Failed to fetch fleet data')
    
    vehicles.value = (Array.isArray(data) ? data : (data.data || [])).map(v => ({
      ...v,
      status: formatStatus(v.status),
      originalStatus: formatStatus(v.status) 
    }))
  } catch (error) {
    apiError.value = error.message
    vehicles.value = []
  } finally {
    loading.value = false
  }
}

const promptStatusChange = (vehicle, newStatus) => {
  statusDialog.value = {
    show: true,
    vehicle: vehicle,
    newStatus: newStatus,
    loading: false
  }
}

const cancelStatusChange = () => {
  if (statusDialog.value.vehicle) {
    statusDialog.value.vehicle.status = statusDialog.value.vehicle.originalStatus
  }
  statusDialog.value.show = false
}

const executeStatusChange = async () => {
  const vehicle = statusDialog.value.vehicle
  statusDialog.value.loading = true
  apiError.value = ''
  
  const id = vehicle.id || vehicle.vehicle_id

  try {
    const res = await fetch(`http://localhost:8000/api/vehicles/${id}`, {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify({ status: vehicle.status })
    })

    if (!res.ok) throw new Error('Failed to update status')
    
    vehicle.originalStatus = vehicle.status
    statusDialog.value.show = false
  } catch (error) {
    apiError.value = `Error updating ${vehicle.unit_identifier}: ${error.message}`
    vehicle.status = vehicle.originalStatus
    statusDialog.value.show = false
  } finally {
    statusDialog.value.loading = false
  }
}

const groupedVehicles = computed(() => {
  return vehicles.value.reduce((acc, vehicle) => {
    const type = vehicle.type || 'Other'
    if (!acc[type]) acc[type] = []
    acc[type].push(vehicle)
    return acc
  }, {})
})

const getCardTint = (status) => {
  switch(status?.toLowerCase()) {
    case 'dispatched': return 'status-card-dispatched'
    case 'maintenance': return 'status-card-maintenance'
    default: return 'status-card-available'
  }
}

const getSelectClass = (status) => {
  switch(status?.toLowerCase()) {
    case 'dispatched': return 'select-dispatched'
    case 'maintenance': return 'select-maintenance'
    default: return 'select-available'
  }
}

const getIconBgColor = (status) => {
  switch(status?.toLowerCase()) {
    case 'dispatched': return 'bg-orange-lighten-5'
    case 'maintenance': return 'bg-red-lighten-5'
    default: return 'bg-green-lighten-5'
  }
}

const getIconColor = (status) => {
  switch(status?.toLowerCase()) {
    case 'dispatched': return 'orange-darken-3'
    case 'maintenance': return 'red-darken-3'
    default: return 'primary'
  }
}

const getVehicleIcon = (type) => {
  switch(type?.toLowerCase()) {
    case 'ambulance': return 'mdi-ambulance'
    case 'fire truck': return 'mdi-fire-truck'
    case 'rescue vehicle': return 'mdi-car-emergency'
    case 'boat': return 'mdi-ferry'
    default: return 'mdi-car'
  }
}

onMounted(() => fetchVehicles())
</script>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap');

* {
  font-family: 'Inter', sans-serif;
}

.page-background {
  background-color: rgb(var(--v-theme-background)) !important;
}

.tracking-tight { letter-spacing: -0.02em; }
.tracking-widest { letter-spacing: 0.1em; }
.gap-3 { gap: 12px; }

.btn-soft-shadow {
  box-shadow: 0 8px 16px -4px rgba(46, 125, 50, 0.25) !important;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.btn-soft-shadow:hover {
  transform: translateY(-2px);
  box-shadow: 0 12px 20px -4px rgba(46, 125, 50, 0.3) !important;
}

.group-card {
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08) !important;
  box-shadow: 0 12px 40px -12px rgba(var(--v-theme-on-surface), 0.04) !important;
}

.vehicle-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12) !important;
  transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease !important;
  box-shadow: 0 4px 12px -4px rgba(var(--v-theme-on-surface), 0.03) !important;
}

.vehicle-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 16px 32px -8px rgba(var(--v-theme-on-surface), 0.08) !important;
  border-color: rgba(var(--v-theme-on-surface), 0.2) !important;
}

/* Status tints were near-white hexes that read as "white card" on a dark
   theme. Tint the status colour over the surface instead, so the cue survives
   both themes. */
.status-card-available { background-color: rgb(var(--v-theme-surface)) !important; }
.status-card-dispatched { background-color: rgba(var(--v-theme-warning), 0.08) !important; border-color: rgba(var(--v-theme-warning), 0.3) !important; }
.status-card-maintenance { background-color: rgba(var(--v-theme-error), 0.08) !important; border-color: rgba(var(--v-theme-error), 0.25) !important; }

.status-select :deep(.v-field) {
  box-shadow: none !important;
  transition: background-color 0.2s ease;
}
.select-available :deep(.v-field) { background-color: rgba(var(--v-theme-primary), 0.10) !important; }
.select-dispatched :deep(.v-field) { background-color: rgba(var(--v-theme-warning), 0.14) !important; }
.select-maintenance :deep(.v-field) { background-color: rgba(var(--v-theme-error), 0.14) !important; }

.icon-wrapper {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.soft-dialog {
  border-radius: 20px !important;
  box-shadow: 0 24px 60px -12px rgba(0, 0, 0, 0.2) !important;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.1) !important;
}
.soft-alert {
  box-shadow: 0 8px 16px -4px rgba(var(--v-theme-error), 0.1) !important;
}

.v-skeleton-loader {
  background: rgba(var(--v-theme-on-surface), 0.04) !important;
}
</style>