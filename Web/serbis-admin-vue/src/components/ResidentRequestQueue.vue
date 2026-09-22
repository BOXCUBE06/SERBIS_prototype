<template>
  <v-container fluid class="dashboard-bg" :class="{ 'pa-0': !standalone }">
    <div class="d-flex flex-column w-100">

      <PageHeader v-if="standalone" title="Resident Requests" />

      <v-skeleton-loader v-if="initialLoad" type="table" class="rounded-lg"></v-skeleton-loader>

      <DataTablePage
        v-else
        v-model:search="search"
        search-placeholder="Search by name, service, barangay..."
        :tabs="statusTabItems"
        :status="filters.status"
        @update:status="filters.status = $event"
        :headers="tableHeaders"
        :items="filteredAndSortedRequests"
        item-value="request_id"
        :no-data-text="emptyListMessage"
        :page="page"
        @update:page="page = $event"
        :items-per-page="itemsPerPage"
        @update:items-per-page="itemsPerPage = $event"
        result-noun="requests"
        :row-props="(ctx) => ({
          class: [`row-${(ctx.item.status || 'Pending').toLowerCase()}`, isSelected(ctx.item) ? 'row-selected' : ''],
          role: 'button',
          tabindex: 0,
          'aria-current': isSelected(ctx.item) ? 'true' : undefined,
          'aria-label': `${ctx.item._requesterName}, ${ctx.item._secondary}, ${ctx.item.status || 'Pending'}`,
        })"
        :active-filters="activeFilters"
        @clear-filter="clearFilter"
        @clear-all="clearAllFilters"
        @click:row="(_event, { item }) => selectRequest(item)"
      >
        <template v-slot:filters>
          <RequestFiltersBar
            v-model:barangay="filters.barangay"
            v-model:unit="filters.unit"
            :barangay-options="barangayOptions"
            :unit-options="unitOptions"
          />
        </template>

        <template v-slot:actions>
          <v-btn
            color="primary"
            variant="text"
            class="text-none font-weight-bold"
            height="40"
            :disabled="filteredAndSortedRequests.length === 0"
            @click="exportCsv"
          >
            <v-icon start size="small">mdi-tray-arrow-down</v-icon>
            {{ filteredAndSortedRequests.length > 0 ? 'Export' : 'Nothing to export' }}
            <span v-if="filteredAndSortedRequests.length > 0" class="d-sr-only">{{ filteredAndSortedRequests.length }} requests as CSV</span>
          </v-btn>
          <v-btn
            color="secondary"
            variant="flat"
            class="text-none font-weight-bold text-white"
            height="40"
            @click="openCreateDialog"
          >
            <v-icon start size="small">mdi-account-plus-outline</v-icon>
            Log Service Request
          </v-btn>
        </template>

        <template v-if="selectedIds.size > 0" v-slot:before-table>
          <div class="d-flex align-center justify-space-between px-4 py-2 subtle-surface rounded-lg mb-3">
            <span class="text-caption font-weight-bold" aria-live="polite">{{ selectedIds.size }} selected</span>
            <div class="d-flex align-center gap-2">
              <v-btn size="small" height="36" variant="text" class="text-none" @click="selectedIds.clear()">Clear</v-btn>
              <v-btn
                color="error"
                variant="flat"
                size="small"
                height="36"
                class="text-none font-weight-bold"
                :loading="bulkLoading"
                @click="openReason('bulk')"
              >
                Disapprove {{ selectedIds.size }}
                <span class="d-sr-only">selected requests</span>
              </v-btn>
            </div>
          </div>
        </template>

        <template v-slot:item.select="{ item }">
          <v-checkbox-btn
            :model-value="selectedIds.has(itemId(item))"
            density="compact"
            :aria-label="`Select ${item._requesterName}'s request`"
            @click.stop="toggleSelect(item)"
          ></v-checkbox-btn>
        </template>

        <template v-slot:item.status="{ item }">
          <StatusPill small :status="outcomeLabel(item.status || 'Pending')" />
        </template>

        <template v-slot:item._requesterName="{ item }">
          <PersonCell
            :name="item._requesterName"
            :initials="requesterInitials(item)"
            :secondary="displayPhone(item.resident?.phone_number) || item.walk_in_contact_number || requesterBarangay(item)"
          />
        </template>

        <template v-slot:item._secondary="{ item }">
          <span class="text-medium-emphasis text-truncate d-block">{{ item._secondary }}</span>
        </template>

        <template v-slot:item._unit="{ item }">
          <span class="text-truncate d-block" :class="item._unit ? '' : 'text-medium-emphasis'">{{ item._unit || 'Unassigned' }}</span>
        </template>
      </DataTablePage>

      <v-dialog
        :model-value="!!selectedRequest"
        @update:model-value="(v) => { if (!v) selectedRequest = null }"
        max-width="720"
        class="detail-modal"
      >
        <v-card v-if="selectedRequest" rounded="lg" elevation="6" class="d-flex flex-column detail-modal-card">
          <div class="d-flex justify-space-between align-center pa-6 pb-4" style="flex-shrink: 0;">
            <div class="d-flex align-center gap-3 min-width-0">
              <v-avatar color="primary" variant="tonal" size="52" class="flex-shrink-0">
                <span class="text-h6 font-weight-black">
                  {{ requesterInitials(selectedRequest) }}
                </span>
              </v-avatar>
              <div class="min-width-0">
                <div class="text-h6 font-weight-bold text-truncate" style="line-height: 1.2;">{{ requesterName(selectedRequest) }}</div>
                <div class="text-caption text-medium-emphasis text-truncate">{{ requesterBarangay(selectedRequest) }}</div>
              </div>
            </div>
            <div class="d-flex align-center gap-2 flex-shrink-0">
              <StatusPill :status="outcomeLabel(selectedRequest.status || 'Pending')" />
              <v-btn icon="mdi-close" variant="text" density="comfortable" aria-label="Close" @click="selectedRequest = null"></v-btn>
            </div>
          </div>

          <v-divider></v-divider>

          <div class="pa-6 overflow-y-auto flex-grow-1">
            <v-alert v-if="apiError" type="error" variant="tonal" class="mb-4" density="compact">{{ apiError }}</v-alert>

            <v-alert
              v-if="pendingWaitLabel(selectedRequest.status, selectedRequest.created_at)"
              type="info"
              variant="tonal"
              class="mb-4"
              density="compact"
              :title="pendingWaitLabel(selectedRequest.status, selectedRequest.created_at)"
            >
              Filed {{ formatDateTime(selectedRequest.created_at) }}, no action taken yet.
            </v-alert>

            <h3 class="section-title">Action</h3>

            <div v-if="selectedRequest.status === 'Responding' && isProgramRequest(selectedRequest)" class="detail-group">
              <v-alert type="success" variant="tonal" border="start" rounded="lg">
                <div class="text-subtitle-2 font-weight-bold">Approved</div>
                <div class="text-body-2">Mark it resolved once the office has carried it out.</div>
              </v-alert>
            </div>

            <div v-else-if="selectedRequest.status === 'Responding'" class="detail-group">
              <v-alert type="info" variant="tonal" border="start" rounded="lg" class="d-flex align-center">
                <template v-slot:prepend><v-icon size="28">mdi-car-emergency</v-icon></template>
                <div class="text-subtitle-2 font-weight-bold">Currently Dispatched</div>
                <div v-if="selectedRequest.vehicle" class="text-body-2">
                  {{ vehicleName(selectedRequest.vehicle) }} ({{ selectedRequest.vehicle.type || 'Unit' }})
                </div>
                <div v-else class="text-body-2">No unit is recorded against this request.</div>
              </v-alert>
            </div>

            <div v-if="showActions" class="detail-group d-flex align-center flex-wrap gap-3">
              <template v-if="selectedRequest.status === 'Pending' || !selectedRequest.status">
                <div v-if="!isProgramRequest(selectedRequest)" class="d-flex align-center gap-3 min-width-0 mr-auto dispatch-state">
                  <v-avatar :color="formData.vehicle_id ? 'success' : undefined" variant="tonal" size="36">
                    <v-icon size="20" :color="formData.vehicle_id ? 'success' : undefined">
                      {{ formData.vehicle_id ? vehicleIcon(selectedVehicle?.type) : 'mdi-car-off' }}
                    </v-icon>
                  </v-avatar>
                  <div class="min-width-0">
                    <div class="text-body-2 font-weight-bold text-truncate">
                      {{ formData.vehicle_id ? getSelectedVehicleName() : 'No vehicle selected' }}
                    </div>
                    <div class="text-caption text-medium-emphasis text-truncate">
                      <template v-if="formData.vehicle_id">
                        Ready to dispatch<template v-if="selectedVehicle?.specification"> &bull; {{ selectedVehicle.specification }}</template>
                      </template>
                      <template v-else>Optional — approving without one sends no unit.</template>
                    </div>
                  </div>
                </div>

                <v-btn
                  v-if="!isProgramRequest(selectedRequest)"
                  color="primary"
                  variant="outlined"
                  class="text-none font-weight-bold"
                  height="40"
                  @click="vehicleModal.isOpen = true"
                >
                  {{ formData.vehicle_id ? 'Change Vehicle' : 'Select Vehicle' }}
                </v-btn>
                <v-btn color="error" variant="text" class="text-none font-weight-bold" :class="{ 'ml-auto': isProgramRequest(selectedRequest) }" height="40" :loading="loading" @click="openReason('disapprove')">
                  Disapprove
                </v-btn>
                <v-btn
                  color="secondary"
                  variant="flat"
                  class="text-none font-weight-bold text-white"
                  height="40"
                  :loading="loading"
                  @click="openReason('approve')"
                >
                  {{ isProgramRequest(selectedRequest) ? 'Approve' : 'Approve & Dispatch' }}
                </v-btn>
              </template>
              <template v-else-if="selectedRequest.status === 'Responding'">
                <v-btn color="success" variant="flat" class="text-none font-weight-bold w-100" height="40" :loading="loading" @click="openResolveConfirm">
                  Mark as Resolved
                </v-btn>
              </template>
            </div>

            <div v-if="!showActions && selectedRequest.status !== 'Responding'" class="text-caption text-medium-emphasis detail-group">
              This request is closed — no action needed.
            </div>

            <h3 class="section-title">Patient &amp; Requester</h3>

            <div class="detail-group">
              <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-2">Description</div>
              <v-card variant="outlined" class="pa-4 text-body-2 rounded-lg subtle-surface" style="border-color: rgba(var(--v-theme-on-surface), 0.08);">
                <template v-if="descriptionLines.length > 0">
                  <div v-for="(line, i) in descriptionLines" :key="i" class="description-line">{{ line }}</div>
                </template>
                <template v-else>No description provided by the Head of the Family.</template>
              </v-card>
            </div>

            <v-row class="detail-group">
              <v-col cols="12" sm="4">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Service</div>
                <div class="font-weight-bold text-body-1">{{ selectedRequest.service?.service_name || 'Other' }}</div>
              </v-col>
              <v-col cols="12" sm="4">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Submitted</div>
                <div class="font-weight-medium text-body-2">{{ formatDateTime(selectedRequest.created_at) }}</div>
              </v-col>
              <v-col cols="12" sm="4">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Phone</div>
                <div class="font-weight-medium text-body-2">{{ requesterPhone(selectedRequest) }}</div>
              </v-col>
              <v-col v-if="selectedRequest.preferred_date" cols="12" sm="4">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Preferred date</div>
                <div class="font-weight-medium text-body-2">{{ formatPreferredDate(selectedRequest.preferred_date) }}</div>
              </v-col>
            </v-row>

            <h3 class="section-title">Notes &amp; Attachments</h3>

            <div class="detail-group">
              <v-textarea
                v-model="formData.internal_notes" label="Internal note (staff only)" variant="outlined" density="comfortable" rounded="lg" rows="2"
                placeholder="e.g. Called twice, no answer — retrying after lunch"
                hint="Never shown to the requester — for staff reading this request later."
                persistent-hint
              ></v-textarea>
              <div class="d-flex align-center gap-3 mt-2">
                <v-btn
                  variant="outlined" color="primary" size="small" class="text-none font-weight-bold"
                  :loading="noteSaving"
                  @click="saveInternalNote"
                >Save note</v-btn>
                <span v-if="noteSaved" class="text-caption text-success">Saved</span>
              </div>
            </div>

            <div class="detail-group" v-if="attachments.length > 0">
              <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-2">Attachments</div>
              <div class="d-flex flex-wrap gap-3 attachments-row">
                <div v-for="a in attachments" :key="a.key">
                  <v-skeleton-loader
                    v-if="a.state.loading"
                    type="image"
                    height="140"
                    width="180"
                    class="rounded-lg"
                  ></v-skeleton-loader>
                  <v-alert
                    v-else-if="a.state.error"
                    type="error"
                    variant="tonal"
                    density="compact"
                    class="attachment-error"
                  >{{ a.state.error }}</v-alert>
                  <a
                    v-else-if="a.state.url && a.state.type === 'application/pdf'"
                    :href="a.state.url"
                    target="_blank"
                    rel="noopener"
                    class="attachment-tile attachment-tile--file rounded-lg"
                    :aria-label="`Open the ${a.label.toLowerCase()} (PDF) in a new tab`"
                  >
                    <v-icon size="40" color="primary">mdi-file-pdf-box</v-icon>
                    <span class="text-body-2 font-weight-bold mt-1">Open PDF</span>
                  </a>
                  <button
                    v-else-if="a.state.url"
                    type="button"
                    class="attachment-tile rounded-lg"
                    :aria-label="`View the ${a.label.toLowerCase()} full size`"
                    @click="openLightbox(a)"
                  >
                    <v-img :src="a.state.url" :alt="a.alt" cover height="140" width="180"></v-img>
                    <span class="attachment-badge" aria-hidden="true">
                      <v-icon size="16">mdi-magnify-plus-outline</v-icon>
                    </span>
                    <span class="attachment-scrim" aria-hidden="true">
                      <v-icon size="18">mdi-magnify-plus-outline</v-icon>
                      View
                    </span>
                  </button>
                  <div class="text-caption text-medium-emphasis mt-1">{{ a.label }}</div>
                </div>
              </div>
            </div>
          </div>
        </v-card>
      </v-dialog>
    </div>

    <v-dialog v-model="lightbox.open" max-width="900">
      <v-card rounded="lg" elevation="6">
        <v-card-title class="pa-4 border-b d-flex justify-space-between align-center">
          <span class="text-h6 font-weight-bold">{{ lightboxAttachment?.label }}</span>
          <v-btn icon="mdi-close" variant="text" density="comfortable" aria-label="Close" @click="lightbox.open = false"></v-btn>
        </v-card-title>
        <v-card-text class="pa-0 subtle-surface">
          <v-img
            v-if="lightboxAttachment?.state.url"
            :src="lightboxAttachment.state.url"
            :alt="lightboxAttachment.alt"
            max-height="70vh"
          ></v-img>
        </v-card-text>
      </v-card>
    </v-dialog>

    <v-dialog v-model="vehicleModal.isOpen" max-width="600">
      <v-card rounded="lg" elevation="6">
        <v-card-title class="pa-4 border-b d-flex justify-space-between align-center">
          <span class="text-h6 font-weight-bold">Available Vehicles</span>
          <v-btn icon="mdi-close" variant="text" density="comfortable" @click="vehicleModal.isOpen = false"></v-btn>
        </v-card-title>

        <v-card-text class="pa-0 subtle-surface" style="max-height: 400px; overflow-y: auto;">
          <v-list v-if="availableVehicles.length > 0" bg-color="transparent" class="py-0">
            <v-list-item
              v-for="v in availableVehicles"
              :key="v.vehicle_id"
              class="vehicle-option px-4 py-3"
              :active="formData.vehicle_id === v.vehicle_id"
              @click="selectVehicle(v.vehicle_id)"
            >
              <template v-slot:prepend>
                <v-avatar
                  :color="formData.vehicle_id === v.vehicle_id ? 'success' : undefined"
                  :variant="formData.vehicle_id === v.vehicle_id ? 'flat' : 'tonal'"
                  size="42"
                  class="mr-3"
                >
                  <v-icon :color="formData.vehicle_id === v.vehicle_id ? 'white' : undefined">{{ vehicleIcon(v.type) }}</v-icon>
                </v-avatar>
              </template>

              <v-list-item-title class="font-weight-bold text-body-1">{{ vehicleName(v) }}</v-list-item-title>
              <v-list-item-subtitle class="text-caption text-uppercase font-weight-bold">
                {{ v.type }}<template v-if="v.specification"> &bull; {{ v.specification }}</template>
              </v-list-item-subtitle>

              <template v-slot:append>
                <v-icon v-if="formData.vehicle_id === v.vehicle_id" color="success">mdi-check-circle</v-icon>
              </template>
            </v-list-item>
          </v-list>
          <div v-else class="pa-6 text-center text-medium-emphasis">
            <v-icon size="48" class="mb-3">mdi-car-off</v-icon>
            <div class="text-h6 font-weight-bold">No Vehicles Available</div>
            <div class="text-body-2">
              No free unit of a type this service uses. Units are either dispatched, under maintenance, or of a type set aside for other services.
            </div>
          </div>
        </v-card-text>
      </v-card>
    </v-dialog>

    <v-dialog v-model="reasonDialog.open" max-width="440" persistent @after-leave="clearReason">
      <v-card rounded="lg">
        <v-card-title class="d-flex justify-space-between align-center text-subtitle-1 font-weight-bold pa-5 pb-2 text-high-emphasis">
          <span>{{ reasonCopy.title }}</span>
          <v-btn
            icon="mdi-close" variant="text" size="small" aria-label="Close"
            :disabled="loading || bulkLoading" @click="reasonDialog.open = false"
          ></v-btn>
        </v-card-title>
        <v-card-text class="px-5 pt-2">
          <div class="text-body-2 text-medium-emphasis mb-4">{{ reasonCopy.body }}</div>

          <template v-if="reasonCopy.showField">
            <v-textarea
              v-if="reasonDialog.kind !== 'approve' || noteExpanded"
              v-model="reasonDialog.reason"
              :label="reasonCopy.label"
              :placeholder="reasonCopy.placeholder"
              :hint="reasonCopy.hint"
              persistent-hint
              variant="outlined"
              rows="3"
              counter="255"
              maxlength="255"
              autofocus
              :error-messages="reasonDialog.error"
              @update:model-value="reasonDialog.error = ''"
            ></v-textarea>
            <v-btn
              v-else
              variant="text"
              size="small"
              density="compact"
              class="text-none px-0"
              prepend-icon="mdi-plus"
              @click="noteExpanded = true"
            >
              Add a note
            </v-btn>
          </template>
          <div v-else class="text-caption text-medium-emphasis">
            No linked account — there is no mobile app to show a note in.
          </div>
        </v-card-text>
        <v-card-actions class="px-5 pb-5 pt-0 justify-end gap-3">
          <v-btn variant="text" class="text-none font-weight-bold" height="44" @click="reasonDialog.open = false">Cancel</v-btn>
          <v-btn
            :color="reasonDialog.kind === 'approve' ? 'secondary' : 'error'"
            variant="flat"
            class="px-6 text-none font-weight-bold"
            :class="reasonDialog.kind === 'approve' ? 'text-white' : ''"
            height="44"
            :loading="loading || bulkLoading"
            @click="confirmReason"
          >{{ reasonCopy.confirm }}</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="resolveDialog.open" max-width="440">
      <v-card rounded="lg">
        <v-card-title class="text-subtitle-1 font-weight-bold pa-5 pb-2 text-high-emphasis">
          Mark this request as resolved?
        </v-card-title>
        <v-card-text class="px-5 pt-2 text-body-2 text-medium-emphasis">
          <p class="mb-3">
            Resolving <strong class="text-high-emphasis">{{ resolveDialog.label }}</strong> closes it permanently.
            The status cannot be changed back from this panel.
          </p>
          <p class="mb-0">
            The trip log stays editable — return timestamps and odometer readings
            can still be filled in after this. It is the status that is permanent,
            not the record.
          </p>
        </v-card-text>
        <v-card-actions class="px-5 pb-5 pt-0 justify-end gap-3">
          <v-btn variant="text" class="text-none font-weight-bold" height="44" :disabled="loading" @click="resolveDialog.open = false">
            Cancel
          </v-btn>
          <v-btn
            color="success"
            variant="flat"
            class="px-6 text-none font-weight-bold"
            height="44"
            :loading="loading"
            @click="confirmResolve"
          >
            Resolve permanently
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="createDialog.open" max-width="640" scrollable persistent>
      <v-card rounded="lg">
        <v-card-title class="d-flex justify-space-between align-center pa-6 border-b bg-surface">
          <span class="text-h6 font-weight-bold">Log Service Request</span>
          <v-btn icon="mdi-close" variant="text" size="small" aria-label="Close" @click="createDialog.open = false"></v-btn>
        </v-card-title>
        <v-card-text class="pa-6" style="max-height: 70vh;">
          <v-alert v-if="createDialog.error" type="error" variant="tonal" density="compact" class="mb-4">{{ createDialog.error }}</v-alert>

          <v-btn-toggle
            v-model="createDialog.requesterType"
            color="primary"
            variant="outlined"
            mandatory
            divided
            class="mb-4 d-flex"
          >
            <v-btn value="resident" class="text-none flex-grow-1">Registered Head of the Family</v-btn>
            <v-btn value="walkin" class="text-none flex-grow-1">No account</v-btn>
          </v-btn-toggle>

          <v-autocomplete
            v-if="createDialog.requesterType === 'resident'"
            v-model="createDialog.form.resident_id"
            :items="residentOptions"
            label="Head of the Family"
            placeholder="Search by name"
            variant="outlined"
            density="comfortable"
            class="mb-2"
            :rules="[required]"
          ></v-autocomplete>

          <template v-else>
            <v-text-field
              v-model="createDialog.form.walk_in_name"
              label="Full name"
              placeholder="e.g. Juan Dela Cruz"
              variant="outlined"
              density="comfortable"
              class="mb-2"
              :rules="[required]"
            ></v-text-field>
            <v-text-field
              v-model="createDialog.form.walk_in_contact_number"
              label="Contact number"
              placeholder="e.g. 09171234567"
              variant="outlined"
              density="comfortable"
              class="mb-2"
              :rules="[required]"
            ></v-text-field>
          </template>

          <v-select
            v-model="createDialog.form.service_id"
            :items="serviceOptions"
            label="Service"
            variant="outlined"
            density="comfortable"
            class="mb-2"
            :rules="[required]"
          ></v-select>

          <v-textarea
            v-model="createDialog.form.description"
            label="Description"
            placeholder="e.g. Fallen tree blocking the road at Purok 3"
            variant="outlined"
            density="comfortable"
            rows="3"
            class="mb-2"
            :rules="[required]"
          ></v-textarea>
        </v-card-text>
        <v-card-actions class="pa-6 pt-0 d-flex justify-end gap-3 border-t">
          <v-btn variant="text" class="text-none font-weight-bold" height="44" @click="createDialog.open = false">Cancel</v-btn>
          <v-btn
            color="secondary"
            variant="flat"
            class="px-6 text-none font-weight-bold text-white"
            height="44"
            :loading="createDialog.loading"
            @click="submitWalkIn"
          >File request</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-container>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { getToken } from '@/composables/authToken'
import { displayPhone } from '@/composables/phoneNumber'
import { outcomeLabel, pendingWaitLabel } from '@/composables/adminUi'
import { API_BASE } from '@/config/api'
import PageHeader from '@/components/PageHeader.vue'
import DataTablePage from '@/components/DataTablePage.vue'
import StatusPill from '@/components/StatusPill.vue'
import PersonCell from '@/components/PersonCell.vue'
import RequestFiltersBar from '@/components/RequestFiltersBar.vue'
import { buildRequestsCsv, downloadCsv } from '@/composables/requestCsvExport'

defineProps({
  standalone: { type: Boolean, default: true },
})

const route = useRoute()

const requests = ref([])
const vehicles = ref([])
const residents = ref([])
const services = ref([])
const search = ref('')
const initialLoad = ref(true)
const loading = ref(false)
const bulkLoading = ref(false)
const apiError = ref('')
const page = ref(1)
const itemsPerPage = ref(10)

const AMBULANCE_SERVICE_CODE = 'ambulance-medical-response'
const isAmbulanceRequest = (r) => r.service?.code === AMBULANCE_SERVICE_CODE

const isProgramRequest = (r) => r?.service?.category === 'programs'

const filters = reactive({ status: 'All', barangay: 'All', unit: 'All' })
const vehicleModal = ref({ isOpen: false })
const selectedRequest = ref(null)
const selectedIds = reactive(new Set())

const formData = ref({ remarks: '', internal_notes: '', vehicle_id: null })
const noteSaving = ref(false)
const noteSaved = ref(false)
let noteSavedTimer = null

const required = (v) => (v !== null && v !== undefined && String(v).trim() !== '') || 'Required'

const emptyCreateForm = () => ({
  resident_id: null,
  walk_in_name: '',
  walk_in_contact_number: '',
  service_id: null,
  description: '',
})
const createDialog = ref({
  open: false,
  loading: false,
  error: '',
  requesterType: 'resident',
  form: emptyCreateForm(),
})

const openCreateDialog = () => {
  createDialog.value = {
    open: true,
    loading: false,
    error: '',
    requesterType: 'resident',
    form: emptyCreateForm(),
  }
}

const emptyReason = () => ({ open: false, kind: 'disapprove', reason: '', error: '' })
const reasonDialog = ref(emptyReason())

const noteExpanded = ref(false)

const resolveDialog = ref({ open: false, label: '' })

const openResolveConfirm = () => {
  const req = selectedRequest.value
  if (!req) return
  const who = requesterName(req)
  resolveDialog.value = {
    open: true,
    label: who && !who.startsWith('Unknown') ? `${who}'s request` : 'this request',
  }
}

const confirmResolve = async () => {
  await updateStatus('Resolved')
  if (!apiError.value) {
    resolveDialog.value.open = false
  }
}

const reasonCopy = computed(() => {
  const req = selectedRequest.value
  const who = req && requesterName(req) !== 'Unknown Head of the Family' && requesterName(req) !== 'Unknown requester' ? requesterName(req) : ''
  const what = selectedRequest.value?.service?.service_name || 'this service'
  const hasAccount = req ? !isWalkIn(req) : true
  const appHint = 'Shown to the Head of the Family in the mobile app.'
  const noAppHint = 'No linked account — kept as an internal record only, not shown to anyone.'
  switch (reasonDialog.value.kind) {
    case 'approve': {
      const unit = getSelectedVehicleName()
      const note = { label: 'Note for the Head of the Family (optional)', hint: appHint, showField: hasAccount }
      if (req && isProgramRequest(req)) {
        return {
          ...note,
          title: 'Approve this request',
          body: `This approves the request for ${what}. Nothing is dispatched.`,
          placeholder: 'e.g. We will confirm the schedule with your office',
          confirm: 'Approve',
        }
      }
      return unit
        ? {
            ...note,
            title: 'Approve and dispatch',
            body: `${unit} will be sent for ${what}.`,
            placeholder: 'e.g. Wait by the barangay hall, the unit is on its way',
            confirm: 'Approve & dispatch',
          }
        : {
            ...note,
            title: 'Approve without a vehicle',
            body: `This approves the request for ${what} and marks it Responding. No vehicle is assigned — cancel and use Select Vehicle first if one is going out.`,
            placeholder: 'e.g. Our team will visit your address this afternoon',
            confirm: 'Approve',
          }
    }
    case 'bulk':
      return {
        title: `Disapprove ${selectedIds.size} request${selectedIds.size === 1 ? '' : 's'}`,
        body: 'Every selected request is declined with this same reason.',
        label: 'Reason for declining',
        placeholder: 'e.g. No unit free for the requested window',
        hint: 'Shown to any Head of the Family in the selection with a linked account; kept as an internal record for a walk-in with none.',
        showField: true,
        confirm: 'Disapprove all',
      }
    default:
      return {
        title: 'Disapprove this request',
        body: who ? `${who} asked for ${what}.` : `A request for ${what}.`,
        label: 'Reason for declining',
        placeholder: 'e.g. No unit free for the requested window — file again for tomorrow',
        hint: hasAccount ? appHint : noAppHint,
        showField: true,
        confirm: 'Disapprove request',
      }
  }
})

const openReason = (kind) => {
  noteExpanded.value = false
  reasonDialog.value = {
    ...emptyReason(),
    open: true,
    kind,
    reason: '',
  }
}

const clearReason = () => { reasonDialog.value = emptyReason(); noteExpanded.value = false }

const confirmReason = () => {
  const { kind, reason } = reasonDialog.value
  const trimmed = reason.trim()
  if (kind !== 'approve' && !trimmed) {
    reasonDialog.value.error = 'Give a reason — the Head of the Family is shown this'
    return
  }
  if (kind === 'bulk') return bulkDisapprove(trimmed)
  formData.value.remarks = trimmed
  return updateStatus(kind === 'approve' ? 'Responding' : 'Disapproved')
}

const createAttachment = (segment, failureMessage) => {
  const state = reactive({ url: '', type: '', loading: false, error: '', for: null })

  const release = () => {
    if (state.url) URL.revokeObjectURL(state.url)
    state.url = ''
    state.type = ''
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
      if (state.for !== id) return
      state.type = blob.type
      state.url = URL.createObjectURL(blob)
    } catch (error) {
      if (state.for === id) state.error = error.message
    } finally {
      if (state.for === id) state.loading = false
    }
  }

  return { state, load, release }
}

const lightbox = ref({ open: false, key: null })

const formatPreferredDate = (value, month = 'long') => {
  const [y, m, d] = String(value).split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('en-PH', { year: 'numeric', month, day: 'numeric' })
}

const validId = createAttachment('valid-id', 'Could not load the attached ID.')
const sitePhoto = createAttachment('site-photo', 'Could not load the landmark photo.')
const letter = createAttachment('letter', 'Could not load the request letter.')

const statusTabs = ['All', 'Pending', 'Responding', 'Resolved', 'Disapproved', 'Cancelled']

const statusTabItems = computed(() =>
  statusTabs.map((status) => ({ value: status, label: status, count: requestCounts.value[status] })),
)

const barangayOptions = computed(() => {
  const names = new Set(requests.value.map(r => r.resident?.barangay?.barangay_name).filter(Boolean))
  return ['All', ...Array.from(names).sort()]
})

const unitOptions = computed(() => {
  const pool = vehicles.value.filter(v => v.type !== 'Ambulance')
  return ['All', 'Unassigned', ...pool.map(v => v.unit_identifier)]
})

const HEADER_WIDTH_TOTAL = 96
const tableHeaders = computed(() => {
  const columns = [
    { title: 'Status', key: 'status', width: 13 },
    { title: 'Requester', key: '_requesterName', width: 22 },
    { title: 'Service', key: '_secondary', width: 16 },
    { title: 'Unit', key: '_unit', width: 12 },
  ]
  const scale = HEADER_WIDTH_TOTAL / columns.reduce((sum, c) => sum + c.width, 0)
  return [
    { title: '', key: 'select', sortable: false, width: '48px' },
    ...columns.map(c => ({ ...c, width: `${Math.round(c.width * scale * 10) / 10}%` })),
  ]
})

if (statusTabs.includes(route.query.status)) filters.status = route.query.status

const itemId = (item) => item.request_id || item.id

const residentName = (resident) =>
  `${resident?.first_name || ''} ${resident?.last_name || ''}`.trim() || 'Unknown Head of the Family'

const isWalkIn = (item) => !item?.resident && !item?.resident_id
const requesterName = (item) =>
  item?.resident ? residentName(item.resident) : (item?.walk_in_name || 'Unknown requester')
const requesterInitials = (item) => {
  if (item?.resident) return `${item.resident.first_name?.charAt(0) || ''}${item.resident.last_name?.charAt(0) || ''}`
  const parts = (item?.walk_in_name || '').trim().split(/\s+/).filter(Boolean)
  return parts.length > 0 ? `${parts[0][0]}${parts[1]?.[0] || ''}`.toUpperCase() : 'W'
}
const requesterPhone = (item) => displayPhone(item?.resident?.phone_number) || item?.walk_in_contact_number || 'N/A'
const requesterBarangay = (item) => {
  if (item?.resident) return item.resident.barangay?.barangay_name || 'Unknown Barangay'
  return isWalkIn(item) ? 'Walk-in (no account)' : 'Unknown Barangay'
}

const requestCounts = computed(() => {
  const counts = { All: requests.value.length, Pending: 0, Booked: 0, Responding: 0, Resolved: 0, Disapproved: 0, Cancelled: 0 }
  requests.value.forEach(req => {
    const status = req.status || 'Pending'
    if (counts[status] !== undefined) counts[status]++
  })
  return counts
})

const availableVehicles = computed(() => {
  const allowed = vehicleTypesByService.value[selectedRequest.value?.service?.code] ?? []
  return vehicles.value.filter(v =>
    v.status === 'Available' && v.type !== 'Ambulance' && (allowed.length === 0 || allowed.includes(v.type)),
  )
})

const vehicleTypesByService = ref({})
const fetchVehicleTypes = async () => {
  try {
    const res = await fetch(`${API_BASE}/service-vehicle-types`, { headers: getHeaders(), signal: listAbortController.signal })
    if (!res.ok) return
    const body = await res.json()
    vehicleTypesByService.value = Object.fromEntries((body.data || []).map(row => [row.code, row.vehicle_types]))
  } catch (error) {
    if (error.name !== 'AbortError') console.error('Failed to fetch vehicle types:', error)
  }
}

const residentOptions = computed(() => residents.value
  .map(r => ({
    title: `${r.last_name}, ${r.first_name}${r.barangay?.barangay_name ? ' — ' + r.barangay.barangay_name : ''}`,
    value: r.resident_id,
  }))
  .sort((a, b) => a.title.localeCompare(b.title)))

const serviceOptions = computed(() => services.value
  .filter(s => s.code !== AMBULANCE_SERVICE_CODE && s.is_active !== false)
  .map(s => ({ title: s.service_name, value: s.service_id })))

const selectedVehicle = computed(() =>
  vehicles.value.find(v => v.vehicle_id === formData.value.vehicle_id) || null
)

const attachments = computed(() => {
  const req = selectedRequest.value
  if (!req) return []
  return [
    {
      key: 'site-photo',
      label: 'Landmark',
      present: !!req.has_site_photo,
      state: sitePhoto.state,
      alt: 'Landmark photo attached by the Head of the Family',
    },
    {
      key: 'letter',
      label: 'Request letter',
      present: !!req.has_letter,
      state: letter.state,
      alt: 'Request letter attached by the requesting barangay or organization',
    },
    {
      key: 'valid-id',
      label: 'Valid ID',
      present: !!req.has_valid_id,
      state: validId.state,
      alt: 'Valid ID attached by the Head of the Family',
    },
  ].filter(a => a.present)
})

const lightboxAttachment = computed(() =>
  attachments.value.find(a => a.key === lightbox.value.key) || null
)

const openLightbox = (a) => { lightbox.value = { open: true, key: a.key } }

const META_TAIL = /^(contact:|submitted\b)/i

const descriptionLines = computed(() => {
  const raw = selectedRequest.value?.description
  if (!raw) return []

  const lines = raw.split('\n').map(line => line.trim()).filter(Boolean)
  const service = (selectedRequest.value?.service?.service_name || '').trim().toLowerCase()

  const kept = [...lines]
  if (service && kept[0]?.toLowerCase() === service) kept.shift()

  while (kept.length > 0 && META_TAIL.test(kept.at(-1))) kept.pop()

  return kept.length > 0 ? kept : lines
})

const filteredAndSortedRequests = computed(() => {
  const searchLower = search.value.toLowerCase()
  const currentStatus = filters.status

  return requests.value.filter(r => {
    if (currentStatus !== 'All' && (r.status || 'Pending') !== currentStatus) return false

    if (filters.barangay !== 'All' && (r.resident?.barangay?.barangay_name || '') !== filters.barangay) return false

    if (filters.unit !== 'All') {
      const unit = r.vehicle?.unit_identifier || ''
      if (filters.unit === 'Unassigned' ? unit : unit !== filters.unit) return false
    }

    if (!searchLower) return true
    return requesterName(r).toLowerCase().includes(searchLower) ||
           (r.service?.service_name || '').toLowerCase().includes(searchLower) ||
           (r.resident?.barangay?.barangay_name || '').toLowerCase().includes(searchLower)
  }).map(r => ({
    ...r,
    _requesterName: requesterName(r),
    _secondary: r.service?.service_name || 'Other',
    _unit: r.vehicle?.unit_identifier || '',
  })).sort((a, b) => {
    const statusA = a.status || 'Pending', statusB = b.status || 'Pending'
    if (statusA === 'Pending' && statusB !== 'Pending') return -1
    if (statusB === 'Pending' && statusA !== 'Pending') return 1
    return new Date(b.created_at) - new Date(a.created_at)
  })
})

const emptyListMessage = computed(() => {
  if (search.value) return `No requests match "${search.value}"`
  if (filters.status !== 'All') return `No ${filters.status.toLowerCase()} requests`
  return 'No requests yet'
})

const activeFilters = computed(() => {
  const out = []
  if (filters.status !== 'All') out.push({ key: 'status', label: `Status: ${filters.status}` })
  if (filters.barangay !== 'All') out.push({ key: 'barangay', label: `Barangay: ${filters.barangay}` })
  if (filters.unit !== 'All') out.push({ key: 'unit', label: `Unit: ${filters.unit}` })
  return out
})

const clearFilter = (key) => {
  if (key === 'status') filters.status = 'All'
  else if (key === 'barangay') filters.barangay = 'All'
  else if (key === 'unit') filters.unit = 'All'
}

const clearAllFilters = () => {
  filters.status = 'All'
  filters.barangay = 'All'
  filters.unit = 'All'
}

const showActions = computed(() =>
  selectedRequest.value && (
    selectedRequest.value.status === 'Pending'
    || !selectedRequest.value.status
    || selectedRequest.value.status === 'Responding'
  )
)

const exportCsv = () => {
  const rows = filteredAndSortedRequests.value
  if (rows.length === 0) return

  const csv = buildRequestsCsv(rows, { itemId, requesterName, isWalkIn, requesterPhone, vehicleName, formatDateTime })
  const stamp = new Date().toISOString().slice(0, 10)
  const scope = filters.status === 'All' ? 'all' : filters.status.toLowerCase()
  downloadCsv(csv, `serbis-requests-${scope}-${stamp}.csv`)
}

const isSelected = (item) => selectedRequest.value && itemId(selectedRequest.value) === itemId(item)

const toggleSelect = (item) => {
  const id = itemId(item)
  if (selectedIds.has(id)) selectedIds.delete(id)
  else selectedIds.add(id)
}

const formatDateTime = (dateStr) => dateStr ? new Date(dateStr).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''

const getHeaders = () => ({
  'Authorization': `Bearer ${getToken()}`,
  'Content-Type': 'application/json',
  'Accept': 'application/json'
})

const loadAttachments = (item) => {
  validId.load(item, !!item?.has_valid_id)
  sitePhoto.load(item, !!item?.has_site_photo)
  letter.load(item, !!item?.has_letter)
}

const releaseAttachments = () => {
  validId.release()
  sitePhoto.release()
  letter.release()
}

const listAbortController = new AbortController()

const fetchData = async () => {
  try {
    const [reqRes, vehRes, resRes, svcRes] = await Promise.all([
      fetch(`${API_BASE}/admin/service-requests`, { headers: getHeaders(), signal: listAbortController.signal }),
      fetch(`${API_BASE}/vehicles`, { headers: getHeaders(), signal: listAbortController.signal }),
      fetch(`${API_BASE}/residents/lookup`, { headers: getHeaders(), signal: listAbortController.signal }),
      fetch(`${API_BASE}/services`, { headers: getHeaders(), signal: listAbortController.signal })
    ])
    const reqData = await reqRes.json()
    const vehData = await vehRes.json()
    const resData = await resRes.json()
    const svcData = await svcRes.json()
    const allRequests = reqData.data || reqData
    requests.value = allRequests.filter(r => !isAmbulanceRequest(r))
    vehicles.value = vehData.data || vehData
    residents.value = resData.data || resData
    services.value = svcData.data || svcData

    selectDefaultOrRefreshSelection()
  } catch (error) {
    if (error.name === 'AbortError') return
    console.error('Failed to fetch data:', error)
  } finally {
    initialLoad.value = false
  }
}

const fetchRequests = async () => {
  try {
    const reqRes = await fetch(`${API_BASE}/admin/service-requests`, { headers: getHeaders(), signal: listAbortController.signal })
    const reqData = await reqRes.json()
    const allRequests = reqData.data || reqData
    requests.value = allRequests.filter(r => !isAmbulanceRequest(r))

    selectDefaultOrRefreshSelection()
  } catch (error) {
    if (error.name === 'AbortError') return
    console.error('Failed to fetch service requests:', error)
  }
}

const selectDefaultOrRefreshSelection = () => {
  if (!selectedRequest.value) return
  const fresh = requests.value.find(r => itemId(r) === itemId(selectedRequest.value))
  if (fresh) selectRequest(fresh, false)
}

const selectRequest = (item, resetRemarks = true) => {
  if (!item) return
  apiError.value = ''
  selectedRequest.value = item
  formData.value = {
    remarks: resetRemarks ? (item.remarks || '') : formData.value.remarks,
    internal_notes: item.internal_notes || '',
    vehicle_id: item.vehicle_id || null
  }
  loadAttachments(item)
}

const selectVehicle = (id) => {
  formData.value.vehicle_id = id
  vehicleModal.value.isOpen = false
}

const vehicleName = (v) => v?.unit_identifier || 'Unassigned unit'

const vehicleIcon = (type) => ({
  ambulance: 'mdi-ambulance',
  'fire truck': 'mdi-fire-truck',
  'rescue vehicle': 'mdi-car-emergency',
  boat: 'mdi-ferry',
}[(type || '').toLowerCase()] || 'mdi-car')

const getSelectedVehicleName = () => {
  const v = vehicles.value.find(veh => veh.vehicle_id === formData.value.vehicle_id)
  return v ? `${vehicleName(v)} (${v.type})` : ''
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
        internal_notes: formData.value.internal_notes,
        vehicle_id: formData.value.vehicle_id || targetRequest.vehicle_id
      })
    })

    if (!res.ok) {
      const errData = await res.json().catch(() => ({}))
      const firstError = errData.errors ? Object.values(errData.errors)[0]?.[0] : null
      throw new Error(firstError || errData.message || 'Failed to update request')
    }

    await fetchRequests()

    reasonDialog.value.open = false
  } catch (error) {
    apiError.value = error.message
    reasonDialog.value.error = error.message
  } finally {
    loading.value = false
  }
}

const saveInternalNote = async () => {
  if (!selectedRequest.value) return
  noteSaving.value = true
  apiError.value = ''
  const id = itemId(selectedRequest.value)

  try {
    const res = await fetch(`${API_BASE}/service-requests/${id}`, {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify({ internal_notes: formData.value.internal_notes }),
    })
    if (!res.ok) {
      const errData = await res.json()
      throw new Error(errData.message || 'Failed to save the note')
    }
    await fetchRequests()
    noteSaved.value = true
    clearTimeout(noteSavedTimer)
    noteSavedTimer = setTimeout(() => { noteSaved.value = false }, 2000)
  } catch (error) {
    apiError.value = error.message
  } finally {
    noteSaving.value = false
  }
}

const submitWalkIn = async () => {
  const form = createDialog.value.form
  const isResident = createDialog.value.requesterType === 'resident'

  if (isResident && !form.resident_id) {
    createDialog.value.error = 'Pick a Head of the Family'
    return
  }
  if (!isResident && (!form.walk_in_name.trim() || !form.walk_in_contact_number.trim())) {
    createDialog.value.error = 'Name and contact number are required for someone with no account'
    return
  }
  if (!form.service_id) {
    createDialog.value.error = 'Pick a service'
    return
  }
  if (!form.description.trim()) {
    createDialog.value.error = 'Description is required'
    return
  }

  createDialog.value.loading = true
  createDialog.value.error = ''
  try {
    const body = new FormData()
    if (isResident) {
      body.append('resident_id', form.resident_id)
    } else {
      body.append('walk_in_name', form.walk_in_name.trim())
      body.append('walk_in_contact_number', form.walk_in_contact_number.trim())
    }
    body.append('service_id', form.service_id)
    body.append('description', form.description.trim())

    const res = await fetch(`${API_BASE}/admin/service-requests`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${getToken()}` },
      body,
    })
    if (!res.ok) {
      const errData = await res.json().catch(() => ({}))
      const firstError = errData.errors ? Object.values(errData.errors)[0]?.[0] : null
      throw new Error(firstError || errData.message || 'Failed to file the request')
    }
    createDialog.value.open = false
    await fetchRequests()
  } catch (error) {
    createDialog.value.error = error.message
  } finally {
    createDialog.value.loading = false
  }
}

const bulkDisapprove = async (reason) => {
  bulkLoading.value = true
  apiError.value = ''
  const targets = requests.value.filter(r => selectedIds.has(itemId(r)))
  try {
    await Promise.all(targets.map(async (r) => {
      const res = await fetch(`${API_BASE}/service-requests/${itemId(r)}`, {
        method: 'PUT',
        headers: getHeaders(),
        body: JSON.stringify({ status: 'Disapproved', remarks: reason, vehicle_id: r.vehicle_id })
      })
      if (!res.ok) throw new Error('Failed to update one or more requests')
    }))
    selectedIds.clear()
    await fetchRequests()
    reasonDialog.value.open = false
  } catch {
    apiError.value = 'Failed to update one or more requests'
    reasonDialog.value.error = 'Failed to update one or more requests'
  } finally {
    bulkLoading.value = false
  }
}

watch(selectedRequest, () => { lightbox.value = { open: false, key: null } })

watch(() => filters.status, () => { page.value = 1 })
watch(search, () => { page.value = 1 })
watch(() => filters.barangay, () => { page.value = 1 })
watch(() => filters.unit, () => { page.value = 1 })

onMounted(fetchData)
onMounted(fetchVehicleTypes)
onUnmounted(releaseAttachments)
onUnmounted(() => listAbortController.abort())
</script>

<style scoped>
.dashboard-bg {
  background-color: rgb(var(--v-theme-background));
}
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
.min-width-0 { min-width: 0; }

.detail-modal-card {
  max-height: 90vh;
}

.soft-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  box-shadow: 0 1px 2px rgba(var(--v-theme-on-surface), 0.04), 0 4px 14px rgba(var(--v-theme-on-surface), 0.08);
}

.subtle-surface {
  background-color: rgba(var(--v-theme-on-surface), 0.05);
}

.vehicle-option {
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
.vehicle-option:last-child { border-bottom: none; }
.vehicle-option:hover { background-color: rgba(var(--v-theme-primary), 0.06); }

.detail-group { margin-bottom: 28px; }
.detail-group:last-child { margin-bottom: 0; }

.section-title {
  font-size: 0.78rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgb(var(--v-theme-primary-strong));
  margin: 28px 0 12px;
}
.section-title:first-child { margin-top: 0; }

.dispatch-state {
  flex: 1 1 200px;
}

.cursor-pointer {
  cursor: pointer;
}

.description-line + .description-line {
  margin-top: 3px;
}

.attachment-tile {
  position: relative;
  display: block;
  padding: 0;
  overflow: hidden;
  cursor: pointer;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  background-color: rgba(var(--v-theme-on-surface), 0.05);
}

.attachment-tile:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.attachment-badge {
  position: absolute;
  right: 6px;
  bottom: 6px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: 6px;
  color: #fff;
  background-color: rgba(0, 0, 0, 0.6);
  transition: opacity 150ms ease;
}

.attachment-tile:hover .attachment-badge,
.attachment-tile:focus-visible .attachment-badge {
  opacity: 0;
}

.attachment-scrim {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-size: 0.8rem;
  font-weight: 700;
  color: #fff;
  background-color: rgba(0, 0, 0, 0.55);
  opacity: 0;
  transition: opacity 150ms ease;
}

.attachment-tile:hover .attachment-scrim,
.attachment-tile:focus-visible .attachment-scrim {
  opacity: 1;
}

@media (prefers-reduced-motion: reduce) {
  .attachment-scrim,
  .attachment-badge { transition: none; }
}

.attachment-error {
  max-width: 320px;
}

.attachment-tile--file {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 180px;
  height: 140px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  text-decoration: none;
  color: inherit;
}
.attachments-row {
  width: fit-content;
  max-width: 100%;
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

.request-row:focus-visible {
  outline: none;
  box-shadow: inset 0 0 0 2px rgb(var(--v-theme-primary));
  background-color: rgba(var(--v-theme-primary), 0.06);
}

.row-date {
  flex: 0 0 auto;
  font-variant-numeric: tabular-nums;
  opacity: 0.85;
}
</style>
