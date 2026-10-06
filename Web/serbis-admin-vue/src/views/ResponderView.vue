<template>
  <v-container fluid class="fill-height align-start page-background">
    <PageHeader title="Responders" class="mb-5">
      <template v-slot:subtitle>{{ subtitle }}</template>
      <template v-slot:actions>
        <v-btn color="primary" variant="flat" class="text-none font-weight-bold" height="40" prepend-icon="mdi-plus" @click="openAdd">
          Add responder
        </v-btn>
      </template>
    </PageHeader>

    <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6 w-100" density="compact" rounded="lg">{{ apiError }}</v-alert>

    <div class="w-100">
      <DataTablePage
        compact
        filter-bar
        range-summary
        collapse-mobile
        @click:row="(_event, { item }) => openEdit(item)"
        :tabs="statusTabs"
        :status="statusFilter"
        @update:status="statusFilter = $event"
        :loading="firstLoad"
        :refreshing="refreshing"
        v-model:search="search"
        search-placeholder="Search name or position"
        :headers="headers"
        :items="filteredResponders"
        item-value="responder_id"
        :no-data-text="responders.length > 0 ? 'No responders match your filters' : 'No responders yet'"
        :page="page"
        @update:page="page = $event"
        :items-per-page="itemsPerPage"
        @update:items-per-page="itemsPerPage = $event"
        result-noun="responders"
        :active-filters="activeFilters"
        @clear-filter="clearFilter"
        @clear-all="clearAllFilters"
      >
        <template v-slot:filters>
          <FilterSelect v-model="positionFilter" :items="positionOptions" label="Position" />
        </template>

        <template v-slot:item.name="{ item }">
          <PersonCell
            :name="item.name"
            :secondary="item.contact_no"
            :initials="initials(item.name)"
            :photo="item.photo_url"
            size="36"
          />
        </template>

        <template v-slot:item.status="{ item }">
          <StatusChip
            :status="PILL_KEY[item.status]"
            :label="statusLabel(item.status)"
            :items="statusChoices"
            :current="item.status"
            :aria-label="`${item.name} is ${statusLabel(item.status)}. Change status`"
            @select="promptStatusChange(item, $event)"
          />
        </template>

        <template v-slot:item.actions="{ item }">
          <RowActions :label="item.name" @edit="openEdit(item)" @delete="askDelete(item)" />
        </template>
      </DataTablePage>
    </div>


    <!-- Status confirm -->
    <v-dialog v-model="statusDialog.show" max-width="420">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">Update status</v-card-title>
        <v-card-text class="px-6 py-4 text-body-1 text-medium-emphasis">
          Change <span class="font-weight-bold text-high-emphasis">{{ statusDialog.responder?.name }}</span> to
          <span class="font-weight-bold text-uppercase">{{ statusLabel(statusDialog.newStatus) }}</span>?
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="outlined" color="primary" rounded="lg" class="text-none" :disabled="statusDialog.loading" @click="statusDialog.show = false">Cancel</v-btn>
          <v-btn color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" :loading="statusDialog.loading" @click="executeStatusChange">Confirm</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Add / Edit -->
    <!-- A photo needs an existing responder, so the avatar row is Edit only. -->
    <EditDialog
      ref="formRef"
      v-model="formDialog.show"
      :title="formDialog.editing ? 'Edit responder' : 'Add responder'"
      :confirm-label="formDialog.editing ? 'Save' : 'Add responder'"
      :fields="formFields"
      :form="form"
      :field-errors="fieldErrors"
      :error="formDialog.error"
      :loading="formDialog.loading"
      :avatar="formDialog.editing ? initials(form.name) : undefined"
      :photo="form.photo_url"
      :photo-loading="photoUploading"
      @photo="uploadPhoto"
      @save="saveResponder"
    />

    <!-- Delete confirm -->
    <v-dialog v-model="deleteDialog.show" max-width="420">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">Delete responder?</v-card-title>
        <v-card-text class="px-6 py-4 text-body-2 text-medium-emphasis">
          <strong class="text-high-emphasis">{{ deleteDialog.responder?.name }}</strong> will be permanently removed. This cannot be undone.
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
import FilterSelect from '@/components/FilterSelect.vue'
import EditDialog from '@/components/EditDialog.vue'

const API = `${API_BASE}/responders`
const STATUSES = ['available', 'deployed', 'off_duty']
// The four roles on a response team; matches Responder::POSITIONS, which refuses anything else.
const POSITIONS = ['Team Leader', 'Assistant Leader', 'Logistics', 'Driver']
const STATUS_LABELS = { available: 'Available', deployed: 'Deployed', off_duty: 'Off Duty' }
const statusLabel = (s) => STATUS_LABELS[s] || s
// The colour key each status has in statusPill.ts.
const PILL_KEY = { available: 'Available', deployed: 'Deployed', off_duty: 'Off duty' }
const statusChoices = STATUSES.map((s) => ({ value: s, label: statusLabel(s), status: PILL_KEY[s] }))

const responders = ref([])
// Skeleton rows only while nothing is cached; a revisit shows the last list and dims it while it refreshes.
const { get, loading: firstLoad, refreshing } = useCachedFetch()
const apiError = ref('')
const search = ref('')
const statusFilter = ref('All')
const positionFilter = ref('All')
const page = ref(1)
const itemsPerPage = ref(10)

const statusDialog = ref({ show: false, responder: null, newStatus: '', loading: false })
const formDialog = ref({ show: false, editing: false, loading: false, error: '' })
const deleteDialog = ref({ show: false, responder: null, loading: false })
const form = ref({ name: '', position: '', contact_no: '', status: 'available', photo_url: null })
const snackbar = ref({ show: false, text: '', color: 'success' })
const photoUploading = ref(false)

// Template ref for the EditDialog (validate / resetValidation).
const formRef = ref(null)

const formFields = [
  { key: 'name', label: 'Name', required: true, placeholder: 'e.g. Juan Dela Cruz' },
  { key: 'position', label: 'Position', required: true, items: POSITIONS },
  { key: 'contact_no', label: 'Contact number', required: true, placeholder: 'e.g. 09171234567' },
  { key: 'status', label: 'Status', required: true, items: STATUSES, itemTitle: statusLabel },
]

const initials = (name) => (name || '').trim().split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || '?'

const fieldErrors = ref({})
const clearFieldErrors = () => { fieldErrors.value = {} }

// Laravel answers `{errors: {field: [msg]}}` — same split as VehiclesView.
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
const getAuthOnlyHeaders = () => ({ Authorization: `Bearer ${getToken()}`, Accept: 'application/json' })

// Search and position narrow the rows first; the tabs then split that set by
// status, so each tab's count is what it would show.
const baseResponders = computed(() => {
  const q = search.value.trim().toLowerCase()
  return responders.value.filter((r) => {
    const matchesPosition = positionFilter.value === 'All' || r.position === positionFilter.value
    const matchesSearch = !q ||
      (r.name || '').toLowerCase().includes(q) ||
      (r.position || '').toLowerCase().includes(q)
    return matchesPosition && matchesSearch
  })
})
const filteredResponders = computed(() =>
  statusFilter.value === 'All' ? baseResponders.value : baseResponders.value.filter((r) => r.status === statusFilter.value),
)
const statusTabs = computed(() => [
  { value: 'All', label: 'All', count: baseResponders.value.length },
  ...STATUSES.map((s) => ({ value: s, label: statusLabel(s), count: baseResponders.value.filter((r) => r.status === s).length })),
])

const positionOptions = computed(() => ['All', ...[...new Set(responders.value.map((r) => r.position).filter(Boolean))].sort()])

const subtitle = computed(() => `${pluralize(responders.value.length, 'responder')} ·${responders.value.filter((r) => r.status === 'deployed').length} deployed`)

// Status is the tabs, so it is not a chip here.
const activeFilters = computed(() => (positionFilter.value === 'All' ? [] : [{ key: 'position', label: `Position: ${positionFilter.value}` }]))
const clearFilter = () => { positionFilter.value = 'All' }
const clearAllFilters = clearFilter
watch([search, statusFilter, positionFilter], () => { page.value = 1 })

// Fixed-layout table, same first three columns as Vehicles and Resource
// Management so Status lines up across them. Position goes on a phone.
const HIDE_SM = { class: 'dtp-hide-sm' }
const headers = [
  { title: 'Responder', key: 'name', width: '35%' },
  { title: 'Position', key: 'position', width: '200px', headerProps: HIDE_SM, cellProps: HIDE_SM },
  { title: 'Status', key: 'status', width: '160px' },
  { title: 'Actions', key: 'actions', sortable: false, align: 'end', width: '120px' },
]

const fetchResponders = async (fresh = false) => {
  try {
    await get('/responders', {
      fresh,
      onData: (data) => { responders.value = Array.isArray(data) ? data : (data.data || []) },
    })
  } catch (error) {
    apiError.value = error.message
  }
}

// After a write: drop the cached list, then fetch past it.
const reload = () => { invalidate('/responders'); return fetchResponders(true) }

const promptStatusChange = (responder, newStatus) => {
  if (newStatus === responder.status) return
  statusDialog.value = { show: true, responder, newStatus, loading: false }
}

const executeStatusChange = async () => {
  const { responder, newStatus } = statusDialog.value
  statusDialog.value.loading = true
  apiError.value = ''
  try {
    const res = await fetch(`${API}/${responder.responder_id}`, { method: 'PUT', headers: getHeaders(), body: JSON.stringify({ status: newStatus }) })
    if (!res.ok) throw new Error((await res.json().catch(() => ({}))).message || 'Failed to update status')
    invalidate('/responders')
    responder.status = newStatus
    statusDialog.value.show = false
    notify(`${responder.name} set to ${statusLabel(newStatus)}`)
  } catch (error) {
    notify(error.message, 'error')
  } finally {
    statusDialog.value.loading = false
  }
}

const openAdd = () => {
  form.value = { name: '', position: '', contact_no: '', status: 'available', photo_url: null }
  formDialog.value = { show: true, editing: false, loading: false, error: '' }
  clearFieldErrors()
  formRef.value?.resetValidation()
}
const openEdit = (responder) => {
  form.value = {
    responder_id: responder.responder_id,
    name: responder.name,
    position: responder.position,
    contact_no: responder.contact_no,
    status: responder.status,
    photo_url: responder.photo_url,
  }
  formDialog.value = { show: true, editing: true, loading: false, error: '' }
  clearFieldErrors()
  formRef.value?.resetValidation()
}

const saveResponder = async () => {
  formDialog.value.error = ''
  clearFieldErrors()

  const { valid } = await formRef.value.validate()
  if (!valid) {
    formDialog.value.error = 'Please correct the highlighted fields.'
    return
  }

  formDialog.value.loading = true
  const editing = formDialog.value.editing
  const url = editing ? `${API}/${form.value.responder_id}` : API
  const payload = {
    name: form.value.name.trim(),
    position: form.value.position.trim(),
    contact_no: form.value.contact_no.trim(),
    status: form.value.status,
  }
  try {
    const res = await fetch(url, { method: editing ? 'PUT' : 'POST', headers: getHeaders(), body: JSON.stringify(payload) })
    if (!res.ok) throw new Error(await applyServerErrors(res))
    await reload()
    formDialog.value.show = false
    notify(editing ? 'Responder updated' : 'Responder added')
  } catch (error) {
    formDialog.value.error = error.message
  } finally {
    formDialog.value.loading = false
  }
}

// Uploads immediately on file pick — editing only, since a photo needs an
// existing responder id (ResponderController::uploadPhoto).
const uploadPhoto = async (file) => {
  if (!file || !form.value.responder_id) return
  photoUploading.value = true
  try {
    const body = new FormData()
    body.append('photo', file)
    const res = await fetch(`${API}/${form.value.responder_id}/photo`, { method: 'POST', headers: getAuthOnlyHeaders(), body })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(data.message || 'Photo upload failed')
    form.value.photo_url = data.photo_url
    await reload()
    notify('Photo updated')
  } catch (error) {
    notify(error.message, 'error')
  } finally {
    photoUploading.value = false
  }
}

const askDelete = (responder) => { deleteDialog.value = { show: true, responder, loading: false } }
const confirmDelete = async () => {
  const responder = deleteDialog.value.responder
  deleteDialog.value.loading = true
  try {
    const res = await fetch(`${API}/${responder.responder_id}`, { method: 'DELETE', headers: getHeaders() })
    if (!res.ok) throw new Error((await res.json().catch(() => ({}))).message || 'Delete failed')
    await reload()
    deleteDialog.value.show = false
    notify('Responder deleted')
  } catch (error) {
    notify(error.message, 'error')
  } finally {
    deleteDialog.value.loading = false
  }
}

onMounted(fetchResponders)
</script>

<style scoped>
.page-background { background-color: rgb(var(--v-theme-background)) !important; }
.gap-3 { gap: 12px; }
</style>
