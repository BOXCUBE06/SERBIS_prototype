<template>
  <v-container fluid class="bg-grey-lighten-4 fill-height align-start pa-8"
    style="background-color: #F4F7FC !important;">
    <v-row>
      <v-col cols="12">

        <v-row class="mb-4">
  <v-col cols="12" md="3">
    <v-card elevation="1" rounded="xl" class="pa-4 border-0 d-flex align-center">
      <v-avatar color="orange-lighten-5" size="50" class="mr-4 text-orange-darken-2">
        <v-icon>mdi-alert-circle-outline</v-icon>
      </v-avatar>
      <div>
        <div class="text-subtitle-2 text-grey-darken-1 font-weight-medium">Pending</div>
        <div class="text-h5 font-weight-bold">{{ pendingRequests }}</div>
      </div>
    </v-card>
  </v-col>
  <v-col cols="12" md="3">
    <v-card elevation="1" rounded="xl" class="pa-4 border-0 d-flex align-center">
      <v-avatar color="blue-lighten-5" size="50" class="mr-4 text-blue-darken-2">
        <v-icon>mdi-car-emergency</v-icon>
      </v-avatar>
      <div>
        <div class="text-subtitle-2 text-grey-darken-1 font-weight-medium">Responding</div>
        <div class="text-h5 font-weight-bold">{{ respondingRequests }}</div>
      </div>
    </v-card>
  </v-col>
  <v-col cols="12" md="3">
    <v-card elevation="1" rounded="xl" class="pa-4 border-0 d-flex align-center">
      <v-avatar color="green-lighten-5" size="50" class="mr-4 text-green-darken-2">
        <v-icon>mdi-check-circle-outline</v-icon>
      </v-avatar>
      <div>
        <div class="text-subtitle-2 text-grey-darken-1 font-weight-medium">Resolved</div>
        <div class="text-h5 font-weight-bold">{{ resolvedRequests }}</div>
      </div>
    </v-card>
  </v-col>
  <!-- 4th dark card to match the Available Vehicles card -->
  <v-col cols="12" md="3">
    <v-card elevation="1" rounded="xl" class="pa-4 border-0 d-flex align-center" color="#113F36">
      <v-avatar color="rgba(255,255,255,0.1)" size="50" class="mr-4">
        <v-icon color="white">mdi-clipboard-list-outline</v-icon>
      </v-avatar>
      <div>
        <div class="text-subtitle-2 text-white font-weight-medium">Total Requests</div>
        <div class="text-h5 font-weight-bold text-white">{{ pendingRequests + respondingRequests + resolvedRequests }}</div>
      </div>
    </v-card>
  </v-col>
</v-row>

        <v-card elevation="2" rounded="xl" class="pa-6 border-0">

          <v-row class="mb-6" align="center" justify="space-between">
            <v-col cols="12" md="4">
              <h2 class="text-h5 font-weight-bold text-grey-darken-3">Dispatch & Requests</h2>
              <div class="text-subtitle-2 text-grey">Prioritized queue for emergency and service requests</div>
            </v-col>

            <v-col cols="12" md="8" class="d-flex gap-4 justify-end">
              <v-text-field v-model="search" prepend-inner-icon="mdi-magnify"
                placeholder="Search resident, service, or barangay..." variant="outlined" density="compact" hide-details
                rounded="lg" bg-color="white" class="max-w-xs"></v-text-field>

              <v-select v-model="filters.status"
                :items="['All', 'Pending', 'Responding', 'Resolved', 'Denied', 'Cancelled']" label="Filter Status"
                variant="outlined" density="compact" hide-details rounded="lg" bg-color="white"
                class="max-w-xs"></v-select>
            </v-col>
          </v-row>

          <v-data-table :headers="headers" :items="filteredAndSortedRequests" :search="search" :items-per-page="10"
            hover class="custom-table" @click:row="openProcessModal">
            <template v-slot:item.resident_name="{ item }">
              <div class="font-weight-bold text-grey-darken-3">
                {{ item.resident?.last_name }}, {{ item.resident?.first_name }}
              </div>
              <div class="text-caption text-grey-darken-1">{{ item.resident?.barangay?.barangay_name || 'N/A' }}</div>
            </template>

            <template v-slot:item.service_name="{ item }">
              <span class="font-weight-medium">{{ item.service?.service_name || 'N/A' }}</span>
            </template>

            <template v-slot:item.created_at="{ item }">
              <span class="text-grey-darken-2">{{ new Date(item.created_at).toLocaleString() }}</span>
            </template>

            <template v-slot:item.status="{ item }">
              <v-chip :color="getStatusColor(item.status)" size="small" label variant="flat"
                class="text-uppercase font-weight-bold px-3 text-white">
                {{ item.status || 'Pending' }}
              </v-chip>
            </template>
          </v-data-table>
        </v-card>
      </v-col>
    </v-row>

    <v-dialog v-model="modal.isOpen" max-width="900" persistent>
      <v-card rounded="xl" elevation="10">
        <v-card-title class="d-flex justify-space-between align-center pa-6 border-b bg-grey-lighten-4">
          <div class="d-flex align-center gap-3">
            <span class="text-h6 font-weight-bold text-grey-darken-3">Request Details</span>
            <v-chip :color="getStatusColor(selectedRequest?.status)" size="small" label
              class="text-uppercase font-weight-bold text-white">
              {{ selectedRequest?.status || 'Pending' }}
            </v-chip>
          </div>
          <v-btn icon="mdi-close" variant="text" size="small" color="grey" @click="closeModal"></v-btn>
        </v-card-title>

        <v-card-text class="pa-0">
          <v-row class="ma-0 h-100">
            <v-col cols="12" md="4" class="bg-grey-lighten-5 pa-6 border-e">
              <div class="d-flex flex-column align-center mb-6">
                <v-avatar color="blue-lighten-4" size="80" class="mb-3">
                  <span class="text-h4 font-weight-bold text-blue-darken-2">
                    {{ selectedRequest?.resident?.first_name?.charAt(0) }}{{
                      selectedRequest?.resident?.last_name?.charAt(0)
                    }}
                  </span>
                </v-avatar>
                <div class="text-h6 font-weight-bold text-center">{{ selectedRequest?.resident?.first_name }} {{
                  selectedRequest?.resident?.last_name }}</div>
                <div class="text-caption text-grey-darken-1 text-uppercase">{{ selectedRequest?.resident?.status ||
                  'Active'
                  }} Resident</div>
              </div>

              <v-divider class="mb-4"></v-divider>

              <div class="mb-3">
                <div class="text-caption text-grey">Barangay</div>
                <div class="font-weight-medium">{{ selectedRequest?.resident?.barangay?.barangay_name || 'N/A' }}</div>
              </div>
              <div class="mb-3">
                <div class="text-caption text-grey">Phone Number</div>
                <div class="font-weight-medium">{{ selectedRequest?.resident?.phone_number || 'N/A' }}</div>
              </div>
              <div class="mb-3">
                <div class="text-caption text-grey">Email Address</div>
                <div class="font-weight-medium">{{ selectedRequest?.resident?.email_address || 'N/A' }}</div>
              </div>
            </v-col>

            <v-col cols="12" md="8" class="pa-6">
              <v-alert v-if="apiError" type="error" variant="tonal" class="mb-4" density="compact" rounded="lg">
                {{ apiError }}
              </v-alert>

              <h3 class="text-subtitle-1 font-weight-bold mb-4 text-primary">Incident / Request Information</h3>

              <v-row class="mb-4">
                <v-col cols="12" sm="6">
                  <div class="text-caption text-grey">Service Required</div>
                  <div class="font-weight-bold text-body-1">{{ selectedRequest?.service?.service_name }}</div>
                </v-col>
                <v-col cols="12" sm="6">
                  <div class="text-caption text-grey">Date Submitted</div>
                  <div class="font-weight-medium">{{ new Date(selectedRequest?.created_at).toLocaleString() }}</div>
                </v-col>
              </v-row>

              <div class="mb-4">
                <div class="text-caption text-grey mb-1">Description</div>
                <v-card variant="tonal" color="grey" class="pa-4 text-body-2 rounded-lg">
                  {{ selectedRequest?.description || 'No description provided.' }}
                </v-card>
              </div>

              <div class="mb-6" v-if="selectedRequest?.valid_id">
                <div class="text-caption text-grey mb-1">Attached Evidence / Valid ID</div>
                <v-img :src="`http://localhost:8000/storage/${selectedRequest.valid_id}`" max-height="200"
                  class="bg-grey-lighten-2 rounded-lg border"></v-img>
              </div>

              <v-divider class="mb-6"></v-divider>

              <div v-if="selectedRequest?.status === 'Pending' || !selectedRequest?.status">
                <v-select v-model="formData.vehicle_id" :items="availableVehicles" item-title="plate_number"
                  item-value="vehicle_id" label="Assign Vehicle for Dispatch" variant="outlined" density="comfortable"
                  rounded="lg" color="primary" bg-color="white" persistent-hint
                  hint="Select an available vehicle to approve this request.">
                  <template v-slot:item="{ props, item }">
                    <v-list-item v-bind="props" :title="item.raw.plate_number" :subtitle="item.raw.type"></v-list-item>
                  </template>
                </v-select>
              </div>

              <div v-else-if="selectedRequest?.status === 'Responding'" class="mb-4">
                <v-alert type="info" variant="tonal" border="start" rounded="lg">
                  <strong>Currently Dispatched:</strong> Vehicle {{ selectedRequest?.vehicle?.plate_number || 'Unknown'
                  }}
                  ({{ selectedRequest?.vehicle?.type || 'Unit' }})
                </v-alert>
              </div>

              <v-textarea v-if="selectedRequest?.status === 'Pending' || selectedRequest?.status === 'Responding'"
                v-model="formData.remarks" label="Admin Remarks (Optional)" variant="outlined" density="compact"
                rounded="lg" rows="2" hide-details class="mt-2"></v-textarea>

            </v-col>
          </v-row>
        </v-card-text>

        <v-card-actions class="pa-6 pt-0 d-flex justify-end bg-grey-lighten-4 border-t gap-3"
          v-if="selectedRequest?.status === 'Pending' || !selectedRequest?.status || selectedRequest?.status === 'Responding'">

          <template v-if="selectedRequest?.status === 'Pending' || !selectedRequest?.status">
            <v-btn color="error" variant="outlined" rounded="lg" class="px-6 text-none font-weight-bold" height="44"
              @click="updateStatus('Denied')" :loading="loading">
              Deny Request
            </v-btn>
            <v-btn color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" height="44"
              @click="updateStatus('Responding')" :loading="loading" :disabled="!formData.vehicle_id">
              Approve & Dispatch
            </v-btn>
          </template>

          <template v-if="selectedRequest?.status === 'Responding'">
            <v-btn color="success" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" height="44"
              @click="updateStatus('Resolved')" :loading="loading">
              Mark as Resolved
            </v-btn>
          </template>

        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'

const headers = [
  { title: 'Resident & Location', key: 'resident_name' },
  { title: 'Service Type', key: 'service_name' },
  { title: 'Date & Time', key: 'created_at' },
  { title: 'Status', key: 'status', align: 'center' }
]

const requests = ref([])
const vehicles = ref([])
const search = ref('')
const loading = ref(false)
const apiError = ref('')

const filters = ref({
  status: 'All'
})

const modal = ref({
  isOpen: false
})

const selectedRequest = ref(null)

const formData = ref({
  remarks: '',
  vehicle_id: null
})

// Metrics Computations
const pendingRequests = computed(() => requests.value.filter(r => !r.status || r.status === 'Pending').length)
const respondingRequests = computed(() => requests.value.filter(r => r.status === 'Responding').length)
const resolvedRequests = computed(() => requests.value.filter(r => r.status === 'Resolved').length)
const availableVehicles = computed(() => vehicles.value.filter(v => v.status === 'Available'))

// Filter & Sort Logic (Prioritize Pending)
const filteredAndSortedRequests = computed(() => {
  let result = requests.value

  if (filters.value.status !== 'All') {
    result = result.filter(r => (r.status || 'Pending') === filters.value.status)
  }

  if (search.value) {
    const searchLower = search.value.toLowerCase()
    result = result.filter(r => {
      const residentName = `${r.resident?.first_name} ${r.resident?.last_name}`.toLowerCase()
      const serviceName = (r.service?.service_name || '').toLowerCase()
      const barangay = (r.resident?.barangay?.barangay_name || '').toLowerCase()
      return residentName.includes(searchLower) || serviceName.includes(searchLower) || barangay.includes(searchLower)
    })
  }

  // Sort: Pending first, then by date (newest first)
  return result.sort((a, b) => {
    const statusA = a.status || 'Pending'
    const statusB = b.status || 'Pending'

    if (statusA === 'Pending' && statusB !== 'Pending') return -1
    if (statusB === 'Pending' && statusA !== 'Pending') return 1

    return new Date(b.created_at) - new Date(a.created_at)
  })
})

const getStatusColor = (status) => {
  switch (status) {
    case 'Pending': return 'warning'
    case 'Responding': return 'primary'
    case 'Resolved': return 'success'
    case 'Denied':
    case 'Cancelled': return 'error'
    default: return 'warning'
  }
}

const getHeaders = () => ({
  'Authorization': `Bearer ${localStorage.getItem('serbis_token')}`,
  'Content-Type': 'application/json',
  'Accept': 'application/json'
})

const fetchData = async () => {
  try {
    const [reqRes, vehRes] = await Promise.all([
      fetch('http://localhost:8000/api/admin/service-requests', { headers: getHeaders() }), // Updated path
      fetch('http://localhost:8000/api/vehicles', { headers: getHeaders() })
    ])

    const reqData = await reqRes.json()
    const vehData = await vehRes.json()

    requests.value = reqData.data || reqData
    vehicles.value = vehData.data || vehData
  } catch (error) {
    console.error('Failed to fetch data:', error)
  }
}

const openProcessModal = (event, { item }) => {
  apiError.value = ''
  selectedRequest.value = item

  formData.value = {
    remarks: item.remarks || '',
    vehicle_id: item.vehicle_id || null
  }

  modal.value.isOpen = true
}

const closeModal = () => {
  modal.value.isOpen = false
  selectedRequest.value = null
}

const updateStatus = async (newStatus) => {
  loading.value = true
  apiError.value = ''

  const id = selectedRequest.value.request_id || selectedRequest.value.id

  try {
    const res = await fetch(`http://localhost:8000/api/service-requests/${id}`, {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify({
        status: newStatus,
        remarks: formData.value.remarks,
        vehicle_id: formData.value.vehicle_id || selectedRequest.value.vehicle_id
      })
    })

    if (!res.ok) {
      const errData = await res.json()
      throw new Error(errData.message || 'Failed to update request')
    }

    // If dispatching, also update the vehicle status in the UI to prevent double booking before refresh
    if (newStatus === 'Responding' && formData.value.vehicle_id) {
      await fetch(`http://localhost:8000/api/vehicles/${formData.value.vehicle_id}`, {
        method: 'PUT',
        headers: getHeaders(),
        body: JSON.stringify({ status: 'Dispatched' })
      })
    }

    await fetchData()
    closeModal()
  } catch (error) {
    apiError.value = error.message
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchData()
})
</script>

<style scoped>
.gap-3 {
  gap: 12px;
}

.gap-4 {
  gap: 16px;
}

.custom-table :deep(th) {
  font-weight: 600 !important;
  color: #616161 !important;
  background-color: transparent !important;
  border-bottom: 2px solid #EEEEEE !important;
}

.custom-table :deep(td) {
  border-bottom: 1px solid #F5F5F5 !important;
  padding-top: 12px !important;
  padding-bottom: 12px !important;
}
</style>