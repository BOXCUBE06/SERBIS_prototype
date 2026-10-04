<template>
  <v-container fluid class="fill-height align-start page-background">
    <PageHeader title="Procurement Reference" class="mb-5">
      <template v-slot:subtitle>{{ initialLoad ? '' : subtitle }}</template>
      <template v-slot:actions>
        <v-btn
          color="primary" variant="outlined" class="text-none font-weight-bold" height="40"
          prepend-icon="mdi-refresh" :loading="reloading" @click="refresh"
        >Refresh</v-btn>
      </template>
    </PageHeader>

    <v-alert v-if="loadError" type="error" variant="tonal" class="mb-6 w-100" density="compact" rounded="lg" role="alert">{{ loadError }}</v-alert>

    <div class="w-100">
      <!-- What this page is, said once. Without it the list reads as an
           inventory of things the office has, which is the opposite. -->
      <v-alert
        type="info" variant="tonal" density="comfortable" rounded="lg" class="mb-5"
        icon="mdi-information-outline"
      >
        Each row is a request for an item that is not in the catalogue. It records demand, not an order:
        nothing is reserved, and a request cannot be released until the item exists in Resource Management.
        This view is read-only.
      </v-alert>

      <DataTablePage
        compact
        filter-bar
        range-summary
        v-model:search="search"
        search-placeholder="Search item, requester or barangay"
        :loading="initialLoad"
        :refreshing="reloading"
        :headers="headers"
        :items="filtered"
        item-value="borrow_id"
        :no-data-text="rows.length > 0 ? 'No requests match your filters' : 'No uncatalogued requests'"
        :page="page"
        @update:page="page = $event"
        :items-per-page="itemsPerPage"
        @update:items-per-page="itemsPerPage = $event"
        result-noun="requests"
        :row-props="{ style: 'cursor: default' }"
        :active-filters="activeFilters"
        @clear-filter="clearFilter"
        @clear-all="clearAllFilters"
      >
        <template v-slot:filters>
          <FilterSelect v-model="statusFilter" :items="statusOptions" label="Status" />
        </template>

        <template v-slot:actions>
          <span v-if="!initialLoad" class="text-body-2 text-medium-emphasis">{{ filtered.length }} of {{ rows.length }}</span>
        </template>

        <template v-slot:item.number="{ item }">
          <span class="row-number text-medium-emphasis">{{ rowNumber(item) }}</span>
        </template>

        <template v-slot:item.other_equipment_text="{ item }">
          <div class="font-weight-bold text-high-emphasis cell-truncate" :title="item.other_equipment_text">{{ item.other_equipment_text }}</div>
          <div v-if="item.purpose" class="text-caption text-medium-emphasis cell-truncate" :title="item.purpose">{{ item.purpose }}</div>
        </template>

        <template v-slot:item.quantity="{ item }">
          <span class="font-weight-bold text-high-emphasis">{{ item.quantity }}</span>
        </template>

        <template v-slot:item.resident="{ item }">
          <div class="font-weight-bold text-high-emphasis cell-truncate">
            {{ item.resident?.last_name }}, {{ item.resident?.first_name }}
          </div>
          <div class="text-caption text-medium-emphasis cell-truncate">
            {{ item.resident?.barangay?.barangay_name || 'N/A' }}
          </div>
        </template>

        <template v-slot:item.created_at="{ item }">
          {{ fmtDate(item.created_at) }}
        </template>

        <template v-slot:item.status="{ item }">
          <StatusPill small :status="item.status" />
        </template>
      </DataTablePage>
    </div>
  </v-container>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { fmtDate, pluralize } from '@/composables/adminUi'
import { BORROWING_STATUSES } from '@/composables/borrowingStatus'
import { useRowNumbers } from '@/composables/rowNumber'
import { REFERENCE_TTL_MS, useCachedFetch } from '@/composables/useCachedFetch'
import PageHeader from '@/components/PageHeader.vue'
import DataTablePage from '@/components/DataTablePage.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import StatusPill from '@/components/StatusPill.vue'

/**
 * The MDRRMC procurement reference: every borrow request that named an item the
 * catalogue does not carry.
 *
 * Read-only, and deliberately so. There is no reference number, no procurement
 * status and no link to a purchase, because none of those exist in the schema —
 * inventing them here would put a state in the panel that no server column
 * holds. What this view is, is the thing the audit found missing: somewhere the
 * office can point at and see what it has been asked for and could not lend.
 *
 * Reads `GET /procurement/other-equipment`, which returns only the uncatalogued
 * requests and only the columns drawn here. It used to filter the whole
 * borrowings list in the browser, which meant an account given Procurement also
 * needed Equipment Borrowing and was handed every borrower's contact details.
 */

const CLOSED_STATUSES = new Set(['Returned', 'Denied', 'Cancelled'])
const ALL_STATUS = 'All'

// The server's definition of this page: a borrowing whose item is free text
// rather than an inventory row. Nothing left to filter here.
const rows = ref([])
const loadError = ref('')
const initialLoad = ref(true)
const reloading = ref(false)
const page = ref(1)
const itemsPerPage = ref(10)

const { get } = useCachedFetch()

const takeRows = (data) => {
  const list = data.data || data
  if (!Array.isArray(list)) throw new Error('The server returned an unexpected response')

  rows.value = list
  loadError.value = ''
  initialLoad.value = false
}

const load = async (fresh = false) => {
  reloading.value = true

  try {
    await get('/procurement/other-equipment', { ttl: REFERENCE_TTL_MS, fresh, onData: takeRows })
  } catch (error) {
    console.error('Failed to fetch procurement rows:', error)
    loadError.value = error.message || 'Could not reach the server'
  } finally {
    initialLoad.value = false
    reloading.value = false
  }
}

const search = ref('')
const statusFilter = ref(ALL_STATUS)

const statusOptions = [ALL_STATUS, ...BORROWING_STATUSES.map((s) => s.status)]

const distinctItems = computed(
  () => new Set(rows.value.map((b) => String(b.other_equipment_text).trim().toLowerCase())).size
)

const openCount = computed(() => rows.value.filter((b) => !CLOSED_STATUSES.has(b.status)).length)

// The stats card folded into the header: still open is the number that decides
// whether to procure (a denied or cancelled row is demand that went away).
const subtitle = computed(() => (rows.value.length
  ? `${pluralize(rows.value.length, 'request')} logged · ${pluralize(distinctItems.value, 'distinct item')} · ${openCount.value} still open`
  : 'No requests logged'))

const filtered = computed(() => {
  const term = search.value.trim().toLowerCase()

  return rows.value.filter((b) => {
    if (statusFilter.value !== ALL_STATUS && b.status !== statusFilter.value) return false
    if (!term) return true

    const haystack = [
      b.other_equipment_text,
      b.purpose,
      b.resident?.first_name,
      b.resident?.last_name,
      b.resident?.barangay?.barangay_name,
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase()

    return haystack.includes(term)
  })
})

const activeFilters = computed(() => (statusFilter.value === ALL_STATUS ? [] : [{ key: 'status', label: `Status: ${statusFilter.value}` }]))
const clearFilter = () => { statusFilter.value = ALL_STATUS }
const clearAllFilters = clearFilter
watch([search, statusFilter], () => { page.value = 1 })

const rowNumber = useRowNumbers(filtered, 'borrow_id')

const headers = [
  { title: '#', key: 'number', sortable: false, width: '56px' },
  { title: 'Item requested', key: 'other_equipment_text', width: '30%' },
  { title: 'Qty', key: 'quantity', width: '90px' },
  { title: 'Requested by', key: 'resident', sortable: false, width: '24%' },
  { title: 'Date filed', key: 'created_at', width: '150px' },
  { title: 'Status', key: 'status', width: '150px' },
]

// The refresh button asks for the live list, not the cached one.
const refresh = () => load(true)

onMounted(load)
</script>

<style scoped>
.page-background { background-color: rgb(var(--v-theme-background)) !important; }

.cell-truncate {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.row-number { font-variant-numeric: tabular-nums; }
</style>
