<template>
  <v-container fluid class="fill-height align-start pa-6 bg-background">
    <v-row class="ma-0 w-100 align-stretch" :style="rowStyle">

      <v-col cols="12" :md="7" :lg="8" class="pa-0 h-100">
        <v-card elevation="3" rounded="lg" class="bg-surface w-100 h-100 d-flex flex-column">

          <div class="px-6 py-3 border-b d-flex flex-wrap align-center justify-space-between gap-4 flex-shrink-0">
            <div>
              <h2 class="text-h5 font-weight-bold text-high-emphasis">User Management</h2>
              <div class="text-body-2 text-medium-emphasis">
                <strong class="text-high-emphasis">{{ filteredAndSortedResidents.length }}</strong>
                of {{ residents.length }} residents
              </div>
            </div>

            <div class="d-flex flex-wrap gap-3 align-center">
              <v-text-field
                v-model="search"
                prepend-inner-icon="mdi-magnify"
                label="Search residents"
                placeholder="Name or email"
                clearable
                variant="outlined"
                density="comfortable"
                hide-details
                rounded="lg"
                class="search-field"
              ></v-text-field>

              <v-select
                v-model="filters.status"
                :items="['All', 'Active', 'Deactivated']"
                label="Status"
                variant="outlined"
                density="comfortable"
                hide-details
                rounded="lg"
                class="status-field"
              ></v-select>

              <v-btn
                color="#0f4c3a"
                elevation="0"
                rounded="lg"
                height="48"
                class="px-5 text-none font-weight-bold text-white transition-btn"
                @click="openAddModal"
              >
                <v-icon start>mdi-plus</v-icon> Add User
              </v-btn>
            </div>
          </div>

          <div class="px-6 py-2 border-b subtle-surface d-flex align-center gap-2 overflow-x-auto flex-shrink-0">
            <v-btn
              variant="text"
              :class="['tab-btn text-none px-4 rounded-0', filters.barangay === 'All' ? 'active-tab font-weight-black' : 'text-medium-emphasis font-weight-bold']"
              @click="filters.barangay = 'All'"
            >
              All Barangays
            </v-btn>
            <v-btn
              v-for="b in barangays"
              :key="b.barangay_id"
              variant="text"
              :class="['tab-btn text-none px-4 rounded-0', filters.barangay === b.barangay_name ? 'active-tab font-weight-black' : 'text-medium-emphasis font-weight-bold']"
              @click="filters.barangay = b.barangay_name"
            >
              {{ b.barangay_name }}
            </v-btn>
          </div>

          <!-- Error -->
          <v-alert
            v-if="apiError"
            type="error"
            variant="tonal"
            density="comfortable"
            rounded="0"
            class="flex-shrink-0"
          >
            {{ apiError }}
            <template v-slot:append>
              <v-btn variant="text" class="text-none font-weight-bold" @click="loadAll">Retry</v-btn>
            </template>
          </v-alert>

          <!-- Loading -->
          <div v-if="initialLoad" class="pa-6 flex-grow-1">
            <v-skeleton-loader v-for="n in 8" :key="n" type="list-item-avatar-two-line" class="mb-1"></v-skeleton-loader>
          </div>

          <!-- Empty -->
          <div v-else-if="!filteredAndSortedResidents.length" class="empty-state flex-grow-1">
            <v-icon size="56" class="text-medium-emphasis mb-4">mdi-account-off-outline</v-icon>
            <div class="text-h6 font-weight-bold text-high-emphasis mb-1">
              {{ residents.length ? 'No residents match your filters' : 'No residents registered yet' }}
            </div>
            <div class="text-body-1 text-medium-emphasis mb-5">
              {{ residents.length
                ? 'Try a different keyword, status, or barangay.'
                : 'Add the first resident account to get started.' }}
            </div>
            <v-btn
              v-if="residents.length"
              color="primary"
              variant="flat"
              rounded="lg"
              height="48"
              class="px-6 text-none font-weight-bold"
              @click="clearFilters"
            >
              Clear filters
            </v-btn>
          </div>

          <v-data-table
            v-else
            :headers="headers"
            :items="filteredAndSortedResidents"
            :items-per-page="-1"
            fixed-header
            :height="tableHeight"
            hover
            class="elegant-table flex-grow-1"
            item-value="resident_id"
            @click:row="selectRow"
            :row-props="rowProps"
          >
            <template v-slot:bottom></template>

            <template v-slot:item.photo="{ item }">
              <v-avatar :color="undefined" size="42" class="my-2 avatar-tint">
                <v-img v-if="item.photo" :src="item.photo" :alt="`Photo of ${item.first_name} ${item.last_name}`"></v-img>
                <span v-else class="avatar-initials">
                  {{ initials(item) }}
                </span>
              </v-avatar>
            </template>

            <template v-slot:item.fullName="{ item }">
              <v-tooltip :text="fullName(item)" location="top">
                <template v-slot:activator="{ props }">
                  <div v-bind="props" class="font-weight-bold text-high-emphasis text-body-1 cell-truncate">
                    {{ fullName(item) }}
                  </div>
                </template>
              </v-tooltip>
            </template>

            <template v-slot:item.barangay_name="{ item }">
              <span class="font-weight-medium text-body-1 text-high-emphasis cell-truncate">
                {{ barangayOf(item) }}
              </span>
            </template>

            <template v-slot:item.phone_number="{ item }">
              <span class="text-body-1 text-medium-emphasis cell-truncate">{{ item.phone_number }}</span>
            </template>

            <template v-slot:item.email_address="{ item }">
              <v-tooltip :text="item.email_address" location="top">
                <template v-slot:activator="{ props }">
                  <span v-bind="props" class="text-body-1 text-medium-emphasis cell-truncate">
                    {{ item.email_address }}
                  </span>
                </template>
              </v-tooltip>
            </template>

            <template v-slot:item.status="{ item }">
              <!-- Custom pill rather than a Vuetify chip: the flat grey chip Vuetify
                   renders for "Deactivated" pairs white on #9E9E9E (2.68:1) in both
                   themes. These tint the surface token instead, so both states pass
                   AA in light and dark. -->
              <span class="status-pill" :class="item.status === 'Active' ? 'pill-active' : 'pill-inactive'">
                <span class="status-dot" :class="item.status === 'Active' ? 'dot-active' : 'dot-inactive'"></span>
                {{ item.status }}
              </span>
            </template>
          </v-data-table>
        </v-card>
      </v-col>

      <!-- Detail panel — desktop -->
      <v-col v-if="showSidePanel" cols="12" md="5" lg="4" class="pa-0 pl-md-4 h-100">
        <transition name="slide-fade" mode="out-in">
          <v-card
            v-if="selectedResident"
            key="profile"
            elevation="3"
            rounded="lg"
            class="bg-surface h-100 d-flex flex-column position-relative"
          >
            <ResidentDetailPanel
              :resident="selectedResident"
              :status-loading="statusToggleLoading"
              @close="selectedResident = null"
              @edit="openExistingEditModal"
              @toggle-status="toggleStatus"
              @delete="askDelete"
            />
          </v-card>

          <v-card
            v-else
            key="placeholder"
            elevation="3"
            rounded="lg"
            class="bg-surface h-100 d-flex flex-column align-center justify-center pa-6 text-center"
          >
            <v-icon size="64" class="mb-4 text-medium-emphasis">mdi-account-search</v-icon>
            <h3 class="text-h6 font-weight-bold text-high-emphasis">No resident selected</h3>
            <p class="text-body-1 text-medium-emphasis mt-2">
              Select a row in the table to see the full profile here.
            </p>
            <p class="text-body-2 text-medium-emphasis mt-4">
              Tip: use <kbd class="kbd">↑</kbd> <kbd class="kbd">↓</kbd> to move between rows and
              <kbd class="kbd">Enter</kbd> to open one.
            </p>
          </v-card>
        </transition>
      </v-col>
    </v-row>

    <!-- Detail panel — small screens, as a full-height sheet -->
    <v-dialog v-model="mobileSheet" fullscreen transition="dialog-bottom-transition">
      <v-card v-if="selectedResident" class="bg-surface d-flex flex-column">
        <ResidentDetailPanel
          :resident="selectedResident"
          :status-loading="statusToggleLoading"
          @close="selectedResident = null"
          @edit="openExistingEditModal"
          @toggle-status="toggleStatus"
          @delete="askDelete"
        />
      </v-card>
    </v-dialog>

    <!-- Add / Edit -->
    <v-dialog v-model="modal.isOpen" max-width="680" persistent>
      <v-card rounded="lg" elevation="10">
        <v-card-title class="d-flex justify-space-between align-center pa-6 border-b bg-surface">
          <span class="text-h6 font-weight-bold text-high-emphasis">
            {{ modal.isEditing ? 'Modify User Profile' : 'Add New User Account' }}
          </span>
          <v-btn icon="mdi-close" variant="text" size="small" aria-label="Close dialog" @click="closeModal"></v-btn>
        </v-card-title>

        <v-card-text class="pa-6">
          <v-alert v-if="modalError" type="error" variant="tonal" class="mb-6" density="comfortable" rounded="lg" role="alert">
            {{ modalError }}
          </v-alert>

          <v-form ref="form" @submit.prevent="saveUser">
            <v-row>
              <v-col cols="12" class="d-flex align-center gap-4 mb-2">
                <v-avatar size="70" class="avatar-tint">
                  <span class="avatar-initials text-h5">
                    {{ (formData.first_name?.charAt(0) || '?') }}{{ (formData.last_name?.charAt(0) || '') }}
                  </span>
                </v-avatar>
                <div>
                  <div class="text-subtitle-2 font-weight-bold text-high-emphasis mb-1">Profile Picture</div>
                  <v-btn variant="outlined" color="#0f4c3a" size="small" rounded="lg" class="text-none font-weight-bold">
                    Change Photo
                  </v-btn>
                </div>
              </v-col>

              <v-col cols="12" md="4">
                <v-text-field v-model="formData.first_name" label="First Name *" variant="outlined" density="comfortable" rounded="lg" autocomplete="given-name"></v-text-field>
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field v-model="formData.middle_name" label="Middle Name" variant="outlined" density="comfortable" rounded="lg" autocomplete="additional-name"></v-text-field>
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field v-model="formData.last_name" label="Last Name *" variant="outlined" density="comfortable" rounded="lg" autocomplete="family-name"></v-text-field>
              </v-col>

              <v-col cols="12" md="6">
                <v-text-field v-model="formData.phone_number" label="Phone Number *" type="tel" variant="outlined" density="comfortable" rounded="lg" autocomplete="tel"></v-text-field>
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field v-model="formData.email_address" label="Email Address *" type="email" variant="outlined" density="comfortable" rounded="lg" autocomplete="email"></v-text-field>
              </v-col>

              <v-col cols="12" md="6" v-if="!modal.isEditing">
                <v-text-field
                  v-model="formData.password"
                  label="Password *"
                  :type="showPassword ? 'text' : 'password'"
                  :append-inner-icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
                  hint="At least 8 characters, with upper and lower case and a number"
                  persistent-hint
                  variant="outlined"
                  density="comfortable"
                  rounded="lg"
                  autocomplete="new-password"
                  @click:append-inner="showPassword = !showPassword"
                ></v-text-field>
              </v-col>

              <v-col cols="12" :md="modal.isEditing ? 12 : 6">
                <v-select v-model="formData.barangay_id" :items="barangays" item-title="barangay_name" item-value="barangay_id" label="Barangay *" variant="outlined" density="comfortable" rounded="lg"></v-select>
              </v-col>

              <v-col cols="12">
                <div class="text-subtitle-2 font-weight-bold text-high-emphasis mb-2">Account Status</div>
                <v-radio-group v-model="formData.status" inline hide-details color="#0f4c3a">
                  <v-radio label="Active" value="Active"></v-radio>
                  <v-radio label="Deactivated" value="Deactivated"></v-radio>
                </v-radio-group>
              </v-col>
            </v-row>
          </v-form>
        </v-card-text>

        <v-card-actions class="pa-6 pt-0 d-flex justify-end gap-3 bg-surface">
          <v-btn variant="text" rounded="lg" height="48" class="px-4 text-none font-weight-bold" :disabled="loading" @click="closeModal">
            Cancel
          </v-btn>
          <v-btn
            color="#0f4c3a"
            variant="flat"
            rounded="lg"
            class="px-6 text-none font-weight-bold text-white"
            height="48"
            :loading="loading"
            @click="saveUser"
          >
            {{ modal.isEditing ? 'Save Changes' : 'Create Account' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete confirm -->
    <v-dialog v-model="deleteDialog.show" max-width="470">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">Delete resident account?</v-card-title>
        <v-card-text class="px-6 py-4 text-body-1 text-medium-emphasis">
          <strong class="text-high-emphasis">{{ deleteDialog.item ? fullName(deleteDialog.item) : '' }}</strong>
          will be permanently removed, along with their ability to sign in and file requests.
          This cannot be undone — deactivate the account instead if you only want to suspend access.
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="text" rounded="lg" height="48" class="text-none font-weight-bold" :disabled="deleteDialog.loading" @click="deleteDialog.show = false">
            Cancel
          </v-btn>
          <v-btn
            color="error"
            variant="flat"
            rounded="lg"
            height="48"
            class="px-6 text-none font-weight-bold"
            :loading="deleteDialog.loading"
            @click="confirmDelete"
          >
            Delete account
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="bottom right" rounded="lg">
      {{ snackbar.text }}
    </v-snackbar>
  </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useDisplay } from 'vuetify'
import { getToken } from '@/composables/authToken'
import { API_BASE } from '@/config/api'
import ResidentDetailPanel from '@/components/ResidentDetailPanel.vue'

const { mdAndUp } = useDisplay()

const headers = [
  { title: '', key: 'photo', sortable: false, align: 'center', width: '76px' },
  { title: 'Full Name', key: 'fullName', width: '26%' },
  { title: 'Barangay', key: 'barangay_name', width: '15%' },
  { title: 'Phone Number', key: 'phone_number', width: '18%' },
  { title: 'Email', key: 'email_address', width: '25%' },
  { title: 'Status', key: 'status', align: 'center', width: '16%' },
]

const residents = ref([])
const barangays = ref([])
const search = ref('')
const initialLoad = ref(true)
const loading = ref(false)
const apiError = ref('')
const modalError = ref('')
const showPassword = ref(false)

const selectedResident = ref(null)
const filters = ref({ status: 'All', barangay: 'All' })
const modal = ref({ isOpen: false, isEditing: false, targetId: null })
const deleteDialog = ref({ show: false, item: null, loading: false })
const snackbar = ref({ show: false, text: '', color: 'success' })
const statusToggleLoading = ref(false)

const formData = ref({
  first_name: '', middle_name: '', last_name: '', phone_number: '',
  email_address: '', password: '', barangay_id: null, status: 'Active',
})

const showSidePanel = computed(() => mdAndUp.value)
const mobileSheet = computed({
  get: () => !mdAndUp.value && Boolean(selectedResident.value),
  set: (v) => { if (!v) selectedResident.value = null },
})
const rowStyle = computed(() => (mdAndUp.value ? 'height: calc(100vh - 96px);' : ''))
const tableHeight = computed(() => (mdAndUp.value ? 'calc(100vh - 268px)' : '60vh'))

const idOf = (r) => r?.resident_id ?? r?.id
const fullName = (r) => [r.last_name, [r.first_name, r.middle_name].filter(Boolean).join(' ')].filter(Boolean).join(', ')
const initials = (r) => `${(r.first_name || '').charAt(0)}${(r.last_name || '').charAt(0)}`.toUpperCase()
const barangayOf = (r) => r.barangay?.barangay_name || r.barangay_name || 'N/A'

const notify = (text, color = 'success') => { snackbar.value = { show: true, text, color } }
const getHeaders = () => ({ Authorization: `Bearer ${getToken()}`, 'Content-Type': 'application/json', Accept: 'application/json' })

const filteredAndSortedResidents = computed(() => {
  let result = residents.value
  if (filters.value.status !== 'All') result = result.filter((r) => r.status === filters.value.status)
  if (filters.value.barangay !== 'All') result = result.filter((r) => barangayOf(r) === filters.value.barangay)
  if (search.value) {
    const q = search.value.toLowerCase()
    result = result.filter((r) =>
      `${r.first_name} ${r.last_name}`.toLowerCase().includes(q) ||
      (r.email_address || '').toLowerCase().includes(q) ||
      (r.phone_number || '').toLowerCase().includes(q),
    )
  }
  return [...result].sort((a, b) => `${a.last_name} ${a.first_name}`.localeCompare(`${b.last_name} ${b.first_name}`))
})

const clearFilters = () => {
  search.value = ''
  filters.value = { status: 'All', barangay: 'All' }
}

const selectRow = (event, { item }) => { selectedResident.value = item }

// Rows are focusable and respond to Enter/Space, so a resident can be opened
// without a mouse; arrows walk the list the way a native listbox would.
const onRowKeydown = (event, item) => {
  const row = event.currentTarget
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault()
    selectedResident.value = item
    return
  }
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    const next = event.key === 'ArrowDown' ? row.nextElementSibling : row.previousElementSibling
    if (next && next.tagName === 'TR') next.focus()
  }
}

const rowProps = ({ item }) => {
  const isSelected = selectedResident.value && idOf(item) === idOf(selectedResident.value)
  return {
    class: isSelected ? 'selected-row' : '',
    tabindex: 0,
    'aria-selected': isSelected ? 'true' : 'false',
    onKeydown: (e) => onRowKeydown(e, item),
  }
}

const fetchResidents = async () => {
  const res = await fetch(`${API_BASE}/residents`, { headers: getHeaders() })
  const data = await res.json()
  if (!res.ok) throw new Error(data.message || 'Failed to load residents')
  residents.value = data.data || data
  // Keep the open panel in step with the refreshed list. Residents are keyed
  // resident_id, never id — comparing on `id` silently left stale data on screen.
  if (selectedResident.value) {
    const id = idOf(selectedResident.value)
    selectedResident.value = residents.value.find((r) => idOf(r) === id) || null
  }
}

const fetchBarangays = async () => {
  const res = await fetch(`${API_BASE}/barangays`, { headers: getHeaders() })
  const data = await res.json()
  if (!res.ok) throw new Error(data.message || 'Failed to load barangays')
  barangays.value = data.data || data
}

const loadAll = async () => {
  apiError.value = ''
  try {
    await Promise.all([fetchBarangays(), fetchResidents()])
  } catch (error) {
    apiError.value = error.message
  } finally {
    initialLoad.value = false
  }
}

const openAddModal = () => {
  modalError.value = ''
  showPassword.value = false
  formData.value = {
    first_name: '', middle_name: '', last_name: '', phone_number: '',
    email_address: '', password: '', barangay_id: null, status: 'Active',
  }
  modal.value = { isOpen: true, isEditing: false, targetId: null }
}

const openExistingEditModal = (item) => {
  modalError.value = ''
  formData.value = {
    first_name: item.first_name,
    middle_name: item.middle_name,
    last_name: item.last_name,
    phone_number: item.phone_number,
    email_address: item.email_address,
    password: '',
    barangay_id: item.barangay_id,
    status: item.status,
  }
  modal.value = { isOpen: true, isEditing: true, targetId: idOf(item) }
}

const closeModal = () => { modal.value.isOpen = false }

const errorFrom = async (res) => {
  const data = await res.json().catch(() => ({}))
  if (data.errors) return Object.values(data.errors).flat().join(' ')
  return data.message || 'Request failed'
}

const saveUser = async () => {
  loading.value = true
  modalError.value = ''
  const editing = modal.value.isEditing
  const payload = { ...formData.value }
  if (editing && !payload.password) delete payload.password
  try {
    const res = await fetch(
      editing ? `${API_BASE}/residents/${modal.value.targetId}` : `${API_BASE}/residents`,
      { method: editing ? 'PUT' : 'POST', headers: getHeaders(), body: JSON.stringify(payload) },
    )
    if (!res.ok) throw new Error(await errorFrom(res))
    await fetchResidents()
    closeModal()
    notify(editing ? 'Profile updated' : 'Account created')
  } catch (error) {
    modalError.value = error.message
  } finally {
    loading.value = false
  }
}

const toggleStatus = async (item) => {
  const next = item.status === 'Active' ? 'Deactivated' : 'Active'
  statusToggleLoading.value = true
  try {
    const res = await fetch(`${API_BASE}/residents/${idOf(item)}`, {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify({
        first_name: item.first_name,
        middle_name: item.middle_name,
        last_name: item.last_name,
        phone_number: item.phone_number,
        email_address: item.email_address,
        barangay_id: item.barangay_id,
        status: next,
      }),
    })
    if (!res.ok) throw new Error(await errorFrom(res))
    await fetchResidents()
    notify(next === 'Active' ? 'Account activated' : 'Account deactivated')
  } catch (error) {
    notify(error.message, 'error')
  } finally {
    statusToggleLoading.value = false
  }
}

const askDelete = (item) => { deleteDialog.value = { show: true, item, loading: false } }

const confirmDelete = async () => {
  const item = deleteDialog.value.item
  deleteDialog.value.loading = true
  try {
    const res = await fetch(`${API_BASE}/residents/${idOf(item)}`, { method: 'DELETE', headers: getHeaders() })
    if (!res.ok) throw new Error(await errorFrom(res))
    selectedResident.value = null
    await fetchResidents()
    deleteDialog.value.show = false
    notify('Account deleted')
  } catch (error) {
    notify(error.message, 'error')
  } finally {
    deleteDialog.value.loading = false
  }
}

onMounted(loadAll)
</script>

<style scoped>
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
.search-field { width: 260px; max-width: 100%; }
.status-field { width: 150px; max-width: 100%; }

.tab-btn {
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  border-bottom: 3px solid transparent;
}
/* Deep brand green reads well on the light surface (9.9:1) but vanishes on the
   dark one, so each theme gets the version that stays legible. The primary
   token alone is not enough: it is mint in dark (good) but only 5.15:1 on
   white, and the underline needs the heavier weight. */
.active-tab {
  border-bottom: 3px solid #0f4c3a !important;
  color: #0f4c3a !important;
}
.v-theme--dark .active-tab {
  border-bottom-color: rgb(var(--v-theme-primary)) !important;
  color: rgb(var(--v-theme-primary)) !important;
}

.transition-btn { transition: transform 0.2s ease, opacity 0.2s ease; }
.transition-btn:hover { transform: translateY(-2px); opacity: 0.95; }

/* Avatars — the old blue-on-light-blue pairing measured 3.28:1. Tinting the
   primary token instead keeps the same soft look and passes AA in both themes. */
.avatar-tint {
  background: rgba(var(--v-theme-primary), 0.14) !important;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
}
.avatar-initials {
  color: rgb(var(--v-theme-primary));
  font-weight: 800;
  letter-spacing: 0.02em;
}

/* Table */
.elegant-table :deep(table) { table-layout: fixed !important; width: 100% !important; }
.elegant-table :deep(td) {
  padding: 18px 24px !important;
  height: 76px !important;
  font-size: 0.95rem;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08) !important;
  cursor: pointer;
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
.cell-truncate {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Selection — previously these classes were applied but never styled, so the
   chosen row was indistinguishable from the rest. */
.elegant-table :deep(tr.selected-row) {
  background: rgba(var(--v-theme-primary), 0.1) !important;
  box-shadow: inset 4px 0 0 0 rgb(var(--v-theme-primary));
}
.elegant-table :deep(tr.selected-row td) { font-weight: 600; }
.elegant-table :deep(tbody tr:focus-visible) {
  outline: 3px solid rgb(var(--v-theme-primary));
  outline-offset: -3px;
}
.elegant-table :deep(.v-table__wrapper) {
  scrollbar-width: none;
  -ms-overflow-style: none;
}

/* Status pills — replace the flat grey chip (white on #9E9E9E, 2.68:1). */
.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 5px 12px;
  border-radius: 8px;
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  white-space: nowrap;
}
.pill-active { background: rgba(var(--v-theme-primary), 0.14); color: rgb(var(--v-theme-primary)); }
.pill-inactive {
  background: rgba(var(--v-theme-on-surface), 0.1);
  color: rgba(var(--v-theme-on-surface), 0.82);
}
.status-dot { width: 8px; height: 8px; border-radius: 50%; flex: none; }
.dot-active { background: rgb(var(--v-theme-primary)); }
.dot-inactive { background: rgba(var(--v-theme-on-surface), 0.5); }

.kbd {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 4px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.2);
  background: rgba(var(--v-theme-on-surface), 0.06);
  font-size: 0.78rem;
  font-weight: 700;
}

.empty-state {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center; padding: 64px 24px;
}

.slide-fade-enter-active, .slide-fade-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.slide-fade-enter-from, .slide-fade-leave-to { opacity: 0; transform: translateX(12px); }

@media (prefers-reduced-motion: reduce) {
  .tab-btn, .transition-btn, .slide-fade-enter-active, .slide-fade-leave-active { transition: none; }
  .transition-btn:hover { transform: none; }
}
</style>
