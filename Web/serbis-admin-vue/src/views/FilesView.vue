<template>
  <v-container fluid class="fill-height align-start bg-background">
    <v-row>
      <v-col cols="12">
        <PageHeader title="Documents" />

        <v-card elevation="2" rounded="xl" class="pa-6 border-0">

          <!-- Filters -->
          <v-row class="mb-6" align="center" justify="end">
            <v-col cols="12" class="d-flex justify-end align-center gap-4 flex-wrap">
              <v-slide-group v-model="typeFilter" class="type-filter" show-arrows mandatory>
                <v-slide-group-item
                  v-for="f in typeFilters"
                  :key="f.value"
                  :value="f.value"
                  v-slot="{ isSelected, toggle }"
                >
                  <v-chip
                    :color="isSelected ? 'primary' : undefined"
                    :variant="isSelected ? 'flat' : 'tonal'"
                    class="mr-2 font-weight-medium"
                    @click="toggle"
                  >{{ f.label }}</v-chip>
                </v-slide-group-item>
              </v-slide-group>

              <v-select
                v-model="dateFilter"
                :items="dateFilters"
                item-title="label"
                item-value="value"
                prepend-inner-icon="mdi-calendar-range"
                variant="outlined"
                density="compact"
                hide-details
                rounded="lg"
                class="date-field"
              ></v-select>

              <v-text-field
                v-model="search"
                prepend-inner-icon="mdi-magnify"
                placeholder="Search materials..."
                variant="outlined"
                density="compact"
                hide-details
                rounded="lg"
                class="search-field"
              ></v-text-field>
            </v-col>
          </v-row>

          <!-- Dropzone / staging -->
          <div
            v-if="!staged"
            class="dropzone subtle-border mb-6"
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
            <v-icon size="40" color="primary" class="mb-2">mdi-cloud-upload-outline</v-icon>
            <div class="text-subtitle-1 font-weight-bold text-high-emphasis">
              Drop a file here, or click to browse
            </div>
            <div class="text-caption text-medium-emphasis">
              PDF or images — max 10 MB. This is what residents will download.
            </div>
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
            class="pa-4 mb-6 staging-card"
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

          <!-- Empty state -->
          <div v-if="!firstLoad && visibleFiles.length === 0" class="empty-state subtle-surface">
            <v-icon size="48" class="text-medium-emphasis mb-3">mdi-file-hidden</v-icon>
            <div class="text-subtitle-1 font-weight-bold text-high-emphasis">
              {{ files.length > 0 ? 'No materials match your filter' : 'No materials published yet' }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ files.length > 0 ? 'Try a different search or type.' : 'Upload the first document residents will see.' }}
            </div>
          </div>

          <!-- Materials list. This was a card grid, and it was the odd one out:
               Resource Management, Vehicles and Activity Logs are all tables,
               and the questions asked here are the ones a table answers - which
               file is newest, which one is the 8 MB PDF, is the advisory still
               up. Same v-data-table, same row height, same 10 per page as
               VehiclesView, so all four read the same way. -->
          <v-card v-else elevation="0" rounded="xl" class="subtle-border overflow-hidden">
            <v-data-table
              :key="firstLoad ? 'loading' : 'ready'"
              :loading="refreshing"
              :class="{ 'is-refreshing': refreshing }"
              :headers="materialHeaders"
              :items="visibleFiles"
              :items-per-page="10"
              item-value="files_id"
              density="comfortable"
              class="materials-table table-fade"
            >
              <template v-if="firstLoad" #body>
                <SkeletonRows :rows="10" :columns="materialHeaders.length" />
              </template>
              <template v-slot:item.rowNumber="{ item }">
                <span class="row-number text-medium-emphasis">{{ rowNumber(item) }}</span>
              </template>

              <template v-slot:item.title="{ item }">
                <div class="d-flex align-center gap-3 py-2">
                  <div
                    class="icon-wrapper"
                    :style="{ background: `rgba(var(--v-theme-${getFileIconColor(item.file_type)}), 0.14)` }"
                  >
                    <v-icon :color="getFileIconColor(item.file_type)" size="22">{{ getFileIcon(item.file_type) }}</v-icon>
                  </div>
                  <div class="min-w-0">
                    <div class="text-body-1 font-weight-bold text-high-emphasis text-truncate">{{ item.title }}</div>
                    <span
                      class="type-badge"
                      :style="{
                        background: `rgba(var(--v-theme-${getFileIconColor(item.file_type)}), 0.14)`,
                        color: `rgb(var(--v-theme-${getFileIconColor(item.file_type)}))`,
                      }"
                    >{{ (item.file_type || 'file').toUpperCase() }}</span>
                  </div>
                </div>
              </template>

              <template v-slot:item.file_type="{ item }">
                <span class="text-body-2 font-weight-medium text-high-emphasis">{{ typeLabel(item.file_type) }}</span>
              </template>

              <template v-slot:item.file_size="{ item }">
                <span class="text-body-2 text-medium-emphasis file-size">{{ formatBytes(item.file_size) }}</span>
              </template>

              <template v-slot:item.created_at="{ item }">
                <span class="text-body-2 text-medium-emphasis">{{ relativeDate(item.created_at) }}</span>
              </template>

              <!-- Switch rather than a button pair: this is one state with two
                   directions, and a mistaken click has to be undoable. -->
              <template v-slot:item.verified="{ item }">
                <v-switch
                  :model-value="item.verified"
                  :loading="verifying === item.files_id"
                  :disabled="verifying === item.files_id"
                  :aria-label="`Mark ${item.title} as verified`"
                  color="success"
                  density="compact"
                  hide-details
                  inset
                  @update:model-value="value => onToggleVerified(item, value)"
                ></v-switch>
                <!-- Who, not just that — MDRRMO feedback, 2026-09-18. -->
                <div v-if="item.verified && item.verified_by_name" class="text-caption text-medium-emphasis" style="line-height: 1.3;">
                  {{ item.verified_by_name }}<br>{{ item.verified_by_role }}
                </div>
              </template>

              <template v-slot:item.actions="{ item }">
                <div class="d-flex justify-end align-center gap-1">
                  <v-btn
                    variant="tonal" color="primary" size="small" rounded="lg"
                    class="text-none"
                    :href="item.full_url" target="_blank" rel="noopener"
                  >
                    <v-icon start size="18">mdi-download</v-icon> Download
                  </v-btn>
                  <v-btn
                    icon="mdi-delete-outline" variant="outlined" size="small" color="error"
                    :aria-label="`Delete ${item.title}`"
                    @click="askDelete(item)"
                  ></v-btn>
                </div>
              </template>
            </v-data-table>
          </v-card>

        </v-card>
      </v-col>
    </v-row>

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
        <v-card-title class="text-h6 font-weight-bold text-high-emphasis">Who verified this?</v-card-title>
        <v-card-text class="text-body-2 text-medium-emphasis">
          <div class="mb-4">
            Marking <strong class="text-high-emphasis">{{ pendingVerify?.title }}</strong> verified names the
            person who checked it — an unnamed "verified" is what this replaces.
          </div>
          <v-text-field
            v-model="verifyName"
            label="Name" placeholder="e.g. Dr. Ana Reyes"
            variant="outlined" density="comfortable" class="mb-2"
            :error-messages="verifyError"
            @update:model-value="verifyError = ''"
          ></v-text-field>
          <v-text-field
            v-model="verifyRole"
            label="Role" placeholder="e.g. MDRRMO Medical Officer"
            variant="outlined" density="comfortable"
            @update:model-value="verifyError = ''"
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
import { ref, computed, onMounted } from 'vue'
import { getToken } from '@/composables/authToken'
import { useRowNumbers } from '@/composables/rowNumber'
import { API_BASE } from '@/config/api'
import PageHeader from '@/components/PageHeader.vue'
import SkeletonRows from '@/components/SkeletonRows.vue'

const API = `${API_BASE}/admin/info-materials`

const files = ref([])
const search = ref('')
const loadingList = ref(true)
// Skeleton rows on the first load only; a refetch dims the rows it already has.
const firstLoad = computed(() => loadingList.value && files.value.length === 0)
const refreshing = computed(() => loadingList.value && files.value.length > 0)

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

const visibleFiles = computed(() => {
  const q = search.value.trim().toLowerCase()
  const since = dateThreshold()
  return files.value.filter((f) => {
    const matchesType = typeFilter.value === 'all' || category(f.file_type) === typeFilter.value
    const matchesSearch = !q || (f.title || '').toLowerCase().includes(q)
    const matchesDate = !since || new Date(f.created_at) >= since
    return matchesType && matchesSearch && matchesDate
  })
})

const rowNumber = useRowNumbers(visibleFiles, 'files_id')

const materialHeaders = [
  { title: '#', key: 'rowNumber', sortable: false, align: 'center', width: '64px' },
  { title: 'File', key: 'title', width: '32%' },
  { title: 'Type', key: 'file_type', width: '12%' },
  { title: 'Size', key: 'file_size', width: '9%' },
  { title: 'Uploaded', key: 'created_at', width: '13%' },
  { title: 'Verified', key: 'verified', width: '13%' },
  { title: '', key: 'actions', sortable: false, align: 'end', width: '21%' },
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

const fetchFiles = async () => {
  loadingList.value = true
  try {
    const res = await fetch(API, { headers: getHeaders() })
    const data = await res.json()
    if (!res.ok) throw new Error(data.message || 'Failed to fetch materials')
    files.value = Array.isArray(data) ? data : (data.data || [])
  } catch (error) {
    console.error('Failed to fetch materials:', error)
    files.value = []
    notify(error.message || 'Could not load materials', 'error')
  } finally {
    loadingList.value = false
  }
}

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
      await fetchFiles()
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
const verifyError = ref('')

const onToggleVerified = (item, value) => {
  if (!value) {
    setVerified(item, false)
    return
  }

  pendingVerify.value = item
  verifyName.value = ''
  verifyRole.value = ''
  verifyError.value = ''
  verifyDialog.value = true
}

const cancelVerify = () => {
  verifyDialog.value = false
  pendingVerify.value = null
}

const confirmVerify = async () => {
  if (!verifyName.value.trim() || !verifyRole.value.trim()) {
    verifyError.value = 'Name and role are both required.'
    return
  }

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
    await fetchFiles()
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
.gap-4 { gap: 16px; }
.min-w-0 { min-width: 0; }
.search-field { max-width: 240px; }
.date-field { max-width: 170px; }
.type-filter { max-width: 100%; }

/* Dropzone */
.dropzone {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 32px 16px;
  border-style: dashed !important;
  border-width: 2px !important;
  border-radius: 16px;
  cursor: pointer;
  transition: background-color var(--motion-base) var(--ease-out), border-color var(--motion-base) var(--ease-out);
  background-color: rgba(var(--v-theme-on-surface), 0.02);
}
.dropzone:hover,
.dropzone:focus-visible {
  background-color: rgba(var(--v-theme-primary), 0.06);
  border-color: rgb(var(--v-theme-primary)) !important;
  outline: none;
}
.dropzone--active {
  background-color: rgba(var(--v-theme-primary), 0.12);
  border-color: rgb(var(--v-theme-primary)) !important;
}

.staging-card { border: 1px solid rgba(var(--v-theme-primary), 0.4); }

/* Materials table. Fixed layout keeps the seven columns stable regardless
   of file-title length. */
.materials-table :deep(table) { table-layout: fixed !important; width: 100% !important; min-width: 700px; }
.materials-table :deep(thead th) {
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}
.row-number {
  font-size: 0.95rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.file-size { font-variant-numeric: tabular-nums; }

.icon-wrapper {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
}
.type-badge {
  display: inline-block;
  margin-top: 2px;
  padding: 1px 8px;
  border-radius: 6px;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.06em;
}

/* Empty state */
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 56px 16px;
  border-radius: 16px;
}

@media (prefers-reduced-motion: reduce) {
  .dropzone { transition: none; }
}
</style>
