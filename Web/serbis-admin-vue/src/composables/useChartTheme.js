// Chart.js draws to canvas and cannot read CSS custom properties, so colours
// come off Vuetify's reactive theme; that is what repaints a chart when the
// theme flips. Same option shapes the Analytics page uses, for the dashboard.
import { computed } from 'vue'
import { useTheme } from 'vuetify'
import {
  Chart as ChartJS, Tooltip, Legend, CategoryScale, LinearScale, BarElement, ArcElement, PointElement, LineElement, Filler,
} from 'chart.js'

ChartJS.register(Tooltip, Legend, CategoryScale, LinearScale, BarElement, ArcElement, PointElement, LineElement, Filler)

// Canvas text does not inherit CSS, so the app font is set here, once, for every chart.
ChartJS.defaults.font.family = "'Plus Jakarta Sans Variable', system-ui, sans-serif"
// Canvas ignores CSS motion rules, so the token ceiling and reduced motion are mirrored here.
ChartJS.defaults.animation.duration = globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 0 : 320

const hexToRgb = (hex) => {
  const n = Number.parseInt(String(hex).replace('#', ''), 16)
  return `${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}`
}

// `hex` is a #rrggbb theme colour; the alpha is what dims a bar that is not selected.
export const withAlpha = (hex, alpha) => `rgba(${hexToRgb(hex)}, ${alpha})`

export function useChartTheme() {
  const theme = useTheme()
  const colors = computed(() => theme.global.current.value.colors)
  const isDark = computed(() => theme.global.current.value.dark)
  const tick = computed(() => `rgba(${hexToRgb(colors.value['on-surface'])}, 0.70)`)
  const grid = computed(() => `rgba(${hexToRgb(colors.value['on-surface'])}, 0.10)`)

  // Two or more series need a legend: identity must not rest on colour alone.
  const legend = computed(() => ({
    display: true,
    position: 'bottom',
    labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', color: tick.value },
  }))

  const axes = computed(() => ({
    x: { grid: { display: false }, ticks: { color: tick.value } },
    y: { beginAtZero: true, ticks: { precision: 0, color: tick.value }, grid: { color: grid.value } },
  }))

  const base = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: { legend: { display: false }, tooltip: { boxPadding: 4 } },
    scales: axes.value,
  }))

  const horizontal = computed(() => ({
    ...base.value,
    indexAxis: 'y',
    scales: {
      x: axes.value.y,
      y: { grid: { display: false }, ticks: { color: tick.value } },
    },
  }))

  const donut = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    cutout: '62%',
    plugins: { legend: legend.value, tooltip: { boxPadding: 4 } },
  }))

  return { colors, isDark, legend, base, horizontal, donut }
}
