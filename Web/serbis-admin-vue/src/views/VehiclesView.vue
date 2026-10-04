<template>
  <v-container fluid class="fill-height align-start page-background">
    <PageHeader title="Vehicles">
      <template v-slot:actions>
        <!-- One child, so the slot's 12px gap does not apply: 8px between the two. -->
        <div class="d-flex align-center header-actions">
          <ExportMenu type="vehicle" :rows="filteredVehicles" plain height="36" />
          <v-btn color="primary" variant="flat" rounded="lg" height="36" class="px-5 text-none font-weight-bold" @click="openAdd">
            <v-icon start size="18">mdi-plus</v-icon> Add Unit
          </v-btn>
        </div>
      </template>
    </PageHeader>

    <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6 w-100" density="compact" rounded="lg">{{ apiError }}</v-alert>

    <div class="w-100">
      <!-- The type is a column and a filter, not a heading, so a four-type
           fleet is one list to scan for the free unit. Status is the tabs. -->
      <DataTablePage
        compact
        collapse-mobile
        @click:row="(_event, { item }) => openEdit(item)"
        :tabs="statusTabs"
        :status="statusFilter"
        @update:status="statusFilter = $event"
        :loading="firstLoad"
        :refreshing="refreshing"
        v-model:search="search"
        search-placeholder="Search unit or specification"
        :headers="fleetHeaders"
        :items="filteredVehicles"
        item-value="vehicle_id"
        :no-data-text="vehicles.length > 0 ? 'No units match your filters' : 'No units in the fleet yet'"
        :page="page"
        @update:page="page = $event"
        :items-per-page="itemsPerPage"
        @update:items-per-page="itemsPerPage = $event"
        result-noun="units"
        :active-filters="activeFilters"
        @clear-filter="clearFilter"
        @clear-all="clearAllFilters"
      >
        <template v-slot:filters>
          <v-select
            v-model="typeFilter"
            :items="typeOptions"
            label="Type"
            variant="outlined" density="compact" hide-details rounded="lg"
          ></v-select>
        </template>

        <template v-slot:item.unit_identifier="{ item }">
          <PersonCell
            :name="item.unit_identifier"
            :secondary="item.specification || 'Standard Unit'"
            :icon="getVehicleIcon(item.type)"
            tinted
            size="36"
          />
        </template>

        <template v-slot:item.status="{ item }">
          <StatusChip
            :status="item.status"
            :items="statusChoices"
            :current="item.status"
            :aria-label="`${item.unit_identifier} is ${item.status}. Change status`"
            @select="promptStatusChange(item, $event)"
          />
        </template>

        <template v-slot:item.actions="{ item }">
          <RowActions :label="item.unit_identifier" @edit="openEdit(item)" @delete="askDelete(item)" />
        </template>
      </DataTablePage>
    </div>


    <!-- Status confirm -->
    <v-dialog v-model="statusDialog.show" max-width="420">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">Update status</v-card-title>
        <v-card-text class="px-6 py-4 text-body-1 text-medium-emphasis">
          Change <span class="font-weight-bold text-high-emphasis">{{ statusDialog.vehicle?.unit_identifier }}</span> to
          <span class="font-weight-bold text-uppercase" :class="`text-${statusMeta[statusDialog.newStatus]?.color}`">{{ statusDialog.newStatus }}</span>?
          <!-- Known before Confirm — the fleet list this dialog reads from
               already carries conflicting_bookings per unit (VehicleController::
               index()), so this needs no extra call. Informational only: the
               change still goes through either way (Maintenance keeps its own
               server-side block for this same list; this is for Dispatched and
               any other non-Available target). -->
          <div v-if="statusDialog.vehicle?.conflicting_bookings?.length" class="mt-4">
            <div class="text-caption font-weight-bold text-uppercase text-warning mb-1">
              {{ statusDialog.vehicle.conflicting_bookings.length }} booking{{ statusDialog.vehicle.conflicting_bookings.length === 1 ? '' : 's' }} on this unit will need a new one
            </div>
            <div v-for="b in statusDialog.vehicle.conflicting_bookings" :key="b.request_id" class="text-body-2">
              {{ transactionNo(b.request_id) }} — {{ b.patient_name || 'Unnamed patient' }} — {{ fmtDateTime(b.scheduled_at) }}
            </div>
          </div>
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="outlined" color="primary" rounded="lg" class="text-none" :disabled="statusDialog.loading" @click="statusDialog.show = false">Cancel</v-btn>
          <v-btn color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" :loading="statusDialog.loading" @click="executeStatusChange">Confirm</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Add / Edit -->
    <v-dialog v-model="formDialog.show" max-width="480" persistent>
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="d-flex justify-space-between align-center pa-6 pb-2">
          <span class="text-h6 font-weight-bold text-high-emphasis">{{ formDialog.editing ? 'Edit unit' : 'Add unit' }}</span>
          <v-btn icon="mdi-close" variant="tonal" rounded="circle" size="small" @click="formDialog.show = false"></v-btn>
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
          <v-btn variant="outlined" color="primary" rounded="lg" class="text-none" :disabled="formDialog.loading" @click="formDialog.show = false">Cancel</v-btn>
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
          <v-btn variant="outlined" color="primary" rounded="lg" class="text-none" :disabled="deleteDialog.loading" @click="deleteDialog.show = false">Cancel</v-btn>
          <v-btn color="error" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" :loading="deleteDialog.loading" @click="confirmDelete">Delete</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="snackbar.link ? 6000 : 3500" location="bottom right" rounded="lg">
      {{ snackbar.text }}
      <template v-if="snackbar.link" v-slot:actions>
        <v-btn variant="text" class="text-none font-weight-bold" @click="router.push(snackbar.link); snackbar.show = false">View</v-btn>
      </template>
    </v-snackbar>
  </v-container>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { getToken } from '@/composables/authToken'
import { API_BASE } from '@/config/api'
import { invalidate, useCachedFetch } from '@/composables/useCachedFetch'
import { transactionNo } from '@/composables/requestDisplay'
import PageHeader from '@/components/PageHeader.vue'
import DataTablePage from '@/components/DataTablePage.vue'
import PersonCell from '@/components/PersonCell.vue'
import StatusChip from '@/components/StatusChip.vue'
import RowActions from '@/components/RowActions.vue'
import ExportMenu from '@/components/ExportMenu.vue'

const router = useRouter()

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
// Skeleton rows only while nothing is cached; a revisit shows the last list and dims it while it refreshes.
const { get, loading: firstLoad, refreshing } = useCachedFetch()
const apiError = ref('')
const search = ref('')
const typeFilter = ref('All')
const statusFilter = ref('All')
const page = ref(1)
const itemsPerPage = ref(10)

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

const notify = (text, color = 'success', link = null) => { snackbar.value = { show: true, text, color, link } }
const getHeaders = () => ({ Authorization: `Bearer ${getToken()}`, 'Content-Type': 'application/json', Accept: 'application/json' })

const formatStatus = (status) => {
  const s = (status || '').toLowerCase()
  if (s === 'dispatched') return 'Dispatched'
  if (s === 'maintenance') return 'Maintenance'
  return 'Available'
}

const fmtDateTime = (iso) => iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''

const statusChoices = STATUSES.map((s) => ({ value: s, label: s, status: s }))

const typeOptions = computed(() => ['All', ...VEHICLE_TYPES.filter((t) => vehicles.value.some((v) => v.type === t))])

// Search and type narrow the rows first; the tabs then split that set by status,
// so each tab's count is what it would show.
const baseVehicles = computed(() => {
  const q = search.value.trim().toLowerCase()
  return vehicles.value.filter((v) => {
    const matchesType = typeFilter.value === 'All' || v.type === typeFilter.value
    const matchesSearch = !q ||
      (v.unit_identifier || '').toLowerCase().includes(q) ||
      (v.specification || '').toLowerCase().includes(q)
    return matchesType && matchesSearch
  })
})
const filteredVehicles = computed(() =>
  statusFilter.value === 'All' ? baseVehicles.value : baseVehicles.value.filter((v) => v.status === statusFilter.value),
)
const statusTabs = computed(() => [
  { value: 'All', label: 'All', count: baseVehicles.value.length },
  ...STATUSES.map((s) => ({ value: s, label: s, count: baseVehicles.value.filter((v) => v.status === s).length })),
])

// Status is the tabs, so it is not a chip here.
const activeFilters = computed(() => (typeFilter.value === 'All' ? [] : [{ key: 'type', label: `Type: ${typeFilter.value}` }]))
const clearFilter = () => { typeFilter.value = 'All' }
const clearAllFilters = clearFilter
watch([search, statusFilter, typeFilter], () => { page.value = 1 })

// Fixed-layout table: identity gets the room, the rest are sized to their content.
// Identity 35% + a 200px second column + Status 160px is the same geometry as
// Responders and Resource Management, so Status lines up across the three. On a
// phone Type goes (the icon already says it); see DataTablePage's collapseMobile.
const HIDE_SM = { class: 'dtp-hide-sm' }
const fleetHeaders = [
  { title: 'Unit', key: 'unit_identifier', width: '35%' },
  { title: 'Type', key: 'type', width: '200px', headerProps: HIDE_SM, cellProps: HIDE_SM },
  { title: 'Status', key: 'status', width: '160px' },
  { title: 'Actions', key: 'actions', sortable: false, align: 'end', width: '96px' },
]

const getVehicleIcon = (type) => ({
  ambulance: 'mdi-ambulance',
  'fire truck': 'mdi-fire-truck',
  'rescue vehicle': 'mdi-car-emergency',
  boat: 'mdi-ferry',
}[type?.toLowerCase()] || 'mdi-car')

const fetchVehicles = async ({ fresh = false } = {}) => {
  try {
    await get('/vehicles', {
      fresh,
      onData: (data) => {
        apiError.value = ''
        vehicles.value = (Array.isArray(data) ? data : (data.data || [])).map((v) => ({ ...v, status: formatStatus(v.status) }))
      },
    })
  } catch (error) {
    apiError.value = error.message
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
    const data = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(data.errors?.status?.[0] || data.message || 'Failed to update status')
    invalidate('/vehicles')
    vehicle.status = newStatus
    statusDialog.value.show = false
    const conflicts = data.conflicting_bookings?.length || 0
    if (conflicts > 0) {
      notify(`${conflicts} booking${conflicts === 1 ? '' : 's'} ${conflicts === 1 ? 'needs' : 'need'} a new unit`, 'warning', '/conduction-requests')
    } else {
      notify(`${vehicle.unit_identifier} set to ${newStatus}`)
    }
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
    invalidate('/vehicles')
    await fetchVehicles({ fresh: true })
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
    invalidate('/vehicles')
    await fetchVehicles({ fresh: true })
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
.gap-3 { gap: 12px; }
.header-actions { gap: 8px; }
</style>
