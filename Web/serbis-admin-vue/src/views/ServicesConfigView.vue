<template>
  <v-container fluid class="fill-height align-start pa-6 bg-background">
    <v-row class="ma-0 w-100">
      <v-col cols="12" class="pa-0 w-100">

        <!-- Header -->
        <div class="d-flex flex-wrap justify-space-between align-center gap-4 mb-6">
          <div>
            <h2 class="text-h4 font-weight-bold text-high-emphasis tracking-tight">Service Management</h2>
            <div class="text-subtitle-1 text-medium-emphasis">
              Every emergency and public service residents can request from the MDRRMO
            </div>
          </div>
          <v-btn
            color="primary"
            variant="flat"
            rounded="lg"
            height="52"
            class="px-6 text-none font-weight-bold text-body-1 btn-soft-shadow"
            @click="openAdd"
          >
            <v-icon start size="22">mdi-plus</v-icon> Add Service
          </v-btn>
        </div>

        <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6" density="comfortable" rounded="lg">
          {{ apiError }}
          <template v-slot:append>
            <v-btn variant="text" class="text-none font-weight-bold" @click="fetchServices">Retry</v-btn>
          </template>
        </v-alert>

        <v-card elevation="0" rounded="xl" class="table-card">

          <!-- Toolbar -->
          <div class="pa-5 pb-4">
            <div class="d-flex flex-wrap align-center gap-4">
              <v-text-field
                v-model="search"
                prepend-inner-icon="mdi-magnify"
                label="Search services"
                placeholder="Type a service name or keyword"
                clearable
                variant="outlined"
                density="comfortable"
                hide-details
                rounded="lg"
                class="search-field"
              ></v-text-field>

              <v-select
                v-model="categoryFilter"
                :items="categoryOptions"
                label="Category"
                prepend-inner-icon="mdi-filter-variant"
                variant="outlined"
                density="comfortable"
                hide-details
                rounded="lg"
                class="filter-field"
              ></v-select>

              <v-spacer class="d-none d-lg-block"></v-spacer>

              <div class="text-body-1 text-medium-emphasis">
                <strong class="text-high-emphasis">{{ filteredServices.length }}</strong>
                of {{ services.length }} services
              </div>
            </div>

            <div v-if="isFiltered" class="d-flex align-center gap-2 mt-4">
              <span class="text-body-2 text-medium-emphasis">Filters:</span>
              <v-chip
                v-if="search"
                closable
                size="small"
                variant="tonal"
                color="primary"
                @click:close="search = ''"
              >
                Search: {{ search }}
              </v-chip>
              <v-chip
                v-if="categoryFilter !== 'All'"
                closable
                size="small"
                variant="tonal"
                color="primary"
                @click:close="categoryFilter = 'All'"
              >
                {{ categoryFilter }}
              </v-chip>
              <v-btn variant="text" size="small" class="text-none font-weight-bold" @click="clearFilters">Clear all</v-btn>
            </div>
          </div>

          <v-divider></v-divider>

          <!-- Loading -->
          <div v-if="initialLoad" class="pa-5">
            <v-skeleton-loader v-for="n in 6" :key="n" type="list-item-two-line" class="mb-2"></v-skeleton-loader>
          </div>

          <!-- Empty -->
          <div v-else-if="!filteredServices.length" class="empty-state">
            <v-icon size="56" class="text-medium-emphasis mb-4">mdi-clipboard-list-outline</v-icon>
            <div class="text-h6 font-weight-bold text-high-emphasis mb-1">
              {{ services.length ? 'No services match your search' : 'No services configured yet' }}
            </div>
            <div class="text-body-1 text-medium-emphasis mb-5">
              {{ services.length
                ? 'Try a different keyword, or clear the filters to see the full list.'
                : 'Add the first service so residents have something to request.' }}
            </div>
            <v-btn
              v-if="services.length"
              variant="flat"
              color="primary"
              rounded="lg"
              height="48"
              class="px-6 text-none font-weight-bold"
              @click="clearFilters"
            >
              Clear filters
            </v-btn>
            <v-btn
              v-else
              color="primary"
              variant="flat"
              rounded="lg"
              height="48"
              class="px-6 text-none font-weight-bold"
              @click="openAdd"
            >
              <v-icon start size="22">mdi-plus</v-icon> Add Service
            </v-btn>
          </div>

          <!-- Table -->
          <v-data-table
            v-else
            :headers="headers"
            :items="filteredServices"
            v-model:page="page"
            v-model:items-per-page="itemsPerPage"
            :sort-by="sortBy"
            item-value="service_id"
            hover
            class="services-table"
            :items-per-page-options="[10, 25, 50, -1]"
            items-per-page-text="Rows per page"
          >
            <template v-slot:item.rowNumber="{ index }">
              <span class="row-number text-medium-emphasis">{{ rowNumber(index) }}</span>
            </template>

            <template v-slot:item.service_name="{ item }">
              <div class="d-flex align-center gap-3 py-2">
                <div class="icon-wrapper" :class="`iconbg-${category(item).key}`">
                  <v-icon :color="category(item).color" size="22">{{ serviceIcon(item.service_name) }}</v-icon>
                </div>
                <span class="text-body-1 font-weight-bold text-high-emphasis">{{ item.service_name }}</span>
              </div>
            </template>

            <template v-slot:item.description="{ item }">
              <span v-if="item.description" class="text-body-2 text-medium-emphasis description-cell">
                {{ item.description }}
              </span>
              <span v-else class="text-body-2 text-disabled font-italic">No description</span>
            </template>

            <template v-slot:item.category="{ item }">
              <span class="category-pill" :class="`pill-${category(item).key}`">
                <v-icon size="14" class="mr-1">{{ category(item).icon }}</v-icon>
                {{ category(item).label }}
              </span>
            </template>

            <template v-slot:item.created_at="{ item }">
              <span class="text-body-2 text-medium-emphasis">{{ addedOn(item) }}</span>
            </template>

            <template v-slot:item.actions="{ item }">
              <div class="d-flex justify-end gap-2">
                <v-btn
                  variant="tonal"
                  color="primary"
                  size="default"
                  height="40"
                  rounded="lg"
                  class="text-none font-weight-bold px-4"
                  @click="openEdit(item)"
                >
                  <v-icon start size="18">mdi-pencil-outline</v-icon> Edit
                </v-btn>
                <v-btn
                  variant="tonal"
                  color="error"
                  size="default"
                  height="40"
                  rounded="lg"
                  class="text-none font-weight-bold px-4"
                  @click="askDelete(item)"
                >
                  <v-icon start size="18">mdi-delete-outline</v-icon> Delete
                </v-btn>
              </div>
            </template>
          </v-data-table>
        </v-card>

      </v-col>
    </v-row>

    <!-- Add / Edit -->
    <v-dialog v-model="modal.show" max-width="560" persistent>
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="d-flex justify-space-between align-center pa-6 pb-2">
          <span class="text-h6 font-weight-bold text-high-emphasis">
            {{ modal.editing ? 'Edit service' : 'Add service' }}
          </span>
          <v-btn icon="mdi-close" variant="text" size="small" aria-label="Close dialog" @click="closeModal"></v-btn>
        </v-card-title>

        <v-card-text class="px-6 py-2">
          <v-alert v-if="modal.error" type="error" variant="tonal" density="comfortable" rounded="lg" class="mb-4" role="alert">
            {{ modal.error }}
          </v-alert>

          <v-text-field
            v-model="form.service_name"
            label="Service name *"
            placeholder="e.g. Flood Evacuation"
            hint="What residents will see when they file a request"
            persistent-hint
            counter="255"
            maxlength="255"
            variant="outlined"
            density="comfortable"
            rounded="lg"
            class="mb-4"
            :error-messages="nameError"
            @blur="touched.name = true"
          ></v-text-field>

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
          <v-btn variant="text" rounded="lg" height="48" class="text-none font-weight-bold" :disabled="modal.loading" @click="closeModal">
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
            {{ modal.editing ? 'Save changes' : 'Add service' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete confirm -->
    <v-dialog v-model="deleteDialog.show" max-width="460">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">Delete service?</v-card-title>
        <v-card-text class="px-6 py-4 text-body-1 text-medium-emphasis">
          <strong class="text-high-emphasis">{{ deleteDialog.item?.service_name }}</strong>
          will be removed from the list and residents will no longer be able to request it.
          Existing requests are kept. This cannot be undone.
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
            Delete
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
import { getToken } from '@/composables/authToken'
import { API_BASE } from '@/config/api'

const API = `${API_BASE}/services`

// Categories are derived from the service name — the API stores no category
// column. They group a long list and drive the filter; every row also shows the
// category as text, so nothing is conveyed by colour alone.
const categories = {
  rescue: { key: 'rescue', label: 'Rescue', color: 'error', icon: 'mdi-lifebuoy' },
  medical: { key: 'medical', label: 'Medical', color: 'info', icon: 'mdi-medical-bag' },
  relief: { key: 'relief', label: 'Relief', color: 'primary', icon: 'mdi-hand-heart-outline' },
  infrastructure: { key: 'infrastructure', label: 'Infrastructure', color: 'warning', icon: 'mdi-road-variant' },
}

const headers = [
  // Position in the list as it is currently sorted and filtered, not an id.
  // `service_id` is a database key with gaps in it, and showing that as "the
  // number of the service" would have people reading a deleted row into a gap.
  { title: '#', key: 'rowNumber', sortable: false, align: 'center', width: '64px' },
  { title: 'Service', key: 'service_name', minWidth: '260px' },
  { title: 'Description', key: 'description', sortable: false, minWidth: '280px' },
  { title: 'Category', key: 'category', value: (item) => categoryOf(item).label, width: '170px' },
  { title: 'Date added', key: 'created_at', width: '150px' },
  { title: 'Actions', key: 'actions', sortable: false, align: 'end', width: '230px' },
]

const services = ref([])
const search = ref('')
const categoryFilter = ref('All')
const sortBy = ref([{ key: 'service_name', order: 'asc' }])
const itemsPerPage = ref(10)
const page = ref(1)

// The slot's `index` counts within the visible page, so page 2 would otherwise
// restart at 1. "All" is -1, and there is only ever one page of it.
const rowNumber = (index) =>
  (itemsPerPage.value === -1 ? 0 : (page.value - 1) * itemsPerPage.value) + index + 1
const initialLoad = ref(true)
const apiError = ref('')

const modal = ref({ show: false, editing: false, loading: false, error: '', targetId: null })
const form = ref({ service_name: '', description: '' })
const touched = ref({ name: false })
const deleteDialog = ref({ show: false, item: null, loading: false })
const snackbar = ref({ show: false, text: '', color: 'success' })

const notify = (text, color = 'success') => { snackbar.value = { show: true, text, color } }
const getHeaders = () => ({ Authorization: `Bearer ${getToken()}`, 'Content-Type': 'application/json', Accept: 'application/json' })

const categoryOf = (item) => {
  const n = (item.service_name || '').toLowerCase()
  if (/(medical|ambulance|health|first aid)/.test(n)) return categories.medical
  if (/(rescue|evacuat|search|fire|sandbag)/.test(n)) return categories.rescue
  if (/(road|power|line|debris|clearing|repair|water|infrastructure)/.test(n)) return categories.infrastructure
  return categories.relief
}
const category = categoryOf

const categoryOptions = ['All', 'Rescue', 'Medical', 'Relief', 'Infrastructure']

const serviceIcon = (name) => {
  const n = (name || '').toLowerCase()
  if (n.includes('flood')) return 'mdi-home-flood'
  if (n.includes('fire')) return 'mdi-fire-truck'
  if (n.includes('ambulance') || n.includes('medical')) return 'mdi-ambulance'
  if (n.includes('relief') || n.includes('goods')) return 'mdi-package-variant-closed'
  if (n.includes('road') || n.includes('clearing')) return 'mdi-road-variant'
  if (n.includes('search')) return 'mdi-account-search-outline'
  if (n.includes('power') || n.includes('line')) return 'mdi-transmission-tower'
  if (n.includes('debris')) return 'mdi-shovel'
  if (n.includes('animal')) return 'mdi-paw'
  if (n.includes('sandbag')) return 'mdi-wall'
  if (n.includes('evacuat')) return 'mdi-exit-run'
  if (n.includes('water')) return 'mdi-water-pump'
  return 'mdi-lifebuoy'
}

const addedOn = (item) => {
  if (!item.created_at) return '—'
  const d = new Date(item.created_at)
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

const isFiltered = computed(() => Boolean(search.value) || categoryFilter.value !== 'All')

const filteredServices = computed(() => {
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

const nameError = computed(() =>
  touched.value.name && !form.value.service_name.trim() ? 'Service name is required.' : '',
)

const clearFilters = () => {
  search.value = ''
  categoryFilter.value = 'All'
}

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

const openAdd = () => {
  form.value = { service_name: '', description: '' }
  touched.value = { name: false }
  modal.value = { show: true, editing: false, loading: false, error: '', targetId: null }
}

const openEdit = (item) => {
  form.value = { service_name: item.service_name || '', description: item.description || '' }
  touched.value = { name: false }
  modal.value = { show: true, editing: true, loading: false, error: '', targetId: item.service_id || item.id }
}

const closeModal = () => { modal.value.show = false }

const saveService = async () => {
  touched.value.name = true
  if (!form.value.service_name.trim()) {
    modal.value.error = 'Service name is required.'
    return
  }
  modal.value.loading = true
  modal.value.error = ''
  const editing = modal.value.editing
  const payload = {
    service_name: form.value.service_name.trim(),
    description: form.value.description.trim() || null,
  }
  try {
    const res = await fetch(editing ? `${API}/${modal.value.targetId}` : API, {
      method: editing ? 'PUT' : 'POST',
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
    notify(editing ? 'Service updated' : 'Service added')
  } catch (error) {
    modal.value.error = error.message
  } finally {
    modal.value.loading = false
  }
}

const askDelete = (item) => { deleteDialog.value = { show: true, item, loading: false } }

const confirmDelete = async () => {
  const item = deleteDialog.value.item
  deleteDialog.value.loading = true
  try {
    const res = await fetch(`${API}/${item.service_id || item.id}`, { method: 'DELETE', headers: getHeaders() })
    if (!res.ok) throw new Error('Delete failed')
    await fetchServices()
    deleteDialog.value.show = false
    notify('Service deleted')
  } catch (error) {
    notify(error.message, 'error')
  } finally {
    deleteDialog.value.loading = false
  }
}

onMounted(fetchServices)
</script>

<style scoped>
.tracking-tight { letter-spacing: -0.02em; }
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }

.search-field { width: 340px; max-width: 100%; }
.filter-field { width: 220px; max-width: 100%; }

.btn-soft-shadow {
  box-shadow: 0 8px 16px -4px rgba(var(--v-theme-primary), 0.28) !important;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.btn-soft-shadow:hover {
  transform: translateY(-2px);
  box-shadow: 0 12px 20px -4px rgba(var(--v-theme-primary), 0.34) !important;
}

.table-card {
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08) !important;
  overflow: hidden;
}

/* Table — larger type and taller rows than Vuetify's default, so a long
   list stays readable at arm's length. */
.services-table :deep(th) {
  background: rgba(var(--v-theme-on-surface), 0.04) !important;
  font-size: 0.9rem !important;
  font-weight: 700 !important;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: rgba(var(--v-theme-on-surface), 0.75) !important;
  border-bottom: 2px solid rgba(var(--v-theme-on-surface), 0.12) !important;
  white-space: nowrap;
}
.services-table :deep(td) {
  height: 68px !important;
  font-size: 0.95rem;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.06) !important;
}
.services-table :deep(tbody tr:hover) {
  background: rgba(var(--v-theme-primary), 0.05) !important;
}
/* Tabular figures so the column stays a straight edge from 9 to 10. */
.row-number {
  font-size: 0.95rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.services-table :deep(tbody tr:focus-within) {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}
.services-table :deep(.v-data-table-footer) {
  font-size: 0.9rem;
  padding-block: 8px;
}

.description-cell {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  max-width: 460px;
}

.icon-wrapper {
  width: 40px; height: 40px; border-radius: 10px; flex: none;
  display: flex; align-items: center; justify-content: center;
}
.iconbg-rescue { background: rgba(var(--v-theme-error), 0.14); }
.iconbg-medical { background: rgba(var(--v-theme-info), 0.14); }
.iconbg-relief { background: rgba(var(--v-theme-primary), 0.12); }
.iconbg-infrastructure { background: rgba(var(--v-theme-warning), 0.16); }

.category-pill {
  display: inline-flex;
  align-items: center;
  padding: 4px 12px;
  border-radius: 8px;
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  white-space: nowrap;
}
.pill-rescue { background: rgba(var(--v-theme-error), 0.14); color: rgb(var(--v-theme-error)); }
.pill-medical { background: rgba(var(--v-theme-info), 0.14); color: rgb(var(--v-theme-info)); }
.pill-relief { background: rgba(var(--v-theme-primary), 0.12); color: rgb(var(--v-theme-primary)); }
.pill-infrastructure { background: rgba(var(--v-theme-warning), 0.18); color: rgb(var(--v-theme-warning)); }

.empty-state {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center; padding: 72px 16px;
}

@media (prefers-reduced-motion: reduce) {
  .btn-soft-shadow { transition: none; }
  .btn-soft-shadow:hover { transform: none; }
}
</style>
