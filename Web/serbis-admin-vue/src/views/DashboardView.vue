<template>
  <v-container fluid class="dashboard-bg">

    <PageHeader
      title="Dashboard"
    >
      <template v-slot:actions>
        <!-- The bell carries the activity log and the follow-up calls. An account
             holding none of the sections those come from would open an empty
             list, so it is not drawn. -->
        <v-menu v-if="showBell" location="bottom end">
          <template v-slot:activator="{ props }">
            <v-btn icon="mdi-bell-outline" variant="outlined" v-bind="props" aria-label="System notifications">
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

        <v-avatar color="primary" size="44" class="cursor-pointer font-weight-bold text-white">J</v-avatar>
      </template>
    </PageHeader>

    <!-- KPI strip. A card that counts requests filters the queue below; the
         one that does not (vehicles) still opens its own page. The
         grid is CSS rather than v-col because the card count is not fixed:
         the ambulance and overdue cards only arrive when there is something
         to act on, and five cards do not divide twelve columns. -->
    <v-row v-if="loading" class="mb-2">
      <v-col cols="12"><v-skeleton-loader type="card" height="88"></v-skeleton-loader></v-col>
    </v-row>
    <div v-else-if="cards.length > 0" class="kpi-grid mb-4" :style="{ '--kpi-count': cards.length }">
      <v-card
        v-for="(stat, i) in cards" :key="stat.title"
        elevation="0" rounded="xl" class="soft-card stagger-item kpi-tile pa-3 h-100 d-flex align-center"
        :class="{ 'cursor-pointer': isActionable(stat), 'kpi-tile--active': isActiveKpi(stat) }"
        :style="{ '--stagger-i': i }"
        :role="isActionable(stat) ? 'button' : undefined"
        :tabindex="isActionable(stat) ? 0 : undefined"
        :aria-pressed="KPI_FILTERS[stat.title] ? isActiveKpi(stat) : undefined"
        @click="onKpi(stat)"
        @keydown.enter="onKpi(stat)"
      >
        <v-avatar :color="stat.color || 'primary'" variant="tonal" size="40" rounded="lg" class="mr-3 flex-shrink-0">
          <v-icon :color="stat.color || 'primary'" size="20">{{ stat.icon || 'mdi-chart-arc' }}</v-icon>
        </v-avatar>
        <div class="min-width-0">
          <div class="text-h6 font-weight-black lh-1">{{ displayValues[stat.title] ?? stat.value }}</div>
          <div class="text-caption font-weight-bold text-medium-emphasis kpi-label" :title="stat.title">{{ stat.label || stat.title }}</div>
          <div
            v-if="stat.delta != null"
            class="text-caption font-weight-bold"
            :class="deltaClass(stat.delta)"
            :title="stat.title === 'Equipment Overdue' ? 'Loans that fell due in the last 7 days, against the 7 before' : 'Filed in the last 7 days, against the 7 before'"
          >{{ deltaText(stat.delta) }}</div>
        </div>
      </v-card>
    </div>

    <!-- The work queue. Resident requests, ambulance bookings and equipment
         loans share one table because staff triage by how long something has
         waited, not by which form it came from; the tabs cut it back apart
         the way the sidebar does. Only open items are listed here: the full
         history lives on each page. -->
    <v-row>
      <v-col cols="12" lg="8">
    <v-card elevation="0" rounded="xl" class="soft-card queue-card">
      <v-card-item class="pb-0">
        <div class="d-flex justify-space-between align-center flex-wrap gap-2">
          <div>
            <v-card-title class="text-body-1 font-weight-bold pa-0">Open Requests</v-card-title>
            <v-card-subtitle class="pa-0">Oldest first, so the longest wait is at the top</v-card-subtitle>
          </div>
          <v-chip
            v-if="statusFilter"
            closable
            size="small"
            color="primary"
            variant="tonal"
            @click:close="statusFilter = null"
          >Status: {{ statusFilter }}</v-chip>
          <v-chip
            v-if="bucketFilter"
            closable
            size="small"
            color="primary"
            variant="tonal"
            @click:close="bucketFilter = null"
          >Waiting: {{ bucketFilter }}</v-chip>
        </div>
      </v-card-item>

      <v-tabs v-model="queueTab" color="primary" density="compact" class="px-4">
        <v-tab v-for="t in QUEUE_TABS" :key="t.value" :value="t.value" class="text-none font-weight-bold">
          {{ t.title }}
          <v-chip
            size="x-small"
            class="ml-2 font-weight-bold"
            :color="t.value === 'overdue' && tabCounts[t.value] > 0 ? 'error' : undefined"
            variant="tonal"
          >{{ tabCounts[t.value] }}</v-chip>
        </v-tab>
      </v-tabs>
      <v-divider></v-divider>

      <v-alert v-if="loadError" type="warning" variant="tonal" density="compact" class="ma-4">{{ loadError }}</v-alert>

      <v-data-table
        v-model:sort-by="sortBy"
        v-model:expanded="expanded"
        :headers="headers"
        :items="visibleRows"
        :loading="loading"
        item-value="key"
        :items-per-page="8"
        no-data-text="Nothing open here"
        class="queue-table"
      >
        <template #item.filedAt="{ item }">
          <div class="text-body-2 font-weight-bold">{{ fmtFiled(item.filedAt) }}</div>
          <div v-if="item.note" class="text-caption" :class="item.overdue ? 'text-error font-weight-bold' : 'text-medium-emphasis'">
            {{ item.note }}
          </div>
        </template>
        <template #item.name="{ item }">
          <div class="text-body-2 font-weight-bold">{{ item.name }}</div>
        </template>
        <template #item.type="{ item }">
          <div class="d-flex align-center gap-2">
            <v-icon size="16" class="text-medium-emphasis">{{ KIND_ICONS[item.kind] }}</v-icon>
            <span class="text-body-2">{{ item.type }}</span>
          </div>
        </template>
        <template #item.status="{ item }">
          <StatusPill :status="item.status" solid />
        </template>
        <template #item.actions="{ item }">
          <v-btn
            size="x-small"
            variant="tonal"
            color="primary"
            class="text-none font-weight-bold"
            :prepend-icon="isExpanded(item.key) ? 'mdi-chevron-up' : 'mdi-chevron-down'"
            :aria-expanded="isExpanded(item.key)"
            @click="toggleExpanded(item.key)"
          >{{ isExpanded(item.key) ? 'Hide' : 'Details' }}</v-btn>
        </template>
        <template #expanded-row="{ columns, item }">
          <tr>
            <td :colspan="columns.length" class="expanded-cell">
              <div class="d-flex flex-wrap gap-6 align-center">
                <div v-for="d in item.details" :key="d.label" class="min-width-0">
                  <div class="text-caption text-medium-emphasis">{{ d.label }}</div>
                  <div class="text-body-2 font-weight-bold">{{ d.value }}</div>
                </div>
                <v-spacer></v-spacer>
                <v-btn
                  size="small"
                  variant="text"
                  color="primary"
                  class="text-none font-weight-bold"
                  append-icon="mdi-arrow-right"
                  @click="goTo(KIND_ROUTES[item.kind])"
                >Open in {{ KIND_PAGES[item.kind] }}</v-btn>
              </div>
            </td>
          </tr>
        </template>
      </v-data-table>
    </v-card>
      </v-col>
      <v-col cols="12" lg="4">
        <DashboardRail :rows="rows" />
      </v-col>
    </v-row>

    <DashboardCharts
      :history="history"
      :rows="rows"
      :fleet="fleet"
      :active-bucket="bucketFilter"
      :loading="loading"
      @pick-bucket="onBucket"
    />

  </v-container>
</template>

<script setup>
import { ref, reactive, onMounted, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import StatusPill from '@/components/StatusPill.vue'
import DashboardRail from '@/components/DashboardRail.vue'
import DashboardCharts from '@/components/DashboardCharts.vue'
import { API_BASE } from '@/config/api'
import { authHeaders } from '@/composables/adminUi'
import { BORROWING_STATUSES } from '@/composables/borrowingStatus'
import { isAmbulanceRequest } from '@/composables/useRequestFetch'
import { requesterName, requesterPhone, requesterBarangay } from '@/composables/requestDisplay'
import { useCurrentAdmin } from '@/composables/useCurrentAdmin'
import { DAY_MS, waitBucket, weekDelta } from '@/composables/dashboardTrends'

const router = useRouter()
const goTo = (route) => router.push(route)

// The server already leaves out the lists an account may not see (activity feed,
// follow-up calls); this only decides whether the bell that shows them is worth
// drawing at all.
const { can } = useCurrentAdmin()
const showBell = computed(() => can('logs') || can('borrowings') || can('ambulance'))

const kpiStats = ref([])
const systemLogs = ref([])
const followUps = ref([])
const rows = ref([])
// Every service request, for the chart row: { filedAt, resolvedAt, service }.
const history = ref([])
// Filing times of every request, closed ones too: the 7-day delta is about
// intake, and `rows` holds open items only.
const filedTimes = reactive({ service: [], ambulance: [], borrow: [] })
const vehicles = ref(null)
const loading = ref(true)
const loadError = ref('')
// Which lists arrived. A 403 or 500 on one must not read as "nothing open".
const loaded = reactive({ services: false, borrowings: false })

// Which queue each request-counting card narrows to. Cards not listed here
// (vehicles) count something that is not a request and keep opening their own
// page through `route`.
// `source` is the list the card is counted from, so a card and the tab it
// opens always use the same rows and statuses (Pending, Booked, Responding).
// The server's own figure differs: it counts Pending only, ambulance bookings
// included, and counts ambulance trips not yet departed, which is not what the
// queue lists.
const KPI_FILTERS = {
  'Pending Service Requests': { tab: 'service', status: null, source: 'services', label: 'Open Service Requests' },
  'Pending Borrow Requests': { tab: 'borrow', status: 'Pending', source: 'borrowings' },
  'Pending Ambulance Requests': { tab: 'ambulance', status: null, source: 'services', label: 'Open Ambulance Requests' },
  'Equipment Overdue': { tab: 'overdue', status: null, source: 'borrowings' },
}

const QUEUE_TABS = [
  { value: 'all', title: 'All Open' },
  { value: 'service', title: 'Service Requests' },
  { value: 'ambulance', title: 'Ambulance' },
  { value: 'borrow', title: 'Borrowing' },
  { value: 'overdue', title: 'Overdue' },
]
const KIND_ICONS = { service: 'mdi-clipboard-text-outline', ambulance: 'mdi-ambulance', borrow: 'mdi-toolbox-outline' }
const KIND_ROUTES = { service: '/manage-requests', ambulance: '/conduction-requests', borrow: '/borrowings' }
const KIND_PAGES = { service: 'Resident Requests', ambulance: 'Ambulance Dispatch', borrow: 'Equipment Borrowing' }

const queueTab = ref('all')
const statusFilter = ref(null)
const bucketFilter = ref(null)
const sortBy = ref([{ key: 'filedAt', order: 'asc' }])
const expanded = ref([])

const headers = [
  { title: 'Time Filed', key: 'filedAt', sortable: true },
  { title: 'Head of the Family', key: 'name', sortable: true },
  { title: 'Request Type', key: 'type', sortable: true },
  { title: 'Status', key: 'status', sortable: true },
  { title: 'Quick Actions', key: 'actions', sortable: false, align: 'end', width: 110 },
]

const isActionable = (stat) => !!(KPI_FILTERS[stat.title] || stat.route)
const isActiveKpi = (stat) => {
  const f = KPI_FILTERS[stat.title]
  return !!f && queueTab.value === f.tab && statusFilter.value === f.status
}
const onKpi = (stat) => {
  const f = KPI_FILTERS[stat.title]
  if (!f) {
    if (stat.route) goTo(stat.route)
    return
  }
  // Clicking the active card again clears it, so it behaves like a toggle.
  if (isActiveKpi(stat)) {
    queueTab.value = 'all'
    statusFilter.value = null
    return
  }
  // Status goes second: the tab watcher below clears a stale status on tab change.
  queueTab.value = f.tab
  statusFilter.value = f.status
}

// A tab change is a new question; a status narrowed by a card would silently
// carry over and hide rows the tab name promises. flush 'sync' so a card's own
// write to statusFilter, made right after the tab, is not undone by this.
watch(queueTab, () => { statusFilter.value = null; bucketFilter.value = null }, { flush: 'sync' })

// The waiting chart counts requests, so picking a bar starts from All Open.
// Picking the same bar again clears it.
const onBucket = (bucket) => {
  const again = bucketFilter.value === bucket
  queueTab.value = 'all'
  bucketFilter.value = again ? null : bucket
}

// More arriving is more work, so up reads as a warning and down as relief.
const deltaText = (d) => {
  if (d === 0) {
    return '– same as prev 7 days'
  }
  return `${d > 0 ? '▲' : '▼'} ${Math.abs(d)} vs prev 7 days`
}
const deltaClass = (d) => {
  if (d === 0) {
    return 'text-medium-emphasis'
  }
  return d > 0 ? 'text-warning-strong' : 'text-success-strong'
}

const isExpanded = (key) => expanded.value.includes(key)
const toggleExpanded = (key) => {
  expanded.value = isExpanded(key) ? expanded.value.filter((k) => k !== key) : [...expanded.value, key]
}

// Count-up animation for the headline numbers
const displayValues = reactive({})
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches

const animateValue = (key, target) => {
  const numTarget = Number(String(target).replace(/,/g, '')) || 0
  if (reduceMotion) {
    displayValues[key] = target
    return
  }
  const duration = 600
  const start = performance.now()
  const step = (now) => {
    const progress = Math.min((now - start) / duration, 1)
    const eased = 1 - Math.pow(1 - progress, 3)
    displayValues[key] = Math.round(numTarget * eased).toLocaleString()
    if (progress < 1) requestAnimationFrame(step)
  }
  requestAnimationFrame(step)
}

// ---- The queue ----------------------------------------------------------

const SERVICE_TERMINAL = new Set(['Resolved', 'Cancelled', 'Disapproved'])
const BORROW_TERMINAL = new Set(BORROWING_STATUSES.filter((s) => s.terminal).map((s) => s.status))

const fmtFiled = (ms) => new Date(ms).toLocaleString('en-PH', {
  month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
})
const fmtWhen = (value) => (value ? fmtFiled(new Date(value).getTime()) : '')

// Only a Pending request is waiting on staff; later statuses have moved on.
const waitNote = (status, ms) => {
  if (status !== 'Pending') return ''
  return `${Math.max(0, Math.floor((Date.now() - ms) / DAY_MS))}d waiting`
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
  const ambulance = isAmbulanceRequest(r)
  const details = [
    { label: 'Contact', value: requesterPhone(r) },
    { label: 'Barangay', value: requesterBarangay(r) },
  ]
  if (ambulance && r.scheduled_at) details.push({ label: 'Scheduled for', value: fmtWhen(r.scheduled_at) })
  const filedAt = new Date(r.created_at).getTime()
  return {
    key: `svc-${r.request_id}`,
    kind: ambulance ? 'ambulance' : 'service',
    filedAt,
    // Null until the first status move; the rail ages Responding/Booked from here.
    statusAt: new Date(r.status_changed_at ?? r.created_at).getTime(),
    name: requesterName(r),
    type: r.service?.service_name || 'Other',
    status: r.status,
    residentId: r.resident_id,
    unit: r.vehicle?.unit_identifier,
    overdue: false,
    note: waitNote(r.status, filedAt),
    details,
  }
}

const borrowRow = (b) => {
  const item = b.equipment?.item_name || b.other_equipment_text || 'Unknown item'
  const details = [
    { label: 'Contact', value: requesterPhone(b) },
    { label: 'Barangay', value: requesterBarangay(b) },
    { label: 'Quantity', value: String(b.quantity ?? 1) },
  ]
  if (b.due_date) details.push({ label: 'Due back', value: b.due_date })
  const filedAt = new Date(b.created_at).getTime()
  const late = b.status === 'Released' && daysPastDue(b.due_date) > 0
  return {
    key: `bor-${b.borrow_id}`,
    kind: 'borrow',
    filedAt,
    name: requesterName(b),
    type: item,
    status: b.status,
    residentId: b.resident_id,
    phone: requesterPhone(b),
    daysLate: daysPastDue(b.due_date),
    overdue: late,
    dueAt: dueDay(b.due_date)?.getTime(),
    note: late ? `${daysPastDue(b.due_date)}d overdue` : waitNote(b.status, filedAt),
    details,
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
    const [dash, services, borrowings, fleet] = await Promise.all([
      fetch(`${API_BASE}/admin/dashboard`, { headers: authHeaders() }),
      fetchList('/admin/service-requests'),
      fetchList('/borrowings'),
      fetchList('/vehicles'),
    ])
    if (!dash.ok) throw new Error('Network response error')

    const data = await dash.json()
    kpiStats.value = data.kpiStats || []
    systemLogs.value = data.systemLogs || []
    followUps.value = data.followUps || []

    rows.value = [
      ...(services || []).filter((r) => !SERVICE_TERMINAL.has(r.status)).map((r) => serviceRow(r)),
      ...(borrowings || []).filter((b) => !BORROW_TERMINAL.has(b.status)).map((b) => borrowRow(b)),
    ]
    vehicles.value = fleet
    history.value = (services || []).map((r) => ({
      filedAt: new Date(r.created_at).getTime(),
      resolvedAt: r.resolved_at ? new Date(r.resolved_at).getTime() : null,
      service: r.service?.service_name || 'Other',
    }))
    filedTimes.service = (services || []).filter((r) => !isAmbulanceRequest(r)).map((r) => new Date(r.created_at).getTime())
    filedTimes.ambulance = (services || []).filter(isAmbulanceRequest).map((r) => new Date(r.created_at).getTime())
    filedTimes.borrow = (borrowings || []).map((b) => new Date(b.created_at).getTime())
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

const rowsForTab = (tab) => rows.value.filter((r) => {
  if (tab === 'all') return true
  if (tab === 'overdue') return r.overdue
  return r.kind === tab
})

const tabCounts = computed(() => Object.fromEntries(QUEUE_TABS.map((t) => [t.value, rowsForTab(t.value).length])))

const visibleRows = computed(() =>
  rowsForTab(queueTab.value)
    .filter((r) => !statusFilter.value || r.status === statusFilter.value)
    .filter((r) => !bucketFilter.value || (r.kind !== 'borrow' && waitBucket(r.filedAt) === bucketFilter.value))
)

// A unit sitting on a Booked ambulance request is spoken for, though the
// vehicle row stays 'Available' until it actually leaves.
const fleet = computed(() => {
  if (vehicles.value === null) return null
  const assigned = new Set(rows.value.filter((r) => r.kind === 'ambulance' && r.status === 'Booked' && r.unit).map((r) => r.unit))
  const onStatus = (status) => vehicles.value.filter((v) => v.status === status).length
  const availableStatus = vehicles.value.filter((v) => v.status === 'Available')
  const assignedCount = availableStatus.filter((v) => assigned.has(v.unit_identifier)).length
  return {
    total: vehicles.value.length,
    available: availableStatus.length - assignedCount,
    assigned: assignedCount,
    onTrip: onStatus('Dispatched'),
    maintenance: onStatus('Maintenance'),
  }
})

// Intake trend for a card. Overdue has no filing date worth trending, so it
// counts loans that fell due in each week and are still out. The fleet has no
// history to compare, so it gets none.
const deltaFor = (f) => {
  if (!f || !loaded[f.source]) return null
  const times = f.tab === 'overdue' ? rowsForTab('overdue').map((r) => r.dueAt) : filedTimes[f.tab]
  return weekDelta(times)
}

// The server's cards, with the request-counting ones recounted from the queue.
// Total Residents is dropped: it is not a queue, and the Users page has it.
const cards = computed(() => kpiStats.value.filter((stat) => stat.title !== 'Total Residents').map((stat) => {
  if (stat.title === 'Available Vehicles' && fleet.value) {
    return { ...stat, value: `${fleet.value.available}/${fleet.value.total}`, label: 'Fleet available' }
  }
  const f = KPI_FILTERS[stat.title]
  if (!f || !loaded[f.source]) return stat
  const count = rowsForTab(f.tab).filter((r) => !f.status || r.status === f.status).length
  return { ...stat, value: String(count), label: f.label, delta: deltaFor(f) }
}))

watch(cards, (stats) => {
  for (const stat of stats) {
    // "3/14" is not a number to count up to.
    if (String(stat.value).includes('/')) displayValues[stat.title] = stat.value
    else animateValue(stat.title, stat.value)
  }
})

onMounted(fetchDashboardData)
</script>

<style scoped>
.dashboard-bg {
  background-color: rgb(var(--v-theme-background));
}
.min-width-0 {
  min-width: 0;
}
.gap-6 {
  gap: 24px;
}

/* Soft UI Evolution: layered shadow depth instead of flat borders, theme-aware */
.soft-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  box-shadow: 0 1px 2px rgba(var(--v-theme-on-surface), 0.04), 0 4px 14px rgba(var(--v-theme-on-surface), 0.08);
  transition: transform 220ms cubic-bezier(0.16, 1, 0.3, 1), box-shadow 220ms cubic-bezier(0.16, 1, 0.3, 1);
}
.soft-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 4px 10px rgba(var(--v-theme-on-surface), 0.06), 0 12px 24px rgba(var(--v-theme-on-surface), 0.14);
}
/* The queue is a working surface, not a tile: it should not lift under the cursor. */
.queue-card:hover {
  transform: none;
}

.lh-1 {
  line-height: 1;
}

.kpi-tile {
  min-height: 72px;
}
.kpi-tile--active {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}

/* Wraps rather than truncating. A tile is ~306px at 1920 with five cards and
   ~175px at the tablet tier, and "Pending Ambulance Requests" fits one line of
   neither — ellipsing the one card that only appears when it needs acting on is
   the wrong trade. Three lines is what the longest title needs at the narrowest
   tier; the clamp only bites past that, so wider tiles still settle at one or
   two and the grid keeps every tile the same height. */
.kpi-label {
  display: -webkit-box;
  -webkit-line-clamp: 3;
  line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
  line-height: 1.25;
}

/* Two up on a phone, three on a tablet, then one row of whatever arrived —
   the same 600/960 breakpoints the old cols="6" sm="4" md="2" used, and the
   8px gap a dense v-row produced (two 4px gutters). */
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

.queue-table :deep(th) {
  font-weight: 700;
  white-space: nowrap;
}
.expanded-cell {
  background: rgba(var(--v-theme-on-surface), 0.04);
  padding: 12px 16px;
}

.stagger-item {
  animation: dashFadeUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
  animation-delay: calc(var(--stagger-i, 0) * 60ms);
}

@keyframes dashFadeUp {
  from {
    opacity: 0;
    transform: translateY(12px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@media (prefers-reduced-motion: reduce) {
  .soft-card,
  .soft-card:hover {
    transition: none;
    transform: none;
  }
  .stagger-item {
    animation: none;
  }
}
</style>
