<template>
  <v-container fluid class="fill-height align-start pa-6" style="background-color: #F4F7FC !important;">
    <v-row class="ma-0 w-100">
      <v-col cols="12" class="pa-0 w-100">
        
        <!-- Metrics Row -->
<v-row class="mb-6">
  <v-col v-for="(metric, index) in metrics" :key="index" cols="12" md="4">
    <v-skeleton-loader v-if="initialLoad" type="list-item-avatar-two-line" elevation="3" rounded="lg"></v-skeleton-loader>
    <v-card v-else elevation="3" rounded="lg" class="pa-4 bg-white d-flex align-center metric-card fade-in">
      <v-avatar :color="metric.color" size="48" class="mr-4 rounded-lg">
        <v-icon size="28" :class="metric.iconColor">{{ metric.icon }}</v-icon>
      </v-avatar>
      <div>
        <div class="text-caption text-uppercase font-weight-bold text-grey-darken-1">{{ metric.title }}</div>
        <div class="custom-metric-number mt-1 text-grey-darken-4">{{ metric.value }}</div>
      </div>
    </v-card>
  </v-col>
</v-row>

<!-- Main Content -->
<v-card elevation="3" rounded="lg" class="bg-white w-100">
  <div class="px-6 py-2 border-b d-flex flex-row align-center justify-space-between gap-4">
  <div>
    <h2 class="text-h5 font-weight-bold text-grey-darken-4">Equipment Borrowing</h2>
  </div>
  
  <div class="d-flex gap-4 align-center">
    <v-text-field
      v-model="search"
      prepend-inner-icon="mdi-magnify"
      placeholder="Search resident or item..."
      variant="outlined"
      density="compact"
      hide-details
      bg-color="white"
      style="width: 250px;"
    ></v-text-field>

    <v-select
      v-model="filters.status"
      :items="['All', 'Pending', 'Approved', 'Released', 'Returned', 'Denied']"
      variant="outlined"
      density="compact"
      hide-details
      bg-color="white"
      style="width: 130px;"
    ></v-select>
  </div>
</div>

  <v-skeleton-loader v-if="initialLoad" type="table-tbody" class="pa-4"></v-skeleton-loader>

  <v-data-table
    v-else
    :headers="headers"
    :items="filteredBorrowings"
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
              <div class="text-subtitle-2 text-grey-darken-1 mt-1">{{ item.resident?.barangay?.barangay_name || 'N/A' }}</div>
            </template>

            <template v-slot:item.equipment="{ item }">
              <span class="font-weight-bold text-subtitle-1 text-grey-darken-3">{{ item.equipment?.item_name || 'Unknown' }}</span>
              <v-chip size="small" color="grey-lighten-2" class="ml-3 font-weight-black text-grey-darken-4 px-3">{{ item.quantity }}x</v-chip>
            </template>

            <template v-slot:item.created_at="{ item }">
              <span class="text-body-1 font-weight-medium text-grey-darken-2">
                {{ new Date(item.created_at).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) }}
              </span>
            </template>

            <template v-slot:item.status="{ item }">
              <v-chip
                :color="getStatusColor(item.status)"
                size="default"
                label
                variant="flat"
                class="text-uppercase font-weight-bold px-4 text-white"
              >
                {{ item.status }}
              </v-chip>
            </template>
          </v-data-table>
        </v-card>
      </v-col>
    </v-row>

    <!-- Modal -->
    <v-dialog v-model="modal.isOpen" max-width="900" persistent transition="dialog-fade-transition">
      <v-card rounded="lg" elevation="4">
        <v-card-title class="d-flex justify-space-between align-center pa-6 border-b bg-white">
          <div class="d-flex align-center gap-3">
            <span class="text-h6 font-weight-bold text-grey-darken-4">Borrowing Request Details</span>
            <v-chip :color="getStatusColor(selectedRecord?.status)" size="small" label class="text-uppercase font-weight-bold text-white">
              {{ selectedRecord?.status }}
            </v-chip>
          </div>
          <v-btn icon="mdi-close" variant="text" size="small" color="grey-darken-2" @click="closeModal"></v-btn>
        </v-card-title>

        <v-card-text class="pa-0">
          <v-row class="ma-0 h-100">
            <!-- Left Side: Resident Info -->
            <v-col cols="12" md="5" class="bg-grey-lighten-5 pa-6 border-e">
              <div class="d-flex flex-column align-center mb-6">
                <v-avatar color="blue-grey-lighten-4" size="80" class="mb-3">
                  <span class="text-h4 font-weight-black text-blue-grey-darken-3">
                    {{ selectedRecord?.resident?.first_name?.charAt(0) }}{{ selectedRecord?.resident?.last_name?.charAt(0) }}
                  </span>
                </v-avatar>
                <div class="text-h6 font-weight-bold text-center text-grey-darken-4">{{ selectedRecord?.resident?.first_name }} {{ selectedRecord?.resident?.last_name }}</div>
                <div class="text-caption text-grey-darken-1 text-uppercase font-weight-bold mt-1">Resident Profile</div>
              </div>
              
              <v-divider class="mb-4"></v-divider>
              
              <div class="mb-3">
                <div class="text-caption text-uppercase font-weight-bold text-grey-darken-1">Phone Number</div>
                <div class="font-weight-medium text-body-1 text-grey-darken-4">{{ selectedRecord?.resident?.phone_number || 'N/A' }}</div>
              </div>
              <div class="mb-3">
                <div class="text-caption text-uppercase font-weight-bold text-grey-darken-1">Barangay</div>
                <div class="font-weight-medium text-body-1 text-grey-darken-4">{{ selectedRecord?.resident?.barangay?.barangay_name || 'N/A' }}</div>
              </div>
            </v-col>

            <!-- Right Side: Equipment Info -->
            <v-col cols="12" md="7" class="pa-6 bg-white">
              <v-alert v-if="apiError" type="error" variant="tonal" class="mb-4" density="compact">
                {{ apiError }}
              </v-alert>

              <h3 class="text-subtitle-1 font-weight-bold mb-4 text-grey-darken-4 text-uppercase">Equipment Requested</h3>
              
              <v-card variant="outlined" border class="pa-6 mb-6 rounded-lg bg-grey-lighten-5 d-flex justify-space-between align-center">
                <div>
                  <div class="text-h5 font-weight-black text-grey-darken-4">{{ selectedRecord?.equipment?.item_name }}</div>
                  <div class="text-subtitle-2 font-weight-medium text-grey-darken-1 mt-1">
                    Current Stock Available: <span class="font-weight-bold" :class="selectedRecord?.equipment?.available_quantity > 0 ? 'text-green-darken-3' : 'text-error'">{{ selectedRecord?.equipment?.available_quantity }}</span>
                  </div>
                </div>
                <div class="text-h3 font-weight-black text-grey-darken-4">{{ selectedRecord?.quantity }}<span class="text-h5 text-grey-darken-1 ml-1">x</span></div>
              </v-card>

              <v-row class="mb-4">
                <v-col cols="6">
                  <div class="text-caption text-uppercase font-weight-bold text-grey-darken-1">Requested On</div>
                  <div class="font-weight-medium text-body-1 text-grey-darken-4">{{ new Date(selectedRecord?.created_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) }}</div>
                </v-col>
                <v-col cols="6" v-if="selectedRecord?.released_at">
                  <div class="text-caption text-uppercase font-weight-bold text-grey-darken-1">Released On</div>
                  <div class="font-weight-medium text-body-1 text-primary">{{ new Date(selectedRecord?.released_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) }}</div>
                </v-col>
                <v-col cols="6" v-if="selectedRecord?.returned_at">
                  <div class="text-caption text-uppercase font-weight-bold text-grey-darken-1">Returned On</div>
                  <div class="font-weight-medium text-body-1 text-success">{{ new Date(selectedRecord?.returned_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) }}</div>
                </v-col>
              </v-row>
            </v-col>
          </v-row>
        </v-card-text>

        <v-card-actions class="pa-6 d-flex justify-end bg-grey-lighten-5 border-t gap-3" v-if="selectedRecord?.status !== 'Returned' && selectedRecord?.status !== 'Denied'">
          
          <template v-if="selectedRecord?.status === 'Pending'">
            <v-btn color="error" variant="text" class="px-6 text-none font-weight-bold" height="44" @click="updateStatus('Denied')" :loading="loading">
              Deny Request
            </v-btn>
            <v-btn color="primary" variant="flat" class="px-6 text-none font-weight-bold" height="44" @click="updateStatus('Approved')" :loading="loading">
              Approve Request
            </v-btn>
          </template>

          <template v-if="selectedRecord?.status === 'Approved'">
            <v-btn color="primary" variant="flat" class="px-6 text-none font-weight-bold w-100" height="44" @click="updateStatus('Released')" :loading="loading">
              Mark as Released to Resident
            </v-btn>
          </template>

          <template v-if="selectedRecord?.status === 'Released'">
            <v-btn color="success" variant="flat" class="px-6 text-none font-weight-bold w-100" height="44" @click="updateStatus('Returned')" :loading="loading">
              Confirm Items Returned
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
  { title: 'RESIDENT', key: 'resident_name' },
  { title: 'EQUIPMENT', key: 'equipment' },
  { title: 'DATE REQUESTED', key: 'created_at' },
  { title: 'STATUS', key: 'status', align: 'center' }
]

const borrowings = ref([])
const search = ref('')
const initialLoad = ref(true)
const loading = ref(false)
const apiError = ref('')

const filters = ref({
  status: 'All'
})

const modal = ref({
  isOpen: false
})

const selectedRecord = ref(null)

// Metrics
const pendingCount = computed(() => borrowings.value.filter(b => b.status === 'Pending').length)
const releasedCount = computed(() => borrowings.value.filter(b => b.status === 'Released').length)
const returnedCount = computed(() => borrowings.value.filter(b => b.status === 'Returned').length)

const metrics = computed(() => [
  { title: 'Pending Requests', value: pendingCount.value, icon: 'mdi-clock-outline', color: 'orange-lighten-5', iconColor: 'text-orange-darken-2' },
  { title: 'Currently Released', value: releasedCount.value, icon: 'mdi-hand-extended-outline', color: 'blue-lighten-5', iconColor: 'text-blue-darken-2' },
  { title: 'Returned', value: returnedCount.value, icon: 'mdi-check-circle-outline', color: 'green-lighten-5', iconColor: 'text-green-darken-2' }
])

// Filter Logic
const filteredBorrowings = computed(() => {
  let result = borrowings.value

  if (filters.value.status !== 'All') {
    result = result.filter(b => b.status === filters.value.status)
  }

  if (search.value) {
    const searchLower = search.value.toLowerCase()
    result = result.filter(b => {
      const residentName = `${b.resident?.first_name} ${b.resident?.last_name}`.toLowerCase()
      const itemName = (b.equipment?.item_name || '').toLowerCase()
      return residentName.includes(searchLower) || itemName.includes(searchLower)
    })
  }

  // Sort newest first
  return result.sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
})

const getStatusColor = (status) => {
  switch(status) {
    case 'Pending': return 'warning'
    case 'Approved': return 'info'
    case 'Released': return 'primary'
    case 'Returned': return 'success'
    case 'Denied': return 'error'
    default: return 'grey'
  }
}

const getHeaders = () => ({
  'Authorization': `Bearer ${localStorage.getItem('serbis_token')}`,
  'Content-Type': 'application/json',
  'Accept': 'application/json'
})

const fetchData = async () => {
  try {
    const res = await fetch('http://localhost:8000/api/borrowings', { headers: getHeaders() })
    const data = await res.json()
    borrowings.value = data.data || data 
  } catch (error) {
    console.error('Failed to fetch borrowings:', error)
  } finally {
    initialLoad.value = false
  }
}

const openProcessModal = (event, { item }) => {
  apiError.value = ''
  selectedRecord.value = item
  modal.value.isOpen = true
}

const closeModal = () => {
  modal.value.isOpen = false
  selectedRecord.value = null
}

const updateStatus = async (newStatus) => {
  loading.value = true
  apiError.value = ''
  
  const id = selectedRecord.value.borrow_id || selectedRecord.value.id

  try {
    const res = await fetch(`http://localhost:8000/api/borrowings/${id}`, {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify({ status: newStatus })
    })
    
    if (!res.ok) {
      const errData = await res.json()
      throw new Error(errData.message || 'Failed to update status')
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
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }

.metric-card {
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.metric-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 12px 20px rgba(0,0,0,0.08) !important;
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
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
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
</style>