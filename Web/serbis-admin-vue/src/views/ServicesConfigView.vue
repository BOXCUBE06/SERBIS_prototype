<template>
  <v-container fluid class="fill-height align-start bg-background">
    <v-row class="ma-0 w-100">
      <v-col cols="12" class="pa-0 w-100">

        <PageHeader
          title="Manage Services"
        />

        <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6" density="comfortable" rounded="lg">
          {{ apiError }}
          <template v-slot:append>
            <v-btn variant="outlined" color="primary" class="text-none font-weight-bold" @click="fetchServices">Retry</v-btn>
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

              <div class="page-subtitle text-medium-emphasis">
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
              <v-btn variant="outlined" color="primary" size="small" class="text-none font-weight-bold" @click="clearFilters">Clear all</v-btn>
            </div>
          </div>

          <v-divider></v-divider>

          <!-- Empty -->
          <div v-if="!initialLoad && filteredServices.length === 0" class="empty-state">
            <v-icon size="56" class="text-medium-emphasis mb-4">mdi-clipboard-list-outline</v-icon>
            <div class="text-h6 font-weight-bold text-high-emphasis mb-1">
              {{ services.length > 0 ? 'No services match your search' : 'No services configured yet' }}
            </div>
            <div class="text-body-1 text-medium-emphasis mb-5">
              {{ services.length > 0
                ? 'Try a different keyword, or clear the filters to see the full list.'
                : 'Services are set up in the database directly — none exist yet.' }}
            </div>
            <v-btn
              v-if="services.length > 0"
              variant="flat"
              color="primary"
              rounded="lg"
              height="48"
              class="px-6 text-none font-weight-bold"
              @click="clearFilters"
            >
              Clear filters
            </v-btn>
          </div>

          <!-- Table -->
          <v-data-table
            v-else
            :key="initialLoad ? 'loading' : 'ready'"
            :headers="headers"
            :items="filteredServices"
            v-model:page="page"
            v-model:items-per-page="itemsPerPage"
            :sort-by="sortBy"
            item-value="service_id"
            hover
            class="services-table table-fade"
            :items-per-page-options="[10, 25, 50, -1]"
            items-per-page-text="Rows per page"
          >
            <template v-if="initialLoad" #body>
              <SkeletonRows :rows="itemsPerPage > 0 ? itemsPerPage : 10" :columns="headers.length" />
            </template>
            <template v-slot:item.rowNumber="{ index }">
              <span class="row-number text-medium-emphasis">{{ rowNumber(index) }}</span>
            </template>

            <template v-slot:item.service_name="{ item }">
              <div class="d-flex align-center gap-3 py-2">
                <div class="icon-wrapper" :class="`iconbg-${category(item).key}`">
                  <v-icon :color="item.is_active ? category(item).color : undefined" size="22">{{ serviceIcon(item.service_name) }}</v-icon>
                </div>
                <span class="text-body-1 font-weight-bold" :class="item.is_active ? 'text-high-emphasis' : 'text-disabled'">{{ item.service_name }}</span>
                <v-chip v-if="!item.is_active" size="x-small" variant="flat" color="error" class="text-none font-weight-bold">Inactive</v-chip>
              </div>
            </template>

            <template v-slot:item.description="{ item }">
              <span v-if="item.description" class="text-body-2 description-cell" :class="item.is_active ? 'text-medium-emphasis' : 'text-disabled'">
                {{ item.description }}
              </span>
              <span v-else class="text-body-2 text-disabled font-italic">No description</span>
            </template>

            <template v-slot:item.category="{ item }">
              <span class="category-pill" :class="[`pill-${category(item).key}`, { 'text-disabled': !item.is_active }]">
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
                  :color="item.is_active ? 'error' : 'success'"
                  size="default"
                  height="40"
                  rounded="lg"
                  class="text-none font-weight-bold px-4"
                  :loading="togglingId === (item.service_id || item.id)"
                  @click="toggleActive(item)"
                >
                  <v-icon start size="18">{{ item.is_active ? 'mdi-eye-off-outline' : 'mdi-eye-check-outline' }}</v-icon>
                  {{ item.is_active ? 'Disable' : 'Enable' }}
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
            Edit service
          </span>
          <v-btn icon="mdi-close" variant="tonal" rounded="circle" size="small" aria-label="Close dialog" @click="closeModal"></v-btn>
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

          <v-select
            v-model="form.category"
            :items="categoryChoices"
            label="Category *"
            hint="Groups this service in the list. Programs are approved without dispatching a unit."
            persistent-hint
            variant="outlined"
            density="comfortable"
            rounded="lg"
            class="mb-4"
          ></v-select>

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

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="bottom right" rounded="lg">
      {{ snackbar.text }}
    </v-snackbar>
  </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { getToken } from '@/composables/authToken'
import { useServerRowNumber } from '@/composables/rowNumber'
import { API_BASE } from '@/config/api'
import PageHeader from '@/components/PageHeader.vue'
import SkeletonRows from '@/components/SkeletonRows.vue'

const API = `${API_BASE}/services`

// A service's category is a column the office sets in the edit form. It groups
// a long list and drives the filter; every row also shows the category as
// text, so nothing is conveyed by colour alone.
const categories = {
  rescue: { key: 'rescue', label: 'Rescue', color: 'error', icon: 'mdi-lifebuoy' },
  medical: { key: 'medical', label: 'Medical', color: 'info', icon: 'mdi-medical-bag' },
  relief: { key: 'relief', label: 'Relief', color: 'primary', icon: 'mdi-hand-heart-outline' },
  infrastructure: { key: 'infrastructure', label: 'Infrastructure', color: 'warning', icon: 'mdi-road-variant' },
  // Trainings, drills and certification: things the office runs or issues,
  // not a response to an event.
  programs: { key: 'programs', label: 'Programs', color: 'success', icon: 'mdi-school-outline' },
}

const headers = [
  // Display position, not `service_id` and — deliberately, unlike every
  // other table's '#' column in this app — not useRowNumbers' stable
  // per-row identity either. That composable numbers a row by where it
  // sits in the *unsorted* source array, so sorting this table (default
  // sort is by Service, on load) produced a scrambled sequence like
  // 3, 9, 8, 2, 1, 7... reading as broken rather than as a stable id
  // (impeccable ui-audit, 2026-08-30). useServerRowNumber's page-offset
  // math is reused here even though this table paginates client-side —
  // it needs only the slot's own index, which is already relative to the
  // current sorted+paginated page.
  { title: '#', key: 'rowNumber', sortable: false, align: 'center', width: '64px' },
  { title: 'Service', key: 'service_name', width: '26%' },
  { title: 'Description', key: 'description', sortable: false, width: '28%' },
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

const initialLoad = ref(true)
const apiError = ref('')

const modal = ref({ show: false, loading: false, error: '', targetId: null })
const form = ref({ service_name: '', description: '', category: 'relief' })
const touched = ref({ name: false })
const togglingId = ref(null)
const snackbar = ref({ show: false, text: '', color: 'success' })

const notify = (text, color = 'success') => { snackbar.value = { show: true, text, color } }
const getHeaders = () => ({ Authorization: `Bearer ${getToken()}`, 'Content-Type': 'application/json', Accept: 'application/json' })

// Read off the column, never the name. An unknown or missing value shows as Relief,
// the same fallback the API's column default uses.
const categoryOf = (item) => categories[item.category] ?? categories.relief
const category = categoryOf

const categoryOptions = ['All', 'Rescue', 'Medical', 'Relief', 'Infrastructure', 'Programs']
// The edit form's dropdown: the same five, as value/label pairs.
const categoryChoices = Object.values(categories).map((c) => ({ title: c.label, value: c.key }))

const serviceIcon = (name) => {
  const n = (name || '').toLowerCase()
  if (n.includes('training') || n.includes('seminar')) return 'mdi-school-outline'
  if (n.includes('drill') || n.includes('nsed')) return 'mdi-alarm-light-outline'
  if (n.includes('certif')) return 'mdi-certificate-outline'
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

const rowNumber = useServerRowNumber(page, itemsPerPage)

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

const openEdit = (item) => {
  form.value = { service_name: item.service_name || '', description: item.description || '', category: item.category || 'relief' }
  touched.value = { name: false }
  modal.value = { show: true, loading: false, error: '', targetId: item.service_id || item.id }
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
  const payload = {
    service_name: form.value.service_name.trim(),
    description: form.value.description.trim() || null,
    category: form.value.category,
  }
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

const toggleActive = async (item) => {
  const id = item.service_id || item.id
  togglingId.value = id
  try {
    const res = await fetch(`${API}/${id}`, {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify({ is_active: !item.is_active }),
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(data.message || 'Failed to update the service')
    await fetchServices()
    notify(item.is_active ? 'Service disabled' : 'Service enabled')
  } catch (error) {
    notify(error.message, 'error')
  } finally {
    togglingId.value = null
  }
}

onMounted(fetchServices)
</script>

<style scoped>
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }

.search-field { width: 340px; max-width: 100%; }
.filter-field { width: 220px; max-width: 100%; }

.table-card {
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08) !important;
  overflow: hidden;
}

/* Table — larger type and taller rows than Vuetify's default, so a long
   list stays readable at arm's length. Fixed layout keeps the six columns
   at their declared widths; the two `minWidth`-only columns (Service,
   Description) became explicit percentages because fixed layout only
   reads `width` to size a column. */
.services-table :deep(table) { table-layout: fixed !important; width: 100% !important; min-width: 980px; }
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
.iconbg-programs { background: rgba(var(--v-theme-success), 0.14); }

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
/* Text uses the -strong tokens, not the plain ones: raw error/info/primary/
   warning on their own tint measures under AA (see plugins/vuetify.ts for
   the ratios) — same fix as UsersView's avatar initials and pill-pending. */
.pill-rescue { background: rgba(var(--v-theme-error), 0.14); color: rgb(var(--v-theme-error-strong)); }
.pill-medical { background: rgba(var(--v-theme-info), 0.14); color: rgb(var(--v-theme-info-strong)); }
.pill-relief { background: rgba(var(--v-theme-primary), 0.12); color: rgb(var(--v-theme-primary-strong)); }
.pill-infrastructure { background: rgba(var(--v-theme-warning), 0.18); color: rgb(var(--v-theme-warning-strong)); }
.pill-programs { background: rgba(var(--v-theme-success), 0.14); color: rgb(var(--v-theme-success-strong)); }

.empty-state {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center; padding: 72px 16px;
}


</style>
