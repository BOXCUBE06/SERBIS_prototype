<!--
  AmbulanceScheduleDialog.vue

  The "Day view" popup on Ambulance Dispatch: a 24-hour timeline per ambulance
  unit, or the whole month at a glance. Both draw from one read of
  GET /ambulance-schedule for the visible grid, so moving between days of a month
  costs nothing and a month costs one request. Where things sit and what the
  notes say is in composables/ambulanceSchedule.js.
-->
<template>
  <v-dialog
    :model-value="modelValue"
    width="1200"
    max-width="calc(100vw - 32px)"
    @update:model-value="emit('update:modelValue', $event)"
    @after-leave="returnFocus"
  >
    <div class="sched" role="dialog" aria-labelledby="sched-title" :style="{ '--busy': pillAccent('Responding') }">
      <header class="sched-head">
        <div>
          <h2 id="sched-title" class="sched-title">Ambulance schedule</h2>
          <div class="sched-sub">{{ loaded ? `${plural(data.units.length, 'unit')} · ` : '' }}trips by time of day, or the whole month at a glance</div>
        </div>
        <div class="sched-head-actions">
          <v-btn-toggle v-model="view" mandatory density="comfortable" color="primary" variant="outlined" divided aria-label="Schedule view">
            <v-btn value="day" class="text-none font-weight-bold" prepend-icon="mdi-format-align-left">Day</v-btn>
            <v-btn value="month" class="text-none font-weight-bold" prepend-icon="mdi-calendar-month-outline">Month</v-btn>
          </v-btn-toggle>
          <v-btn icon="mdi-close" variant="tonal" rounded="circle" size="small" aria-label="Close schedule" @click="emit('update:modelValue', false)"></v-btn>
        </div>
      </header>

      <div class="sched-bar">
        <div class="sched-nav">
          <v-btn icon="mdi-chevron-left" variant="outlined" size="small" :aria-label="isDay ? 'Previous day' : 'Previous month'" @click="step(-1)"></v-btn>
          <v-btn icon="mdi-chevron-right" variant="outlined" size="small" :aria-label="isDay ? 'Next day' : 'Next month'" @click="step(1)"></v-btn>
          <v-btn variant="outlined" color="primary" class="text-none font-weight-bold" @click="goToday">Today</v-btn>
          <div>
            <div class="sched-range">{{ rangeLabel }}</div>
            <div class="sched-summary">{{ summary }}</div>
          </div>
        </div>
        <ul class="sched-legend" aria-label="Status colours">
          <li v-for="status in STATUSES" :key="status"><span class="dot" :style="{ background: pillAccent(status) }"></span>{{ status }}</li>
        </ul>
        <v-progress-linear v-if="loading" indeterminate color="primary" height="2" class="sched-loading"></v-progress-linear>
      </div>

      <v-alert v-if="failed" type="error" variant="tonal" density="compact" class="ma-4 mb-0">
        The schedule could not be loaded.
        <template #append><v-btn variant="text" size="small" class="text-none" @click="load">Try again</v-btn></template>
      </v-alert>

      <div v-if="!loaded" class="sched-wait" role="status">{{ failed ? '' : 'Loading the schedule…' }}</div>

      <!-- Day -->
      <div v-else-if="isDay" class="sched-day">
        <div class="sched-scroll">
          <div class="tl">
            <div class="tl-hours">
              <div class="tl-label"></div>
              <div class="tl-axis">
                <span v-for="hour in HOURS" :key="hour.left" class="tl-hour" :style="{ left: hour.left }">{{ hour.label }}</span>
              </div>
            </div>

            <div v-for="row in rows" :key="row.key" class="tl-row" :style="{ height: row.height }">
              <div class="tl-label">
                <div class="tl-name"><b>{{ row.name }}</b><span v-if="row.type">{{ row.type }}</span></div>
                <div class="tl-note" :class="`tone-${row.note.tone}`"><span class="dot"></span>{{ row.note.text }}</div>
              </div>
              <div class="tl-track" :class="{ 'tl-track--off': row.off, 'tl-track--unassigned': row.unassigned }">
                <!-- Still waiting from earlier days: one chip at the left edge, not a block each. -->
                <button
                  v-if="row.carried?.length"
                  type="button"
                  class="carry"
                  :class="{ 'is-picked': picked === row.carried[0].request_id }"
                  :style="{ '--acc': pillAccent('Pending') }"
                  :title="row.carried.map((trip) => `${transactionNo(trip.request_id)} · ${trip.patient_name || 'Unknown patient'} · ${waitingLabel(trip, todayKey)}`).join('\n')"
                  :aria-pressed="picked === row.carried[0].request_id"
                  @click="pickInTable(row.carried[0].request_id)"
                >{{ row.carried.length }} from earlier days</button>
                <button
                  v-for="item in row.placed"
                  :key="item.trip.request_id"
                  type="button"
                  class="blk"
                  :class="[`blk--${item.trip.status}`, { 'is-picked': picked === item.trip.request_id }]"
                  :style="blockStyle(item)"
                  :title="tripTitle(item.trip)"
                  :aria-label="tripTitle(item.trip)"
                  :aria-pressed="picked === item.trip.request_id"
                  @click="pick(item.trip.request_id)"
                >
                  <span class="blk-time">{{ shortTime(item.trip.startMs) }}–{{ shortTime(item.trip.endMs) }}</span>
                  <span class="blk-name">{{ shortName(item.trip.patient_name) }}</span>
                </button>
              </div>
            </div>

            <template v-if="isToday">
              <span class="now-pill" :style="{ left: nowLeft }">Now · {{ fmtTime(nowMs) }}</span>
              <span class="now-line" :style="{ left: nowLeft }"></span>
            </template>
          </div>
        </div>

        <section class="agenda" aria-label="Trips on this day">
          <div class="agenda-head">
            <h3>Trips on this day</h3>
            <span v-if="dayTrips.length">Select a trip to highlight it on the timeline</span>
          </div>
          <div v-if="dayTrips.length === 0" class="agenda-empty">
            <div class="agenda-empty-title">No trips scheduled</div>
            <div>All {{ data.units.length }} units are free on this day.</div>
          </div>
          <template v-else>
            <div class="agenda-cols agenda-cols--head">
              <span>Time</span><span>Txn no.</span><span>Patient</span><span>Requested by</span><span>From</span><span>Unit</span><span>Status</span>
            </div>
            <div ref="agendaBody" class="agenda-body">
              <button
                v-for="trip in dayTrips"
                :key="trip.request_id"
                type="button"
                class="agenda-cols agenda-row"
                :class="{ 'is-picked': picked === trip.request_id }"
                :data-trip="trip.request_id"
                :aria-label="rowLabel(trip)"
                :aria-pressed="picked === trip.request_id"
                @click="pick(trip.request_id)"
              >
                <span v-if="isCarried(trip, todayKey)" class="strong tone-pending-text">{{ waitingLabel(trip, todayKey) }}</span>
                <span v-else class="strong">{{ fmtTime(trip.startMs) }} – {{ fmtTime(trip.endMs) }}</span>
                <span class="mono">{{ transactionNo(trip.request_id) }}</span>
                <span class="strong cut">{{ trip.patient_name || 'Unknown' }}</span>
                <span class="cut">{{ trip.requested_by || 'Unknown requester' }}</span>
                <span class="cut muted">{{ trip.from || '—' }}</span>
                <span :class="{ 'tone-pending-text': !trip.vehicle_id }">{{ unitName(trip) }}</span>
                <span><StatusPill :status="trip.status" small /></span>
              </button>
            </div>
          </template>
        </section>
      </div>

      <!-- Month -->
      <div v-else class="sched-month">
        <div class="sched-scroll sched-scroll--fill">
          <div class="mo">
            <div class="mo-days" aria-hidden="true"><span v-for="day in WEEKDAYS" :key="day">{{ day }}</span></div>
            <div class="mo-grid" :style="{ gridTemplateRows: `repeat(${weeks}, minmax(0, 1fr))` }">
              <button
                v-for="cell in cells"
                :key="cell.key"
                type="button"
                class="mo-cell"
                :class="{ 'is-out': !cell.inMonth, 'is-cursor': cell.key === cursor }"
                :aria-label="cellLabel(cell)"
                @click="openDay(cell.key)"
              >
                <span class="mo-top">
                  <span class="mo-num" :class="{ 'is-today': cell.isToday }">{{ cell.date }}</span>
                  <span class="mo-fill"></span>
                  <span class="mo-bars" :title="`${cell.bars.filter(Boolean).length} of ${data.units.length} units have trips`">
                    <span v-for="(on, i) in cell.bars" :key="i" class="bar" :class="{ 'is-on': on }"></span>
                  </span>
                  <span v-if="cell.trips.length" class="mo-count">{{ plural(cell.trips.length, 'trip') }}</span>
                </span>
                <span v-for="chip in cell.chips" :key="chip.trip.request_id" class="chip" :class="`chip--${chip.trip.status}`" :style="{ '--acc': pillAccent(chip.trip.status) }">
                  <span class="dot"></span>
                  <b>{{ chip.time }}</b>
                  <span class="chip-name">{{ chip.name }}</span>
                  <span class="mono chip-unit">{{ chip.unit }}</span>
                </span>
                <span v-if="cell.more" class="mo-more">+{{ cell.more }} more</span>
              </button>
            </div>
          </div>
        </div>
        <div class="mo-foot">
          <span class="mo-key">
            <span class="mo-bars"><span class="bar is-on"></span><span class="bar is-on"></span><span class="bar"></span><span class="bar"></span></span>
            Units with trips that day: filled is booked, empty is free. Chips show time, patient and unit.
          </span>
          <span>Select a date to open its day view</span>
        </div>
      </div>
    </div>
  </v-dialog>
</template>

<script setup>
import { ref, computed, watch, nextTick, onUnmounted } from 'vue'
import { API_BASE } from '@/config/api'
import { authHeaders } from '@/composables/adminUi'
import { pillAccent } from '@/composables/statusPill'
import { transactionNo } from '@/composables/requestDisplay'
import StatusPill from '@/components/StatusPill.vue'
import {
  dateKey, addDays, addMonths, monthGrid, fetchRange, toTrips, tripsOnDay, layoutRow, fmtTime, shortTime, shortName,
  plural, unitNote, unassignedNote, daySummary, monthCells, monthSummary, parseKey, isCarried, waitingLabel,
} from '@/composables/ambulanceSchedule'

const props = defineProps({ modelValue: { type: Boolean, default: false } })
const emit = defineEmits(['update:modelValue'])

const STATUSES = ['Pending', 'Booked', 'Responding', 'Resolved']
const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
// A label every two hours, to the right of its tick.
const HOURS = Array.from({ length: 12 }, (_, i) => {
  const hour = i * 2
  return { left: `${(i * 100) / 12}%`, label: `${hour % 12 || 12} ${hour < 12 ? 'AM' : 'PM'}` }
})
const LABEL_WIDTH = 180
const BLOCK = 44

const view = ref('day')
const cursor = ref(dateKey(new Date()))
const picked = ref(null)
const data = ref({ units: [], trips: [] })
const loading = ref(false)
const failed = ref(false)
// False until the first answer, so an empty day is never drawn before there is one.
const loaded = ref(false)

const isDay = computed(() => view.value === 'day')

// The clock behind "today" and the Now line, kept while the popup is open.
const nowMs = ref(Date.now())
let ticker = null
const stopClock = () => { clearInterval(ticker); ticker = null }
const startClock = () => {
  stopClock()
  nowMs.value = Date.now()
  ticker = setInterval(() => { nowMs.value = Date.now() }, 60_000)
}
onUnmounted(stopClock)

const todayKey = computed(() => dateKey(new Date(nowMs.value)))
const isToday = computed(() => cursor.value === todayKey.value)

let loadToken = 0
const load = async () => {
  const mine = ++loadToken
  const { from, to } = fetchRange(cursor.value)
  loading.value = true
  failed.value = false

  try {
    const res = await fetch(`${API_BASE}/ambulance-schedule?from=${from}&to=${to}`, { headers: authHeaders() })
    if (!res.ok) {throw new Error(`HTTP ${res.status}`)}
    const body = await res.json()
    if (mine === loadToken) {
      data.value = { units: body.units, trips: toTrips(body.trips) }
      loaded.value = true
    }
  } catch {
    if (mine === loadToken) {failed.value = true}
  } finally {
    if (mine === loadToken) {loading.value = false}
  }
}

watch(() => props.modelValue, (open) => {
  if (!open) {
    stopClock()
    return
  }
  opener = document.activeElement
  startClock()
  view.value = 'day'
  loaded.value = false
  cursor.value = todayKey.value
  picked.value = null
  load()
})

// The grid, so a month is one request and the days inside it are free.
watch(() => fetchRange(cursor.value).from, () => { if (props.modelValue) {load()} })

const step = (n) => {
  cursor.value = isDay.value ? addDays(cursor.value, n) : addMonths(cursor.value, n)
  picked.value = null
}
const goToday = () => {
  cursor.value = todayKey.value
  picked.value = null
}
const openDay = (key) => {
  cursor.value = key
  view.value = 'day'
  picked.value = null
}
const pick = (id) => { picked.value = picked.value === id ? null : id }

const rangeLabel = computed(() => (isDay.value
  ? parseKey(cursor.value).toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })
  : parseKey(cursor.value).toLocaleDateString('en-US', { month: 'long', year: 'numeric' })))

// Day
const dayTrips = computed(() => tripsOnDay(data.value.trips, cursor.value, todayKey.value))

const rows = computed(() => {
  const unitRows = data.value.units.map((unit) => {
    const mine = dayTrips.value.filter((trip) => trip.vehicle_id === unit.vehicle_id)
    const { placed, laneCount } = layoutRow(mine, cursor.value)

    return {
      key: unit.vehicle_id,
      name: unit.unit_identifier,
      type: unit.specification,
      note: unitNote(unit, mine, isToday.value, nowMs.value, data.value.trips),
      off: unit.is_maintenance,
      placed,
      height: rowHeight(laneCount),
    }
  })

  // Waiting since an earlier day: the one chip, which takes the first lane to itself.
  const waiting = dayTrips.value.filter((trip) => !trip.vehicle_id)
  const carried = waiting.filter((trip) => isCarried(trip, todayKey.value))
  const shift = carried.length > 0 ? 1 : 0
  const { placed, laneCount } = layoutRow(waiting.filter((trip) => !carried.includes(trip)), cursor.value)

  return [...unitRows, {
    key: 'unassigned',
    name: 'Unassigned',
    type: '',
    note: unassignedNote(waiting),
    unassigned: true,
    carried,
    placed: placed.map((item) => ({ ...item, lane: item.lane + shift })),
    height: rowHeight(placed.length > 0 ? laneCount + shift : Math.max(1, shift)),
  }]
})

// The carried chip: pick the first of them and bring its row into view.
const agendaBody = ref(null)
const pickInTable = async (id) => {
  pick(id)
  await nextTick()
  agendaBody.value?.querySelector(`[data-trip="${CSS.escape(String(id))}"]`)?.scrollIntoView({ block: 'nearest' })
}

// Opened from a button, so hand focus back to it on close (the dialog has no activator to do it).
let opener = null
const returnFocus = () => {
  opener?.focus?.()
  opener = null
}

const rowHeight = (lanes) => `${Math.max(60, lanes * (BLOCK + 6) + 10)}px`

const blockStyle = ({ lane, leftPct, widthPct, trip }) => ({
  left: `calc(${leftPct}% + 1px)`,
  width: `calc(${widthPct}% - 2px)`,
  top: `${5 + lane * (BLOCK + 6)}px`,
  height: `${BLOCK}px`,
  '--acc': pillAccent(trip.status),
})

const nowLeft = computed(() => {
  const midnight = parseKey(cursor.value).getTime()

  return `calc(${LABEL_WIDTH}px + (100% - ${LABEL_WIDTH}px) * ${((nowMs.value - midnight) / 86_400_000).toFixed(4)})`
})

const unitName = (trip) => data.value.units.find((unit) => unit.vehicle_id === trip.vehicle_id)?.unit_identifier || 'Unassigned'

const tripTitle = (trip) => [
  transactionNo(trip.request_id),
  trip.patient_name || 'Unknown patient',
  `${fmtTime(trip.startMs)} – ${fmtTime(trip.endMs)}${trip.end_is_default ? ' (end estimated)' : ''}`,
  trip.status,
  trip.requested_by ? `Requested by ${trip.requested_by}` : null,
  trip.from ? `From ${trip.from}` : null,
].filter(Boolean).join(' · ')

// The row's cells read as one sentence; without it a screen reader runs them together.
const rowLabel = (trip) => [
  isCarried(trip, todayKey.value) ? waitingLabel(trip, todayKey.value) : `${fmtTime(trip.startMs)} to ${fmtTime(trip.endMs)}`,
  transactionNo(trip.request_id),
  trip.patient_name || 'Unknown patient',
  trip.requested_by ? `requested by ${trip.requested_by}` : null,
  trip.from ? `from ${trip.from}` : null,
  unitName(trip),
  trip.status,
].filter(Boolean).join(', ')

// Month
const weeks = computed(() => monthGrid(cursor.value).weeks)
const cells = computed(() => monthCells(data.value.trips, data.value.units, cursor.value, todayKey.value))
const cellLabel = (cell) => `${parseKey(cell.key).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}, ${cell.trips.length ? plural(cell.trips.length, 'trip') : 'no trips'}`

const summary = computed(() => (isDay.value
  ? daySummary(dayTrips.value, data.value.units, isToday.value, nowMs.value, data.value.trips)
  : monthSummary(cells.value)))
</script>

<style scoped>
.sched {
  --line: rgba(var(--v-border-color), var(--v-border-opacity));
  --muted: rgba(var(--v-theme-on-surface), 0.66);
  --soft: rgba(var(--v-theme-on-surface), 0.04);
  --soft-solid: color-mix(in srgb, rgb(var(--v-theme-on-surface)) 4%, rgb(var(--v-theme-surface)));
  display: flex;
  flex-direction: column;
  height: min(800px, calc(100dvh - 32px));
  border-radius: 16px;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  overflow: hidden;
}
.sched :is(button, [role='button']):focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.sched-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 20px 28px 16px; }
.sched-title { margin: 0; font-size: 1.375rem; line-height: 1.75rem; font-weight: 700; letter-spacing: -0.01em; }
.sched-sub { font-size: 0.8125rem; color: var(--muted); }
.sched-head-actions { display: flex; align-items: center; gap: 12px; }

.sched-bar { position: relative; display: flex; align-items: center; justify-content: space-between; gap: 12px 16px; flex-wrap: wrap; padding: 10px 28px 12px; border-block: 1px solid var(--line); background: var(--soft); }
.sched-nav { display: flex; align-items: center; gap: 8px 12px; flex-wrap: wrap; }
.sched-range { font-size: 1.125rem; line-height: 1.5rem; font-weight: 700; letter-spacing: -0.01em; }
.sched-summary { font-size: 0.78rem; color: var(--muted); }
.sched-loading { position: absolute; inset: auto 0 -1px; }
.sched-legend { display: flex; align-items: center; gap: 8px 16px; flex-wrap: wrap; margin: 0; padding: 0; list-style: none; font-size: 0.78rem; }
.sched-legend li { display: flex; align-items: center; gap: 6px; }
.dot { flex: none; width: 8px; height: 8px; border-radius: 50%; background: currentColor; }

.sched-wait { flex: 1; display: flex; align-items: center; justify-content: center; color: var(--muted); font-size: 0.875rem; }
.sched-day { flex: 1; min-height: 0; display: flex; flex-direction: column; gap: 14px; padding: 12px 28px 22px; }
.sched-scroll { flex: 0 1 auto; min-height: 90px; overflow: auto; }
.sched-scroll--fill { flex: 1; min-height: 0; }

/* Timeline */
.tl { position: relative; min-width: 920px; }
.tl-hours { display: flex; height: 46px; }
.tl-label { flex: none; width: 180px; }
.tl-axis { position: relative; flex: 1; }
.tl-hour { position: absolute; top: 26px; padding-left: 5px; font-size: 0.72rem; font-weight: 500; line-height: 1rem; color: var(--muted); white-space: nowrap; }
.tl-row { display: flex; align-items: stretch; border-top: 1px solid var(--line); }
.tl-row > .tl-label { display: flex; flex-direction: column; justify-content: center; gap: 2px; }
.tl-name { display: flex; align-items: baseline; gap: 8px; font-size: 0.9rem; }
.tl-name span { font-size: 0.75rem; color: var(--muted); }
.tl-note { display: flex; align-items: center; gap: 6px; font-size: 0.75rem; font-weight: 500; color: var(--muted); }
.tl-note.tone-free { color: rgb(var(--v-theme-success)); }
.tl-note.tone-busy { color: color-mix(in srgb, var(--busy) 50%, rgb(var(--v-theme-on-surface))); }
.tl-note.tone-pending { color: color-mix(in srgb, #b45309 50%, rgb(var(--v-theme-on-surface))); }
.tl-track {
  position: relative;
  flex: 1;
  margin: 6px 0;
  border-radius: 8px;
  background-color: var(--soft);
  background-image: repeating-linear-gradient(to right, var(--line) 0, var(--line) 1px, transparent 1px, transparent 8.3333%);
}
.tl-track--off { background-image: repeating-linear-gradient(135deg, var(--soft), var(--soft) 8px, rgba(var(--v-theme-on-surface), 0.08) 8px, rgba(var(--v-theme-on-surface), 0.08) 16px); }
.tl-track--unassigned { background-color: color-mix(in srgb, #f59e0b 7%, rgb(var(--v-theme-surface))); }

/* Status looks, from the one accent table every status pill uses (composables/statusPill.ts). */
.blk,
.chip {
  --tint: color-mix(in srgb, var(--acc) 14%, rgb(var(--v-theme-surface)));
  --ink: color-mix(in srgb, var(--acc) 50%, rgb(var(--v-theme-on-surface)));
  background: var(--tint);
  color: var(--ink);
}
.blk {
  position: absolute;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 1px;
  padding: 0 7px;
  border: 1px solid color-mix(in srgb, var(--acc) 40%, transparent);
  border-radius: 6px;
  font: inherit;
  text-align: left;
  overflow: hidden;
  cursor: pointer;
}
.blk--Pending { border: 1px dashed var(--acc); }
.blk--Responding { background: var(--acc); border-color: var(--acc); color: #fff; }
.blk.is-picked { outline: 2px solid rgb(var(--v-theme-on-surface)); outline-offset: 1px; }
.carry {
  position: absolute;
  top: 5px;
  left: 4px;
  height: 44px;
  padding: 0 10px;
  border: 1px dashed var(--acc);
  border-radius: 6px;
  background: color-mix(in srgb, var(--acc) 14%, rgb(var(--v-theme-surface)));
  color: color-mix(in srgb, var(--acc) 50%, rgb(var(--v-theme-on-surface)));
  font: inherit;
  font-size: 0.75rem;
  font-weight: 700;
  white-space: nowrap;
  cursor: pointer;
}
.carry.is-picked { outline: 2px solid rgb(var(--v-theme-on-surface)); outline-offset: 1px; }
/* Inside clipped containers, so the focus ring is drawn inside the edge. */
.sched .mo-cell:focus-visible,
.sched .agenda-row:focus-visible { outline-offset: -3px; }
.blk-time { font-size: 0.656rem; line-height: 0.8rem; font-weight: 500; white-space: nowrap; }
.blk-name { font-size: 0.75rem; line-height: 0.94rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.now-pill { position: absolute; top: 0; transform: translateX(-50%); height: 20px; padding: 0 8px; border-radius: 10px; background: #C2372B; color: #FFFFFF; font-size: 0.72rem; line-height: 20px; font-weight: 600; white-space: nowrap; pointer-events: none; }
.now-line { position: absolute; top: 20px; bottom: 0; width: 2px; margin-left: -1px; background: #C2372B; pointer-events: none; }

/* Trips on this day */
.agenda { flex: 1; min-height: 160px; display: flex; flex-direction: column; border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
.agenda-head { display: flex; align-items: baseline; gap: 10px; padding: 10px 14px 8px; }
.agenda-head h3 { margin: 0; font-size: 0.875rem; font-weight: 700; }
.agenda-head span { font-size: 0.78rem; color: var(--muted); }
.agenda-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; border-top: 1px solid var(--line); background: var(--soft); font-size: 0.8125rem; color: var(--muted); }
.agenda-empty-title { font-size: 0.94rem; font-weight: 700; color: rgb(var(--v-theme-on-surface)); }
.agenda-body { flex: 1; min-height: 0; overflow-y: auto; display: flex; flex-direction: column; }
.agenda-cols { display: grid; grid-template-columns: 170px 118px minmax(120px, 1.15fr) minmax(120px, 1.15fr) minmax(100px, 1.1fr) 84px 112px; column-gap: 12px; align-items: center; padding: 0 14px; min-width: 900px; }
.agenda-cols--head { padding-block: 6px; border-block: 1px solid var(--line); background: var(--soft); font-size: 0.69rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--muted); }
.agenda-row { flex: none; height: 38px; border: 0; border-bottom: 1px solid var(--line); background: transparent; color: inherit; font: inherit; font-size: 0.8125rem; text-align: left; cursor: pointer; }
.agenda-row:hover { background: var(--soft); }
.agenda-row.is-picked { background: rgba(var(--v-theme-primary), 0.1); }
.strong { font-weight: 600; white-space: nowrap; }
.cut { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.muted { color: var(--muted); }
.tone-pending-text { color: color-mix(in srgb, #b45309 50%, rgb(var(--v-theme-on-surface))); }
.mono { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 0.75rem; font-weight: 500; }
.agenda { overflow-x: auto; }

/* Month */
.sched-month { flex: 1; min-height: 0; display: flex; flex-direction: column; padding: 12px 28px 14px; }
.mo { min-width: 840px; height: 100%; display: flex; flex-direction: column; }
.mo-days { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); padding: 0 1px 6px; font-size: 0.69rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--muted); }
.mo-days span { padding-left: 9px; }
.mo-grid { flex: 1; min-height: 0; display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 1px; border: 1px solid var(--line); border-radius: 10px; background: var(--line); overflow: hidden; }
.mo-cell { min-width: 0; min-height: 0; display: flex; flex-direction: column; gap: 2px; padding: 6px 8px; border: 0; background: rgb(var(--v-theme-surface)); color: inherit; font: inherit; text-align: left; overflow: hidden; cursor: pointer; }
.mo-cell:hover { background: color-mix(in srgb, rgb(var(--v-theme-primary)) 5%, rgb(var(--v-theme-surface))); }
.mo-cell.is-out { background: var(--soft-solid); }
.mo-cell.is-out .mo-num { color: var(--muted); }
.mo-cell.is-cursor { box-shadow: inset 0 0 0 2px rgb(var(--v-theme-primary)); }
.mo-top { display: flex; align-items: center; gap: 6px; height: 22px; margin-bottom: 2px; }
.mo-fill { flex: 1; }
.mo-num { display: flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; border-radius: 11px; font-size: 0.8125rem; font-weight: 700; }
.mo-num.is-today { background: rgb(var(--v-theme-primary)); color: rgb(var(--v-theme-on-primary)); }
.mo-count { font-size: 0.69rem; font-weight: 700; white-space: nowrap; color: var(--muted); }
.mo-bars { display: flex; gap: 2px; }
.bar { box-sizing: border-box; width: 6px; height: 12px; border-radius: 2px; border: 1px solid rgba(var(--v-theme-on-surface), 0.4); background: transparent; }
.bar.is-on { background: rgb(var(--v-theme-primary)); border-color: rgb(var(--v-theme-primary)); }
.chip { flex: none; display: flex; align-items: center; gap: 5px; height: 18px; padding: 0 5px; border-radius: 4px; font-size: 0.69rem; line-height: 18px; }
.chip .dot { width: 6px; height: 6px; background: var(--acc); }
.chip-name { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chip-unit { flex: none; font-size: 0.625rem; }
.chip--Responding { background: color-mix(in srgb, var(--acc) 20%, rgb(var(--v-theme-surface))); }
.mo-more { padding-left: 5px; font-size: 0.69rem; font-weight: 600; color: rgb(var(--v-theme-primary)); }
.mo-foot { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding-top: 10px; font-size: 0.78rem; color: var(--muted); }
.mo-key { display: flex; align-items: center; gap: 8px; }
</style>
