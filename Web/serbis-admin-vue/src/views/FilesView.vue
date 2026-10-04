<template>
  <v-container fluid class="fill-height align-start bg-background">
    <div class="w-100">
      <PageHeader title="Documents" />

      <!-- Dropzone: one row, so the list starts without scrolling. -->
      <div
        v-if="!staged"
        class="dropzone mb-4"
        :class="{ 'dropzone--active': dragActive }"
        role="button"
        tabindex="0"
        aria-label="Upload a file — drop it here or press Enter to browse"
        @click="pickFile"
        @keydown.enter.prevent="pickFile"
        @keydown.space.prevent="pickFile"
        @dragover.prevent="dragActive = true"
        @dragleave.prevent="dragActive = false"
        @drop.prevent="onDrop"
      >
        <v-icon size="20" color="primary">mdi-cloud-upload-outline</v-icon>
        <span class="text-body-2 font-weight-medium text-high-emphasis">Drop a file here, or click to browse</span>
        <span class="text-caption text-medium-emphasis">PDF or images — max 10 MB. This is what residents will download.</span>
        <!-- Kept in step with InfoMaterialController::store's `mimes:` rule.
             Word and ZIP were dropped there because these files are served
             by public URL from the agency's own origin; offering them here
             would only earn the admin a 422. The Word and archive icons
             below stay — rows uploaded before the change still render. -->
        <input
          ref="fileInput"
          type="file"
          class="d-none"
          accept=".pdf,.jpg,.jpeg,.png"
          @change="onPick"
        />
      </div>

      <v-card
        v-else
        variant="tonal"
        color="primary"
        rounded="lg"
        class="pa-4 mb-4 staging-card"
      >
        <div class="d-flex align-center gap-3">
          <v-icon :color="getFileIconColor(staged.ext)" size="40">{{ getFileIcon(staged.ext) }}</v-icon>
          <div class="flex-grow-1 min-w-0">
            <v-text-field
              v-model="stagedTitle"
              label="Title shown to residents *"
              placeholder="Flood evacuation map — Barangay San Isidro"
              variant="outlined"
              density="compact"
              hide-details
              rounded="lg"
              autofocus
              @keydown.enter="publish"
            ></v-text-field>
            <div class="text-caption text-medium-emphasis mt-1 text-truncate">
              {{ staged.name }} · {{ formatBytes(staged.size) }}
            </div>
          </div>
          <v-btn
            color="primary" variant="flat" rounded="lg"
            class="text-none font-weight-bold" height="44"
            :loading="uploading" :disabled="!stagedTitle.trim()"
            @click="publish"
          >
            <v-icon start>mdi-send</v-icon> Publish
          </v-btn>
          <v-btn icon="mdi-close" variant="tonal" rounded="circle" size="small" :disabled="uploading" @click="clearStaged"></v-btn>
        </div>
        <v-progress-linear
          v-if="uploading"
          :model-value="progress"
          color="primary"
          height="6"
          rounded
          class="mt-3"
        ></v-progress-linear>
        <v-alert v-if="apiError" type="error" variant="tonal" density="compact" rounded="lg" class="mt-3">
          {{ apiError }}
        </v-alert>
      </v-card>

      <!-- Table and grid share the shell: tabs, search, date filter, chips and
           footer. A row or a card opens the file in a new tab. -->
      <DataTablePage
        compact
        collapse-mobile
        class="materials-table"
        :tabs="typeTabs"
        :status="typeFilter"
        @update:status="typeFilter = $event"
        :loading="firstLoad"
        :refreshing="refreshing"
        v-model:search="search"
        search-placeholder="Search materials"
        :headers="materialHeaders"
        :items="visibleFiles"
        item-value="files_id"
        :no-data-text="emptyText"
        :page="page"
        @update:page="page = $event"
        :items-per-page="perPage"
        @update:items-per-page="perPage = $event"
        :items-per-page-options="perPageOptions"
        result-noun="materials"
        :active-filters="activeFilters"
        @clear-filter="clearFilter"
        @clear-all="clearFilter('date')"
        @click:row="(_event, { item }) => openFile(item)"
      >
        <template v-slot:filters>
          <v-select
            v-model="dateFilter"
            :items="dateFilters"
            item-title="label"
            item-value="value"
            label="Uploaded"
            variant="outlined" density="compact" hide-details rounded="lg"
          ></v-select>
          <v-btn-toggle v-model="view" mandatory divided density="compact" variant="outlined" rounded="lg" class="view-toggle" aria-label="View">
            <v-btn value="table" icon="mdi-view-list" aria-label="Table view"></v-btn>
            <v-btn value="grid" icon="mdi-view-grid-outline" aria-label="Grid view"></v-btn>
          </v-btn-toggle>
        </template>

        <template v-if="view === 'grid'" v-slot:content>
          <div v-if="firstLoad" class="file-grid" aria-hidden="true">
            <div v-for="n in 6" :key="n" class="file-grid__skeleton"></div>
          </div>
          <div v-else-if="visibleFiles.length === 0" class="file-grid__empty text-body-2 text-medium-emphasis">{{ emptyText }}</div>
          <div v-else class="file-grid">
            <FileCard
              v-for="f in pagedFiles"
              :key="f.files_id"
              :title="f.title"
              :url="f.full_url"
              :image="category(f.file_type) === 'image'"
              :icon="getFileIcon(f.file_type)"
              :meta="`${formatBytes(f.file_size, 1)} · ${relativeDate(f.created_at)}`"
              :verified="!!f.verified"
              :verified-by="verifiedBy(f)"
              :busy="verifying === f.files_id"
              @open="openFile(f)"
              @download="openFile(f)"
              @verify="onToggleVerified(f, !f.verified)"
              @delete="askDelete(f)"
            />
          </div>
        </template>

        <template v-slot:item.title="{ item }">
          <PersonCell
            :name="item.title"
            :secondary="(item.file_type || 'file').toUpperCase()"
            :icon="getFileIcon(item.file_type)"
            size="36"
          />
        </template>

        <template v-slot:item.file_type="{ item }">
          <span class="text-body-2">{{ typeLabel(item.file_type) }}</span>
        </template>

        <template v-slot:item.file_size="{ item }">
          <span class="text-body-2 text-medium-emphasis file-size">{{ formatBytes(item.file_size) }}</span>
        </template>

        <template v-slot:item.created_at="{ item }">
          <span class="text-body-2 text-medium-emphasis">{{ relativeDate(item.created_at) }}</span>
        </template>

        <!-- Switch rather than a button pair: this is one state with two
             directions, and a mistaken click has to be undoable. Who verified it
             sits beside it on one line (MDRRMO feedback, 2026-09-18); the row is
             a fixed height, so it truncates. Clicks stop here, a row opens the file. -->
        <template v-slot:item.verified="{ item }">
          <div class="d-flex align-center gap-2 min-w-0" @click.stop @keydown.stop>
            <v-switch
              :model-value="item.verified"
              :loading="verifying === item.files_id"
              :disabled="verifying === item.files_id"
              :aria-label="`Mark ${item.title} as verified`"
              color="success"
              density="compact"
              hide-details
              inset
              class="flex-grow-0"
              @update:model-value="value => onToggleVerified(item, value)"
            ></v-switch>
            <span v-if="verifiedBy(item)" class="text-caption text-medium-emphasis cell-truncate" :title="verifiedBy(item)">
              {{ verifiedBy(item) }}
            </span>
          </div>
        </template>

        <template v-slot:item.actions="{ item }">
          <RowActions
            :label="item.title"
            :editable="false"
            :extra="{ label: 'Download', icon: 'mdi-download' }"
            @extra="openFile(item)"
            @delete="askDelete(item)"
          />
        </template>
      </DataTablePage>
    </div>

    <!-- Delete confirm -->
    <v-dialog v-model="deleteDialog" max-width="420">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="text-h6 font-weight-bold text-high-emphasis">Delete material?</v-card-title>
        <v-card-text class="text-body-2 text-medium-emphasis">
          <strong class="text-high-emphasis">{{ pendingDelete?.title }}</strong> will be removed and
          residents will no longer be able to download it. This cannot be undone.
        </v-card-text>
        <v-card-actions class="px-4 pb-4">
          <v-spacer></v-spacer>
          <v-btn variant="outlined" color="primary" class="text-none" @click="deleteDialog = false" :disabled="deleting">Cancel</v-btn>
          <v-btn color="error" variant="flat" rounded="lg" class="text-none font-weight-bold" :loading="deleting" @click="confirmDelete">
            Delete
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Verify: who -->
    <v-dialog v-model="verifyDialog" max-width="440">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="text-h6 font-weight-bold text-high-emphasis">Mark as verified</v-card-title>
        <v-card-text class="text-body-2 text-medium-emphasis">
          <div class="mb-4">
            Enter the name and role of the person who checked
            <strong class="text-high-emphasis">{{ pendingVerify?.title }}</strong>.
          </div>
          <v-text-field
            v-model="verifyName"
            label="Name *" placeholder="e.g. Dr. Ana Reyes"
            variant="outlined" density="comfortable" class="mb-2"
            :error-messages="verifyErrors.name"
            @update:model-value="verifyErrors.name = ''"
          ></v-text-field>
          <v-text-field
            v-model="verifyRole"
            label="Role *" placeholder="e.g. MDRRMO Medical Officer"
            variant="outlined" density="comfortable"
            :error-messages="verifyErrors.role"
            @update:model-value="verifyErrors.role = ''"
          ></v-text-field>
        </v-card-text>
        <v-card-actions class="px-4 pb-4">
          <v-spacer></v-spacer>
          <v-btn variant="outlined" color="primary" class="text-none" @click="cancelVerify" :disabled="verifying === pendingVerify?.files_id">Cancel</v-btn>
          <v-btn
            color="success" variant="flat" rounded="lg" class="text-none font-weight-bold"
            :loading="verifying === pendingVerify?.files_id"
            @click="confirmVerify"
          >Mark verified</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Feedback -->
    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="bottom right" rounded="lg">
      {{ snackbar.text }}
      <template v-slot:actions>
        <v-btn icon="mdi-close" variant="tonal" rounded="circle" size="small" @click="snackbar.show = false"></v-btn>
      </template>
    </v-snackbar>
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
import RowActions from '@/components/RowActions.vue'
import FileCard from '@/components/FileCard.vue'

const API = `${API_BASE}/admin/info-materials`

const files = ref([])
const search = ref('')
// Skeleton rows only while nothing is cached; a revisit shows the last list and dims it while it refreshes.
const { get, loading: firstLoad, refreshing } = useCachedFetch()

// Upload staging
const fileInput = ref(null)
const dragActive = ref(false)
const staged = ref(null)        // { name, size, ext, raw }
const stagedTitle = ref('')
const uploading = ref(false)
const progress = ref(0)
const apiError = ref('')

// Delete
const deleteDialog = ref(false)
const pendingDelete = ref(null)
const deleting = ref(false)

// Filter
const typeFilter = ref('all')
const typeFilters = [
  { label: 'All', value: 'all' },
  { label: 'PDF', value: 'pdf' },
  { label: 'Images', value: 'image' },
  { label: 'Documents', value: 'doc' },
  { label: 'Archives', value: 'archive' },
]

const dateFilter = ref('all')
const dateFilters = [
  { label: 'Any time', value: 'all' },
  { label: 'Today', value: 'today' },
  { label: 'This week', value: 'week' },
  { label: 'This month', value: 'month' },
]

// Earliest created_at that passes the current date filter (null = no limit).
const dateThreshold = () => {
  const d = new Date()
  d.setHours(0, 0, 0, 0)
  if (dateFilter.value === 'today') return d
  if (dateFilter.value === 'week') {
    // Start of the current week (Monday).
    const day = (d.getDay() + 6) % 7
    d.setDate(d.getDate() - day)
    return d
  }
  if (dateFilter.value === 'month') {
    d.setDate(1)
    return d
  }
  return null
}

const snackbar = ref({ show: false, text: '', color: 'success' })
const notify = (text, color = 'success') => { snackbar.value = { show: true, text, color } }

const getHeaders = () => ({ Authorization: `Bearer ${getToken()}`, Accept: 'application/json' })

const category = (ext) => {
  const e = (ext || '').toLowerCase()
  if (e === 'pdf') return 'pdf'
  if (['jpg', 'jpeg', 'png'].includes(e)) return 'image'
  if (['doc', 'docx'].includes(e)) return 'doc'
  if (['zip'].includes(e)) return 'archive'
  return 'other'
}

// Search and date narrow the rows first; the type tabs then split that set, so
// each tab's count is what it would show.
const baseFiles = computed(() => {
  const q = search.value.trim().toLowerCase()
  const since = dateThreshold()
  return files.value.filter((f) => {
    const matchesSearch = !q || (f.title || '').toLowerCase().includes(q)
    const matchesDate = !since || new Date(f.created_at) >= since
    return matchesSearch && matchesDate
  })
})
const visibleFiles = computed(() =>
  typeFilter.value === 'all' ? baseFiles.value : baseFiles.value.filter((f) => category(f.file_type) === typeFilter.value),
)
const typeTabs = computed(() => typeFilters.map((t) => ({
  value: t.value,
  label: t.label,
  count: t.value === 'all' ? baseFiles.value.length : baseFiles.value.filter((f) => category(f.file_type) === t.value).length,
})))

const emptyText = computed(() => (files.value.length > 0 ? 'No materials match your filter' : 'No materials published yet'))

// Date is the only filter that is not the tabs or search, so it is the only chip.
const activeFilters = computed(() => (
  dateFilter.value === 'all' ? [] : [{ key: 'date', label: `Uploaded: ${dateFilters.find((d) => d.value === dateFilter.value)?.label}` }]
))
const clearFilter = () => { dateFilter.value = 'all' }

// Table or grid, remembered per browser. Storage can be blocked, so it is
// always wrapped and the page works without it.
const VIEW_KEY = 'serbis.documents.view'
const readView = () => {
  try { return localStorage.getItem(VIEW_KEY) === 'grid' ? 'grid' : 'table' } catch { return 'table' }
}
const view = ref(readView())
watch(view, (value) => {
  try { localStorage.setItem(VIEW_KEY, value) } catch { /* not remembered */ }
  page.value = 1
})

// One page number, and a page size per view (a grid page is a multiple of its row).
const page = ref(1)
const tablePerPage = ref(10)
const gridPerPage = ref(12)
const perPage = computed({
  get: () => (view.value === 'grid' ? gridPerPage.value : tablePerPage.value),
  set: (n) => { if (view.value === 'grid') gridPerPage.value = n; else tablePerPage.value = n },
})
const perPageOptions = computed(() => (view.value === 'grid' ? [12, 24, 48] : [10, 25, 50]))
const pagedFiles = computed(() => visibleFiles.value.slice((page.value - 1) * perPage.value, page.value * perPage.value))
watch([search, typeFilter, dateFilter], () => { page.value = 1 })

// A row or a card opens the file in a new tab, the same as Download.
const openFile = (item) => window.open(item.full_url, '_blank', 'noopener')
const verifiedBy = (item) => (item.verified && item.verified_by_name ? [item.verified_by_name, item.verified_by_role].filter(Boolean).join(', ') : '')

// Fixed-layout table; identity gets the room. Type and Size go on a phone
// (see DataTablePage's collapseMobile).
const HIDE_SM = { class: 'dtp-hide-sm' }
const materialHeaders = [
  { title: 'File', key: 'title', width: '38%' },
  { title: 'Type', key: 'file_type', width: '130px', value: (item) => typeLabel(item.file_type), headerProps: HIDE_SM, cellProps: HIDE_SM },
  { title: 'Size', key: 'file_size', width: '90px', headerProps: HIDE_SM, cellProps: HIDE_SM },
  { title: 'Uploaded', key: 'created_at', width: '130px' },
  { title: 'Verified', key: 'verified', width: '230px', value: (item) => (item.verified ? 1 : 0) },
  { title: 'Actions', key: 'actions', sortable: false, align: 'end', width: '88px' },
]

// The badge on the file cell prints the raw extension; this column prints what
// that extension is, off the same buckets the filter chips use, so the column
// and the chip that hides a row can never disagree about what a file is.
const typeLabels = {
  pdf: 'PDF',
  image: 'Image',
  doc: 'Word document',
  archive: 'Archive',
  other: 'File',
}
const typeLabel = (ext) => typeLabels[category(ext)]

const formatBytes = (bytes, decimals = 2) => {
  if (!+bytes) return '0 Bytes'
  const k = 1024
  const dm = Math.max(decimals, 0)
  const sizes = ['Bytes', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return `${Number.parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`
}

const relativeDate = (iso) => {
  const then = new Date(iso)
  const days = Math.floor((Date.now() - then.getTime()) / 86_400_000)
  if (days <= 0) return 'Today'
  if (days === 1) return 'Yesterday'
  if (days < 7) return `${days} days ago`
  return then.toLocaleDateString()
}

const getFileIcon = (ext) => ({
  pdf: 'mdi-file-pdf-box',
  doc: 'mdi-file-word-box',
  docx: 'mdi-file-word-box',
  jpg: 'mdi-file-image',
  jpeg: 'mdi-file-image',
  png: 'mdi-file-image',
  zip: 'mdi-folder-zip',
}[ext?.toLowerCase()] || 'mdi-file-document-outline')

const getFileIconColor = (ext) => ({
  pdf: 'error',
  doc: 'info',
  docx: 'info',
  jpg: 'success',
  jpeg: 'success',
  png: 'success',
  zip: 'warning',
}[ext?.toLowerCase()] || 'secondary')

const fetchFiles = async (fresh = false) => {
  try {
    await get('/admin/info-materials', {
      fresh,
      onData: (data) => { files.value = Array.isArray(data) ? data : (data.data || []) },
    })
  } catch (error) {
    console.error('Failed to fetch materials:', error)
    files.value = []
    notify(error.message || 'Could not load materials', 'error')
  }
}

// After a write: drop the cached list, then fetch past it.
const reload = () => { invalidate('/admin/info-materials'); return fetchFiles(true) }

// --- Upload flow ---
const pickFile = () => fileInput.value?.click()

const stageFile = (file) => {
  if (!file) return
  const ext = file.name.split('.').pop()
  staged.value = { name: file.name, size: file.size, ext, raw: file }
  stagedTitle.value = file.name.replace(/\.[^.]+$/, '')
  apiError.value = ''
}

const onPick = (e) => stageFile(e.target.files?.[0])

const onDrop = (e) => {
  dragActive.value = false
  stageFile(e.dataTransfer.files?.[0])
}

const clearStaged = () => {
  staged.value = null
  stagedTitle.value = ''
  apiError.value = ''
  progress.value = 0
  if (fileInput.value) fileInput.value.value = ''
}

const publish = () => {
  if (!staged.value || !stagedTitle.value.trim()) return
  uploading.value = true
  apiError.value = ''
  progress.value = 0

  const payload = new FormData()
  payload.append('title', stagedTitle.value.trim())
  payload.append('file', staged.value.raw)

  // XHR (not fetch) so the progress bar reflects the real upload, not a fake timer.
  const xhr = new XMLHttpRequest()
  xhr.open('POST', API)
  Object.entries(getHeaders()).forEach(([k, v]) => xhr.setRequestHeader(k, v))

  xhr.upload.addEventListener('progress', (evt) => {
    if (evt.lengthComputable) progress.value = Math.round((evt.loaded / evt.total) * 100)
  })
  xhr.addEventListener('load', async () => {
    uploading.value = false
    if (xhr.status >= 200 && xhr.status < 300) {
      clearStaged()
      await reload()
      notify('Published — residents can now download it')
    } else {
      let msg = 'Upload failed'
      try { msg = JSON.parse(xhr.responseText).message || msg } catch { /* keep default */ }
      apiError.value = msg
    }
  })
  xhr.addEventListener('error', () => { uploading.value = false; apiError.value = 'Network error during upload' })
  xhr.send(payload)
}

// --- Verified flag ---
// Holds the files_id being written so only that row's switch shows the wait,
// rather than the whole table going busy for a one-row change.
const verifying = ref(null)

// Verifying names who; unverifying does not need to ask anything, same as
// before this existed.
const verifyDialog = ref(false)
const pendingVerify = ref(null)
const verifyName = ref('')
const verifyRole = ref('')
const verifyErrors = ref({ name: '', role: '' })

const onToggleVerified = (item, value) => {
  if (!value) {
    setVerified(item, false)
    return
  }

  pendingVerify.value = item
  verifyName.value = ''
  verifyRole.value = ''
  verifyErrors.value = { name: '', role: '' }
  verifyDialog.value = true
}

const cancelVerify = () => {
  verifyDialog.value = false
  pendingVerify.value = null
}

const confirmVerify = async () => {
  // Both are required: the mark names who checked the file, or it means nothing.
  verifyErrors.value = {
    name: verifyName.value.trim() ? '' : 'Name is required.',
    role: verifyRole.value.trim() ? '' : 'Role is required.',
  }
  if (verifyErrors.value.name || verifyErrors.value.role) return

  const item = pendingVerify.value
  verifyDialog.value = false
  await setVerified(item, true, {
    verified_by_name: verifyName.value.trim(),
    verified_by_role: verifyRole.value.trim(),
  })
  pendingVerify.value = null
}

const setVerified = async (item, value, extra = {}) => {
  verifying.value = item.files_id
  const previous = { verified: item.verified, verified_by_name: item.verified_by_name, verified_by_role: item.verified_by_role }

  // Flipped up front so the switch does not sit on its old position while the
  // request is in flight; put back if the write fails.
  item.verified = value
  if (value) {
    item.verified_by_name = extra.verified_by_name
    item.verified_by_role = extra.verified_by_role
  } else {
    item.verified_by_name = null
    item.verified_by_role = null
  }

  try {
    const res = await fetch(`${API}/${item.files_id}/verify`, {
      method: 'PATCH',
      // Content-Type spelled out here: getHeaders() leaves it off on purpose
      // for the FormData upload, and without it this JSON body never parses.
      headers: { ...getHeaders(), 'Content-Type': 'application/json' },
      body: JSON.stringify({ verified: value, ...extra }),
    })
    if (!res.ok) throw new Error('Could not update the verified mark')
    invalidate('/admin/info-materials')
    notify(value ? 'Material marked verified' : 'Verified mark removed')
  } catch (error) {
    item.verified = previous.verified
    item.verified_by_name = previous.verified_by_name
    item.verified_by_role = previous.verified_by_role
    notify(error.message || 'Could not update the verified mark', 'error')
  } finally {
    verifying.value = null
  }
}

// --- Delete flow ---
const askDelete = (item) => { pendingDelete.value = item; deleteDialog.value = true }

const confirmDelete = async () => {
  if (!pendingDelete.value) return
  deleting.value = true
  try {
    const res = await fetch(`${API}/${pendingDelete.value.files_id}`, {
      method: 'DELETE',
      headers: getHeaders(),
    })
    if (!res.ok) throw new Error('Delete failed')
    await reload()
    notify('Material deleted')
    deleteDialog.value = false
  } catch (error) {
    notify(error.message || 'Could not delete material', 'error')
  } finally {
    deleting.value = false
  }
}

onMounted(fetchFiles)
</script>

<style scoped>
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.min-w-0 { min-width: 0; }

/* Dropzone: a single row (icon, text, hint), wrapping on a narrow screen. */
.dropzone {
  display: flex;
  align-items: center;
  gap: 8px 12px;
  flex-wrap: wrap;
  min-height: 48px;
  padding: 8px 16px;
  border: 1px dashed rgba(var(--v-theme-on-surface), 0.3);
  border-radius: 12px;
  cursor: pointer;
  transition: background-color var(--motion-base) var(--ease-out), border-color var(--motion-base) var(--ease-out);
  background-color: rgba(var(--v-theme-on-surface), 0.02);
}
.dropzone:hover,
.dropzone:focus-visible {
  background-color: rgba(var(--v-theme-primary), 0.06);
  border-color: rgb(var(--v-theme-primary));
  outline: none;
}
.dropzone--active {
  background-color: rgba(var(--v-theme-primary), 0.12);
  border-color: rgb(var(--v-theme-primary));
}

.staging-card { border: 1px solid rgba(var(--v-theme-primary), 0.4); }

/* Table: fixed layout keeps the columns stable whatever the file titles are;
   below the floor it scrolls, on a phone (collapseMobile) the floor goes. */
.materials-table :deep(.dtp-table table) { min-width: 760px; }
@media (max-width: 599px) {
  .materials-table :deep(.dtp-table table) { min-width: 0; }
}
.file-size { font-variant-numeric: tabular-nums; }
.cell-truncate {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Grid: as many 200px-plus columns as fit. */
.file-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(200px, 100%), 1fr));
  gap: 16px;
  padding: 4px 0;
}
.file-grid__skeleton {
  height: 200px;
  border-radius: 12px;
  background: rgba(var(--v-theme-on-surface), 0.06);
}
.file-grid__empty { padding: 48px 16px; text-align: center; }

.view-toggle { flex: none; height: 40px; }

@media (prefers-reduced-motion: reduce) {
  .dropzone { transition: none; }
}
</style>
