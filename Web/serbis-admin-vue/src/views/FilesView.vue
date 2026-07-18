<template>
  <v-container fluid class="fill-height align-start pa-8 bg-background">
    <v-row>
      <v-col cols="12">
        <v-card elevation="2" rounded="xl" class="pa-6 border-0">

          <!-- Header -->
          <v-row class="mb-6" align="center" justify="space-between">
            <v-col cols="12" md="5">
              <h2 class="text-h5 font-weight-bold text-high-emphasis">Info Materials</h2>
              <div class="text-subtitle-2 text-medium-emphasis">
                {{ files.length }} published · residents receive these on the mobile app
              </div>
            </v-col>

            <v-col cols="12" md="7" class="d-flex justify-end align-center gap-4 flex-wrap">
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
              PDF, Word, images or ZIP — max 10 MB. This is what residents will download.
            </div>
            <input
              ref="fileInput"
              type="file"
              class="d-none"
              accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.zip"
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
              <v-btn icon="mdi-close" variant="text" size="small" :disabled="uploading" @click="clearStaged"></v-btn>
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

          <!-- Loading skeleton -->
          <v-row v-if="loadingList">
            <v-col v-for="n in 4" :key="n" cols="12" sm="6" md="4" lg="3">
              <v-skeleton-loader type="image, list-item-two-line" rounded="xl"></v-skeleton-loader>
            </v-col>
          </v-row>

          <!-- Empty state -->
          <div v-else-if="!visibleFiles.length" class="empty-state subtle-surface">
            <v-icon size="48" class="text-medium-emphasis mb-3">mdi-file-hidden</v-icon>
            <div class="text-subtitle-1 font-weight-bold text-high-emphasis">
              {{ files.length ? 'No materials match your filter' : 'No materials published yet' }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ files.length ? 'Try a different search or type.' : 'Upload the first document residents will see.' }}
            </div>
          </div>

          <!-- Card grid -->
          <v-row v-else>
            <v-col
              v-for="item in visibleFiles"
              :key="item.files_id"
              cols="12" sm="6" md="4" lg="3"
            >
              <v-card rounded="xl" class="material-card subtle-border h-100 d-flex flex-column" elevation="0">
                <div class="type-strip" :style="{ background: `rgb(var(--v-theme-${getFileIconColor(item.file_type)}))` }">
                  <v-icon color="white" size="20">{{ getFileIcon(item.file_type) }}</v-icon>
                  <span class="type-label">{{ (item.file_type || 'file').toUpperCase() }}</span>
                </div>

                <div class="pa-4 flex-grow-1 d-flex flex-column">
                  <div class="text-subtitle-1 font-weight-bold text-high-emphasis material-title">
                    {{ item.title }}
                  </div>
                  <div class="text-caption text-medium-emphasis mt-1">
                    {{ formatBytes(item.file_size) }} · {{ relativeDate(item.created_at) }}
                  </div>

                  <div class="d-flex gap-2 mt-auto pt-3">
                    <v-btn
                      variant="tonal" color="primary" size="small" rounded="lg"
                      class="text-none flex-grow-1"
                      :href="item.full_url" target="_blank" rel="noopener"
                    >
                      <v-icon start size="18">mdi-download</v-icon> Download
                    </v-btn>
                    <v-btn
                      icon variant="text" size="small" color="error"
                      :aria-label="`Delete ${item.title}`"
                      @click="askDelete(item)"
                    >
                      <v-icon>mdi-delete-outline</v-icon>
                    </v-btn>
                  </div>
                </div>
              </v-card>
            </v-col>
          </v-row>

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
          <v-btn variant="text" class="text-none" @click="deleteDialog = false" :disabled="deleting">Cancel</v-btn>
          <v-btn color="error" variant="flat" rounded="lg" class="text-none font-weight-bold" :loading="deleting" @click="confirmDelete">
            Delete
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Feedback -->
    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="bottom right" rounded="lg">
      {{ snackbar.text }}
      <template v-slot:actions>
        <v-btn icon="mdi-close" variant="text" size="small" @click="snackbar.show = false"></v-btn>
      </template>
    </v-snackbar>
  </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { getToken } from '@/composables/authToken'

const API = 'http://localhost:8000/api/admin/info-materials'

const files = ref([])
const search = ref('')
const loadingList = ref(true)

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

const formatBytes = (bytes, decimals = 2) => {
  if (!+bytes) return '0 Bytes'
  const k = 1024
  const dm = decimals < 0 ? 0 : decimals
  const sizes = ['Bytes', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`
}

const relativeDate = (iso) => {
  const then = new Date(iso)
  const days = Math.floor((Date.now() - then.getTime()) / 86400000)
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

  xhr.upload.onprogress = (evt) => {
    if (evt.lengthComputable) progress.value = Math.round((evt.loaded / evt.total) * 100)
  }
  xhr.onload = async () => {
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
  }
  xhr.onerror = () => { uploading.value = false; apiError.value = 'Network error during upload' }
  xhr.send(payload)
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
  transition: background-color 0.2s ease, border-color 0.2s ease;
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

/* Material card */
.material-card {
  overflow: hidden;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.material-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(var(--v-theme-on-surface), 0.12) !important;
}
.type-strip {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
}
.type-label {
  color: #fff;
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.06em;
}
.material-title {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  line-height: 1.35;
  min-height: 2.7em;
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
  .material-card,
  .dropzone { transition: none; }
  .material-card:hover { transform: none; }
}
</style>
