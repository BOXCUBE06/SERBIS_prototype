<template>
  <v-container fluid class="pa-6 dashboard-bg">

    <!-- Toolbar -->
    <div class="d-flex justify-space-between align-center flex-wrap gap-4 mb-6">
      <div>
        <h1 class="text-h4 font-weight-black mb-1">Dashboard</h1>
        <div class="text-subtitle-1 text-medium-emphasis">Welcome back! Here's what's happening today.</div>
      </div>

      <div class="d-flex align-center gap-4 flex-wrap">
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

    <!-- KPI strip: every headline number in one scannable row, independent of
         the trend card's height. Cramming these into a sidebar next to the
         hero card (the old layout) meant the two fought over vertical space —
         6 stats now need their own row instead of being squeezed into 5/12
         of one. -->
    <v-row v-if="loading" class="mb-2">
      <v-col cols="12"><v-skeleton-loader type="card" height="88"></v-skeleton-loader></v-col>
    </v-row>
    <v-row v-else dense class="mb-2">
      <v-col cols="6" sm="4" md="2" v-for="(stat, i) in kpiStats" :key="stat.title">
        <v-card
          elevation="0" rounded="xl" class="soft-card stagger-item kpi-tile pa-3 h-100 d-flex align-center"
          :class="{ 'cursor-pointer': stat.route }"
          :style="{ '--stagger-i': i }"
          :role="stat.route ? 'button' : undefined"
          :tabindex="stat.route ? 0 : undefined"
          @click="stat.route && goTo(stat.route)"
          @keydown.enter="stat.route && goTo(stat.route)"
        >
          <v-avatar :color="stat.color || 'primary'" variant="tonal" size="40" rounded="lg" class="mr-3 flex-shrink-0">
            <v-icon :color="stat.color || 'primary'" size="20">{{ stat.icon || 'mdi-chart-arc' }}</v-icon>
          </v-avatar>
          <div class="min-width-0">
            <div class="text-h6 font-weight-black lh-1">{{ displayValues[stat.title] ?? stat.value }}</div>
            <div class="text-caption font-weight-bold text-medium-emphasis text-truncate">{{ stat.title }}</div>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Trend row: headline total + the period-scoped bar chart, grouped
         together because both answer "what's happening in the selected
         period", unlike the KPI strip above (always all-time / right-now). -->
    <v-row v-if="loading" class="mb-2">
      <v-col cols="12" lg="7">
        <v-card elevation="0" rounded="xl" class="pa-6" style="min-height: 220px;">
          <v-skeleton-loader type="heading, text, image"></v-skeleton-loader>
        </v-card>
      </v-col>
      <v-col cols="12" lg="5">
        <v-skeleton-loader type="card" height="286"></v-skeleton-loader>
      </v-col>
    </v-row>

    <v-row v-else class="mb-2">
      <v-col cols="12" lg="7">
        <v-card elevation="0" rounded="xl" class="soft-card hero-tint stagger-item pa-6 h-100" :style="{ '--stagger-i': 6 }">
          <div class="d-flex justify-space-between align-start mb-2 flex-wrap gap-2">
            <div>
              <div class="text-caption font-weight-bold text-uppercase text-medium-emphasis">Requests Filed</div>
              <div class="text-caption text-medium-emphasis">{{ periodLabelFor(heroPeriod) }}</div>
            </div>
            <div class="d-flex align-center gap-3">
              <v-btn-toggle v-model="heroPeriod" mandatory variant="outlined" color="primary" density="compact" divided rounded="lg">
                <v-btn value="today" size="x-small" class="text-none font-weight-bold px-3">Today</v-btn>
                <v-btn value="week" size="x-small" class="text-none font-weight-bold px-3">Week</v-btn>
                <v-btn value="month" size="x-small" class="text-none font-weight-bold px-3">Month</v-btn>
              </v-btn-toggle>
              <v-avatar color="primary" variant="tonal" size="40" rounded="lg">
                <v-icon color="primary" size="20">mdi-chart-line</v-icon>
              </v-avatar>
            </div>
          </div>
          <div class="text-h1 font-weight-black mb-4">{{ displayValues.hero ?? heroTotal }}</div>
          <div style="height: 70px;">
            <Line v-if="chartDataRaw" :data="heroSparklineData" :options="sparklineOptions" />
          </div>
        </v-card>
      </v-col>

      <v-col cols="12" lg="5">
        <v-card elevation="0" rounded="xl" class="soft-card stagger-item h-100" :style="{ '--stagger-i': 7 }">
          <v-card-item class="pb-0">
            <div class="d-flex justify-space-between align-start flex-wrap gap-2">
              <div>
                <v-card-title class="text-body-1 font-weight-bold pa-0">Request Trend</v-card-title>
                <v-card-subtitle class="pa-0">{{ periodLabelFor(trendPeriod) }}</v-card-subtitle>
              </div>
              <v-btn-toggle v-model="trendPeriod" mandatory variant="outlined" color="primary" density="compact" divided rounded="lg">
                <v-btn value="today" size="x-small" class="text-none font-weight-bold px-3">Today</v-btn>
                <v-btn value="week" size="x-small" class="text-none font-weight-bold px-3">Week</v-btn>
                <v-btn value="month" size="x-small" class="text-none font-weight-bold px-3">Month</v-btn>
              </v-btn-toggle>
            </div>
          </v-card-item>
          <v-card-text class="pt-2">
            <v-sheet height="220" color="transparent">
              <Line v-if="chartDataRaw" :data="barChartData" :options="chartOptions" />
              <div class="d-flex align-center justify-center h-100" v-else>
                <v-progress-circular indeterminate color="primary"></v-progress-circular>
              </div>
            </v-sheet>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- Geographic row: map + its data-table fallback, all-time scope -->
    <v-row class="mb-2">
      <v-col cols="12" lg="7">
        <v-card elevation="0" rounded="xl" class="soft-card stagger-item h-100" :style="{ '--stagger-i': 8 }">
          <v-card-item>
            <div class="d-flex justify-space-between align-start flex-wrap gap-2">
              <div>
                <v-card-title class="text-body-1 font-weight-bold pa-0">Requests by Barangay</v-card-title>
                <v-card-subtitle class="pa-0">{{ periodLabelFor(mapPeriod) }}</v-card-subtitle>
              </div>
              <v-btn-toggle v-model="mapPeriod" mandatory variant="outlined" color="primary" density="compact" divided rounded="lg">
                <v-btn value="today" size="x-small" class="text-none font-weight-bold px-3">Today</v-btn>
                <v-btn value="week" size="x-small" class="text-none font-weight-bold px-3">Week</v-btn>
                <v-btn value="month" size="x-small" class="text-none font-weight-bold px-3">Month</v-btn>
                <v-btn value="all" size="x-small" class="text-none font-weight-bold px-3">All</v-btn>
              </v-btn-toggle>
            </div>
          </v-card-item>
          <v-card-text class="pt-0">
            <div ref="mapEl" style="height: 320px; width: 100%; border-radius: 8px; z-index: 1;" class="subtle-surface"></div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" lg="5">
        <v-card elevation="0" rounded="xl" class="soft-card stagger-item h-100" :style="{ '--stagger-i': 9 }">
          <v-card-item>
            <div class="d-flex justify-space-between align-start flex-wrap gap-2">
              <div>
                <v-card-title class="text-body-1 font-weight-bold pa-0">Top Barangays</v-card-title>
                <v-card-subtitle class="pa-0">{{ periodLabelFor(zonesPeriod) }}</v-card-subtitle>
              </div>
              <v-btn-toggle v-model="zonesPeriod" mandatory variant="outlined" color="primary" density="compact" divided rounded="lg">
                <v-btn value="today" size="x-small" class="text-none font-weight-bold px-3">Today</v-btn>
                <v-btn value="week" size="x-small" class="text-none font-weight-bold px-3">Week</v-btn>
                <v-btn value="month" size="x-small" class="text-none font-weight-bold px-3">Month</v-btn>
                <v-btn value="all" size="x-small" class="text-none font-weight-bold px-3">All</v-btn>
              </v-btn-toggle>
            </div>
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

    <!-- Operational row: the feed staff act on, paired with the all-time
         category breakdown that explains what it's mostly made of -->
    <v-row>
      <v-col cols="12" lg="7">
        <v-card elevation="0" rounded="xl" class="soft-card stagger-item h-100" :style="{ '--stagger-i': 10 }">
          <v-card-item class="pb-0">
            <div class="d-flex justify-space-between align-start flex-wrap gap-2">
              <div>
                <v-card-title class="text-body-1 font-weight-bold pa-0">Activity Feed</v-card-title>
                <v-card-subtitle class="pa-0">{{ periodLabelFor(feedPeriod) }}</v-card-subtitle>
              </div>
              <v-btn-toggle v-model="feedPeriod" mandatory variant="outlined" color="primary" density="compact" divided rounded="lg">
                <v-btn value="today" size="x-small" class="text-none font-weight-bold px-3">Today</v-btn>
                <v-btn value="week" size="x-small" class="text-none font-weight-bold px-3">Week</v-btn>
                <v-btn value="month" size="x-small" class="text-none font-weight-bold px-3">Month</v-btn>
              </v-btn-toggle>
            </div>
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
              No activity {{ periodLabelFor(feedPeriod).toLowerCase() }}
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
        <v-card elevation="0" rounded="xl" class="soft-card stagger-item h-100" :style="{ '--stagger-i': 11 }">
          <v-card-item class="pb-0">
            <div class="d-flex justify-space-between align-start flex-wrap gap-2">
              <div>
                <v-card-title class="text-body-1 font-weight-bold pa-0">Most Requested</v-card-title>
                <v-card-subtitle class="pa-0">{{ periodLabelFor(volumePeriod) }}</v-card-subtitle>
              </div>
              <v-btn-toggle v-model="volumePeriod" mandatory variant="outlined" color="primary" density="compact" divided rounded="lg">
                <v-btn value="today" size="x-small" class="text-none font-weight-bold px-3">Today</v-btn>
                <v-btn value="week" size="x-small" class="text-none font-weight-bold px-3">Week</v-btn>
                <v-btn value="month" size="x-small" class="text-none font-weight-bold px-3">Month</v-btn>
                <v-btn value="all" size="x-small" class="text-none font-weight-bold px-3">All</v-btn>
              </v-btn-toggle>
            </div>
          </v-card-item>
          <div class="px-4 pb-2">
            <v-btn-toggle v-model="volumeToggle" color="primary" density="compact" variant="outlined" divided rounded="lg">
              <v-btn size="small" class="text-none font-weight-bold" value="services">Services</v-btn>
              <v-btn size="small" class="text-none font-weight-bold" value="items">Items</v-btn>
            </v-btn-toggle>
          </div>
          <v-card-text class="pt-0">
            <v-sheet height="320" color="transparent">
              <Bar v-if="chartDataRaw && volumeChartData.labels.length" :data="volumeChartData" :options="volumeChartOptions" :plugins="[volumeValueLabelsPlugin]" />
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
import { useRouter } from 'vue-router'
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
import { API_BASE } from '@/config/api'

ChartJS.register(Tooltip, Legend, CategoryScale, LinearScale, BarElement, LineElement, PointElement, Filler)

const router = useRouter()
const goTo = (route) => router.push(route)

const kpiStats = ref([])
const serviceRequests = ref([])
const borrowRequests = ref([])
const systemLogs = ref([])
const loading = ref(true)

const chartDataRaw = ref(null)
const volumeToggle = ref('services')
const feedTab = ref('all')

// Each stat-bearing card owns its period independently now — a single
// shared toggle used to drive the hero card and the trend chart while the
// map, the barangay ranking and the category breakdown were silently
// always all-time, which is what the map/zones/volume cards default to
// here so their look doesn't change until someone touches the toggle.
const heroPeriod = ref('week')
const trendPeriod = ref('week')
const feedPeriod = ref('week')
const mapPeriod = ref('all')
const zonesPeriod = ref('all')
const volumePeriod = ref('all')

const mapDataByPeriod = ref({}) // { today|week|month|all: [{name, requests}] } — feeds the Leaflet map + Top Barangays
const pieDataByPeriod = ref({}) // { today|week|month|all: { services: {...}, items: {...} } }

const PERIOD_LABELS = { today: 'Today', week: 'Last 7 days', month: 'Last 30 days', all: 'All-time' }
const periodLabelFor = (period) => PERIOD_LABELS[period] || ''

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
    const response = await fetch(`${API_BASE}/admin/dashboard`, {
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
    mapDataByPeriod.value = data.mapDataByPeriod || {}
    chartDataRaw.value = data.charts || null
    pieDataByPeriod.value = data.charts?.pieByPeriod || {}

  } catch (error) {
    console.error("Failed to load dashboard:", error)
  } finally {
    loading.value = false
  }
}

// The feed merges two models that use two different status vocabularies:
//   ServiceRequest     — Pending, Responding, Resolved, Cancelled, Disapproved
//   EquipmentBorrowing — Pending, Approved, Released, Returned, Denied
// Only six of those nine were listed, and the one error branch tested for
// 'Rejected', which neither model writes. Every refused item — Denied,
// Disapproved, Cancelled — fell through to grey and read as an unknown state,
// so no red ever reached this feed.
const getStatusColor = (status) => {
  if (!status) return 'grey'
  switch (status.toLowerCase()) {
    case 'pending': return 'warning'
    case 'approved':
    case 'responding':
    case 'released': return 'primary'
    case 'resolved':
    case 'returned': return 'success'
    case 'cancelled':
    case 'disapproved':
    case 'denied': return 'error'
    default: return 'grey'
  }
}

const getHeatColor = (percentage) => {
  if (percentage > 70) return 'error'
  if (percentage > 40) return 'warning'
  return 'primary'
}

// Top 5 zones ranked by request volume, percentage relative to the busiest zone
const topZones = computed(() => {
  const list = mapDataByPeriod.value[zonesPeriod.value] || []
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
  if (heroPeriod.value === 'today') {
    const series = chartDataRaw.value.bar.week
    return series.data[series.data.length - 1] || 0
  }
  const series = chartDataRaw.value.bar[heroPeriod.value]
  return series.data.reduce((a, b) => a + b, 0)
})

watch(heroTotal, (val) => animateValue('hero', val))

const heroSparklineData = computed(() => {
  if (!chartDataRaw.value) return { labels: [], datasets: [] }
  const key = heroPeriod.value === 'today' ? 'week' : heroPeriod.value
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
    return isWithinPeriod(item.date, feedPeriod.value)
  })
})

const volumeChartData = computed(() => {
  const source = pieDataByPeriod.value[volumePeriod.value]?.[volumeToggle.value]
  if (!source) return { labels: [], datasets: [] }
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

// Compare-Categories charts should show every value as text, not hover-only —
// a vue-chartjs `:plugins` prop scopes this to the Request Volume chart alone,
// so the trend/sparkline Line charts elsewhere on the page stay uncluttered.
const volumeValueLabelsPlugin = {
  id: 'volumeValueLabels',
  afterDatasetsDraw(chart) {
    const { ctx } = chart
    const onSurface = getComputedStyle(document.documentElement).getPropertyValue('--v-theme-on-surface').trim() || '0,0,0'
    ctx.save()
    ctx.fillStyle = `rgba(${onSurface}, 0.85)`
    ctx.font = '700 11px sans-serif'
    ctx.textBaseline = 'middle'
    ctx.textAlign = 'left'
    chart.data.datasets.forEach((dataset, dsIndex) => {
      chart.getDatasetMeta(dsIndex).data.forEach((bar, i) => {
        const value = dataset.data[i]
        if (value === undefined || value === null) return
        ctx.fillText(String(value), bar.x + 6, bar.y)
      })
    })
    ctx.restore()
  }
}

const volumeChartOptions = {
  indexAxis: 'y',
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
  scales: {
    x: { beginAtZero: true, ticks: { precision: 0 }, grace: '15%' },
    y: { ticks: { font: { size: 11 } } }
  }
}

// Daily counts over a rolling window — a time axis, so a Line/area chart
// reads the trend correctly. A bar-per-day implies discrete unrelated
// categories, which is the wrong shape for this data (see chart-type rules:
// time-series belongs on Line, Bar is for unordered category comparison).
const barChartData = computed(() => {
  if (!chartDataRaw.value) return { labels: [], datasets: [] }
  const key = trendPeriod.value === 'today' ? 'week' : trendPeriod.value
  const source = chartDataRaw.value.bar[key]
  return {
    labels: source.labels,
    datasets: [{
      label: 'Requests',
      data: source.data,
      borderColor: '#297A67',
      backgroundColor: 'rgba(41, 122, 103, 0.15)',
      fill: true,
      borderWidth: 2,
      tension: 0.35,
      pointRadius: 3,
      pointHoverRadius: 5,
      pointBackgroundColor: '#297A67',
      pointBorderColor: '#fff',
      pointBorderWidth: 1,
    }]
  }
})

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
  interaction: { mode: 'index', intersect: false },
  scales: {
    x: { grid: { display: false } },
    y: { beginAtZero: true, ticks: { precision: 0 } }
  }
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
  Object.fromEntries((mapDataByPeriod.value[mapPeriod.value] || []).map(b => [b.name, b.requests]))
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

.lh-1 {
  line-height: 1;
}

.kpi-tile {
  min-height: 72px;
}

.legend-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
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
