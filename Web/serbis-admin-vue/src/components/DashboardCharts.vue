<!--
  DashboardCharts.vue

  The chart row under the queue. Built from what the dashboard already loaded,
  so nothing here fetches. Reuses the Analytics page's section card and its
  screen-reader table.
-->
<template>
  <v-row>
    <v-col cols="12" md="6" xl="3">
      <AnalyticsSection title="Filed vs resolved" info="Requests filed and requests resolved per day, last 7 days." :loading="loading" :empty="history.length === 0" empty-text="No requests yet">
        <div style="height: 220px;">
          <Line :data="flowData" :options="flowOptions" />
          <ChartDataTable caption="Filed vs resolved, last 7 days — same data as the chart above" category-label="Day" :labels="dayLabels" :series="flowSeries" />
        </div>
      </AnalyticsSection>
    </v-col>

    <v-col cols="12" md="6" xl="3">
      <AnalyticsSection title="Waiting time" info="Open requests by how long they have waited. Click a bar to filter the table." :loading="loading" :empty="waitTotal === 0" empty-text="Nothing is open">
        <div style="height: 220px;">
          <Bar :data="waitData" :options="waitOptions" />
          <ChartDataTable caption="Open requests by waiting time — same data as the chart above" category-label="Waiting" :labels="WAIT_BUCKETS" :series="[{ label: 'Open requests', data: waitCounts }]" />
        </div>
      </AnalyticsSection>
    </v-col>

    <v-col cols="12" md="6" xl="3">
      <AnalyticsSection title="Requests by service" info="Top 5 services by requests filed in the last 30 days." :loading="loading" :empty="topServices.length === 0" empty-text="No requests in 30 days">
        <div style="height: 220px;">
          <Bar :data="serviceData" :options="horizontal" />
          <ChartDataTable caption="Requests by service, last 30 days — same data as the chart above" category-label="Service" :labels="topServices.map((s) => s.label)" :series="[{ label: 'Requests', data: topServices.map((s) => s.count) }]" />
        </div>
      </AnalyticsSection>
    </v-col>

    <v-col cols="12" md="6" xl="3">
      <AnalyticsSection title="Fleet status" :loading="loading" :empty="!fleet || fleet.total === 0" empty-text="No vehicle data">
        <div style="height: 220px;">
          <Doughnut :data="fleetData" :options="donut" />
          <ChartDataTable caption="Fleet status — same data as the chart above" category-label="Status" :labels="fleetLabels" :series="[{ label: 'Units', data: fleetCounts }]" />
        </div>
      </AnalyticsSection>
    </v-col>
  </v-row>
</template>

<script setup>
import { computed } from 'vue'
import { Bar, Doughnut, Line } from 'vue-chartjs'
import AnalyticsSection from '@/components/AnalyticsSection.vue'
import ChartDataTable from '@/components/ChartDataTable.vue'
import { BOOKED_COLOR } from '@/composables/adminUi'
import { DAY_MS, WAIT_BUCKETS, countByDay, lastDays, waitBucket } from '@/composables/dashboardTrends'
import { useChartTheme, withAlpha } from '@/composables/useChartTheme'

const props = defineProps({
  // Every service request: { filedAt, resolvedAt (ms or null), service }.
  history: { type: Array, default: () => [] },
  // The dashboard's open rows.
  rows: { type: Array, default: () => [] },
  // { total, available, assigned, onTrip, maintenance }, or null when the account cannot read vehicles.
  fleet: { type: Object, default: null },
  // The bucket the table is currently narrowed to, if any.
  activeBucket: { type: String, default: null },
  loading: { type: Boolean, default: false },
})
const emit = defineEmits(['pick-bucket'])

const { colors, isDark, legend, base, horizontal, donut } = useChartTheme()

// ---- Filed vs resolved ----------------------------------------------------

const days = lastDays(7)
const dayLabels = days.map((d) => d.toLocaleDateString('en-PH', { weekday: 'short', day: 'numeric' }))
const flowSeries = computed(() => [
  { label: 'Filed', data: countByDay(props.history.map((h) => h.filedAt), days) },
  { label: 'Resolved', data: countByDay(props.history.filter((h) => h.resolvedAt).map((h) => h.resolvedAt), days) },
])
const flowColors = computed(() => [isDark.value ? '#3987e5' : '#2a78d6', colors.value.success])
const flowData = computed(() => ({
  labels: dayLabels,
  datasets: flowSeries.value.map((s, i) => ({
    label: s.label,
    data: s.data,
    borderColor: flowColors.value[i],
    backgroundColor: flowColors.value[i],
    tension: 0.3,
    pointRadius: 3,
  })),
}))
const flowOptions = computed(() => ({ ...base.value, plugins: { ...base.value.plugins, legend: legend.value } }))

// ---- Waiting time -----------------------------------------------------------

// Requests only: a borrowing has no wait in this sense.
const waitCounts = computed(() => {
  const counts = WAIT_BUCKETS.map(() => 0)
  for (const r of props.rows) {
    if (r.kind !== 'borrow') counts[WAIT_BUCKETS.indexOf(waitBucket(r.filedAt))]++
  }
  return counts
})
const waitTotal = computed(() => waitCounts.value.reduce((a, b) => a + b, 0))
// While a bucket is picked the others dim, so the selection reads on the chart.
const waitData = computed(() => ({
  labels: WAIT_BUCKETS,
  datasets: [{
    label: 'Open requests',
    data: waitCounts.value,
    backgroundColor: WAIT_BUCKETS.map((b) => (props.activeBucket && props.activeBucket !== b ? withAlpha(colors.value.primary, 0.35) : colors.value.primary)),
    borderRadius: 4,
    maxBarThickness: 26,
  }],
}))
const waitOptions = computed(() => ({
  ...horizontal.value,
  onClick: (_event, elements) => {
    if (elements.length > 0) emit('pick-bucket', WAIT_BUCKETS[elements[0].index])
  },
  onHover: (event, elements) => {
    event.native.target.style.cursor = elements.length > 0 ? 'pointer' : 'default'
  },
}))

// ---- Requests by service ----------------------------------------------------

const topServices = computed(() => {
  const since = Date.now() - 30 * DAY_MS
  const counts = new Map()
  for (const h of props.history) {
    if (h.filedAt >= since) counts.set(h.service, (counts.get(h.service) ?? 0) + 1)
  }
  return [...counts].map(([label, count]) => ({ label, count })).toSorted((a, b) => b.count - a.count).slice(0, 5)
})
const serviceData = computed(() => ({
  labels: topServices.value.map((s) => s.label),
  datasets: [{
    label: 'Requests',
    data: topServices.value.map((s) => s.count),
    backgroundColor: colors.value.primary,
    borderRadius: 4,
    maxBarThickness: 26,
  }],
}))

// ---- Fleet --------------------------------------------------------------------

// Status colours, so the donut reads like the pills elsewhere in the panel.
const slices = computed(() => {
  const f = props.fleet
  if (!f) return []
  return [
    { label: 'Available', count: f.available, color: colors.value.success },
    { label: 'Assigned', count: f.assigned, color: isDark.value ? '#A78BFA' : BOOKED_COLOR },
    { label: 'On trip', count: f.onTrip, color: colors.value.info },
    { label: 'Maintenance', count: f.maintenance, color: colors.value.warning },
  ].filter((s) => s.count > 0)
})

const fleetLabels = computed(() => slices.value.map((s) => s.label))
const fleetCounts = computed(() => slices.value.map((s) => s.count))
const fleetData = computed(() => ({
  labels: fleetLabels.value,
  datasets: [{
    data: fleetCounts.value,
    backgroundColor: slices.value.map((s) => s.color),
    borderColor: colors.value.surface,
    borderWidth: 2,
  }],
}))
</script>
