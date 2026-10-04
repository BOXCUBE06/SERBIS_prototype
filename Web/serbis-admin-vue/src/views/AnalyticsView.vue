<template>
  <v-container fluid class="analytics-bg">

    <PageHeader
      title="Analytics"
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
            aria-label="Barangay"
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
            aria-label="Service"
            density="compact"
            variant="outlined"
            hide-details
            class="filter-field"
          />

          <v-btn
            v-if="hasFilters"
            variant="outlined"
            size="small"
            color="primary"
            class="text-none font-weight-bold"
            @click="clearFilters"
          >
            Clear
          </v-btn>
        </div>

        <!-- Selecting Custom does not fetch until both dates are set, because
             the server reads a half-filled range as no range and answers with
             the quarter. Saying so beats leaving the previous range's caption
             on screen asserting a window the controls no longer show. -->
        <div v-if="awaitingCustomRange" class="text-caption text-medium-emphasis mt-3">
          Pick a start and an end date to apply a custom range. Showing
          {{ report ? `${report.range.from} to ${report.range.to}` : 'nothing' }} until then.
        </div>

        <!-- Reconciled against the same helper the Dashboard uses, so the two
             pages cannot report different totals for the same window. -->
        <div v-else-if="report" class="text-caption text-medium-emphasis mt-3">
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

    <v-tabs v-model="tab" color="primary" class="mb-4">
      <v-tab v-for="t in TABS" :key="t.value" :value="t.value" class="text-none font-weight-bold">{{ t.title }}</v-tab>
    </v-tabs>

    <template v-if="tab === 'demand'">
    <!-- 1. When demand arrives, split into two ordinary bar charts instead of
         a day x hour heatmap: which weekday, and which quarter of the day
         (see AnalyticsReport::demandByWeekdayHour / timeBlockFor). -->
    <v-row class="mb-2">
      <v-col cols="12">
        <AnalyticsSection
          title="When requests are filed"
          :loading="firstLoad"
          :refreshing="refreshing"
          :error="error"
          :empty="!loading && !error && demand.total === 0"
          empty-text="No requests in this range"
          empty-hint="Widen the range or clear the filters."
          @retry="fetchReport"
        >
          <div v-if="demand.peak.count > 0" class="text-body-1 font-weight-bold text-high-emphasis mb-4">
            Most requests: {{ demand.peak.weekday }} {{ demand.peak.block }}
          </div>

          <div class="d-flex flex-wrap gap-4">
            <div class="demand-chart">
              <div class="text-caption text-medium-emphasis mb-1">Busiest days</div>
              <div style="height: 220px;">
                <Bar :data="busiestDaysChartData" :options="baseOptions" />
                <ChartDataTable
                  caption="Busiest days — same data as the chart above"
                  category-label="Day"
                  :labels="demand.days.labels"
                  :series="[{ label: 'Requests', data: demand.days.data }]"
                />
              </div>
            </div>

            <div class="demand-chart">
              <div class="text-caption text-medium-emphasis mb-1">Busiest time of day</div>
              <div style="height: 220px;">
                <Bar :data="busiestTimeOfDayChartData" :options="baseOptions" />
                <ChartDataTable
                  caption="Busiest time of day — same data as the chart above"
                  category-label="Time of day"
                  :labels="demand.timeOfDay.labels"
                  :series="[{ label: 'Requests', data: demand.timeOfDay.data }]"
                />
              </div>
            </div>
          </div>
        </AnalyticsSection>
      </v-col>
    </v-row>

    <v-row class="mb-2">
      <v-col cols="12">
        <AnalyticsSection
          title="Requests by month and service"
          :loading="firstLoad"
          :refreshing="refreshing"
          :error="error"
          :empty="!loading && !error && volume.total === 0"
          empty-text="No requests in this range"
          @retry="fetchReport"
        >
          <template #actions>
            <!-- Three of the light-mode series sit under 3:1 on white, so a
                 table view is required rather than optional. It doubles as
                 the non-visual reading of the same numbers. -->
            <v-btn-toggle v-model="volumeView" mandatory density="compact" variant="outlined" color="primary" divided rounded="lg">
              <v-btn value="chart" size="x-small" class="text-none font-weight-bold px-2 toggle-btn-text" aria-label="Show as chart">Chart</v-btn>
              <v-btn value="table" size="x-small" class="text-none font-weight-bold px-2 toggle-btn-text" aria-label="Show as table">Table</v-btn>
            </v-btn-toggle>
          </template>

          <div v-if="volumeView === 'chart'" style="height: 300px;">
            <Bar :data="volumeChartData" :options="stackedOptions" />
            <ChartDataTable
              caption="Requests by month and service — same data as the chart above"
              category-label="Month"
              :labels="volume.labels"
              :series="volume.series"
            />
          </div>

          <div v-else class="table-scroll">
            <table class="data-table text-body-2">
              <!-- Total sits second, not last. With a long service name and
                   three month columns the row outgrew the box and pushed the
                   final column out of sight — and this view is the mandated
                   accessible path for the three light-mode series that miss
                   3:1, so it must not be the least readable one. -->
              <thead>
                <tr>
                  <th class="text-left">Service</th>
                  <th class="text-right">Total</th>
                  <th v-for="label in volume.labels" :key="label" class="text-right">{{ label }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="series in volume.series" :key="series.label">
                  <td class="service-cell">
                    <span class="series-dot" :style="{ backgroundColor: colorForSeries(series.label, volume.series) }"></span>
                    {{ series.label }}
                  </td>
                  <td class="text-right font-weight-bold">{{ series.data.reduce((a, b) => a + b, 0) }}</td>
                  <td v-for="(value, i) in series.data" :key="i" class="text-right">{{ value }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </AnalyticsSection>
      </v-col>

    </v-row>
    </template>

    <template v-if="tab === 'barangays'">
    <v-row>
      <!-- 9. Barangays: the map and the residents-vs-requests ranking are one
           set of numbers, so one section. Built from the full barangay
           roster, so a barangay with accounts but no requests still shows at
           zero. No barangay filter — narrowing the one cross-barangay
           comparison to one barangay would defeat its purpose. -->
      <v-col cols="12">
        <AnalyticsSection
          title="Barangays"
          :refreshing="refreshing"
          :error="error"
          :empty="!loading && !error && barangayCoverage.barangays.length === 0"
          empty-text="No barangays configured"
          @retry="fetchReport"
        >
          <BarangayDemand
            :loading="firstLoad"
            :barangays="barangayCoverage.barangays"
            :walk-in="barangayCoverage.walkIn"
            :total-residents="barangayCoverage.totalResidents"
            :total-requests="barangayCoverage.totalRequests"
          />
        </AnalyticsSection>
      </v-col>
    </v-row>
    </template>

    <template v-if="tab === 'operations'">
    <v-row class="mb-2">
      <!-- 3. Are requests being closed, or accumulating? Proportion is the
           question, so the bars are normalised to 100%. Status colours are
           the app's own — reserved for state, never reused as series hues. -->
      <v-col cols="12">
        <AnalyticsSection
          title="Outcomes by month"
          :loading="firstLoad"
          :refreshing="refreshing"
          :error="error"
          :empty="!loading && !error && outcomes.total === 0"
          empty-text="No requests in this range"
          @retry="fetchReport"
        >
          <div style="height: 300px;">
            <Bar :data="outcomeChartDataNormalised" :options="percentStackedOptions" />
            <ChartDataTable
              caption="Outcomes by month — request counts by status (the chart shows share, this table the real counts)"
              category-label="Month"
              :labels="outcomes.labels"
              :series="outcomes.series"
            />
          </div>
        </AnalyticsSection>
      </v-col>
    </v-row>

    <v-row>
      <!-- 5. What is stuck right now. Reads created_at and the current status,
           so it is complete for every row from day one. -->
      <v-col id="open-request-age" cols="12">
        <AnalyticsSection
          title="How Long Requests Have Been Waiting"
          info="Pending, booked or being responded to. Ignores the date filter on purpose, so an old request cannot hide outside the range."
          :loading="firstLoad"
          :refreshing="refreshing"
          :error="error"
          :empty="!loading && !error && aging.total === 0"
          empty-text="Nothing is open"
          empty-hint="Every request has been closed."
          @retry="fetchReport"
        >
          <div style="height: 220px;">
            <Bar :data="agingChartData" :options="horizontalBarOptions" />
            <ChartDataTable
              caption="How long requests have been waiting — same data as the chart above"
              category-label="Age bucket"
              :labels="aging.labels"
              :series="[{ label: 'Open requests', data: aging.data }]"
            />
          </div>

          <div v-if="aging.oldestDays > 0" class="text-caption text-medium-emphasis mt-3">
            Oldest open request: <strong class="text-high-emphasis">{{ aging.oldestDays }} {{ aging.oldestDays === 1 ? 'day' : 'days' }}</strong>
          </div>
        </AnalyticsSection>
      </v-col>
    </v-row>

    </template>

    <template v-if="tab === 'equipment'">
    <v-row>
      <!-- 6. Equipment utilization. Built from the full catalogue, so a
           never-borrowed item shows as a zero row rather than not showing at
           all — dead stock is half the purchasing decision. -->
      <v-col cols="12">
        <AnalyticsSection
          title="Equipment utilization"
          :loading="firstLoad"
          :refreshing="refreshing"
          :error="error"
          :empty="!loading && !error && equipmentUtilization.items.length === 0"
          empty-text="No equipment in the catalogue"
          @retry="fetchReport"
        >
          <div class="table-scroll">
            <table class="data-table text-body-2">
              <thead>
                <tr>
                  <th class="text-left" scope="col">Item</th>
                  <th class="text-right" scope="col">Times borrowed</th>
                  <th class="text-right" scope="col">Quantity borrowed</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in equipmentUtilization.items" :key="item.label">
                  <td>{{ item.label }}</td>
                  <td class="text-right" :class="{ 'text-medium-emphasis': item.timesBorrowed === 0 }">{{ item.timesBorrowed }}</td>
                  <td class="text-right" :class="{ 'text-medium-emphasis': item.quantityBorrowed === 0 }">{{ item.quantityBorrowed }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="equipmentUtilization.zeroBorrowCount > 0" class="text-caption text-medium-emphasis mt-3">
            {{ equipmentUtilization.zeroBorrowCount }} of {{ equipmentUtilization.items.length }}
            {{ equipmentUtilization.items.length === 1 ? 'item was' : 'items were' }} not borrowed in this range.
          </div>
        </AnalyticsSection>
      </v-col>
    </v-row>

    <v-row>
      <!-- 7. Equipment returns: on-time vs late, calm neutral tiles rather than
           an alarm colour. Overdue loans still out are listed on the Dashboard. -->
      <v-col cols="12">
        <AnalyticsSection
          title="Equipment returns"
          :loading="firstLoad"
          :refreshing="refreshing"
          :error="error"
          :empty="!loading && !error && loans.returnedLate.of === 0"
          empty-text="No equipment returned in this range"
          @retry="fetchReport"
        >
          <div class="d-flex flex-wrap gap-4">
            <div class="stat-tile subtle-surface">
              <div class="text-caption text-medium-emphasis">Returned late</div>
              <div class="stat-value text-high-emphasis">
                {{ loans.returnedLate.percent === null ? '—' : `${loans.returnedLate.percent}%` }}
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ loans.returnedLate.count }} of {{ loans.returnedLate.of }} returned in this range
              </div>
            </div>
          </div>
        </AnalyticsSection>
      </v-col>
    </v-row>

    <v-row v-if="loading || error || selectedVehicleTrips.some(v => v.trips > 0)">
      <!-- 8. Most used vehicles, over the page's date range. No barangay/service
           filter: the trip log carries no resident_id and every conduction
           request is the same one dispatch service, so neither has anything
           to narrow. -->
      <v-col cols="12">
        <AnalyticsSection
          title="Most used vehicles"
          :loading="firstLoad"
          :refreshing="refreshing"
          :error="error"
                    @retry="fetchReport"
        >
          <div :style="{ height: Math.max(120, selectedVehicleTrips.length * 40) + 'px' }">
            <Bar :data="vehicleTripsChartData" :options="horizontalBarOptions" />
            <ChartDataTable
              caption="Most used vehicles — same data as the chart above"
              category-label="Vehicle"
              :labels="selectedVehicleTrips.map(v => v.label)"
              :series="[{ label: 'Trips', data: selectedVehicleTrips.map(v => v.trips) }]"
            />
          </div>
        </AnalyticsSection>
      </v-col>
    </v-row>

    </template>

  </v-container>
</template>

<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useTheme } from 'vuetify'
import {
  Chart as ChartJS, Tooltip, Legend, CategoryScale, LinearScale, BarElement,
} from 'chart.js'
import { Bar } from 'vue-chartjs'
import PageHeader from '@/components/PageHeader.vue'
import AnalyticsSection from '@/components/AnalyticsSection.vue'
import ChartDataTable from '@/components/ChartDataTable.vue'
import BarangayDemand from '@/components/BarangayDemand.vue'
import { BOOKED_COLOR, CANCELLED_COLOR } from '@/composables/adminUi'
import { getToken } from '@/composables/authToken'
import { API_BASE } from '@/config/api'
import { REFERENCE_TTL_MS, useCachedFetch } from '@/composables/useCachedFetch'
// Side effect only: sets the chart font default, which this page's own charts need too.
import '@/composables/useChartTheme'

ChartJS.register(Tooltip, Legend, CategoryScale, LinearScale, BarElement)

/*
 * Sections 1-11 are all built. What remains below is what was deliberately
 * cut, not deferred:
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
 * Light mode slots 2 (aqua), 3 (yellow) and 4 (magenta) originally measured
 * 2.82/2.17/2.69 against white — under the 3:1 floor for a non-text fill.
 * Darkened in place, same hue, to 3.22/3.32/3.28 (#1baf7a→#19a371,
 * #eda100→#be8100, #e87ba4→#d16f94). The Table view stays regardless — it is
 * the accessible path for anyone who can't read colour at all, not only a
 * workaround for these three.
 *
 * Kept off the brand green deliberately: primary is the app's own accent and
 * reading it as "one particular service" would collide with every other use
 * of it on the page.
 */
const SERIES_LIGHT = ['#2a78d6', '#eb6834', '#19a371', '#be8100', '#d16f94', '#008300', '#4a3aa7', '#e34948']
const SERIES_DARK = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767']

const TABS = [
  { value: 'demand', title: 'Demand' },
  { value: 'barangays', title: 'Barangays' },
  { value: 'operations', title: 'Operations' },
  { value: 'equipment', title: 'Equipment & Vehicles' },
]
const tab = ref('demand')

const preset = ref('quarter')
const volumeView = ref('chart')
const customFrom = ref('')
const customTo = ref('')
const barangayId = ref(ALL)
const serviceId = ref(ALL)

const report = ref(null)
const loading = ref(true)
const error = ref('')
// Skeletons on the first load only; a filter change keeps the old figures, dimmed.
const firstLoad = computed(() => loading.value && !report.value)
const refreshing = computed(() => loading.value && !!report.value)

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

const awaitingCustomRange = computed(() =>
  preset.value === 'custom' && !(customFrom.value && customTo.value)
)

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

const { get } = useCachedFetch()

const fetchReport = async () => {
  loading.value = true
  error.value = ''

  try {
    await get(`/admin/analytics?${queryString()}`, { onData: (data) => { report.value = data } })
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
  // A filter list that fails to load leaves "All" selected, which is the
  // correct default anyway — not worth failing the page over.
  await Promise.all([
    // /services answers {data: [...]} while /barangays answers a bare
    // array. Three response envelopes are already in use across this API;
    // do not assume a shape here.
    get('/barangays', { ttl: REFERENCE_TTL_MS, onData: (data) => { barangays.value = data } }),
    get('/services', { ttl: REFERENCE_TTL_MS, onData: (data) => { services.value = data.data ?? data } }),
  ].map((request) => request.catch(() => null)))
}

// A custom range with only one end filled is not yet a range, so it must not
// fire a fetch that would silently return the quarter.
watch([preset, barangayId, serviceId, customFrom, customTo], () => {
  if (preset.value === 'custom' && !(customFrom.value && customTo.value)) return
  fetchReport()
})

onMounted(async () => {
  fetchFilterOptions()
  await fetchReport()

  // The Dashboard's aging card links here with #open-request-age. The browser
  // cannot act on that hash itself: the section does not exist until the
  // first payload has rendered, and the app scrolls an inner wrapper rather
  // than the document, so the native jump has nothing to move.
  if (window.location.hash === '#open-request-age') {
    tab.value = 'operations'
    await nextTick()
    document.querySelector('#open-request-age')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }
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

const demand = computed(() => report.value?.demand ?? {
  days: { labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], data: [] },
  timeOfDay: { labels: ['Morning', 'Afternoon', 'Evening', 'Late night'], data: [] },
  total: 0,
  peak: { weekday: null, block: null, count: 0 },
})
const volume = computed(() => report.value?.volume ?? EMPTY_STACK)
const outcomes = computed(() => report.value?.outcomes ?? EMPTY_STACK)
const aging = computed(() => report.value?.aging ?? { labels: [], data: [], total: 0, oldestDays: 0 })
const equipmentUtilization = computed(() => report.value?.equipmentUtilization ?? { items: [], total: 0, zeroBorrowCount: 0 })
const loans = computed(() => report.value?.loans ?? {
  daysOut: { medianDays: null, n: 0 },
  returnedLate: { count: 0, of: 0, percent: null },
})
const selectedVehicleTrips = computed(() => report.value?.vehicleTrips?.range ?? [])
const barangayCoverage = computed(() => report.value?.barangayCoverage ?? {
  barangays: [], walkIn: 0, totalResidents: 0, totalRequests: 0,
})

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

const vehicleTripsChartData = computed(() => ({
  labels: selectedVehicleTrips.value.map(v => v.label),
  datasets: [{
    label: 'Trips',
    data: selectedVehicleTrips.value.map(v => v.trips),
    backgroundColor: themeColors.value.primary,
    borderRadius: 4,
    maxBarThickness: 26,
  }],
}))

const busiestDaysChartData = computed(() => ({
  labels: demand.value.days.labels,
  datasets: [{
    label: 'Requests',
    data: demand.value.days.data,
    backgroundColor: themeColors.value.primary,
    borderRadius: 4,
    maxBarThickness: 38,
  }],
}))

const busiestTimeOfDayChartData = computed(() => ({
  labels: demand.value.timeOfDay.labels,
  datasets: [{
    label: 'Requests',
    data: demand.value.timeOfDay.data,
    backgroundColor: themeColors.value.primary,
    borderRadius: 4,
    maxBarThickness: 38,
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
        // tooltip gives both rather than making the reader multiply. ctx.raw
        // is the normalised percentage the bar is drawn from — the real count
        // lives in dataset.rawData, set alongside it below.
        label: (ctx) => {
          const count = ctx.dataset.rawData?.[ctx.dataIndex] ?? ctx.raw
          const share = Math.round(ctx.raw)
          return `${ctx.dataset.label}: ${count} (${share}%)`
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

const horizontalBarOptions = computed(() => ({
  ...baseOptions.value,
  indexAxis: 'y',
  scales: {
    x: { beginAtZero: true, ticks: { precision: 0, color: tickColor.value }, grid: { color: gridColor.value } },
    y: { grid: { display: false }, ticks: { color: tickColor.value } },
  },
}))

defineExpose({ fetchReport })
</script>

<style scoped>
.analytics-bg {
  background-color: rgb(var(--v-theme-background));
}

/* Vuetify's x-small button default (0.625rem/10px) falls under an 11px
   readability floor, same fact as AnalyticsSection's chip-count override. */
.toggle-btn-text {
  font-size: 0.6875rem;
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

/* Not on a phone: the bar stacks to ~254px there, which is 27% of a 430px
   viewport permanently occupied by controls that are set once and then read
   past. It scrolls away with the rest instead. */
@media (max-width: 599px) {
  .filter-bar {
    position: static;
  }
}

/* The range toggle wraps onto a second line when it outgrows the card; it
   must not scroll. Vuetify gives v-btn-group a fixed 36px height, so an
   overflow-x scrollbar renders INSIDE that box and leaves a 17px content
   strip — the four buttons measured 93x17 at 430px while measuring a correct
   36px at 1280. Wrapping keeps every button at full height and needs no
   horizontal gesture on a phone. */
.filter-bar :deep(.v-btn-group) {
  flex-shrink: 0;
  flex-wrap: wrap;
  height: auto;
  overflow: visible;
}

/* min-height, not height: Vuetify writes an INLINE `height: auto` on every
   button inside a v-btn-group, which no stylesheet rule can outrank. With
   the group wrapped and no vertical padding on the button, auto resolves to
   the text box alone and each button measured 17px tall. min-height is not
   set inline, so it is the one lever that reaches. */
.filter-bar :deep(.v-btn-group .v-btn) {
  flex-shrink: 0;
  min-height: 36px;
}

/* Vuetify signals focus only with a 12%-opacity overlay, which measured
   1.32:1 against its own surface in light mode and 1.59:1 in dark — both far
   under the 3:1 a non-text indicator needs. Keyboard users had no visible
   focus at all on this page's controls. */
.analytics-bg :deep(.v-btn:focus-visible),
.analytics-bg :deep(.v-field:focus-within),
.analytics-bg :deep(a:focus-visible) {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
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

/* Two side by side above 600px (min 280px each, so a narrow half never
   squeezes a bar chart's ticks); one per row below that, same as every
   other flex-wrap pairing on this page. */
.demand-chart {
  flex: 1 1 280px;
  min-width: 0;
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

/* The one cell allowed to wrap. "Ambulance/Medical Response" on one line is
   what pushed the month columns out of the scroll box. */
.data-table .service-cell {
  white-space: normal;
  min-width: 150px;
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
