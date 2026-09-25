<template>
  <v-container fluid class="dashboard-bg">

    <div class="dash-header d-flex justify-space-between align-start flex-wrap gap-3">
      <div class="min-width-0">
        <h2 class="dash-title">Welcome back, {{ adminFirstName || 'there' }}</h2>
        <!-- Only once every list has arrived: a half-loaded count would read as a calm shift. -->
        <p v-if="summary" class="dash-summary">
          <template v-for="(part, i) in summary" :key="i">
            <b v-if="part.tone" :class="`tone-${part.tone}`">{{ part.text }}</b>
            <template v-else>{{ part.text }}</template>
          </template>
        </p>
      </div>
      <div class="d-flex align-center flex-wrap gap-3">
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
      </div>
    </div>

    <!-- Live strip: what is true right now. Each item opens its own page,
         filtered; an item whose data the account cannot read is left out. -->
    <v-skeleton-loader v-if="loading" type="text" height="64" class="mb-4"></v-skeleton-loader>
    <v-card v-else-if="strip.length > 0" elevation="0" rounded="xl" class="soft-card queue-card live-strip mb-4">
      <v-card
        v-for="item in strip" :key="item.label"
        :to="item.to" variant="text" rounded="0"
        class="live-item d-flex align-center pa-3"
      >
        <v-icon :color="item.warn ? 'error' : 'primary'" size="22" class="mr-3">{{ item.icon }}</v-icon>
        <div class="min-width-0">
          <div class="text-body-1 font-weight-black lh-1" :class="{ 'text-error': item.warn }">{{ item.value }}</div>
          <div class="text-caption font-weight-bold text-medium-emphasis">{{ item.label }}</div>
        </div>
      </v-card>
    </v-card>

    <!-- The work queue, cut by what staff have to do: answer (Backlog), chase
         (Stale), or hand out (Borrowing). Only open items; the full history
         lives on each page. -->
    <v-row>
      <v-col cols="12" lg="8">
    <v-card elevation="0" rounded="xl" class="soft-card queue-card">
      <v-card-item class="pb-0">
        <div class="d-flex justify-space-between align-center flex-wrap gap-2">
          <div>
            <v-card-title class="text-body-1 font-weight-bold pa-0">Open Requests</v-card-title>
            <v-card-subtitle class="pa-0">{{ currentTab.hint }}</v-card-subtitle>
          </div>
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
            :color="t.value === 'stale' && tabCounts[t.value] > 0 ? 'error' : undefined"
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
        :no-data-text="currentTab.empty"
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
            <v-chip v-if="item.shortStock" size="x-small" color="error" variant="tonal" class="font-weight-bold" :title="`${item.onHand} on hand`">Short stock</v-chip>
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
        <DashboardToday
          :rows="rows"
          :trips="tripsOut"
          :conflicts="bookingConflicts"
          :known="{ bookings: loaded.services && can('ambulance'), trips: trips !== null, overdue: loaded.borrowings }"
        />
      </v-col>
    </v-row>

    <DashboardCharts
      :history="history"
      :rows="rows"
      :active-bucket="bucketFilter"
      :loading="loading"
      @pick-bucket="onBucket"
    />

  </v-container>
</template>

<script setup>
import { ref, reactive, onMounted, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import StatusPill from '@/components/StatusPill.vue'
import DashboardToday from '@/components/DashboardToday.vue'
import DashboardCharts from '@/components/DashboardCharts.vue'
import { API_BASE } from '@/config/api'
import { authHeaders } from '@/composables/adminUi'
import { BORROWING_STATUSES } from '@/composables/borrowingStatus'
import { isAmbulanceRequest } from '@/composables/useRequestFetch'
import { requesterName, requesterPhone, requesterBarangay } from '@/composables/requestDisplay'
import { useCurrentAdmin, adminFirstName } from '@/composables/useCurrentAdmin'
import { welcomeSentence } from '@/composables/dashboardWelcome'
import { DAY_MS, waitBucket } from '@/composables/dashboardTrends'

const router = useRouter()
const goTo = (route) => router.push(route)

// The server already leaves out the lists an account may not see (activity feed,
// follow-up calls); this only decides whether the bell that shows them is worth
// drawing at all.
const { can } = useCurrentAdmin()
const showBell = computed(() => can('logs') || can('borrowings') || can('ambulance'))

const systemLogs = ref([])
const followUps = ref([])
const rows = ref([])
// Every service request, for the performance row: { filedAt, resolvedAt, respondedAt, service }.
const history = ref([])
// Only to name the unit on a trip; the strip's count comes from the server.
const vehicles = ref(null)
// { free, total }: not in Maintenance, not out on a trip, no booking in the next 2h.
const units = ref(null)
// Trip records, or null when the account cannot read them.
const trips = ref(null)
// { available, total } across all responders, from the dashboard payload.
const responders = ref(null)
// Booked request ids whose unit overlaps another booking in the next 24h.
const bookingConflicts = ref([])
const loading = ref(true)
const loadError = ref('')
// Which lists arrived. A 403 or 500 on one must not read as "nothing open".
const loaded = reactive({ services: false, borrowings: false })

const STALE_DAYS = 3
const IN_PROGRESS = new Set(['Responding', 'Booked'])
const isStale = (r) => IN_PROGRESS.has(r.status) && Date.now() - r.statusAt > STALE_DAYS * DAY_MS

const QUEUE_TABS = [
  { value: 'backlog', title: 'Backlog', test: (r) => r.kind !== 'borrow' && r.status === 'Pending', hint: 'Pending, oldest first', empty: 'No pending requests' },
  // Aged from the last status change, not filing: staff took these on and nothing closed them.
  { value: 'stale', title: 'Stale', test: (r) => r.kind !== 'borrow' && isStale(r), hint: `Responding or Booked, unchanged for more than ${STALE_DAYS} days`, empty: 'Nothing stale' },
  { value: 'borrow', title: 'Borrowing', test: (r) => r.kind === 'borrow', hint: 'Open equipment loans, oldest first', empty: 'No open loans' },
]
const KIND_ICONS = { service: 'mdi-clipboard-text-outline', ambulance: 'mdi-ambulance', borrow: 'mdi-toolbox-outline' }
const KIND_ROUTES = { service: '/manage-requests', ambulance: '/conduction-requests', borrow: '/borrowings' }
const KIND_PAGES = { service: 'Resident Requests', ambulance: 'Ambulance Dispatch', borrow: 'Equipment Borrowing' }

const queueTab = ref('backlog')
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

// A tab change is a new question; a bucket picked earlier would silently hide
// rows the tab name promises.
watch(queueTab, () => { bucketFilter.value = null }, { flush: 'sync' })

// The aging chart counts the backlog, so picking a bar opens it there.
// Picking the same bar again clears it.
const onBucket = (bucket) => {
  const again = bucketFilter.value === bucket
  queueTab.value = 'backlog'
  bucketFilter.value = again ? null : bucket
}

const isExpanded = (key) => expanded.value.includes(key)
const toggleExpanded = (key) => {
  expanded.value = isExpanded(key) ? expanded.value.filter((k) => k !== key) : [...expanded.value, key]
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
  // status_changed_at is null until the first status move.
  const statusAt = new Date(r.status_changed_at ?? r.created_at).getTime()
  const row = {
    key: `svc-${r.request_id}`,
    requestId: r.request_id,
    kind: ambulance ? 'ambulance' : 'service',
    filedAt,
    statusAt,
    name: requesterName(r),
    type: r.service?.service_name || 'Other',
    status: r.status,
    residentId: r.resident_id,
    unit: r.vehicle?.unit_identifier,
    scheduledAt: r.scheduled_at ? new Date(r.scheduled_at).getTime() : null,
    patient: r.patient_name,
    overdue: false,
    note: waitNote(r.status, filedAt),
    details,
  }
  if (isStale(row)) row.note = `${Math.floor((Date.now() - statusAt) / DAY_MS)}d unchanged`
  return row
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
  // Stock only leaves the shelf on release, so only a loan not yet released can come up short.
  const onHand = b.equipment?.available_quantity
  return {
    onHand,
    shortStock: ['Pending', 'Approved'].includes(b.status) && onHand != null && (b.quantity ?? 1) > onHand,
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
    const [dash, services, borrowings, fleet, tripList] = await Promise.all([
      fetch(`${API_BASE}/admin/dashboard`, { headers: authHeaders() }),
      fetchList('/admin/service-requests'),
      fetchList('/borrowings'),
      fetchList('/vehicles'),
      fetchList('/conduction-requests'),
    ])
    if (!dash.ok) throw new Error('Network response error')

    const data = await dash.json()
    systemLogs.value = data.systemLogs || []
    followUps.value = data.followUps || []
    responders.value = data.responders ?? null
    units.value = data.units ?? null
    bookingConflicts.value = data.bookingConflicts || []
    trips.value = tripList

    rows.value = [
      ...(services || []).filter((r) => !SERVICE_TERMINAL.has(r.status)).map((r) => serviceRow(r)),
      ...(borrowings || []).filter((b) => !BORROW_TERMINAL.has(b.status)).map((b) => borrowRow(b)),
    ]
    vehicles.value = fleet
    history.value = (services || []).map((r) => ({
      filedAt: new Date(r.created_at).getTime(),
      resolvedAt: r.resolved_at ? new Date(r.resolved_at).getTime() : null,
      respondedAt: r.first_responded_at ? new Date(r.first_responded_at).getTime() : null,
      service: r.service?.service_name || 'Other',
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

const visibleRows = computed(() =>
  rows.value
    .filter((r) => currentTab.value.test(r))
    .filter((r) => !bucketFilter.value || waitBucket(r.filedAt) === bucketFilter.value)
)

const overdueRows = computed(() => rows.value.filter((r) => r.overdue))


// Left the office and not back yet, per the trip log.
const tripsOut = computed(() => (trips.value || [])
  .filter((t) => t.departed_office_at && !t.returned_office_at)
  .map((t) => ({
    key: t.conduction_request_id,
    unit: vehicles.value?.find((v) => v.vehicle_id === t.vehicle_id)?.unit_identifier || t.vehicle || 'Unit not recorded',
    departedAt: new Date(t.departed_office_at).getTime(),
  })))

const summary = computed(() => {
  if (loading.value || loadError.value) return null
  const open = (kind, status) => rows.value.filter((r) => r.kind === kind && r.status === status).length
  return welcomeSentence({
    trips: tripsOut.value.length,
    overdue: overdueRows.value.length,
    ambulance: open('ambulance', 'Pending'),
    bookings: open('ambulance', 'Booked'),
    services: open('service', 'Pending'),
    borrowing: open('borrow', 'Pending'),
  })
})

const nextBooking = computed(() => rows.value
  .filter((r) => r.status === 'Booked' && r.scheduledAt > Date.now())
  .toSorted((a, b) => a.scheduledAt - b.scheduledAt)[0])

const fmtShort = (ms) => new Date(ms).toLocaleString('en-PH', { weekday: 'short', hour: 'numeric', minute: '2-digit' })

const strip = computed(() => {
  const link = (section, to) => (can(section) ? to : undefined)
  const items = []
  if (units.value) items.push({ icon: 'mdi-ambulance', label: 'Units free', value: `${units.value.free}/${units.value.total}`, to: link('vehicles', '/vehicles') })
  if (trips.value) items.push({ icon: 'mdi-map-marker-path', label: 'Trips out', value: tripsOut.value.length, to: link('ambulance', { path: '/conduction-requests', query: { status: 'Responding' } }) })
  if (responders.value) items.push({ icon: 'mdi-account-hard-hat', label: 'Responders free', value: `${responders.value.available}/${responders.value.total}`, to: link('responders', '/responders') })
  if (loaded.services) {
    const b = nextBooking.value
    items.push({ icon: 'mdi-calendar-clock', label: 'Next booking', value: b ? `${fmtShort(b.scheduledAt)} · ${b.unit || 'No unit'}` : 'None', warn: !!b && !b.unit, to: link('ambulance', { path: '/conduction-requests', query: { status: 'Booked' } }) })
  }
  if (loaded.borrowings) {
    const n = overdueRows.value.length
    items.push({ icon: 'mdi-alert-circle-outline', label: 'Overdue loans', value: n, warn: n > 0, to: link('borrowings', { path: '/borrowings', query: { overdue: '1' } }) })
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
}
.dash-header {
  margin-bottom: 24px;
}
.dash-title {
  font-size: 28px;
  font-weight: 700;
  line-height: 1.2;
  color: var(--dash-text);
}
.dash-summary {
  margin: 4px 0 0;
  font-size: 15px;
  font-weight: 400;
  color: var(--dash-muted);
}
.dash-summary b {
  font-weight: 700;
}
.dash-summary .tone-default {
  color: var(--dash-text);
}
.dash-summary .tone-warning {
  color: var(--dash-warn);
}
.dash-summary .tone-error {
  color: var(--dash-bad);
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

/* One row on desktop; wraps two-up on a phone. */
.live-strip {
  display: flex;
  flex-wrap: wrap;
}
.live-item {
  flex: 1 1 160px;
}

.queue-table :deep(th) {
  font-weight: 700;
  white-space: nowrap;
}
.expanded-cell {
  background: rgba(var(--v-theme-on-surface), 0.04);
  padding: 12px 16px;
}

@media (prefers-reduced-motion: reduce) {
  .soft-card,
  .soft-card:hover {
    transition: none;
    transform: none;
  }
}
</style>
