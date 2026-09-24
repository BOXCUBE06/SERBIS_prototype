<template>
  <div>
    <v-alert v-if="unavailable" type="info" variant="tonal" rounded="lg" class="mb-4">
      The map and volume charts need access to the Dashboard section.
    </v-alert>
    <template v-else>
    <!-- Trend row: headline total + the period-scoped bar chart, grouped
         together because both answer "what's happening in the selected
         period". -->
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
            <!-- 460, not the 320 this started at. Leaflet frames the view on
                 the barangay polygons (fitBounds, below), and that cluster is
                 close to square, so a 320px box in a 7/12 column drew a 3.3:1
                 letterbox: boundaries in the middle third, the rest tiles with
                 nothing plotted on them. -->
            <div ref="mapEl" style="height: 460px; width: 100%; border-radius: 8px; z-index: 1;" class="subtle-surface"></div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" lg="5">
        <v-card elevation="0" rounded="xl" class="soft-card stagger-item h-100" :style="{ '--stagger-i': 9 }">
          <v-card-item>
            <div class="d-flex justify-space-between align-start flex-wrap gap-2">
              <div>
                <v-card-title class="text-body-1 font-weight-bold pa-0">Barangays with Most Requests</v-card-title>
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
            <div v-if="topZones.length === 0 && walkInCount === 0" class="text-center text-caption text-medium-emphasis py-8">
              No zone activity yet
            </div>
            <div v-else-if="topZones.length === 0" class="text-center text-caption text-medium-emphasis py-8">
              No barangay activity yet
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

            <!-- Requests filed at the counter carry no barangay anywhere in
                 the schema, so they cannot be ranked or drawn on the map. They
                 used to be dropped by the join instead, which left both this
                 list and the map reporting fewer requests than exist with
                 nothing explaining the gap. Counted here instead, beside the
                 reconciled total, so the section adds up. -->
            <template v-if="sectionTotal > 0">
              <v-divider class="my-2"></v-divider>
              <div class="d-flex justify-space-between align-center py-1">
                <span class="text-caption text-medium-emphasis">Walk-in (no barangay)</span>
                <span class="text-caption font-weight-bold text-medium-emphasis">{{ walkInCount }}</span>
              </div>
              <div class="d-flex justify-space-between align-center py-1">
                <span class="text-caption font-weight-bold text-high-emphasis">Total requests</span>
                <span class="text-caption font-weight-bold text-high-emphasis">{{ sectionTotal }}</span>
              </div>
            </template>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
    <v-row class="mb-2">
      <v-col cols="12">
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
              <Bar v-if="chartDataRaw && volumeChartData.labels.length > 0" :data="volumeChartData" :options="volumeChartOptions" :plugins="[volumeValueLabelsPlugin]" />
              <div v-else-if="chartDataRaw" class="text-caption text-medium-emphasis text-center py-8">No data yet</div>
              <div class="d-flex align-center justify-center h-100" v-else>
                <v-progress-circular indeterminate color="primary"></v-progress-circular>
              </div>
            </v-sheet>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onUnmounted, computed, watch } from 'vue'
import { useTheme } from 'vuetify'
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

// Chart.js draws to canvas, not the DOM, so it can't read CSS custom
// properties the way the rest of the app does -- these read the active
// theme's resolved hex through Vuetify's own reactive theme instance
// instead, so the charts repaint when the toggle in AppSidebar flips
// theme.global.name (see the P2 dark-mode audit, 2026-09-15: this data
// used to hardcode the light theme's primary, so the dark charts and
// choropleth never changed color at all).
const theme = useTheme()
const themeColors = computed(() => theme.global.current.value.colors)
const hexToRgb = (hex) => {
  const n = parseInt(hex.replace('#', ''), 16)
  return `${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}`
}

const loading = ref(true)
// A 403 is expected here for an account holding Analytics but not Dashboard: the
// endpoint is gated on the Dashboard section.
const unavailable = ref(false)

const chartDataRaw = ref(null)
const volumeToggle = ref('services')

// Each stat-bearing card owns its period independently now — a single
// shared toggle used to drive the hero card and the trend chart while the
// map, the barangay ranking and the category breakdown were silently
// always all-time, which is what the map/zones/volume cards default to
// here so their look doesn't change until someone touches the toggle.
const heroPeriod = ref('week')
const trendPeriod = ref('week')
const mapPeriod = ref('all')
const zonesPeriod = ref('all')
const volumePeriod = ref('all')

const mapDataByPeriod = ref({}) // { today|week|month|all: [{name, requests}] } — feeds the Leaflet map + the barangay ranking
const pieDataByPeriod = ref({}) // { today|week|month|all: { services: {...}, items: {...} } }
// Requests with no barangay — walk-ins filed at the counter. Reported beside
// the ranking rather than folded into it: there is no location on the record
// to rank or draw, but they are still requests and the total has to say so.
const walkInByPeriod = ref({}) // { today|week|month|all: N }
const totalsByPeriod = ref({}) // { today|week|month|all: N } — barangays + walk-ins
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

const fetchDashboardData = async () => {
  loading.value = true
  try {
    const response = await fetch(`${API_BASE}/admin/dashboard`, {
      headers: {
        'Authorization': `Bearer ${getToken()}`,
        'Accept': 'application/json'
      }
    })

    if (!response.ok) {
      unavailable.value = true
      return
    }

    const data = await response.json()

    mapDataByPeriod.value = data.mapDataByPeriod || {}
    walkInByPeriod.value = data.walkInByPeriod || {}
    totalsByPeriod.value = data.totalsByPeriod || {}
    chartDataRaw.value = data.charts || null
    pieDataByPeriod.value = data.charts?.pieByPeriod || {}

  } catch (error) {
    console.error("Failed to load dashboard:", error)
  } finally {
    loading.value = false
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
  if (list.length === 0) return []
  const max = Math.max(...list.map(b => b.requests))
  return [...list]
    .sort((a, b) => b.requests - a.requests)
    .slice(0, 5)
    .map(b => ({ ...b, percentage: max > 0 ? Math.round((b.requests / max) * 100) : 0 }))
})

// Both follow the ranking's own period toggle, not the map's — they are read
// as part of that list's arithmetic, so they have to move with it.
const walkInCount = computed(() => walkInByPeriod.value[zonesPeriod.value] ?? 0)
const sectionTotal = computed(() => totalsByPeriod.value[zonesPeriod.value] ?? 0)

// Headline metric: real aggregate from the backend's day-by-day series (not the 5-item sample lists)
const heroTotal = computed(() => {
  if (!chartDataRaw.value) return 0
  if (heroPeriod.value === 'today') {
    const series = chartDataRaw.value.bar.week
    return series.data.at(-1) || 0
  }
  const series = chartDataRaw.value.bar[heroPeriod.value]
  return series.data.reduce((a, b) => a + b, 0)
})

watch(heroTotal, (val) => animateValue('hero', val))

const heroSparklineData = computed(() => {
  if (!chartDataRaw.value) return { labels: [], datasets: [] }
  const key = heroPeriod.value === 'today' ? 'week' : heroPeriod.value
  const source = chartDataRaw.value.bar[key]
  const primary = themeColors.value.primary
  return {
    labels: source.labels,
    datasets: [{
      data: source.data,
      borderColor: primary,
      backgroundColor: `rgba(${hexToRgb(primary)}, 0.15)`,
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
      backgroundColor: themeColors.value.primary,
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
  const primary = themeColors.value.primary
  return {
    labels: source.labels,
    datasets: [{
      label: 'Requests',
      data: source.data,
      borderColor: primary,
      backgroundColor: `rgba(${hexToRgb(primary)}, 0.15)`,
      fill: true,
      borderWidth: 2,
      tension: 0.35,
      pointRadius: 3,
      pointHoverRadius: 5,
      pointBackgroundColor: primary,
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

// Define colors based on request density. Neutral (zero requests) has no
// theme token -- it's an explicit per-theme pair, same pattern as the
// pill-cancelled slate in settings.scss, rather than one literal blind to
// which theme is active.
const getMapColor = (d) => {
  if (d > 30) return themeColors.value.error       // High
  if (d > 15) return themeColors.value.warning      // Medium
  if (d > 0) return themeColors.value.success       // Low
  return theme.global.name.value === 'dark' ? '#3A4459' : '#e0e0e0' // Zero requests
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
// the fetch land. Also repaints on a theme toggle: Leaflet isn't reactive,
// so getMapColor's new theme-token values wouldn't otherwise reach the
// choropleth until something else re-triggered this watcher.
watch([requestCountByName, () => theme.global.name.value], () => {
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

/* v-card-title and v-card-subtitle both ship nowrap + ellipsis, which clipped
   this card's header inside its own width at phone size. */
.wrap-text {
  white-space: normal;
  overflow: visible;
  text-overflow: clip;
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
