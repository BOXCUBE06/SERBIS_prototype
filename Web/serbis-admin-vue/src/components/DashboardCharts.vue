<!--
  DashboardCharts.vue

  The dashboard's chart row: requests filed and resolved per day, and what is
  asked for most. Nothing here fetches; the view hands over what it loaded.
-->
<template>
  <v-row>
    <v-col cols="12" lg="8">
      <v-card elevation="0" class="dash-card h-100">
        <div class="dash-card-title">Filed &amp; Resolved</div>
        <div class="dash-card-subtitle mb-3">Last 30 days</div>
        <v-skeleton-loader v-if="loading" type="image" height="260"></v-skeleton-loader>
        <div v-else-if="history.length === 0" class="empty">No requests yet</div>
        <div v-else class="chart-box">
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
          <v-btn-toggle v-model="view" mandatory density="compact" variant="outlined" divided color="primary" aria-label="Most requested">
            <v-btn value="services" size="small" class="text-none">Services</v-btn>
            <v-btn value="items" size="small" class="text-none">Equipment</v-btn>
          </v-btn-toggle>
        </div>
        <v-skeleton-loader v-if="loading" type="image" height="260"></v-skeleton-loader>
        <div v-else-if="ranked.length === 0" class="empty">Nothing in the last 30 days</div>
        <div v-else class="chart-box">
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

const { colors, isDark, legend, base, horizontal } = useChartTheme()

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
const flowColors = computed(() => [isDark.value ? '#94A3B8' : '#64748B', colors.value.primary])
const flowData = computed(() => ({
  labels: dayLabels,
  datasets: flowSeries.value.map((s, i) => ({
    label: s.label,
    data: s.data,
    borderColor: flowColors.value[i],
    backgroundColor: withAlpha(flowColors.value[i], 0.14),
    borderWidth: 2,
    fill: true,
    tension: 0.4,
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
  return (t?.labels ?? []).map((label, i) => ({ label, value: t.data[i] })).toSorted((a, b) => b.value - a.value).slice(0, TOP)
})

// #1 stands out, 2-4 are worth a look, the rest are background.
const rankColor = (i) => {
  if (i === 0) return colors.value.error
  return i <= 3 ? colors.value.warning : colors.value.primary
}
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
