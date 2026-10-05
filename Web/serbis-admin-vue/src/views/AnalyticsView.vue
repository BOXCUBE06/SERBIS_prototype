<template>
  <v-container fluid class="analytics-bg">

    <PageHeader title="Analytics">
      <!-- Reconciled against the same helper the Dashboard uses, so the two
           pages cannot report different totals for the same window. -->
      <template v-if="report" #subtitle>
        {{ report.range.from }} to {{ report.range.to }} ({{ report.range.timezone }})
        &bull; {{ report.totals.serviceRequests.toLocaleString() }}
        {{ report.totals.serviceRequests === 1 ? 'request' : 'requests' }} in range
        <template v-if="report.totals.walkIn > 0">
          &bull; {{ report.totals.walkIn.toLocaleString() }} walk-in (no barangay)
        </template>
      </template>
    </PageHeader>

    <!-- One compact row of 36px controls (styles/filter-bar.css). Each select
         shows its own "All …" value, so it needs no floating label. -->
    <div class="filter-bar mb-2">
      <v-btn-toggle
        v-model="preset"
        mandatory
        variant="outlined"
        color="primary"
        density="compact"
        divided
        rounded="lg"
        class="range-toggle"
        aria-label="Date range"
      >
        <v-btn value="month" class="text-none font-weight-bold px-3">This month</v-btn>
        <v-btn value="quarter" class="text-none font-weight-bold px-3">This quarter</v-btn>
        <v-btn value="year" class="text-none font-weight-bold px-3">This year</v-btn>
        <v-btn value="all" class="text-none font-weight-bold px-3">All time</v-btn>
        <v-btn value="custom" class="text-none font-weight-bold px-3">Custom</v-btn>
      </v-btn-toggle>

      <template v-if="preset === 'custom'">
        <v-text-field
          v-model="customFrom"
          type="date"
          aria-label="From"
          density="compact"
          variant="outlined"
          hide-details
          class="filter-bar__select"
        />
        <v-text-field
          v-model="customTo"
          type="date"
          aria-label="To"
          density="compact"
          variant="outlined"
          hide-details
          class="filter-bar__select"
        />
      </template>

      <v-select
        v-model="barangayIds"
        :items="barangayOptions"
        item-title="label"
        item-value="value"
        aria-label="Barangay"
        placeholder="All barangays"
        persistent-placeholder
        multiple
        density="compact"
        variant="outlined"
        hide-details
        class="filter-bar__select filter-select"
      >
        <!-- One line of plain text, drawn once: the name, or a count, so the field stays single-select height. -->
        <template #selection="{ index }">
          <span v-if="index === 0" class="filter-text" :title="selectedNames(barangayIds, barangayOptions).join(', ')">{{ selectedText(barangayIds, barangayOptions, 'barangays') }}</span>
        </template>
      </v-select>

      <v-select
        v-model="serviceIds"
        :items="serviceOptions"
        item-title="label"
        item-value="value"
        aria-label="Service"
        placeholder="All services"
        persistent-placeholder
        multiple
        density="compact"
        variant="outlined"
        hide-details
        class="filter-bar__select filter-select"
      >
        <!-- First choice as a chip, the rest counted, so the control keeps its width. -->
        <template #selection="{ index }">
          <span v-if="index === 0" class="filter-text" :title="selectedNames(serviceIds, serviceOptions).join(', ')">{{ selectedText(serviceIds, serviceOptions, 'services') }}</span>
        </template>
      </v-select>

      <v-btn
        v-if="hasFilters"
        variant="text"
        color="primary"
        height="36"
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
    <div v-if="awaitingCustomRange" class="text-caption text-medium-emphasis mb-2">
      Pick a start and an end date to apply a custom range. Showing
      {{ report ? `${report.range.from} to ${report.range.to}` : 'nothing' }} until then.
    </div>

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

    <SegmentedTabs v-model="tab" :items="TABS" tonal class="mt-2 mb-5" />

    <!-- The answer first, then the numbers behind it, then the charts as
         evidence. All derived from the report already on screen. -->
    <div v-if="report" :class="{ 'is-dim': refreshing }">
      <section v-if="finding" class="finding mb-4" aria-labelledby="finding-label">
        <div class="finding__icon" aria-hidden="true">
          <v-icon :icon="finding.icon" size="20" />
        </div>
        <div class="min-w-0">
          <h3 id="finding-label" class="section-label">What stands out</h3>
          <p class="finding__lead">{{ finding.lead }}</p>
          <p v-if="finding.detail" class="finding__detail">{{ finding.detail }}</p>
        </div>
      </section>

      <div v-if="kpis.length > 0" class="kpi-grid mb-4">
        <v-card v-for="k in kpis" :key="k.title" elevation="0" class="dash-card">
          <div class="kpi-title">{{ k.title }}</div>
          <div class="kpi-value">{{ k.value }}</div>
          <div class="kpi-note">{{ k.note }}</div>
        </v-card>
      </div>
    </div>

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
           zero. The barangay filter narrows it to the chosen barangays. -->
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
            :total-loans="barangayCoverage.totalLoans"
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
                {{ loans.returnedLate.count }} of {{ loans.returnedLate.of }} returned with a due date in this range
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
import SegmentedTabs from '@/components/SegmentedTabs.vue'
import '@/components/dashboard.css'
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
  { value: 'demand', label: 'Demand' },
  { value: 'barangays', label: 'Barangays' },
  { value: 'operations', label: 'Operations' },
  { value: 'equipment', label: 'Equipment & Vehicles' },
]
const tab = ref('demand')

const preset = ref('quarter')
const volumeView = ref('chart')
const customFrom = ref('')
const customTo = ref('')
// Empty means every barangay / every service.
const barangayIds = ref([])
const serviceIds = ref([])

const report = ref(null)
const loading = ref(true)
const error = ref('')
// Skeletons on the first load only; a filter change keeps the old figures, dimmed.
const firstLoad = computed(() => loading.value && !report.value)
const refreshing = computed(() => loading.value && !!report.value)

const barangays = ref([])
const services = ref([])

// Sent as barangay_id[]=walkin: requests filed with no barangay.
const WALK_IN = 'walkin'
const barangayOptions = computed(() => [
  { label: 'Walk-in (no barangay)', value: WALK_IN },
  ...barangays.value.map(b => ({ label: b.barangay_name, value: b.barangay_id })),
])

const serviceOptions = computed(() => services.value.map(s => ({ label: s.service_name, value: s.service_id })))

const selectedNames = (ids, options) => ids.map(id => options.find(o => o.value === id)?.label).filter(Boolean)
/** The name when one is selected, else a count; walk-ins are appended, never counted. */
const selectedText = (ids, options, noun) => {
  const real = ids.filter(id => id !== WALK_IN)
  const base = real.length === 1 ? (selectedNames(real, options)[0] ?? '') : `${real.length} ${noun}`
  if (!ids.includes(WALK_IN)) return base
  return real.length === 0 ? 'Walk-ins' : `${base} + walk-ins`
}

const awaitingCustomRange = computed(() =>
  preset.value === 'custom' && !(customFrom.value && customTo.value)
)

const hasFilters = computed(() =>
  barangayIds.value.length > 0 || serviceIds.value.length > 0 || preset.value !== 'quarter'
)

const clearFilters = () => {
  preset.value = 'quarter'
  barangayIds.value = []
  serviceIds.value = []
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

  for (const id of barangayIds.value) params.append('barangay_id[]', id)
  for (const id of serviceIds.value) params.append('service_id[]', id)

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
  // A filter list that fails to load leaves nothing selected (all), which is
  // the correct default anyway — not worth failing the page over.
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
watch([preset, barangayIds, serviceIds, customFrom, customTo], () => {
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
const selectedVehicleTrips = computed(() => report.value?.vehicleTrips ?? [])
const barangayCoverage = computed(() => report.value?.barangayCoverage ?? {
  barangays: [], walkIn: 0, totalResidents: 0, totalRequests: 0, totalLoans: 0,
})

const turnaround = computed(() => report.value?.turnaround ?? { resolution: { medianDays: null, n: 0 } })

/* ---------------------------------------------------------------------------
 * "What stands out" and the KPI cards. Read only from the report above; a
 * figure the report does not carry is left out rather than estimated.
 * ------------------------------------------------------------------------ */

const DAY_NAMES = { Mon: 'Monday', Tue: 'Tuesday', Wed: 'Wednesday', Thu: 'Thursday', Fri: 'Friday', Sat: 'Saturday', Sun: 'Sunday' }

const sum = (values) => values.reduce((a, b) => a + (b || 0), 0)
const pct = (n, of) => (of > 0 ? Math.round((n / of) * 100) : null)
const count = (n, one, many = `${one}s`) => `${n.toLocaleString()} ${n === 1 ? one : many}`
const days = (d) => {
  if (d === null || d === undefined) return '—'
  return d < 1 ? count(Math.max(1, Math.round(d * 24)), 'hour') : `${d.toFixed(1)} days`
}
/** Index and value of the largest entry, or null when everything is zero. */
const top = (values) => {
  const max = Math.max(0, ...values)
  return max > 0 ? { index: values.indexOf(max), value: max } : null
}
const statusTotal = (status) => sum(outcomes.value.series.find(s => s.label === status)?.data ?? [])

const closed = computed(() => {
  const n = statusTotal('Resolved') + statusTotal('Cancelled') + statusTotal('Disapproved')
  return { n, of: outcomes.value.total, pct: pct(n, outcomes.value.total) }
})
const tripTotal = computed(() => sum(selectedVehicleTrips.value.map(v => v.trips)))

const finding = computed(() => {
  if (!report.value) return null

  if (tab.value === 'demand') {
    const day = top(demand.value.days.data)
    const block = top(demand.value.timeOfDay.data)
    if (!day || !block) return null
    const dayName = DAY_NAMES[demand.value.days.labels[day.index]] ?? demand.value.days.labels[day.index]
    const service = top(volume.value.series.map(s => sum(s.data)))
    const months = volume.value.labels.map((_, i) => sum(volume.value.series.map(s => s.data[i])))
    return {
      icon: 'mdi-chart-bar',
      lead: `Most requests arrive in the ${demand.value.timeOfDay.labels[block.index].toLowerCase()} (${pct(block.value, demand.value.total)}%), and ${dayName} is the busiest day at ${pct(day.value, demand.value.total)}%.`,
      detail: [
        service && `${volume.value.series[service.index].label} is the most requested service: ${service.value} of ${volume.value.total}.`,
        months.length > 1 && `Requests went from ${months[0]} in ${volume.value.labels[0]} to ${months.at(-1)} in ${volume.value.labels.at(-1)}.`,
      ].filter(Boolean).join(' '),
    }
  }

  if (tab.value === 'operations') {
    if (outcomes.value.total === 0 && aging.value.total === 0) return null
    // Under a day plus 1-3 days: the buckets AnalyticsReport::openRequestAging returns first.
    const recent = (aging.value.data[0] ?? 0) + (aging.value.data[1] ?? 0)
    let lead = `${closed.value.pct}% of requests filed in this range are closed, and ${recent} of the ${aging.value.total} still open were filed in the last 3 days.`
    if (closed.value.of === 0) {
      lead = `No requests were filed in this range; ${count(aging.value.total, 'request')} from earlier ${aging.value.total === 1 ? 'is' : 'are'} still open.`
    } else if (aging.value.total === 0) {
      lead = `${closed.value.pct}% of requests filed in this range are closed, and nothing is waiting right now.`
    }
    return {
      icon: 'mdi-check-circle-outline',
      lead,
      detail: [
        aging.value.oldestDays > 0 && `The oldest open request has waited ${count(aging.value.oldestDays, 'day')}.`,
        closed.value.of > 0 && `${pct(statusTotal('Cancelled'), closed.value.of)}% were cancelled and ${pct(statusTotal('Disapproved'), closed.value.of)}% disapproved.`,
      ].filter(Boolean).join(' '),
    }
  }

  if (tab.value === 'equipment') {
    const items = equipmentUtilization.value.items
    const item = top(items.map(i => i.timesBorrowed))
    const unit = top(selectedVehicleTrips.value.map(v => v.trips))
    if (!item && !unit) return null
    const zero = equipmentUtilization.value.zeroBorrowCount
    const late = loans.value.returnedLate
    return {
      icon: 'mdi-package-variant-closed',
      lead: item
        ? `${items[item.index].label} is borrowed most: ${item.value} of ${equipmentUtilization.value.total} loans (${pct(item.value, equipmentUtilization.value.total)}%)${zero > 0 ? `, while ${zero} of ${items.length} items were not borrowed at all` : ''}.`
        : `No equipment was borrowed in this range.`,
      detail: [
        unit && `${selectedVehicleTrips.value[unit.index].label} made ${pct(unit.value, tripTotal.value)}% of vehicle trips.`,
        late.of > 0 && `${late.count} of ${late.of} returned loans with a due date came back after it.`,
      ].filter(Boolean).join(' '),
    }
  }

  // Barangays. Service requests only; loans have their own column.
  const rows = barangayCoverage.value.barangays
  const place = top(rows.map(b => b.requests))
  if (!place) return null
  const active = rows.filter(b => b.requests > 0).length
  return {
    icon: 'mdi-map-marker-radius-outline',
    lead: `${rows[place.index].name} files the most: ${place.value} of ${barangayCoverage.value.totalRequests} requests (${pct(place.value, barangayCoverage.value.totalRequests)}%).`,
    detail: [
      `${active} of ${rows.length} barangays filed at least one.`,
      barangayCoverage.value.walkIn > 0 && `${count(barangayCoverage.value.walkIn, 'walk-in')} carry no barangay.`,
    ].filter(Boolean).join(' '),
  }
})

/** Open now ignores the date range but not Service or Barangay, so the note says which applies. */
const openNote = computed(() => {
  const label = (options, id) => options.find(o => o.value === id)?.label
  const scope = [
    serviceId.value === ALL ? null : label(serviceOptions.value, serviceId.value),
    barangayId.value === ALL ? null : label(barangayOptions.value, barangayId.value),
  ].filter(Boolean).join(' in ')
  const oldest = aging.value.total > 0 ? `Oldest ${count(aging.value.oldestDays, 'day')}.` : 'None waiting.'
  return scope ? `Open requests for ${scope}, any date. ${oldest}` : `All open requests, any date. ${oldest}`
})

const kpis = computed(() => {
  if (!report.value) return []
  const open = aging.value.total
  const closedCard = {
    title: 'Closed',
    value: closed.value.pct === null ? '—' : `${closed.value.pct}%`,
    note: `${closed.value.n} of ${closed.value.of} resolved, cancelled or disapproved`,
  }

  if (tab.value === 'demand') {
    const walkIn = report.value.totals.walkIn
    return [
      { title: 'Requests filed', value: report.value.totals.serviceRequests.toLocaleString(), note: walkIn > 0 ? `${walkIn} walk-in (no barangay)` : 'In the selected range' },
      closedCard,
      { title: 'Median time to resolve', value: days(turnaround.value.resolution.medianDays), note: turnaround.value.resolution.n > 0 ? `Resolved requests only, ${count(turnaround.value.resolution.n, 'request')}` : 'No resolved requests in this range' },
      { title: 'Open now', value: open.toLocaleString(), note: openNote.value },
    ]
  }

  if (tab.value === 'operations') {
    const resolved = statusTotal('Resolved')
    return [
      closedCard,
      { title: 'Resolved', value: resolved.toLocaleString(), note: closed.value.of > 0 ? `${pct(resolved, closed.value.of)}% of all requests filed` : 'No requests in this range' },
      { title: 'Open now', value: open.toLocaleString(), note: 'Pending, booked or responding, any filing date' },
      { title: 'Oldest open', value: open > 0 ? count(aging.value.oldestDays, 'day') : '—', note: open > 0 ? 'Still pending, booked or responding' : 'Nothing is waiting' },
    ]
  }

  if (tab.value === 'equipment') {
    const items = equipmentUtilization.value.items
    const unused = items.filter(i => i.timesBorrowed === 0).map(i => i.label)
    const late = loans.value.returnedLate
    return [
      { title: 'Equipment loans', value: equipmentUtilization.value.total.toLocaleString(), note: `Across ${items.length - unused.length} of ${items.length} catalogue items` },
      { title: 'Returned late', value: late.percent === null ? '—' : `${late.percent}%`, note: `${late.count} of ${late.of} returned with a due date in this range` },
      { title: 'Items never borrowed', value: `${unused.length} of ${items.length}`, note: unused.length > 0 ? unused.slice(0, 3).join(', ') + (unused.length > 3 ? ` and ${unused.length - 3} more` : '') : 'Every item was borrowed' },
      { title: 'Vehicle trips', value: tripTotal.value.toLocaleString(), note: `Across ${count(selectedVehicleTrips.value.filter(v => v.trips > 0).length, 'vehicle')}` },
    ]
  }

  return []
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
  /* The Dashboard's card tokens (components/dashboard.css), so KPI cards here
     are the same 24px-radius, soft-shadow cards. */
  --dash-text: rgb(var(--v-theme-on-surface));
  --dash-muted: rgba(var(--v-theme-on-surface), 0.62);
  --dash-radius: 24px;
  --dash-shadow: 0 1px 2px rgba(var(--v-theme-on-surface), 0.04), 0 4px 14px rgba(var(--v-theme-on-surface), 0.08);
  --dash-pad: 20px;
}
/* on-surface is light in the dark theme, so the same shadow would glow. */
.v-theme--dark .analytics-bg {
  --dash-shadow: 0 1px 2px rgba(var(--v-shadow-color), 0.4), 0 4px 14px rgba(var(--v-shadow-color), 0.25);
}

/* Vuetify's x-small button default (0.625rem/10px) falls under an 11px
   readability floor, same fact as AnalyticsSection's chip-count override. */
.toggle-btn-text {
  font-size: 0.6875rem;
}

/* Service names run long; 160px (filter-bar__select) clips most of them. */
.filter-select {
  width: 200px;
}
/* One line: a long name ellipsises inside the 200px control. */
.filter-select :deep(.v-select__selection) {
  min-width: 0;
  max-width: 100%;
}
.filter-text {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  line-height: 24px;
}
/* The empty placeholder and the selected text share one centred 24px line, so the field looks the same either way. */
.filter-select :deep(.v-field__input) {
  align-items: center;
  padding-top: 0;
  padding-bottom: 0;
}
.filter-select :deep(.v-field__input input) {
  height: 24px;
  min-height: 0;
  padding: 0;
  line-height: 24px;
}

/* The range toggle wraps onto a second line when it outgrows the row; it
   must not scroll. Vuetify gives v-btn-group a fixed height, so an
   overflow-x scrollbar renders INSIDE that box and leaves a 17px content
   strip. Wrapping keeps every button at full height. */
.range-toggle {
  flex-shrink: 0;
  flex-wrap: wrap;
  height: auto;
  overflow: visible;
}

/* min-height, not height: Vuetify writes an INLINE `height: auto` on every
   button inside a v-btn-group, which no stylesheet rule can outrank. */
.range-toggle :deep(.v-btn) {
  flex-shrink: 0;
  min-height: 36px;
}

/* "What stands out": a primary tint, the heading in primary-strong (AA on the
   tint in both themes), the finding itself at the section-title size. */
.finding {
  display: flex;
  gap: 16px;
  align-items: flex-start;
  padding: 20px 24px;
  border-radius: 16px;
  background: rgba(var(--v-theme-primary), 0.10);
}
.finding__icon {
  flex: none;
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
}
.section-label {
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgb(var(--v-theme-primary-strong));
}
.finding__lead {
  margin: 4px 0 0;
  font-size: 18.72px;
  line-height: 28px;
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
}
.finding__detail {
  margin: 4px 0 0;
  font-size: 14px;
  line-height: 20px;
  color: rgba(var(--v-theme-on-surface), 0.7);
}

.kpi-grid {
  display: grid;
  gap: 16px;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
}
.kpi-title {
  font-size: 14px;
  line-height: 20px;
  font-weight: 600;
  color: rgba(var(--v-theme-on-surface), 0.7);
}
.kpi-value {
  margin-top: 8px;
  font-size: 36px;
  line-height: 40px;
  font-weight: 800;
  letter-spacing: -0.02em;
  font-variant-numeric: tabular-nums;
  color: var(--dash-text);
}
.kpi-note {
  margin-top: 8px;
  font-size: 12px;
  line-height: 16px;
  color: var(--dash-muted);
}

/* A filter change keeps the old figures on screen, dimmed, until the new ones land. */
.is-dim {
  opacity: 0.55;
  transition: opacity var(--motion-fast) var(--ease-out);
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
