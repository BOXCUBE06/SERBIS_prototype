<template>
  <v-container fluid class="pa-6 analytics-bg">

    <PageHeader
      title="Analytics"
      subtitle="Demand, turnaround and backlog over a period. The Dashboard answers today; this answers the quarter."
      class="mb-6"
    />

    <!-- Filter bar. Governs every section below, so it sits above all of them
         and stays put while the page scrolls — the alternative is scrolling
         back up to change a range you are in the middle of reading. -->
    <v-card elevation="0" rounded="xl" class="soft-card mb-6 filter-bar">
      <v-card-text class="py-4">
        <div class="d-flex flex-wrap align-center gap-4">
          <v-btn-toggle
            v-model="preset"
            mandatory
            variant="outlined"
            color="primary"
            density="compact"
            divided
            rounded="lg"
          >
            <v-btn value="month" size="small" class="text-none font-weight-bold px-3">This month</v-btn>
            <v-btn value="quarter" size="small" class="text-none font-weight-bold px-3">This quarter</v-btn>
            <v-btn value="year" size="small" class="text-none font-weight-bold px-3">This year</v-btn>
            <v-btn value="custom" size="small" class="text-none font-weight-bold px-3">Custom</v-btn>
          </v-btn-toggle>

          <template v-if="preset === 'custom'">
            <v-text-field
              v-model="customFrom"
              type="date"
              label="From"
              density="compact"
              variant="outlined"
              hide-details
              class="date-field"
            />
            <v-text-field
              v-model="customTo"
              type="date"
              label="To"
              density="compact"
              variant="outlined"
              hide-details
              class="date-field"
            />
          </template>

          <v-select
            v-model="barangayId"
            :items="barangayOptions"
            item-title="label"
            item-value="value"
            label="Barangay"
            density="compact"
            variant="outlined"
            hide-details
            class="filter-field"
          />

          <v-select
            v-model="serviceId"
            :items="serviceOptions"
            item-title="label"
            item-value="value"
            label="Service"
            density="compact"
            variant="outlined"
            hide-details
            class="filter-field"
          />

          <v-btn
            v-if="hasFilters"
            variant="text"
            size="small"
            color="primary"
            class="text-none font-weight-bold"
            @click="clearFilters"
          >
            Clear
          </v-btn>
        </div>

        <!-- Reconciled against the same helper the Dashboard uses, so the two
             pages cannot report different totals for the same window. -->
        <div v-if="report" class="text-caption text-medium-emphasis mt-3">
          {{ report.range.from }} to {{ report.range.to }} ({{ report.range.timezone }})
          &bull; {{ report.totals.serviceRequests.toLocaleString() }}
          {{ report.totals.serviceRequests === 1 ? 'request' : 'requests' }} in range
          <template v-if="report.totals.walkIn > 0">
            &bull; {{ report.totals.walkIn.toLocaleString() }} walk-in (no barangay)
          </template>
        </div>
      </v-card-text>
    </v-card>

    <v-alert
      v-if="error && !loading"
      type="warning"
      variant="tonal"
      rounded="lg"
      class="mb-6"
    >
      <div class="d-flex align-center justify-space-between flex-wrap gap-3">
        <span>{{ error }}</span>
        <v-btn size="small" variant="tonal" color="warning" class="text-none font-weight-bold" @click="fetchReport">
          Try again
        </v-btn>
      </div>
    </v-alert>

    <!-- 1. When demand arrives. Two cyclical dimensions at once, which no bar
         chart can carry, and a plain CSS grid rather than a Chart.js matrix
         plugin — this needs no new dependency. -->
    <v-row class="mb-2">
      <v-col cols="12">
        <AnalyticsSection
          title="When requests arrive"
          subtitle="Day of week against hour of day, in Asia/Manila. Use it to decide when the desk needs covering."
          :loading="loading"
          :error="error"
          :empty="!loading && !error && demand.total === 0"
          :count="demand.total"
          empty-text="No requests in this range"
          empty-hint="Widen the range or clear the filters."
          skeleton="image"
          @retry="fetchReport"
        >
          <div class="heatmap-scroll">
            <div class="heatmap">
              <div class="heat-corner"></div>
              <div
                v-for="hour in 24"
                :key="'h' + hour"
                class="heat-hour text-caption text-medium-emphasis"
              >{{ (hour - 1) % 3 === 0 ? (hour - 1) : '' }}</div>

              <template v-for="(row, dayIndex) in demand.grid" :key="'d' + dayIndex">
                <div class="heat-day text-caption text-medium-emphasis">{{ demand.weekdays[dayIndex] }}</div>
                <div
                  v-for="(count, hourIndex) in row"
                  :key="'c' + dayIndex + '-' + hourIndex"
                  class="heat-cell"
                  :style="heatStyle(count)"
                  :title="`${demand.weekdays[dayIndex]} ${String(hourIndex).padStart(2, '0')}:00 — ${count} ${count === 1 ? 'request' : 'requests'}`"
                  :aria-label="`${demand.weekdays[dayIndex]} ${hourIndex} hundred hours, ${count} requests`"
                ></div>
              </template>
            </div>
          </div>

          <div class="d-flex align-center justify-space-between flex-wrap gap-3 mt-3">
            <div v-if="demand.peak.count > 0" class="text-caption text-medium-emphasis">
              Busiest hour: <strong class="text-high-emphasis">{{ demand.peak.weekday }} {{ String(demand.peak.hour).padStart(2, '0') }}:00</strong>
              ({{ demand.peak.count }} {{ demand.peak.count === 1 ? 'request' : 'requests' }})
            </div>
            <div class="d-flex align-center gap-2">
              <span class="text-caption text-medium-emphasis">Fewer</span>
              <div v-for="step in 5" :key="'l' + step" class="heat-legend" :style="heatStyle(((step - 1) / 4) * demand.peak.count)"></div>
              <span class="text-caption text-medium-emphasis">More</span>
            </div>
          </div>
        </AnalyticsSection>
      </v-col>
    </v-row>

    <v-row class="mb-2">
      <!-- 2. Seasonality and mix. Months are ordered discrete buckets whose
           segments sum to a real total, which is what a stacked bar is for. -->
      <v-col cols="12" lg="7">
        <AnalyticsSection
          title="Requests by month and service"
          subtitle="What the office is asked for, and when."
          :loading="loading"
          :error="error"
          :empty="!loading && !error && volume.total === 0"
          :count="volume.total"
          empty-text="No requests in this range"
          @retry="fetchReport"
        >
          <template #subtitle>
            <div class="d-flex align-center justify-space-between flex-wrap gap-2">
              <span>What the office is asked for, and when.</span>
              <!-- Three of the light-mode series sit under 3:1 on white, so a
                   table view is required rather than optional. It doubles as
                   the non-visual reading of the same numbers. -->
              <v-btn-toggle v-model="volumeView" mandatory density="compact" variant="outlined" color="primary" divided rounded="lg">
                <v-btn value="chart" size="x-small" class="text-none font-weight-bold px-2" aria-label="Show as chart">Chart</v-btn>
                <v-btn value="table" size="x-small" class="text-none font-weight-bold px-2" aria-label="Show as table">Table</v-btn>
              </v-btn-toggle>
            </div>
          </template>

          <div v-if="volumeView === 'chart'" style="height: 300px;">
            <Bar :data="volumeChartData" :options="stackedOptions" />
          </div>

          <div v-else class="table-scroll">
            <table class="data-table text-body-2">
              <thead>
                <tr>
                  <th class="text-left">Service</th>
                  <th v-for="label in volume.labels" :key="label" class="text-right">{{ label }}</th>
                  <th class="text-right">Total</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="series in volume.series" :key="series.label">
                  <td>
                    <span class="series-dot" :style="{ backgroundColor: colorForSeries(series.label, volume.series) }"></span>
                    {{ series.label }}
                  </td>
                  <td v-for="(value, i) in series.data" :key="i" class="text-right">{{ value }}</td>
                  <td class="text-right font-weight-bold">{{ series.data.reduce((a, b) => a + b, 0) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </AnalyticsSection>
      </v-col>

      <!-- 3. Are requests being closed, or accumulating? Proportion is the
           question, so the bars are normalised to 100%. Status colours are
           the app's own — reserved for state, never reused as series hues. -->
      <v-col cols="12" lg="5">
        <AnalyticsSection
          title="Outcomes by month"
          subtitle="Share of each month's requests by where they ended up."
          :loading="loading"
          :error="error"
          :empty="!loading && !error && outcomes.total === 0"
          :count="outcomes.total"
          empty-text="No requests in this range"
          @retry="fetchReport"
        >
          <div style="height: 300px;">
            <Bar :data="outcomeChartDataNormalised" :options="percentStackedOptions" />
          </div>
        </AnalyticsSection>
      </v-col>
    </v-row>

    <v-row>
      <!-- 4. How long the office takes. Every figure carries the sample it
           came from: these columns are only partly backfilled. -->
      <v-col cols="12" lg="7">
        <AnalyticsSection
          title="Turnaround"
          subtitle="Median time to a first answer, and to closing the request."
          :loading="loading"
          :error="error"
          :empty="!loading && !error && turnaround.coverage.requests === 0"
          empty-text="No requests in this range"
          @retry="fetchReport"
        >
          <div class="d-flex flex-wrap gap-4 mb-4">
            <div class="stat-tile subtle-surface">
              <div class="text-caption text-medium-emphasis">Median first response</div>
              <div class="stat-value text-high-emphasis">
                {{ turnaround.firstResponse.medianHours === null ? '—' : formatHours(turnaround.firstResponse.medianHours) }}
              </div>
              <div class="text-caption text-medium-emphasis">n = {{ turnaround.firstResponse.n }}</div>
            </div>

            <div class="stat-tile subtle-surface">
              <div class="text-caption text-medium-emphasis">Median time to close</div>
              <div class="stat-value text-high-emphasis">
                {{ turnaround.resolution.medianDays === null ? '—' : formatDays(turnaround.resolution.medianDays) }}
              </div>
              <div class="text-caption text-medium-emphasis">n = {{ turnaround.resolution.n }}</div>
            </div>
          </div>

          <div v-if="turnaround.histogram.n > 0" style="height: 170px;">
            <Bar :data="histogramChartData" :options="simpleBarOptions" />
          </div>
          <div v-else class="text-body-2 text-medium-emphasis py-4">
            No request in this range has been closed yet, so there is nothing to time.
          </div>

          <!-- Not a footnote. These columns were backfilled from the audit
               log, which starts later than the oldest requests, so a reader
               must not take the medians above as the whole history. -->
          <v-alert
            v-if="turnaround.coverage.requests > turnaround.coverage.withResolution"
            density="compact"
            variant="tonal"
            color="info"
            rounded="lg"
            class="mt-4 text-caption"
          >
            Timed from recorded history only:
            {{ turnaround.coverage.withFirstResponse }} of {{ turnaround.coverage.requests }} requests have a recorded
            first response and {{ turnaround.coverage.withResolution }} have a recorded closing time. Requests without
            one are left out rather than counted as zero.
          </v-alert>
        </AnalyticsSection>
      </v-col>

      <!-- 5. What is stuck right now. Reads created_at and the current status,
           so unlike turnaround it is complete for every row from day one. -->
      <v-col cols="12" lg="5">
        <AnalyticsSection
          title="Open requests by age"
          subtitle="Everything not yet resolved, disapproved or cancelled. Ignores the date filter on purpose."
          :loading="loading"
          :error="error"
          :empty="!loading && !error && aging.total === 0"
          :count="aging.total"
          empty-text="Nothing is open"
          empty-hint="Every request has been closed."
          @retry="fetchReport"
        >
          <div style="height: 220px;">
            <Bar :data="agingChartData" :options="horizontalBarOptions" />
          </div>

          <div v-if="aging.oldestDays > 0" class="text-caption text-medium-emphasis mt-3">
            Oldest open request: <strong class="text-high-emphasis">{{ aging.oldestDays }} {{ aging.oldestDays === 1 ? 'day' : 'days' }}</strong>
          </div>
        </AnalyticsSection>
      </v-col>
    </v-row>

  </v-container>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useTheme } from 'vuetify'
import {
  Chart as ChartJS, Tooltip, Legend, CategoryScale, LinearScale, BarElement,
} from 'chart.js'
import { Bar } from 'vue-chartjs'
import PageHeader from '@/components/PageHeader.vue'
import AnalyticsSection from '@/components/AnalyticsSection.vue'
import { BOOKED_COLOR, CANCELLED_COLOR } from '@/composables/adminUi'
import { getToken } from '@/composables/authToken'
import { API_BASE } from '@/config/api'

ChartJS.register(Tooltip, Legend, CategoryScale, LinearScale, BarElement)

/*
 * Held sections, deliberately not built and deliberately not stubbed. Each
 * was chosen from the data and cut only for scope, so this list is the
 * shortlist to pick up from rather than a wish list:
 *
 *   6.  Equipment utilization — times borrowed and quantity borrowed per
 *       item, INCLUDING zero-borrow items, because dead stock is half the
 *       purchasing decision and a chart of only borrowed items hides it.
 *   7.  Loan turnaround and overdue — median days released to returned,
 *       currently overdue, share returned late. Columns already exist
 *       (released_at, returned_at, due_date).
 *   8.  Fleet usage — trips per unit and median trip duration from the
 *       conduction timeline. Odometer km is present on a minority of trips,
 *       so it needs its own sample size.
 *   9.  Barangay: residents vs requests — reveals barangays with accounts
 *       but no requests, and barangays with neither. Needs the walk-in row
 *       BarangayRequestCounts already returns.
 *   10. App adoption — walk-in vs app-filed share by month. Measures the
 *       project's own premise and is invisible today.
 *   11. Account activation backlog — Inactive residents and signups over
 *       time. Small, but nothing currently surfaces the waiting accounts.
 *
 * Not built at all, with reasons, so nobody re-proposes them: per-staff
 * productivity (one admin exists; processed_by is set on 2 of 50 rows; and
 * per-person metrics in a three-person office are surveillance), SMS reach
 * (tbl_sms_logs persists nothing), patient demographics (populated on under
 * a quarter of bookings), and per-capita rates (no barangay population).
 */

const ALL = 'all'

/*
 * Categorical series colours, one fixed order per theme, assigned by position
 * and never cycled — a ninth service folds into "Other" rather than reusing a
 * hue that already means something else on the same chart.
 *
 * Validated with the data-viz palette validator against THIS app's surfaces
 * (#FFFFFF light, #131B2E dark), not the validator's defaults: 8 slots, both
 * modes, all checks pass on the adjacent pairlist that stacked bars use —
 * worst adjacent CVD ΔE 9.1 light / 8.4 dark against a target of 8.
 *
 * Light mode raises a contrast relief on three slots (aqua 2.82, yellow 2.17,
 * magenta 2.69 against white). That is not dismissable, which is why this
 * chart ships a Table view rather than treating one as optional.
 *
 * Kept off the brand green deliberately: primary is the app's own accent and
 * reading it as "one particular service" would collide with every other use
 * of it on the page.
 */
const SERIES_LIGHT = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948']
const SERIES_DARK = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767']

const preset = ref('quarter')
const volumeView = ref('chart')
const customFrom = ref('')
const customTo = ref('')
const barangayId = ref(ALL)
const serviceId = ref(ALL)

const report = ref(null)
const loading = ref(true)
const error = ref('')

const barangays = ref([])
const services = ref([])

const barangayOptions = computed(() => [
  { label: 'All barangays', value: ALL },
  ...barangays.value.map(b => ({ label: b.barangay_name, value: b.barangay_id })),
])

const serviceOptions = computed(() => [
  { label: 'All services', value: ALL },
  ...services.value.map(s => ({ label: s.service_name, value: s.service_id })),
])

const hasFilters = computed(() =>
  barangayId.value !== ALL || serviceId.value !== ALL || preset.value !== 'quarter'
)

const clearFilters = () => {
  preset.value = 'quarter'
  barangayId.value = ALL
  serviceId.value = ALL
  customFrom.value = ''
  customTo.value = ''
}

const authHeaders = () => ({
  Authorization: `Bearer ${getToken()}`,
  Accept: 'application/json',
})

const queryString = () => {
  const params = new URLSearchParams({ preset: preset.value })

  // Only sent when both ends are present: the server treats a half-filled
  // custom range as no range at all and falls back to the quarter, which
  // would read as the filter silently doing nothing.
  if (preset.value === 'custom' && customFrom.value && customTo.value) {
    params.set('from', customFrom.value)
    params.set('to', customTo.value)
  }

  if (barangayId.value !== ALL) params.set('barangay_id', barangayId.value)
  if (serviceId.value !== ALL) params.set('service_id', serviceId.value)

  return params.toString()
}

const fetchReport = async () => {
  loading.value = true
  error.value = ''

  try {
    const response = await fetch(`${API_BASE}/admin/analytics?${queryString()}`, { headers: authHeaders() })

    if (!response.ok) throw new Error(`Request failed (${response.status})`)

    report.value = await response.json()
  } catch {
    // The sections read their own error prop from this, so one failed fetch
    // does not leave stale numbers on screen looking current.
    report.value = null
    error.value = 'Could not load analytics. The server may be unreachable.'
  } finally {
    loading.value = false
  }
}

const fetchFilterOptions = async () => {
  try {
    const [barangayResponse, serviceResponse] = await Promise.all([
      fetch(`${API_BASE}/barangays`, { headers: authHeaders() }),
      fetch(`${API_BASE}/services`, { headers: authHeaders() }),
    ])

    if (barangayResponse.ok) barangays.value = await barangayResponse.json()

    if (serviceResponse.ok) {
      // /services answers {data: [...]} while /barangays answers a bare
      // array. Three response envelopes are already in use across this API;
      // do not assume a shape here.
      const payload = await serviceResponse.json()
      services.value = payload.data ?? payload
    }
  } catch {
    // A filter list that fails to load leaves "All" selected, which is the
    // correct default anyway — not worth failing the page over.
  }
}

// A custom range with only one end filled is not yet a range, so it must not
// fire a fetch that would silently return the quarter.
watch([preset, barangayId, serviceId, customFrom, customTo], () => {
  if (preset.value === 'custom' && !(customFrom.value && customTo.value)) return
  fetchReport()
})

onMounted(() => {
  fetchFilterOptions()
  fetchReport()
})

/* ---------------------------------------------------------------------------
 * Rendering
 *
 * Chart.js draws to canvas and cannot read the CSS custom properties the rest
 * of the app uses, so every colour here comes off Vuetify's reactive theme
 * instance. That is what makes the charts repaint when the sidebar toggle
 * flips the theme rather than keeping light-mode colours on a dark page.
 * ------------------------------------------------------------------------ */

const theme = useTheme()
const themeColors = computed(() => theme.global.current.value.colors)
const isDark = computed(() => theme.global.current.value.dark)

const gridColor = computed(() => `rgba(${hexToRgb(themeColors.value['on-surface'])}, 0.10)`)
const tickColor = computed(() => `rgba(${hexToRgb(themeColors.value['on-surface'])}, 0.70)`)
const surfaceColor = computed(() => themeColors.value.surface)

function hexToRgb (hex) {
  const n = Number.parseInt(String(hex).replace('#', ''), 16)
  return `${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}`
}

// Empty shapes so the template can read these before the first response lands
// without a guard on every access.
const EMPTY_STACK = { labels: [], series: [], total: 0 }

const demand = computed(() => report.value?.demand ?? { weekdays: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], grid: [], total: 0, peak: { weekday: null, hour: null, count: 0 } })
const volume = computed(() => report.value?.volume ?? EMPTY_STACK)
const outcomes = computed(() => report.value?.outcomes ?? EMPTY_STACK)
const aging = computed(() => report.value?.aging ?? { labels: [], data: [], total: 0, oldestDays: 0 })
const turnaround = computed(() => report.value?.turnaround ?? {
  firstResponse: { medianHours: null, n: 0 },
  resolution: { medianDays: null, n: 0 },
  histogram: { labels: [], data: [], n: 0 },
  coverage: { requests: 0, withFirstResponse: 0, withResolution: 0 },
})

/**
 * Sequential fill for the heatmap: one hue, light to dark, as magnitude
 * demands. Zero gets a neutral rather than the palest tint of the hue, so
 * "none" reads as absence instead of "a little".
 */
const heatStyle = (count) => {
  const peak = demand.value.peak.count || 1

  if (!count) {
    return { backgroundColor: `rgba(${hexToRgb(themeColors.value['on-surface'])}, 0.06)` }
  }

  // sqrt, not linear: a single busy cell would otherwise flatten every other
  // cell on the grid to near-invisible.
  const intensity = 0.18 + 0.82 * Math.sqrt(count / peak)

  return { backgroundColor: `rgba(${hexToRgb(themeColors.value.primary)}, ${intensity.toFixed(3)})` }
}

const seriesPalette = computed(() => (isDark.value ? SERIES_DARK : SERIES_LIGHT))

/** Colour by the series' fixed position, so a filter that drops a service does not repaint the survivors. */
const colorForSeries = (label, series) => {
  const index = series.findIndex(s => s.label === label)
  return seriesPalette.value[index % seriesPalette.value.length]
}

/**
 * Status colours, not categorical ones. These are reserved for state across
 * the whole panel, so the chart reads the same language as every pill.
 * Booked and Cancelled are off the semantic five and are hand-matched to
 * `.pill-booked` / `.pill-cancelled` in settings.scss, which is the same
 * arrangement (and the same caveat) as adminUi.ts already documents.
 */
const statusColor = (status) => {
  const c = themeColors.value

  switch (status) {
    case 'Pending': return c.warning
    case 'Booked': return isDark.value ? '#A78BFA' : BOOKED_COLOR
    case 'Responding': return c.info
    case 'Resolved': return c.success
    case 'Disapproved': return c.error
    case 'Cancelled': return isDark.value ? '#94A3B8' : CANCELLED_COLOR
    default: return c.info
  }
}

// A 2px surface-coloured gap between stacked segments, so adjoining fills read
// as separate bands rather than one continuous block.
const stackedDataset = (series, color) => ({
  label: series.label,
  data: series.data,
  backgroundColor: color,
  borderColor: surfaceColor.value,
  borderWidth: { top: 2, right: 0, bottom: 0, left: 0 },
  borderRadius: 3,
  maxBarThickness: 46,
})

const volumeChartData = computed(() => ({
  labels: volume.value.labels,
  datasets: volume.value.series.map(s => stackedDataset(s, colorForSeries(s.label, volume.value.series))),
}))

const histogramChartData = computed(() => ({
  labels: turnaround.value.histogram.labels,
  datasets: [{
    label: 'Requests',
    data: turnaround.value.histogram.data,
    backgroundColor: themeColors.value.primary,
    borderRadius: 4,
    maxBarThickness: 38,
  }],
}))

const agingChartData = computed(() => ({
  labels: aging.value.labels,
  datasets: [{
    label: 'Open requests',
    data: aging.value.data,
    backgroundColor: themeColors.value.primary,
    borderRadius: 4,
    maxBarThickness: 26,
  }],
}))

const baseOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  plugins: {
    legend: { display: false },
    tooltip: { boxPadding: 4 },
  },
  scales: {
    x: { grid: { display: false }, ticks: { color: tickColor.value } },
    y: { beginAtZero: true, ticks: { precision: 0, color: tickColor.value }, grid: { color: gridColor.value } },
  },
}))

// A legend is mandatory once there are two or more series: identity must not
// rest on colour alone.
const stackedOptions = computed(() => ({
  ...baseOptions.value,
  plugins: {
    ...baseOptions.value.plugins,
    legend: { display: true, position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', color: tickColor.value } },
  },
  scales: {
    x: { ...baseOptions.value.scales.x, stacked: true },
    y: { ...baseOptions.value.scales.y, stacked: true },
  },
}))

const percentStackedOptions = computed(() => ({
  ...stackedOptions.value,
  plugins: {
    ...stackedOptions.value.plugins,
    tooltip: {
      boxPadding: 4,
      callbacks: {
        // The axis is a percentage but the useful number is the count, so the
        // tooltip gives both rather than making the reader multiply.
        label: (ctx) => {
          const total = ctx.chart.data.datasets.reduce((sum, d) => sum + (d.data[ctx.dataIndex] || 0), 0)
          const share = total ? Math.round((ctx.raw / total) * 100) : 0
          return `${ctx.dataset.label}: ${ctx.raw} (${share}%)`
        },
      },
    },
  },
  scales: {
    x: { ...baseOptions.value.scales.x, stacked: true },
    y: {
      ...baseOptions.value.scales.y,
      stacked: true,
      max: 100,
      ticks: { color: tickColor.value, callback: (v) => `${v}%` },
      grid: { color: gridColor.value },
    },
  },
}))

// Chart.js has no percentage stacking mode, so the values are normalised here
// and the real counts are restored in the tooltip above.
const outcomeChartDataNormalised = computed(() => {
  const totals = outcomes.value.labels.map((_, i) =>
    outcomes.value.series.reduce((sum, s) => sum + (s.data[i] || 0), 0)
  )

  return {
    labels: outcomes.value.labels,
    datasets: outcomes.value.series.map(s => ({
      ...stackedDataset(s, statusColor(s.label)),
      data: s.data.map((v, i) => (totals[i] ? (v / totals[i]) * 100 : 0)),
      rawData: s.data,
    })),
  }
})

const simpleBarOptions = computed(() => baseOptions.value)

const horizontalBarOptions = computed(() => ({
  ...baseOptions.value,
  indexAxis: 'y',
  scales: {
    x: { beginAtZero: true, ticks: { precision: 0, color: tickColor.value }, grid: { color: gridColor.value } },
    y: { grid: { display: false }, ticks: { color: tickColor.value } },
  },
}))

const formatHours = (hours) => {
  if (hours < 1) return `${Math.round(hours * 60)} min`
  if (hours < 48) return `${hours} ${hours === 1 ? 'hour' : 'hours'}`
  return `${(hours / 24).toFixed(1)} days`
}

const formatDays = (days) => {
  const hours = Math.round(days * 24)

  // "0 hours" reads as instantaneous, which is never what happened — it is a
  // rounding artefact of a resolution that landed inside the same hour.
  if (hours < 1) return 'Under an hour'
  if (days < 1) return `${hours} ${hours === 1 ? 'hour' : 'hours'}`

  return `${days} ${days === 1 ? 'day' : 'days'}`
}

defineExpose({ fetchReport })
</script>

<style scoped>
.analytics-bg {
  background-color: rgb(var(--v-theme-background));
}

.soft-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  box-shadow: 0 1px 2px rgba(var(--v-theme-on-surface), 0.04), 0 4px 14px rgba(var(--v-theme-on-surface), 0.08);
}

/* Sticky so the controls stay reachable while reading a section far down the
   page. z-index keeps it above the cards it scrolls over. */
.filter-bar {
  position: sticky;
  top: 0;
  z-index: 3;
  background-color: rgb(var(--v-theme-surface));
}

.gap-4 {
  gap: 16px;
}

/* A max-width alone collapses these to ~100px inside a flex row (see the
   PageHeader note on the same trap). Both need a real width. */
.filter-field {
  width: 190px;
  max-width: 100%;
}

.date-field {
  width: 170px;
  max-width: 100%;
}

.subtle-surface {
  background-color: rgba(var(--v-theme-on-surface), 0.05);
}

/* 24 hour columns do not fit a phone, and squeezing them would make every
   cell unreadable rather than merely offscreen. Scroll the grid, not the
   page. */
.heatmap-scroll {
  overflow-x: auto;
  padding-bottom: 4px;
}

.heatmap {
  display: grid;
  grid-template-columns: 34px repeat(24, minmax(20px, 1fr));
  gap: 3px;
  min-width: 620px;
}

.heat-corner {
  grid-column: 1;
}

.heat-hour {
  text-align: center;
  font-size: 10px;
  line-height: 1;
}

.heat-day {
  display: flex;
  align-items: center;
  font-size: 11px;
}

.heat-cell {
  aspect-ratio: 1;
  border-radius: 3px;
  min-height: 18px;
}

.heat-legend {
  width: 16px;
  height: 10px;
  border-radius: 2px;
}

.stat-tile {
  flex: 1 1 180px;
  border-radius: 12px;
  padding: 14px 16px;
}

.stat-value {
  font-size: 26px;
  font-weight: 700;
  line-height: 1.2;
  margin: 2px 0;
}

.table-scroll {
  overflow-x: auto;
  max-height: 300px;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
}

.data-table th,
.data-table td {
  padding: 6px 10px;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  white-space: nowrap;
}

.data-table th {
  position: sticky;
  top: 0;
  background-color: rgb(var(--v-theme-surface));
  font-weight: 700;
}

/* The colour sits on a dot beside the label, never on the text — a series
   hue is not a text colour and would not clear contrast as one. */
.series-dot {
  display: inline-block;
  width: 9px;
  height: 9px;
  border-radius: 50%;
  margin-right: 8px;
}
</style>
