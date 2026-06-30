<template>
  <v-container fluid class="bg-grey-lighten-4 fill-height align-start pa-8" style="background-color: #F4F7FC !important;">
    <v-row>
      <v-col cols="12">
        <div class="d-flex justify-space-between align-center mb-6">
          <div>
            <h2 class="text-h5 font-weight-bold text-grey-darken-3">Fleet Management</h2>
            <div class="text-subtitle-2 text-grey">Monitor unit availability and dispatch status</div>
          </div>
          
          <v-btn color="primary" variant="tonal" rounded="lg" class="text-none font-weight-bold">
            <v-icon start>mdi-plus</v-icon> Add Unit
          </v-btn>
        </div>

        <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6" density="compact" rounded="lg">
          {{ apiError }}
        </v-alert>

        <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-6"></v-progress-linear>

        <!-- Outer Box Grouping -->
        <v-card 
          v-for="(units, type) in groupedVehicles" 
          :key="type" 
          elevation="0" 
          border 
          rounded="xl" 
          class="mb-6 pa-6 bg-white border-grey-lighten-3"
        >
          <!-- Removed bottom border, increased text size, redesigned unit count -->
          <div class="d-flex align-baseline mb-4">
            <h3 class="text-h4 font-weight-black text-grey-darken-4 mr-4">{{ type }}s</h3>
            <span class="text-h6 text-primary font-weight-bold">
              {{ units.length }} Unit{{ units.length !== 1 ? 's' : '' }}
            </span>
          </div>

          <v-row>
            <v-col v-for="vehicle in units" :key="vehicle.id || vehicle.vehicle_id" cols="12" sm="6" md="4" lg="3">
              <v-card 
                elevation="1" 
                rounded="lg" 
                :class="getCardTint(vehicle.status)"
                class="transition-swing border"
              >
                <v-card-text class="pa-4">
                  <div class="d-flex justify-space-between align-start mb-2">
                    <div>
                      <div class="text-h6 font-weight-bold">{{ vehicle.unit_identifier }}</div>
                      <div class="text-caption text-grey-darken-2 font-weight-medium">{{ vehicle.specification || 'Standard Unit' }}</div>
                    </div>
                    <v-icon :color="getIconColor(vehicle.status)" size="large">
                      {{ getVehicleIcon(type) }}
                    </v-icon>
                  </div>

                  <!-- Inline Status Dropdown -->
                  <div class="mt-4">
                    <div class="text-caption font-weight-bold text-grey-darken-1 mb-1">CURRENT STATUS</div>
                    <v-select
                      v-model="vehicle.status"
                      :items="statusOptions"
                      variant="solo-filled"
                      density="compact"
                      hide-details
                      rounded="lg"
                      :bg-color="getSelectColor(vehicle.status)"
                      flat
                      @update:model-value="promptStatusChange(vehicle, $event)"
                    ></v-select>
                  </div>
                </v-card-text>
              </v-card>
            </v-col>
          </v-row>
        </v-card>
        
      </v-col>
    </v-row>

    <!-- Status Confirmation Dialog -->
    <v-dialog v-model="statusDialog.show" max-width="420" persistent>
      <v-card rounded="xl" elevation="10">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-grey-darken-3 d-flex align-center">
          <v-icon color="warning" class="mr-2">mdi-alert-circle-outline</v-icon>
          Confirm Status Update
        </v-card-title>
        
        <v-card-text class="pa-6 pt-2 text-body-1 text-grey-darken-1">
          Are you sure you want to change the status of 
          <strong class="text-grey-darken-4">{{ statusDialog.vehicle?.unit_identifier }}</strong> to 
          <strong :class="`text-${getIconColor(statusDialog.newStatus)}`">{{ statusDialog.newStatus }}</strong>?
        </v-card-text>
        
        <v-card-actions class="pa-6 pt-0 d-flex justify-end gap-2">
          <v-btn 
            color="grey-darken-1" 
            variant="text" 
            rounded="lg" 
            class="px-4 text-none font-weight-bold" 
            @click="cancelStatusChange" 
            :disabled="statusDialog.loading"
          >
            Cancel
          </v-btn>
          <v-btn 
            color="primary" 
            variant="flat" 
            rounded="lg" 
            class="px-6 text-none font-weight-bold" 
            @click="executeStatusChange" 
            :loading="statusDialog.loading"
          >
            Update Status
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
const statusOptions = ['Available', 'Dispatched', 'Maintenance']

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

const fetchVehicles = async () => {
  loading.value = true
  try {
    const res = await fetch('http://localhost:8000/api/vehicles', { headers: getHeaders() })
    const data = await res.json()
    if (!res.ok) throw new Error(data.message || 'Failed to fetch fleet data')
    
    vehicles.value = (Array.isArray(data) ? data : (data.data || [])).map(v => ({
      ...v,
      originalStatus: v.status 
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
    case 'dispatched': return 'bg-orange-lighten-5 border-orange-lighten-3'
    case 'maintenance': return 'bg-red-lighten-5 border-red-lighten-3'
    default: return 'bg-white border-grey-lighten-3'
  }
}

const getSelectColor = (status) => {
  switch(status?.toLowerCase()) {
    case 'dispatched': return 'orange-lighten-4'
    case 'maintenance': return 'red-lighten-4'
    default: return 'green-lighten-5'
  }
}

const getIconColor = (status) => {
  switch(status?.toLowerCase()) {
    case 'dispatched': return 'orange-darken-2'
    case 'maintenance': return 'red-darken-2'
    default: return 'green-darken-2'
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
.gap-2 { gap: 8px; }
.transition-swing {
  transition: all 0.3s cubic-bezier(0.25, 0.8, 0.5, 1);
}
</style>