<template>
  <v-container fluid class="pa-8 pl-10">
    
    <v-row class="mb-6 align-center">
      <v-col cols="12" md="6">
        <h1 class="text-h4 font-weight-black text-grey-darken-4">Dashboard</h1>
      </v-col>
      <v-col cols="12" md="6" class="d-flex justify-end">
        <v-card 
          elevation="0" 
          color="#113F36" 
          rounded="lg" 
          class="pa-3 d-flex align-center shadow-sm"
          style="min-width: 250px;"
        >
          <v-icon color="green-lighten-3" size="large" class="mr-3">mdi-calendar-clock</v-icon>
          <div>
            <div class="text-caption text-green-lighten-4 font-weight-bold text-uppercase">
              {{ currentDay }}
            </div>
            <div class="text-body-1 font-weight-bold text-white">
              {{ currentDate }}
            </div>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <v-row>
      <template v-if="loading">
        <v-col v-for="i in 4" :key="`kpi-skeleton-${i}`" cols="12" sm="6" lg="3">
          <v-card elevation="0" border rounded="xl" class="pa-5 bg-white h-100">
            <v-skeleton-loader type="list-item-avatar-two-line"></v-skeleton-loader>
          </v-card>
        </v-col>
      </template>

      <template v-else>
        <v-col v-for="stat in kpiStats" :key="stat.title" cols="12" sm="6" lg="3">
          <v-card elevation="0" border rounded="xl" class="pa-5 bg-white h-100 d-flex flex-column">
            <div class="d-flex justify-space-between align-center mb-2">
              <span class="text-caption font-weight-bold text-grey-darken-1 text-uppercase">{{ stat.title }}</span>
              <v-btn :icon="stat.icon" size="x-small" variant="tonal" :color="stat.color" flat></v-btn>
            </div>
            <div class="d-flex align-center mb-4 mt-auto">
              <span class="text-h4 font-weight-bold mr-3">{{ stat.value }}</span>
            </div>
            <v-divider class="mb-3"></v-divider>
            <div class="text-caption text-grey-darken-1">{{ stat.subtitle }}</div>
          </v-card>
        </v-col>
      </template>
    </v-row>

    <v-row>
      <v-col cols="12" lg="8">
        <v-card elevation="0" border rounded="xl" class="pa-6 bg-white h-100">
          <div class="d-flex justify-space-between align-center mb-4">
            <span class="text-caption font-weight-bold text-grey-darken-1 text-uppercase">Recent Service Requests</span>
            <v-btn variant="text" size="small" color="green" append-icon="mdi-chevron-right" class="text-none">View All</v-btn>
          </div>
          
          <v-skeleton-loader v-if="loading" type="table-tbody"></v-skeleton-loader>
          
          <v-data-table v-else :headers="serviceHeaders" :items="serviceRequests" density="compact" class="bg-transparent text-body-2" hide-default-footer>
            <template v-slot:item.status="{ item }">
              <v-chip :color="getStatusColor(item.status)" size="small" variant="tonal" class="font-weight-bold">{{ item.status }}</v-chip>
            </template>
          </v-data-table>
        </v-card>
      </v-col>

      <v-col cols="12" lg="4">
        <v-card elevation="0" border rounded="xl" class="pa-6 bg-white h-100">
          <div class="mb-4">
            <span class="text-caption font-weight-bold text-grey-darken-1 text-uppercase">Requests by Service Type</span>
          </div>
          
          <v-skeleton-loader v-if="loading" type="image" height="250"></v-skeleton-loader>
          
          <v-sheet v-else height="250" color="grey-lighten-4" rounded="lg" class="d-flex align-center justify-center border border-dashed border-grey-lighten-2">
            <div class="text-center">
              <v-icon size="large" color="grey">mdi-chart-donut</v-icon>
              <div class="text-grey-darken-1 font-weight-bold mt-2">Donut Chart Component</div>
            </div>
          </v-sheet>
        </v-card>
      </v-col>
    </v-row>

    <v-row>
      <v-col cols="12" lg="8">
        <v-card elevation="0" border rounded="xl" class="pa-6 bg-white h-100">
          <div class="d-flex justify-space-between align-center mb-4">
            <span class="text-caption font-weight-bold text-grey-darken-1 text-uppercase">Recent Borrow Requests</span>
            <v-btn variant="text" size="small" color="green" append-icon="mdi-chevron-right" class="text-none">View All</v-btn>
          </div>
          
          <v-skeleton-loader v-if="loading" type="table-tbody"></v-skeleton-loader>

          <v-data-table v-else :headers="borrowHeaders" :items="borrowRequests" density="compact" class="bg-transparent text-body-2" hide-default-footer>
            <template v-slot:item.status="{ item }">
              <v-chip :color="getStatusColor(item.status)" size="small" variant="tonal" class="font-weight-bold">{{ item.status }}</v-chip>
            </template>
          </v-data-table>
        </v-card>
      </v-col>

      <v-col cols="12" lg="4">
        <v-card elevation="0" border rounded="xl" class="pa-6 bg-white h-100">
          <div class="mb-4">
            <span class="text-caption font-weight-bold text-grey-darken-1 text-uppercase">Service Requests Over Time</span>
          </div>

          <v-skeleton-loader v-if="loading" type="image" height="250"></v-skeleton-loader>

          <v-sheet v-else height="250" color="grey-lighten-4" rounded="lg" class="d-flex align-center justify-center border border-dashed border-grey-lighten-2">
            <div class="text-center">
              <v-icon size="large" color="grey">mdi-chart-line</v-icon>
              <div class="text-grey-darken-1 font-weight-bold mt-2">Line Chart Component</div>
            </div>
          </v-sheet>
        </v-card>
      </v-col>
    </v-row>

    <v-row>
      <v-col cols="12">
        <v-card elevation="0" border rounded="xl" class="pa-6 bg-white">
          <div class="d-flex justify-space-between align-center mb-4">
            <span class="text-caption font-weight-bold text-grey-darken-1 text-uppercase">Recent System Logs</span>
            <v-btn variant="text" size="small" color="green" append-icon="mdi-chevron-right" class="text-none">Go to Logs</v-btn>
          </div>

          <v-skeleton-loader v-if="loading" type="table-tbody"></v-skeleton-loader>

          <v-data-table v-else :headers="logHeaders" :items="systemLogs" density="compact" class="bg-transparent text-body-2" hide-default-footer>
            <template v-slot:item.action="{ item }">
              <v-chip size="x-small" variant="outlined" color="grey-darken-2">{{ item.action }}</v-chip>
            </template>
          </v-data-table>
        </v-card>
      </v-col>
    </v-row>

  </v-container>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'

const kpiStats = ref([])
const serviceRequests = ref([])
const borrowRequests = ref([])
const systemLogs = ref([])
const loading = ref(true)

const now = new Date()

const currentDay = computed(() => {
  return new Intl.DateTimeFormat('en-PH', { weekday: 'long' }).format(now)
})

const currentDate = computed(() => {
  return new Intl.DateTimeFormat('en-PH', { 
    month: 'long', 
    day: 'numeric', 
    year: 'numeric' 
  }).format(now)
})

const serviceHeaders = [
  { title: 'Resident', key: 'resident' },
  { title: 'Service Type', key: 'type' },
  { title: 'Date', key: 'date' },
  { title: 'Status', key: 'status', align: 'end' },
]

const borrowHeaders = [
  { title: 'Borrower', key: 'borrower' },
  { title: 'Equipment', key: 'equipment' },
  { title: 'Date Needed', key: 'date' },
  { title: 'Status', key: 'status', align: 'end' },
]

const logHeaders = [
  { title: 'Time', key: 'time' },
  { title: 'User', key: 'user' },
  { title: 'Module', key: 'module' },
  { title: 'Action', key: 'action' },
]

const fetchDashboardData = async () => {
  loading.value = true
  try {
    const response = await fetch('http://localhost:8000/api/admin/dashboard', {
      headers: {
        'Authorization': `Bearer ${localStorage.getItem('serbis_token')}`,
        'Accept': 'application/json'
      }
    })
    
    if (!response.ok) throw new Error('Network response error')
    
    const data = await response.json()

    kpiStats.value = data.kpiStats || []
    serviceRequests.value = data.serviceRequests || []
    borrowRequests.value = data.borrowRequests || []
    systemLogs.value = data.systemLogs || []
    
  } catch (error) {
    console.error("Failed to load dashboard:", error)
  } finally {
    loading.value = false
  }
}

const getStatusColor = (status) => {
  if (!status) return 'grey'
  const s = status.toLowerCase()
  if (s === 'pending') return 'orange'
  if (s === 'approved' || s === 'responding') return 'blue'
  if (s === 'resolved' || s === 'returned') return 'green'
  if (s === 'rejected') return 'red'
  return 'grey'
}

onMounted(() => {
  fetchDashboardData()
})
</script>

<style scoped>
.v-list-item--active {
  background-color: rgba(255, 255, 255, 0.1) !important;
}
</style>