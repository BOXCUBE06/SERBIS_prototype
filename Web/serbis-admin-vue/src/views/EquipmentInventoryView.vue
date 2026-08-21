<template>
  <v-container fluid class="fill-height align-start pa-6 bg-background">
    <v-row class="ma-0 w-100">
      <v-col cols="12" class="pa-0 w-100">

        <!-- Header -->
        <div class="d-flex flex-wrap justify-space-between align-center gap-4 mb-6">
          <div>
            <h2 class="text-h4 font-weight-bold text-high-emphasis tracking-tight">Equipment Inventory</h2>
            <div class="text-subtitle-2 text-medium-emphasis">How much of each item is on hand, and how much is out on loan</div>
          </div>
          <v-btn color="primary" variant="flat" rounded="lg" height="48" class="px-6 text-none font-weight-bold btn-soft-shadow" @click="openAdd">
            <v-icon start size="20">mdi-plus</v-icon> Add Equipment
          </v-btn>
        </div>

        <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6" density="compact" rounded="lg">{{ apiError }}</v-alert>

        <!-- Metric tiles -->
        <v-row v-if="!initialLoad && equipments.length" class="mb-2">
          <v-col v-for="m in metricTiles" :key="m.key" cols="6" md="3">
            <button type="button" class="metric-tile group-card" :class="{ 'metric-tile--active': m.filter && statusFilter === m.filter }" @click="m.filter && (statusFilter = statusFilter === m.filter ? 'All' : m.filter)">
              <div class="metric-icon" :style="{ background: `rgba(var(--v-theme-${m.color}), 0.12)` }">
                <v-icon :color="m.color" size="24">{{ m.icon }}</v-icon>
              </div>
              <div class="text-truncate">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis text-truncate">{{ m.title }}</div>
                <div class="metric-number text-high-emphasis">{{ m.value }}</div>
              </div>
            </button>
          </v-col>
        </v-row>

        <!-- Controls -->
        <div v-if="!initialLoad && equipments.length" class="d-flex flex-wrap align-center gap-3 mb-6 mt-4">
          <v-text-field
            v-model="search"
            prepend-inner-icon="mdi-magnify"
            placeholder="Search equipment..."
            variant="outlined" density="compact" hide-details rounded="lg"
            class="control-field"
          ></v-text-field>
          <v-select
            v-model="statusFilter"
            :items="statusOptions"
            prepend-inner-icon="mdi-filter-variant"
            variant="outlined" density="compact" hide-details rounded="lg"
            class="control-field-sm"
          ></v-select>
        </div>

        <!-- Loading -->
        <v-card v-if="initialLoad" elevation="0" rounded="xl" class="group-card pa-6">
          <v-skeleton-loader type="table-row@6" class="bg-transparent"></v-skeleton-loader>
        </v-card>

        <!-- Empty -->
        <div v-else-if="!filteredEquipments.length" class="empty-state group-card">
          <v-icon size="48" class="text-medium-emphasis mb-3">mdi-package-variant</v-icon>
          <div class="text-subtitle-1 font-weight-bold text-high-emphasis">
            {{ equipments.length ? 'No items match your filters' : 'No equipment yet' }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ equipments.length ? 'Clear the search or filter to see all items.' : 'Add the first resource category to get started.' }}
          </div>
        </div>

        <!-- Inventory list. The card grid gave every category the same visual
             weight and pushed the one number the desk needs -- how many are on
             the shelf right now -- into a different corner of each tile. As a
             list the availability column reads straight down, and sorting on it
             puts whatever is running out at the top. -->
        <v-card v-else elevation="0" rounded="xl" class="group-card overflow-hidden">
          <v-data-table
            :headers="inventoryHeaders"
            :items="filteredEquipments"
            v-model:page="page"
            v-model:items-per-page="itemsPerPage"
            item-value="equipment_id"
            density="comfortable"
            class="inventory-table"
          >
            <template v-slot:item.rowNumber="{ index }">
              <span class="row-number text-medium-emphasis">{{ rowNumber(index) }}</span>
            </template>

            <template v-slot:item.item_name="{ item }">
              <div class="d-flex align-center gap-3 py-2 min-w-0">
                <div class="icon-wrapper" :class="`iconbg-${stockState(item)}`">
                  <v-icon :color="stateMeta[stockState(item)].color" size="22">{{ itemIcon(item.item_name) }}</v-icon>
                </div>
                <span class="text-body-1 font-weight-bold text-high-emphasis text-truncate">{{ item.item_name }}</span>
              </div>
            </template>

            <template v-slot:item.available_quantity="{ item }">
              <div class="py-2">
                <div class="d-flex align-baseline gap-1">
                  <span class="qty-available" :class="`text-${stateMeta[stockState(item)].color}`">{{ item.available_quantity }}</span>
                  <span class="text-body-2 text-medium-emphasis">/ {{ item.total_quantity }}</span>
                </div>
                <div class="gauge mt-1">
                  <span class="gauge-fill" :class="`fill-${stockState(item)}`" :style="{ width: gaugePct(item) }"></span>
                </div>
              </div>
            </template>

            <template v-slot:item.state="{ item }">
              <span class="state-pill" :class="`pill-${stockState(item)}`">
                <span class="dot" :class="`dot-${stockState(item)}`"></span>
                {{ stateLabel(item) }}
              </span>
            </template>

            <template v-slot:item.in_use="{ item }">
              <span class="text-body-2 text-medium-emphasis">{{ inUse(item) }} in use</span>
            </template>

            <template v-slot:item.actions="{ item }">
              <div class="d-flex justify-end gap-1">
                <v-btn
                  icon="mdi-pencil-outline" variant="text" size="small"
                  :aria-label="`Edit ${item.item_name}`" @click="openEdit(item)"
                ></v-btn>
                <v-btn
                  icon="mdi-delete-outline" variant="text" size="small" color="error"
                  :aria-label="`Delete ${item.item_name}`" @click="askDelete(item)"
                ></v-btn>
              </div>
            </template>
          </v-data-table>
        </v-card>

      </v-col>
    </v-row>

    <!-- Add / Edit -->
    <v-dialog v-model="modal.show" max-width="500" persistent>
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="d-flex justify-space-between align-center pa-6 pb-2">
          <span class="text-h6 font-weight-bold text-high-emphasis">{{ modal.editing ? 'Edit equipment' : 'Add equipment' }}</span>
          <v-btn icon="mdi-close" variant="text" size="small" @click="modal.show = false"></v-btn>
        </v-card-title>
        <v-card-text class="px-6 py-2">
          <v-alert v-if="modal.error" type="error" variant="tonal" density="compact" rounded="lg" class="mb-4">{{ modal.error }}</v-alert>
          <v-text-field v-model="form.item_name" label="Item name *" variant="outlined" density="comfortable" rounded="lg" class="mb-3"></v-text-field>
          <v-row>
            <v-col cols="12" md="6">
              <v-text-field v-model.number="form.total_quantity" label="Total owned *" type="number" min="1" variant="outlined" density="comfortable" rounded="lg"></v-text-field>
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field
                v-if="modal.editing"
                v-model.number="form.available_quantity"
                label="Available now *"
                type="number" min="0" :max="form.total_quantity"
                variant="outlined" density="comfortable" rounded="lg"
              ></v-text-field>
              <div v-else class="autosync-note subtle-surface">
                <v-icon size="16" class="mr-1 text-medium-emphasis">mdi-sync</v-icon>
                Available starts equal to total
              </div>
            </v-col>
          </v-row>
          <v-select v-model="form.status" :items="['Available', 'Unavailable']" label="Status *" variant="outlined" density="comfortable" rounded="lg" class="mt-1"></v-select>
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="text" rounded="lg" class="text-none" :disabled="modal.loading" @click="modal.show = false">Cancel</v-btn>
          <v-btn color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" :loading="modal.loading" @click="saveEquipment">
            {{ modal.editing ? 'Save' : 'Add' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete confirm -->
    <v-dialog v-model="deleteDialog.show" max-width="420">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">Delete equipment?</v-card-title>
        <v-card-text class="px-6 py-4 text-body-2 text-medium-emphasis">
          <strong class="text-high-emphasis">{{ deleteDialog.item?.item_name }}</strong> will be permanently removed from the inventory. This cannot be undone.
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="text" rounded="lg" class="text-none" :disabled="deleteDialog.loading" @click="deleteDialog.show = false">Cancel</v-btn>
          <v-btn color="error" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" :loading="deleteDialog.loading" @click="confirmDelete">Delete</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3500" location="bottom right" rounded="lg">{{ snackbar.text }}</v-snackbar>
  </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { getToken } from '@/composables/authToken'
import { useRowNumber } from '@/composables/rowNumber'
import { API_BASE } from '@/config/api'

const API = `${API_BASE}/equipments`

const stateMeta = {
  available: { color: 'primary' },
  low: { color: 'warning' },
  depleted: { color: 'error' },
}

const equipments = ref([])
const search = ref('')
const statusFilter = ref('All')
const initialLoad = ref(true)
const apiError = ref('')

const modal = ref({ show: false, editing: false, loading: false, error: '', targetId: null })
const form = ref({ item_name: '', total_quantity: 1, available_quantity: 1, status: 'Available' })
const deleteDialog = ref({ show: false, item: null, loading: false })
const snackbar = ref({ show: false, text: '', color: 'success' })

const notify = (text, color = 'success') => { snackbar.value = { show: true, text, color } }
const getHeaders = () => ({ Authorization: `Bearer ${getToken()}`, 'Content-Type': 'application/json', Accept: 'application/json' })

// Depleted: none available or admin-marked Unavailable. Low: some out and <=25% left.
const stockState = (e) => {
  if (e.status === 'Unavailable' || e.available_quantity === 0) return 'depleted'
  const lowThresh = Math.max(1, Math.floor(e.total_quantity * 0.25))
  if (e.available_quantity < e.total_quantity && e.available_quantity <= lowThresh) return 'low'
  return 'available'
}
const stateLabel = (e) => {
  const s = stockState(e)
  if (s === 'available') return 'Available'
  if (s === 'low') return 'Low stock'
  return e.available_quantity === 0 ? 'Depleted' : 'Unavailable'
}
const inUse = (e) => Math.max(0, e.total_quantity - e.available_quantity)
const gaugePct = (e) => `${e.total_quantity ? (e.available_quantity / e.total_quantity) * 100 : 0}%`

const totalOwned = computed(() => equipments.value.reduce((a, e) => a + (e.total_quantity || 0), 0))
const totalAvailable = computed(() => equipments.value.reduce((a, e) => a + (e.available_quantity || 0), 0))
const needsAttention = computed(() => equipments.value.filter((e) => stockState(e) !== 'available').length)

const metricTiles = computed(() => [
  { key: 'cat', title: 'Categories', value: equipments.value.length, icon: 'mdi-toolbox-outline', color: 'primary' },
  { key: 'owned', title: 'Total Owned', value: totalOwned.value, icon: 'mdi-package-variant-closed', color: 'primary' },
  { key: 'avail', title: 'Available', value: totalAvailable.value, icon: 'mdi-check-all', color: 'primary' },
  { key: 'attn', title: 'Low / Depleted', value: needsAttention.value, icon: 'mdi-alert-octagon-outline', color: 'error', filter: 'Needs attention' },
])

const statusOptions = ['All', 'Available', 'Low stock', 'Depleted', 'Needs attention']

// `state` and `in_use` are derived, not columns on the row, so neither can be
// sorted by the table's own comparator -- the status filter above covers that
// question. `available_quantity` sorts, and it is the one worth sorting.
const inventoryHeaders = [
  { title: '#', key: 'rowNumber', sortable: false, align: 'center', width: '64px' },
  { title: 'Item', key: 'item_name', width: '30%' },
  { title: 'Available', key: 'available_quantity', width: '19%' },
  { title: 'Status', key: 'state', sortable: false, width: '17%' },
  { title: 'In use', key: 'in_use', sortable: false, width: '14%' },
  { title: '', key: 'actions', sortable: false, align: 'end', width: '14%' },
]

const page = ref(1)
const itemsPerPage = ref(10)
const rowNumber = useRowNumber(page, itemsPerPage)

const filteredEquipments = computed(() => {
  const q = search.value.trim().toLowerCase()
  return equipments.value.filter((e) => {
    const matchesSearch = !q || (e.item_name || '').toLowerCase().includes(q)
    const state = stockState(e)
    let matchesStatus = true
    if (statusFilter.value === 'Available') matchesStatus = state === 'available'
    else if (statusFilter.value === 'Low stock') matchesStatus = state === 'low'
    else if (statusFilter.value === 'Depleted') matchesStatus = state === 'depleted'
    else if (statusFilter.value === 'Needs attention') matchesStatus = state !== 'available'
    return matchesSearch && matchesStatus
  })
})

const itemIcon = (name) => {
  const n = (name || '').toLowerCase()
  if (n.includes('wheelchair')) return 'mdi-wheelchair'
  if (n.includes('megaphone')) return 'mdi-bullhorn-outline'
  if (n.includes('generator')) return 'mdi-engine-outline'
  if (n.includes('first aid') || n.includes('medical')) return 'mdi-medical-bag'
  if (n.includes('oxygen')) return 'mdi-gas-cylinder'
  if (n.includes('rescue') || n.includes('tool')) return 'mdi-toolbox-outline'
  if (n.includes('tent')) return 'mdi-tent'
  if (n.includes('light') || n.includes('lamp')) return 'mdi-flashlight'
  if (n.includes('radio')) return 'mdi-radio-handheld'
  return 'mdi-package-variant-closed'
}

const fetchEquipments = async () => {
  try {
    const res = await fetch(API, { headers: getHeaders() })
    const data = await res.json()
    if (!res.ok) throw new Error(data.message || 'Failed to load inventory')
    equipments.value = data.data || data
  } catch (error) {
    apiError.value = error.message
  } finally {
    initialLoad.value = false
  }
}

const openAdd = () => {
  form.value = { item_name: '', total_quantity: 1, available_quantity: 1, status: 'Available' }
  modal.value = { show: true, editing: false, loading: false, error: '', targetId: null }
}
const openEdit = (item) => {
  form.value = {
    item_name: item.item_name,
    total_quantity: item.total_quantity,
    available_quantity: item.available_quantity,
    status: item.status,
  }
  modal.value = { show: true, editing: true, loading: false, error: '', targetId: item.equipment_id || item.id }
}

const saveEquipment = async () => {
  if (!form.value.item_name.trim() || !form.value.total_quantity) {
    modal.value.error = 'Item name and total owned are required.'
    return
  }
  modal.value.loading = true
  modal.value.error = ''
  const editing = modal.value.editing
  // On add, available mirrors total (the auto-sync rule); on edit it is explicit.
  const payload = {
    item_name: form.value.item_name.trim(),
    total_quantity: form.value.total_quantity,
    available_quantity: editing ? form.value.available_quantity : form.value.total_quantity,
    status: form.value.status,
  }
  try {
    const res = await fetch(editing ? `${API}/${modal.value.targetId}` : API, {
      method: editing ? 'PUT' : 'POST',
      headers: getHeaders(),
      body: JSON.stringify(payload),
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      const msg = data.errors ? Object.values(data.errors).flat().join(' ') : (data.message || 'Save failed')
      throw new Error(msg)
    }
    await fetchEquipments()
    modal.value.show = false
    notify(editing ? 'Equipment updated' : 'Equipment added')
  } catch (error) {
    modal.value.error = error.message
  } finally {
    modal.value.loading = false
  }
}

const askDelete = (item) => { deleteDialog.value = { show: true, item, loading: false } }
const confirmDelete = async () => {
  const item = deleteDialog.value.item
  deleteDialog.value.loading = true
  try {
    const res = await fetch(`${API}/${item.equipment_id || item.id}`, { method: 'DELETE', headers: getHeaders() })
    if (!res.ok) throw new Error('Delete failed')
    await fetchEquipments()
    deleteDialog.value.show = false
    notify('Equipment deleted')
  } catch (error) {
    notify(error.message, 'error')
  } finally {
    deleteDialog.value.loading = false
  }
}

onMounted(fetchEquipments)
</script>

<style scoped>
.tracking-tight { letter-spacing: -0.02em; }
.gap-1 { gap: 4px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
.min-w-0 { min-width: 0; }
.control-field { width: 260px; max-width: 100%; }
.control-field-sm { width: 200px; max-width: 100%; }

.btn-soft-shadow { box-shadow: 0 8px 16px -4px rgba(var(--v-theme-primary), 0.28) !important; transition: transform 0.2s ease, box-shadow 0.2s ease; }
.btn-soft-shadow:hover { transform: translateY(-2px); box-shadow: 0 12px 20px -4px rgba(var(--v-theme-primary), 0.34) !important; }

.group-card {
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08) !important;
}

/* Metric tiles */
.metric-tile {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px 18px;
  border-radius: 16px;
  cursor: default;
  text-align: left;
  transition: transform 0.15s ease, border-color 0.2s ease;
}
.metric-tile[class*="attn"], .metric-tile:has(.mdi-alert-octagon-outline) { cursor: pointer; }
.metric-tile--active { border-color: rgb(var(--v-theme-error)) !important; background: rgba(var(--v-theme-error), 0.06) !important; }
.metric-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: none; }
.metric-number { font-size: 1.9rem; font-weight: 800; line-height: 1.1; letter-spacing: -0.02em; }

/* Inventory list */
.inventory-table :deep(thead th) {
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.icon-wrapper { width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: none; }
.iconbg-available { background: rgba(var(--v-theme-primary), 0.12); }
.iconbg-low { background: rgba(var(--v-theme-warning), 0.14); }
.iconbg-depleted { background: rgba(var(--v-theme-error), 0.14); }

.state-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  align-self: flex-start;
  padding: 3px 10px;
  border-radius: 8px;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.pill-available { background: rgba(var(--v-theme-primary), 0.12); color: rgb(var(--v-theme-primary)); }
.pill-low { background: rgba(var(--v-theme-warning), 0.16); color: rgb(var(--v-theme-warning)); }
.pill-depleted { background: rgba(var(--v-theme-error), 0.16); color: rgb(var(--v-theme-error)); }

.dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex: none; }
.dot-available { background: rgb(var(--v-theme-primary)); }
.dot-low { background: rgb(var(--v-theme-warning)); }
.dot-depleted { background: rgb(var(--v-theme-error)); }

.qty-available { font-size: 1.35rem; font-weight: 800; line-height: 1; letter-spacing: -0.02em; }

.gauge {
  height: 6px;
  max-width: 140px;
  border-radius: 5px;
  background: rgba(var(--v-theme-on-surface), 0.08);
  overflow: hidden;
}
.gauge-fill { display: block; height: 100%; border-radius: 5px; transition: width 0.4s ease; }
.fill-available { background: rgb(var(--v-theme-primary)); }
.fill-low { background: rgb(var(--v-theme-warning)); }
.fill-depleted { background: rgb(var(--v-theme-error)); }

.autosync-note {
  display: flex;
  align-items: center;
  height: 100%;
  min-height: 52px;
  padding: 8px 12px;
  border-radius: 10px;
  font-size: 0.78rem;
  color: rgba(var(--v-theme-on-surface), 0.7);
}

.empty-state {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center; padding: 64px 16px; border-radius: 16px;
}

@media (prefers-reduced-motion: reduce) {
  .metric-tile, .gauge-fill { transition: none; }
  .metric-tile:hover { transform: none; }
}
.row-number {
  font-size: 0.95rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
</style>
