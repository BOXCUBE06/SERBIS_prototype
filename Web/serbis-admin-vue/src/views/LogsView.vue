<template>
  <v-container fluid class="fill-height align-start bg-background">
    <v-row>
      <v-col cols="12">
        <PageHeader
          title="Activity Logs"
        >
          <template v-slot:actions>
            <v-text-field
              v-model="search"
              prepend-inner-icon="mdi-magnify"
              placeholder="Search logs..."
              variant="solo"
              density="compact"
              hide-details
              rounded="lg"
              class="elevation-1"
              style="width: 300px; max-width: 100%;"
            ></v-text-field>
          </template>
        </PageHeader>

        <v-card elevation="0" border rounded="xl" class="bg-surface">
          <v-tabs v-model="activeTab" color="primary" class="border-b px-4">
            <v-tab value="system" class="text-none font-weight-bold">
              <v-icon start>mdi-laptop</v-icon> System Activity
            </v-tab>
            <v-tab value="sms" class="text-none font-weight-bold">
              <v-icon start>mdi-message-text</v-icon> SMS History
            </v-tab>
          </v-tabs>

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
                  class="bg-transparent logs-table"
                  @update:options="onSystemOptions"
                >
                  <template v-if="loading && systemLogs.length === 0" #body>
                    <SkeletonRows :rows="itemsPerPage" :columns="systemHeaders.length" />
                  </template>
                  <template v-slot:item.rowNumber="{ index }">
                    <span class="row-number text-medium-emphasis">{{ systemRowNumber(index) }}</span>
                  </template>

                  <template v-slot:item.action="{ item }">
                    <v-chip :color="getActionColor(item.action)" size="small" variant="tonal" class="font-weight-bold">
                      {{ item.action }}
                    </v-chip>
                  </template>
                  <template v-slot:item.created_at="{ item }">
                    {{ formatDate(item.created_at) }}
                  </template>
                  <template v-slot:item.description="{ item }">
                    <span class="cell-truncate" :title="item.description">{{ item.description }}</span>
                  </template>
                </v-data-table-server>
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
                  class="bg-transparent logs-table"
                  @update:options="onSmsOptions"
                >
                  <template v-if="loading && smsLogs.length === 0" #body>
                    <SkeletonRows :rows="itemsPerPage" :columns="smsHeaders.length" />
                  </template>
                  <template v-slot:item.rowNumber="{ index }">
                    <span class="row-number text-medium-emphasis">{{ smsRowNumber(index) }}</span>
                  </template>

                  <template v-slot:item.message="{ item }">
                    <span class="cell-truncate" :title="item.message">{{ item.message }}</span>
                  </template>

                  <template v-slot:item.status="{ item }">
                    <!-- The column holds 'Sent' or 'Failed'. This used to test
                         for 'Completed', a value nothing writes, so every row
                         would have rendered orange — a failed blast and a
                         delivered one looking alike is the one distinction this
                         table exists to make. -->
                    <!-- 'Queued' is what SkySMS accepting a blast is recorded as:
                         billed, not delivered, so it is blue and never green.
                         'Pending' is SkySMS holding it. 'Unconfirmed' is amber
                         and not red: the vendor never answered, which is not
                         the same as nothing having been sent, and colouring it
                         as a failure is what would prompt a duplicate blast.
                         Anything unrecognised falls back to grey, not red — red
                         is for Failed only. -->
                    <v-chip :color="smsStatusColor(item.status)" size="small" variant="tonal" class="font-weight-bold">
                      {{ item.status }}
                    </v-chip>
                  </template>
                  <template v-slot:item.created_at="{ item }">
                    {{ formatDate(item.created_at) }}
                  </template>
                </v-data-table-server>
              </v-window-item>
            </v-window>
          </v-card-text>
        </v-card>

      </v-col>
    </v-row>
  </v-container>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import { getToken } from '@/composables/authToken'
import { useServerRowNumber } from '@/composables/rowNumber'
import { API_BASE } from '@/config/api'
import PageHeader from '@/components/PageHeader.vue'
import SkeletonRows from '@/components/SkeletonRows.vue'

// The SMS History status chip. Sent is the only green: it is set only after
// SkySMS's own message list says every recipient's message was sent.
const smsStatusColor = (status) => ({
  Sent: 'green',
  Queued: 'blue',
  Pending: 'amber-darken-2',
  Unconfirmed: 'amber-darken-2',
  Failed: 'red',
}[status] || 'grey')

const activeTab = ref('system')
const search = ref('')
const loading = ref(false)

const systemLogs = ref([])
const smsLogs = ref([])

// Both log endpoints paginate as of backend audit #10. These mirror the
// server's answer rather than deriving anything: `*Total` is meta.total, which
// is the count of rows matching the CURRENT SEARCH, not the size of the table.
// v-data-table-server needs it to know how many page buttons to draw.
const itemsPerPage = 25
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
  { title: '#', key: 'rowNumber', sortable: false, align: 'center', width: '64px' },
  { title: 'Date & Time', key: 'created_at', width: '19%' },
  { title: 'User', key: 'user.name', width: '19%' },
  { title: 'Module', key: 'module', width: '14%' },
  { title: 'Action', key: 'action', width: '14%' },
  { title: 'Description', key: 'description', width: '28%' },
]

// One row per barangay per blast, which is how the backend records them: the
// vendor is called once, but "what went to my barangay" is the unit anyone asks
// about afterwards.
const smsHeaders = [
  { title: '#', key: 'rowNumber', sortable: false, align: 'center', width: '64px' },
  { title: 'Date & Time', key: 'created_at', width: '17%' },
  { title: 'Sender', key: 'user.name', width: '15%' },
  { title: 'Barangay', key: 'barangay', width: '13%' },
  { title: 'Message Content', key: 'message', width: '31%' },
  { title: 'Recipients', key: 'recipient_count', align: 'center', width: '10%' },
  { title: 'Status', key: 'status', align: 'center', width: '9%' },
]

// Data Fetching
const getHeaders = () => ({
  'Authorization': `Bearer ${getToken()}`,
  'Accept': 'application/json'
})

const listUrl = (path, page) => {
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(itemsPerPage),
  })

  // URLSearchParams encodes the term, so a '%' or '&' typed into the box
  // reaches the server intact rather than truncating the query string. The
  // server matches wildcards literally.
  const term = (search.value || '').trim()
  if (term) params.set('search', term)

  return `${API_BASE}${path}?${params.toString()}`
}

const fetchLogs = async () => {
  loading.value = true
  try {
    const [systemRes, smsRes] = await Promise.all([
      fetch(listUrl('/logs/system', systemPage.value), { headers: getHeaders() }),
      fetch(listUrl('/logs/sms', smsPage.value), { headers: getHeaders() })
    ])

    const systemData = await systemRes.json()
    const smsData = await smsRes.json()

    systemLogs.value = systemData.data || []
    smsLogs.value = smsData.data || []

    // Fall back to the row count when meta is absent, so a server that has
    // not been updated yet still renders its rows instead of an empty table
    // with a zero-page pager.
    systemTotal.value = systemData.meta?.total ?? systemLogs.value.length
    smsTotal.value = smsData.meta?.total ?? smsLogs.value.length
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

// Helpers
const formatDate = (dateString) => {
  if (!dateString) return ''
  const date = new Date(dateString)
  return new Intl.DateTimeFormat('en-PH', { 
    year: 'numeric', month: 'short', day: '2-digit', 
    hour: '2-digit', minute: '2-digit' 
  }).format(date)
}

const getActionColor = (action) => {
  switch (action?.toLowerCase()) {
    case 'created': return 'green'   // was 'create'
    case 'updated': return 'blue'    // was 'update'
    case 'deleted': return 'red'     // was 'delete'
    case 'login':   return 'purple'
    case 'exported':
    case 'printed': return 'teal'
    default:        return 'grey'
  }
}

onMounted(() => {
  fetchLogs()
})
</script>
<style scoped>
.row-number {
  font-size: 0.95rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

/* Fixed layout holds both tables to the header widths declared in
   systemHeaders/smsHeaders; without it, Description and Message Content
   wrap freely and every row is a different height. */
.logs-table :deep(table) { table-layout: fixed !important; width: 100% !important; min-width: 760px; }
.cell-truncate {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
