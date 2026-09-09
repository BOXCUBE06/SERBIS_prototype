<template>
  <v-container fluid class="fill-height align-start px-6 px-md-10 pt-4 pb-10 page-background">
    <v-row>
      <v-col cols="12">

        <!-- Header -->
        <div class="d-flex flex-wrap justify-space-between align-center gap-4 mb-6">
          <div>
            <h2 class="page-title text-high-emphasis">Vehicles</h2>
            <div class="page-subtitle text-medium-emphasis">Which units are available and which are currently dispatched</div>
          </div>
          <v-btn color="primary" variant="flat" rounded="lg" height="48" class="px-6 text-none font-weight-bold btn-soft-shadow" @click="openAdd">
            <v-icon start size="20">mdi-plus</v-icon> Add Unit
          </v-btn>
        </div>

        <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6" density="compact" rounded="lg">{{ apiError }}</v-alert>

        <!-- Readiness overview -->
        <v-card v-if="!loading && vehicles.length > 0" elevation="0" rounded="xl" class="group-card pa-6 mb-8">
          <div class="d-flex flex-wrap align-center justify-space-between gap-6">
            <div class="readiness-headline">
              <div class="text-overline font-weight-bold text-medium-emphasis tracking-widest">Fleet Readiness</div>
              <div class="d-flex align-end gap-2">
                <span class="readiness-number text-high-emphasis">{{ counts.Available }}</span>
                <span class="text-h6 text-medium-emphasis mb-1">/ {{ vehicles.length }} ready</span>
              </div>
              <div class="composition-bar mt-3">
                <span class="seg seg-available" :style="{ width: pct('Available') }" :title="`Available ${counts.Available}`"></span>
                <span class="seg seg-dispatched" :style="{ width: pct('Dispatched') }" :title="`Dispatched ${counts.Dispatched}`"></span>
                <span class="seg seg-maintenance" :style="{ width: pct('Maintenance') }" :title="`Maintenance ${counts.Maintenance}`"></span>
              </div>
            </div>

            <div class="d-flex gap-3 flex-wrap">
              <button
                v-for="s in STATUSES"
                :key="s"
                type="button"
                class="stat-tile"
                :class="[{ 'stat-tile--active': statusFilter === s }, `tile-${s.toLowerCase()}`]"
                @click="statusFilter = statusFilter === s ? 'All' : s"
              >
                <span class="dot" :class="`dot-${s.toLowerCase()}`" :data-live="s === 'Available'"></span>
                <span class="stat-value text-high-emphasis">{{ counts[s] }}</span>
                <span class="stat-label text-medium-emphasis">{{ statusMeta[s].label }}</span>
              </button>
            </div>
          </div>
        </v-card>

        <!-- Controls -->
        <div v-if="!loading && vehicles.length > 0" class="d-flex flex-wrap align-center gap-3 mb-6">
          <v-text-field
            v-model="search"
            prepend-inner-icon="mdi-magnify"
            placeholder="Search unit or spec..."
            variant="outlined" density="compact" hide-details rounded="lg"
            class="control-field"
          ></v-text-field>
          <v-select
            v-model="typeFilter"
            :items="typeOptions"
            prepend-inner-icon="mdi-shape-outline"
            variant="outlined" density="compact" hide-details rounded="lg"
            class="control-field-sm"
          ></v-select>
          <v-chip
            v-if="statusFilter !== 'All'"
            closable
            :color="statusMeta[statusFilter]?.color"
            variant="flat"
            class="font-weight-bold"
            @click:close="statusFilter = 'All'"
          >{{ statusFilter }}</v-chip>
        </div>

        <!-- Loading -->
        <template v-if="loading">
          <v-card elevation="0" rounded="xl" class="mb-8 pa-8 group-card">
            <v-skeleton-loader type="table-row@6" class="bg-transparent"></v-skeleton-loader>
          </v-card>
        </template>

        <!-- Empty -->
        <div v-else-if="filteredVehicles.length === 0" class="empty-state group-card">
          <v-icon size="48" class="text-medium-emphasis mb-3">mdi-truck-remove-outline</v-icon>
          <div class="text-subtitle-1 font-weight-bold text-high-emphasis">
            {{ vehicles.length > 0 ? 'No units match your filters' : 'No units in the fleet yet' }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ vehicles.length > 0 ? 'Clear the search or filters to see all units.' : 'Add the first unit to get started.' }}
          </div>
        </div>

        <!-- Fleet list. A card per unit looked handsome and answered none of the
             questions the desk actually asks -- which unit is free, what type it
             is, in one scan down a column. The type is a column and a filter now
             rather than a heading, so a four-type fleet is one list instead of
             four stacked grids. -->
        <v-card v-else elevation="0" rounded="xl" class="group-card overflow-hidden">
          <v-data-table
            :headers="fleetHeaders"
            :items="filteredVehicles"
            :items-per-page="10"
            item-value="vehicle_id"
            density="comfortable"
            class="fleet-table"
          >
            <template v-slot:item.rowNumber="{ item }">
              <span class="row-number text-medium-emphasis">{{ rowNumber(item) }}</span>
            </template>

            <template v-slot:item.unit_identifier="{ item }">
              <div class="d-flex align-center gap-3 py-2">
                <div class="icon-wrapper" :class="`iconbg-${item.status.toLowerCase()}`">
                  <v-icon :color="statusMeta[item.status].color" size="22">{{ getVehicleIcon(item.type) }}</v-icon>
                </div>
                <div class="min-w-0">
                  <div class="text-body-1 font-weight-bold text-high-emphasis text-truncate">{{ item.unit_identifier }}</div>
                  <div class="text-caption text-medium-emphasis text-truncate">
                    {{ item.specification || 'Standard Unit' }}
                  </div>
                </div>
              </div>
            </template>

            <template v-slot:item.type="{ item }">
              <span class="text-body-2 font-weight-medium text-high-emphasis">{{ item.type }}</span>
            </template>

            <template v-slot:item.status="{ item }">
              <v-menu location="bottom">
                <template v-slot:activator="{ props }">
                  <button
                    type="button"
                    class="status-pill"
                    :class="`pill-${item.status.toLowerCase()}`"
                    v-bind="props"
                    :aria-label="`${item.unit_identifier} is ${item.status}. Change status`"
                  >
                    <span class="dot" :class="`dot-${item.status.toLowerCase()}`" :data-live="item.status === 'Available'"></span>
                    <span class="font-weight-bold text-uppercase">{{ item.status }}</span>
                    <v-icon size="16" class="ml-auto">mdi-chevron-down</v-icon>
                  </button>
                </template>
                <v-list density="compact" rounded="lg">
                  <v-list-item
                    v-for="s in STATUSES" :key="s"
                    :disabled="s === item.status"
                    @click="promptStatusChange(item, s)"
                  >
                    <template v-slot:prepend><span class="dot mr-3" :class="`dot-${s.toLowerCase()}`"></span></template>
                    <v-list-item-title>{{ s }}</v-list-item-title>
                  </v-list-item>
                </v-list>
              </v-menu>
            </template>

            <template v-slot:item.actions="{ item }">
              <div class="d-flex justify-end gap-1">
                <v-btn
                  icon="mdi-pencil-outline" variant="text" size="small"
                  :aria-label="`Edit ${item.unit_identifier}`" @click="openEdit(item)"
                ></v-btn>
                <v-btn
                  icon="mdi-delete-outline" variant="text" size="small" color="error"
                  :aria-label="`Delete ${item.unit_identifier}`" @click="askDelete(item)"
                ></v-btn>
              </div>
            </template>
          </v-data-table>
        </v-card>

      </v-col>
    </v-row>

    <!-- Status confirm -->
    <v-dialog v-model="statusDialog.show" max-width="420">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">Update status</v-card-title>
        <v-card-text class="px-6 py-4 text-body-1 text-medium-emphasis">
          Change <span class="font-weight-bold text-high-emphasis">{{ statusDialog.vehicle?.unit_identifier }}</span> to
          <span class="font-weight-bold text-uppercase" :class="`text-${statusMeta[statusDialog.newStatus]?.color}`">{{ statusDialog.newStatus }}</span>?
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="text" rounded="lg" class="text-none" :disabled="statusDialog.loading" @click="statusDialog.show = false">Cancel</v-btn>
          <v-btn color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" :loading="statusDialog.loading" @click="executeStatusChange">Confirm</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Add / Edit -->
    <v-dialog v-model="formDialog.show" max-width="480" persistent>
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="d-flex justify-space-between align-center pa-6 pb-2">
          <span class="text-h6 font-weight-bold text-high-emphasis">{{ formDialog.editing ? 'Edit unit' : 'Add unit' }}</span>
          <v-btn icon="mdi-close" variant="text" size="small" @click="formDialog.show = false"></v-btn>
        </v-card-title>
        <v-card-text class="px-6 py-2">
          <v-alert v-if="formDialog.error" type="error" variant="tonal" density="compact" rounded="lg" class="mb-4" role="alert">{{ formDialog.error }}</v-alert>
          <v-form ref="formRef">
            <v-text-field v-model="form.unit_identifier" label="Unit identifier *" placeholder="e.g. AMB-01" :rules="[requiredRule('Unit identifier')]" :error-messages="fieldErrors.unit_identifier" variant="outlined" density="comfortable" rounded="lg" class="mb-3"></v-text-field>
            <v-select v-model="form.type" :items="VEHICLE_TYPES" label="Type *" :rules="[requiredRule('Type')]" :error-messages="fieldErrors.type" variant="outlined" density="comfortable" rounded="lg" class="mb-3"></v-select>
            <v-text-field v-model="form.specification" label="Specification" placeholder="e.g. TYPE I" :error-messages="fieldErrors.specification" variant="outlined" density="comfortable" rounded="lg" class="mb-3"></v-text-field>
            <v-select v-model="form.status" :items="STATUSES" label="Status *" :rules="[requiredRule('Status')]" :error-messages="fieldErrors.status" variant="outlined" density="comfortable" rounded="lg"></v-select>
          </v-form>
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="text" rounded="lg" class="text-none" :disabled="formDialog.loading" @click="formDialog.show = false">Cancel</v-btn>
          <v-btn color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" :loading="formDialog.loading" @click="saveVehicle">
            {{ formDialog.editing ? 'Save' : 'Add unit' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete confirm -->
    <v-dialog v-model="deleteDialog.show" max-width="420">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">Delete unit?</v-card-title>
        <v-card-text class="px-6 py-4 text-body-2 text-medium-emphasis">
          <strong class="text-high-emphasis">{{ deleteDialog.vehicle?.unit_identifier }}</strong> will be permanently removed from the fleet. This cannot be undone.
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
import { useRowNumbers } from '@/composables/rowNumber'
import { API_BASE } from '@/config/api'

const API = `${API_BASE}/vehicles`
const STATUSES = ['Available', 'Dispatched', 'Maintenance']
const VEHICLE_TYPES = ['Ambulance', 'Rescue Vehicle', 'Fire Truck', 'Boat']

// Available = primary green (success tracks primary), Dispatched = warning, Maintenance = error.
const statusMeta = {
  Available: { color: 'primary', label: 'Available' },
  Dispatched: { color: 'warning', label: 'Dispatched' },
  Maintenance: { color: 'error', label: 'Maintenance' },
}

const vehicles = ref([])
const loading = ref(false)
const apiError = ref('')
const search = ref('')
const typeFilter = ref('All')
const statusFilter = ref('All')

const statusDialog = ref({ show: false, vehicle: null, newStatus: '', loading: false })
const formDialog = ref({ show: false, editing: false, loading: false, error: '' })
const deleteDialog = ref({ show: false, vehicle: null, loading: false })
const form = ref({ unit_identifier: '', type: 'Ambulance', specification: '', status: 'Available' })
const snackbar = ref({ show: false, text: '', color: 'success' })

// Template ref for the Add/Edit <v-form> — named formRef, not form, because
// `form` above is already the reactive object the fields are bound to.
const formRef = ref(null)

const requiredRule = (label) => (v) =>
  (v !== null && v !== undefined && String(v).trim() !== '') || `${label} is required.`

// Server-side errors, keyed by field, so a 422 lands on the input it belongs
// to instead of being concatenated into the banner above the form.
const fieldErrors = ref({})
const clearFieldErrors = () => { fieldErrors.value = {} }

// Laravel answers `{errors: {field: [msg]}}`. Split it: known fields go to
// their input, anything unrecognised stays in the banner so nothing is
// silently swallowed. Mirrors UsersView's applyServerErrors.
const applyServerErrors = async (res) => {
  const data = await res.json().catch(() => ({}))
  if (data.errors && typeof data.errors === 'object') {
    const mapped = {}
    const leftovers = []
    for (const [key, messages] of Object.entries(data.errors)) {
      const text = Array.isArray(messages) ? messages.join(' ') : String(messages)
      if (key in form.value) mapped[key] = text
      else leftovers.push(text)
    }
    fieldErrors.value = mapped
    return leftovers.length > 0 ? leftovers.join(' ') : 'Please correct the highlighted fields.'
  }
  return data.message || 'Save failed'
}

const notify = (text, color = 'success') => { snackbar.value = { show: true, text, color } }
const getHeaders = () => ({ Authorization: `Bearer ${getToken()}`, 'Content-Type': 'application/json', Accept: 'application/json' })

const formatStatus = (status) => {
  const s = (status || '').toLowerCase()
  if (s === 'dispatched') return 'Dispatched'
  if (s === 'maintenance') return 'Maintenance'
  return 'Available'
}

const counts = computed(() => {
  const c = { Available: 0, Dispatched: 0, Maintenance: 0 }
  for (const v of vehicles.value) c[v.status] = (c[v.status] || 0) + 1
  return c
})
const pct = (s) => vehicles.value.length > 0 ? `${(counts.value[s] / vehicles.value.length) * 100}%` : '0%'

const typeOptions = computed(() => ['All', ...VEHICLE_TYPES.filter((t) => vehicles.value.some((v) => v.type === t))])

const filteredVehicles = computed(() => {
  const q = search.value.trim().toLowerCase()
  return vehicles.value.filter((v) => {
    const matchesType = typeFilter.value === 'All' || v.type === typeFilter.value
    const matchesStatus = statusFilter.value === 'All' || v.status === statusFilter.value
    const matchesSearch = !q ||
      (v.unit_identifier || '').toLowerCase().includes(q) ||
      (v.specification || '').toLowerCase().includes(q)
    return matchesType && matchesStatus && matchesSearch
  })
})

const rowNumber = useRowNumbers(filteredVehicles, 'vehicle_id')

const fleetHeaders = [
  { title: '#', key: 'rowNumber', sortable: false, align: 'center', width: '64px' },
  { title: 'Unit', key: 'unit_identifier', width: '32%' },
  { title: 'Type', key: 'type', width: '21%' },
  { title: 'Status', key: 'status', width: '23%' },
  { title: '', key: 'actions', sortable: false, align: 'end', width: '19%' },
]

const getVehicleIcon = (type) => ({
  ambulance: 'mdi-ambulance',
  'fire truck': 'mdi-fire-truck',
  'rescue vehicle': 'mdi-car-emergency',
  boat: 'mdi-ferry',
}[type?.toLowerCase()] || 'mdi-car')

const fetchVehicles = async () => {
  loading.value = true
  try {
    const res = await fetch(API, { headers: getHeaders() })
    const data = await res.json()
    if (!res.ok) throw new Error(data.message || 'Failed to fetch fleet data')
    vehicles.value = (Array.isArray(data) ? data : (data.data || [])).map((v) => ({ ...v, status: formatStatus(v.status) }))
  } catch (error) {
    apiError.value = error.message
    vehicles.value = []
  } finally {
    loading.value = false
  }
}

// --- Status change (commits only after confirm; no optimistic mutation) ---
const promptStatusChange = (vehicle, newStatus) => {
  if (newStatus === vehicle.status) return
  statusDialog.value = { show: true, vehicle, newStatus, loading: false }
}

const executeStatusChange = async () => {
  const { vehicle, newStatus } = statusDialog.value
  statusDialog.value.loading = true
  apiError.value = ''
  const id = vehicle.vehicle_id || vehicle.id
  try {
    const res = await fetch(`${API}/${id}`, { method: 'PUT', headers: getHeaders(), body: JSON.stringify({ status: newStatus }) })
    if (!res.ok) throw new Error('Failed to update status')
    vehicle.status = newStatus
    statusDialog.value.show = false
    notify(`${vehicle.unit_identifier} set to ${newStatus}`)
  } catch (error) {
    apiError.value = `Error updating ${vehicle.unit_identifier}: ${error.message}`
    notify(error.message, 'error')
  } finally {
    statusDialog.value.loading = false
  }
}

// --- Add / Edit ---
const openAdd = () => {
  form.value = { unit_identifier: '', type: 'Ambulance', specification: '', status: 'Available' }
  formDialog.value = { show: true, editing: false, loading: false, error: '' }
  clearFieldErrors()
  formRef.value?.resetValidation()
}
const openEdit = (vehicle) => {
  form.value = {
    vehicle_id: vehicle.vehicle_id || vehicle.id,
    unit_identifier: vehicle.unit_identifier,
    type: vehicle.type,
    specification: vehicle.specification || '',
    status: vehicle.status,
  }
  formDialog.value = { show: true, editing: true, loading: false, error: '' }
  clearFieldErrors()
  formRef.value?.resetValidation()
}

const saveVehicle = async () => {
  formDialog.value.error = ''
  clearFieldErrors()

  // Validate before spending a round trip. Vuetify focuses the first invalid
  // field itself once the rules are attached.
  const { valid } = await formRef.value.validate()
  if (!valid) {
    formDialog.value.error = 'Please correct the highlighted fields.'
    return
  }

  formDialog.value.loading = true
  const editing = formDialog.value.editing
  const url = editing ? `${API}/${form.value.vehicle_id}` : API
  const payload = {
    unit_identifier: form.value.unit_identifier.trim(),
    type: form.value.type,
    specification: form.value.specification?.trim() || null,
    status: form.value.status,
  }
  try {
    const res = await fetch(url, { method: editing ? 'PUT' : 'POST', headers: getHeaders(), body: JSON.stringify(payload) })
    if (!res.ok) throw new Error(await applyServerErrors(res))
    await fetchVehicles()
    formDialog.value.show = false
    notify(editing ? 'Unit updated' : 'Unit added')
  } catch (error) {
    formDialog.value.error = error.message
  } finally {
    formDialog.value.loading = false
  }
}

// --- Delete ---
const askDelete = (vehicle) => { deleteDialog.value = { show: true, vehicle, loading: false } }
const confirmDelete = async () => {
  const vehicle = deleteDialog.value.vehicle
  deleteDialog.value.loading = true
  const id = vehicle.vehicle_id || vehicle.id
  try {
    const res = await fetch(`${API}/${id}`, { method: 'DELETE', headers: getHeaders() })
    if (!res.ok) throw new Error('Delete failed')
    await fetchVehicles()
    deleteDialog.value.show = false
    notify('Unit deleted')
  } catch (error) {
    notify(error.message, 'error')
  } finally {
    deleteDialog.value.loading = false
  }
}

onMounted(fetchVehicles)
</script>

<style scoped>
.page-background { background-color: rgb(var(--v-theme-background)) !important; }
.tracking-widest { letter-spacing: 0.12em; }
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
.gap-6 { gap: 24px; }
.min-w-0 { min-width: 0; }
.control-field { width: 260px; max-width: 100%; }
.control-field-sm { width: 180px; max-width: 100%; }

.btn-soft-shadow { box-shadow: 0 8px 16px -4px rgba(var(--v-theme-primary), 0.28) !important; transition: transform 0.2s ease, box-shadow 0.2s ease; }
.btn-soft-shadow:hover { transform: translateY(-2px); box-shadow: 0 12px 20px -4px rgba(var(--v-theme-primary), 0.34) !important; }

.group-card {
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08) !important;
  box-shadow: 0 12px 40px -12px rgba(var(--v-theme-on-surface), 0.05) !important;
}

/* Readiness overview */
.readiness-number { font-size: 3rem; font-weight: 800; line-height: 1; letter-spacing: -0.03em; }
.composition-bar {
  display: flex;
  height: 10px;
  width: 260px;
  max-width: 60vw;
  border-radius: 6px;
  overflow: hidden;
  background: rgba(var(--v-theme-on-surface), 0.08);
}
.composition-bar .seg { height: 100%; transition: width 0.4s ease; }
.seg-available { background: rgb(var(--v-theme-primary)); }
.seg-dispatched { background: rgb(var(--v-theme-warning)); }
.seg-maintenance { background: rgb(var(--v-theme-error)); }

.stat-tile {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  min-width: 104px;
  padding: 14px 16px;
  border-radius: 14px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.1);
  background: rgba(var(--v-theme-on-surface), 0.02);
  cursor: pointer;
  transition: border-color 0.2s ease, background-color 0.2s ease, transform 0.15s ease;
  text-align: left;
}
.stat-tile:hover { transform: translateY(-2px); }
.stat-tile--active.tile-available { border-color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), 0.08); }
.stat-tile--active.tile-dispatched { border-color: rgb(var(--v-theme-warning)); background: rgba(var(--v-theme-warning), 0.1); }
.stat-tile--active.tile-maintenance { border-color: rgb(var(--v-theme-error)); background: rgba(var(--v-theme-error), 0.1); }
.stat-value { font-size: 1.6rem; font-weight: 800; line-height: 1.1; }
.stat-label { font-size: 0.75rem; font-weight: 600; }

/* Status dots */
.dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; flex: none; }
.dot-available { background: rgb(var(--v-theme-primary)); }
.dot-dispatched { background: rgb(var(--v-theme-warning)); }
.dot-maintenance { background: rgb(var(--v-theme-error)); }
.dot[data-live="true"] { box-shadow: 0 0 0 0 rgba(var(--v-theme-primary), 0.5); animation: livePulse 2s infinite; }
@keyframes livePulse {
  0% { box-shadow: 0 0 0 0 rgba(var(--v-theme-primary), 0.5); }
  70% { box-shadow: 0 0 0 6px rgba(var(--v-theme-primary), 0); }
  100% { box-shadow: 0 0 0 0 rgba(var(--v-theme-primary), 0); }
}

/* Fleet list. Fixed layout keeps the five columns stable regardless of unit-
   identifier length. */
.fleet-table :deep(table) { table-layout: fixed !important; width: 100% !important; min-width: 640px; }
.fleet-table :deep(thead th) {
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.icon-wrapper { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: none; }
.iconbg-available { background: rgba(var(--v-theme-primary), 0.12); }
.iconbg-dispatched { background: rgba(var(--v-theme-warning), 0.14); }
.iconbg-maintenance { background: rgba(var(--v-theme-error), 0.14); }

/* Status pill (opens the change menu) */
.status-pill {
  display: flex;
  align-items: center;
  gap: 8px;
  /* Sized to its own label in a table cell. A full-width pill was right in a
     card and reads as a stray button across a column. */
  min-width: 148px;
  padding: 6px 12px;
  border-radius: 10px;
  font-size: 0.82rem;
  letter-spacing: 0.04em;
  border: none;
  cursor: pointer;
  transition: filter 0.15s ease;
}
.status-pill:hover { filter: brightness(0.97); }
/* Text uses the -strong tokens, not the plain ones: raw primary/warning/
   error on their own tint measures under AA (see plugins/vuetify.ts for the
   ratios) — same fix as UsersView's avatar initials and pill-pending. */
.pill-available { background: rgba(var(--v-theme-primary), 0.12); color: rgb(var(--v-theme-primary-strong)); }
.pill-dispatched { background: rgba(var(--v-theme-warning), 0.16); color: rgb(var(--v-theme-warning-strong)); }
.pill-maintenance { background: rgba(var(--v-theme-error), 0.16); color: rgb(var(--v-theme-error-strong)); }

.empty-state {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center; padding: 64px 16px; border-radius: 16px;
}

@media (prefers-reduced-motion: reduce) {
  .stat-tile, .composition-bar .seg { transition: none; }
  .stat-tile:hover { transform: none; }
  .dot[data-live="true"] { animation: none; }
}
.row-number {
  font-size: 0.95rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
</style>
