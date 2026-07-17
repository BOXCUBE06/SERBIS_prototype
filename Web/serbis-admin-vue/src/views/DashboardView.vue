<template>
  <v-container fluid class="pa-6 dashboard-bg">

    <!-- Toolbar -->
    <div class="d-flex justify-space-between align-center flex-wrap gap-4 mb-6">
      <div>
        <h1 class="text-h4 font-weight-black mb-1">Dashboard</h1>
        <div class="text-subtitle-1 text-medium-emphasis">Welcome back! Here's what's happening today.</div>
      </div>

      <div class="d-flex align-center gap-4 flex-wrap">
        <!-- Global period filter — drives hero metric, overview chart & activity feed -->
        <v-btn-toggle v-model="periodFilter" mandatory variant="outlined" color="primary" density="comfortable" divided rounded="lg">
          <v-btn value="today" size="small" class="text-none font-weight-bold px-4">Today</v-btn>
          <v-btn value="week" size="small" class="text-none font-weight-bold px-4">Week</v-btn>
          <v-btn value="month" size="small" class="text-none font-weight-bold px-4">Month</v-btn>
        </v-btn-toggle>

        <v-menu location="bottom end">
          <template v-slot:activator="{ props }">
            <v-btn icon="mdi-bell-outline" variant="outlined" v-bind="props" aria-label="System notifications">
              <v-badge color="error" dot v-if="systemLogs.length > 0">
                <v-icon>mdi-bell-outline</v-icon>
              </v-badge>
              <v-icon v-else>mdi-bell-outline</v-icon>
            </v-btn>
          </template>
          <v-card min-width="320" elevation="4" rounded="lg" class="border">
            <v-list density="compact" class="pa-0">
              <v-list-subheader class="font-weight-bold text-uppercase py-2">System Logs</v-list-subheader>
              <v-divider></v-divider>
              <template v-if="systemLogs.length">
                <v-list-item v-for="(log, i) in systemLogs.slice(0, 5)" :key="'log-'+i" class="py-3 border-b">
                  <template v-slot:prepend>
                    <v-avatar color="primary" variant="tonal" size="32" class="mr-3">
                      <v-icon color="primary" size="small">mdi-history</v-icon>
                    </v-avatar>
                  </template>
                  <v-list-item-title class="text-body-2 font-weight-bold">{{ log.action }}</v-list-item-title>
                  <v-list-item-subtitle class="text-caption">{{ log.user }} &bull; {{ log.module }}</v-list-item-subtitle>
                  <template v-slot:append>
                    <span class="text-caption text-medium-emphasis">{{ log.time }}</span>
                  </template>
                </v-list-item>
              </template>
              <div v-else class="pa-4 text-center text-caption text-medium-emphasis">No recent logs</div>
            </v-list>
          </v-card>
        </v-menu>

        <v-avatar color="primary" size="44" class="cursor-pointer font-weight-bold text-white">J</v-avatar>
      </div>
    </div>

    <!-- Hero row -->
    <v-row v-if="loading" class="mb-2">
      <v-col cols="12" lg="7">
        <v-card elevation="0" rounded="xl" class="pa-6" style="min-height: 220px;">
          <v-skeleton-loader type="heading, text, image"></v-skeleton-loader>
        </v-card>
      </v-col>
      <v-col cols="12" lg="5">
        <v-skeleton-loader type="card@2"></v-skeleton-loader>
      </v-col>
    </v-row>

    <v-row v-else class="mb-2">
      <!-- Headline metric -->
      <v-col cols="12" lg="7">
        <v-card elevation="0" rounded="xl" class="soft-card hero-tint stagger-item pa-6 h-100" :style="{ '--stagger-i': 0 }">
          <div class="d-flex justify-space-between align-start mb-2">
            <div>
              <div class="text-caption font-weight-bold text-uppercase text-medium-emphasis">Total Requests</div>
              <div class="text-caption text-medium-emphasis">{{ periodLabel }}</div>
            </div>
            <v-avatar color="primary" variant="tonal" size="40" rounded="lg">
              <v-icon color="primary" size="20">mdi-chart-line</v-icon>
            </v-avatar>
          </div>
          <div class="text-h1 font-weight-black mb-4">{{ displayValues.hero ?? heroTotal }}</div>
          <div style="height: 70px;">
            <Line v-if="chartDataRaw" :data="heroSparklineData" :options="sparklineOptions" />
          </div>
        </v-card>
      </v-col>

      <!-- Secondary stat grid -->
      <v-col cols="12" lg="5">
        <v-row dense class="h-100">
          <v-col cols="6" v-for="(stat, i) in kpiStats" :key="stat.title">
            <v-card elevation="0" rounded="xl" class="soft-card stagger-item pa-4 h-100" :style="{ '--stagger-i': i + 1 }">
              <v-avatar :color="stat.color || 'primary'" variant="tonal" size="36" rounded="lg" class="mb-3">
                <v-icon :color="stat.color || 'primary'" size="18">{{ stat.icon || 'mdi-chart-arc' }}</v-icon>
              </v-avatar>
              <div class="text-h5 font-weight-black mb-1">{{ displayValues[stat.title] ?? stat.value }}</div>
              <div class="text-caption font-weight-bold text-medium-emphasis">{{ stat.title }}</div>
            </v-card>
          </v-col>
        </v-row>
      </v-col>
    </v-row>

    <!-- Heatmap & Zones -->
    <v-row class="mb-2">
      <v-col cols="12" lg="7">
        <v-card elevation="0" rounded="xl" class="soft-card stagger-item h-100" :style="{ '--stagger-i': 2 }">
          <v-card-item>
            <v-card-title class="text-body-1 font-weight-bold">Incident Heatmap</v-card-title>
            <v-card-subtitle>All-time distribution</v-card-subtitle>
            <template v-slot:append>
              <v-icon>mdi-map-marker-radius</v-icon>
            </template>
          </v-card-item>
          <v-card-text class="pt-0">
            <div ref="mapEl" style="height: 320px; width: 100%; border-radius: 8px; z-index: 1;" class="subtle-surface"></div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" lg="5">
        <v-card elevation="0" rounded="xl" class="soft-card stagger-item h-100" :style="{ '--stagger-i': 3 }">
          <v-card-item>
            <v-card-title class="text-body-1 font-weight-bold">High Request Zones</v-card-title>
            <v-card-subtitle>All-time</v-card-subtitle>
            <template v-slot:append>
              <v-icon>mdi-fire</v-icon>
            </template>
          </v-card-item>
          <v-card-text class="pt-2">
            <div v-if="!topZones.length" class="text-center text-caption text-medium-emphasis py-8">
              No zone activity yet
            </div>
            <div v-else>
              <div v-for="(brgy, index) in topZones" :key="index" class="d-flex align-center py-2">
                <div class="rank-badge mr-3">{{ index + 1 }}</div>
                <div class="flex-grow-1 min-width-0">
                  <div class="d-flex justify-space-between align-center mb-1">
                    <span class="text-body-2 font-weight-bold text-truncate">{{ brgy.name }}</span>
                    <span class="text-caption font-weight-bold text-medium-emphasis ml-2">{{ brgy.requests }}</span>
                  </div>
                  <div class="subtle-surface rounded-pill" style="height: 6px; overflow: hidden;">
                    <div class="rounded-pill h-100" :class="`bg-${getHeatColor(brgy.percentage)}`" :style="{ width: brgy.percentage + '%' }"></div>
                  </div>
                </div>
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- Activity feed & charts -->
    <v-row>
      <v-col cols="12" lg="7">
        <v-card elevation="0" rounded="xl" class="soft-card stagger-item h-100" :style="{ '--stagger-i': 4 }">
          <v-card-item class="pb-0">
            <v-card-title class="text-body-1 font-weight-bold">Activity Feed</v-card-title>
            <v-card-subtitle>{{ periodLabel }}</v-card-subtitle>
          </v-card-item>
          <v-tabs v-model="feedTab" color="primary" density="compact" class="px-4">
            <v-tab value="all" class="text-none font-weight-bold">All</v-tab>
            <v-tab value="service" class="text-none font-weight-bold">Services</v-tab>
            <v-tab value="borrow" class="text-none font-weight-bold">Borrowing</v-tab>
          </v-tabs>
          <v-divider></v-divider>

          <v-card-text class="pt-2">
            <v-skeleton-loader v-if="loading" type="list-item-avatar-two-line@5"></v-skeleton-loader>

            <div v-else-if="!filteredFeed.length" class="text-center text-caption text-medium-emphasis py-8">
              No activity {{ periodLabel.toLowerCase() }}
            </div>

            <v-list v-else density="comfortable" class="pa-0">
              <v-list-item v-for="(item, index) in filteredFeed.slice(0, 8)" :key="index" class="px-0">
                <template v-slot:prepend>
                  <v-avatar color="primary" variant="tonal" size="40" class="mr-1">
                    <v-icon color="primary" size="18">{{ item.icon }}</v-icon>
                  </v-avatar>
                </template>
                <v-list-item-title class="text-body-2 font-weight-bold text-truncate">{{ item.name }}</v-list-item-title>
                <v-list-item-subtitle class="text-caption text-truncate">{{ item.meta }} &bull; {{ item.date }}</v-list-item-subtitle>
                <template v-slot:append>
                  <v-chip :color="getStatusColor(item.status)" size="x-small" variant="tonal" class="font-weight-bold">{{ item.status }}</v-chip>
                </template>
              </v-list-item>
            </v-list>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" lg="5">
        <v-card elevation="0" rounded="xl" class="soft-card stagger-item mb-6" :style="{ '--stagger-i': 5 }">
          <v-card-item class="pb-0">
            <v-card-title class="text-body-1 font-weight-bold">Requests Overview</v-card-title>
            <v-card-subtitle>{{ periodLabel }}</v-card-subtitle>
          </v-card-item>
          <v-card-text class="pt-2">
            <v-sheet height="200" color="transparent">
              <Bar v-if="chartDataRaw" :data="barChartData" :options="chartOptions" />
              <div class="d-flex align-center justify-center h-100" v-else>
                <v-progress-circular indeterminate color="primary"></v-progress-circular>
              </div>
            </v-sheet>
          </v-card-text>
        </v-card>

        <v-card elevation="0" rounded="xl" class="soft-card stagger-item" :style="{ '--stagger-i': 6 }">
          <v-card-item class="pb-0">
            <v-card-title class="text-body-1 font-weight-bold">Request Volume</v-card-title>
            <v-card-subtitle>All-time by category</v-card-subtitle>
          </v-card-item>
          <div class="px-4 pb-2">
            <v-btn-toggle v-model="volumeToggle" color="primary" density="compact" variant="outlined" divided rounded="lg">
              <v-btn size="small" class="text-none font-weight-bold" value="services">Services</v-btn>
              <v-btn size="small" class="text-none font-weight-bold" value="items">Items</v-btn>
            </v-btn-toggle>
          </div>
          <v-card-text class="pt-0">
            <v-sheet height="220" color="transparent">
              <Bar v-if="chartDataRaw && volumeChartData.labels.length" :data="volumeChartData" :options="volumeChartOptions" />
              <div v-else-if="chartDataRaw" class="text-caption text-medium-emphasis text-center py-8">No data yet</div>
              <div class="d-flex align-center justify-center h-100" v-else>
                <v-progress-circular indeterminate color="primary"></v-progress-circular>
              </div>
            </v-sheet>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

  </v-container>
</template>

<script setup>
import { ref, reactive, onMounted, onUnmounted, computed, watch } from 'vue'
import { getToken } from '@/composables/authToken'
import {
  Chart as ChartJS, Tooltip, Legend, CategoryScale, LinearScale,
  BarElement, LineElement, PointElement, Filler
} from 'chart.js'
import { Bar, Line } from 'vue-chartjs'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
// Real PSA boundaries, Echague (PSGC PH023112000), filtered to the seeded
// barangays. properties.name matches tbl_barangay.barangay_name exactly —
// the choropleth joins on it, so the two must stay in step.
import barangayBoundaries from '@/assets/echague-barangays.json'

ChartJS.register(Tooltip, Legend, CategoryScale, LinearScale, BarElement, LineElement, PointElement, Filler)

const kpiStats = ref([])
const serviceRequests = ref([])
const borrowRequests = ref([])
const systemLogs = ref([])
const loading = ref(true)

const chartDataRaw = ref(null)
const volumeToggle = ref('services')
const periodFilter = ref('week')
const feedTab = ref('all')

const topBarangays = ref([]) // Raw data, feeds the Leaflet map

const periodLabel = computed(() => ({ today: 'Today', week: 'Last 7 days', month: 'Last 30 days' }[periodFilter.value]))

// Count-up animation for headline numbers on load / filter change
const displayValues = reactive({})
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches

const animateValue = (key, target) => {
  const numTarget = Number(String(target).replace(/,/g, '')) || 0
  if (reduceMotion) {
    displayValues[key] = target
    return
  }
  const duration = 600
  const start = performance.now()
  const step = (now) => {
    const progress = Math.min((now - start) / duration, 1)
    const eased = 1 - Math.pow(1 - progress, 3)
    displayValues[key] = Math.round(numTarget * eased).toLocaleString()
    if (progress < 1) requestAnimationFrame(step)
  }
  requestAnimationFrame(step)
}

watch(kpiStats, (stats) => {
  stats.forEach(stat => animateValue(stat.title, stat.value))
})

const fetchDashboardData = async () => {
  loading.value = true
  try {
    const response = await fetch('http://localhost:8000/api/admin/dashboard', {
      headers: {
        'Authorization': `Bearer ${getToken()}`,
        'Accept': 'application/json'
      }
    })

    if (!response.ok) throw new Error('Network response error')

    const data = await response.json()

    kpiStats.value = data.kpiStats || []
    serviceRequests.value = data.serviceRequests || []
    borrowRequests.value = data.borrowRequests || []
    systemLogs.value = data.systemLogs || []
    topBarangays.value = data.mapData || []
    chartDataRaw.value = data.charts || null

  } catch (error) {
    console.error("Failed to load dashboard:", error)
  } finally {
    loading.value = false
  }
}

const getStatusColor = (status) => {
  if (!status) return 'grey'
  const s = status.toLowerCase()
  if (s === 'pending') return 'warning'
  if (s === 'approved' || s === 'responding') return 'primary'
  if (s === 'resolved' || s === 'returned') return 'success'
  if (s === 'rejected') return 'error'
  return 'grey'
}

const getHeatColor = (percentage) => {
  if (percentage > 70) return 'error'
  if (percentage > 40) return 'warning'
  return 'primary'
}

// Top 5 zones ranked by request volume, percentage relative to the busiest zone
const topZones = computed(() => {
  const list = topBarangays.value
  if (!list.length) return []
  const max = Math.max(...list.map(b => b.requests))
  return [...list]
    .sort((a, b) => b.requests - a.requests)
    .slice(0, 5)
    .map(b => ({ ...b, percentage: max > 0 ? Math.round((b.requests / max) * 100) : 0 }))
})

// Headline metric: real aggregate from the backend's day-by-day series (not the 5-item sample lists)
const heroTotal = computed(() => {
  if (!chartDataRaw.value) return 0
  if (periodFilter.value === 'today') {
    const series = chartDataRaw.value.bar.week
    return series.data[series.data.length - 1] || 0
  }
  const series = chartDataRaw.value.bar[periodFilter.value]
  return series.data.reduce((a, b) => a + b, 0)
})

watch(heroTotal, (val) => animateValue('hero', val))

const heroSparklineData = computed(() => {
  if (!chartDataRaw.value) return { labels: [], datasets: [] }
  const key = periodFilter.value === 'today' ? 'week' : periodFilter.value
  const source = chartDataRaw.value.bar[key]
  return {
    labels: source.labels,
    datasets: [{
      data: source.data,
      borderColor: '#297A67',
      backgroundColor: 'rgba(41, 122, 103, 0.15)',
      fill: true,
      borderWidth: 2,
      tension: 0.4
    }]
  }
})

const sparklineOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
  scales: { x: { display: false }, y: { display: false } },
  elements: { point: { radius: 0 } }
}

// Unified activity feed — merges service + borrow requests into one timeline
const unifiedFeed = computed(() => {
  const svc = serviceRequests.value.map(item => ({
    kind: 'service', name: item.resident, meta: item.type, date: item.date, status: item.status, icon: 'mdi-account'
  }))
  const brw = borrowRequests.value.map(item => ({
    kind: 'borrow', name: item.borrower, meta: item.equipment, date: item.date, status: item.status, icon: 'mdi-toolbox-outline'
  }))
  return [...svc, ...brw].sort((a, b) => new Date(b.date) - new Date(a.date))
})

const isWithinPeriod = (dateStr, period) => {
  const d = new Date(dateStr)
  if (isNaN(d.getTime())) return true
  const now = new Date()
  if (period === 'today') return d.toDateString() === now.toDateString()
  if (period === 'week') { const wk = new Date(now); wk.setDate(now.getDate() - 7); return d >= wk }
  if (period === 'month') { const mo = new Date(now); mo.setDate(now.getDate() - 30); return d >= mo }
  return true
}

const filteredFeed = computed(() => {
  return unifiedFeed.value.filter(item => {
    if (feedTab.value !== 'all' && item.kind !== feedTab.value) return false
    return isWithinPeriod(item.date, periodFilter.value)
  })
})

const volumeChartData = computed(() => {
  if (!chartDataRaw.value) return { labels: [], datasets: [] }
  const source = chartDataRaw.value.pie[volumeToggle.value]
  const paired = source.labels
    .map((label, i) => ({ label, value: source.data[i] }))
    .sort((a, b) => b.value - a.value)
    .slice(0, 8)
  return {
    labels: paired.map(p => p.label),
    datasets: [{
      label: 'Requests',
      backgroundColor: '#297A67',
      borderRadius: 4,
      barThickness: 14,
      data: paired.map(p => p.value)
    }]
  }
})

const volumeChartOptions = {
  indexAxis: 'y',
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
  scales: {
    x: { beginAtZero: true, ticks: { precision: 0 } },
    y: { ticks: { font: { size: 11 } } }
  }
}

const barChartData = computed(() => {
  if (!chartDataRaw.value) return { labels: [], datasets: [] }
  const key = periodFilter.value === 'today' ? 'week' : periodFilter.value
  const source = chartDataRaw.value.bar[key]
  return {
    labels: source.labels,
    datasets: [{
      label: 'Requests',
      backgroundColor: '#297A67',
      borderRadius: 4,
      data: source.data
    }]
  }
})

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
  scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
}

// Define colors based on request density
const getMapColor = (d) => {
  return d > 30 ? '#d32f2f' : // High (Red)
         d > 15 ? '#f57c00' : // Medium (Orange)
         d > 0  ? '#2E8B75' : // Low (Green)
                  '#e0e0e0';  // Zero requests (Grey)
}

const mapEl = ref(null)
let map = null
let geoLayer = null

// Keyed on the exact backend name — no case folding, so a rename on either
// side fails loudly as an unmatched grey polygon rather than silently.
const requestCountByName = computed(() =>
  Object.fromEntries(topBarangays.value.map(b => [b.name, b.requests]))
)

const styleFor = (feature) => ({
  fillColor: getMapColor(requestCountByName.value[feature.properties.name] ?? 0),
  weight: 2,
  opacity: 1,
  color: 'white',
  dashArray: '3',
  fillOpacity: 0.7,
})

const tooltipFor = (feature) => {
  const name = feature.properties.name
  const count = requestCountByName.value[name] ?? 0
  return `<b>${name}</b><br>${count} ${count === 1 ? 'Request' : 'Requests'}`
}

onMounted(() => {
  fetchDashboardData()

  map = L.map(mapEl.value)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap',
    className: 'map-tiles',
  }).addTo(map)

  geoLayer = L.geoJSON(barangayBoundaries, {
    style: styleFor,
    onEachFeature: (feature, layer) => layer.bindTooltip(tooltipFor(feature)),
  }).addTo(map)

  // Frame the barangays themselves rather than hardcoding a centre and zoom,
  // so the view stays correct if the boundary file changes.
  map.fitBounds(geoLayer.getBounds(), { padding: [12, 12] })
})

// Repaint whenever counts arrive or change. The map is built on mount with
// whatever data exists (usually none), so this — not a timer — is what makes
// the fetch land.
watch(requestCountByName, () => {
  if (!geoLayer) return
  geoLayer.setStyle(styleFor)
  geoLayer.eachLayer((layer) => layer.setTooltipContent(tooltipFor(layer.feature)))
})

onUnmounted(() => {
  map?.remove()
  map = null
  geoLayer = null
})
</script>

<style scoped>
.dashboard-bg {
  background-color: rgb(var(--v-theme-background));
}
.min-width-0 {
  min-width: 0;
}
.gap-4 {
  gap: 16px;
}

/* Soft UI Evolution: layered shadow depth instead of flat borders, theme-aware */
.soft-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  box-shadow: 0 1px 2px rgba(var(--v-theme-on-surface), 0.04), 0 4px 14px rgba(var(--v-theme-on-surface), 0.08);
  transition: transform 220ms cubic-bezier(0.16, 1, 0.3, 1), box-shadow 220ms cubic-bezier(0.16, 1, 0.3, 1);
}
.soft-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 4px 10px rgba(var(--v-theme-on-surface), 0.06), 0 12px 24px rgba(var(--v-theme-on-surface), 0.14);
}

.hero-tint {
  background: linear-gradient(135deg, rgba(var(--v-theme-primary), 0.10), rgba(var(--v-theme-primary), 0.02));
}

.subtle-surface {
  background-color: rgba(var(--v-theme-on-surface), 0.05);
}

/* OSM ships only light tiles, so a raster filter is the one way to keep the
   map from being a floodlight in dark mode without adding a tile provider (and
   an API key). Applied to the tile layer alone — the choropleth lives in the
   overlay pane above it and keeps its true colours. */
.v-theme--dark .map-tiles {
  filter: invert(1) hue-rotate(180deg) brightness(0.95) contrast(0.9) saturate(0.8);
}

.rank-badge {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 800;
  background-color: rgba(var(--v-theme-on-surface), 0.06);
  flex-shrink: 0;
}

.stagger-item {
  animation: dashFadeUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
  animation-delay: calc(var(--stagger-i, 0) * 60ms);
}

@keyframes dashFadeUp {
  from {
    opacity: 0;
    transform: translateY(12px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@media (prefers-reduced-motion: reduce) {
  .soft-card,
  .soft-card:hover {
    transition: none;
    transform: none;
  }
  .stagger-item {
    animation: none;
  }
}
</style>
