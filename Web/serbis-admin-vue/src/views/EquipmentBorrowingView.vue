<template>
  <v-container fluid class="align-start pa-6 bg-background" style="min-height: 100vh;">

    <!-- Header -->
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-6">
      <div>
        <h2 class="text-h5 font-weight-bold text-high-emphasis">Equipment Borrowing</h2>
        <div class="text-subtitle-2 text-medium-emphasis">
          Move each request through the pipeline — approve, release, then confirm its return
        </div>
      </div>
      <div class="d-flex align-center gap-3 flex-wrap">
        <v-select
          v-model="itemFilter"
          :items="itemOptions"
          prepend-inner-icon="mdi-package-variant-closed"
          variant="outlined"
          density="compact"
          hide-details
          rounded="lg"
          class="item-field"
        ></v-select>
        <v-text-field
          v-model="search"
          prepend-inner-icon="mdi-magnify"
          placeholder="Search resident or item..."
          variant="outlined"
          density="compact"
          hide-details
          rounded="lg"
          class="search-field"
        ></v-text-field>
      </div>
    </div>

    <!-- Board -->
    <v-skeleton-loader v-if="initialLoad" type="table" class="rounded-lg"></v-skeleton-loader>

    <div v-else class="kanban-board">
      <section
        v-for="col in visibleColumns"
        :key="col.status"
        class="kanban-column subtle-surface"
        :class="{ 'kanban-column--muted': col.muted }"
      >
        <header class="kanban-header" :style="{ '--accent': col.accent }">
          <div class="d-flex align-center gap-2">
            <v-icon size="18" :style="{ color: col.accent }">{{ col.icon }}</v-icon>
            <span class="text-subtitle-2 font-weight-bold text-high-emphasis">{{ col.label }}</span>
          </div>
          <span class="count-badge" :style="{ backgroundColor: col.accent }">{{ grouped[col.status].length }}</span>
        </header>

        <div class="kanban-body">
          <div
            v-for="item in grouped[col.status]"
            :key="item.borrow_id || item.id"
            class="kanban-card"
            role="button"
            tabindex="0"
            @click="openDetail(item)"
            @keydown.enter="openDetail(item)"
          >
            <div class="d-flex align-center gap-2 mb-2">
              <v-avatar size="34" color="rgba(var(--v-theme-primary), 0.14)">
                <span class="text-caption font-weight-bold text-primary">
                  {{ initials(item.resident) }}
                </span>
              </v-avatar>
              <div class="min-w-0 flex-grow-1">
                <div class="text-body-2 font-weight-bold text-high-emphasis text-truncate">
                  {{ item.resident?.last_name }}, {{ item.resident?.first_name }}
                </div>
                <div class="text-caption text-medium-emphasis text-truncate">
                  {{ item.resident?.barangay?.barangay_name || 'N/A' }}
                </div>
              </div>
            </div>

            <div class="equip-line">
              <v-icon size="16" class="text-medium-emphasis mr-1">mdi-package-variant-closed</v-icon>
              <span class="text-body-2 font-weight-medium text-high-emphasis text-truncate">
                {{ item.equipment?.item_name || 'Unknown' }}
              </span>
              <span class="qty-pill">{{ item.quantity }}×</span>
            </div>

            <!-- Stock warning for still-actionable stages -->
            <div
              v-if="!col.terminal && shortStock(item)"
              class="stock-warn"
            >
              <v-icon size="14" class="mr-1">mdi-alert-outline</v-icon>
              Only {{ item.equipment?.available_quantity ?? 0 }} in stock
            </div>

            <div class="d-flex align-center justify-space-between mt-2">
              <span class="text-caption text-medium-emphasis">{{ relativeDate(item.created_at) }}</span>

              <div class="d-flex gap-1" @click.stop>
                <template v-if="item.status === 'Pending'">
                  <v-btn
                    size="x-small" variant="text" color="error" class="text-none"
                    :loading="processingId === (item.borrow_id || item.id)"
                    @click="updateStatus(item, 'Denied')"
                  >Deny</v-btn>
                  <v-btn
                    size="x-small" variant="flat" color="primary" class="text-none px-3"
                    :loading="processingId === (item.borrow_id || item.id)"
                    @click="updateStatus(item, 'Approved')"
                  >Approve</v-btn>
                </template>
                <v-btn
                  v-else-if="item.status === 'Approved'"
                  size="x-small" variant="flat" color="primary" class="text-none px-3"
                  :loading="processingId === (item.borrow_id || item.id)"
                  @click="updateStatus(item, 'Released')"
                >Release</v-btn>
                <v-btn
                  v-else-if="item.status === 'Released'"
                  size="x-small" variant="flat" color="primary" class="text-none px-3"
                  :loading="processingId === (item.borrow_id || item.id)"
                  @click="updateStatus(item, 'Returned')"
                >Confirm return</v-btn>
                <v-icon v-else size="16" :style="{ color: col.accent }">
                  {{ item.status === 'Denied' ? 'mdi-close-circle' : 'mdi-check-circle' }}
                </v-icon>
              </div>
            </div>
          </div>

          <div v-if="!grouped[col.status].length" class="kanban-empty text-caption text-medium-emphasis">
            Nothing here
          </div>
        </div>
      </section>
    </div>

    <!-- Detail modal (full record + fallback actions) -->
    <v-dialog v-model="modal.isOpen" max-width="900" persistent transition="dialog-fade-transition">
      <v-card rounded="lg" elevation="4">
        <v-card-title class="d-flex justify-space-between align-center pa-6 border-b bg-surface">
          <div class="d-flex align-center gap-3">
            <span class="text-h6 font-weight-bold text-high-emphasis">Borrowing Request Details</span>
            <v-chip
              :color="statusAccent(selectedRecord?.status)"
              size="small" rounded="pill" variant="flat"
              class="text-uppercase font-weight-bold"
            >{{ selectedRecord?.status }}</v-chip>
          </div>
          <v-btn icon="mdi-close" variant="text" size="small" @click="closeModal"></v-btn>
        </v-card-title>

        <v-card-text class="pa-0">
          <v-row class="ma-0 h-100">
            <v-col cols="12" md="5" class="subtle-surface pa-6 border-e">
              <div class="d-flex flex-column align-center mb-6">
                <v-avatar color="rgba(var(--v-theme-primary), 0.14)" size="80" class="mb-3">
                  <span class="text-h4 font-weight-black text-primary">{{ initials(selectedRecord?.resident) }}</span>
                </v-avatar>
                <div class="text-h6 font-weight-bold text-center text-high-emphasis">
                  {{ selectedRecord?.resident?.first_name }} {{ selectedRecord?.resident?.last_name }}
                </div>
                <div class="text-caption text-medium-emphasis text-uppercase font-weight-bold mt-1">Resident Profile</div>
              </div>
              <v-divider class="mb-4"></v-divider>
              <div class="mb-3">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Phone Number</div>
                <div class="font-weight-medium text-body-1 text-high-emphasis">{{ selectedRecord?.resident?.phone_number || 'N/A' }}</div>
              </div>
              <div class="mb-3">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Barangay</div>
                <div class="font-weight-medium text-body-1 text-high-emphasis">{{ selectedRecord?.resident?.barangay?.barangay_name || 'N/A' }}</div>
              </div>
            </v-col>

            <v-col cols="12" md="7" class="pa-6 bg-surface">
              <v-alert v-if="apiError" type="error" variant="tonal" class="mb-4" density="compact">{{ apiError }}</v-alert>

              <h3 class="text-subtitle-1 font-weight-bold mb-4 text-high-emphasis text-uppercase">Equipment Requested</h3>
              <v-card variant="outlined" border class="pa-6 mb-6 rounded-lg subtle-surface d-flex justify-space-between align-center">
                <div>
                  <div class="text-h5 font-weight-black text-high-emphasis">{{ selectedRecord?.equipment?.item_name }}</div>
                  <div class="text-subtitle-2 font-weight-medium text-medium-emphasis mt-1">
                    Current Stock Available:
                    <span class="font-weight-bold" :class="selectedRecord?.equipment?.available_quantity > 0 ? 'text-primary' : 'text-error'">
                      {{ selectedRecord?.equipment?.available_quantity }}
                    </span>
                  </div>
                </div>
                <div class="text-h3 font-weight-black text-high-emphasis">{{ selectedRecord?.quantity }}<span class="text-h5 text-medium-emphasis ml-1">×</span></div>
              </v-card>

              <v-row class="mb-4">
                <v-col cols="6">
                  <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Requested On</div>
                  <div class="font-weight-medium text-body-1 text-high-emphasis">{{ fmtDateTime(selectedRecord?.created_at) }}</div>
                </v-col>
                <v-col cols="6" v-if="selectedRecord?.released_at">
                  <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Released On</div>
                  <div class="font-weight-medium text-body-1 text-primary">{{ fmtDateTime(selectedRecord?.released_at) }}</div>
                </v-col>
                <v-col cols="6" v-if="selectedRecord?.returned_at">
                  <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Returned On</div>
                  <div class="font-weight-medium text-body-1 text-success">{{ fmtDateTime(selectedRecord?.returned_at) }}</div>
                </v-col>
              </v-row>
            </v-col>
          </v-row>
        </v-card-text>

        <v-card-actions
          v-if="selectedRecord && selectedRecord.status !== 'Returned' && selectedRecord.status !== 'Denied'"
          class="pa-6 d-flex justify-end subtle-surface border-t gap-3"
        >
          <template v-if="selectedRecord.status === 'Pending'">
            <v-btn color="error" variant="text" class="px-6 text-none font-weight-bold" height="44" :loading="loading" @click="updateStatus(selectedRecord, 'Denied')">Deny Request</v-btn>
            <v-btn color="primary" variant="flat" class="px-6 text-none font-weight-bold" height="44" :loading="loading" @click="updateStatus(selectedRecord, 'Approved')">Approve Request</v-btn>
          </template>
          <v-btn v-else-if="selectedRecord.status === 'Approved'" color="primary" variant="flat" class="px-6 text-none font-weight-bold w-100" height="44" :loading="loading" @click="updateStatus(selectedRecord, 'Released')">Mark as Released to Resident</v-btn>
          <v-btn v-else-if="selectedRecord.status === 'Released'" color="success" variant="flat" class="px-6 text-none font-weight-bold w-100" height="44" :loading="loading" @click="updateStatus(selectedRecord, 'Returned')">Confirm Items Returned</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3500" location="bottom right" rounded="lg">
      {{ snackbar.text }}
    </v-snackbar>
  </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { getToken } from '@/composables/authToken'

// Status colours: saturated 700-level ramp, each AA with white text as a badge,
// legible on both light and dark surfaces. Semantic (data-viz), not brand tokens —
// except Returned, which uses the system primary green (success tracks primary).
const columns = [
  { status: 'Pending',  label: 'Pending',  accent: '#B45309', icon: 'mdi-clock-outline' },
  { status: 'Approved', label: 'Approved', accent: '#1D4ED8', icon: 'mdi-check-decagram-outline' },
  { status: 'Released', label: 'Released', accent: '#0E7490', icon: 'mdi-hand-extended-outline' },
  { status: 'Returned', label: 'Returned', accent: '#297A67', icon: 'mdi-check-circle-outline', terminal: true },
  { status: 'Denied',   label: 'Denied',   accent: '#B91C1C', icon: 'mdi-close-circle-outline', terminal: true, muted: true },
]

const borrowings = ref([])
const search = ref('')
const itemFilter = ref('All items')
const initialLoad = ref(true)
const loading = ref(false)
const processingId = ref(null)
const apiError = ref('')
const modal = ref({ isOpen: false })
const selectedRecord = ref(null)
const snackbar = ref({ show: false, text: '', color: 'success' })

const notify = (text, color = 'success') => { snackbar.value = { show: true, text, color } }

const visibleColumns = computed(() => columns)

// Distinct equipment names present in the current requests, for the item filter.
const itemOptions = computed(() => {
  const names = new Set()
  for (const b of borrowings.value) {
    if (b.equipment?.item_name) names.add(b.equipment.item_name)
  }
  return ['All items', ...[...names].sort((a, b) => a.localeCompare(b))]
})

const matchesSearch = (b) => {
  const q = search.value.trim().toLowerCase()
  if (!q) return true
  const name = `${b.resident?.first_name || ''} ${b.resident?.last_name || ''}`.toLowerCase()
  const item = (b.equipment?.item_name || '').toLowerCase()
  return name.includes(q) || item.includes(q)
}

const matchesItem = (b) =>
  itemFilter.value === 'All items' || b.equipment?.item_name === itemFilter.value

// Bucket records by status, newest first, filtered by item + search.
const grouped = computed(() => {
  const out = Object.fromEntries(columns.map((c) => [c.status, []]))
  for (const b of borrowings.value) {
    if (out[b.status] && matchesItem(b) && matchesSearch(b)) out[b.status].push(b)
  }
  for (const k in out) out[k].sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
  return out
})

const initials = (r) => `${r?.first_name?.charAt(0) || ''}${r?.last_name?.charAt(0) || ''}`
const shortStock = (item) => (item.equipment?.available_quantity ?? 0) < item.quantity
const statusAccent = (status) => columns.find((c) => c.status === status)?.accent || '#64748B'

const relativeDate = (iso) => {
  const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000)
  if (days <= 0) return 'Today'
  if (days === 1) return 'Yesterday'
  if (days < 7) return `${days}d ago`
  return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}
const fmtDateTime = (iso) => iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''

const getHeaders = () => ({
  Authorization: `Bearer ${getToken()}`,
  'Content-Type': 'application/json',
  Accept: 'application/json',
})

const fetchData = async () => {
  try {
    const res = await fetch('http://localhost:8000/api/borrowings', { headers: getHeaders() })
    const data = await res.json()
    borrowings.value = data.data || data
  } catch (error) {
    console.error('Failed to fetch borrowings:', error)
    notify('Could not load borrowings', 'error')
  } finally {
    initialLoad.value = false
  }
}

const openDetail = (item) => {
  apiError.value = ''
  selectedRecord.value = item
  modal.value.isOpen = true
}
const closeModal = () => {
  modal.value.isOpen = false
  selectedRecord.value = null
}

const updateStatus = async (record, newStatus) => {
  const id = record.borrow_id || record.id
  loading.value = true
  processingId.value = id
  apiError.value = ''
  try {
    const res = await fetch(`http://localhost:8000/api/borrowings/${id}`, {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify({ status: newStatus }),
    })
    if (!res.ok) {
      const errData = await res.json().catch(() => ({}))
      throw new Error(errData.message || 'Failed to update status')
    }
    await fetchData()
    notify(`Request marked ${newStatus}`)
    if (modal.value.isOpen) closeModal()
  } catch (error) {
    apiError.value = error.message
    notify(error.message, 'error')
  } finally {
    loading.value = false
    processingId.value = null
  }
}

onMounted(fetchData)
</script>

<style scoped>
.gap-1 { gap: 4px; }
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
.min-w-0 { min-width: 0; }
.search-field { width: 260px; max-width: 100%; }
.item-field { width: 210px; max-width: 100%; }

/* Board */
.kanban-board {
  display: flex;
  gap: 16px;
  align-items: flex-start;
  overflow-x: auto;
  padding-bottom: 8px;
}
.kanban-column {
  flex: 1 1 0;
  min-width: 280px;
  border-radius: 16px;
  display: flex;
  flex-direction: column;
  max-height: calc(100vh - 180px);
}
.kanban-column--muted { opacity: 0.85; }

.kanban-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px 12px;
  border-top: 3px solid var(--accent);
  border-radius: 16px 16px 0 0;
}
.count-badge {
  color: #fff;
  font-size: 0.75rem;
  font-weight: 700;
  min-width: 22px;
  height: 22px;
  padding: 0 7px;
  border-radius: 11px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.kanban-body {
  padding: 0 12px 12px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.kanban-card {
  background-color: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  border-radius: 12px;
  padding: 12px;
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.kanban-card:hover,
.kanban-card:focus-visible {
  transform: translateY(-2px);
  box-shadow: 0 6px 18px rgba(var(--v-theme-on-surface), 0.12);
  outline: none;
}

.equip-line { display: flex; align-items: center; min-width: 0; }
.qty-pill {
  margin-left: auto;
  font-size: 0.75rem;
  font-weight: 700;
  color: rgb(var(--v-theme-primary));
  background-color: rgba(var(--v-theme-primary), 0.12);
  padding: 1px 8px;
  border-radius: 8px;
  white-space: nowrap;
}

.stock-warn {
  display: flex;
  align-items: center;
  margin-top: 8px;
  font-size: 0.72rem;
  font-weight: 600;
  color: rgb(var(--v-theme-error));
  background-color: rgba(var(--v-theme-error), 0.1);
  padding: 3px 8px;
  border-radius: 8px;
}

.kanban-empty {
  text-align: center;
  padding: 24px 8px;
  border: 1px dashed rgba(var(--v-theme-on-surface), 0.14);
  border-radius: 12px;
}

@media (prefers-reduced-motion: reduce) {
  .kanban-card { transition: none; }
  .kanban-card:hover { transform: none; }
}
</style>
