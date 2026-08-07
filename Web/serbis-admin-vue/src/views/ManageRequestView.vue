<template>
  <v-container fluid class="pa-5 fill-height overflow-hidden dashboard-bg">
    <div class="d-flex flex-column w-100 h-100">

      <!-- Toolbar -->
      <div class="d-flex justify-space-between align-center w-100 mb-3 flex-wrap gap-3">
        <div>
          <h2 class="text-h5 font-weight-bold" style="line-height: 1; margin-bottom: 4px;">Dispatch & Requests</h2>
          <div class="text-body-2 text-medium-emphasis" style="line-height: 1;">{{ requestCounts.All }} requests across all barangays</div>
        </div>
        <v-btn color="secondary" size="large" variant="flat" class="text-none font-weight-bold text-white px-8" height="40">
          <v-icon start size="small">mdi-download</v-icon> Export
        </v-btn>
      </div>

      <!-- Split view: list + detail panel -->
      <div class="d-flex flex-grow-1 gap-4 overflow-hidden" style="min-height: 0;">

        <!-- LEFT: request list -->
        <v-card elevation="0" rounded="xl" class="soft-card d-flex flex-column overflow-hidden" style="width: 400px; flex-shrink: 0;">
          <div class="pa-4 pb-2" style="flex-shrink: 0;">
            <v-text-field v-model="search" prepend-inner-icon="mdi-magnify" placeholder="Search resident, service..." variant="outlined" density="compact" hide-details class="mb-3"></v-text-field>

            <v-chip-group v-if="!initialLoad" column>
              <v-chip
                v-for="status in statusTabs" :key="status"
                size="small" class="font-weight-bold"
                :color="status === filters.status ? 'primary' : undefined"
                :variant="status === filters.status ? 'flat' : 'tonal'"
                @click="filters.status = status"
              >
                {{ status }} <span class="ml-1 font-weight-black">{{ requestCounts[status] }}</span>
              </v-chip>
            </v-chip-group>
            <v-skeleton-loader v-else type="chip" width="100%" height="32"></v-skeleton-loader>
          </div>

          <!-- Bulk action bar -->
          <div v-if="selectedIds.size > 0" class="d-flex align-center justify-space-between px-4 py-2 subtle-surface" style="flex-shrink: 0;">
            <span class="text-caption font-weight-bold">{{ selectedIds.size }} selected</span>
            <div class="d-flex gap-2">
              <v-btn size="small" variant="text" color="error" class="text-none font-weight-bold" :loading="bulkLoading" @click="bulkDisapprove">Disapprove</v-btn>
              <v-btn size="small" variant="text" class="text-none" @click="selectedIds.clear()">Clear</v-btn>
            </div>
          </div>

          <v-divider></v-divider>

          <div class="flex-grow-1 overflow-y-auto">
            <v-skeleton-loader v-if="initialLoad" type="list-item-avatar-two-line@6"></v-skeleton-loader>

            <div v-else-if="!pagedRequests.length" class="text-center text-caption text-medium-emphasis py-10">
              No requests match this filter
            </div>

            <div v-else>
              <div
                v-for="item in pagedRequests" :key="item.request_id || item.id"
                class="d-flex align-center px-4 py-3 request-row"
                :class="[`row-${(item.status || 'Pending').toLowerCase()}`, { 'row-selected': isSelected(item) }]"
                @click="selectRequest(item)"
              >
                <v-checkbox-btn
                  :model-value="selectedIds.has(itemId(item))"
                  class="mr-1 flex-shrink-0"
                  density="compact"
                  @click.stop="toggleSelect(item)"
                ></v-checkbox-btn>
                <v-avatar color="primary" variant="tonal" size="36" class="mr-3 flex-shrink-0">
                  <span class="font-weight-bold text-caption">
                    {{ item.resident?.first_name?.charAt(0) }}{{ item.resident?.last_name?.charAt(0) }}
                  </span>
                </v-avatar>
                <div class="flex-grow-1 min-width-0">
                  <div class="text-body-2 font-weight-bold text-truncate">{{ item.resident?.last_name }}, {{ item.resident?.first_name }}</div>
                  <div class="text-caption text-medium-emphasis text-truncate">{{ item.service?.service_name || 'N/A' }} &bull; {{ formatDate(item.created_at) }}</div>
                </div>
                <v-chip :color="getStatusColor(item.status)" size="x-small" variant="tonal" class="font-weight-bold ml-2 flex-shrink-0">{{ item.status || 'Pending' }}</v-chip>
              </div>
            </div>
          </div>

          <div class="d-flex justify-center pa-2" style="flex-shrink: 0;">
            <v-pagination v-model="page" :length="pageCount" :total-visible="4" density="compact" active-color="secondary"></v-pagination>
          </div>
        </v-card>

        <!-- RIGHT: detail panel -->
        <v-card elevation="0" rounded="xl" class="soft-card d-flex flex-column overflow-hidden flex-grow-1">
          <div v-if="!selectedRequest" class="d-flex flex-column align-center justify-center h-100 text-medium-emphasis">
            <v-icon size="48" class="mb-3">mdi-clipboard-text-outline</v-icon>
            <div class="text-body-1">Select a request to view details</div>
          </div>

          <template v-else>
            <div class="d-flex justify-space-between align-center pa-6 pb-4" style="flex-shrink: 0;">
              <div class="d-flex align-center gap-3">
                <v-avatar color="primary" variant="tonal" size="52">
                  <span class="text-h6 font-weight-black">
                    {{ selectedRequest.resident?.first_name?.charAt(0) }}{{ selectedRequest.resident?.last_name?.charAt(0) }}
                  </span>
                </v-avatar>
                <div>
                  <div class="text-h6 font-weight-bold" style="line-height: 1.2;">{{ selectedRequest.resident?.first_name }} {{ selectedRequest.resident?.last_name }}</div>
                  <div class="text-caption text-medium-emphasis">{{ selectedRequest.resident?.barangay?.barangay_name || 'Unknown Barangay' }}</div>
                </div>
              </div>
              <v-chip :color="getStatusColor(selectedRequest.status)" size="small" label class="text-uppercase font-weight-bold text-white">
                {{ selectedRequest.status || 'Pending' }}
              </v-chip>
            </div>

            <v-divider></v-divider>

            <div class="flex-grow-1 overflow-y-auto pa-6">
              <v-alert v-if="apiError" type="error" variant="tonal" class="mb-4" density="compact">{{ apiError }}</v-alert>

              <v-row class="mb-2">
                <v-col cols="12" sm="4">
                  <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Service</div>
                  <div class="font-weight-bold text-body-1">{{ selectedRequest.service?.service_name || 'N/A' }}</div>
                </v-col>
                <v-col cols="12" sm="4">
                  <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Submitted</div>
                  <div class="font-weight-medium text-body-2">{{ formatDateTime(selectedRequest.created_at) }}</div>
                </v-col>
                <v-col cols="12" sm="4">
                  <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Phone</div>
                  <div class="font-weight-medium text-body-2">{{ selectedRequest.resident?.phone_number || 'N/A' }}</div>
                </v-col>
              </v-row>

              <div class="mb-4">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-2">Description</div>
                <v-card variant="outlined" class="pa-4 text-body-2 rounded-lg subtle-surface" style="border-color: rgba(var(--v-theme-on-surface), 0.08);">
                  {{ selectedRequest.description || 'No description provided by resident.' }}
                </v-card>
              </div>

              <!-- The site photo comes first: it is what the resident is
                   reporting, and it is what decides whether a unit is sent.
                   The ID answers a different question, and answers it after. -->
              <div class="mb-4" v-if="selectedRequest.has_site_photo">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-2">Photo of the Site</div>
                <v-skeleton-loader v-if="sitePhoto.state.loading" type="image" height="200" class="rounded-lg"></v-skeleton-loader>
                <v-alert v-else-if="sitePhoto.state.error" type="error" variant="tonal" density="compact">{{ sitePhoto.state.error }}</v-alert>
                <v-img
                  v-else-if="sitePhoto.state.url"
                  :src="sitePhoto.state.url"
                  max-height="240"
                  class="subtle-surface rounded-lg border"
                  alt="Photo of the site, attached by the resident"
                ></v-img>
              </div>

              <div class="mb-4" v-if="selectedRequest.has_valid_id">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-2">Attached Evidence / Valid ID</div>
                <v-skeleton-loader v-if="validId.state.loading" type="image" height="200" class="rounded-lg"></v-skeleton-loader>
                <v-alert v-else-if="validId.state.error" type="error" variant="tonal" density="compact">{{ validId.state.error }}</v-alert>
                <v-img
                  v-else-if="validId.state.url"
                  :src="validId.state.url"
                  max-height="200"
                  class="subtle-surface rounded-lg border"
                  alt="Valid ID attached by the resident"
                ></v-img>
              </div>

              <v-divider class="mb-4"></v-divider>

              <div v-if="selectedRequest.status === 'Pending' || !selectedRequest.status">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-2">Dispatch Assignment</div>
                <v-card variant="outlined" class="pa-4 rounded-lg d-flex justify-space-between align-center" :class="formData.vehicle_id ? 'bg-success' + '-tint' : ''" style="border-color: rgba(var(--v-theme-on-surface), 0.08);">
                  <div v-if="formData.vehicle_id" class="d-flex align-center gap-3">
                    <v-avatar color="success" variant="tonal" size="40"><v-icon color="success">mdi-car</v-icon></v-avatar>
                    <div>
                      <div class="font-weight-bold">{{ getSelectedVehicleName() }}</div>
                      <div class="text-caption text-medium-emphasis">Selected for dispatch</div>
                    </div>
                  </div>
                  <div v-else class="text-body-2 text-medium-emphasis">No vehicle assigned yet.</div>

                  <v-btn color="secondary" variant="flat" size="small" class="text-none font-weight-bold text-white" @click="vehicleModal.isOpen = true">
                    {{ formData.vehicle_id ? 'Change Vehicle' : 'Select Vehicle' }}
                  </v-btn>
                </v-card>
              </div>

              <div v-else-if="selectedRequest.status === 'Responding'" class="mb-4">
                <v-alert type="info" variant="tonal" border="start" rounded="lg" class="d-flex align-center">
                  <template v-slot:prepend><v-icon size="28">mdi-car-emergency</v-icon></template>
                  <div class="text-subtitle-2 font-weight-bold">Currently Dispatched</div>
                  <div class="text-body-2">Vehicle {{ selectedRequest.vehicle?.plate_number || 'Unknown' }} ({{ selectedRequest.vehicle?.type || 'Unit' }})</div>
                </v-alert>
              </div>

              <v-textarea
                v-if="selectedRequest.status === 'Pending' || selectedRequest.status === 'Responding' || !selectedRequest.status"
                v-model="formData.remarks" label="Admin Remarks (Optional)" variant="outlined" density="comfortable" rounded="lg" rows="2" hide-details class="mt-4"
              ></v-textarea>
            </div>

            <v-divider v-if="showActions"></v-divider>
            <div v-if="showActions" class="d-flex justify-end pa-4 gap-3" style="flex-shrink: 0;">
              <template v-if="selectedRequest.status === 'Pending' || !selectedRequest.status">
                <v-btn color="error" variant="text" class="text-none font-weight-bold" height="40" :loading="loading" @click="updateStatus('Disapproved')">
                  Disapprove
                </v-btn>
                <v-btn color="secondary" variant="flat" class="text-none font-weight-bold text-white" height="40" :loading="loading" :disabled="!formData.vehicle_id" @click="updateStatus('Responding')">
                  Approve & Dispatch
                </v-btn>
              </template>
              <template v-else-if="selectedRequest.status === 'Responding'">
                <v-btn color="success" variant="flat" class="text-none font-weight-bold w-100" height="40" :loading="loading" @click="updateStatus('Resolved')">
                  Mark as Resolved
                </v-btn>
              </template>
            </div>
          </template>
        </v-card>
      </div>
    </div>

    <!-- Vehicle picker -->
    <v-dialog v-model="vehicleModal.isOpen" max-width="600">
      <v-card rounded="lg" elevation="6">
        <v-card-title class="pa-4 border-b d-flex justify-space-between align-center">
          <span class="text-h6 font-weight-bold">Available Vehicles</span>
          <v-btn icon="mdi-close" variant="text" density="comfortable" @click="vehicleModal.isOpen = false"></v-btn>
        </v-card-title>

        <v-card-text class="pa-4 subtle-surface" style="max-height: 400px; overflow-y: auto;">
          <v-row v-if="availableVehicles.length > 0">
            <v-col v-for="v in availableVehicles" :key="v.vehicle_id" cols="12" sm="6">
              <v-card
                hover rounded="lg" class="pa-4 cursor-pointer soft-card"
                :class="formData.vehicle_id === v.vehicle_id ? 'bg-success-tint' : ''"
                @click="selectVehicle(v.vehicle_id)"
              >
                <div class="d-flex align-center gap-3">
                  <v-avatar :color="formData.vehicle_id === v.vehicle_id ? 'success' : undefined" :variant="formData.vehicle_id === v.vehicle_id ? 'flat' : 'tonal'" size="48">
                    <v-icon :color="formData.vehicle_id === v.vehicle_id ? 'white' : undefined">mdi-car</v-icon>
                  </v-avatar>
                  <div>
                    <div class="font-weight-bold text-h6" style="line-height: 1.2;">{{ v.plate_number }}</div>
                    <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">{{ v.type }}</div>
                  </div>
                </div>
              </v-card>
            </v-col>
          </v-row>
          <div v-else class="pa-6 text-center text-medium-emphasis">
            <v-icon size="48" class="mb-3">mdi-car-off</v-icon>
            <div class="text-h6 font-weight-bold">No Vehicles Available</div>
            <div class="text-body-2">All fleet vehicles are currently dispatched or under maintenance.</div>
          </div>
        </v-card-text>
      </v-card>
    </v-dialog>
  </v-container>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue'
import { getToken } from '@/composables/authToken'
import { API_BASE } from '@/config/api'

const requests = ref([])
const vehicles = ref([])
const search = ref('')
const initialLoad = ref(true)
const loading = ref(false)
const bulkLoading = ref(false)
const apiError = ref('')
const page = ref(1)
const itemsPerPage = 10

const filters = reactive({ status: 'All' })
const vehicleModal = ref({ isOpen: false })
const selectedRequest = ref(null)
const selectedIds = reactive(new Set())

const formData = ref({ remarks: '', vehicle_id: null })

// Two attachments hang off a request now: the resident's ID and, optionally, a
// photo of the scene. Both live on the private disk and both are served only by
// an authenticated route, so both need the same fetch-as-a-blob treatment. One
// factory rather than a second hand-written copy — the copy is where the rule
// that every early return must release the previous blob gets forgotten.
const createAttachment = (segment, failureMessage) => {
  const state = reactive({ url: '', loading: false, error: '', for: null })

  const release = () => {
    if (state.url) URL.revokeObjectURL(state.url)
    state.url = ''
  }

  const load = async (item, present) => {
    const id = item ? itemId(item) : null
    if (id === state.for) return

    release()
    state.for = id
    state.error = ''
    if (!present) return

    state.loading = true
    try {
      const res = await fetch(`${API_BASE}/service-requests/${id}/${segment}`, { headers: getHeaders() })
      if (!res.ok) throw new Error(failureMessage)
      const blob = await res.blob()
      // The selection moved on while this was in flight; the blob belongs to a
      // request that is no longer on screen.
      if (state.for !== id) return
      state.url = URL.createObjectURL(blob)
    } catch (error) {
      if (state.for === id) state.error = error.message
    } finally {
      if (state.for === id) state.loading = false
    }
  }

  return { state, load, release }
}

const validId = createAttachment('valid-id', 'Could not load the attached ID.')
const sitePhoto = createAttachment('site-photo', 'Could not load the site photo.')

const statusTabs = ['All', 'Pending', 'Responding', 'Resolved', 'Disapproved', 'Cancelled']

const itemId = (item) => item.request_id || item.id

const requestCounts = computed(() => {
  const counts = { All: requests.value.length, Pending: 0, Responding: 0, Resolved: 0, Disapproved: 0, Cancelled: 0 }
  requests.value.forEach(req => {
    const status = req.status || 'Pending'
    if (counts[status] !== undefined) counts[status]++
  })
  return counts
})

const availableVehicles = computed(() => vehicles.value.filter(v => v.status === 'Available'))

const filteredAndSortedRequests = computed(() => {
  const searchLower = search.value.toLowerCase()
  const currentStatus = filters.status

  return requests.value.filter(r => {
    if (currentStatus !== 'All' && (r.status || 'Pending') !== currentStatus) return false

    if (!searchLower) return true
    const res = r.resident || {}
    return `${res.first_name} ${res.last_name}`.toLowerCase().includes(searchLower) ||
           (r.service?.service_name || '').toLowerCase().includes(searchLower) ||
           (res.barangay?.barangay_name || '').toLowerCase().includes(searchLower)
  }).sort((a, b) => {
    const statusA = a.status || 'Pending', statusB = b.status || 'Pending'
    if (statusA === 'Pending' && statusB !== 'Pending') return -1
    if (statusB === 'Pending' && statusA !== 'Pending') return 1
    return new Date(b.created_at) - new Date(a.created_at)
  })
})

const pageCount = computed(() => Math.max(1, Math.ceil(filteredAndSortedRequests.value.length / itemsPerPage)))

const pagedRequests = computed(() => {
  const start = (page.value - 1) * itemsPerPage
  return filteredAndSortedRequests.value.slice(start, start + itemsPerPage)
})

const showActions = computed(() =>
  selectedRequest.value && (selectedRequest.value.status === 'Pending' || !selectedRequest.value.status || selectedRequest.value.status === 'Responding')
)

const isSelected = (item) => selectedRequest.value && itemId(selectedRequest.value) === itemId(item)

const toggleSelect = (item) => {
  const id = itemId(item)
  if (selectedIds.has(id)) selectedIds.delete(id)
  else selectedIds.add(id)
}

const formatDate = (dateStr) => new Date(dateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
const formatDateTime = (dateStr) => new Date(dateStr).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })

const getStatusColor = (status) => {
  switch (status) {
    case 'Pending': return 'warning'
    case 'Responding': return 'info'
    case 'Resolved': return 'success'
    case 'Disapproved':
    case 'Cancelled': return 'error'
    default: return 'warning'
  }
}

const getHeaders = () => ({
  'Authorization': `Bearer ${getToken()}`,
  'Content-Type': 'application/json',
  'Accept': 'application/json'
})

// Neither storage path is serialized by the API — the model hides both and
// appends `has_valid_id` / `has_site_photo` instead, so these flags are the only
// way to know whether there is anything to fetch.
const loadAttachments = (item) => {
  validId.load(item, !!item?.has_valid_id)
  sitePhoto.load(item, !!item?.has_site_photo)
}

const releaseAttachments = () => {
  validId.release()
  sitePhoto.release()
}

const fetchData = async () => {
  try {
    const [reqRes, vehRes] = await Promise.all([
      fetch(`${API_BASE}/admin/service-requests`, { headers: getHeaders() }),
      fetch(`${API_BASE}/vehicles`, { headers: getHeaders() })
    ])
    const reqData = await reqRes.json()
    const vehData = await vehRes.json()
    requests.value = reqData.data || reqData
    vehicles.value = vehData.data || vehData

    if (!selectedRequest.value && requests.value.length) {
      selectRequest(pagedRequests.value[0] || filteredAndSortedRequests.value[0])
    } else if (selectedRequest.value) {
      // Keep the panel in sync with the freshly-fetched copy of the selected request
      const fresh = requests.value.find(r => itemId(r) === itemId(selectedRequest.value))
      if (fresh) selectRequest(fresh, false)
    }
  } catch (error) {
    console.error('Failed to fetch data:', error)
  } finally {
    initialLoad.value = false
  }
}

const selectRequest = (item, resetRemarks = true) => {
  if (!item) return
  apiError.value = ''
  selectedRequest.value = item
  formData.value = {
    remarks: resetRemarks ? (item.remarks || '') : formData.value.remarks,
    vehicle_id: item.vehicle_id || null
  }
  loadAttachments(item)
}

const selectVehicle = (id) => {
  formData.value.vehicle_id = id
  vehicleModal.value.isOpen = false
}

const getSelectedVehicleName = () => {
  const v = vehicles.value.find(veh => veh.vehicle_id === formData.value.vehicle_id)
  return v ? `${v.plate_number} (${v.type})` : ''
}

const updateStatus = async (newStatus, targetRequest = selectedRequest.value) => {
  loading.value = true
  apiError.value = ''
  const id = itemId(targetRequest)

  try {
    const res = await fetch(`${API_BASE}/service-requests/${id}`, {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify({
        status: newStatus,
        remarks: formData.value.remarks,
        vehicle_id: formData.value.vehicle_id || targetRequest.vehicle_id
      })
    })

    if (!res.ok) {
      const errData = await res.json()
      throw new Error(errData.message || 'Failed to update request')
    }

    // The vehicle's own status used to be flipped here, by a second request.
    // The server now owns it: PUT /service-requests/{id} attaches the unit and
    // moves it to Dispatched, and returns it to Available on a terminal status.
    // Doing it from here could only ever handle the dispatch half — nothing was
    // releasing the unit afterwards, so the fleet drained one vehicle at a time.
    await fetchData()
  } catch (error) {
    apiError.value = error.message
  } finally {
    loading.value = false
  }
}

const bulkDisapprove = async () => {
  bulkLoading.value = true
  const targets = requests.value.filter(r => selectedIds.has(itemId(r)))
  try {
    await Promise.all(targets.map(r => fetch(`${API_BASE}/service-requests/${itemId(r)}`, {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify({ status: 'Disapproved', remarks: r.remarks || '', vehicle_id: r.vehicle_id })
    })))
    selectedIds.clear()
    await fetchData()
  } catch (error) {
    apiError.value = 'Failed to update one or more requests'
  } finally {
    bulkLoading.value = false
  }
}

watch(() => filters.status, () => { page.value = 1 })
watch(search, () => { page.value = 1 })

onMounted(fetchData)
onUnmounted(releaseAttachments)
</script>

<style scoped>
.dashboard-bg {
  background-color: rgb(var(--v-theme-background));
}
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
.min-width-0 { min-width: 0; }

.soft-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  box-shadow: 0 1px 2px rgba(var(--v-theme-on-surface), 0.04), 0 4px 14px rgba(var(--v-theme-on-surface), 0.08);
}

.subtle-surface {
  background-color: rgba(var(--v-theme-on-surface), 0.05);
}

.bg-success-tint {
  background-color: rgba(var(--v-theme-success), 0.10) !important;
  border-color: rgba(var(--v-theme-success), 0.4) !important;
}

.cursor-pointer {
  cursor: pointer;
}

.request-row {
  cursor: pointer;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.06);
  border-left: 3px solid transparent;
  transition: background-color 150ms ease;
}
.request-row:hover {
  background-color: rgba(var(--v-theme-on-surface), 0.04);
}
.request-row.row-selected {
  background-color: rgba(var(--v-theme-primary), 0.08);
}
.request-row.row-pending { border-left-color: rgb(var(--v-theme-warning)); }
.request-row.row-responding { border-left-color: rgb(var(--v-theme-info)); }
.request-row.row-resolved { border-left-color: rgb(var(--v-theme-success)); }
.request-row.row-disapproved,
.request-row.row-cancelled { border-left-color: rgb(var(--v-theme-error)); }
</style>
