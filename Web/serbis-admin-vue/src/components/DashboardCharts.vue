<!--
  DashboardCharts.vue

  The dashboard's chart row: requests filed and resolved per day, and what is
  asked for most. Nothing here fetches; the view hands over what it loaded.
-->
<template>
  <div class="chart-grid">
    <v-card v-if="history" elevation="0" class="dash-card chart-card">
      <div class="chart-head">
        <div>
          <div class="chart-title">Filed &amp; resolved</div>
          <div class="chart-sub">Last 30 days, requests per day</div>
        </div>
        <!-- The legend is HTML, top right (the chart's own is off): a swatch and a word each. -->
        <div class="chart-legend">
          <span><i :style="{ background: flowColors[0] }"></i>Filed</span>
          <span><i :style="{ background: flowColors[1] }"></i>Resolved</span>
        </div>
      </div>
      <div v-if="loading" class="skel skel-plot" aria-hidden="true"></div>
      <div v-else-if="history.length === 0" class="empty content-in">No requests yet</div>
      <div v-else class="chart-box content-in">
        <Line :data="flowData" :options="flowOptions" :plugins="[crosshair]" />
        <ChartDataTable caption="Requests filed and resolved per day, last 30 days — same data as the chart above" category-label="Day" :labels="dayLabels" :series="flowSeries" />
      </div>
    </v-card>

    <v-card elevation="0" class="dash-card chart-card">
      <div class="chart-head">
        <div>
          <div class="chart-title">Most requested</div>
          <div class="chart-sub">{{ view === 'services' ? 'Requests by service' : 'Times borrowed by item' }}, {{ periodNote }}</div>
        </div>
        <div class="chart-filters">
          <SegmentedTabs v-model="view" tonal dense aria-label="Most requested" :items="[{ value: 'services', label: 'Services' }, { value: 'items', label: 'Equipment' }]" />
        </div>
      </div>
      <div v-if="loading" class="skel skel-plot" aria-hidden="true"></div>
      <div v-else-if="ranked.length === 0" class="empty content-in">Nothing {{ periodNote }}</div>
      <!-- A ranked list: each bar is the share of the top entry, the number sits at its end. -->
      <ol v-else :key="view" class="rank-list content-in">
        <li v-for="r in ranked" :key="r.label">
          <span class="rank-name">{{ r.label }}</span>
          <span class="rank-track"><span class="rank-bar" :style="{ width: `${Math.round((r.value / rankMax) * 100)}%` }"></span></span>
          <b class="rank-n">{{ r.value }}</b>
        </li>
      </ol>
    </v-card>
  </div>
</template>

<script setup>
import './dashboard.css'
import { computed, ref } from 'vue'
import { Line } from 'vue-chartjs'
import ChartDataTable from '@/components/ChartDataTable.vue'
import SegmentedTabs from '@/components/SegmentedTabs.vue'
import { countByDay, lastDays } from '@/composables/dashboardTrends'
import { useChartTheme, withAlpha } from '@/composables/useChartTheme'

const props = defineProps({
  // Every service request: { filedAt, resolvedAt } in ms, resolvedAt null while open.
  // null when the account cannot read requests: the chart is left out.
  history: { type: Array, default: null },
  // Ranking per period from /admin/dashboard: { today|week|month|all: { services, items } }, each { labels, data }.
  top: { type: Object, default: null },
  loading: { type: Boolean, default: false },
})

const { colors, base } = useChartTheme()

// ---- Filed & Resolved -----------------------------------------------------

const DAYS = 30
const LABEL_EVERY = 4
const days = lastDays(DAYS)
const dayLabels = days.map((d) => d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }))
const dayTitles = days.map((d) => d.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' }))

const flowSeries = computed(() => [
  { label: 'Filed', data: countByDay((props.history ?? []).map((h) => h.filedAt), days) },
  { label: 'Resolved', data: countByDay((props.history ?? []).map((h) => h.resolvedAt).filter(Boolean), days) },
])
// Filed is neutral load (slate); resolved is the good news, so it takes the brand
// teal. The board's own hexes, so the legend swatches match the lines.
const flowColors = ['#64748b', '#297a67']
// Filed carries a flat light area under its line (the board's .10); Resolved is a line only.
const FILED_AREA = 'rgba(100, 116, 139, 0.10)'
const flowData = computed(() => ({
  labels: dayLabels,
  datasets: flowSeries.value.map((s, i) => ({
    label: s.label,
    data: s.data,
    borderColor: flowColors[i],
    backgroundColor: FILED_AREA,
    borderWidth: 2.2,
    fill: i === 0,
    // Monotone: the curve never dips below zero or overshoots a peak.
    cubicInterpolationMode: 'monotone',
    pointRadius: 0,
    pointHoverRadius: 4,
  })),
}))

// Gridlines are solid hairlines at .08 and axis labels 11px at .6, per the board.
const gridLine = computed(() => ({ color: withAlpha(colors.value['on-surface'], 0.08), lineWidth: 1 }))
const tickStyle = computed(() => ({ color: withAlpha(colors.value['on-surface'], 0.6), font: { size: 11 } }))
const flowOptions = computed(() => ({
  ...base.value,
  plugins: {
    legend: { display: false },
    tooltip: { boxPadding: 4, callbacks: { title: (items) => dayTitles[items[0].dataIndex] } },
  },
  scales: {
    x: {
      grid: { display: false },
      // Every fourth label, counted back from today so today is always named.
      ticks: { ...tickStyle.value, autoSkip: false, maxRotation: 0, callback: (_v, i) => ((DAYS - 1 - i) % LABEL_EVERY === 0 ? dayLabels[i] : '') },
    },
    y: { ...base.value.scales.y, ticks: { ...base.value.scales.y.ticks, ...tickStyle.value }, grid: gridLine.value, border: { display: false } },
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
// The board's one window: the server's rolling 30 Manila days. It also sends
// today, 7 days and all time; Analytics is where other windows are read.
const periodNote = 'last 30 days'
const ranked = computed(() => {
  const t = props.top?.month?.[view.value]
  return (t?.labels ?? []).map((label, i) => ({ label, value: t.data[i] })).slice().sort((a, b) => b.value - a.value).slice(0, TOP)
})

// Each bar is the share of the top entry (the list is sorted, so that is the first).
const rankMax = computed(() => Math.max(1, ...ranked.value.map((r) => r.value)))
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
/* Two equal cards, 24px radius and padding (Dashboard board). */
.chart-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 16px; align-items: stretch; margin-bottom: 8px; }
.chart-card { padding: 24px !important; }
.chart-head { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 12px; }
.chart-title { font-size: 18.72px; line-height: 28px; font-weight: 600; }
.chart-sub { font-size: 14px; line-height: 20px; color: var(--dash-muted); }
.chart-filters { display: flex; flex-wrap: wrap; gap: 8px; }
.chart-legend { display: flex; gap: 16px; font-size: 13px; font-weight: 600; }
.chart-legend span { display: inline-flex; align-items: center; gap: 6px; }
.chart-legend i { display: block; width: 18px; height: 3px; border-radius: 2px; }
.rank-list { list-style: none; margin: 8px 0 0; padding: 0; display: flex; flex-direction: column; gap: 14px; }
.rank-list li { display: grid; grid-template-columns: minmax(120px, 38%) 1fr 32px; align-items: center; gap: 12px; font-size: 13px; }
.rank-name { line-height: 18px; }
.rank-track { display: block; height: 10px; border-radius: 999px; background: rgba(var(--v-theme-on-surface), 0.06); overflow: hidden; }
.rank-bar { display: block; height: 100%; border-radius: 999px; background: #297A67; }
.rank-n { text-align: right; font-variant-numeric: tabular-nums; }
.empty {
  height: 260px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  color: var(--dash-muted);
}
</style>
