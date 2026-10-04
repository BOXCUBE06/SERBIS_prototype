<template>
  <v-container fluid class="fill-height align-start bg-background">
    <PageHeader title="Resource Management" class="mb-5">
      <template v-slot:subtitle>{{ subtitle }}</template>
      <template v-slot:actions>
        <v-btn color="primary" variant="flat" class="text-none font-weight-bold" height="40" prepend-icon="mdi-plus" @click="openAdd">
          Add equipment
        </v-btn>
      </template>
    </PageHeader>

    <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6 w-100" density="compact" rounded="lg">{{ apiError }}</v-alert>

    <div class="w-100">
      <!-- A list, not a card grid: the availability column reads straight down,
           and sorting on it puts whatever is running out at the top. -->
      <DataTablePage
        :refreshing="refreshing"
        compact
        filter-bar
        range-summary
        collapse-mobile
        @click:row="(_event, { item }) => openEdit(item)"
        :tabs="statusTabs"
        :status="statusFilter"
        @update:status="statusFilter = $event"
        :loading="initialLoad"
        v-model:search="search"
        search-placeholder="Search equipment"
        :headers="inventoryHeaders"
        :items="filteredEquipments"
        item-value="equipment_id"
        :no-data-text="equipments.length > 0 ? 'No items match your filters' : 'No equipment yet'"
        :page="page"
        @update:page="page = $event"
        :items-per-page="itemsPerPage"
        @update:items-per-page="itemsPerPage = $event"
        result-noun="items"
      >
        <template v-slot:item.item_name="{ item }">
          <PersonCell :name="item.item_name" icon="mdi-package-variant-closed" tinted size="36" />
        </template>

        <template v-slot:item.available_quantity="{ item }">
          <div class="py-1">
            <div class="d-flex align-baseline gap-1">
              <span class="qty-available text-high-emphasis">{{ item.available_quantity }}</span>
              <span class="text-body-2 text-medium-emphasis">/ {{ item.total_quantity }}</span>
            </div>
            <div class="gauge mt-1">
              <span class="gauge-fill" :class="`fill-${stockState(item)}`" :style="{ width: gaugePct(item) }"></span>
            </div>
          </div>
        </template>

        <template v-slot:item.state="{ item }">
          <StatusChip :status="stateLabel(item)" />
        </template>

        <template v-slot:item.in_use="{ item }">
          <span class="text-body-2 text-medium-emphasis">{{ inUse(item) }} in use</span>
        </template>

        <template v-slot:item.actions="{ item }">
          <RowActions :label="item.item_name" @edit="openEdit(item)" @delete="askDelete(item)" />
        </template>
      </DataTablePage>
    </div>


    <!-- Add / Edit -->
    <EditDialog
      v-model="modal.show"
      :title="modal.editing ? 'Edit equipment' : 'Add equipment'"
      :confirm-label="modal.editing ? 'Save' : 'Add'"
      :fields="formFields"
      :form="form"
      :error="modal.error"
      :loading="modal.loading"
      @save="saveEquipment"
    />

    <!-- Delete confirm -->
    <v-dialog v-model="deleteDialog.show" max-width="420">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">Delete equipment?</v-card-title>
        <v-card-text class="px-6 py-4 text-body-2 text-medium-emphasis">
          <strong class="text-high-emphasis">{{ deleteDialog.item?.item_name }}</strong> will be permanently removed from the inventory. This cannot be undone.
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="outlined" color="primary" rounded="lg" class="text-none" :disabled="deleteDialog.loading" @click="deleteDialog.show = false">Cancel</v-btn>
          <v-btn color="error" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" :loading="deleteDialog.loading" @click="confirmDelete">Delete</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3500" location="bottom right" rounded="lg">{{ snackbar.text }}</v-snackbar>
  </v-container>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { getToken } from '@/composables/authToken'
import { API_BASE } from '@/config/api'
import { invalidate, useCachedFetch } from '@/composables/useCachedFetch'
import { pluralize } from '@/composables/adminUi'
import PageHeader from '@/components/PageHeader.vue'
import DataTablePage from '@/components/DataTablePage.vue'
import PersonCell from '@/components/PersonCell.vue'
import StatusChip from '@/components/StatusChip.vue'
import RowActions from '@/components/RowActions.vue'
import EditDialog from '@/components/EditDialog.vue'

const API = `${API_BASE}/equipments`

const equipments = ref([])
const search = ref('')
const statusFilter = ref('All')
const page = ref(1)
const itemsPerPage = ref(10)
const initialLoad = ref(true)
const apiError = ref('')

const modal = ref({ show: false, editing: false, loading: false, error: '', targetId: null })
const form = ref({ item_name: '', total_quantity: 1, available_quantity: 1, status: 'Available' })
const deleteDialog = ref({ show: false, item: null, loading: false })
const snackbar = ref({ show: false, text: '', color: 'success' })

// Total and Available sit side by side; on add, Available is just a note (it mirrors total).
const formFields = computed(() => [
  { key: 'item_name', label: 'Item name', required: true, placeholder: 'Folding stretcher' },
  { key: 'total_quantity', label: 'Total owned', required: true, placeholder: '12', type: 'number', min: 1, half: true },
  modal.value.editing
    ? { key: 'available_quantity', label: 'Available now', required: true, placeholder: '9', type: 'number', min: 0, max: form.value.total_quantity, half: true }
    : { key: 'available_quantity', note: 'Available starts equal to total', half: true },
  { key: 'status', label: 'Status', required: true, items: ['Available', 'Unavailable'] },
])

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

// Search narrows the rows first; the tabs then split that set into items in
// stock and items running low or out, so each tab's count is what it would show.
const baseEquipments = computed(() => {
  const q = search.value.trim().toLowerCase()
  return equipments.value.filter((e) => !q || (e.item_name || '').toLowerCase().includes(q))
})
const inStock = (e) => stockState(e) === 'available'
const filteredEquipments = computed(() => {
  if (statusFilter.value === 'Available') return baseEquipments.value.filter((e) => inStock(e))
  if (statusFilter.value === 'Needs attention') return baseEquipments.value.filter((e) => !inStock(e))
  return baseEquipments.value
})
const statusTabs = computed(() => [
  { value: 'All', label: 'All', count: baseEquipments.value.length },
  { value: 'Available', label: 'Available', count: baseEquipments.value.filter((e) => inStock(e)).length },
  { value: 'Needs attention', label: 'Low / Depleted', count: baseEquipments.value.filter((e) => !inStock(e)).length },
])

// Header subtitle: unit totals over the whole inventory ("Available" sums units
// here, which is why it is not a tab of its own).
const subtitle = computed(() => {
  const rows = equipments.value
  const sum = (key) => rows.reduce((total, e) => total + (e[key] || 0), 0)
  return `${pluralize(rows.length, 'item')} ·${sum('total_quantity')} owned · ${sum('available_quantity')} available`
})

watch([search, statusFilter], () => { page.value = 1 })

// `state` is derived, not a column on the row, so Status sorts on its label
// through `value`; `available_quantity` sorts on the number; `in_use` does not
// sort. Fixed-layout table with the same first three columns as Vehicles and
// Responders, so Status lines up across them. In use goes on a phone.
const HIDE_SM = { class: 'dtp-hide-sm' }
const inventoryHeaders = [
  { title: 'Item', key: 'item_name', width: '35%' },
  { title: 'Available', key: 'available_quantity', width: '200px' },
  { title: 'Status', key: 'state', value: (e) => stateLabel(e), width: '160px' },
  { title: 'In use', key: 'in_use', sortable: false, width: '120px', headerProps: HIDE_SM, cellProps: HIDE_SM },
  { title: 'Actions', key: 'actions', sortable: false, align: 'end', width: '120px' },
]

const { get, refreshing } = useCachedFetch()

const fetchEquipments = async (fresh = false) => {
  try {
    await get('/equipments', {
      fresh,
      onData: (data) => { equipments.value = data.data || data; initialLoad.value = false },
    })
  } catch (error) {
    apiError.value = error.message
  } finally {
    initialLoad.value = false
  }
}

// After a write: drop the cached list, then fetch past it.
const reload = () => { invalidate('/equipments'); return fetchEquipments(true) }

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
    await reload()
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
    await reload()
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
.gap-1 { gap: 4px; }
.gap-3 { gap: 12px; }

.qty-available { font-size: 1rem; font-weight: 600; font-variant-numeric: tabular-nums; }

.gauge {
  height: 6px;
  max-width: 140px;
  border-radius: 5px;
  background: rgba(var(--v-theme-on-surface), 0.08);
  overflow: hidden;
}
.gauge-fill { display: block; height: 100%; border-radius: 5px; }
.fill-available { background: rgb(var(--v-theme-primary)); }
.fill-low { background: rgb(var(--v-theme-warning)); }
.fill-depleted { background: rgb(var(--v-theme-error)); }
</style>
