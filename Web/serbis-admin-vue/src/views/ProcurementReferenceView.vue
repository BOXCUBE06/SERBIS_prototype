<template>
  <v-container fluid class="fill-height align-start px-6 px-md-10 pt-4 pb-10 page-background">
    <v-row>
      <v-col cols="12">

        <PageHeader
          title="Procurement Reference"
          subtitle="Equipment residents asked for that MDRRMO does not stock — the standing list for MDRRMC"
          class="mb-6"
        >
          <template v-slot:actions>
            <v-btn
              variant="tonal" rounded="lg" height="48" class="px-6 text-none font-weight-bold"
              :loading="reloading" @click="refresh"
            >
              <v-icon start size="20">mdi-refresh</v-icon> Refresh
            </v-btn>
          </template>
        </PageHeader>

        <v-alert
          v-if="loadError" type="error" variant="tonal" class="mb-6" density="compact" rounded="lg" role="alert"
        >{{ loadError }}</v-alert>

        <!-- What this page is, said once. Without it the list reads as an
             inventory of things the office has, which is the opposite. -->
        <v-alert
          type="info" variant="tonal" density="comfortable" rounded="lg" class="mb-6"
          icon="mdi-information-outline"
        >
          A row here is a request for an item that is not in the catalogue. It is a record of demand, not an
          order: nothing is reserved, and a request cannot be released until the item exists in Resource
          Management. This view is read-only.
        </v-alert>

        <!-- Summary -->
        <v-card v-if="!initialLoad && rows.length > 0" elevation="0" rounded="xl" class="group-card pa-6 mb-8">
          <div class="d-flex flex-wrap gap-8">
            <div>
              <div class="text-overline font-weight-bold text-medium-emphasis tracking-widest">Requests logged</div>
              <div class="text-h4 font-weight-bold text-high-emphasis">{{ rows.length }}</div>
            </div>
            <div>
              <div class="text-overline font-weight-bold text-medium-emphasis tracking-widest">Distinct items</div>
              <div class="text-h4 font-weight-bold text-high-emphasis">{{ distinctItems }}</div>
            </div>
            <div>
              <!-- Still open is the number that decides whether to procure:
                   a denied or cancelled row is demand that went away. -->
              <div class="text-overline font-weight-bold text-medium-emphasis tracking-widest">Still open</div>
              <div class="text-h4 font-weight-bold text-high-emphasis">{{ openCount }}</div>
            </div>
          </div>
        </v-card>

        <!-- Controls -->
        <div v-if="!initialLoad && rows.length > 0" class="d-flex flex-wrap align-center gap-3 mb-6">
          <v-text-field
            v-model="search"
            prepend-inner-icon="mdi-magnify"
            placeholder="Search item, requester or barangay..."
            variant="outlined" density="compact" hide-details rounded="lg"
            class="control-field"
          ></v-text-field>
          <v-select
            v-model="statusFilter"
            :items="statusOptions"
            label="Status"
            variant="outlined" density="compact" hide-details rounded="lg"
            class="control-field-sm"
          ></v-select>
          <v-spacer></v-spacer>
          <span class="page-subtitle text-medium-emphasis">{{ filtered.length }} of {{ rows.length }}</span>
        </div>

        <div v-if="initialLoad" class="d-flex justify-center py-16">
          <v-progress-circular indeterminate color="primary" size="40"></v-progress-circular>
        </div>

        <!-- Empty is the good state here: nobody has had to ask for something
             the office does not carry. Said plainly rather than as a blank table. -->
        <v-card v-else-if="rows.length === 0" elevation="0" rounded="xl" class="group-card pa-12 text-center">
          <v-icon size="48" color="medium-emphasis">mdi-clipboard-check-outline</v-icon>
          <div class="text-h6 font-weight-bold text-high-emphasis mt-4">No uncatalogued requests</div>
          <div class="text-body-2 text-medium-emphasis mt-1">
            Every borrow request so far has named an item already in Resource Management.
          </div>
        </v-card>

        <v-card v-else elevation="0" rounded="xl" class="group-card overflow-hidden">
          <v-data-table
            :headers="headers"
            :items="filtered"
            item-value="borrow_id"
            :items-per-page="10"
            density="comfortable"
            class="text-body-2 procurement-table"
          >
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
              <div class="font-weight-bold text-high-emphasis">
                {{ item.resident?.last_name }}, {{ item.resident?.first_name }}
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ item.resident?.barangay?.barangay_name || 'N/A' }}
              </div>
            </template>

            <template v-slot:item.created_at="{ item }">
              {{ fmtDate(item.created_at) }}
            </template>

            <template v-slot:item.status="{ item }">
              <v-chip
                size="small"
                variant="flat"
                class="font-weight-bold"
                :style="{ backgroundColor: statusAccent(item.status), color: '#FFFFFF' }"
              >
                <v-icon start size="14">{{ statusIcon(item.status) }}</v-icon>
                {{ item.status }}
              </v-chip>
            </template>
          </v-data-table>
        </v-card>

      </v-col>
    </v-row>
  </v-container>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { fmtDate } from '@/composables/adminUi'
import { useBorrowingsList } from '@/composables/borrowingsList'
import { BORROWING_STATUSES, statusAccent, statusIcon } from '@/composables/borrowingStatus'
import { useRowNumbers } from '@/composables/rowNumber'
import PageHeader from '@/components/PageHeader.vue'

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
 * Reads `useBorrowingsList`, the same rows EquipmentBorrowingView already
 * fetched. `GET /borrowings` returns the entire table unpaginated, so a second
 * independent fetch would double the panel's heaviest read for identical data.
 */

const CLOSED_STATUSES = ['Returned', 'Denied', 'Cancelled']
const ALL_STATUS = 'All'

const { rows: allBorrowings, loadError, initialLoad, reloading, load } = useBorrowingsList()

const search = ref('')
const statusFilter = ref(ALL_STATUS)

const statusOptions = [ALL_STATUS, ...BORROWING_STATUSES.map((s) => s.status)]

// The whole definition of this page: a borrowing whose item is free text rather
// than an inventory row. The backend's CHECK constraint guarantees exactly one
// of the two is set, so this needs no second condition on equipment_id.
const rows = computed(() =>
  allBorrowings.value.filter((b) => !!b.other_equipment_text && String(b.other_equipment_text).trim() !== '')
)

const distinctItems = computed(
  () => new Set(rows.value.map((b) => String(b.other_equipment_text).trim().toLowerCase())).size
)

const openCount = computed(() => rows.value.filter((b) => !CLOSED_STATUSES.includes(b.status)).length)

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

const rowNumber = useRowNumbers(filtered, 'borrow_id')

const headers = [
  { title: '#', key: 'number', sortable: false, width: 64 },
  { title: 'Item requested', key: 'other_equipment_text', width: '32%' },
  { title: 'Qty', key: 'quantity', width: 90 },
  { title: 'Requested by', key: 'resident', sortable: false, width: '24%' },
  { title: 'Date filed', key: 'created_at', width: 150 },
  { title: 'Request status', key: 'status', width: 170 },
]

const refresh = () => load()

// `ifEmpty` so arriving here from the borrowing board reuses the rows that
// board just fetched instead of pulling the whole table again. The Refresh
// button above is the way to force a fresh read.
onMounted(() => load({ ifEmpty: true }))
</script>

<style scoped>
/* Same locally-scoped utilities the sibling views define — VehiclesView,
   EquipmentBorrowingView. There is no shared stylesheet for them. */
.page-background { background-color: rgb(var(--v-theme-background)) !important; }
.tracking-widest { letter-spacing: 0.12em; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
.gap-8 { gap: 32px; }

.group-card {
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08) !important;
  box-shadow: 0 12px 40px -12px rgba(var(--v-theme-on-surface), 0.05) !important;
}

.control-field { width: 320px; max-width: 100%; }
.control-field-sm { width: 200px; max-width: 100%; }

.cell-truncate {
  max-width: 340px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Fixed layout keeps the six columns at their declared widths; the
   resident-typed equipment name is the one field here with no length
   limit at the source. */
.procurement-table :deep(table) { table-layout: fixed !important; width: 100% !important; min-width: 760px; }

.row-number {
  font-variant-numeric: tabular-nums;
}
</style>
