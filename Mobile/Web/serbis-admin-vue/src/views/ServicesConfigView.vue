<template>
  <v-container fluid class="bg-grey-lighten-4 fill-height align-start pa-8" style="background-color: #F4F7FC !important;">
    <v-row>
      <v-col cols="12">
        <v-card elevation="2" rounded="xl" class="pa-6 border-0">
          
          <v-row class="mb-6" align="center" justify="space-between">
            <v-col cols="12" md="6">
              <h2 class="text-h5 font-weight-bold text-grey-darken-3">Service Management</h2>
              <div class="text-subtitle-2 text-grey">Configure the types of emergency and public services offered by the MDRRMO</div>
            </v-col>
            
            <v-col cols="12" md="6" class="d-flex justify-end gap-4">
              <v-text-field
                v-model="search"
                prepend-inner-icon="mdi-magnify"
                placeholder="Search services..."
                variant="outlined"
                density="compact"
                hide-details
                rounded="lg"
                bg-color="white"
                class="max-w-xs"
              ></v-text-field>

              <v-btn color="primary" elevation="0" rounded="lg" height="40" class="px-6 text-none font-weight-bold" @click="openAddModal">
                <v-icon start>mdi-plus</v-icon> Add Service
              </v-btn>
            </v-col>
          </v-row>

          <v-data-table
            :headers="headers"
            :items="services"
            :search="search"
            :items-per-page="10"
            hover
            class="custom-table"
          >
            <template v-slot:item.service_name="{ item }">
              <span class="font-weight-bold text-grey-darken-3">{{ item.service_name }}</span>
            </template>

            <template v-slot:item.actions="{ item }">
              <div class="d-flex gap-2 justify-end">
                <v-btn icon variant="text" size="small" color="info" @click="openEditModal(item)">
                  <v-icon>mdi-pencil</v-icon>
                </v-btn>
                <v-btn icon variant="text" size="small" color="error" @click="deleteService(item.id || item.service_id)">
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
          <span class="text-h6 font-weight-bold text-grey-darken-3">
            {{ isEditing ? 'Edit Service' : 'Add New Service' }}
          </span>
          <v-btn icon="mdi-close" variant="text" size="small" color="grey" @click="modal = false"></v-btn>
        </v-card-title>

        <v-card-text class="pa-6">
          <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6" density="compact" rounded="lg">
            {{ apiError }}
          </v-alert>

          <v-form ref="form" @submit.prevent="saveService">
            <v-text-field 
              v-model="formData.service_name" 
              label="Service Name *" 
              variant="outlined" 
              density="comfortable" 
              rounded="lg" 
              color="primary" 
              bg-color="white" 
              required
              class="mb-4"
            ></v-text-field>

            <v-textarea
              v-model="formData.description"
              label="Description (Optional)"
              variant="outlined"
              density="comfortable"
              rounded="lg"
              color="primary"
              bg-color="white"
              rows="3"
            ></v-textarea>
          </v-form>
        </v-card-text>

        <v-card-actions class="pa-6 pt-0 d-flex justify-start">
          <v-btn color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" height="44" @click="saveService" :loading="loading">
            {{ isEditing ? 'Update' : 'Save' }}
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
  { title: 'Service Name', key: 'service_name' },
  { title: 'Description', key: 'description' },
  { title: 'Actions', key: 'actions', align: 'end', sortable: false }
]

const services = ref([])
const search = ref('')
const loading = ref(false)
const modal = ref(false)
const isEditing = ref(false)
const currentId = ref(null)
const apiError = ref('')

const formData = ref({
  service_name: '',
  description: ''
})

const getHeaders = () => ({
  'Authorization': `Bearer ${localStorage.getItem('serbis_token')}`,
  'Content-Type': 'application/json',
  'Accept': 'application/json'
})

const fetchServices = async () => {
  try {
    const res = await fetch('http://localhost:8000/api/services', { headers: getHeaders() })
    const data = await res.json()
    if (!res.ok) throw new Error(data.message || 'Failed to fetch services')
    services.value = Array.isArray(data) ? data : (data.data || [])
  } catch (error) {
    console.error('Failed to fetch services:', error)
    services.value = []
  }
}

const openAddModal = () => {
  apiError.value = ''
  isEditing.value = false
  currentId.value = null
  formData.value = { service_name: '', description: '' }
  modal.value = true
}

const openEditModal = (item) => {
  apiError.value = ''
  isEditing.value = true
  currentId.value = item.id || item.service_id // Fallback for specific PK naming
  formData.value = {
    service_name: item.service_name,
    description: item.description || ''
  }
  modal.value = true
}

const saveService = async () => {
  if (!formData.value.service_name) {
    apiError.value = 'Service name is required.'
    return
  }

  loading.value = true
  apiError.value = ''

  const method = isEditing.value ? 'PUT' : 'POST'
  const url = isEditing.value 
    ? `http://localhost:8000/api/services/${currentId.value}` 
    : 'http://localhost:8000/api/services'

  try {
    const res = await fetch(url, {
      method,
      headers: getHeaders(),
      body: JSON.stringify(formData.value)
    })

    if (!res.ok) {
      const err = await res.json()
      throw new Error(err.message || 'Operation failed')
    }

    await fetchServices()
    modal.value = false
  } catch (error) {
    apiError.value = error.message
  } finally {
    loading.value = false
  }
}

const deleteService = async (id) => {
  if (!confirm('Are you sure you want to delete this service?')) return

  try {
    const res = await fetch(`http://localhost:8000/api/services/${id}`, {
      method: 'DELETE',
      headers: getHeaders()
    })

    if (!res.ok) throw new Error('Delete failed')
    
    await fetchServices()
  } catch (error) {
    alert(error.message)
  }
}

onMounted(() => fetchServices())
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