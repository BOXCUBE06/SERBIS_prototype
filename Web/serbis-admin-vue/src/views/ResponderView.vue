<template>
  <v-container fluid class="fill-height align-start page-background">
    <v-row>
      <v-col cols="12">

        <PageHeader title="Responders">
          <template v-slot:actions>
            <v-btn color="primary" variant="flat" rounded="lg" height="48" class="px-6 text-none font-weight-bold btn-soft-shadow" @click="openAdd">
              <v-icon start size="20">mdi-plus</v-icon> Add Responder
            </v-btn>
          </template>
        </PageHeader>

        <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6" density="compact" rounded="lg">{{ apiError }}</v-alert>

        <div v-if="!loading && responders.length > 0" class="d-flex flex-wrap align-center gap-3 mb-6">
          <v-text-field
            v-model="search"
            prepend-inner-icon="mdi-magnify"
            placeholder="Search name or position..."
            variant="outlined" density="compact" hide-details rounded="lg"
            class="control-field"
          ></v-text-field>
          <v-chip
            v-if="statusFilter !== 'All'"
            closable
            variant="flat"
            class="font-weight-bold"
            @click:close="statusFilter = 'All'"
          >{{ statusFilter }}</v-chip>
        </div>

        <template v-if="loading">
          <v-card elevation="0" rounded="xl" class="mb-8 pa-8 group-card">
            <v-skeleton-loader type="table-row@6" class="bg-transparent"></v-skeleton-loader>
          </v-card>
        </template>

        <div v-else-if="filteredResponders.length === 0" class="empty-state group-card">
          <v-icon size="48" class="text-medium-emphasis mb-3">mdi-account-hard-hat-outline</v-icon>
          <div class="text-subtitle-1 font-weight-bold text-high-emphasis">
            {{ responders.length > 0 ? 'No responders match your filters' : 'No responders yet' }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ responders.length > 0 ? 'Clear the search to see everyone.' : 'Add the first responder to get started.' }}
          </div>
        </div>

        <v-card v-else elevation="0" rounded="xl" class="group-card overflow-hidden">
          <v-data-table
            :headers="headers"
            :items="filteredResponders"
            :items-per-page="10"
            item-value="responder_id"
            density="comfortable"
            class="responder-table"
          >
            <template v-slot:item.name="{ item }">
              <div class="d-flex align-center gap-3 py-2">
                <v-avatar size="40" color="primary" variant="tonal">
                  <v-img v-if="item.photo_url" :src="item.photo_url" :alt="item.name" cover></v-img>
                  <span v-else class="text-body-2 font-weight-bold">{{ initials(item.name) }}</span>
                </v-avatar>
                <div class="min-w-0">
                  <div class="text-body-1 font-weight-bold text-high-emphasis text-truncate">{{ item.name }}</div>
                  <div class="text-caption text-medium-emphasis text-truncate">{{ item.contact_no }}</div>
                </div>
              </div>
            </template>

            <template v-slot:item.position="{ item }">
              <span class="text-body-2 font-weight-medium text-high-emphasis">{{ item.position }}</span>
            </template>

            <template v-slot:item.status="{ item }">
              <v-menu location="bottom">
                <template v-slot:activator="{ props }">
                  <button
                    type="button"
                    class="status-pill"
                    :class="`pill-${item.status}`"
                    v-bind="props"
                    :aria-label="`${item.name} is ${item.status}. Change status`"
                  >
                    <span class="font-weight-bold text-uppercase">{{ statusLabel(item.status) }}</span>
                    <v-icon size="16" class="ml-auto">mdi-chevron-down</v-icon>
                  </button>
                </template>
                <v-list density="compact" rounded="lg">
                  <v-list-item
                    v-for="s in STATUSES" :key="s"
                    :disabled="s === item.status"
                    @click="promptStatusChange(item, s)"
                  >
                    <v-list-item-title>{{ statusLabel(s) }}</v-list-item-title>
                  </v-list-item>
                </v-list>
              </v-menu>
            </template>

            <template v-slot:item.actions="{ item }">
              <div class="d-flex justify-end gap-1">
                <v-btn icon="mdi-pencil-outline" variant="text" size="small" :aria-label="`Edit ${item.name}`" @click="openEdit(item)"></v-btn>
                <v-btn icon="mdi-delete-outline" variant="text" size="small" color="error" :aria-label="`Delete ${item.name}`" @click="askDelete(item)"></v-btn>
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
          Change <span class="font-weight-bold text-high-emphasis">{{ statusDialog.responder?.name }}</span> to
          <span class="font-weight-bold text-uppercase">{{ statusLabel(statusDialog.newStatus) }}</span>?
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
          <span class="text-h6 font-weight-bold text-high-emphasis">{{ formDialog.editing ? 'Edit responder' : 'Add responder' }}</span>
          <v-btn icon="mdi-close" variant="text" size="small" @click="formDialog.show = false"></v-btn>
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
          <v-btn variant="text" rounded="lg" class="text-none" :disabled="formDialog.loading" @click="formDialog.show = false">Cancel</v-btn>
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
import { API_BASE } from '@/config/api'
import PageHeader from '@/components/PageHeader.vue'

const API = `${API_BASE}/responders`
const STATUSES = ['available', 'deployed', 'off_duty']
const STATUS_LABELS = { available: 'Available', deployed: 'Deployed', off_duty: 'Off Duty' }
const statusLabel = (s) => STATUS_LABELS[s] || s

const responders = ref([])
const loading = ref(false)
const apiError = ref('')
const search = ref('')
const statusFilter = ref('All')

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

const filteredResponders = computed(() => {
  const q = search.value.trim().toLowerCase()
  return responders.value.filter((r) => {
    const matchesStatus = statusFilter.value === 'All' || r.status === statusFilter.value
    const matchesSearch = !q ||
      (r.name || '').toLowerCase().includes(q) ||
      (r.position || '').toLowerCase().includes(q)
    return matchesStatus && matchesSearch
  })
})

const headers = [
  { title: 'Responder', key: 'name', width: '34%' },
  { title: 'Position', key: 'position', width: '22%' },
  { title: 'Status', key: 'status', width: '25%' },
  { title: '', key: 'actions', sortable: false, align: 'end', width: '19%' },
]

const fetchResponders = async () => {
  loading.value = true
  try {
    const res = await fetch(API, { headers: getHeaders() })
    const data = await res.json()
    if (!res.ok) throw new Error(data.message || 'Failed to fetch responders')
    responders.value = Array.isArray(data) ? data : (data.data || [])
  } catch (error) {
    apiError.value = error.message
    responders.value = []
  } finally {
    loading.value = false
  }
}

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
    await fetchResponders()
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
    await fetchResponders()
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
    await fetchResponders()
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
.min-w-0 { min-width: 0; }
.control-field { width: 260px; max-width: 100%; }

.group-card {
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08) !important;
  box-shadow: 0 12px 40px -12px rgba(var(--v-theme-on-surface), 0.05) !important;
}

.responder-table :deep(table) { table-layout: fixed !important; width: 100% !important; min-width: 640px; }
.responder-table :deep(thead th) {
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.status-pill {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 128px;
  padding: 6px 12px;
  border-radius: 10px;
  font-size: 0.82rem;
  letter-spacing: 0.04em;
  border: none;
  cursor: pointer;
  transition: filter var(--motion-fast) var(--ease-out);
}
.status-pill:hover { filter: brightness(0.97); }
.pill-available { background: rgba(var(--v-theme-primary), 0.12); color: rgb(var(--v-theme-primary-strong)); }
.pill-deployed { background: rgba(var(--v-theme-warning), 0.16); color: rgb(var(--v-theme-warning-strong)); }
.pill-off_duty { background: rgba(var(--v-theme-on-surface), 0.1); color: rgba(var(--v-theme-on-surface), 0.7); }

.empty-state {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center; padding: 64px 16px; border-radius: 16px;
}
</style>
