<template>
  <v-container fluid class="fill-height align-start page-background">
    <PageHeader title="Responders">
      <template v-slot:actions>
        <v-btn color="primary" variant="flat" rounded="lg" height="36" class="px-5 text-none font-weight-bold" @click="openAdd">
          <v-icon start size="18">mdi-plus</v-icon> Add Responder
        </v-btn>
      </template>
    </PageHeader>

    <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6 w-100" density="compact" rounded="lg">{{ apiError }}</v-alert>

    <div class="w-100">
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
          <v-select
            v-model="positionFilter"
            :items="positionOptions"
            label="Position"
            variant="outlined" density="compact" hide-details rounded="lg"
          ></v-select>
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
    <v-dialog v-model="formDialog.show" max-width="480" persistent>
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="d-flex justify-space-between align-center pa-6 pb-2">
          <span class="text-h6 font-weight-bold text-high-emphasis">{{ formDialog.editing ? 'Edit responder' : 'Add responder' }}</span>
          <v-btn icon="mdi-close" variant="tonal" rounded="circle" size="small" @click="formDialog.show = false"></v-btn>
        </v-card-title>
        <v-card-text class="px-6 py-2">
          <v-alert v-if="formDialog.error" type="error" variant="tonal" density="compact" rounded="lg" class="mb-4" role="alert">{{ formDialog.error }}</v-alert>

          <div v-if="formDialog.editing" class="d-flex align-center gap-3 mb-4">
            <v-avatar size="56" color="primary" variant="tonal">
              <v-img v-if="form.photo_url" :src="form.photo_url" cover></v-img>
              <span v-else class="text-body-1 font-weight-bold">{{ initials(form.name) }}</span>
            </v-avatar>
            <v-file-input
              v-model="photoFile"
              accept="image/png,image/jpeg"
              label="Change photo"
              density="compact"
              variant="outlined"
              rounded="lg"
              hide-details
              prepend-icon=""
              prepend-inner-icon="mdi-camera-outline"
              :loading="photoUploading"
              @update:model-value="uploadPhoto"
            ></v-file-input>
          </div>

          <v-form ref="formRef">
            <v-text-field v-model="form.name" label="Name *" placeholder="e.g. Juan Dela Cruz" :rules="[requiredRule('Name')]" :error-messages="fieldErrors.name" variant="outlined" density="comfortable" rounded="lg" class="mb-3"></v-text-field>
            <v-text-field v-model="form.position" label="Position *" placeholder="e.g. EMT" :rules="[requiredRule('Position')]" :error-messages="fieldErrors.position" variant="outlined" density="comfortable" rounded="lg" class="mb-3"></v-text-field>
            <v-text-field v-model="form.contact_no" label="Contact number *" placeholder="e.g. 09171234567" :rules="[requiredRule('Contact number')]" :error-messages="fieldErrors.contact_no" variant="outlined" density="comfortable" rounded="lg" class="mb-3"></v-text-field>
            <v-select v-model="form.status" :items="STATUSES" :item-title="statusLabel" label="Status *" :rules="[requiredRule('Status')]" :error-messages="fieldErrors.status" variant="outlined" density="comfortable" rounded="lg"></v-select>
          </v-form>
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="outlined" color="primary" rounded="lg" class="text-none" :disabled="formDialog.loading" @click="formDialog.show = false">Cancel</v-btn>
          <v-btn color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" :loading="formDialog.loading" @click="saveResponder">
            {{ formDialog.editing ? 'Save' : 'Add responder' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

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
import PageHeader from '@/components/PageHeader.vue'
import DataTablePage from '@/components/DataTablePage.vue'
import PersonCell from '@/components/PersonCell.vue'
import StatusChip from '@/components/StatusChip.vue'
import RowActions from '@/components/RowActions.vue'

const API = `${API_BASE}/responders`
const STATUSES = ['available', 'deployed', 'off_duty']
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
const photoFile = ref(null)
const photoUploading = ref(false)

const formRef = ref(null)

const requiredRule = (label) => (v) =>
  (v !== null && v !== undefined && String(v).trim() !== '') || `${label} is required.`

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
  { title: 'Actions', key: 'actions', sortable: false, align: 'end', width: '96px' },
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
    photoFile.value = null
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
