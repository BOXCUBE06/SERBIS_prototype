<template>
  <v-container fluid class="pa-6" style="background-color: #F8FAFB;">
    
    <!-- Header Section -->
    <v-row class="mb-4 align-center">
      <v-col cols="12" md="6">
        <h1 class="text-h4 font-weight-black text-grey-darken-4 mb-1">Dashboard</h1>
        <div class="text-subtitle-1 text-grey-darken-1">Welcome back! Here's what's happening today.</div>
      </v-col>
      <v-col cols="12" md="6" class="d-flex justify-end align-center gap-4">
        <!-- Notifications Menu -->
        <v-menu location="bottom end">
          <template v-slot:activator="{ props }">
            <v-btn 
              icon="mdi-bell-outline" 
              variant="outlined" 
              color="grey-darken-2" 
              v-bind="props" 
              class="mr-4 bg-white"
            >
              <v-badge color="red" dot v-if="systemLogs.length > 0">
                <v-icon>mdi-bell-outline</v-icon>
              </v-badge>
              <v-icon v-else>mdi-bell-outline</v-icon>
            </v-btn>
          </template>
          <v-card min-width="320" elevation="4" rounded="lg" class="border">
            <v-list density="compact" class="pa-0">
              <v-list-subheader class="font-weight-bold text-uppercase bg-grey-lighten-4 py-2">System Logs</v-list-subheader>
              <v-divider></v-divider>
              <template v-if="systemLogs.length">
                <v-list-item v-for="(log, i) in systemLogs.slice(0, 5)" :key="'log-'+i" class="py-3 border-b">
                  <template v-slot:prepend>
                    <v-avatar color="#E2F5ED" size="32" class="mr-3">
                      <v-icon color="#2E8B75" size="small">mdi-history</v-icon>
                    </v-avatar>
                  </template>
                  <v-list-item-title class="text-body-2 font-weight-bold text-grey-darken-4">{{ log.action }}</v-list-item-title>
                  <v-list-item-subtitle class="text-caption text-grey-darken-1">{{ log.user }} &bull; {{ log.module }}</v-list-item-subtitle>
                  <template v-slot:append>
                    <span class="text-caption text-grey">{{ log.time }}</span>
                  </template>
                </v-list-item>
              </template>
              <div v-else class="pa-4 text-center text-caption text-grey">No recent logs</div>
            </v-list>
          </v-card>
        </v-menu>

        <!-- Profile Avatar -->
        <v-avatar color="#2E8B75" size="44" class="cursor-pointer font-weight-bold text-white shadow-sm">
          J
        </v-avatar>
      </v-col>
    </v-row>

    <!-- Main Layout Grid -->
    <v-row>
      
      <!-- LEFT MAIN CONTENT (8 Columns) -->
      <v-col cols="12" xl="9" lg="8">
        
        <!-- Row 1: KPI Cards -->
        <v-row>
          <template v-if="loading">
            <v-col v-for="i in 4" :key="`kpi-skeleton-${i}`" cols="12" sm="6" lg="3">
              <v-card elevation="0" border rounded="xl" class="pa-4 bg-white h-100">
                <v-skeleton-loader type="list-item-two-line"></v-skeleton-loader>
              </v-card>
            </v-col>
          </template>

          <template v-else>
            <v-col v-for="stat in kpiStats" :key="stat.title" cols="12" sm="6" lg="3">
              <v-card elevation="0" border rounded="xl" class="pa-4 bg-white h-100 d-flex flex-column">
                <div class="d-flex align-center justify-space-between mb-3">
                  <div class="d-flex align-center">
                    <v-icon size="small" class="mr-2 text-grey-darken-2" :color="stat.color">{{ stat.icon || 'mdi-chart-arc' }}</v-icon>
                    <span class="text-body-2 font-weight-bold text-grey-darken-2">{{ stat.title }}</span>
                  </div>
                  <v-icon size="small" class="text-grey-lighten-1">mdi-dots-horizontal</v-icon>
                </div>
                
                <div class="d-flex align-center justify-space-between mb-2 mt-auto">
                  <span class="text-h4 font-weight-black">{{ stat.value }}</span>
                  <v-chip size="x-small" color="#2E8B75" variant="flat" class="font-weight-bold px-2 rounded">
                    <v-icon start size="x-small">mdi-trending-up</v-icon>
                    New
                  </v-chip>
                </div>
                
                <div class="text-caption text-success font-weight-medium" style="color: #2E8B75 !important;">
                  {{ stat.subtitle }}
                </div>
              </v-card>
            </v-col>
          </template>
        </v-row>

        <!-- Row 2: Heatmap & Top Barangays -->
        <v-row class="mt-2">
          <!-- Heatmap -->
          <v-col cols="12" md="6">
            <v-card elevation="0" border rounded="xl" class="pa-5 bg-white h-100">
              <div class="d-flex justify-space-between align-center mb-4">
                <span class="text-body-1 font-weight-bold text-grey-darken-4">Incident Heatmap</span>
                <v-icon color="grey-darken-1">mdi-map-marker-radius</v-icon>
              </div>
              <div id="heatmap" style="height: 350px; width: 100%; border-radius: 8px; z-index: 1;" class="bg-grey-lighten-4 border"></div>
            </v-card>
          </v-col>

          <!-- Hotspot Barangays List -->
          <v-col cols="12" md="6">
            <v-card elevation="0" border rounded="xl" class="pa-5 bg-white h-100">
              <div class="d-flex justify-space-between align-center mb-4">
                <span class="text-body-1 font-weight-bold text-grey-darken-4">High Request Zones</span>
                <v-icon color="grey-darken-1">mdi-fire</v-icon>
              </div>
              
              <div class="d-flex flex-column gap-4 mt-2">
                <div v-for="(brgy, index) in topBarangays" :key="index" class="w-100">
                  <div class="d-flex justify-space-between align-center mb-1">
                    <span class="text-body-2 font-weight-bold text-grey-darken-3">{{ brgy.name }}</span>
                    <span class="text-caption font-weight-bold text-grey-darken-1">{{ brgy.requests }} Requests</span>
                  </div>
                  <v-progress-linear 
                    :model-value="brgy.percentage" 
                    :color="getHeatColor(brgy.percentage)" 
                    height="8" 
                    rounded 
                    class="bg-grey-lighten-3"
                  ></v-progress-linear>
                </div>
              </div>
            </v-card>
          </v-col>
        </v-row>

        <!-- Row 3: Lists (Services & Borrowing) -->
        <v-row class="mt-2">
          <!-- Recent Service Requests -->
          <v-col cols="12" md="6">
            <v-card elevation="0" border rounded="xl" class="pa-5 bg-white h-100">
              <div class="mb-1">
                <span class="text-h6 font-weight-bold text-grey-darken-4">Recent Service Requests</span>
                <div class="text-caption text-grey-darken-1 mb-5">Incoming and ongoing requests</div>
              </div>

              <v-skeleton-loader v-if="loading" type="list-item-avatar-two-line@5"></v-skeleton-loader>

              <div v-else class="d-flex flex-column gap-3">
                <div v-for="(item, index) in serviceRequests.slice(0, 5)" :key="'srv-'+index" class="d-flex align-center mb-4">
                  <v-avatar size="42" color="grey-lighten-4" class="mr-3 border">
                    <v-icon color="#2E8B75" size="small">mdi-account</v-icon>
                  </v-avatar>
                  <div class="flex-grow-1 min-width-0">
                    <div class="text-body-2 font-weight-bold text-truncate">{{ item.resident }}</div>
                    <div class="text-caption text-grey-darken-1 text-truncate">{{ item.type }}</div>
                  </div>
                  <div class="d-flex align-center ml-2">
                    <v-icon size="x-small" class="mr-1 text-grey">mdi-clock-outline</v-icon>
                    <span class="text-caption text-grey-darken-1 mr-3">{{ item.date }}</span>
                    <v-chip :color="getStatusColor(item.status)" size="x-small" variant="tonal" class="font-weight-bold rounded">{{ item.status }}</v-chip>
                  </div>
                </div>
              </div>
            </v-card>
          </v-col>

          <!-- Recent Borrow Requests -->
          <v-col cols="12" md="6">
            <v-card elevation="0" border rounded="xl" class="pa-5 bg-white h-100">
              <div class="mb-1">
                <span class="text-h6 font-weight-bold text-grey-darken-4">Recent Borrow Requests</span>
                <div class="text-caption text-grey-darken-1 mb-5">Latest equipment requests</div>
              </div>

              <v-skeleton-loader v-if="loading" type="list-item-avatar-two-line@5"></v-skeleton-loader>

              <div v-else class="d-flex flex-column gap-3 mt-2">
                <div v-for="(item, index) in borrowRequests.slice(0, 5)" :key="'brw-'+index" class="d-flex align-center mb-4">
                  <div class="flex-grow-1 min-width-0">
                    <div class="text-body-2 font-weight-bold text-truncate">{{ item.borrower }}</div>
                    <div class="text-caption text-grey-darken-1 text-truncate">{{ item.equipment }}</div>
                  </div>
                  <div class="d-flex align-center flex-column align-end">
                    <span class="text-caption font-weight-bold text-grey-darken-3 mb-1">{{ item.date }}</span>
                    <v-chip :color="getStatusColor(item.status)" size="x-small" variant="tonal" class="font-weight-bold rounded">{{ item.status }}</v-chip>
                  </div>
                </div>
              </div>
            </v-card>
          </v-col>
        </v-row>
      </v-col>

      <!-- RIGHT SIDEBAR (4 Columns) -->
      <v-col cols="12" xl="3" lg="4">
        
        <!-- Calendar Section -->
        <v-card elevation="0" border rounded="xl" class="pa-4 bg-white mb-6 d-flex justify-center">
          <v-date-picker 
            v-model="selectedDate"
            color="#2E8B75"
            hide-header
            elevation="0"
            class="w-100 border-0"
          ></v-date-picker>
        </v-card>

        <!-- Pie Chart (Service Volume) -->
        <v-card elevation="0" border rounded="xl" class="pa-5 bg-white mb-6">
          <div class="d-flex justify-space-between align-center mb-4">
            <span class="text-body-1 font-weight-bold text-grey-darken-4">Service Volume</span>
          </div>
          <v-sheet height="200" color="transparent" class="d-flex align-center justify-center border border-dashed border-grey-lighten-2 rounded-lg">
            <div class="text-center">
              <v-icon size="x-large" color="#2E8B75">mdi-chart-pie</v-icon>
              <div class="text-grey-darken-1 font-weight-bold mt-2">Pie Chart Component</div>
            </div>
          </v-sheet>
        </v-card>

        <!-- Bar Chart (Requests Overview) -->
        <v-card elevation="0" border rounded="xl" class="pa-5 bg-white">
          <div class="d-flex justify-space-between align-center mb-4">
            <span class="text-body-1 font-weight-bold text-grey-darken-4">Requests Overview</span>
            <v-btn-toggle v-model="chartToggle" color="#2E8B75" density="compact" variant="outlined" divided rounded="lg">
              <v-btn size="small" class="text-none font-weight-bold" value="week">Week</v-btn>
              <v-btn size="small" class="text-none font-weight-bold" value="month">Month</v-btn>
            </v-btn-toggle>
          </div>
          <v-sheet height="200" color="transparent" class="d-flex align-center justify-center border border-dashed border-grey-lighten-2 rounded-lg">
            <div class="text-center">
              <v-icon size="x-large" color="#2E8B75">mdi-chart-bar</v-icon>
              <div class="text-grey-darken-1 font-weight-bold mt-2">Bar Chart Component</div>
            </div>
          </v-sheet>
        </v-card>

      </v-col>
    </v-row>

  </v-container>
</template>

<script setup>
import { ref, onMounted } from 'vue'

const kpiStats = ref([])
const serviceRequests = ref([])
const borrowRequests = ref([])
const systemLogs = ref([])
const loading = ref(true)

const chartToggle = ref('week')
const selectedDate = ref(new Date())

// Mock Data for Hotspot Barangays
const topBarangays = ref([
  { name: 'San Isidro', requests: 45, percentage: 85 },
  { name: 'San Fabian', requests: 32, percentage: 65 },
  { name: 'Maligaya', requests: 28, percentage: 55 },
  { name: 'Buneg', requests: 15, percentage: 30 },
  { name: 'Gumbuan', requests: 9, percentage: 15 },
])

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
  if (s === 'approved' || s === 'responding') return '#2E8B75'
  if (s === 'resolved' || s === 'returned') return 'green'
  if (s === 'rejected') return 'red'
  return 'grey'
}

const getHeatColor = (percentage) => {
  if (percentage > 70) return 'red'
  if (percentage > 40) return 'orange'
  return '#2E8B75'
}

onMounted(() => {
  fetchDashboardData()

  // Inject Leaflet for Heatmap
  const link = document.createElement('link')
  link.rel = 'stylesheet'
  link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'
  document.head.appendChild(link)

  const script = document.createElement('script')
  script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'
  script.onload = () => {
    const heatScript = document.createElement('script')
    heatScript.src = 'https://unpkg.com/leaflet.heat/dist/leaflet-heat.js'
    heatScript.onload = () => {
      if (!window.L) return

      const map = window.L.map('heatmap').setView([16.74, 121.62], 12)
      
      window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap'
      }).addTo(map)

      const heatData = [
        [16.745, 121.623, 0.9], // San Isidro area
        [16.751, 121.615, 0.7], 
        [16.732, 121.642, 0.8], // San Fabian area
        [16.748, 121.630, 0.6], // Maligaya area
        [16.730, 121.610, 0.5]  // Buneg area
      ]

      window.L.heatLayer(heatData, {radius: 25, blur: 15}).addTo(map)
    }
    document.head.appendChild(heatScript)
  }
  document.head.appendChild(script)
})
</script>

<style scoped>
.min-width-0 {
  min-width: 0;
}
.gap-4 {
  gap: 16px;
}
</style>