<template>
  <v-container fluid class="fill-height align-start pa-6" style="background-color: #F4F7FC !important;">
    <v-row class="ma-0 w-100">
      <v-col cols="12" class="pa-0 w-100">

        <!-- Metrics Row -->
        <v-row class="mb-6">
          <v-col v-for="(metric, index) in metrics" :key="index" cols="12" md="3">
            <v-skeleton-loader v-if="initialLoad" type="list-item-avatar-two-line" elevation="3"
              rounded="lg"></v-skeleton-loader>
            <v-card v-else elevation="3" rounded="lg" class="pa-4 d-flex align-center metric-card fade-in"
              :style="{ backgroundColor: metric.bgColor || '#ffffff' }">
              <v-avatar :color="metric.color" size="48" class="mr-4 rounded-lg">
                <v-icon size="28" :class="metric.iconColor">{{ metric.icon }}</v-icon>
              </v-avatar>
              <div>
                <div class="text-caption text-uppercase font-weight-bold"
                  :class="metric.textColor || 'text-grey-darken-1'">{{ metric.title }}</div>
                <div class="custom-metric-number mt-1" :class="metric.numberColor || 'text-grey-darken-4'">{{
                  metric.value }}</div>
              </div>
            </v-card>
          </v-col>
        </v-row>

        <v-card elevation="3" rounded="lg" class="bg-white w-100">
          <div class="px-6 py-2 border-b d-flex flex-row align-center justify-space-between gap-4">
            <div>
              <h2 class="text-h5 font-weight-bold text-grey-darken-4">Dispatch & Requests</h2>
            </div>

            <div class="d-flex gap-4 align-center">
              <v-text-field v-model="search" prepend-inner-icon="mdi-magnify"
                placeholder="Search resident, service, or barangay..." variant="outlined" density="compact" hide-details
                bg-color="white" style="width: 250px;"></v-text-field>

              <v-select v-model="filters.status"
                :items="['All', 'Pending', 'Responding', 'Resolved', 'Denied', 'Cancelled']" variant="outlined"
                density="compact" hide-details bg-color="white" style="width: 130px;"></v-select>
            </div>
          </div>

          <v-skeleton-loader v-if="initialLoad" type="table-tbody" class="pa-4"></v-skeleton-loader>

          <v-data-table 
  v-else
  :headers="headers" 
  :items="filteredAndSortedRequests" 
  :search="search" 
  :items-per-page="6"
  hover 
  class="elegant-table fade-in" 
  @click:row="openProcessModal"
>
            <template v-slot:item.resident_name="{ item }">
              <div class="font-weight-black text-grey-darken-4 text-h6">
                {{ item.resident?.last_name }}, {{ item.resident?.first_name }}
              </div>
              <div class="text-subtitle-2 text-grey-darken-1 mt-1">{{ item.resident?.barangay?.barangay_name || 'Unknown Barangay' }}
                </div>
            </template>

            <template v-slot:item.service_name="{ item }">
              <span class="font-weight-bold text-subtitle-1 text-grey-darken-3">{{ item.service?.service_name || 'N/A'
                }}</span>
            </template>

            <template v-slot:item.created_at="{ item }">
              <span class="text-body-1 font-weight-medium text-grey-darken-2">
                {{ new Date(item.created_at).toLocaleDateString(undefined, {
                  year: 'numeric', month: 'short', day:
                'numeric' }) }}
                <span class="text-caption text-grey ml-1">{{ new Date(item.created_at).toLocaleTimeString([], {
                  hour:
                    '2-digit', minute:'2-digit'}) }}</span>
              </span>
            </template>

            <template v-slot:item.status="{ item }">
              <v-chip :color="getStatusColor(item.status)" size="default" label variant="flat"
                class="text-uppercase font-weight-bold px-4 text-white">
                {{ item.status || 'Pending' }}
              </v-chip>
            </template>
          </v-data-table>
        </v-card>
      </v-col>
    </v-row>

    <!-- Main Request Modal -->
    <v-dialog v-model="modal.isOpen" max-width="900" persistent transition="dialog-fade-transition">
      <v-card rounded="lg" elevation="4">
        <v-card-title class="d-flex justify-space-between align-center pa-6 border-b bg-white">
          <div class="d-flex align-center gap-3">
            <span class="text-h6 font-weight-bold text-grey-darken-4">Request Details</span>
            <v-chip :color="getStatusColor(selectedRequest?.status)" size="small" label
              class="text-uppercase font-weight-bold text-white">
              {{ selectedRequest?.status || 'Pending' }}
            </v-chip>
          </div>
          <v-btn icon="mdi-close" variant="text" size="small" color="grey-darken-2" @click="closeModal"></v-btn>
        </v-card-title>

        <v-card-text class="pa-0">
          <v-row class="ma-0 h-100">
            <!-- Left Side: Resident Info -->
            <v-col cols="12" md="4" class="bg-grey-lighten-5 pa-6 border-e">
              <div class="d-flex flex-column align-center mb-6">
                <v-avatar color="blue-grey-lighten-4" size="80" class="mb-3">
                  <span class="text-h4 font-weight-black text-blue-grey-darken-3">
                    {{ selectedRequest?.resident?.first_name?.charAt(0) }}{{
                      selectedRequest?.resident?.last_name?.charAt(0)
                    }}
                  </span>
                </v-avatar>
                <div class="text-h6 font-weight-bold text-center text-grey-darken-4">{{
                  selectedRequest?.resident?.first_name }} {{ selectedRequest?.resident?.last_name }}</div>
                <div class="text-caption text-grey-darken-1 text-uppercase font-weight-bold mt-1">{{
                  selectedRequest?.resident?.status || 'Active' }} Resident</div>
              </div>

              <v-divider class="mb-4"></v-divider>

              <div class="mb-3">
                <div class="text-caption text-uppercase font-weight-bold text-grey-darken-1">Barangay</div>
                <div class="font-weight-medium text-body-1 text-grey-darken-4">{{
                  selectedRequest?.resident?.barangay?.barangay_name || 'N/A' }}</div>
              </div>
              <div class="mb-3">
                <div class="text-caption text-uppercase font-weight-bold text-grey-darken-1">Phone Number</div>
                <div class="font-weight-medium text-body-1 text-grey-darken-4">{{
                  selectedRequest?.resident?.phone_number ||
                  'N/A' }}</div>
              </div>
              <div class="mb-3">
                <div class="text-caption text-uppercase font-weight-bold text-grey-darken-1">Email Address</div>
                <div class="font-weight-medium text-body-1 text-grey-darken-4">{{
                  selectedRequest?.resident?.email_address
                  || 'N/A' }}</div>
              </div>
            </v-col>

            <!-- Right Side: Incident Info -->
            <v-col cols="12" md="8" class="pa-6 bg-white">
              <v-alert v-if="apiError" type="error" variant="tonal" class="mb-4" density="compact">
                {{ apiError }}
              </v-alert>

              <h3 class="text-subtitle-1 font-weight-bold mb-4 text-grey-darken-4 text-uppercase">Incident Information
              </h3>

              <v-row class="mb-4">
                <v-col cols="12" sm="6">
                  <div class="text-caption text-uppercase font-weight-bold text-grey-darken-1">Service Required</div>
                  <div class="font-weight-bold text-h6 text-grey-darken-4">{{ selectedRequest?.service?.service_name }}
                  </div>
                </v-col>
                <v-col cols="12" sm="6">
                  <div class="text-caption text-uppercase font-weight-bold text-grey-darken-1">Date Submitted</div>
                  <div class="font-weight-medium text-body-1 text-grey-darken-4">{{ new
                    Date(selectedRequest?.created_at).toLocaleString(undefined, {
                      dateStyle: 'medium', timeStyle:
                    'short' })
                    }}</div>
                </v-col>
              </v-row>

              <div class="mb-4">
                <div class="text-caption text-uppercase font-weight-bold text-grey-darken-1 mb-2">Description</div>
                <v-card variant="outlined" border
                  class="pa-4 text-body-1 rounded-lg bg-grey-lighten-5 text-grey-darken-3">
                  {{ selectedRequest?.description || 'No description provided by resident.' }}
                </v-card>
              </div>

              <div class="mb-6" v-if="selectedRequest?.valid_id">
                <div class="text-caption text-uppercase font-weight-bold text-grey-darken-1 mb-2">Attached Evidence /
                  Valid
                  ID</div>
                <v-img :src="`http://localhost:8000/storage/${selectedRequest.valid_id}`" max-height="200"
                  class="bg-grey-lighten-3 rounded-lg border"></v-img>
              </div>

              <v-divider class="mb-6"></v-divider>

              <!-- Custom Vehicle Picker Button -->
              <div v-if="selectedRequest?.status === 'Pending' || !selectedRequest?.status">
                <div class="text-caption text-uppercase font-weight-bold text-grey-darken-1 mb-2">Dispatch Assignment
                </div>
                <v-card variant="outlined" border class="pa-4 rounded-lg d-flex justify-space-between align-center"
                  :class="formData.vehicle_id ? 'bg-green-lighten-5 border-green' : 'bg-white'">
                  <div v-if="formData.vehicle_id" class="d-flex align-center gap-3">
                    <v-avatar color="green-darken-3" size="40"><v-icon color="white">mdi-car</v-icon></v-avatar>
                    <div>
                      <div class="font-weight-bold text-green-darken-4">{{ getSelectedVehicleName() }}</div>
                      <div class="text-caption text-green-darken-3">Selected for dispatch</div>
                    </div>
                  </div>
                  <div v-else class="text-body-1 text-grey-darken-1">No vehicle assigned yet.</div>

                  <v-btn color="#0f4c3a" variant="flat" class="text-none font-weight-bold text-white"
                    @click="vehicleModal.isOpen = true">
                    {{ formData.vehicle_id ? 'Change Vehicle' : 'Select Vehicle' }}
                  </v-btn>
                </v-card>
              </div>

              <!-- Currently Dispatched Alert -->
              <div v-else-if="selectedRequest?.status === 'Responding'" class="mb-4">
                <v-alert type="info" variant="tonal" border="start" rounded="lg" class="d-flex align-center">
                  <template v-slot:prepend><v-icon size="32">mdi-car-emergency</v-icon></template>
                  <div class="text-subtitle-1 font-weight-bold">Currently Dispatched</div>
                  <div>Vehicle {{ selectedRequest?.vehicle?.plate_number || 'Unknown' }} ({{
                    selectedRequest?.vehicle?.type
                    || 'Unit' }})</div>
                </v-alert>
              </div>

              <v-textarea v-if="selectedRequest?.status === 'Pending' || selectedRequest?.status === 'Responding'"
                v-model="formData.remarks" label="Admin Remarks (Optional)" variant="outlined" density="comfortable"
                rounded="lg" rows="2" hide-details class="mt-4"></v-textarea>
            </v-col>
          </v-row>
        </v-card-text>

        <v-card-actions class="pa-6 d-flex justify-end bg-grey-lighten-5 border-t gap-3"
          v-if="selectedRequest?.status === 'Pending' || !selectedRequest?.status || selectedRequest?.status === 'Responding'">
          <template v-if="selectedRequest?.status === 'Pending' || !selectedRequest?.status">
            <v-btn color="error" variant="text" class="px-6 text-none font-weight-bold" height="44"
              @click="updateStatus('Denied')" :loading="loading">
              Deny Request
            </v-btn>
            <v-btn color="#0f4c3a" variant="flat" class="px-6 text-none font-weight-bold text-white" height="44"
              @click="updateStatus('Responding')" :loading="loading" :disabled="!formData.vehicle_id">
              Approve & Dispatch
            </v-btn>
          </template>

          <template v-if="selectedRequest?.status === 'Responding'">
            <v-btn color="success" variant="flat" class="px-6 text-none font-weight-bold w-100" height="44"
              @click="updateStatus('Resolved')" :loading="loading">
              Mark as Resolved
            </v-btn>
          </template>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Secondary Mini Modal: Vehicle Selector -->
    <v-dialog v-model="vehicleModal.isOpen" max-width="600" transition="dialog-bottom-transition">
      <v-card rounded="lg" elevation="6">
        <v-card-title class="pa-4 border-b bg-white d-flex justify-space-between align-center">
          <span class="text-h6 font-weight-bold text-grey-darken-4">Available Vehicles</span>
          <v-btn icon="mdi-close" variant="text" density="comfortable" @click="vehicleModal.isOpen = false"></v-btn>
        </v-card-title>

        <v-card-text class="pa-4 bg-grey-lighten-4" style="max-height: 400px; overflow-y: auto;">
          <v-row v-if="availableVehicles.length > 0">
            <v-col v-for="v in availableVehicles" :key="v.vehicle_id" cols="12" sm="6">
              <v-card hover border rounded="lg" class="pa-4 cursor-pointer transition-fast-in-fast-out"
                :class="formData.vehicle_id === v.vehicle_id ? 'bg-green-lighten-5 border-green border-opacity-100' : 'bg-white'"
                @click="selectVehicle(v.vehicle_id)">
                <div class="d-flex align-center gap-3">
                  <v-avatar :color="formData.vehicle_id === v.vehicle_id ? 'green-darken-3' : 'grey-lighten-2'"
                    size="48">
                    <v-icon :color="formData.vehicle_id === v.vehicle_id ? 'white' : 'grey-darken-2'">mdi-car</v-icon>
                  </v-avatar>
                  <div>
                    <div class="font-weight-bold text-h6 text-grey-darken-4" style="line-height: 1.2;">{{ v.plate_number
                      }}
                    </div>
                    <div class="text-caption text-uppercase font-weight-bold text-grey-darken-1">{{ v.type }}</div>
                  </div>
                </div>
              </v-card>
            </v-col>
          </v-row>
          <div v-else class="pa-6 text-center text-grey-darken-1">
            <v-icon size="48" class="mb-3 text-grey-lighten-1">mdi-car-off</v-icon>
            <div class="text-h6 font-weight-bold">No Vehicles Available</div>
            <div class="text-body-2">All fleet vehicles are currently dispatched or under maintenance.</div>
          </div>
        </v-card-text>
      </v-card>
    </v-dialog>

  </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'

const headers = [
  { title: 'RESIDENT & LOCATION', key: 'resident_name' },
  { title: 'SERVICE TYPE', key: 'service_name' },
  { title: 'DATE & TIME', key: 'created_at' },
  { title: 'STATUS', key: 'status', align: 'center' }
]

const requests = ref([])
const vehicles = ref([])
const search = ref('')
const initialLoad = ref(true)
const loading = ref(false)
const apiError = ref('')

const filters = ref({ status: 'All' })
const modal = ref({ isOpen: false })
const vehicleModal = ref({ isOpen: false })
const selectedRequest = ref(null)

const formData = ref({ remarks: '', vehicle_id: null })

const pendingRequests = computed(() => requests.value.filter(r => !r.status || r.status === 'Pending').length)
const respondingRequests = computed(() => requests.value.filter(r => r.status === 'Responding').length)
const resolvedRequests = computed(() => requests.value.filter(r => r.status === 'Resolved').length)
const availableVehicles = computed(() => vehicles.value.filter(v => v.status === 'Available'))

const metrics = computed(() => [
  { title: 'Pending', value: pendingRequests.value, icon: 'mdi-alert-circle-outline', color: 'orange-lighten-5', iconColor: 'text-orange-darken-2' },
  { title: 'Responding', value: respondingRequests.value, icon: 'mdi-car-emergency', color: 'blue-lighten-5', iconColor: 'text-blue-darken-2' },
  { title: 'Resolved', value: resolvedRequests.value, icon: 'mdi-check-circle-outline', color: 'green-lighten-5', iconColor: 'text-green-darken-2' },
  { title: 'Total Requests', value: pendingRequests.value + respondingRequests.value + resolvedRequests.value, icon: 'mdi-clipboard-list-outline', color: 'rgba(255,255,255,0.1)', iconColor: 'text-white', bgColor: '#113F36', textColor: 'text-white', numberColor: 'text-white' }
])

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
    case 'Responding': return 'info'
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
      fetch('http://localhost:8000/api/admin/service-requests', { headers: getHeaders() }),
      fetch('http://localhost:8000/api/vehicles', { headers: getHeaders() })
    ])
    const reqData = await reqRes.json()
    const vehData = await vehRes.json()
    requests.value = reqData.data || reqData
    vehicles.value = vehData.data || vehData
  } catch (error) {
    console.error('Failed to fetch data:', error)
  } finally {
    initialLoad.value = false
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

const selectVehicle = (id) => {
  formData.value.vehicle_id = id
  vehicleModal.value.isOpen = false
}

const getSelectedVehicleName = () => {
  const v = vehicles.value.find(veh => veh.vehicle_id === formData.value.vehicle_id)
  return v ? `${v.plate_number} (${v.type})` : ''
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

onMounted(fetchData)
</script>

<style scoped>
.gap-3 {
  gap: 12px;
}

.gap-4 {
  gap: 16px;
}

.metric-card {
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.metric-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 12px 20px rgba(0, 0, 0, 0.08) !important;
}

.custom-metric-number {
  font-size: 2.45rem !important;
  font-weight: 500 !important;
  line-height: 1 !important;
  letter-spacing: -0.02em !important;
}

.fade-in {
  animation: fadeIn 0.4s ease-in-out;
}

@keyframes fadeIn {
  from {
    opacity: 0;
    transform: translateY(10px);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.elegant-table :deep(th) {
  font-size: 0.85rem !important;
  font-weight: 700 !important;
  color: #ffffff !important;
  padding: 0 24px !important;
  height: 56px !important;
  border-bottom: 2px solid #EEEEEE !important;
  background-color: #0f4c3a !important;
}

.elegant-table :deep(td) {
  padding: 24px 24px !important; 
  border-bottom: 1px solid #F5F5F5 !important;
}

.cursor-pointer {
  cursor: pointer;
}
</style>