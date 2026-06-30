<template>
  <v-container fluid class="bg-grey-lighten-4 fill-height align-start pa-8" style="background-color: #F4F7FC !important;">
    <v-row>
      <v-col cols="12">
        
        <v-row class="mb-4">
          <v-col cols="12" md="3">
            <v-card elevation="1" rounded="xl" class="pa-4 border-0 d-flex align-center">
              <v-avatar color="blue-lighten-5" size="50" class="mr-4 text-blue-darken-2">
                <v-icon>mdi-toolbox-outline</v-icon>
              </v-avatar>
              <div>
                <div class="text-subtitle-2 text-grey-darken-1 font-weight-medium">Resource Categories</div>
                <div class="text-h5 font-weight-bold">{{ equipments.length }}</div>
              </div>
            </v-card>
          </v-col>
          <v-col cols="12" md="3">
            <v-card elevation="1" rounded="xl" class="pa-4 border-0 d-flex align-center">
              <v-avatar color="indigo-lighten-5" size="50" class="mr-4 text-indigo-darken-2">
                <v-icon>mdi-package-variant-closed</v-icon>
              </v-avatar>
              <div>
                <div class="text-subtitle-2 text-grey-darken-1 font-weight-medium">Total Items Owned</div>
                <div class="text-h5 font-weight-bold">{{ totalItems }}</div>
              </div>
            </v-card>
          </v-col>
          <v-col cols="12" md="3">
            <v-card elevation="1" rounded="xl" class="pa-4 border-0 d-flex align-center">
              <v-avatar color="green-lighten-5" size="50" class="mr-4 text-green-darken-2">
                <v-icon>mdi-check-all</v-icon>
              </v-avatar>
              <div>
                <div class="text-subtitle-2 text-grey-darken-1 font-weight-medium">Currently Available</div>
                <div class="text-h5 font-weight-bold">{{ totalAvailableItems }}</div>
              </div>
            </v-card>
          </v-col>
          <v-col cols="12" md="3">
            <v-card elevation="1" rounded="xl" class="pa-4 border-0 d-flex align-center">
              <v-avatar color="red-lighten-5" size="50" class="mr-4 text-red-darken-2">
                <v-icon>mdi-alert-octagon-outline</v-icon>
              </v-avatar>
              <div>
                <div class="text-subtitle-2 text-grey-darken-1 font-weight-medium">Depleted / Unavailable</div>
                <div class="text-h5 font-weight-bold">{{ depletedCategories }}</div>
              </div>
            </v-card>
          </v-col>
        </v-row>

        <v-card elevation="2" rounded="xl" class="pa-6 border-0">
          
          <v-row class="mb-6" align="center" justify="space-between">
            <v-col cols="12" md="4">
              <h2 class="text-h5 font-weight-bold text-grey-darken-3">Equipment Inventory</h2>
              <div class="text-subtitle-2 text-grey">Manage physical assets and borrowing availability</div>
            </v-col>
            
            <v-col cols="12" md="8" class="d-flex gap-4 justify-end">
              <v-text-field
                v-model="search"
                prepend-inner-icon="mdi-magnify"
                placeholder="Search equipment name..."
                variant="outlined"
                density="compact"
                hide-details
                rounded="lg"
                bg-color="white"
                class="max-w-xs"
              ></v-text-field>

              <v-select
                v-model="filters.status"
                :items="['All', 'Available', 'Unavailable']"
                label="Status Filter"
                variant="outlined"
                density="compact"
                hide-details
                rounded="lg"
                bg-color="white"
                class="max-w-xs"
              ></v-select>

              <v-btn color="primary" elevation="0" rounded="lg" height="40" class="px-6 text-none font-weight-bold" @click="openAddModal">
                <v-icon start>mdi-plus</v-icon> Add Equipment
              </v-btn>
            </v-col>
          </v-row>

          <v-data-table
            :headers="headers"
            :items="filteredEquipments"
            :search="search"
            :items-per-page="10"
            hover
            class="custom-table"
            @click:row="openEditModal"
          >
            <template v-slot:item.item_name="{ item }">
              <span class="font-weight-bold text-grey-darken-3">{{ item.item_name }}</span>
            </template>

            <template v-slot:item.quantities="{ item }">
              <div class="d-flex align-center gap-2">
                <span class="font-weight-bold text-primary">{{ item.available_quantity }}</span>
                <span class="text-caption text-grey">/ {{ item.total_quantity }}</span>
                <v-progress-linear
                  :model-value="(item.available_quantity / item.total_quantity) * 100"
                  :color="item.available_quantity > 0 ? 'success' : 'error'"
                  height="6"
                  rounded
                  class="ml-2 w-50"
                ></v-progress-linear>
              </div>
            </template>

            <template v-slot:item.status="{ item }">
              <v-chip
                :color="item.status === 'Available' && item.available_quantity > 0 ? 'success' : 'error'"
                size="small"
                label
                variant="flat"
                class="text-uppercase font-weight-bold px-3 text-white"
              >
                {{ item.available_quantity === 0 ? 'Depleted' : item.status }}
              </v-chip>
            </template>
          </v-data-table>
        </v-card>
      </v-col>
    </v-row>

    <v-dialog v-model="modal.isOpen" max-width="500" persistent>
      <v-card rounded="xl" elevation="10">
        <v-card-title class="d-flex justify-space-between align-center pa-6 border-b">
          <span class="text-h6 font-weight-bold text-grey-darken-3">
            {{ modal.isEditing ? 'Edit Equipment' : 'Add New Equipment' }}
          </span>
          <v-btn icon="mdi-close" variant="text" size="small" color="grey" @click="closeModal"></v-btn>
        </v-card-title>

        <v-card-text class="pa-6">
          <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6" density="compact" rounded="lg">
            {{ apiError }}
          </v-alert>

          <v-form ref="form" @submit.prevent="saveEquipment">
            <v-row>
              <v-col cols="12">
                <v-text-field 
                  v-model="formData.item_name" 
                  label="Item Name (e.g., Wheelchair, Oxygen Tank) *" 
                  variant="outlined" 
                  density="comfortable" 
                  rounded="lg" 
                  color="primary" 
                  bg-color="white" 
                  required
                ></v-text-field>
              </v-col>

              <v-col cols="12" md="6">
                <v-text-field 
                  v-model.number="formData.total_quantity" 
                  label="Total Owned Quantity *" 
                  type="number"
                  min="1"
                  variant="outlined" 
                  density="comfortable" 
                  rounded="lg" 
                  color="primary" 
                  bg-color="white" 
                  required
                ></v-text-field>
              </v-col>

              <v-col cols="12" md="6">
                <v-text-field 
                  v-if="modal.isEditing"
                  v-model.number="formData.available_quantity" 
                  label="Currently Available *" 
                  type="number"
                  min="0"
                  :max="formData.total_quantity"
                  variant="outlined" 
                  density="comfortable" 
                  rounded="lg" 
                  color="primary" 
                  bg-color="white" 
                  required
                ></v-text-field>
                <v-alert v-else type="info" variant="tonal" density="compact" class="text-caption">
                  Available quantity will be set to total automatically.
                </v-alert>
              </v-col>

              <v-col cols="12">
                <v-select
                  v-model="formData.status"
                  :items="['Available', 'Unavailable']"
                  label="System Status *"
                  variant="outlined"
                  density="comfortable"
                  rounded="lg"
                  color="primary"
                  bg-color="white"
                  persistent-hint
                  hint="Set to Unavailable if all items are under maintenance or retired."
                  required
                ></v-select>
              </v-col>
            </v-row>
          </v-form>
        </v-card-text>

        <v-card-actions class="pa-6 pt-0 d-flex justify-start">
          <v-btn color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" height="44" @click="saveEquipment" :loading="loading">
            Save Changes
          </v-btn>
          <v-btn v-if="modal.isEditing" color="error" variant="outlined" rounded="lg" class="px-6 text-none font-weight-bold ml-3" height="44" @click="deleteEquipment" :loading="deleteLoading">
            Delete
          </v-btn>
          <v-spacer></v-spacer>
          <v-btn color="grey-darken-1" variant="text" rounded="lg" class="px-6 text-none font-weight-bold" height="44" @click="closeModal">
            Cancel
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'

const headers = [
  { title: 'Item Name', key: 'item_name' },
  { title: 'Availability / Total', key: 'quantities', sortable: false },
  { title: 'Status', key: 'status', align: 'center' }
]

const equipments = ref([])
const search = ref('')
const loading = ref(false)
const deleteLoading = ref(false)
const apiError = ref('')

const filters = ref({
  status: 'All'
})

const modal = ref({
  isOpen: false,
  isEditing: false,
  targetId: null
})

const formData = ref({
  item_name: '',
  total_quantity: 1,
  available_quantity: 1,
  status: 'Available'
})

// Metrics Computations
const totalItems = computed(() => equipments.value.reduce((acc, curr) => acc + curr.total_quantity, 0))
const totalAvailableItems = computed(() => equipments.value.reduce((acc, curr) => acc + curr.available_quantity, 0))
const depletedCategories = computed(() => equipments.value.filter(e => e.available_quantity === 0 || e.status === 'Unavailable').length)

// Filter Logic
const filteredEquipments = computed(() => {
  let result = equipments.value

  if (filters.value.status !== 'All') {
    result = result.filter(e => e.status === filters.value.status)
  }

  return result
})

const getHeaders = () => ({
  'Authorization': `Bearer ${localStorage.getItem('serbis_token')}`,
  'Content-Type': 'application/json',
  'Accept': 'application/json'
})

const fetchEquipments = async () => {
  try {
    const res = await fetch('http://localhost:8000/api/equipments', { headers: getHeaders() })
    const data = await res.json()
    equipments.value = data.data || data 
  } catch (error) {
    console.error('Failed to fetch equipments:', error)
  }
}

const openAddModal = () => {
  apiError.value = ''
  formData.value = { item_name: '', total_quantity: 1, available_quantity: 1, status: 'Available' }
  modal.value = { isOpen: true, isEditing: false, targetId: null }
}

const openEditModal = (event, { item }) => {
  apiError.value = ''
  formData.value = {
    item_name: item.item_name,
    total_quantity: item.total_quantity,
    available_quantity: item.available_quantity,
    status: item.status
  }
  modal.value = { isOpen: true, isEditing: true, targetId: item.equipment_id || item.id }
}

const closeModal = () => {
  modal.value.isOpen = false
}

const saveEquipment = async () => {
  loading.value = true
  apiError.value = ''
  
  const url = modal.value.isEditing 
    ? `http://localhost:8000/api/equipments/${modal.value.targetId}` 
    : 'http://localhost:8000/api/equipments'
  
  const method = modal.value.isEditing ? 'PUT' : 'POST'

  try {
    const res = await fetch(url, {
      method,
      headers: getHeaders(),
      body: JSON.stringify(formData.value)
    })
    
    if (!res.ok) {
      const errData = await res.json()
      throw new Error(errData.message || 'Validation failed')
    }

    await fetchEquipments()
    closeModal()
  } catch (error) {
    apiError.value = error.message
  } finally {
    loading.value = false
  }
}

const deleteEquipment = async () => {
  if (!confirm('Are you sure you want to delete this resource category? This may affect historical borrowing logs.')) return
  
  deleteLoading.value = true
  try {
    const res = await fetch(`http://localhost:8000/api/equipments/${modal.value.targetId}`, {
      method: 'DELETE',
      headers: getHeaders()
    })

    if (!res.ok) throw new Error('Failed to delete')

    await fetchEquipments()
    closeModal()
  } catch (error) {
    apiError.value = error.message
  } finally {
    deleteLoading.value = false
  }
}

onMounted(() => {
  fetchEquipments()
})
</script>

<style scoped>
.gap-2 { gap: 8px; }
.gap-4 { gap: 16px; }
.w-50 { width: 50px; }
.custom-table :deep(th) {
  font-weight: 600 !important;
  color: #616161 !important;
  background-color: transparent !important;
  border-bottom: 2px solid #EEEEEE !important;
}
.custom-table :deep(td) {
  border-bottom: 1px solid #F5F5F5 !important;
}
</style>