<template>
  <v-container fluid class="dashboard-bg">

    <div class="dash-band d-flex justify-space-between align-start flex-wrap ga-3">
      <div class="min-width-0">
        <h2 class="dash-title">Welcome back, {{ adminFirstName || 'there' }}</h2>
        <!-- Only once every list has arrived: a half-loaded count would read as a calm shift. -->
        <p v-if="summary" class="dash-summary">
          <template v-for="(part, i) in summary" :key="i">
            <b v-if="part.tone" class="count-chip" :class="`tone-${part.tone}`">{{ part.text }}</b>
            <template v-else>{{ part.text }}</template>
          </template>
        </p>
      </div>
      <div class="d-flex align-center flex-wrap ga-3">
        <!-- The bell carries the activity log and the follow-up calls. An account
             holding none of the sections those come from would open an empty
             list, so it is not drawn. -->
        <v-menu v-if="showBell" location="bottom end">
          <template v-slot:activator="{ props }">
            <v-btn icon="mdi-bell-outline" variant="outlined" class="band-btn" v-bind="props" aria-label="System notifications">
              <!-- A count when staff have someone to ring, a plain dot for activity. -->
              <v-badge v-if="followUps.length > 0" color="error" :content="followUps.length">
                <v-icon>mdi-bell-outline</v-icon>
              </v-badge>
              <v-badge color="error" dot v-else-if="systemLogs.length > 0">
                <v-icon>mdi-bell-outline</v-icon>
              </v-badge>
              <v-icon v-else>mdi-bell-outline</v-icon>
            </v-btn>
          </template>
          <v-card min-width="320" elevation="4" rounded="lg" class="border">
            <v-list density="compact" class="pa-0">
              <!-- Push-only notices that reached nobody (equipment due-back
                   reminders and available-again notices). There is no text behind
                   them, so this is how staff learn whom to ring. -->
              <template v-if="followUps.length > 0">
                <v-list-subheader class="font-weight-bold text-uppercase py-2">Follow up by phone</v-list-subheader>
                <v-divider></v-divider>
                <v-list-item v-for="(item, i) in followUps" :key="'follow-'+i" class="py-3 border-b">
                  <template v-slot:prepend>
                    <v-avatar color="warning" variant="tonal" size="32" class="mr-3">
                      <v-icon color="warning" size="small">mdi-phone-alert-outline</v-icon>
                    </v-avatar>
                  </template>
                  <v-list-item-title class="text-body-2 font-weight-bold">{{ item.name }} &bull; {{ item.phone }}</v-list-item-title>
                  <v-list-item-subtitle class="text-caption">{{ item.what }}: no registered device</v-list-item-subtitle>
                  <template v-slot:append>
                    <span class="text-caption text-medium-emphasis">{{ item.time }}</span>
                  </template>
                </v-list-item>
              </template>
              <v-list-subheader class="font-weight-bold text-uppercase py-2">System Logs</v-list-subheader>
              <v-divider></v-divider>
              <template v-if="systemLogs.length > 0">
                <v-list-item v-for="(log, i) in systemLogs.slice(0, 5)" :key="'log-'+i" class="py-3 border-b">
                  <template v-slot:prepend>
                    <v-avatar color="primary" variant="tonal" size="32" class="mr-3">
                      <v-icon color="primary" size="small">mdi-history</v-icon>
                    </v-avatar>
                  </template>
                  <v-list-item-title class="text-body-2 font-weight-bold">{{ log.action }}</v-list-item-title>
                  <v-list-item-subtitle class="text-caption">{{ log.user }} &bull; {{ log.module }}</v-list-item-subtitle>
                  <template v-slot:append>
                    <span class="text-caption text-medium-emphasis">{{ log.time }}</span>
                  </template>
                </v-list-item>
              </template>
              <div v-else class="pa-4 text-center text-caption text-medium-emphasis">No recent logs</div>
            </v-list>
          </v-card>
        </v-menu>

        <v-avatar size="44" class="band-avatar cursor-pointer font-weight-bold">J</v-avatar>
      </div>
    </div>

    <!-- What is true right now. Each card opens its own page, filtered; a card
         whose data the account cannot read is left out. -->
    <v-skeleton-loader v-if="loading" type="text" height="88" class="mb-4"></v-skeleton-loader>
    <!-- A grid, not v-col: four or five cards depending on what the account can read. -->
    <div v-else-if="kpis.length > 0" class="kpi-grid mb-2" :style="{ '--kpi-count': kpis.length }">
      <v-card v-for="k in kpis" :key="k.label" :to="k.to" elevation="0" class="dash-card kpi-card h-100" :class="{ 'kpi-link': k.to }">
        <v-avatar :color="k.accent" variant="tonal" size="40" rounded="lg" class="flex-shrink-0">
          <v-icon :color="k.accent" size="20">{{ k.icon }}</v-icon>
        </v-avatar>
        <div class="min-width-0">
          <div class="kpi-label">{{ k.label }}</div>
          <div class="kpi-value" :class="`tone-${k.tone}`">{{ k.value }}</div>
        </div>
      </v-card>
    </div>

    <!-- Everything open, one tab per kind of work. A row opens that request on
         its own page. Only open items; the full history lives on each page. -->
    <v-row class="mb-2"><v-col cols="12">
    <v-card elevation="0" class="dash-card dash-panel">
      <div class="panel-head">
        <div class="dash-card-title">Open Requests</div>
        <div class="dash-card-subtitle">{{ currentTab.hint }}</div>
      </div>

      <v-tabs v-model="queueTab" color="primary" density="compact" height="48" hide-slider class="dash-tabs px-4">
        <v-tab v-for="t in QUEUE_TABS" :key="t.value" :value="t.value" class="text-none font-weight-bold">
          {{ t.title }}
          <v-chip size="x-small" class="ml-2 font-weight-bold" variant="tonal" :color="queueTab === t.value ? 'primary' : undefined">{{ tabCounts[t.value] }}</v-chip>
        </v-tab>
      </v-tabs>
      <v-divider></v-divider>

      <v-alert v-if="loadError" type="warning" variant="tonal" density="compact" class="ma-4">{{ loadError }}</v-alert>

      <v-data-table
        v-model:sort-by="sortBy"
        v-model:page="page"
        :headers="headers"
        :items="visibleRows"
        :loading="loading"
        item-value="key"
        :items-per-page="PAGE_SIZE"
        must-sort
        hide-default-footer
        :row-props="() => ({ class: 'queue-row' })"
        :no-data-text="currentTab.empty"
        class="queue-table"
        @click:row="(_event, { item }) => openRow(item)"
      >
        <template #item.filedAt="{ item }">
          <div class="cell-primary" :title="fmtFiled(item.filedAt)">{{ fmtFiled(item.filedAt) }}</div>
          <div v-if="item.note" class="cell-secondary" :class="`wait-${item.noteTone}`" :title="item.note">{{ item.note }}</div>
        </template>
        <template #item.name="{ item }">
          <!-- A real link so the row can be reached from the keyboard; a click
               anywhere else on the row goes to the same place. -->
          <router-link :to="rowLink(item)" class="cell-primary row-link" :title="item.name" @click.stop>{{ item.name }}</router-link>
        </template>
        <template #item.type="{ item }">
          <div class="d-flex align-center ga-2 min-width-0">
            <v-icon size="16" class="text-medium-emphasis">{{ KIND_ICONS[item.kind] }}</v-icon>
            <span class="cell-primary" :title="item.type">{{ item.type }}</span>
            <v-chip v-if="item.shortStock" size="x-small" color="error" variant="tonal" class="font-weight-bold" :title="`${item.onHand} on hand`">Short stock</v-chip>
          </div>
        </template>
        <template #item.status="{ item }">
          <StatusPill :status="item.status" solid class="status-badge" />
        </template>
      </v-data-table>
      <div class="queue-pager d-flex justify-center pa-3">
        <v-pagination v-if="pageCount > 1" v-model="page" :length="pageCount" :total-visible="5" density="comfortable" rounded="circle"></v-pagination>
      </div>
    </v-card>
    </v-col></v-row>

    <DashboardCharts :history="history" :top="top" :loading="loading" />

  </v-container>
</template>

<script setup>
import { ref, reactive, onMounted, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import StatusPill from '@/components/StatusPill.vue'
import DashboardCharts from '@/components/DashboardCharts.vue'
import '@/components/dashboard.css'
import { API_BASE } from '@/config/api'
import { authHeaders } from '@/composables/adminUi'
import { BORROWING_STATUSES } from '@/composables/borrowingStatus'
import { isAmbulanceRequest } from '@/composables/useRequestFetch'
import { requesterName } from '@/composables/requestDisplay'
import { useCurrentAdmin, adminFirstName } from '@/composables/useCurrentAdmin'
import { welcomeSentence } from '@/composables/dashboardWelcome'
import { DAY_MS } from '@/composables/dashboardTrends'

const router = useRouter()

// The server already leaves out the lists an account may not see (activity feed,
// follow-up calls); this only decides whether the bell that shows them is worth
// drawing at all.
const { can } = useCurrentAdmin()
const showBell = computed(() => can('logs') || can('borrowings') || can('ambulance'))

const systemLogs = ref([])
const followUps = ref([])
const rows = ref([])
// Every service request, for the filed/resolved chart: { filedAt, resolvedAt }.
const history = ref([])
// Last 30 days by service and by equipment item, for Most Requested.
const top = ref(null)
// { free, total }: not in Maintenance, not out on a trip, no booking in the next 2h.
const units = ref(null)
// Trip records, or null when the account cannot read them.
const trips = ref(null)
// { available, total } across all responders, from the dashboard payload.
const responders = ref(null)
const loading = ref(true)
const loadError = ref('')
// Which lists arrived. A 403 or 500 on one must not read as "nothing open".
const loaded = reactive({ services: false, borrowings: false })

const WAIT_AMBER_DAYS = 3
const WAIT_RED_DAYS = 7

const QUEUE_TABS = [
  { value: 'services', title: 'Services', test: (r) => r.kind === 'service', hint: 'Resident requests still open', empty: 'No open service requests' },
  { value: 'ambulance', title: 'Ambulance', test: (r) => r.kind === 'ambulance' && r.status !== 'Booked', hint: 'Ambulance dispatch requests not yet closed', empty: 'No active dispatch requests' },
  { value: 'bookings', title: 'Bookings', test: (r) => r.kind === 'ambulance' && r.status === 'Booked', hint: 'Scheduled ambulance bookings', empty: 'No scheduled bookings' },
  { value: 'borrowing', title: 'Borrowing', test: (r) => r.kind === 'borrow', hint: 'Open equipment loans', empty: 'No open loans' },
]
const KIND_ICONS = { service: 'mdi-clipboard-text-outline', ambulance: 'mdi-ambulance', borrow: 'mdi-toolbox-outline' }
const KIND_ROUTES = { service: '/manage-requests', ambulance: '/conduction-requests', borrow: '/borrowings' }

const PAGE_SIZE = 5
const queueTab = ref('services')
const page = ref(1)
// must-sort on the table: a header toggles ascending and descending, never off.
const sortBy = ref([{ key: 'filedAt', order: 'asc' }])

const headers = [
  { title: 'Time Filed', key: 'filedAt', sortable: true, width: '22%' },
  { title: 'Head of the Family', key: 'name', sortable: true, width: '26%' },
  { title: 'Request Type', key: 'type', sortable: true, width: '34%' },
  { title: 'Status', key: 'status', sortable: true, width: '18%' },
]

// A tab change is a new list; page 3 of the last one would be blank.
watch(queueTab, () => { page.value = 1 })

// ---- The queue ----------------------------------------------------------

const SERVICE_TERMINAL = new Set(['Resolved', 'Cancelled', 'Disapproved'])
const BORROW_TERMINAL = new Set(BORROWING_STATUSES.filter((s) => s.terminal).map((s) => s.status))

const fmtFiled = (ms) => new Date(ms).toLocaleString('en-PH', {
  month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
})

const waitTone = (days) => {
  if (days > WAIT_RED_DAYS) {
    return 'error'
  }
  return days >= WAIT_AMBER_DAYS ? 'warning' : 'muted'
}

// Only a Pending request is waiting on staff; later statuses have moved on.
const waitNote = (status, ms) => {
  if (status !== 'Pending') {
    return { note: '', noteTone: 'muted' }
  }
  const days = Math.max(0, Math.floor((Date.now() - ms) / DAY_MS))
  return { note: `${days}d waiting`, noteTone: waitTone(days) }
}

// due_date is a bare calendar date; new Date('2026-08-10') would parse as UTC
// midnight and put "overdue" a day off, so it is built from its parts.
const dueDay = (value) => {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(value || '')
  return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : null
}
const daysPastDue = (value) => {
  const due = dueDay(value)
  if (!due) return 0
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return Math.round((today - due) / DAY_MS)
}

const serviceRow = (r) => {
  const filedAt = new Date(r.created_at).getTime()
  return {
    key: `svc-${r.request_id}`,
    id: r.request_id,
    kind: isAmbulanceRequest(r) ? 'ambulance' : 'service',
    filedAt,
    name: requesterName(r),
    type: r.service?.service_name || 'Other',
    status: r.status,
    overdue: false,
    ...waitNote(r.status, filedAt),
  }
}

const borrowRow = (b) => {
  const item = b.equipment?.item_name || b.other_equipment_text || 'Unknown item'
  const filedAt = new Date(b.created_at).getTime()
  const late = b.status === 'Released' && daysPastDue(b.due_date) > 0
  // Stock only leaves the shelf on release, so only a loan not yet released can come up short.
  const onHand = b.equipment?.available_quantity
  return {
    onHand,
    shortStock: ['Pending', 'Approved'].includes(b.status) && onHand != null && (b.quantity ?? 1) > onHand,
    key: `bor-${b.borrow_id}`,
    id: b.borrow_id,
    kind: 'borrow',
    filedAt,
    name: requesterName(b),
    type: item,
    status: b.status,
    overdue: late,
    ...(late ? { note: `${daysPastDue(b.due_date)}d overdue`, noteTone: 'error' } : waitNote(b.status, filedAt)),
  }
}

// One authed GET that is allowed to fail: an account without the section is
// answered 403, and its queue is simply missing that kind of row.
const fetchList = async (path) => {
  try {
    const res = await fetch(`${API_BASE}${path}`, { headers: authHeaders() })
    if (!res.ok) return null
    const body = await res.json()
    const list = body.data || body
    return Array.isArray(list) ? list : null
  } catch {
    return null
  }
}

const fetchDashboardData = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const [dash, services, borrowings, tripList] = await Promise.all([
      fetch(`${API_BASE}/admin/dashboard`, { headers: authHeaders() }),
      fetchList('/admin/service-requests'),
      fetchList('/borrowings'),
      fetchList('/conduction-requests'),
    ])
    if (!dash.ok) throw new Error('Network response error')

    const data = await dash.json()
    systemLogs.value = data.systemLogs || []
    followUps.value = data.followUps || []
    responders.value = data.responders ?? null
    units.value = data.units ?? null
    trips.value = tripList
    top.value = data.charts?.pieByPeriod?.month ?? null

    rows.value = [
      ...(services || []).filter((r) => !SERVICE_TERMINAL.has(r.status)).map((r) => serviceRow(r)),
      ...(borrowings || []).filter((b) => !BORROW_TERMINAL.has(b.status)).map((b) => borrowRow(b)),
    ]
    history.value = (services || []).map((r) => ({
      filedAt: new Date(r.created_at).getTime(),
      resolvedAt: r.resolved_at ? new Date(r.resolved_at).getTime() : null,
    }))
    loaded.services = services !== null
    loaded.borrowings = borrowings !== null
    const missing = [!loaded.services && 'resident requests and ambulance bookings', !loaded.borrowings && 'equipment loans'].filter(Boolean)
    if (missing.length > 0) loadError.value = `Could not load ${missing.join(' or ')}. The list below is incomplete.`
  } catch (error) {
    console.error('Failed to load dashboard:', error)
    loadError.value = 'The dashboard could not be loaded.'
  } finally {
    loading.value = false
  }
}

const currentTab = computed(() => QUEUE_TABS.find((t) => t.value === queueTab.value))
const tabCounts = computed(() => Object.fromEntries(QUEUE_TABS.map((t) => [t.value, rows.value.filter((r) => t.test(r)).length])))
const visibleRows = computed(() => rows.value.filter((r) => currentTab.value.test(r)))
const pageCount = computed(() => Math.ceil(visibleRows.value.length / PAGE_SIZE))

// Each page opens the request itself from ?request=<id>.
const rowLink = (item) => ({ path: KIND_ROUTES[item.kind], query: { request: item.id } })
const openRow = (item) => router.push(rowLink(item))

const overdueRows = computed(() => rows.value.filter((r) => r.overdue))

// Left the office and not back yet, per the trip log.
const tripsOut = computed(() => (trips.value || []).filter((t) => t.departed_office_at && !t.returned_office_at).length)

const summary = computed(() => {
  if (loading.value || loadError.value) return null
  const open = (kind, status) => rows.value.filter((r) => r.kind === kind && r.status === status).length
  return welcomeSentence({
    trips: tripsOut.value,
    overdue: overdueRows.value.length,
    ambulance: open('ambulance', 'Pending'),
  })
})

// Number colour: red for a problem, amber for nothing left to send out.
const kpis = computed(() => {
  const link = (section, to) => (can(section) ? to : undefined)
  const emptyTone = (free) => (free === 0 ? 'warning' : 'default')
  const items = []
  if (units.value) items.push({ label: 'Available Units', icon: 'mdi-ambulance', accent: 'primary', value: `${units.value.free}/${units.value.total}`, tone: emptyTone(units.value.free), to: link('vehicles', '/vehicles') })
  if (responders.value) items.push({ label: 'Available Responders', icon: 'mdi-account-hard-hat', accent: 'info', value: `${responders.value.available}/${responders.value.total}`, tone: emptyTone(responders.value.available), to: link('responders', '/responders') })
  if (trips.value) items.push({ label: 'Ongoing Trips', icon: 'mdi-map-marker-path', accent: 'slate', value: tripsOut.value, tone: 'default', to: link('ambulance', { path: '/conduction-requests', query: { status: 'Responding' } }) })
  if (loaded.borrowings) {
    const n = overdueRows.value.length
    items.push({ label: 'Overdue Borrowing', icon: 'mdi-alert-circle-outline', accent: 'error', value: n, tone: n > 0 ? 'error' : 'default', to: link('borrowings', { path: '/borrowings', query: { overdue: '1' } }) })
  }
  return items
})

onMounted(fetchDashboardData)
</script>

<style scoped>
.dashboard-bg {
  background-color: rgb(var(--v-theme-background));
  /* Dashboard-only design tokens. Text on the surface uses the -strong warning
     and error variants: the base ones fail AA as text on white. */
  --dash-text: rgb(var(--v-theme-on-surface));
  --dash-muted: rgba(var(--v-theme-on-surface), 0.62);
  --dash-warn: rgb(var(--v-theme-warning-strong));
  --dash-bad: rgb(var(--v-theme-error-strong));
  /* The card shape from before the rebuild: Vuetify's rounded-xl (24px) and the
     soft two-layer shadow, with no border. One place, so every card matches. */
  --dash-radius: 24px;
  --dash-shadow: 0 1px 2px rgba(var(--v-theme-on-surface), 0.04), 0 4px 14px rgba(var(--v-theme-on-surface), 0.08);
  --dash-pad: 20px;
}
/* on-surface is light in the dark theme, so the same shadow would glow. Use
   the shadow colour token and rely on surface vs background to separate cards. */
.v-theme--dark .dashboard-bg {
  --dash-shadow: 0 1px 2px rgba(var(--v-shadow-color), 0.4), 0 4px 14px rgba(var(--v-shadow-color), 0.25);
}
.kpi-card {
  display: flex;
  align-items: center;
  gap: 14px;
}
/* Two up on a phone, three on a tablet, then one row of whatever arrived, with
   the 8px gap the cards had before the rebuild. */
.kpi-grid {
  display: grid;
  gap: 8px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}
@media (min-width: 600px) {
  .kpi-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
@media (min-width: 960px) {
  .kpi-grid {
    grid-template-columns: repeat(var(--kpi-count, 4), minmax(0, 1fr));
  }
}
.kpi-label {
  font-size: 13px;
  font-weight: 500;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--dash-muted);
}
.kpi-value {
  margin-top: 6px;
  font-size: 32px;
  font-weight: 700;
  line-height: 1.1;
  font-variant-numeric: tabular-nums;
}
.kpi-value.tone-default {
  color: var(--dash-text);
}
.kpi-value.tone-warning {
  color: var(--dash-warn);
}
.kpi-value.tone-error {
  color: var(--dash-bad);
}
/* Keyboard focus is an outline, so no card ever carries a resting border colour. */
.kpi-link:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}
/* The brand band. The secondary token is the sidebar's dark green in both
   themes, and on-secondary is the text colour Vuetify derives for it, so
   nothing here is a literal colour. */
.dash-band {
  margin-bottom: 24px;
  padding: 24px var(--dash-pad);
  border-radius: var(--dash-radius);
  box-shadow: var(--dash-shadow);
  background: linear-gradient(
    135deg,
    color-mix(in srgb, rgb(var(--v-theme-primary)) 55%, rgb(var(--v-theme-secondary))) 0%,
    rgb(var(--v-theme-secondary)) 100%
  );
  color: rgb(var(--v-theme-on-secondary));
}
.dash-title {
  font-size: 28px;
  font-weight: 700;
  line-height: 1.2;
  color: rgb(var(--v-theme-on-secondary));
}
.dash-summary {
  margin: 8px 0 0;
  font-size: 15px;
  font-weight: 400;
  line-height: 1.9;
  color: rgba(var(--v-theme-on-secondary), 0.85);
}
.count-chip {
  display: inline-block;
  padding: 0 10px;
  border-radius: 999px;
  font-weight: 700;
  line-height: 1.5;
}
.count-chip.tone-default {
  background: rgba(var(--v-theme-on-secondary), 0.16);
  color: rgb(var(--v-theme-on-secondary));
}
.count-chip.tone-warning {
  background: rgb(var(--v-theme-warning));
  color: rgb(var(--v-theme-on-warning));
}
.count-chip.tone-error {
  background: rgb(var(--v-theme-error));
  color: rgb(var(--v-theme-on-error));
}
.band-btn {
  color: rgb(var(--v-theme-on-secondary));
}
.band-avatar {
  background: rgba(var(--v-theme-on-secondary), 0.16);
  color: rgb(var(--v-theme-on-secondary));
}
.min-width-0 {
  min-width: 0;
}
.dash-panel {
  padding: 0 !important;
  overflow: hidden;
}
.panel-head {
  padding: var(--dash-pad) var(--dash-pad) 8px;
}

.dash-tabs :deep(.v-tab) {
  height: 34px;
  align-self: center;
  min-width: 0;
  margin-right: 4px;
  border-radius: 999px;
}
.dash-tabs :deep(.v-tab--selected) {
  background: rgba(var(--v-theme-primary), 0.12);
  color: rgb(var(--v-theme-primary-strong));
}

.queue-table :deep(th) {
  background: color-mix(in srgb, rgb(var(--v-theme-primary)) 6%, rgb(var(--v-theme-surface))) !important;
  font-size: 12px !important;
  font-weight: 600 !important;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--dash-muted) !important;
  white-space: nowrap;
}
/* Fixed columns and a fixed row height, so no tab or page reflows the card.
   The wrapper reserves header + PAGE_SIZE rows; the pager slot is always there. */
.queue-table {
  --v-table-row-height: 52px;
}
.queue-table :deep(table) {
  table-layout: fixed;
}
.queue-table :deep(.v-table__wrapper) {
  min-height: calc(var(--v-table-header-height, 56px) + 5 * var(--v-table-row-height));
}
.queue-table :deep(td) {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  height: var(--v-table-row-height);
}
.queue-pager {
  min-height: 64px;
}
.queue-table :deep(.queue-row) {
  cursor: pointer;
}
.queue-table :deep(.queue-row:hover > td) {
  background: rgba(var(--v-theme-primary), 0.06);
}
/* Inset shadow, not a border: the row keeps its size when the accent appears. */
.queue-table :deep(.queue-row:hover > td:first-child) {
  box-shadow: inset 3px 0 0 rgb(var(--v-theme-primary));
}
.cell-primary {
  font-size: 14px;
  font-weight: 600;
  color: var(--dash-text);
}
.cell-primary,
.cell-secondary {
  overflow: hidden;
  text-overflow: ellipsis;
}
.cell-secondary {
  font-size: 12px;
  font-weight: 400;
}
.row-link {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  text-decoration: none;
}
.row-link:hover {
  text-decoration: underline;
}
.wait-muted {
  color: var(--dash-muted);
}
.wait-warning {
  color: var(--dash-warn);
}
.wait-error {
  color: var(--dash-bad);
}
/* One width for every status, so the column reads as a column. */
.status-badge {
  min-width: 96px;
  justify-content: center;
}
</style>
