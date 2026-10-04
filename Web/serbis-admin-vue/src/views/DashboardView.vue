<template>
  <v-container fluid class="dashboard-bg">

    <div class="dash-band d-flex justify-space-between align-start flex-wrap ga-3">
      <div class="min-width-0">
        <div class="dash-overline">{{ today }}</div>
        <h2 class="dash-title">Welcome back, {{ adminFirstName || 'there' }}</h2>
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
    <div v-if="loading" class="kpi-grid mb-2" :style="{ '--kpi-count': kpiCount }" aria-hidden="true">
      <v-card v-for="n in kpiCount" :key="n" elevation="0" class="dash-card kpi-card h-100">
        <span class="skel kpi-skel-icon"></span>
        <div class="flex-grow-1 min-width-0">
          <span class="skel kpi-skel-label"></span>
          <span class="skel kpi-skel-value"></span>
        </div>
      </v-card>
    </div>
    <!-- A grid, not v-col: four or five cards depending on what the account can read. -->
    <div v-else-if="kpis.length > 0" class="kpi-grid mb-2 content-in" :style="{ '--kpi-count': kpis.length }">
      <v-card v-for="k in kpis" :key="k.label" :to="k.to" elevation="0" class="dash-card kpi-card h-100" :class="{ 'kpi-link': k.to }">
        <span class="kpi-tile" :class="`kpi-tile--${k.accent}`"><v-icon size="22">{{ k.icon }}</v-icon></span>
        <div class="kpi-main">
          <div class="kpi-label">{{ k.label }}</div>
          <div class="kpi-valuerow">
            <span class="kpi-value" :class="`tone-${k.tone}`"><span :key="k.value" class="value-in">{{ k.value }}</span></span>
            <span v-if="k.total != null" class="kpi-total">/ {{ k.total }}</span>
          </div>
          <div v-if="k.total" class="kpi-bar"><span :class="`kpi-bar--${k.accent}`" :style="{ width: `${Math.round((k.value / k.total) * 100)}%` }"></span></div>
          <!-- Units on trips: the ongoing-trips count, a link to the trip log
               when the account may open it. Not nested in a card link. -->
          <router-link v-if="k.caption && k.captionTo" :to="k.captionTo" class="kpi-caption kpi-caption--link">{{ k.caption }}</router-link>
          <div v-else-if="k.caption" class="kpi-caption">{{ k.caption }}</div>
        </div>
      </v-card>
    </div>

    <!-- Everything open, one tab per kind of work. A row opens that request on
         its own page. Only open items; the full history lives on each page. -->
    <section class="open-requests">
      <div class="open-head">
        <div>
          <h2 class="open-title">Open requests</h2>
          <div class="open-sub">{{ currentTab.hint }}</div>
        </div>
        <router-link :to="currentTab.to" class="open-link">View all requests</router-link>
      </div>

      <v-alert v-if="loadError" type="warning" variant="tonal" density="compact">{{ loadError }}</v-alert>

      <!-- The shared board table: tabs with counts, the table, the footer and
           pager. Sorting is the table's own, starting oldest first. -->
      <DataTablePage
        compact
        filter-bar
        board-table
        :row-height="56"
        class="queue-table"
        :searchable="false"
        :loading="loading"
        :tabs="queueTabs"
        :status="queueTab"
        @update:status="queueTab = $event"
        :headers="headers"
        :items="visibleRows"
        item-value="key"
        :sort-by="sortBy"
        must-sort
        :page="page"
        @update:page="page = $event"
        :items-per-page="PAGE_SIZE"
        :items-per-page-options="[PAGE_SIZE]"
        :result-noun="currentTab.noun"
        range-summary
        :row-props="() => ({ class: 'queue-row' })"
        :no-data-text="currentTab.empty"
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
            <span class="cell-type" :title="item.type">{{ item.type }}</span>
            <v-chip v-if="item.shortStock" size="x-small" color="error" variant="tonal" class="font-weight-bold" :title="`${item.onHand} on hand`">Short stock</v-chip>
          </div>
        </template>
        <template #item.status="{ item }">
          <StatusPill :status="item.status" />
        </template>
      </DataTablePage>
    </section>

    <DashboardCharts :history="history" :top="top" :loading="loading" />

  </v-container>
</template>

<script setup>
import { ref, reactive, onMounted, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import StatusPill from '@/components/StatusPill.vue'
import DashboardCharts from '@/components/DashboardCharts.vue'
import DataTablePage from '@/components/DataTablePage.vue'
import '@/components/dashboard.css'
import { pluralize, waitTone } from '@/composables/adminUi'
import { useCachedFetch } from '@/composables/useCachedFetch'
import { BORROWING_STATUSES } from '@/composables/borrowingStatus'
import { isAmbulanceRequest } from '@/composables/useRequestFetch'
import { requesterName } from '@/composables/requestDisplay'
import { useCurrentAdmin, adminFirstName } from '@/composables/useCurrentAdmin'
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
// Skeletons only while something has nothing cached; a revisit paints the last numbers
// at once and swaps them when the refetch lands.
const { get, loading } = useCachedFetch()
const loadError = ref('')
// Which lists arrived. A 403 or 500 on one must not read as "nothing open".
const loaded = reactive({ services: false, borrowings: false })

const QUEUE_TABS = [
  { value: 'services', title: 'Services', test: (r) => r.kind === 'service', hint: 'Resident requests still open, oldest first', empty: 'No open service requests', to: '/manage-requests', noun: 'services' },
  { value: 'ambulance', title: 'Ambulance', test: (r) => r.kind === 'ambulance' && r.status !== 'Booked', hint: 'Ambulance dispatch requests not yet closed', empty: 'No active dispatch requests', to: '/conduction-requests', noun: 'requests' },
  { value: 'bookings', title: 'Bookings', test: (r) => r.kind === 'ambulance' && r.status === 'Booked', hint: 'Scheduled ambulance bookings', empty: 'No scheduled bookings', to: '/conduction-requests', noun: 'bookings' },
  { value: 'borrowing', title: 'Borrowing', test: (r) => r.kind === 'borrow', hint: 'Open equipment loans', empty: 'No open loans', to: '/borrowings', noun: 'loans' },
]
const KIND_ICONS = { service: 'mdi-clipboard-text-outline', ambulance: 'mdi-ambulance', borrow: 'mdi-toolbox-outline' }
const KIND_ROUTES = { service: '/manage-requests', ambulance: '/conduction-requests', borrow: '/borrowings' }

const PAGE_SIZE = 5
const queueTab = ref('services')
const page = ref(1)
// must-sort on the table: a header toggles ascending and descending, never off.
const sortBy = ref([{ key: 'filedAt', order: 'asc' }])

const headers = [
  { title: 'Time filed', key: 'filedAt', sortable: true, width: '24%' },
  { title: 'Head of the family', key: 'name', sortable: true },
  { title: 'Request type', key: 'type', sortable: false },
  { title: 'Status', key: 'status', sortable: false },
]

// A tab change is a new list; page 3 of the last one would be blank.
watch(queueTab, () => { page.value = 1 })

// ---- The queue ----------------------------------------------------------

const SERVICE_TERMINAL = new Set(['Resolved', 'Cancelled', 'Disapproved'])
const BORROW_TERMINAL = new Set(BORROWING_STATUSES.filter((s) => s.terminal).map((s) => s.status))

const fmtFiled = (ms) => new Date(ms).toLocaleString('en-US', {
  month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
})

// Only a Pending request is waiting on staff; later statuses have moved on.
const waitNote = (status, ms) => {
  if (status !== 'Pending') {
    return { note: '', noteTone: 'muted' }
  }
  const days = Math.max(0, Math.floor((Date.now() - ms) / DAY_MS))
  return { note: `${pluralize(days, 'day')} waiting`, noteTone: waitTone(days) }
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
    ...(late ? { note: `${pluralize(daysPastDue(b.due_date), 'day')} overdue`, noteTone: 'error' } : waitNote(b.status, filedAt)),
  }
}

const list = (body) => {
  const rows = body.data || body
  return Array.isArray(rows) ? rows : null
}

// What each request last returned: undefined until it answers, null if it failed.
const src = { dash: undefined, services: undefined, borrowings: undefined, trips: undefined }

const apply = () => {
  const { dash, services, borrowings, trips: tripList } = src
  if (dash) {
    systemLogs.value = dash.systemLogs || []
    followUps.value = dash.followUps || []
    responders.value = dash.responders ?? null
    units.value = dash.units ?? null
    top.value = dash.charts?.pieByPeriod?.month ?? null
  }
  trips.value = tripList ?? null
  rows.value = [
    ...(services || []).filter((r) => !SERVICE_TERMINAL.has(r.status)).map((r) => serviceRow(r)),
    ...(borrowings || []).filter((b) => !BORROW_TERMINAL.has(b.status)).map((b) => borrowRow(b)),
  ]
  history.value = (services || []).map((r) => ({
    filedAt: new Date(r.created_at).getTime(),
    resolvedAt: r.resolved_at ? new Date(r.resolved_at).getTime() : null,
  }))
  loaded.services = !!services
  loaded.borrowings = !!borrowings
}

// A list is allowed to fail: an account without the section is answered 403, and
// its queue is simply missing that kind of row.
const pull = (key, path, select) =>
  get(path, { onData: (body) => { src[key] = select(body); apply() } })
    .catch(() => { src[key] = null; apply() })

const fetchDashboardData = async () => {
  loadError.value = ''
  try {
    await Promise.all([
      get('/admin/dashboard', { onData: (body) => { src.dash = body; apply() } }),
      pull('services', '/admin/service-requests', list),
      pull('borrowings', '/borrowings', list),
      pull('trips', '/conduction-requests', list),
    ])
    const missing = [!loaded.services && 'resident requests and ambulance bookings', !loaded.borrowings && 'equipment loans'].filter(Boolean)
    if (missing.length > 0) loadError.value = `Could not load ${missing.join(' or ')}. The list below is incomplete.`
  } catch (error) {
    console.error('Failed to load dashboard:', error)
    loadError.value = 'The dashboard could not be loaded.'
  }
}

const currentTab = computed(() => QUEUE_TABS.find((t) => t.value === queueTab.value))
const tabCounts = computed(() => Object.fromEntries(QUEUE_TABS.map((t) => [t.value, rows.value.filter((r) => t.test(r)).length])))
const visibleRows = computed(() => rows.value.filter((r) => currentTab.value.test(r)))
// The shared table's tabs, with the counts it shows.
const queueTabs = computed(() => QUEUE_TABS.map((t) => ({ value: t.value, label: t.title, count: tabCounts.value[t.value] })))
const today = new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' })

// Each page opens the request itself from ?request=<id>.
const rowLink = (item) => ({ path: KIND_ROUTES[item.kind], query: { request: item.id } })
const openRow = (item) => router.push(rowLink(item))

const overdueRows = computed(() => rows.value.filter((r) => r.overdue))

// Left the office and not back yet, per the trip log.
const tripsOut = computed(() => (trips.value || []).filter((t) => t.departed_office_at && !t.returned_office_at).length)

// Ambulance requests still waiting on staff (the count the welcome band used to show).
const pendingAmbulance = computed(() => rows.value.filter((r) => r.kind === 'ambulance' && r.status === 'Pending').length)

// Which cards this account gets, known before anything loads: the server always
// sends units and responders; pending requests and overdue need their section. The
// skeleton counts this, the real cards read it.
const kpiShown = computed(() => ({ units: true, responders: true, pending: can('ambulance'), overdue: can('borrowings') }))
const kpiCount = computed(() => Object.values(kpiShown.value).filter(Boolean).length)

// Number colour: red for a problem, amber for nothing left to send out.
const kpis = computed(() => {
  const link = (section, to) => (can(section) ? to : undefined)
  const emptyTone = (free) => (free === 0 ? 'warning' : 'default')
  const items = []
  if (kpiShown.value.units && units.value) items.push({ label: 'Available units', icon: 'mdi-ambulance', accent: 'primary', value: units.value.free, total: units.value.total, tone: emptyTone(units.value.free), caption: trips.value ? `${tripsOut.value} on trips` : '', captionTo: link('ambulance', { path: '/conduction-requests', query: { status: 'Responding' } }) })
  if (kpiShown.value.responders && responders.value) items.push({ label: 'Available responders', icon: 'mdi-account-hard-hat', accent: 'info', value: responders.value.available, total: responders.value.total, tone: emptyTone(responders.value.available), to: link('responders', '/responders') })
  if (kpiShown.value.pending && loaded.services) items.push({ label: 'Pending ambulance requests', icon: 'mdi-clock-outline', accent: 'warning', value: pendingAmbulance.value, tone: 'default', to: link('ambulance', '/conduction-requests') })
  if (kpiShown.value.overdue && loaded.borrowings) {
    const n = overdueRows.value.length
    items.push({ label: 'Overdue borrowing', icon: 'mdi-alert-circle-outline', accent: 'error', value: n, tone: n > 0 ? 'error' : 'default', to: link('borrowings', { path: '/borrowings', query: { overdue: '1' } }) })
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
/* Placeholders sized to the real card: 40px icon, 13px label, 32px value. */
.kpi-skel-icon {
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: 8px;
}
.kpi-skel-label {
  width: 60%;
  height: 13px;
  margin: 3px 0;
}
.kpi-skel-value {
  width: 72px;
  height: 35px;
  margin-top: 6px;
}
/* The welcome sentence, on the dark band. */
.skel-summary {
  --skel-bg: rgba(var(--v-theme-on-secondary), 0.16);
  --skel-shine: rgba(255, 255, 255, 0.12);
  display: inline-block;
  vertical-align: middle;
  width: min(420px, 80%);
  height: 15px;
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
/* Open requests (Dashboard board): a section header, then the shared table. */
.open-requests { display: flex; flex-direction: column; gap: 12px; margin-bottom: 24px; }
.open-head { display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 12px; }
.open-title { margin: 0; font-size: 18.72px; line-height: 28px; font-weight: 600; }
.open-sub { font-size: 14px; line-height: 20px; color: var(--dash-muted); }
.open-link { font-size: 14px; font-weight: 700; color: rgb(var(--v-theme-primary-strong)); text-decoration: none; }
.open-link:hover { text-decoration: underline; }
.queue-table :deep(.queue-row) { cursor: pointer; }
.cell-type { font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cell-primary {
  font-size: 14px;
  font-weight: 700;
  color: var(--dash-text);
}
.cell-primary,
.cell-secondary {
  overflow: hidden;
  text-overflow: ellipsis;
}
.cell-secondary {
  font-size: 12px;
  line-height: 16px;
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
/* KPI cards (Dashboard board): 44px tile, uppercase label, 32px value, thin bar. */
.kpi-card { align-items: flex-start; gap: 16px; }
.kpi-grid { gap: 16px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
.kpi-main { flex: 1; min-width: 0; }
.kpi-tile {
  flex: none; display: grid; place-items: center; width: 44px; height: 44px; border-radius: 10px;
  background: rgba(var(--v-theme-primary), 0.14); color: rgb(var(--v-theme-primary-strong));
}
.kpi-tile--info { background: rgba(21, 95, 168, 0.12); color: #155FA8; }
.kpi-tile--warning { background: rgba(245, 124, 0, 0.14); color: #8A4B00; }
.kpi-caption { margin-top: 6px; font-size: 12px; line-height: 16px; color: var(--dash-muted); }
.kpi-caption--link { display: block; text-decoration: none; }
.kpi-caption--link:hover { text-decoration: underline; color: rgb(var(--v-theme-primary-strong)); }
.kpi-caption--link:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 2px; border-radius: 4px; }
/* A card that is a link lifts a little on hover; keyboard focus is the outline below. */
.kpi-link { transition: box-shadow var(--motion-hover) ease; }
.kpi-link:hover { box-shadow: 0 2px 4px rgba(var(--v-theme-on-surface), 0.06), 0 10px 24px rgba(var(--v-theme-on-surface), 0.12) !important; }
.kpi-tile--error { background: rgba(211, 47, 47, 0.12); color: #B3261E; }
.kpi-label { font-size: 12px; line-height: 16px; font-weight: 700; letter-spacing: 0.06em; }
.kpi-valuerow { display: flex; align-items: baseline; gap: 4px; margin-top: 4px; }
.kpi-value { margin-top: 0; font-size: 32px; line-height: 40px; }
.kpi-total { font-size: 16px; font-weight: 600; color: var(--dash-muted); }
.kpi-bar { height: 6px; margin-top: 10px; border-radius: 999px; background: rgba(var(--v-theme-on-surface), 0.08); overflow: hidden; }
.kpi-bar span { display: block; height: 100%; border-radius: 999px; background: rgb(var(--v-theme-primary)); }
.kpi-bar .kpi-bar--info { background: #1976D2; }

/* Welcome band: the board's gradient, an overline, and three attention chips. */
.dash-band {
  padding: 28px 32px;
  background: linear-gradient(120deg, #0A2620 0%, #12403A 100%);
  color: #fff;
}
.dash-overline { font-size: 12px; line-height: 16px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: rgba(255, 255, 255, 0.7); }
.dash-title { margin: 4px 0 0; font-size: 28px; line-height: 36px; font-weight: 700; letter-spacing: -0.48px; color: #fff; }
.band-btn { width: 44px; height: 44px; border: 1px solid rgba(255, 255, 255, 0.4) !important; border-radius: 50% !important; color: #fff; }
.band-avatar { background: rgba(255, 255, 255, 0.16); color: #fff; }
</style>
