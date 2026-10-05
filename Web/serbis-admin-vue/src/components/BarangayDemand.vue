<!--
  BarangayDemand.vue

  Where requests come from, in one place: the map and the ranked
  residents-vs-requests table share one card and one date range. The table's
  counts are the report's own (service requests and equipment loans in separate
  columns, see BarangayRequestCounts), and the map is shaded from the same
  requests, so it follows the range and filters.

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
          aria-label="Map of Echague barangays, shaded by requests in the selected range"
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
          <div>Residents: {{ statOf(active.code).residents ?? '—' }}</div>
          <div>Requests in range: {{ statOf(active.code).requests }}</div>
        </div>
      </div>

      <div class="legend text-caption text-medium-emphasis mt-2">
        <span>Requests in range</span>
        <span class="legend-swatch legend-none"></span><span>0</span>
        <template v-if="maxRequests > 0">
          <span class="legend-swatch legend-ramp"></span><span>{{ maxRequests }}</span>
        </template>
      </div>
      <div class="text-caption text-medium-emphasis mt-1">Hover, tap or tab to a barangay for its figures.</div>
    </v-col>
    <v-col cols="12" lg="5">
      <div class="table-scroll">
        <table class="data-table text-body-2">
          <thead>
            <tr>
              <th class="text-left" scope="col">Barangay</th>
              <th class="text-right" scope="col">Residents</th>
              <th class="text-right" scope="col">Requests</th>
              <th class="text-right" scope="col">Loans</th>
            </tr>
          </thead>
          <tbody>
            <SkeletonRows v-if="loading" :rows="8" :columns="4" />
            <tr v-for="row in ranked" :key="row.name">
              <td>{{ row.name }}</td>
              <td class="text-right" :class="{ 'text-medium-emphasis': !row.residents }">{{ row.residents ?? '—' }}</td>
              <td class="text-right">
                <span :class="{ 'text-medium-emphasis': row.requests === 0 }">{{ row.requests }}</span>
                <div class="bar-track"><div class="bar-fill" :style="{ width: row.percent + '%' }"></div></div>
              </td>
              <td class="text-right" :class="{ 'text-medium-emphasis': row.loans === 0 }">{{ row.loans }}</td>
            </tr>
            <tr v-if="walkIn > 0">
              <td class="text-medium-emphasis">Walk-in (no barangay)</td>
              <td class="text-right text-medium-emphasis">—</td>
              <td class="text-right">{{ walkIn }}</td>
              <td class="text-right text-medium-emphasis">—</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="text-caption text-medium-emphasis mt-3" :style="{ visibility: loading ? 'hidden' : undefined }">
        <template v-if="showResidents">
          {{ totalResidents.toLocaleString() }} registered {{ totalResidents === 1 ? 'resident' : 'residents' }}
          &bull;
        </template>
        {{ totalRequests.toLocaleString() }} {{ totalRequests === 1 ? 'request' : 'requests' }}
        &bull; {{ totalLoans.toLocaleString() }} {{ totalLoans === 1 ? 'loan' : 'loans' }} in this range
      </div>
    </v-col>
  </v-row>
</template>

<script setup>
import { ref, computed, nextTick } from 'vue'
import { buildEchagueMap } from '@/composables/echagueMap'
import SkeletonRows from '@/components/SkeletonRows.vue'
// Every barangay of Echague. Matched to tbl_barangay rows by
// properties.psgc_code, never by name (names differ between sources).
import barangayBoundaries from '@/assets/echague-barangays.json'

const props = defineProps({
  barangays: { type: Array, required: true },
  walkIn: { type: Number, default: 0 },
  totalResidents: { type: Number, default: 0 },
  totalRequests: { type: Number, default: 0 },
  totalLoans: { type: Number, default: 0 },
  // The map needs no placeholder: the boundaries are bundled, so it draws as a
  // neutral silhouette until the figures land and the fills fade in.
  loading: { type: Boolean, default: false },
})

const max = computed(() => Math.max(0, ...props.barangays.map((b) => b.requests)))
const ranked = computed(() => props.barangays
  .slice()
  .sort((a, b) => b.requests - a.requests || a.name.localeCompare(b.name))
  .map((b) => ({ ...b, percent: max.value > 0 ? Math.round((b.requests / max.value) * 100) : 0 })))

// ---- Projection: once, at import; the boundaries never change ------------------

const { W, H, shapes } = buildEchagueMap(barangayBoundaries)

// ---- Figures -----------------------------------------------------------------

// { psgc_code: { residents, requests } }, from the report's own rows.
// residents is null for a barangay the filter leaves out.
const statByCode = computed(() => Object.fromEntries(props.barangays.map((b) => [b.psgc_code, b])))
const maxRequests = max
const statOf = (code) => statByCode.value[code] ?? { residents: null, requests: 0 }
// With every resident figure null (walk-in only) there is no resident total to print.
const showResidents = computed(() => props.barangays.some((b) => b.residents !== null))

// Teal ramp by requests in range against the busiest barangay; none is a light
// neutral. The legend's gradient uses the same two ends.
const RAMP_MIN = 0.25
const RAMP_MAX = 0.85
const fillFor = (code) => {
  const requests = statOf(code).requests
  if (requests === 0) return { fill: 'rgb(var(--v-theme-on-surface))', fillOpacity: 0.06 }
  return { fill: 'rgb(var(--v-theme-primary))', fillOpacity: RAMP_MIN + (RAMP_MAX - RAMP_MIN) * (requests / maxRequests.value) }
}

const label = (s) => `${s.name}. Residents: ${statOf(s.code).residents ?? 'not selected'}. Requests in range: ${statOf(s.code).requests}.`

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
  /* Half of it is covered by the fills, so 3.5 leaves 1.75px outside Echague. */
  stroke-width: 3.5;
}
.outline-cover path {
  fill: rgb(var(--v-theme-surface));
  stroke: none;
}
.brgy {
  /* Same border whatever the fill, so barangays stay distinct at zero and at max. */
  stroke: rgba(var(--v-theme-on-surface), 0.35);
  stroke-width: 1;
  cursor: pointer;
  outline: none;
  transition: fill-opacity var(--motion-base) var(--ease-out);
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
  background: rgba(var(--v-theme-on-surface), 0.06);
  border: 1px solid rgba(var(--v-theme-on-surface), 0.35);
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
