<!--
  BarangayDemand.vue

  Where requests come from, in one place: the map and the ranked
  residents-vs-requests table share one card and one date range. The table's
  counts are the report's own (service requests and equipment loans together,
  see BarangayRequestCounts); the map's figures are live, from
  GET /admin/analytics/barangays.

  The map is a plain SVG of Echague, projected once with d3-geo: no basemap, no
  tiles, no pan or zoom. Colours are theme CSS variables, so a theme flip needs
  no repaint code.
-->
<template>
  <v-row>
    <v-col cols="12" lg="7">
      <div ref="wrapEl" class="map-wrap" @click="clear" @keydown.esc="clear">
        <svg
          class="echague-map"
          :viewBox="`0 0 ${W} ${H}`"
          preserveAspectRatio="xMidYMid meet"
          role="group"
          aria-label="Map of Echague barangays, shaded by pending requests"
        >
          <!-- The outer boundary without merging geometry: every polygon's outline
               is drawn 3px wide, then every polygon is covered with its own fill.
               The covers hide the inner half of each line everywhere, so only the
               half facing outside Echague (1.5px) survives. -->
          <g class="outline-stroke"><path v-for="s in shapes" :key="s.code" :d="s.d" /></g>
          <g class="outline-cover"><path v-for="s in shapes" :key="s.code" :d="s.d" /></g>
          <g>
            <path
              v-for="s in shapes"
              :key="s.code"
              :d="s.d"
              class="brgy"
              :style="fillFor(s.code)"
              tabindex="0"
              role="img"
              :aria-label="label(s)"
              @pointerenter="track($event, s)"
              @pointermove="track($event, s)"
              @pointerleave="leave"
              @click.stop="track($event, s)"
              @focus="focused($event, s)"
              @blur="clear"
              @keydown.enter.prevent="focused($event, s, true)"
            />
          </g>
          <!-- Drawn last, so the hovered border is never under a neighbour. -->
          <path v-if="active" :d="active.d" class="brgy-hi" />
        </svg>

        <div v-if="active" ref="tipEl" class="map-tip" :style="pos ? { left: pos.left + 'px', top: pos.top + 'px' } : { visibility: 'hidden' }" role="tooltip">
          <strong>{{ active.name }}</strong>
          <div>Residents: {{ statOf(active.code).residents_count }}</div>
          <div>Pending requests: {{ statOf(active.code).pending_requests_count }}</div>
        </div>
      </div>

      <div class="legend text-caption text-medium-emphasis mt-2">
        <span>Pending requests</span>
        <span class="legend-swatch legend-none"></span><span>0</span>
        <template v-if="maxPending > 0">
          <span class="legend-swatch legend-ramp"></span><span>{{ maxPending }}</span>
        </template>
      </div>
      <div class="text-caption text-medium-emphasis mt-1">Hover, tap or tab to a barangay for its figures.</div>
      <v-alert v-if="statsFailed" type="warning" variant="tonal" density="compact" class="mt-2">
        Could not load the per-barangay figures, so the map shows every barangay at zero.
      </v-alert>
    </v-col>
    <v-col cols="12" lg="5">
      <div class="table-scroll">
        <table class="data-table text-body-2">
          <thead>
            <tr>
              <th class="text-left" scope="col">Barangay</th>
              <th class="text-right" scope="col">Residents</th>
              <th class="text-right" scope="col">Requests</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in ranked" :key="row.name">
              <td>{{ row.name }}</td>
              <td class="text-right" :class="{ 'text-medium-emphasis': row.residents === 0 }">{{ row.residents }}</td>
              <td class="text-right">
                <span :class="{ 'text-medium-emphasis': row.requests === 0 }">{{ row.requests }}</span>
                <div class="bar-track"><div class="bar-fill" :style="{ width: row.percent + '%' }"></div></div>
              </td>
            </tr>
            <tr v-if="walkIn > 0">
              <td class="text-medium-emphasis">Walk-in (no barangay)</td>
              <td class="text-right text-medium-emphasis">—</td>
              <td class="text-right">{{ walkIn }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="text-caption text-medium-emphasis mt-3">
        {{ totalResidents.toLocaleString() }} registered {{ totalResidents === 1 ? 'resident' : 'residents' }}
        &bull; {{ totalRequests.toLocaleString() }} requests + loans in this range
      </div>
    </v-col>
  </v-row>
</template>

<script setup>
import { ref, computed, nextTick, onMounted } from 'vue'
import { buildEchagueMap } from '@/composables/echagueMap'
import { API_BASE } from '@/config/api'
import { authHeaders } from '@/composables/adminUi'
// Every barangay of Echague. Matched to tbl_barangay rows by
// properties.psgc_code, never by name (names differ between sources).
import barangayBoundaries from '@/assets/echague-barangays.json'

const props = defineProps({
  barangays: { type: Array, required: true },
  walkIn: { type: Number, default: 0 },
  totalResidents: { type: Number, default: 0 },
  totalRequests: { type: Number, default: 0 },
})

const max = computed(() => Math.max(0, ...props.barangays.map((b) => b.requests)))
const ranked = computed(() => props.barangays
  .toSorted((a, b) => b.requests - a.requests || a.name.localeCompare(b.name))
  .map((b) => ({ ...b, percent: max.value > 0 ? Math.round((b.requests / max.value) * 100) : 0 })))

// ---- Projection: once, at import; the boundaries never change ------------------

const { W, H, shapes } = buildEchagueMap(barangayBoundaries)

// ---- Figures -----------------------------------------------------------------

// { psgc_code: { residents_count, pending_requests_count } }
const statByCode = ref({})
const statsFailed = ref(false)
const maxPending = computed(() => Math.max(0, ...Object.values(statByCode.value).map((s) => s.pending_requests_count)))
const statOf = (code) => statByCode.value[code] ?? { residents_count: 0, pending_requests_count: 0 }

const loadStats = async () => {
  try {
    const res = await fetch(`${API_BASE}/admin/analytics/barangays`, { headers: authHeaders() })
    if (!res.ok) throw new Error(String(res.status))
    const { data } = await res.json()
    statByCode.value = Object.fromEntries(data.map((s) => [s.psgc_code, s]))
  } catch {
    statsFailed.value = true
  }
}
onMounted(loadStats)

// Teal ramp by pending requests against the busiest barangay; none is a light
// neutral. The legend's gradient uses the same two ends.
const RAMP_MIN = 0.25
const RAMP_MAX = 0.85
const fillFor = (code) => {
  const pending = statOf(code).pending_requests_count
  if (pending === 0) return { fill: 'rgb(var(--v-theme-on-surface))', fillOpacity: 0.08 }
  return { fill: 'rgb(var(--v-theme-primary))', fillOpacity: RAMP_MIN + (RAMP_MAX - RAMP_MIN) * (pending / maxPending.value) }
}

const label = (s) => `${s.name}. Residents: ${statOf(s.code).residents_count}. Pending requests: ${statOf(s.code).pending_requests_count}.`

// ---- Hover, tap and keyboard focus all land here ------------------------------

const GAP = 12
const wrapEl = ref(null)
const tipEl = ref(null)
const active = ref(null)
const pos = ref(null)

// Follows the pointer; flips to the other side of it near the card's edge so
// the tooltip never leaves the card.
const show = async (shape, clientX, clientY) => {
  active.value = shape
  await nextTick()
  const tip = tipEl.value
  if (!tip || !wrapEl.value) return
  const box = wrapEl.value.getBoundingClientRect()
  const x = clientX - box.left
  const y = clientY - box.top
  const left = x + GAP + tip.offsetWidth > box.width ? x - GAP - tip.offsetWidth : x + GAP
  const top = y + GAP + tip.offsetHeight > box.height ? y - GAP - tip.offsetHeight : y + GAP
  pos.value = { left: Math.max(0, left), top: Math.max(0, top) }
}

const clear = () => {
  active.value = null
  pos.value = null
}

const track = (event, shape) => show(shape, event.clientX, event.clientY)
// A finger lifting is not the pointer leaving; a tap elsewhere clears (wrapper click).
const leave = (event) => {
  if (event.pointerType === 'mouse') clear()
}
// Mouse clicks focus the path too; only keyboard focus places the tooltip itself.
const focused = (event, shape, force = false) => {
  if (!force && !event.target.matches(':focus-visible')) return
  const r = event.target.getBoundingClientRect()
  show(shape, r.left + r.width / 2, r.top + r.height / 2)
}
</script>

<style scoped>
.table-scroll {
  overflow: auto;
  max-height: 460px;
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

.map-wrap {
  position: relative;
}
.echague-map {
  display: block;
  width: 100%;
  height: auto;
  max-height: 460px;
  /* The container is not a control; only the barangay paths take focus. */
  outline: none;
}
/* vector-effect keeps every stroke width in screen pixels however the viewBox scales. */
.echague-map path {
  vector-effect: non-scaling-stroke;
  stroke-linejoin: round;
}
.outline-stroke path {
  fill: none;
  stroke: rgba(var(--v-theme-on-surface), 0.7);
  stroke-width: 3;
}
.outline-cover path {
  fill: rgb(var(--v-theme-surface));
  stroke: none;
}
.brgy {
  stroke: rgb(var(--v-theme-surface));
  stroke-width: 0.75;
  cursor: pointer;
  outline: none;
}
.brgy:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
}
.brgy-hi {
  fill: none;
  stroke: rgb(var(--v-theme-primary));
  stroke-width: 2.5;
  pointer-events: none;
}
.map-tip {
  position: absolute;
  z-index: 2;
  pointer-events: none;
  padding: 6px 10px;
  border-radius: 8px;
  font-size: 13px;
  line-height: 1.4;
  white-space: nowrap;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.2);
  box-shadow: 0 2px 8px rgba(var(--v-shadow-color), 0.25);
}

.legend {
  display: flex;
  align-items: center;
  gap: 6px;
}
.legend-swatch {
  width: 28px;
  height: 10px;
  border-radius: 3px;
}
.legend-none {
  margin-left: 8px;
  background: rgba(var(--v-theme-on-surface), 0.08);
  border: 1px solid rgba(var(--v-theme-on-surface), 0.2);
}
.legend-ramp {
  width: 96px;
  background: linear-gradient(90deg, rgba(var(--v-theme-primary), 0.25), rgba(var(--v-theme-primary), 0.85));
}

.bar-track {
  height: 4px;
  margin-top: 2px;
  border-radius: 2px;
  background: rgba(var(--v-theme-on-surface), 0.06);
}
.bar-fill {
  height: 100%;
  border-radius: 2px;
  background: rgb(var(--v-theme-primary));
}
</style>
