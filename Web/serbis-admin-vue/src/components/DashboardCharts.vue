<!--
  DashboardCharts.vue

  The dashboard's chart row: requests filed and resolved per day, and what is
  asked for most. Nothing here fetches; the view hands over what it loaded.
-->
<template>
  <v-row class="mb-2">
    <v-col cols="12" lg="8">
      <v-card elevation="0" class="dash-card dash-tint h-100">
        <div class="dash-card-title">Filed &amp; Resolved</div>
        <div class="dash-card-subtitle mb-3">Last 30 days</div>
        <div v-if="loading" class="skel skel-plot" aria-hidden="true"></div>
        <div v-else-if="history.length === 0" class="empty content-in">No requests yet</div>
        <div v-else class="chart-box content-in">
          <Line :data="flowData" :options="flowOptions" :plugins="[crosshair]" />
          <ChartDataTable caption="Requests filed and resolved per day, last 30 days — same data as the chart above" category-label="Day" :labels="dayLabels" :series="flowSeries" />
        </div>
      </v-card>
    </v-col>

    <v-col cols="12" lg="4">
      <v-card elevation="0" class="dash-card h-100">
        <div class="d-flex justify-space-between align-start flex-wrap ga-2 mb-3">
          <div>
            <div class="dash-card-title">Most Requested</div>
            <div class="dash-card-subtitle">{{ view === 'services' ? 'Requests by service' : 'Times borrowed by item' }}, last 30 days</div>
          </div>
          <v-btn-toggle v-model="view" mandatory variant="outlined" color="primary" density="compact" divided rounded="lg" aria-label="Most requested">
            <v-btn value="services" size="x-small" class="text-none font-weight-bold px-3">Services</v-btn>
            <v-btn value="items" size="x-small" class="text-none font-weight-bold px-3">Equipment</v-btn>
          </v-btn-toggle>
        </div>
        <div v-if="loading" class="skel skel-plot" aria-hidden="true"></div>
        <div v-else-if="ranked.length === 0" class="empty content-in">Nothing in the last 30 days</div>
        <div v-else :key="view" class="chart-box content-in">
          <Bar :data="rankData" :options="rankOptions" />
          <ChartDataTable :caption="`Most requested ${view}, last 30 days — same data as the chart above`" category-label="Name" :labels="ranked.map((r) => r.label)" :series="[{ label: 'Count', data: ranked.map((r) => r.value) }]" />
        </div>
      </v-card>
    </v-col>
  </v-row>
</template>

<script setup>
import './dashboard.css'
import { computed, ref } from 'vue'
import { Bar, Line } from 'vue-chartjs'
import ChartDataTable from '@/components/ChartDataTable.vue'
import { countByDay, lastDays } from '@/composables/dashboardTrends'
import { useChartTheme, withAlpha } from '@/composables/useChartTheme'

const props = defineProps({
  // Every service request: { filedAt, resolvedAt } in ms, resolvedAt null while open.
  history: { type: Array, default: () => [] },
  // Last-30-days ranking from /admin/dashboard: { services, items }, each { labels, data }.
  top: { type: Object, default: null },
  loading: { type: Boolean, default: false },
})

const { colors, legend, base, horizontal } = useChartTheme()

// ---- Filed & Resolved -----------------------------------------------------

const DAYS = 30
const LABEL_EVERY = 4
const days = lastDays(DAYS)
const dayLabels = days.map((d) => d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }))
const dayTitles = days.map((d) => d.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' }))

const flowSeries = computed(() => [
  { label: 'Filed', data: countByDay(props.history.map((h) => h.filedAt), days) },
  { label: 'Resolved', data: countByDay(props.history.map((h) => h.resolvedAt).filter(Boolean), days) },
])
// Filed is neutral load; resolved is the good news, so it takes the brand teal.
const flowColors = computed(() => [colors.value.slate, colors.value.primary])
const FILL_TOP = [0.16, 0.4]

// Fades from the series colour at the line to nothing at the axis. The canvas
// gradient needs the chart area, which does not exist on the first pass.
const fade = (color, top) => ({ chart }) => {
  const area = chart.chartArea
  if (!area) return withAlpha(color, top / 2)
  const gradient = chart.ctx.createLinearGradient(0, area.top, 0, area.bottom)
  gradient.addColorStop(0, withAlpha(color, top))
  gradient.addColorStop(1, withAlpha(color, 0))
  return gradient
}
const flowData = computed(() => ({
  labels: dayLabels,
  datasets: flowSeries.value.map((s, i) => ({
    label: s.label,
    data: s.data,
    borderColor: flowColors.value[i],
    backgroundColor: fade(flowColors.value[i], FILL_TOP[i]),
    borderWidth: 2,
    fill: true,
    // Monotone: the curve never dips below zero or overshoots a peak.
    cubicInterpolationMode: 'monotone',
    pointRadius: 0,
    pointHoverRadius: 4,
  })),
}))

const dashedGrid = computed(() => ({ color: base.value.scales.y.grid.color, borderDash: [4, 4] }))
const flowOptions = computed(() => ({
  ...base.value,
  plugins: {
    legend: legend.value,
    tooltip: { boxPadding: 4, callbacks: { title: (items) => dayTitles[items[0].dataIndex] } },
  },
  scales: {
    x: {
      grid: { display: false },
      // Every fourth label, counted back from today so today is always named.
      ticks: { ...base.value.scales.x.ticks, autoSkip: false, maxRotation: 0, callback: (_v, i) => ((DAYS - 1 - i) % LABEL_EVERY === 0 ? dayLabels[i] : '') },
    },
    y: { ...base.value.scales.y, grid: dashedGrid.value, border: { display: false } },
  },
}))

// A vertical guide under the pointer, so both series read off the same day.
const crosshair = computed(() => ({
  id: 'crosshair',
  afterDatasetsDraw(chart) {
    const active = chart.tooltip?.getActiveElements?.() ?? []
    if (active.length === 0) return
    const { ctx, chartArea } = chart
    ctx.save()
    ctx.beginPath()
    ctx.setLineDash([4, 4])
    ctx.lineWidth = 1
    ctx.strokeStyle = withAlpha(colors.value['on-surface'], 0.4)
    ctx.moveTo(active[0].element.x, chartArea.top)
    ctx.lineTo(active[0].element.x, chartArea.bottom)
    ctx.stroke()
    ctx.restore()
  },
}))

// ---- Most Requested -------------------------------------------------------

const TOP = 8
const view = ref('services')
const ranked = computed(() => {
  const t = props.top?.[view.value]
  return (t?.labels ?? []).map((label, i) => ({ label, value: t.data[i] })).slice().sort((a, b) => b.value - a.value).slice(0, TOP)
})

// One teal ramp, darkest for #1.
const rankColor = (i) => withAlpha(colors.value.primary, 1 - i * 0.09)
const rankData = computed(() => ({
  labels: ranked.value.map((r) => r.label),
  datasets: [{
    label: view.value === 'services' ? 'Requests' : 'Times borrowed',
    data: ranked.value.map((r) => r.value),
    backgroundColor: ranked.value.map((_r, i) => rankColor(i)),
    borderRadius: 6,
    borderSkipped: false,
    maxBarThickness: 18,
  }],
}))
const rankOptions = computed(() => ({
  ...horizontal.value,
  scales: {
    x: { ...horizontal.value.scales.x, grid: dashedGrid.value, border: { display: false } },
    y: horizontal.value.scales.y,
  },
}))
</script>

<style scoped>
/* The plot area with two faint axis lines, at the chart's own 260px. */
.skel-plot {
  --axis: rgba(var(--v-theme-on-surface), 0.12);
  height: 260px;
  border-radius: 12px;
  background:
    linear-gradient(var(--axis), var(--axis)) 32px calc(100% - 28px) / calc(100% - 48px) 1px no-repeat,
    linear-gradient(var(--axis), var(--axis)) 32px 16px / 1px calc(100% - 44px) no-repeat,
    rgba(var(--v-theme-on-surface), 0.05);
}
.chart-box {
  position: relative;
  height: 260px;
}
.empty {
  height: 260px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  color: var(--dash-muted);
}
</style>
