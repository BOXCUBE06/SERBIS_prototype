<!--
  DashboardCharts.vue

  The performance row: this month against the same days of last month. Built
  from what the dashboard already loaded, so nothing here fetches. Reuses the
  Analytics page's section card and its screen-reader table.
-->
<template>
  <v-row>
    <v-col cols="12" md="6" xl="3">
      <AnalyticsSection title="Filed vs resolved" info="Requests filed and resolved per day, last 30 days." :loading="loading" :empty="history.length === 0" empty-text="No requests yet">
        <div style="height: 200px;">
          <Line :data="flowData" :options="flowOptions" />
          <ChartDataTable caption="Filed vs resolved, last 30 days — same data as the chart above" category-label="Day" :labels="dayLabels" :series="flowSeries" />
        </div>
        <div class="text-caption text-medium-emphasis mt-2">
          This month {{ monthFlow.filed.current }} filed, {{ monthFlow.resolved.current }} resolved
          · same days last month {{ monthFlow.filed.previous }}, {{ monthFlow.resolved.previous }}
        </div>
      </AnalyticsSection>
    </v-col>

    <v-col cols="12" md="6" xl="3">
      <!-- Requests created as Booked never waited, so the server leaves their
           first_responded_at null and they drop out here on their own. -->
      <AnalyticsSection title="Time to first response" info="Average from filing to the first answer (Booked, Responding or Disapproved). Bookings filed already Booked are not counted." :loading="loading" :empty="response.current == null" empty-text="No responses this month">
        <div class="text-h4 font-weight-black">{{ duration(response.current) }}</div>
        <div v-if="response.previous != null" class="text-body-2 font-weight-bold mt-1" :class="responseTrend.cls">{{ responseTrend.text }}</div>
        <div class="text-caption text-medium-emphasis mt-1">{{ response.count }} answered this month</div>
      </AnalyticsSection>
    </v-col>

    <v-col cols="12" md="6" xl="3">
      <AnalyticsSection title="Backlog aging" info="Pending requests by how long they have waited. Click a bar to filter the queue." :loading="loading" :empty="waitTotal === 0" empty-text="No pending requests">
        <div style="height: 200px;">
          <Bar :data="waitData" :options="waitOptions" />
          <ChartDataTable caption="Pending requests by waiting time — same data as the chart above" category-label="Waiting" :labels="WAIT_BUCKETS" :series="[{ label: 'Pending requests', data: waitCounts }]" />
        </div>
        <!-- The chart is mouse-only; these do the same filter from the keyboard. -->
        <div class="d-flex flex-wrap ga-1 mt-3" role="group" aria-label="Filter the queue by waiting time">
          <v-btn
            v-for="(bucket, i) in WAIT_BUCKETS"
            :key="bucket"
            size="x-small"
            :variant="activeBucket === bucket ? 'flat' : 'tonal'"
            color="primary"
            class="text-none font-weight-bold"
            :aria-pressed="activeBucket === bucket"
            @click="emit('pick-bucket', bucket)"
          >{{ BUCKET_SHORT[i] }} · {{ waitCounts[i] }}</v-btn>
        </div>
      </AnalyticsSection>
    </v-col>

    <v-col cols="12" md="6" xl="3">
      <AnalyticsSection title="Top services" info="Top 5 services by requests filed this month, against the same days last month." :loading="loading" :empty="topServices.length === 0" empty-text="No requests this month">
        <div style="height: 200px;">
          <Bar :data="serviceData" :options="serviceOptions" />
          <ChartDataTable caption="Top services this month — same data as the chart above" category-label="Service" :labels="topServices.map((s) => s.label)" :series="serviceSeries" />
        </div>
      </AnalyticsSection>
    </v-col>
  </v-row>
</template>

<script setup>
import { computed } from 'vue'
import { Bar, Line } from 'vue-chartjs'
import AnalyticsSection from '@/components/AnalyticsSection.vue'
import ChartDataTable from '@/components/ChartDataTable.vue'
import { WAIT_BUCKETS, countByDay, lastDays, monthWindows, waitBucket, within } from '@/composables/dashboardTrends'
import { useChartTheme, withAlpha } from '@/composables/useChartTheme'

const props = defineProps({
  // Every service request: { filedAt, resolvedAt, respondedAt (ms or null), service }.
  history: { type: Array, default: () => [] },
  // The dashboard's open rows.
  rows: { type: Array, default: () => [] },
  // The bucket the queue is currently narrowed to, if any.
  activeBucket: { type: String, default: null },
  loading: { type: Boolean, default: false },
})
const emit = defineEmits(['pick-bucket'])

const BUCKET_SHORT = ['Under a day', '1–3d', '3–7d', '7+d']
const { current, previous } = monthWindows()

const { colors, isDark, legend, base, horizontal } = useChartTheme()

// ---- Filed vs resolved ----------------------------------------------------

const days = lastDays(30)
const dayLabels = days.map((d) => d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }))
const filedTimes = computed(() => props.history.map((h) => h.filedAt))
const resolvedTimes = computed(() => props.history.map((h) => h.resolvedAt).filter(Boolean))
const flowSeries = computed(() => [
  { label: 'Filed', data: countByDay(filedTimes.value, days) },
  { label: 'Resolved', data: countByDay(resolvedTimes.value, days) },
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
    pointRadius: 0,
  })),
}))
const flowOptions = computed(() => ({ ...base.value, plugins: { ...base.value.plugins, legend: legend.value } }))

const countIn = (times) => ({ current: times.filter((t) => within(t, current)).length, previous: times.filter((t) => within(t, previous)).length })
const monthFlow = computed(() => ({ filed: countIn(filedTimes.value), resolved: countIn(resolvedTimes.value) }))

// ---- Time to first response -----------------------------------------------

// Grouped by when the answer came, so this month's figure is this month's work.
const avgWait = (window) => {
  const waits = props.history.filter((h) => within(h.respondedAt, window)).map((h) => h.respondedAt - h.filedAt)
  return { avg: waits.length > 0 ? waits.reduce((a, b) => a + b, 0) / waits.length : null, count: waits.length }
}
const response = computed(() => {
  const now = avgWait(current)
  return { current: now.avg, count: now.count, previous: avgWait(previous).avg }
})

const duration = (ms) => {
  if (ms == null) return '—'
  const hours = ms / 3_600_000
  if (hours < 1) return `${Math.round(ms / 60_000)}m`
  return hours < 48 ? `${hours.toFixed(1)}h` : `${(hours / 24).toFixed(1)}d`
}

// Slower is worse, so up reads as a warning.
const responseTrend = computed(() => {
  const d = (response.value.current ?? 0) - response.value.previous
  if (Math.abs(d) < 60_000) return { text: '– same as last month', cls: 'text-medium-emphasis' }
  return { text: `${d > 0 ? '▲' : '▼'} ${duration(Math.abs(d))} vs same days last month`, cls: d > 0 ? 'text-warning-strong' : 'text-success-strong' }
})

// ---- Backlog aging ----------------------------------------------------------

const waitCounts = computed(() => {
  const counts = WAIT_BUCKETS.map(() => 0)
  for (const r of props.rows) {
    if (r.kind !== 'borrow' && r.status === 'Pending') counts[WAIT_BUCKETS.indexOf(waitBucket(r.filedAt))]++
  }
  return counts
})
const waitTotal = computed(() => waitCounts.value.reduce((a, b) => a + b, 0))
// While a bucket is picked the others dim, so the selection reads on the chart.
const waitData = computed(() => ({
  labels: WAIT_BUCKETS,
  datasets: [{
    label: 'Pending requests',
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

// ---- Top services -------------------------------------------------------------

const topServices = computed(() => {
  const counts = new Map()
  for (const h of props.history) {
    const c = counts.get(h.service) ?? { label: h.service, current: 0, previous: 0 }
    if (within(h.filedAt, current)) c.current++
    else if (within(h.filedAt, previous)) c.previous++
    counts.set(h.service, c)
  }
  return [...counts.values()].filter((c) => c.current > 0).toSorted((a, b) => b.current - a.current).slice(0, 5)
})
const serviceSeries = computed(() => [
  { label: 'This month', data: topServices.value.map((s) => s.current) },
  { label: 'Same days last month', data: topServices.value.map((s) => s.previous) },
])
const serviceData = computed(() => ({
  labels: topServices.value.map((s) => s.label),
  datasets: serviceSeries.value.map((s, i) => ({
    ...s,
    backgroundColor: i === 0 ? colors.value.primary : withAlpha(colors.value.primary, 0.35),
    borderRadius: 4,
    maxBarThickness: 14,
  })),
}))
const serviceOptions = computed(() => ({ ...horizontal.value, plugins: { ...horizontal.value.plugins, legend: legend.value } }))
</script>
