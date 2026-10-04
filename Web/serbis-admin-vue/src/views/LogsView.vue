<template>
  <v-container fluid class="fill-height align-start bg-background">
    <div class="logs-page w-100">
        <PageHeader title="Activity logs">
          <template v-slot:subtitle>What staff changed in the panel, and the text blasts that were sent</template>
        </PageHeader>

        <SegmentedTabs
          v-model="activeTab"
          tonal
          :items="[{ value: 'system', label: 'System activity' }, { value: 'sms', label: 'SMS history' }]"
        />

        <div class="filter-bar">
          <v-text-field
            v-model="search"
            prepend-inner-icon="mdi-magnify"
            placeholder="Search logs"
            aria-label="Search logs"
            variant="outlined"
            density="compact"
            hide-details
            rounded="lg"
            class="filter-bar__search logs-search"
          ></v-text-field>
        </div>

        <v-card elevation="0" rounded="xl" class="logs-card bg-surface">
          <v-card-text class="pa-0">
            <v-window v-model="activeTab">
              <v-window-item value="system">
                <!-- v-data-table-server, not v-data-table: the plain component
                     filters and pages the array it is handed, which is exactly
                     what must NOT happen now that the server sends one page.
                     Client-side filtering over a single page is a search box
                     that cannot find a row on page 2 and gives no sign of it. -->
                <v-data-table-server
                  :headers="systemHeaders"
                  :items="systemLogs"
                  :items-length="systemTotal"
                  :items-per-page="itemsPerPage"
                  :page="systemPage"
                  :loading="loading && systemLogs.length > 0"
                  :class="{ 'is-refreshing': loading && systemLogs.length > 0 }"
                  hover
                  hide-default-footer
                  :key="loading && systemLogs.length === 0 ? 'loading' : 'ready'"
                  class="bg-transparent logs-table board-table table-fade"
                  @update:options="onSystemOptions"
                >
                  <template v-if="loading && systemLogs.length === 0" #body>
                    <SkeletonRows :rows="itemsPerPage" :columns="systemHeaders.length" />
                  </template>
                  <template v-slot:item.rowNumber="{ index }">
                    <span class="row-number">{{ systemRowNumber(index) }}</span>
                  </template>

                  <template v-slot:item.action="{ item }">
                    <StatusPill :status="actionKey(item.action)" :label="item.action" />
                  </template>
                  <template v-slot:item.created_at="{ item }">
                    {{ formatLogDate(item.created_at) }}
                  </template>
                  <template v-slot:item.description="{ item }">
                    <span class="cell-truncate cell-muted" :title="item.description">{{ item.description }}</span>
                  </template>
                </v-data-table-server>
                <TableFooter :page="systemPage" :per-page="itemsPerPage" :total="systemTotal" @update:page="setSystemPage" @update:per-page="setPerPage" />
              </v-window-item>

              <v-window-item value="sms">
                <v-data-table-server
                  :headers="smsHeaders"
                  :items="smsLogs"
                  :items-length="smsTotal"
                  :items-per-page="itemsPerPage"
                  :page="smsPage"
                  :loading="loading && smsLogs.length > 0"
                  :class="{ 'is-refreshing': loading && smsLogs.length > 0 }"
                  hover
                  hide-default-footer
                  :key="loading && smsLogs.length === 0 ? 'loading' : 'ready'"
                  class="bg-transparent logs-table board-table table-fade"
                  @update:options="onSmsOptions"
                >
                  <template v-if="loading && smsLogs.length === 0" #body>
                    <SkeletonRows :rows="itemsPerPage" :columns="smsHeaders.length" />
                  </template>
                  <template v-slot:item.rowNumber="{ index }">
                    <span class="row-number">{{ smsRowNumber(index) }}</span>
                  </template>

                  <template v-slot:item.message="{ item }">
                    <span class="cell-truncate cell-muted" :title="item.message">{{ item.message }}</span>
                  </template>

                  <template v-slot:item.recipient_count="{ item }">
                    <span class="cell-num cell-bold">{{ item.recipient_count }}</span>
                  </template>

                  <template v-slot:item.status="{ item }">
                    <!-- The column holds 'Sent' or 'Failed'. This used to test
                         for 'Completed', a value nothing writes, so every row
                         would have rendered orange — a failed blast and a
                         delivered one looking alike is the one distinction this
                         table exists to make. -->
                    <!-- 'Queued' is what SkySMS accepting a blast is recorded as:
                         billed, not delivered, so it is neutral slate and never
                         green. 'Pending' is SkySMS holding it. 'Unconfirmed' is
                         amber and not red: the vendor never answered, which is
                         not the same as nothing having been sent, and colouring
                         it as a failure is what would prompt a duplicate blast.
                         Anything unrecognised falls back to grey, not red — red
                         is for Failed only. -->
                    <StatusPill :status="smsPillStatus(item.status)" :label="item.status" />
                  </template>
                  <template v-slot:item.created_at="{ item }">
                    {{ formatLogDate(item.created_at) }}
                  </template>
                </v-data-table-server>
                <TableFooter :page="smsPage" :per-page="itemsPerPage" :total="smsTotal" @update:page="setSmsPage" @update:per-page="setPerPage" />
              </v-window-item>
            </v-window>
          </v-card-text>
        </v-card>
    </div>
  </v-container>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import { getToken } from '@/composables/authToken'
import { useServerRowNumber } from '@/composables/rowNumber'
import { API_BASE } from '@/config/api'
import { useCachedFetch } from '@/composables/useCachedFetch'
import PageHeader from '@/components/PageHeader.vue'
import TableFooter from '@/components/TableFooter.vue'
import SegmentedTabs from '@/components/SegmentedTabs.vue'
import StatusPill from '@/components/StatusPill.vue'
import SkeletonRows from '@/components/SkeletonRows.vue'

// The SMS History status pill (statusPill.ts accents). Sent is the only green: it
// is set only after SkySMS's own message list says every recipient's message was
// sent. Unconfirmed wears Pending's amber.
const smsPillStatus = (status) => (status === 'Unconfirmed' ? 'Pending' : status)

const activeTab = ref('system')
const search = ref('')
const loading = ref(false)

const systemLogs = ref([])
const smsLogs = ref([])

// Both log endpoints paginate as of backend audit #10. These mirror the
// server's answer rather than deriving anything: `*Total` is meta.total, which
// is the count of rows matching the CURRENT SEARCH, not the size of the table.
// v-data-table-server needs it to know how many page buttons to draw.
const itemsPerPage = ref(25)
const systemPage = ref(1)
const smsPage = ref(1)
const systemTotal = ref(0)
const smsTotal = ref(0)

// These two tables only ever hold one page of rows, so the row number has to
// come from the page the server was asked for — there is no full list here to
// count a position in.
const systemRowNumber = useServerRowNumber(systemPage, itemsPerPage)
const smsRowNumber = useServerRowNumber(smsPage, itemsPerPage)

// Debounced, because the search box now costs a round trip per keystroke
// instead of filtering an array already in memory.
let searchTimer
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    // Back to page 1: staying on page 4 while the result set shrinks to one
    // page shows an empty table and reads as "no matches".
    systemPage.value = 1
    smsPage.value = 1
    fetchLogs()
  }, 300)
})

// Table Definitions
const systemHeaders = [
  { title: '#', key: 'rowNumber', sortable: false, width: '56px' },
  { title: 'Date & time', key: 'created_at' },
  { title: 'User', key: 'user.name', sortable: false },
  { title: 'Module', key: 'module', sortable: false },
  { title: 'Action', key: 'action', sortable: false },
  { title: 'Description', key: 'description', sortable: false, width: '34%' },
]

// One row per barangay per blast, which is how the backend records them: the
// vendor is called once, but "what went to my barangay" is the unit anyone asks
// about afterwards.
const smsHeaders = [
  { title: '#', key: 'rowNumber', sortable: false, width: '56px' },
  { title: 'Date & time', key: 'created_at' },
  { title: 'Sender', key: 'user.name', sortable: false },
  { title: 'Barangay', key: 'barangay', sortable: false },
  { title: 'Message', key: 'message', sortable: false, width: '32%' },
  { title: 'Recipients', key: 'recipient_count', sortable: false, align: 'end' },
  { title: 'Status', key: 'status', sortable: false },
]

// Data Fetching
const getHeaders = () => ({
  'Authorization': `Bearer ${getToken()}`,
  'Accept': 'application/json'
})

const listUrl = (path, page) => {
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(itemsPerPage.value),
  })

  // URLSearchParams encodes the term, so a '%' or '&' typed into the box
  // reaches the server intact rather than truncating the query string. The
  // server matches wildcards literally.
  const term = (search.value || '').trim()
  if (term) params.set('search', term)

  return `${path}?${params.toString()}`
}

const { get } = useCachedFetch()

const takeSystem = (data) => {
  systemLogs.value = data.data || []
  // Fall back to the row count when meta is absent, so a server that has
  // not been updated yet still renders its rows instead of an empty table
  // with a zero-page pager.
  systemTotal.value = data.meta?.total ?? systemLogs.value.length
}

const takeSms = (data) => {
  smsLogs.value = data.data || []
  smsTotal.value = data.meta?.total ?? smsLogs.value.length
}

const fetchLogs = async () => {
  loading.value = true
  try {
    await Promise.all([
      get(listUrl('/logs/system', systemPage.value), { onData: takeSystem }),
      get(listUrl('/logs/sms', smsPage.value), { onData: takeSms }),
    ])
  } catch (error) {
    console.error('Failed to fetch logs:', error)
  } finally {
    loading.value = false
  }
}

// v-data-table-server emits this on mount and on every page change. Guarded so
// the mount emission does not fire a second identical request alongside
// onMounted's, and so a page change fetches only the tab that moved.
const onSystemOptions = ({ page }) => {
  if (page === systemPage.value) return
  systemPage.value = page
  fetchLogs()
}

const onSmsOptions = ({ page }) => {
  if (page === smsPage.value) return
  smsPage.value = page
  fetchLogs()
}

// The footer's own paging: it reports, these fetch (same as the options above).
const setSystemPage = (page) => { systemPage.value = page; fetchLogs() }
const setSmsPage = (page) => { smsPage.value = page; fetchLogs() }
const setPerPage = (n) => {
  itemsPerPage.value = n
  systemPage.value = 1
  smsPage.value = 1
  fetchLogs()
}

// Helpers
// "Oct 4, 2026, 7:02 PM" on both tables.
const formatLogDate = (dateString) => (dateString
  ? new Date(dateString).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
  : '')

// The action's StatusPill accent key (statusPill.ts): the server's word, capitalised.
const actionKey = (action) => `${(action || '').charAt(0).toUpperCase()}${(action || '').slice(1).toLowerCase()}`

onMounted(() => {
  fetchLogs()
})
</script>
<style scoped>
.row-number {
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-variant-numeric: tabular-nums;
}

.logs-page { display: flex; flex-direction: column; gap: 20px; }
.logs-page > .page-header { margin-bottom: 0; }
.logs-search { width: 320px; }
.logs-search :deep(.v-field) { border-radius: 10px; }

/* The board's card: 24px radius and shadow, no border. */
.logs-card {
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04), 0 4px 14px rgba(0, 0, 0, 0.08) !important;
  overflow: hidden;
}

/* Both tables take the shared board look (styles/board-table.css), whose fixed
   layout holds Description and Message to their declared widths. */
.cell-truncate {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
