<template>
  <v-container fluid class="bg-grey-lighten-4 fill-height align-start pa-8" style="background-color: #F4F7FC !important;">
    <v-row>
      <v-col cols="12">
        
        <v-row class="mb-4">
          <v-col cols="12" md="3">
            <v-card elevation="1" rounded="xl" class="pa-4 border-0 d-flex align-center">
              <v-avatar color="orange-lighten-5" size="50" class="mr-4 text-orange-darken-2">
                <v-icon>mdi-clock-outline</v-icon>
              </v-avatar>
              <div>
                <div class="text-subtitle-2 text-grey-darken-1 font-weight-medium">Pending Requests</div>
                <div class="text-h5 font-weight-bold">{{ pendingCount }}</div>
              </div>
            </v-card>
          </v-col>
          <v-col cols="12" md="3">
            <v-card elevation="1" rounded="xl" class="pa-4 border-0 d-flex align-center">
              <v-avatar color="blue-lighten-5" size="50" class="mr-4 text-blue-darken-2">
                <v-icon>mdi-hand-extended-outline</v-icon>
              </v-avatar>
              <div>
                <div class="text-subtitle-2 text-grey-darken-1 font-weight-medium">Currently Released</div>
                <div class="text-h5 font-weight-bold">{{ releasedCount }}</div>
              </div>
            </v-card>
          </v-col>
          <v-col cols="12" md="3">
            <v-card elevation="1" rounded="xl" class="pa-4 border-0 d-flex align-center">
              <v-avatar color="green-lighten-5" size="50" class="mr-4 text-green-darken-2">
                <v-icon>mdi-check-circle-outline</v-icon>
              </v-avatar>
              <div>
                <div class="text-subtitle-2 text-grey-darken-1 font-weight-medium">Returned</div>
                <div class="text-h5 font-weight-bold">{{ returnedCount }}</div>
              </div>
            </v-card>
          </v-col>
        </v-row>

        <v-card elevation="2" rounded="xl" class="pa-6 border-0">
          
          <v-row class="mb-6" align="center" justify="space-between">
            <v-col cols="12" md="4">
              <h2 class="text-h5 font-weight-bold text-grey-darken-3">Equipment Borrowing</h2>
              <div class="text-subtitle-2 text-grey">Process resident requests and track unreturned assets</div>
            </v-col>
            
            <v-col cols="12" md="8" class="d-flex gap-4 justify-end">
              <v-text-field
                v-model="search"
                prepend-inner-icon="mdi-magnify"
                placeholder="Search resident or item..."
                variant="outlined"
                density="compact"
                hide-details
                rounded="lg"
                bg-color="white"
                class="max-w-xs"
              ></v-text-field>

              <v-select
                v-model="filters.status"
                :items="['All', 'Pending', 'Approved', 'Released', 'Returned', 'Denied']"
                label="Status Filter"
                variant="outlined"
                density="compact"
                hide-details
                rounded="lg"
                bg-color="white"
                class="max-w-xs"
              ></v-select>
            </v-col>
          </v-row>

          <v-data-table
            :headers="headers"
            :items="filteredBorrowings"
            :search="search"
            :items-per-page="10"
            hover
            class="custom-table"
            @click:row="openProcessModal"
          >
            <template v-slot:item.resident_name="{ item }">
              <div class="font-weight-bold text-grey-darken-3">
                {{ item.resident?.last_name }}, {{ item.resident?.first_name }}
              </div>
              <div class="text-caption text-grey-darken-1">{{ item.resident?.barangay?.barangay_name || 'N/A' }}</div>
            </template>

            <template v-slot:item.equipment="{ item }">
              <span class="font-weight-medium">{{ item.equipment?.item_name || 'Unknown' }}</span>
              <v-chip size="x-small" class="ml-2 font-weight-bold">{{ item.quantity }}x</v-chip>
            </template>

            <template v-slot:item.created_at="{ item }">
              <span class="text-grey-darken-2">{{ new Date(item.created_at).toLocaleDateString() }}</span>
            </template>

            <template v-slot:item.status="{ item }">
              <v-chip
                :color="getStatusColor(item.status)"
                size="small"
                label
                variant="flat"
                class="text-uppercase font-weight-bold px-3 text-white"
              >
                {{ item.status }}
              </v-chip>
            </template>
          </v-data-table>
        </v-card>
      </v-col>
    </v-row>

    <v-dialog v-model="modal.isOpen" max-width="800" persistent>
      <v-card rounded="xl" elevation="10">
        <v-card-title class="d-flex justify-space-between align-center pa-6 border-b bg-grey-lighten-4">
          <div class="d-flex align-center gap-3">
            <span class="text-h6 font-weight-bold text-grey-darken-3">Borrowing Request Details</span>
            <v-chip :color="getStatusColor(selectedRecord?.status)" size="small" label class="text-uppercase font-weight-bold text-white">
              {{ selectedRecord?.status }}
            </v-chip>
          </div>
          <v-btn icon="mdi-close" variant="text" size="small" color="grey" @click="closeModal"></v-btn>
        </v-card-title>

        <v-card-text class="pa-0">
          <v-row class="ma-0 h-100">
            <v-col cols="12" md="5" class="bg-grey-lighten-5 pa-6 border-e">
              <div class="d-flex flex-column align-center mb-6">
                <v-avatar color="blue-lighten-4" size="80" class="mb-3">
                  <span class="text-h4 font-weight-bold text-blue-darken-2">
                    {{ selectedRecord?.resident?.first_name?.charAt(0) }}{{ selectedRecord?.resident?.last_name?.charAt(0) }}
                  </span>
                </v-avatar>
                <div class="text-h6 font-weight-bold text-center">{{ selectedRecord?.resident?.first_name }} {{ selectedRecord?.resident?.last_name }}</div>
              </div>
              <v-divider class="mb-4"></v-divider>
              <div class="mb-3">
                <div class="text-caption text-grey">Phone Number</div>
                <div class="font-weight-medium">{{ selectedRecord?.resident?.phone_number || 'N/A' }}</div>
              </div>
              <div class="mb-3">
                <div class="text-caption text-grey">Barangay</div>
                <div class="font-weight-medium">{{ selectedRecord?.resident?.barangay?.barangay_name || 'N/A' }}</div>
              </div>
            </v-col>

            <v-col cols="12" md="7" class="pa-6">
              <v-alert v-if="apiError" type="error" variant="tonal" class="mb-4" density="compact" rounded="lg">
                {{ apiError }}
              </v-alert>

              <h3 class="text-subtitle-1 font-weight-bold mb-4 text-primary">Equipment Requested</h3>
              
              <v-card variant="outlined" class="pa-4 mb-4 rounded-lg border-primary bg-blue-lighten-5">
                <div class="d-flex justify-space-between align-center">
                  <div>
                    <div class="text-h6 font-weight-bold text-primary">{{ selectedRecord?.equipment?.item_name }}</div>
                    <div class="text-caption text-grey-darken-1">Current Stock Available: {{ selectedRecord?.equipment?.available_quantity }}</div>
                  </div>
                  <div class="text-h4 font-weight-bold text-primary">{{ selectedRecord?.quantity }}x</div>
                </div>
              </v-card>

              <v-row class="mb-4 text-body-2">
                <v-col cols="6">
                  <div class="text-caption text-grey">Requested On</div>
                  <div class="font-weight-medium">{{ new Date(selectedRecord?.created_at).toLocaleString() }}</div>
                </v-col>
                <v-col cols="6" v-if="selectedRecord?.released_at">
                  <div class="text-caption text-grey">Released On</div>
                  <div class="font-weight-medium">{{ new Date(selectedRecord?.released_at).toLocaleString() }}</div>
                </v-col>
                <v-col cols="6" v-if="selectedRecord?.returned_at">
                  <div class="text-caption text-grey">Returned On</div>
                  <div class="font-weight-medium">{{ new Date(selectedRecord?.returned_at).toLocaleString() }}</div>
                </v-col>
              </v-row>
            </v-col>
          </v-row>
        </v-card-text>

        <v-card-actions class="pa-6 pt-0 d-flex justify-end bg-grey-lighten-4 border-t gap-3" v-if="selectedRecord?.status !== 'Returned' && selectedRecord?.status !== 'Denied'">
          
          <template v-if="selectedRecord?.status === 'Pending'">
            <v-btn color="error" variant="outlined" rounded="lg" class="px-6 text-none font-weight-bold" height="44" @click="updateStatus('Denied')" :loading="loading">
              Deny
            </v-btn>
            <v-btn color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" height="44" @click="updateStatus('Approved')" :loading="loading">
              Approve Request
            </v-btn>
          </template>

          <template v-if="selectedRecord?.status === 'Approved'">
            <v-btn color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold w-100" height="44" @click="updateStatus('Released')" :loading="loading">
              Mark as Released to Resident
            </v-btn>
          </template>

          <template v-if="selectedRecord?.status === 'Released'">
            <v-btn color="success" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold w-100" height="44" @click="updateStatus('Returned')" :loading="loading">
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
  { title: 'Resident', key: 'resident_name' },
  { title: 'Equipment', key: 'equipment' },
  { title: 'Date Requested', key: 'created_at' },
  { title: 'Status', key: 'status', align: 'center' }
]

const borrowings = ref([])
const search = ref('')
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

  return result
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