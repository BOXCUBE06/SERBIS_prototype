<!--
  BarangayDemand.vue

  Where requests come from, in one place: the choropleth map and the ranked
  residents-vs-requests table are the same numbers, so they share one card and
  one date range. Counts are the report's own (service requests and equipment
  loans together, see BarangayRequestCounts).
-->
<template>
  <v-row>
    <v-col cols="12" lg="7">
      <div ref="mapEl" class="map-box subtle-surface"></div>
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
// properties.name matches tbl_barangay.barangay_name exactly; the choropleth
// joins on it, so the two must stay in step.
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

const countByName = computed(() => Object.fromEntries(props.barangays.map((b) => [b.name, b.requests])))

// Shade by share of the busiest barangay, so the map reads the same in a
// quiet month as in a busy one.
const fillFor = (count) => {
  if (count === 0) return theme.global.name.value === 'dark' ? '#3A4459' : '#e0e0e0'
  const share = count / max.value
  if (share > 0.66) return colors.value.error
  if (share > 0.33) return colors.value.warning
  return colors.value.success
}

const styleFor = (feature) => ({
  fillColor: fillFor(countByName.value[feature.properties.name] ?? 0),
  weight: 2,
  opacity: 1,
  color: 'white',
  dashArray: '3',
  fillOpacity: 0.7,
})

const tipFor = (feature) => {
  const count = countByName.value[feature.properties.name] ?? 0
  return `<b>${feature.properties.name}</b><br>${count} ${count === 1 ? 'request' : 'requests'}`
}

const mapEl = ref(null)
let map = null
let geoLayer = null

onMounted(() => {
  // eslint-disable-next-line unicorn/no-array-callback-reference -- Leaflet, not Array#map
  map = L.map(mapEl.value)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap',
    className: 'map-tiles',
  }).addTo(map)

  geoLayer = L.geoJSON(barangayBoundaries, {
    style: styleFor,
    onEachFeature: (feature, layer) => layer.bindTooltip(tipFor(feature)),
  }).addTo(map)

  map.fitBounds(geoLayer.getBounds(), { padding: [12, 12] })
})

// Leaflet is not reactive: repaint when the counts or the theme change.
watch([countByName, () => theme.global.name.value], () => {
  if (!geoLayer) return
  geoLayer.setStyle(styleFor)
  geoLayer.eachLayer((layer) => layer.setTooltipContent(tipFor(layer.feature)))
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
