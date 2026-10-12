<!--
  BlastHistory.vue

  Recent blasts on the Text blast page: one compact row per send, a filter, and
  a detail panel for the one selected. A send to many barangays is one row; the
  barangays and the residents are opened from the panel, so the page never
  fills with a line per resident.

  No auto-refresh: SkySMS's rate limit is shared with every send, and a stuck
  message will not resolve faster for being polled. Check status and Refresh
  all are the only calls to the vendor.
-->
<template>
  <v-card elevation="0" rounded="xl" class="bh-card fade-in">
    <div class="bh-pad bh-head">
      <div class="min-w-0">
        <h2 class="bh-title">Recent blasts</h2>
        <p class="bh-sub">
          Queued means SkySMS accepted it and billed the credits. A message is delivered only when it shows Sent.
        </p>
      </div>
      <v-btn
        variant="flat" height="40" class="outline-btn text-none ml-auto flex-shrink-0"
        prepend-icon="mdi-refresh"
        :disabled="refreshAll.running"
        @click="refreshAllDeliveries"
      >{{ refreshAll.running && refreshAll.total ? `Checking ${refreshAll.done} of ${refreshAll.total}` : 'Refresh all' }}</v-btn>
    </div>
    <div v-if="refreshAll.message" class="bh-pad text-error" role="status">{{ refreshAll.message }}</div>

    <div class="bh-body" :class="{ 'bh-body--split': lgAndUp }">
      <div class="bh-listpane">
        <div class="bh-filters" role="group" aria-label="Filter blasts">
          <button
            v-for="f in FILTERS" :key="f.key" type="button"
            class="bh-chip" :class="{ 'is-on': filter === f.key }" :aria-pressed="filter === f.key"
            @click="filter = f.key"
          >{{ f.label }}<span class="bh-chip-n">{{ countFor(f.key) }}</span></button>
        </div>

        <div v-if="deliveries.error" class="bh-pad text-error" role="alert">
          {{ deliveries.error }}
          <button type="button" class="bh-link" @click="fetchDeliveries">Try again</button>
        </div>
        <div v-else-if="deliveries.loading && deliveries.rows.length === 0" class="bh-pad bh-muted" role="status">Loading recent blasts…</div>
        <div v-else-if="deliveries.rows.length === 0" class="bh-pad bh-muted">No blasts have been sent yet.</div>
        <div v-else-if="visible.length === 0" class="bh-pad bh-muted">No blasts match this filter.</div>

        <ul v-else class="bh-list">
          <li v-for="blast in visible" :key="blast.blast_id">
            <button
              type="button" class="bh-row"
              :class="{ 'is-selected': selectedId === blast.blast_id }"
              :aria-pressed="selectedId === blast.blast_id"
              @click="select(blast)"
            >
              <span class="bh-when">{{ formatShort(blast.created_at) }}</span>
              <span class="bh-msg">{{ blast.message }}</span>
              <span class="bh-meta">
                {{ pluralize(blast.barangay_count, 'barangay') }} · {{ pluralize(blast.recipient_count, 'resident') }} · {{ blast.sender }}
              </span>
              <span class="bh-bar" role="img" :aria-label="summary(blast)">
                <span
                  v-for="seg in segments(blast)" :key="seg.key"
                  class="bh-seg" :class="`bh-seg--${seg.key}`" :style="{ width: seg.pct + '%' }"
                ></span>
              </span>
              <span class="bh-summary">{{ summary(blast) }}</span>
            </button>
          </li>
        </ul>

        <p v-if="deliveries.rows.length > 0" class="bh-foot">Showing the {{ deliveries.rows.length }} most recent {{ deliveries.rows.length === 1 ? 'blast' : 'blasts' }}</p>
      </div>

      <!-- md and up: the panel sits beside the list, so another blast is one
           click away. Below that it opens in a dialog instead. -->
      <div v-if="lgAndUp" class="bh-detailpane">
        <BlastDetail
          v-if="selected"
          :blast="selected"
          :residents="residents" :expanded="expanded"
          :checking="!!checking[selected.blast_id]" :note="notes[selected.blast_id] ?? ''"
          closable
          v-on="detailEvents(selected)"
        />
        <div v-else class="bh-placeholder">
          <v-icon size="28">mdi-text-box-search-outline</v-icon>
          <p>Select a blast to see which barangays it reached and what happened to each resident.</p>
        </div>
      </div>
    </div>
  </v-card>

  <v-dialog v-if="!lgAndUp" v-model="dialogOpen" max-width="720" :fullscreen="xs" scrollable>
    <v-card v-if="selected" rounded="xl" class="bh-dialog">
      <BlastDetail
        :blast="selected"
        :residents="residents" :expanded="expanded"
        :checking="!!checking[selected.blast_id]" :note="notes[selected.blast_id] ?? ''"
        closable
        v-on="detailEvents(selected)"
      />
    </v-card>
  </v-dialog>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useDisplay } from 'vuetify'
import { getToken } from '@/composables/authToken'
import { API_BASE } from '@/config/api'
import { pluralize } from '@/composables/adminUi'
import { formatShort } from '@/composables/blastDelivery'
import BlastDetail from '@/components/BlastDetail.vue'

const { lgAndUp, xs } = useDisplay()

const getHeaders = () => ({
  'Authorization': `Bearer ${getToken()}`,
  'Content-Type': 'application/json',
  'Accept': 'application/json',
})

// ---- the list --------------------------------------------------------------

const deliveries = ref({ rows: [], loading: false, error: '' })
const filter = ref('all')

const FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'progress', label: 'In progress' },
  { key: 'failures', label: 'Has failures' },
]

// Queued and Pending are the two states that can still change.
const matches = (blast, key) => key === 'all'
  || (key === 'progress' && (blast.counts.queued > 0 || blast.counts.pending > 0))
  || (key === 'failures' && blast.counts.failed > 0)

const visible = computed(() => deliveries.value.rows.filter(b => matches(b, filter.value)))
const countFor = (key) => deliveries.value.rows.filter(b => matches(b, key)).length

// The stacked bar and the sentence under it say the same thing; the bar is
// aria-labelled with the sentence so it is not colour alone.
const SEGMENTS = [
  { key: 'sent', label: 'sent' },
  { key: 'pending', label: 'pending' },
  { key: 'queued', label: 'queued' },
  { key: 'failed', label: 'failed' },
]

const segments = (blast) => {
  const total = blast.recipient_count || 0
  if (total === 0) return []
  const odd = (blast.counts.unconfirmed ?? 0) + (blast.counts.other ?? 0)
  return [
    ...SEGMENTS.map(s => ({ key: s.key, n: blast.counts[s.key] ?? 0 })),
    { key: 'other', n: odd },
  ].filter(s => s.n > 0).map(s => ({ key: s.key, pct: (s.n / total) * 100 }))
}

const summary = (blast) => {
  const parts = SEGMENTS.filter(s => (blast.counts[s.key] ?? 0) > 0).map(s => `${blast.counts[s.key]} ${s.label}`)
  const odd = (blast.counts.unconfirmed ?? 0) + (blast.counts.other ?? 0)
  if (odd > 0) parts.push(`${odd} unconfirmed`)
  return parts.length > 0 ? parts.join(' · ') : 'No recipients'
}

const fetchDeliveries = async () => {
  deliveries.value.loading = true
  deliveries.value.error = ''

  try {
    const res = await fetch(`${API_BASE}/sms/deliveries`, { headers: getHeaders() })
    const data = await res.json()
    if (!res.ok) throw new Error(data.message || 'Failed to load recent blasts')
    deliveries.value.rows = data.data
    selectNewest()
  } catch (error) {
    deliveries.value.error = error.message
  } finally {
    deliveries.value.loading = false
  }
}

// ---- selection and the panel -----------------------------------------------

const selectedId = ref(null)
const dialogOpen = ref(false)
const selected = computed(() => deliveries.value.rows.find(b => b.blast_id === selectedId.value) ?? null)

// Rows come newest first. Fill the inline panel so it is not blank on load;
// never on small screens, where a selection would open the dialog by itself.
const selectNewest = () => {
  const rows = deliveries.value.rows
  if (!lgAndUp.value || rows.length === 0) return
  if (rows.some(b => b.blast_id === selectedId.value)) return
  selectedId.value = rows[0].blast_id
}
watch(lgAndUp, selectNewest)

const select = (blast) => {
  selectedId.value = blast.blast_id
  dialogOpen.value = true
}

// One handler set for either container (inline panel or dialog).
const detailEvents = (blast) => ({
  close: () => { selectedId.value = null; dialogOpen.value = false },
  check: () => checkBlast(blast),
  toggle: (row) => toggleBarangay(row),
  search: (row, value) => setSearch(row, value),
  filter: (row, status) => setStatus(row, status),
  more: (row) => loadResidents(row, { append: true }),
  retry: (row) => loadResidents(row),
})

// ---- residents of one barangay ---------------------------------------------

const PER_PAGE = 25
const expanded = ref({})
const residents = ref({})
const searchTimers = {}

const blank = () => ({ loading: false, loadingMore: false, error: '', rows: [], meta: null, search: '', status: '' })
const patch = (logId, change) => {
  residents.value = { ...residents.value, [logId]: { ...(residents.value[logId] ?? blank()), ...change } }
}

const loadResidents = async (row, { append = false } = {}) => {
  const logId = row.sms_log_id
  const current = residents.value[logId] ?? blank()
  const page = append ? (current.meta?.current_page ?? 0) + 1 : 1

  patch(logId, append ? { loadingMore: true, error: '' } : { loading: true, error: '' })

  const params = new URLSearchParams({ page: String(page), per_page: String(PER_PAGE) })
  if (current.status) params.set('status', current.status)
  if (current.search) params.set('search', current.search)

  try {
    const res = await fetch(`${API_BASE}/sms/deliveries/${logId}/recipients?${params}`, { headers: getHeaders() })
    const data = await res.json()
    if (!res.ok) throw new Error(data.message || 'Failed to load residents')

    // A late answer for a filter the admin has since changed is dropped.
    const now = residents.value[logId] ?? blank()
    if (now.status !== current.status || now.search !== current.search) return

    patch(logId, {
      loading: false,
      loadingMore: false,
      rows: append ? [...now.rows, ...data.data] : data.data,
      meta: data.meta,
    })
  } catch (error) {
    patch(logId, { loading: false, loadingMore: false, error: error.message })
  }
}

const toggleBarangay = (row) => {
  const open = !expanded.value[row.sms_log_id]
  expanded.value = { ...expanded.value, [row.sms_log_id]: open }
  if (open && !residents.value[row.sms_log_id]) loadResidents(row)
}

const setStatus = (row, status) => {
  patch(row.sms_log_id, { status })
  loadResidents(row)
}

const setSearch = (row, value) => {
  patch(row.sms_log_id, { search: value })
  clearTimeout(searchTimers[row.sms_log_id])
  searchTimers[row.sms_log_id] = setTimeout(() => loadResidents(row), 300)
}

onBeforeUnmount(() => {
  for (const timer of Object.values(searchTimers)) clearTimeout(timer)
})

// A check changes statuses and timestamps, so any list of residents that is
// open is read again rather than left showing what it showed before.
const reloadOpenResidents = (rows) => {
  for (const row of rows) {
    if (expanded.value[row.sms_log_id]) loadResidents(row)
  }
}

// ---- checking delivery ------------------------------------------------------

const checking = ref({})
const notes = ref({})

// The blast's totals are sums of its barangay rows, so a check that updates one
// barangay recomputes them here instead of asking the server again.
const rollUp = (blast) => {
  const totals = {}
  for (const key of Object.keys(blast.counts)) {
    totals[key] = blast.barangays.reduce((sum, b) => sum + (b.counts[key] ?? 0), 0)
  }
  blast.counts = totals
  blast.recipient_count = Object.values(totals).reduce((sum, n) => sum + n, 0)
  blast.checkable = blast.barangays.some(b => b.checkable)
  blast.delivery_checked_at = blast.barangays.map(b => b.delivery_checked_at).filter(Boolean).sort().at(-1) ?? null
}

// One barangay row's check. Returns { checked, note }.
const checkBarangay = async (blast, row) => {
  try {
    const res = await fetch(`${API_BASE}/sms/deliveries/${row.sms_log_id}/check`, {
      method: 'POST',
      headers: getHeaders(),
    })
    const data = await res.json()
    if (!res.ok) throw new Error(data.message || 'Failed to check the status')

    const index = blast.barangays.findIndex(b => b.sms_log_id === row.sms_log_id)
    if (index !== -1) blast.barangays[index] = data.data
    rollUp(blast)

    if (data.checked && data.not_found > 0) {
      return { checked: true, note: `${data.not_found} recipient(s) in ${row.barangay} are not in SkySMS's list yet, so they still show what was recorded at send.` }
    }
    return { checked: data.checked, note: data.message || '' }
  } catch (error) {
    return { checked: false, note: error.message }
  }
}

const CHECK_CONCURRENCY = 3
const stillChanging = (row) => row.checkable && (row.counts.queued > 0 || row.counts.pending > 0)

// Check status on a blast checks every barangay of it that can still change
// (Sent and Failed are final), a few at a time.
const checkBlast = async (blast) => {
  const rows = blast.barangays.filter(row => stillChanging(row))
  const queue = [...rows]
  checking.value = { ...checking.value, [blast.blast_id]: true }
  notes.value = { ...notes.value, [blast.blast_id]: queue.length > 0 ? '' : 'Every message already has a final status.' }

  let unchecked = 0
  let note = ''
  const worker = async () => {
    for (let row = queue.shift(); row; row = queue.shift()) {
      const result = await checkBarangay(blast, row)
      if (!result.checked) unchecked++
      if (result.note && !note) note = result.note
    }
  }
  await Promise.all(Array.from({ length: CHECK_CONCURRENCY }, worker))

  if (unchecked > 0) note = `${unchecked} ${unchecked === 1 ? 'barangay' : 'barangays'} could not be checked. ${note}`.trim()
  notes.value = { ...notes.value, [blast.blast_id]: note }
  checking.value = { ...checking.value, [blast.blast_id]: false }
  reloadOpenResidents(rows)
}

// Refresh all: reload the list, then check every barangay row that still has
// Queued or Pending messages, a few at a time.
const refreshAll = ref({ running: false, done: 0, total: 0, message: '' })

const refreshAllDeliveries = async () => {
  refreshAll.value = { running: true, done: 0, total: 0, message: '' }
  await fetchDeliveries()

  const items = deliveries.value.rows.flatMap(blast => blast.barangays.filter(row => stillChanging(row)).map(row => ({ blast, row })))
  const queue = [...items]
  refreshAll.value.total = queue.length
  let failed = 0

  const worker = async () => {
    for (let item = queue.shift(); item; item = queue.shift()) {
      const result = await checkBarangay(item.blast, item.row)
      if (!result.checked) failed++
      refreshAll.value.done++
    }
  }
  await Promise.all(Array.from({ length: CHECK_CONCURRENCY }, worker))

  refreshAll.value.running = false
  if (failed > 0) refreshAll.value.message = `${failed} ${failed === 1 ? 'barangay' : 'barangays'} could not be checked`
  reloadOpenResidents(items.map(i => i.row))
}

onMounted(fetchDeliveries)

// SmsView reloads the list after a blast goes out.
defineExpose({ reload: fetchDeliveries })
</script>

<style scoped>
.bh-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.1);
  overflow: hidden;
  /* Height of the scrolling panes: the viewport less the page chrome. */
  --bh-pane-h: max(560px, calc(100vh - 220px));
}
.bh-pad { padding: 20px 24px; }
.bh-head {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
.bh-title { margin: 0; font-size: 18px; line-height: 26px; font-weight: 700; }
.bh-sub { margin: 4px 0 0; font-size: 14px; line-height: 20px; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.bh-muted { color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); font-size: 14px; }
.bh-link { margin-left: 8px; background: none; border: 0; font: inherit; font-weight: 700; color: rgb(var(--v-theme-primary-strong)); cursor: pointer; text-decoration: underline; }

.bh-body { display: block; }
.bh-body--split { display: grid; grid-template-columns: 340px minmax(0, 1fr); }
.bh-body--split .bh-listpane { border-right: 1px solid rgba(var(--v-theme-on-surface), 0.08); }
.bh-listpane { display: flex; flex-direction: column; max-height: var(--bh-pane-h); min-width: 0; }
.bh-detailpane { min-width: 0; max-height: var(--bh-pane-h); overflow-y: auto; }

.bh-filters { display: flex; flex-wrap: wrap; gap: 8px; padding: 16px 24px 12px; }
.bh-chip {
  display: inline-flex; align-items: center; gap: 6px;
  min-height: 36px; padding: 0 14px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.16);
  border-radius: 999px; background: rgb(var(--v-theme-surface));
  font-family: inherit; font-size: 13px; font-weight: 600; color: inherit; cursor: pointer;
}
.bh-chip.is-on {
  background: rgba(var(--v-theme-primary), 0.12);
  border-color: rgba(var(--v-theme-primary), 0.5);
  color: rgb(var(--v-theme-primary-strong));
}
.bh-chip-n { font-variant-numeric: tabular-nums; opacity: 0.7; }

.bh-list { list-style: none; margin: 0; padding: 0; flex: 1 1 auto; min-height: 0; overflow-y: auto; }
.bh-row {
  display: grid;
  gap: 2px;
  width: 100%;
  padding: 12px 24px 12px 21px;
  border: 0;
  border-left: 3px solid transparent;
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  background: none;
  font-family: inherit;
  text-align: left;
  color: inherit;
  cursor: pointer;
}
.bh-row:hover { background: rgba(var(--v-theme-on-surface), 0.04); }
.bh-row.is-selected {
  background: rgba(var(--v-theme-primary), 0.08);
  border-left-color: rgb(var(--v-theme-primary));
}
.bh-when { font-size: 12px; line-height: 16px; font-weight: 700; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.bh-msg { font-size: 14px; line-height: 20px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.bh-meta { font-size: 12px; line-height: 16px; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.bh-summary { font-size: 12px; line-height: 16px; font-weight: 600; font-variant-numeric: tabular-nums; }
.bh-bar {
  display: flex;
  height: 8px;
  margin-top: 6px;
  border-radius: 999px;
  overflow: hidden;
  background: rgba(var(--v-theme-on-surface), 0.08);
}
.bh-seg { display: block; height: 100%; }
.bh-seg--sent { background: rgb(var(--v-theme-success)); }
.bh-seg--pending { background: rgb(var(--v-theme-warning)); }
.bh-seg--queued { background: rgba(var(--v-theme-on-surface), 0.35); }
.bh-seg--failed { background: rgb(var(--v-theme-error)); }
.bh-seg--other { background: rgba(var(--v-theme-info), 0.7); }
.bh-foot { margin: 0; padding: 12px 24px; border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08); font-size: 12px; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }

.bh-placeholder {
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
  min-height: 320px; padding: 24px; text-align: center;
  font-size: 14px; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.bh-placeholder p { margin: 0; max-width: 320px; }
.bh-dialog { max-height: 100%; overflow-y: auto; }

/* Outlined button, the same as the other Text blast header actions. */
.outline-btn {
  padding: 0 16px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.14);
  border-radius: 12px;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-primary-strong));
  font-size: 14px; font-weight: 700; letter-spacing: 0;
}
.outline-btn :deep(.v-icon--start) { margin-inline-end: 8px; font-size: 16px; }
</style>
