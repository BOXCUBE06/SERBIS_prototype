<template>
  <v-container fluid class="fill-height align-start pa-6 bg-background">
    <v-row class="ma-0 w-100">
      <v-col cols="12" class="pa-0 w-100">
        
        <!-- Metrics Row (Larger with Shadows) -->
        <v-row class="mb-6">
          <v-col v-for="(metric, index) in metrics" :key="index" cols="12" md="3">
            <v-skeleton-loader v-if="initialLoad" type="list-item-avatar-two-line" elevation="3" rounded="lg"></v-skeleton-loader>
            <v-card v-else elevation="3" rounded="lg" class="pa-6 bg-surface d-flex align-center metric-card fade-in">
              <v-avatar :color="metric.color" size="64" class="mr-5 rounded-lg">
                <v-icon size="32" :class="metric.iconColor">{{ metric.icon }}</v-icon>
              </v-avatar>
              <div>
                <div class="text-subtitle-2 text-uppercase font-weight-bold text-medium-emphasis">{{ metric.title }}</div>
                <div class="custom-metric-number text-high-emphasis mt-2">{{ metric.value }}</div>
              </div>
            </v-card>
          </v-col>
        </v-row>

        <!-- Main Content -->
        <v-card elevation="3" rounded="lg" class="bg-surface w-100">
          <div class="pa-6 border-b d-flex flex-row align-center justify-space-between gap-4">
            <div>
              <h2 class="text-h5 font-weight-bold text-high-emphasis">Equipment Inventory</h2>
            </div>
            
            <div class="d-flex gap-4 align-center">
              <v-text-field
                v-model="search"
                prepend-inner-icon="mdi-magnify"
                placeholder="Search equipment..."
                variant="outlined"
                density="comfortable"
                hide-details
                bg-color="white"
                style="width: 300px;"
              ></v-text-field>

              <v-select
                v-model="filters.status"
                :items="['All', 'Available', 'Unavailable']"
                variant="outlined"
                density="comfortable"
                hide-details
                bg-color="white"
                style="width: 180px;"
              ></v-select>

              <v-btn color="#0f4c3a" height="48" elevation="2" class="text-none font-weight-bold px-6 text-white" @click="openAddModal">
                <v-icon start>mdi-plus</v-icon> Add Equipment
              </v-btn>
            </div>
          </div>

          <v-skeleton-loader v-if="initialLoad" type="table-tbody" class="pa-4"></v-skeleton-loader>

          <v-data-table
            v-else
            :headers="headers"
            :items="filteredEquipments"
            :search="search"
            :items-per-page="10"
            hover
            class="elegant-table fade-in"
            @click:row="openEditModal"
          >
            <template v-slot:item.item_name="{ item }">
              <span class="font-weight-black text-high-emphasis text-h6">{{ item.item_name }}</span>
            </template>

            <template v-slot:item.quantities="{ item }">
              <div class="d-flex justify-center align-center subtle-surface rounded-pill px-4 py-2 mx-auto" style="width: fit-content;">
                <span class="text-subtitle-1 font-weight-black" :class="item.available_quantity > 0 ? 'text-primary' : 'text-error'">{{ item.available_quantity }}</span>
                <span class="text-subtitle-1 text-medium-emphasis mx-2">/</span>
                <span class="text-subtitle-1 font-weight-bold text-medium-emphasis">{{ item.total_quantity }}</span>
              </div>
            </template>

            <template v-slot:item.updated_at="{ item }">
              <span class="text-body-1 font-weight-medium text-medium-emphasis">{{ new Date(item.updated_at).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) }}</span>
            </template>

            <template v-slot:item.status="{ item }">
              <v-chip
                :color="item.status === 'Available' && item.available_quantity > 0 ? '#0f4c3a' : 'error'"
                size="default"
                label
                variant="flat"
                class="text-uppercase font-weight-bold px-4 text-white"
              >
                {{ item.available_quantity === 0 ? 'Depleted' : item.status }}
              </v-chip>
            </template>
          </v-data-table>
        </v-card>
      </v-col>
    </v-row>

    <!-- Modal -->
    <v-dialog v-model="modal.isOpen" max-width="500" persistent transition="dialog-fade-transition">
      <v-card rounded="lg" elevation="4">
        <v-card-title class="d-flex justify-space-between align-center pa-6 border-b bg-surface">
          <span class="text-h6 font-weight-bold text-high-emphasis">
            {{ modal.isEditing ? 'Edit Equipment' : 'Add New Equipment' }}
          </span>
          <v-btn icon="mdi-close" variant="text" size="small" color="grey-darken-2" @click="closeModal"></v-btn>
        </v-card-title>

        <v-card-text class="pa-6">
          <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6" density="compact">
            {{ apiError }}
          </v-alert>

          <v-form ref="form" @submit.prevent="saveEquipment">
            <v-row>
              <v-col cols="12">
                <v-text-field v-model="formData.item_name" label="Item Name" variant="outlined" density="comfortable" required></v-text-field>
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field v-model.number="formData.total_quantity" label="Total Owned" type="number" min="1" variant="outlined" density="comfortable" required></v-text-field>
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field v-if="modal.isEditing" v-model.number="formData.available_quantity" label="Available Now" type="number" min="0" :max="formData.total_quantity" variant="outlined" density="comfortable" required></v-text-field>
                <div v-else class="text-caption text-grey mt-2 text-center border rounded pa-3 subtle-surface">Auto-syncs with total count</div>
              </v-col>
              <v-col cols="12">
                <v-select v-model="formData.status" :items="['Available', 'Unavailable']" label="Status" variant="outlined" density="comfortable" required></v-select>
              </v-col>
            </v-row>
          </v-form>
        </v-card-text>

        <v-card-actions class="pa-6 pt-0 d-flex justify-start bg-surface gap-3">
          <v-btn color="#0f4c3a" variant="flat" class="text-none font-weight-bold px-6 text-white" height="44" @click="saveEquipment" :loading="loading">Save Changes</v-btn>
          <v-btn v-if="modal.isEditing" color="error" variant="text" class="text-none font-weight-bold px-4" height="44" @click="deleteEquipment" :loading="deleteLoading">Delete</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'

const headers = [
  { title: 'ITEM NAME', key: 'item_name' },
  { title: 'AVAILABILITY / TOTAL', key: 'quantities', align: 'center', sortable: false },
  { title: 'LAST UPDATED', key: 'updated_at' },
  { title: 'STATUS', key: 'status', align: 'center' }
]

const equipments = ref([])
const search = ref('')
const initialLoad = ref(true)
const loading = ref(false)
const deleteLoading = ref(false)
const apiError = ref('')

const filters = ref({ status: 'All' })
const modal = ref({ isOpen: false, isEditing: false, targetId: null })

const formData = ref({ item_name: '', total_quantity: 1, available_quantity: 1, status: 'Available' })

const totalItems = computed(() => equipments.value.reduce((acc, curr) => acc + curr.total_quantity, 0))
const totalAvailableItems = computed(() => equipments.value.reduce((acc, curr) => acc + curr.available_quantity, 0))
const depletedCategories = computed(() => equipments.value.filter(e => e.available_quantity === 0 || e.status === 'Unavailable').length)

const metrics = computed(() => [
  { title: 'Resource Categories', value: equipments.value.length, icon: 'mdi-toolbox-outline', color: 'green-lighten-5', iconColor: 'text-primary' },
  { title: 'Total Items Owned', value: totalItems.value, icon: 'mdi-package-variant-closed', color: 'green-lighten-5', iconColor: 'text-primary' },
  { title: 'Currently Available', value: totalAvailableItems.value, icon: 'mdi-check-all', color: 'green-lighten-5', iconColor: 'text-primary' },
  { title: 'Depleted / Unavailable', value: depletedCategories.value, icon: 'mdi-alert-octagon-outline', color: 'red-lighten-5', iconColor: 'text-error' }
])

const filteredEquipments = computed(() => {
  let result = equipments.value
  if (filters.value.status !== 'All') result = result.filter(e => e.status === filters.value.status)
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
    console.error(error)
  } finally {
    initialLoad.value = false
  }
}

const openAddModal = () => {
  apiError.value = ''
  formData.value = { item_name: '', total_quantity: 1, available_quantity: 1, status: 'Available' }
  modal.value = { isOpen: true, isEditing: false, targetId: null }
}

const openEditModal = (event, { item }) => {
  apiError.value = ''
  formData.value = { item_name: item.item_name, total_quantity: item.total_quantity, available_quantity: item.available_quantity, status: item.status }
  modal.value = { isOpen: true, isEditing: true, targetId: item.equipment_id || item.id }
}

const closeModal = () => modal.value.isOpen = false

const saveEquipment = async () => {
  loading.value = true
  apiError.value = ''
  const url = modal.value.isEditing ? `http://localhost:8000/api/equipments/${modal.value.targetId}` : 'http://localhost:8000/api/equipments'
  try {
    const res = await fetch(url, { method: modal.value.isEditing ? 'PUT' : 'POST', headers: getHeaders(), body: JSON.stringify(formData.value) })
    if (!res.ok) throw new Error('Validation failed')
    await fetchEquipments()
    closeModal()
  } catch (error) {
    apiError.value = error.message
  } finally {
    loading.value = false
  }
}

const deleteEquipment = async () => {
  if (!confirm('Are you sure you want to delete this resource category?')) return
  deleteLoading.value = true
  try {
    const res = await fetch(`http://localhost:8000/api/equipments/${modal.value.targetId}`, { method: 'DELETE', headers: getHeaders() })
    if (!res.ok) throw new Error('Failed to delete')
    await fetchEquipments()
    closeModal()
  } catch (error) {
    apiError.value = error.message
  } finally {
    deleteLoading.value = false
  }
}

onMounted(fetchEquipments)
</script>

<style scoped>
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }

.metric-card {
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.metric-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 12px 20px rgba(0,0,0,0.08) !important;
}

.fade-in {
  animation: fadeIn 0.4s ease-in-out;
}
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

.elegant-table :deep(th) {
  font-size: 1.1rem !important;
  font-weight: 700 !important;
  color: rgba(var(--v-theme-on-surface), 0.7) !important;
  padding: 0 24px !important;
  height: 56px !important;
  border-bottom: 2px solid rgba(var(--v-theme-on-surface), 0.12) !important;
  background-color: rgb(var(--v-theme-surface)) !important;
}
.elegant-table :deep(td) {
  padding: 16px 24px !important;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08) !important;
}

.custom-metric-number {
  font-size: 2.45rem !important; /* Forces the size. Increase if needed (e.g., 3rem) */
  font-weight: 500 !important;
  line-height: 1 !important;
  letter-spacing: -0.02em !important;
}
</style>