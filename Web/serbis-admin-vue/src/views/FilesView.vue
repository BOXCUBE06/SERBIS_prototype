<template>
  <v-container fluid class="bg-grey-lighten-4 fill-height align-start pa-8" style="background-color: #F4F7FC !important;">
    <v-row>
      <v-col cols="12">
        <v-card elevation="2" rounded="xl" class="pa-6 border-0">
          
          <v-row class="mb-6" align="center" justify="space-between">
            <v-col cols="12" md="6">
              <h2 class="text-h5 font-weight-bold text-grey-darken-3">File Management</h2>
              <div class="text-subtitle-2 text-grey">Upload and manage official MDRRMO documents and information materials</div>
            </v-col>
            
            <v-col cols="12" md="6" class="d-flex justify-end gap-4">
              <v-text-field
                v-model="search"
                prepend-inner-icon="mdi-magnify"
                placeholder="Search files..."
                variant="outlined"
                density="compact"
                hide-details
                rounded="lg"
                bg-color="white"
                class="max-w-xs"
              ></v-text-field>

              <v-btn color="primary" elevation="0" rounded="lg" height="40" class="px-6 text-none font-weight-bold" @click="openUploadModal">
                <v-icon start>mdi-cloud-upload</v-icon> Upload File
              </v-btn>
            </v-col>
          </v-row>

          <v-data-table
            :headers="headers"
            :items="files"
            :search="search"
            :items-per-page="10"
            hover
            class="custom-table"
          >
            <template v-slot:item.title="{ item }">
              <div class="d-flex align-center py-2">
                <v-icon :color="getFileIconColor(item.file_type)" size="large" class="mr-3">
                  {{ getFileIcon(item.file_type) }}
                </v-icon>
                <span class="font-weight-bold text-grey-darken-3">{{ item.title }}</span>
              </div>
            </template>

            <template v-slot:item.file_size="{ item }">
              <span class="text-grey-darken-2">{{ formatBytes(item.file_size) }}</span>
            </template>

            <template v-slot:item.created_at="{ item }">
              <span class="text-grey-darken-2">{{ new Date(item.created_at).toLocaleDateString() }}</span>
            </template>

            <template v-slot:item.actions="{ item }">
              <div class="d-flex gap-2 justify-end">
                <v-btn icon variant="text" size="small" color="primary" :href="`http://localhost:8000/${item.file_path}`" target="_blank">
                  <v-icon>mdi-download</v-icon>
                </v-btn>
                <v-btn icon variant="text" size="small" color="error" @click="deleteFile(item.material_id)">
                  <v-icon>mdi-delete</v-icon>
                </v-btn>
              </div>
            </template>
          </v-data-table>
        </v-card>
      </v-col>
    </v-row>

    <v-dialog v-model="modal" max-width="500" persistent>
      <v-card rounded="xl" elevation="10">
        <v-card-title class="d-flex justify-space-between align-center pa-6 border-b">
          <span class="text-h6 font-weight-bold text-grey-darken-3">Upload Document</span>
          <v-btn icon="mdi-close" variant="text" size="small" color="grey" @click="modal = false"></v-btn>
        </v-card-title>

        <v-card-text class="pa-6">
          <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6" density="compact" rounded="lg">
            {{ apiError }}
          </v-alert>

          <v-form ref="form" @submit.prevent="uploadFile">
            <v-text-field 
              v-model="formData.title" 
              label="Document Title *" 
              variant="outlined" 
              density="comfortable" 
              rounded="lg" 
              color="primary" 
              bg-color="white" 
              required
              class="mb-4"
            ></v-text-field>

            <v-file-input
              v-model="formData.file"
              label="Select File *"
              variant="outlined"
              density="comfortable"
              rounded="lg"
              color="primary"
              bg-color="white"
              prepend-inner-icon="mdi-paperclip"
              prepend-icon=""
              show-size
              required
              accept=".pdf,.doc,.docx,.jpg,.png,.zip"
            ></v-file-input>
          </v-form>
        </v-card-text>

        <v-card-actions class="pa-6 pt-0 d-flex justify-start">
          <v-btn color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" height="44" @click="uploadFile" :loading="loading">
            Upload
          </v-btn>
          <v-spacer></v-spacer>
          <v-btn color="grey-darken-1" variant="text" rounded="lg" class="px-6 text-none font-weight-bold" height="44" @click="modal = false">
            Cancel
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-container>
</template>

<script setup>
import { ref, onMounted } from 'vue'

const headers = [
  { title: 'Document Title', key: 'title' },
  { title: 'Size', key: 'file_size' },
  { title: 'Type', key: 'file_type', align: 'center' },
  { title: 'Date Uploaded', key: 'created_at' },
  { title: 'Actions', key: 'actions', align: 'end', sortable: false }
]

const files = ref([])
const search = ref('')
const loading = ref(false)
const modal = ref(false)
const apiError = ref('')

const formData = ref({
  title: '',
  file: null
})

const getHeaders = () => ({
  'Authorization': `Bearer ${localStorage.getItem('serbis_token')}`,
  'Accept': 'application/json'
})

const formatBytes = (bytes, decimals = 2) => {
  if (!+bytes) return '0 Bytes'
  const k = 1024
  const dm = decimals < 0 ? 0 : decimals
  const sizes = ['Bytes', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`
}

const getFileIcon = (ext) => {
  const icons = {
    pdf: 'mdi-file-pdf-box',
    doc: 'mdi-file-word-box',
    docx: 'mdi-file-word-box',
    jpg: 'mdi-file-image',
    png: 'mdi-file-image',
    zip: 'mdi-folder-zip'
  }
  return icons[ext?.toLowerCase()] || 'mdi-file-document-outline'
}

const getFileIconColor = (ext) => {
  const colors = {
    pdf: 'error',
    doc: 'info',
    docx: 'info',
    jpg: 'success',
    png: 'success',
    zip: 'warning'
  }
  return colors[ext?.toLowerCase()] || 'grey'
}

const fetchFiles = async () => {
  try {
    const res = await fetch('http://localhost:8000/api/admin/info-materials', { headers: getHeaders() })
    const data = await res.json()

    if (!res.ok) {
      throw new Error(data.message || 'Failed to fetch files')
    }

    // Safely assign the array, whether it's wrapped in 'data' or not
    files.value = Array.isArray(data) ? data : (data.data || [])
  } catch (error) {
    console.error('Failed to fetch files:', error)
    // Fallback to an empty array so the data table does not crash
    files.value = [] 
  }
}

const openUploadModal = () => {
  apiError.value = ''
  formData.value = { title: '', file: null }
  modal.value = true
}

const uploadFile = async () => {
  if (!formData.value.title || !formData.value.file) {
    apiError.value = 'Title and file are required.'
    return
  }

  loading.value = true
  apiError.value = ''

  const payload = new FormData()
  payload.append('title', formData.value.title)
  
  // Extract the raw file object safely
  const fileTarget = Array.isArray(formData.value.file) 
    ? formData.value.file[0] 
    : formData.value.file

  payload.append('file', fileTarget)

  try {
    const res = await fetch('http://localhost:8000/api/admin/info-materials', {
      method: 'POST',
      headers: getHeaders(),
      body: payload
    })

    if (!res.ok) {
      const err = await res.json()
      throw new Error(err.message || 'Upload failed')
    }

    await fetchFiles()
    modal.value = false
  } catch (error) {
    apiError.value = error.message
  } finally {
    loading.value = false
  }
}

const deleteFile = async (id) => {
  if (!confirm('Are you sure you want to delete this file?')) return

  try {
    const res = await fetch(`http://localhost:8000/api/admin/info-materials/${id}`, {
      method: 'DELETE',
      headers: getHeaders()
    })

    if (!res.ok) throw new Error('Delete failed')
    
    await fetchFiles()
  } catch (error) {
    alert(error.message)
  }
}

onMounted(() => fetchFiles())
</script>

<style scoped>
.gap-2 { gap: 8px; }
.gap-4 { gap: 16px; }
.custom-table :deep(th) {
  font-weight: 600 !important;
  color: #616161 !important;
  border-bottom: 2px solid #EEEEEE !important;
}
</style>