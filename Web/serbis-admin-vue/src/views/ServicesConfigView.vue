<template>
  <v-container fluid class="fill-height align-start bg-background">
    <PageHeader title="Manage Services" />

    <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6 w-100" density="compact" rounded="lg">
      {{ apiError }}
      <template v-slot:append>
        <v-btn variant="outlined" color="primary" size="small" class="text-none font-weight-bold" @click="fetchServices">Retry</v-btn>
      </template>
    </v-alert>

    <div class="w-100">
      <DataTablePage
        compact
        collapse-mobile
        @click:row="(_event, { item }) => openEdit(item)"
        :tabs="statusTabs"
        :status="statusFilter"
        @update:status="statusFilter = $event"
        :loading="initialLoad"
        v-model:search="search"
        search-placeholder="Search name or description"
        :headers="headers"
        :items="filteredServices"
        item-value="service_id"
        :sort-by="sortBy"
        :no-data-text="services.length > 0 ? 'No services match your filters' : 'No services yet'"
        :page="page"
        @update:page="page = $event"
        :items-per-page="itemsPerPage"
        @update:items-per-page="itemsPerPage = $event"
        result-noun="services"
        :active-filters="activeFilters"
        @clear-filter="clearFilter"
        @clear-all="clearFilter"
      >
        <template v-slot:filters>
          <v-select
            v-model="categoryFilter"
            :items="categoryOptions"
            label="Category"
            variant="outlined" density="compact" hide-details rounded="lg"
          ></v-select>
        </template>

        <template v-slot:item.service_name="{ item }">
          <div class="service-cell">
            <div class="service-name font-weight-bold" :class="item.is_active ? 'text-high-emphasis' : 'text-medium-emphasis'">
              {{ item.service_name }}
            </div>
            <div v-if="item.description" class="service-desc text-body-2 text-medium-emphasis" :title="item.description">
              {{ item.description }}
            </div>
            <div v-else class="service-desc text-body-2 text-medium-emphasis font-italic">No description</div>
          </div>
        </template>

        <template v-slot:item.category="{ item }">
          <span class="text-body-2 text-medium-emphasis">{{ categoryOf(item).label }}</span>
        </template>

        <template v-slot:item.status="{ item }">
          <StatusChip :status="item.is_active ? 'Active' : 'Deactivated'" :label="statusLabel(item)" />
        </template>

        <template v-slot:item.actions="{ item }">
          <RowActions
            :label="item.service_name"
            :deletable="false"
            :extra="toggleExtra(item)"
            @edit="openEdit(item)"
            @extra="askToggle(item)"
          />
        </template>
      </DataTablePage>
    </div>

    <!-- Edit -->
    <v-dialog v-model="modal.show" max-width="560" persistent>
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="d-flex justify-space-between align-center pa-6 pb-2">
          <div>
            <div class="text-h6 font-weight-bold text-high-emphasis">Edit service</div>
            <div v-if="modal.addedOn" class="text-body-2 text-medium-emphasis">Added {{ modal.addedOn }}</div>
          </div>
          <v-btn icon="mdi-close" variant="tonal" rounded="circle" size="small" aria-label="Close dialog" @click="closeModal"></v-btn>
        </v-card-title>

        <v-card-text class="px-6 py-2">
          <v-alert v-if="modal.error" type="error" variant="tonal" density="comfortable" rounded="lg" class="mb-4" role="alert">
            {{ modal.error }}
          </v-alert>

          <!-- Fixed once a service exists (ServiceController::update refuses a
               change): the app names services from its own translations, and
               the category drives dispatch and vehicle mapping. -->
          <dl class="service-facts mb-2">
            <dt class="text-body-2 text-medium-emphasis">Service</dt>
            <dd class="text-body-1 font-weight-bold text-high-emphasis">{{ form.service_name }}</dd>
            <dt class="text-body-2 text-medium-emphasis">Category</dt>
            <dd class="text-body-1 text-high-emphasis">{{ categories[form.category]?.label ?? form.category }}</dd>
          </dl>
          <p class="text-body-2 text-medium-emphasis mb-4">The name and category are fixed; only the description can be changed.</p>

          <v-textarea
            v-model="form.description"
            label="Description"
            placeholder="When this service applies and what the MDRRMO provides"
            hint="Optional, but it helps residents pick the right service"
            persistent-hint
            rows="3"
            auto-grow
            variant="outlined"
            density="comfortable"
            rounded="lg"
          ></v-textarea>
        </v-card-text>

        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="outlined" color="primary" rounded="lg" height="48" class="text-none font-weight-bold" :disabled="modal.loading" @click="closeModal">
            Cancel
          </v-btn>
          <v-btn
            color="primary"
            variant="flat"
            rounded="lg"
            height="48"
            class="px-6 text-none font-weight-bold"
            :loading="modal.loading"
            @click="saveService"
          >
            Save changes
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Disable confirm. Enabling needs none: it only makes a service requestable again. -->
    <v-dialog v-model="disableDialog.show" max-width="420">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">Disable service?</v-card-title>
        <v-card-text class="px-6 py-4 text-body-2 text-medium-emphasis">
          <strong class="text-high-emphasis">{{ disableDialog.item?.service_name }}</strong> will be hidden from residents, and new requests for it will be refused. You can enable it again at any time.
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="outlined" color="primary" rounded="lg" class="text-none" :disabled="togglingId !== null" @click="disableDialog.show = false">Cancel</v-btn>
          <v-btn color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" :loading="togglingId !== null" @click="confirmDisable">Disable service</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="bottom right" rounded="lg">
      {{ snackbar.text }}
    </v-snackbar>
  </v-container>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { getToken } from '@/composables/authToken'
import { API_BASE } from '@/config/api'
import PageHeader from '@/components/PageHeader.vue'
import DataTablePage from '@/components/DataTablePage.vue'
import StatusChip from '@/components/StatusChip.vue'
import RowActions from '@/components/RowActions.vue'

const API = `${API_BASE}/services`

// A service's category is a column the office sets in the edit form. It groups
// a long list and drives the filter.
const categories = {
  rescue: { key: 'rescue', label: 'Rescue' },
  medical: { key: 'medical', label: 'Medical' },
  relief: { key: 'relief', label: 'Relief' },
  infrastructure: { key: 'infrastructure', label: 'Infrastructure' },
  // Trainings, drills and certification: things the office runs or issues,
  // not a response to an event.
  programs: { key: 'programs', label: 'Programs' },
}

// Read off the column, never the name. An unknown or missing value shows as Relief,
// the same fallback the API's column default uses.
const categoryOf = (item) => categories[item.category] ?? categories.relief
const categoryOptions = ['All', ...Object.values(categories).map((c) => c.label)]

const statusLabel = (item) => (item.is_active ? 'Active' : 'Disabled')

const HIDE_SM = { class: 'dtp-hide-sm' }
const headers = [
  { title: 'Service', key: 'service_name' },
  { title: 'Category', key: 'category', value: (item) => categoryOf(item).label, width: '180px', headerProps: HIDE_SM, cellProps: HIDE_SM },
  { title: 'Status', key: 'status', value: statusLabel, width: '140px' },
  { title: 'Actions', key: 'actions', sortable: false, align: 'end', width: '96px' },
]

const services = ref([])
const search = ref('')
const categoryFilter = ref('All')
const statusFilter = ref('All')
const sortBy = [{ key: 'service_name', order: 'asc' }]
const itemsPerPage = ref(10)
const page = ref(1)

const initialLoad = ref(true)
const apiError = ref('')

const modal = ref({ show: false, loading: false, error: '', targetId: null, addedOn: '' })
const form = ref({ service_name: '', description: '', category: 'relief' })
const togglingId = ref(null)
const disableDialog = ref({ show: false, item: null })
const snackbar = ref({ show: false, text: '', color: 'success' })

const notify = (text, color = 'success') => { snackbar.value = { show: true, text, color } }
const getHeaders = () => ({ Authorization: `Bearer ${getToken()}`, 'Content-Type': 'application/json', Accept: 'application/json' })
const idOf = (item) => item.service_id || item.id

const formatDate = (value) => {
  if (!value) return ''
  const d = new Date(value)
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

// Search and category narrow the rows first; the tabs then split that set by
// status, so each tab's count is what it would show.
const baseServices = computed(() => {
  const q = (search.value || '').trim().toLowerCase()
  return services.value.filter((s) => {
    const matchesSearch =
      !q ||
      (s.service_name || '').toLowerCase().includes(q) ||
      (s.description || '').toLowerCase().includes(q)
    const matchesCategory = categoryFilter.value === 'All' || categoryOf(s).label === categoryFilter.value
    return matchesSearch && matchesCategory
  })
})
const filteredServices = computed(() =>
  statusFilter.value === 'All' ? baseServices.value : baseServices.value.filter((s) => statusLabel(s) === statusFilter.value),
)
const statusTabs = computed(() => [
  { value: 'All', label: 'All', count: baseServices.value.length },
  ...['Active', 'Disabled'].map((s) => ({ value: s, label: s, count: baseServices.value.filter((r) => statusLabel(r) === s).length })),
])

// Status is the tabs, so it is not a chip here.
const activeFilters = computed(() => (categoryFilter.value === 'All' ? [] : [{ key: 'category', label: `Category: ${categoryFilter.value}` }]))
const clearFilter = () => { categoryFilter.value = 'All' }
watch([search, statusFilter, categoryFilter], () => { page.value = 1 })

const toggleExtra = (item) => ({
  label: item.is_active ? 'Disable' : 'Enable',
  icon: item.is_active ? 'mdi-eye-off-outline' : 'mdi-eye-outline',
  color: item.is_active ? undefined : 'primary',
  disabled: togglingId.value === idOf(item),
})

const fetchServices = async () => {
  apiError.value = ''
  try {
    const res = await fetch(API, { headers: getHeaders() })
    const data = await res.json()
    if (!res.ok) throw new Error(data.message || 'Failed to load services')
    services.value = Array.isArray(data) ? data : (data.data || [])
  } catch (error) {
    apiError.value = error.message
  } finally {
    initialLoad.value = false
  }
}

const openEdit = (item) => {
  form.value = { service_name: item.service_name || '', description: item.description || '', category: item.category || 'relief' }
  modal.value = { show: true, loading: false, error: '', targetId: idOf(item), addedOn: formatDate(item.created_at) }
}

const closeModal = () => { modal.value.show = false }

const saveService = async () => {
  modal.value.loading = true
  modal.value.error = ''
  // Description only: name and category are fixed once a service exists.
  const payload = { description: form.value.description.trim() || null }
  try {
    const res = await fetch(`${API}/${modal.value.targetId}`, {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify(payload),
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      const msg = data.errors ? Object.values(data.errors).flat().join(' ') : (data.message || 'Save failed')
      throw new Error(msg)
    }
    await fetchServices()
    modal.value.show = false
    notify('Service updated')
  } catch (error) {
    modal.value.error = error.message
  } finally {
    modal.value.loading = false
  }
}

const setActive = async (item, isActive) => {
  const id = idOf(item)
  togglingId.value = id
  try {
    const res = await fetch(`${API}/${id}`, {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify({ is_active: isActive }),
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(data.message || 'Failed to update the service')
    await fetchServices()
    notify(isActive ? 'Service enabled' : 'Service disabled')
    return true
  } catch (error) {
    notify(error.message, 'error')
    return false
  } finally {
    togglingId.value = null
  }
}

const askToggle = (item) => {
  if (item.is_active) {
    disableDialog.value = { show: true, item }
    return
  }
  setActive(item, true)
}

const confirmDisable = async () => {
  if (await setActive(disableDialog.value.item, false)) disableDialog.value.show = false
}

onMounted(fetchServices)
</script>

<style scoped>
.gap-3 { gap: 12px; }

.service-facts {
  display: grid;
  grid-template-columns: max-content 1fr;
  column-gap: 16px;
  row-gap: 4px;
  align-items: baseline;
}
.service-facts dd { margin: 0; }

/* Two lines inside the 48px compact row: the name, then the description
   clamped to one line with the full text on the cell's title. */
.service-cell { min-width: 0; line-height: 1.25; }
.service-name,
.service-desc {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.service-desc { font-size: 0.8125rem !important; }
</style>
