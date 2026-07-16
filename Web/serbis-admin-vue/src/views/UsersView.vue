<template>
  <v-container fluid class="fill-height align-start pa-6 bg-background">
    <v-row class="ma-0 w-100 align-stretch" style="height: calc(100vh - 96px);">
      
      <v-col cols="8" class="pa-0 h-100">
        <v-card elevation="3" rounded="lg" class="bg-surface w-100 h-100 d-flex flex-column">
          
          <div class="px-6 py-2 border-b d-flex flex-row align-center justify-space-between gap-4 flex-shrink-0">
            <div>
              <h2 class="text-h5 font-weight-bold text-high-emphasis">User Management</h2>
            </div>
            
            <div class="d-flex gap-4 align-center">
              <v-text-field 
                v-model="search" 
                prepend-inner-icon="mdi-magnify"
                placeholder="Search users..." 
                variant="outlined" 
                density="compact" 
                hide-details
                bg-color="white" 
                style="width: 250px;"
              ></v-text-field>

              <v-select 
                v-model="filters.status"
                :items="['All', 'Active', 'Deactivated']" 
                variant="outlined" 
                density="compact" 
                hide-details 
                bg-color="white"
                style="width: 130px;"
              ></v-select>

              <v-btn color="#0f4c3a" elevation="0" rounded="lg" height="40" class="px-4 text-none font-weight-bold text-white transition-btn" @click="openAddModal">
                <v-icon start>mdi-plus</v-icon> Add User
              </v-btn>
            </div>
          </div>

          <div class="px-6 py-2 border-b subtle-surface d-flex align-center gap-2 overflow-x-auto flex-shrink-0">
            <v-btn
              variant="text"
              :class="['tab-btn text-none px-4 rounded-0', filters.barangay === 'All' ? 'active-tab text-green-darken-4 font-weight-black' : 'text-medium-emphasis font-weight-bold']"
              @click="filters.barangay = 'All'"
            >
              All Barangays
            </v-btn>
            <v-btn
              v-for="b in barangays"
              :key="b.barangay_id"
              variant="text"
              :class="['tab-btn text-none px-4 rounded-0', filters.barangay === b.barangay_name ? 'active-tab text-green-darken-4 font-weight-black' : 'text-medium-emphasis font-weight-bold']"
              @click="filters.barangay = b.barangay_name"
            >
              {{ b.barangay_name }}
            </v-btn>
          </div>

          <v-data-table 
            :headers="headers" 
            :items="filteredAndSortedResidents" 
            :search="search" 
            :items-per-page="-1"
            fixed-header
            height="calc(100vh - 240px)"
            hover 
            class="elegant-table flex-grow-1" 
            @click:row="selectRow"
            :row-props="rowProps"
          >
            <template v-slot:bottom></template>

            <template v-slot:item.photo="{ item }">
              <v-avatar color="blue-lighten-4" size="40" class="my-2">
                <v-img v-if="item.photo || item.raw?.photo" :src="item.photo || item.raw?.photo" alt="Photo"></v-img>
                <span v-else class="text-blue-darken-2 font-weight-bold text-body-2">
                  {{ (item.first_name || item.raw?.first_name)?.charAt(0) }}{{ (item.last_name || item.raw?.last_name)?.charAt(0) }}
                </span>
              </v-avatar>
            </template>

            <template v-slot:item.fullName="{ item }">
              <div class="font-weight-black text-high-emphasis text-body-1 transition-text">
                {{ item.last_name || item.raw?.last_name }}, {{ item.first_name || item.raw?.first_name }} {{ item.middle_name || item.raw?.middle_name || '' }}
              </div>
            </template>

            <template v-slot:item.barangay_name="{ item }">
              <span class="font-weight-bold text-subtitle-1 text-high-emphasis transition-text">
                {{ item.barangay?.barangay_name || item.raw?.barangay?.barangay_name || item.barangay_name || item.raw?.barangay_name || 'N/A' }}
              </span>
            </template>

            <template v-slot:item.phone_number="{ item }">
              <span class="text-body-1 font-weight-medium text-medium-emphasis transition-text">
                {{ item.phone_number || item.raw?.phone_number }}
              </span>
            </template>

            <template v-slot:item.email_address="{ item }">
              <span class="text-body-1 font-weight-medium text-medium-emphasis transition-text">
                {{ item.email_address || item.raw?.email_address }}
              </span>
            </template>

            <template v-slot:item.status="{ item }">
              <!-- No text-white: success lightens to mint in the dark theme, so
                   forcing white text drops it to ~2.2:1. Letting Vuetify pick the
                   on-colour keeps it legible in both. -->
              <v-chip
                :color="(item.status || item.raw?.status) === 'Active' ? 'success' : 'grey'"
                size="default"
                label
                variant="flat"
                class="text-uppercase font-weight-bold px-4"
              >
                {{ item.status || item.raw?.status }}
              </v-chip>
            </template>
          </v-data-table>
        </v-card>
      </v-col>

      <v-col cols="4" class="pa-0 pl-4 h-100">
        <transition name="slide-fade" mode="out-in">
          <v-card v-if="selectedResident" key="profile" elevation="3" rounded="lg" class="bg-surface h-100 d-flex flex-column relative pa-6">
            <div class="absolute top-2 right-2">
              <v-btn icon="mdi-close" variant="text" size="small" color="grey" @click="selectedResident = null" class="transition-btn"></v-btn>
            </div>

            <div class="d-flex flex-column align-center text-center mt-4">
              <v-avatar color="blue-grey-lighten-4" size="90" class="mb-4 elevation-1 transition-avatar">
                <v-img v-if="selectedResident.photo" :src="selectedResident.photo"></v-img>
                <span v-else class="text-h4 font-weight-black text-blue-grey-darken-3">
                  {{ selectedResident.first_name?.charAt(0) }}{{ selectedResident.last_name?.charAt(0) }}
                </span>
              </v-avatar>
              <h3 class="text-h5 font-weight-bold text-high-emphasis">{{ selectedResident.first_name }} {{ selectedResident.last_name }}</h3>
              <v-chip :color="selectedResident.status === 'Active' ? 'success' : 'grey'" size="small" label variant="flat" class="mt-2 text-uppercase font-weight-bold px-3">
                {{ selectedResident.status }}
              </v-chip>
            </div>

            <v-divider class="my-6"></v-divider>

            <div class="flex-grow-1">
              <h4 class="text-subtitle-1 font-weight-bold text-medium-emphasis text-uppercase mb-4">About Resident</h4>
              
              <div class="mb-4">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Barangay Location</div>
                <div class="font-weight-bold text-h6 text-high-emphasis">{{ selectedResident.barangay?.barangay_name || selectedResident.barangay_name || 'N/A' }}</div>
              </div>

              <div class="mb-4">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Phone Number</div>
                <div class="font-weight-medium text-body-1 text-high-emphasis">{{ selectedResident.phone_number }}</div>
              </div>

              <div class="mb-4">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Email Address</div>
                <div class="font-weight-medium text-body-1 text-high-emphasis text-truncate">{{ selectedResident.email_address }}</div>
              </div>
            </div>

            <v-divider class="my-4"></v-divider>
            
            <v-btn color="#0f4c3a" variant="flat" class="text-none font-weight-bold text-white w-100 transition-btn" height="44" rounded="lg" @click="openExistingEditModal(selectedResident)">
              <v-icon start>mdi-pencil</v-icon> Edit Profile Form
            </v-btn>
          </v-card>

          <v-card v-else key="placeholder" elevation="3" rounded="lg" class="bg-surface h-100 d-flex flex-column align-center justify-center pa-6 text-center">
            <v-icon size="64" color="grey-lighten-2" class="mb-4">mdi-account-search</v-icon>
            <h3 class="text-h6 font-weight-bold text-medium-emphasis">No User Selected</h3>
            <p class="text-body-2 text-medium-emphasis mt-2">Click on a user from the table to view their details.</p>
          </v-card>
        </transition>
      </v-col>
    </v-row>

    <v-dialog v-model="modal.isOpen" max-width="650" persistent>
      <v-card rounded="lg" elevation="10">
        <v-card-title class="d-flex justify-space-between align-center pa-6 border-b bg-surface">
          <span class="text-h6 font-weight-bold text-high-emphasis">
            {{ modal.isEditing ? 'Modify User Profile' : 'Add New User Account' }}
          </span>
          <v-btn icon="mdi-close" variant="text" size="small" color="grey" @click="closeModal"></v-btn>
        </v-card-title>

        <v-card-text class="pa-6">
          <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6" density="compact" rounded="lg">
            {{ apiError }}
          </v-alert>

          <v-form ref="form" @submit.prevent="saveUser">
            <v-row>
              <v-col cols="12" class="d-flex align-center gap-4 mb-2">
                <v-avatar color="grey-lighten-3" size="70">
                  <span class="text-h5 text-medium-emphasis font-weight-bold">
                    {{ formData.first_name?.charAt(0) || 'A' }}{{ formData.last_name?.charAt(0) || 'V' }}
                  </span>
                </v-avatar>
                <div>
                  <div class="text-subtitle-2 font-weight-bold text-high-emphasis mb-1">Profile Picture</div>
                  <v-btn variant="outlined" color="#0f4c3a" size="small" rounded="lg" class="text-none font-weight-bold">Change Photo</v-btn>
                </div>
              </v-col>

              <v-col cols="12" md="4">
                <v-text-field v-model="formData.first_name" label="First Name *" variant="outlined" density="comfortable" rounded="lg" required></v-text-field>
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field v-model="formData.middle_name" label="Middle Name" variant="outlined" density="comfortable" rounded="lg"></v-text-field>
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field v-model="formData.last_name" label="Last Name *" variant="outlined" density="comfortable" rounded="lg" required></v-text-field>
              </v-col>

              <v-col cols="12" md="6">
                <v-text-field v-model="formData.phone_number" label="Phone Number *" variant="outlined" density="comfortable" rounded="lg" required></v-text-field>
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field v-model="formData.email_address" label="Email Address *" type="email" variant="outlined" density="comfortable" rounded="lg" required></v-text-field>
              </v-col>

              <v-col cols="12" md="6" v-if="!modal.isEditing">
                <v-text-field v-model="formData.password" label="Password (Min 8 chars) *" type="password" variant="outlined" density="comfortable" rounded="lg" append-inner-icon="mdi-key" required></v-text-field>
              </v-col>

              <v-col cols="12" :md="modal.isEditing ? 12 : 6">
                <v-select v-model="formData.barangay_id" :items="barangays" item-title="barangay_name" item-value="barangay_id" label="Barangay *" variant="outlined" density="comfortable" rounded="lg" required></v-select>
              </v-col>

              <v-col cols="12">
                <div class="text-subtitle-2 font-weight-bold text-high-emphasis mb-2">Account Status</div>
                <v-radio-group v-model="formData.status" inline hide-details color="#0f4c3a">
                  <v-radio label="Activate" value="Active"></v-radio>
                  <v-radio label="Deactivate" value="Deactivated"></v-radio>
                </v-radio-group>
              </v-col>
            </v-row>
          </v-form>
        </v-card-text>

        <v-card-actions class="pa-6 pt-0 d-flex justify-start gap-2 bg-surface">
          <v-btn color="#0f4c3a" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold text-white" height="44" @click="saveUser" :loading="loading">
            Save Changes
          </v-btn>
          <v-btn v-if="modal.isEditing" color="error" variant="outlined" rounded="lg" class="px-6 text-none font-weight-bold" height="44" @click="deleteUser" :loading="deleteLoading">
            Delete Account
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
  { title: '', key: 'photo', sortable: false, align: 'center', width: '80px' },
  { title: 'Full Name', key: 'fullName', width: '25%' },
  { title: 'Barangay', key: 'barangay_name', width: '15%' },
  { title: 'Phone Number', key: 'phone_number', width: '20%' },
  { title: 'Email', key: 'email_address', width: '25%' },
  { title: 'Status', key: 'status', align: 'center', width: '15%' }
]   

const residents = ref([])
const barangays = ref([])
const search = ref('')
const loading = ref(false)
const deleteLoading = ref(false)
const apiError = ref('')

const selectedResident = ref(null)

const filters = ref({
  status: 'All',
  barangay: 'All'
})

const modal = ref({
  isOpen: false,
  isEditing: false,
  targetId: null
})

const formData = ref({
  first_name: '', middle_name: '', last_name: '', phone_number: '',
  email_address: '', password: '', barangay_id: null, status: 'Active'
})

const filteredAndSortedResidents = computed(() => {
  let result = residents.value
  if (filters.value.status !== 'All') {
    result = result.filter(r => r.status === filters.value.status)
  }
  if (filters.value.barangay !== 'All') {
    result = result.filter(r => (r.barangay?.barangay_name || r.barangay_name) === filters.value.barangay)
  }
  if (search.value) {
    const sLower = search.value.toLowerCase()
    result = result.filter(r => {
      const full = `${r.first_name} ${r.last_name}`.toLowerCase()
      return full.includes(sLower) || (r.email_address || '').toLowerCase().includes(sLower)
    })
  }
  return result.sort((a, b) => `${a.last_name} ${a.first_name}`.localeCompare(`${b.last_name} ${b.first_name}`))
})

const getHeaders = () => ({
  'Authorization': `Bearer ${localStorage.getItem('serbis_token')}`,
  'Content-Type': 'application/json',
  'Accept': 'application/json'
})

const fetchResidents = async () => {
  try {
    const res = await fetch('http://localhost:8000/api/residents', { headers: getHeaders() })
    const data = await res.json()
    residents.value = data.data || data
  } catch (error) {
    console.error(error)
  }
}

const fetchBarangays = async () => {
  try {
    const res = await fetch('http://localhost:8000/api/barangays', { headers: getHeaders() })
    const data = await res.json()
    barangays.value = data.data || data
  } catch (error) {
    console.error(error)
  }
}

const selectRow = (event, { item }) => {
  selectedResident.value = item
}

// Function to attach active styling dynamically based on selection
const rowProps = (data) => {
  const isSelected = selectedResident.value && 
    (data.item.id || data.item.resident_id || data.item.raw?.id || data.item.raw?.resident_id) === 
    (selectedResident.value.id || selectedResident.value.resident_id)

  return {
    class: isSelected ? 'selected-row' : 'unselected-row'
  }
}

const openAddModal = () => {
  apiError.value = ''
  formData.value = {
    first_name: '', middle_name: '', last_name: '', phone_number: '',
    email_address: '', password: '', barangay_id: null, status: 'Active'
  }
  modal.value = { isOpen: true, isEditing: false, targetId: null }
}

const openExistingEditModal = (item) => {
  apiError.value = ''
  formData.value = {
    first_name: item.first_name,
    middle_name: item.middle_name,
    last_name: item.last_name,
    phone_number: item.phone_number,
    email_address: item.email_address,
    password: '',
    barangay_id: item.barangay_id,
    status: item.status
  }
  modal.value = { isOpen: true, isEditing: true, targetId: item.id || item.resident_id }
}

const closeModal = () => {
  modal.value.isOpen = false
}

const saveUser = async () => {
  loading.value = true
  apiError.value = ''
  const url = modal.value.isEditing ? `http://localhost:8000/api/residents/${modal.value.targetId}` : 'http://localhost:8000/api/residents'
  const method = modal.value.isEditing ? 'PUT' : 'POST'
  try {
    const res = await fetch(url, { method, headers: getHeaders(), body: JSON.stringify(formData.value) })
    if (!res.ok) throw new Error('Action execution failed')
    await fetchResidents()
    if (selectedResident.value && selectedResident.value.id === modal.value.targetId) {
      selectedResident.value = residents.value.find(r => (r.id || r.resident_id) === modal.value.targetId)
    }
    closeModal()
  } catch (error) {
    apiError.value = error.message
  } finally {
    loading.value = false
  }
}

const deleteUser = async () => {
  if (!confirm('Are you sure?')) return
  deleteLoading.value = true
  try {
    const res = await fetch(`http://localhost:8000/api/residents/${modal.value.targetId}`, { method: 'DELETE', headers: getHeaders() })
    if (!res.ok) throw new Error('Delete execution failed')
    selectedResident.value = null
    await fetchResidents()
    closeModal()
  } catch (error) {
    apiError.value = error.message
  } finally {
    deleteLoading.value = false
  }
}

onMounted(() => {
  fetchBarangays()
  fetchResidents()
})
</script>

<style scoped>
.gap-2 { gap: 8px; }
.gap-4 { gap: 16px; }
.relative { position: relative; }
.absolute { position: absolute; }
.top-2 { top: 8px; }
.right-2 { right: 8px; }

/* Custom Transitions */
.tab-btn {
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  border-bottom: 3px solid transparent;
}
/* Deep brand green reads well on the light surface (9.9:1) but vanishes on the
   dark one, so each theme gets the version that stays legible. The primary
   token alone is not enough: it is mint in dark (good) but only 4.15:1 on
   white (below AA). */
.active-tab {
  border-bottom: 3px solid #0f4c3a !important;
  color: #0f4c3a !important;
}

.v-theme--dark .active-tab {
  border-bottom-color: rgb(var(--v-theme-primary)) !important;
  color: rgb(var(--v-theme-primary)) !important;
}

.transition-btn {
  transition: transform 0.2s ease, opacity 0.2s ease;
}
.transition-btn:hover {
  transform: translateY(-2px);
  opacity: 0.95;
}

/* Enforce strict column widths and prevent shifting */
.elegant-table :deep(table) {
  table-layout: fixed !important;
  width: 100% !important;
}

/* Ensure data truncates instead of stretching the column */
.elegant-table :deep(td) {
  padding: 24px 24px !important;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08) !important;
  cursor: pointer;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.elegant-table :deep(th) {
  font-size: 0.85rem !important;
  font-weight: 700 !important;
  color: #ffffff !important;
  padding: 0 24px !important;
  height: 56px !important;
  border-bottom: 2px solid rgba(var(--v-theme-on-surface), 0.12) !important;
  /* Fixed brand green, not the primary token: this header carries white text,
     and primary lightens to mint in the dark theme (white-on-mint ~2.2:1). */
  background-color: #0f4c3a !important;
  white-space: nowrap !important;
}

/* Hide scrollbar while keeping scroll functionality */
.elegant-table :deep(.v-table__wrapper) {
  scrollbar-width: none; /* Firefox */
  -ms-overflow-style: none; /* IE/Edge */
}

.elegant-table :deep(td:first-child) {
  text-overflow: clip !important;
  overflow: visible !important;
}  
</style>