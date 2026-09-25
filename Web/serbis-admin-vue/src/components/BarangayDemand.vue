<!--
  BarangayDemand.vue

  Where requests come from, in one place: the choropleth map and the ranked
  residents-vs-requests table are the same numbers, so they share one card and
  one date range. The table's counts are the report's own (service requests and
  equipment loans together, see BarangayRequestCounts); the map's hover figures
  are live, from GET /admin/analytics/barangays.
-->
<template>
  <v-row>
    <v-col cols="12" lg="7">
      <div ref="mapEl" class="map-box subtle-surface"></div>
      <div class="text-caption text-medium-emphasis mt-2">
        Darker means more pending requests; grey means none. Hover, tap or tab to a barangay for its figures.
      </div>
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
        &bull; {{ totalRequests.toLocaleString() }} requests and equipment loans in this range
      </div>
    </v-col>
  </v-row>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useTheme } from 'vuetify'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { API_BASE } from '@/config/api'
import { authHeaders } from '@/composables/adminUi'
// Every barangay of Echague. Polygons are matched to tbl_barangay rows by
// properties.psgc_code, never by name (names differ between sources).
import barangayBoundaries from '@/assets/echague-barangays.json'

const props = defineProps({
  barangays: { type: Array, required: true },
  walkIn: { type: Number, default: 0 },
  totalResidents: { type: Number, default: 0 },
  totalRequests: { type: Number, default: 0 },
})

const theme = useTheme()
const colors = computed(() => theme.global.current.value.colors)

const max = computed(() => Math.max(0, ...props.barangays.map((b) => b.requests)))
const ranked = computed(() => props.barangays
  .toSorted((a, b) => b.requests - a.requests || a.name.localeCompare(b.name))
  .map((b) => ({ ...b, percent: max.value > 0 ? Math.round((b.requests / max.value) * 100) : 0 })))

// ---- Map figures: residents and pending requests per barangay ---------------

// { psgc_code: { name, residents_count, pending_requests_count } }
const statByCode = ref({})
const statsFailed = ref(false)
const maxPending = computed(() => Math.max(0, ...Object.values(statByCode.value).map((s) => s.pending_requests_count)))

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

const statFor = (feature) => statByCode.value[feature.properties.psgc_code] ?? {
  name: feature.properties.name, residents_count: 0, pending_requests_count: 0,
}

// Teal ramp by pending requests, against the busiest barangay; none is a light neutral.
const styleFor = (feature) => {
  const pending = statFor(feature).pending_requests_count
  const share = maxPending.value > 0 ? pending / maxPending.value : 0
  return {
    fillColor: pending === 0 ? colors.value['on-surface'] : colors.value.primary,
    fillOpacity: pending === 0 ? 0.08 : 0.25 + 0.6 * share,
    color: colors.value.surface,
    weight: 1,
    opacity: 1,
  }
}
const highlight = (layer) => {
  layer.setStyle({ color: colors.value['primary-strong'], weight: 3 })
  layer.bringToFront()
}

const describe = (feature) => {
  const s = statFor(feature)
  return `${feature.properties.name}. Residents: ${s.residents_count}. Pending requests: ${s.pending_requests_count}.`
}

// Built as DOM nodes, so a name is never parsed as markup.
const tooltipFor = (feature) => {
  const s = statFor(feature)
  const box = document.createElement('div')
  const name = document.createElement('strong')
  name.textContent = feature.properties.name
  box.append(name)
  for (const line of [`Residents: ${s.residents_count}`, `Pending requests: ${s.pending_requests_count}`]) {
    const row = document.createElement('div')
    row.textContent = line
    box.append(row)
  }
  return box
}

const mapEl = ref(null)
let map = null
let geoLayer = null

// Hover, tap and keyboard focus all end in the same highlight and tooltip.
const wire = (feature, layer) => {
  layer.bindTooltip(() => tooltipFor(feature), { sticky: true })
  layer.on({
    mouseover: () => highlight(layer),
    mouseout: () => geoLayer.resetStyle(layer),
    click: (event) => layer.openTooltip(event.latlng),
  })
}

const makeReachable = (layer) => {
  const el = layer.getElement()
  if (!el) return
  el.setAttribute('tabindex', '0')
  el.setAttribute('role', 'img')
  el.setAttribute('aria-label', describe(layer.feature))
  el.addEventListener('focus', () => {
    highlight(layer)
    layer.openTooltip(layer.getBounds().getCenter())
  })
  el.addEventListener('blur', () => {
    geoLayer.resetStyle(layer)
    layer.closeTooltip()
  })
}

onMounted(() => {
  // eslint-disable-next-line unicorn/no-array-callback-reference -- Leaflet, not Array#map
  map = L.map(mapEl.value)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap',
    className: 'map-tiles',
  }).addTo(map)

  geoLayer = L.geoJSON(barangayBoundaries, { style: styleFor, onEachFeature: wire }).addTo(map)
  geoLayer.eachLayer(makeReachable)

  // The whole of Echague, every polygon in view.
  map.fitBounds(geoLayer.getBounds(), { padding: [12, 12] })
  loadStats()
})

// Leaflet is not reactive: repaint when the figures or the theme change.
watch([statByCode, () => theme.global.name.value], () => {
  if (!geoLayer) return
  geoLayer.setStyle(styleFor)
  geoLayer.eachLayer((layer) => layer.getElement()?.setAttribute('aria-label', describe(layer.feature)))
})

onUnmounted(() => {
  map?.remove()
  map = null
  geoLayer = null
})
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
.map-box {
  height: 460px;
  width: 100%;
  border-radius: 8px;
  z-index: 1;
}
/* Leaflet's tooltip is white by default; bring it onto the theme. */
.map-box :deep(.leaflet-tooltip) {
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  border-color: rgba(var(--v-theme-on-surface), 0.2);
  box-shadow: 0 2px 8px rgba(var(--v-shadow-color), 0.25);
}
.map-box :deep(.leaflet-tooltip::before) {
  display: none;
}
.map-box :deep(path:focus-visible) {
  outline: 2px solid rgb(var(--v-theme-primary));
}
.subtle-surface {
  background-color: rgba(var(--v-theme-on-surface), 0.05);
}
/* OSM ships only light tiles; invert the tile layer alone in dark mode so the
   choropleth above it keeps its true colours. */
.v-theme--dark .map-tiles {
  filter: invert(1) hue-rotate(180deg) brightness(0.95) contrast(0.9) saturate(0.8);
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
