<template>
  <v-container fluid class="dashboard-bg" :class="{ 'pa-0': !standalone }">
    <div class="d-flex flex-column w-100">

      <PageHeader v-if="standalone" title="Resident Requests" class="mb-5">
        <template #subtitle>{{ subtitle }}</template>
        <template #actions>
          <ExportMenu type="request" :rows="filteredAndSortedRequests" :selected-ids="selectedIds" />
          <v-btn color="primary" variant="flat" class="text-none font-weight-bold" height="40" prepend-icon="mdi-plus" @click="openCreateDialog">
            Log service request
          </v-btn>
        </template>
      </PageHeader>

      <DataTablePage
        :loading="initialLoad"
        :refreshing="refreshing"
        class="request-table"
        compact
        filter-bar
        selectable
        :selected="[...selectedIds]"
        @update:selected="setSelected"
        v-model:search="search"
        search-placeholder="Search name or transaction no."
        :tabs="statusTabItems"
        :status="filters.status"
        @update:status="filters.status = $event"
        :headers="tableHeaders"
        :sort-hint="sortCaption"
        :items="filteredAndSortedRequests"
        item-value="request_id"
        :no-data-text="emptyListMessage"
        :page="page"
        @update:page="page = $event"
        :items-per-page="itemsPerPage"
        @update:items-per-page="itemsPerPage = $event"
        :row-props="(ctx) => ({
          class: isSelected(ctx.item) ? 'row-selected' : '',
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
            compact
            v-model:barangay="filters.barangay"
            v-model:unit="filters.unit"
            :barangay-options="barangayOptions"
            :unit-options="unitOptions"
          />
        </template>

        <template v-slot:summary>{{ summary }}</template>

        <template v-if="selectedIds.size > 0" v-slot:before-table>
          <BulkSelectionBar
            :selected="selectedIds.size"
            :actionable="pendingSelected.length"
            :loading="bulkLoading"
            @clear="selectedIds.clear()"
            @action="openReason('bulk')"
          />
        </template>

        <template v-slot:item.request_id="{ item }">
          <span class="mono txn">{{ transactionNo(item.request_id) }}</span>
        </template>

        <template v-slot:item._requesterName="{ item }">
          <PersonCell :name="item._requesterName" :secondary="item._where" :initials="requesterInitials(item)" :title="item._requesterName">
            <template v-if="item._badge" #badge>
              <v-chip size="x-small" variant="tonal" label class="ml-2 flex-shrink-0">{{ item._badge }}</v-chip>
            </template>
          </PersonCell>
        </template>

        <template v-slot:item._secondary="{ item }">
          <span class="text-truncate d-block" :title="item._secondary">{{ item._secondary }}</span>
        </template>

        <template v-slot:item.status="{ item }">
          <span class="status-col-pill"><StatusPill small :status="outcomeLabel(item.status || 'Pending')" /></span>
        </template>

        <template v-slot:item._unit="{ item }">
          <template v-if="item._unit || item.responders?.length">
            <div class="avatar-stack">
              <v-avatar v-for="r in item.responders" :key="r.responder_id" color="primary" variant="tonal" size="24" :title="r.name">
                <span class="text-caption font-weight-bold">{{ nameInitials(r.name) }}</span>
              </v-avatar>
            </div>
            <div class="text-caption text-medium-emphasis text-truncate">{{ item._unit || 'No vehicle' }}</div>
          </template>
          <span v-else class="text-medium-emphasis">Unassigned</span>
        </template>

        <!-- Pending: how long it has waited, the filing date under it. -->
        <template v-slot:item._waitDays="{ item }">
          <div class="font-weight-bold tabular" :class="WAIT_CLASS[waitTone(item._waitDays ?? 0)]">{{ pluralize(item._waitDays ?? 0, 'day') }}</div>
          <div class="text-caption text-medium-emphasis">Filed {{ shortDate(item.created_at) }}</div>
        </template>

        <template v-slot:item.created_at="{ item }">
          <div class="tabular">{{ shortDate(item.created_at) }}</div>
          <div class="text-caption text-medium-emphasis">{{ daysAgo(item.created_at) }}</div>
        </template>

        <template v-slot:item.first_responded_at="{ item }">
          <div class="font-weight-bold tabular">{{ shortDate(item.first_responded_at) || '—' }}</div>
          <div v-if="item.first_responded_at" class="text-caption text-medium-emphasis">for {{ elapsed(item.first_responded_at) }}</div>
        </template>

        <!-- Closed tabs: when it closed, and how long after filing. -->
        <template v-slot:item.resolved_at="{ item }">
          <div class="font-weight-bold tabular">{{ shortDate(item.resolved_at) || '—' }}</div>
          <div v-if="item.resolved_at" class="text-caption text-medium-emphasis">{{ item.status === 'Resolved' ? 'in' : 'after' }} {{ elapsed(item.created_at, item.resolved_at) }}</div>
        </template>

        <template v-slot:item.chevron>
          <v-icon size="18" class="text-medium-emphasis">mdi-chevron-right</v-icon>
        </template>
      </DataTablePage>

      <DetailDrawer
        :model-value="!!selectedRequest"
        @update:model-value="(v) => { if (!v) closeDrawer() }"
        eyebrow="Service request"
        :name="selectedRequest ? requesterName(selectedRequest) : ''"
        :initials="selectedRequest ? requesterInitials(selectedRequest) : ''"
        :secondary="drawerSecondary"
        :status-text="statusLine"
        :status-text-class="waitDays != null ? WAIT_CLASS[waitTone(waitDays)] : null"
      >
        <template v-if="picker.open" #panel>
          <PickerDrawer
            title="Assign to this request"
            :subtitle="pickerSubtitle"
            :tabs="pickerTabs"
            v-model:tab="picker.tab"
            :items="pickerItems"
            :selected="pickerSelected"
            :search-placeholder="picker.tab === 'responders' ? 'Search name or position' : 'Search unit or type'"
            :empty-text="picker.tab === 'responders'
              ? 'Every responder is deployed elsewhere or off duty.'
              : 'No free unit of a type this service uses. Units are dispatched, under maintenance, or set aside for other services.'"
            :error="responderModal.error"
            @toggle="onPick"
            @back="picker.open = false"
            @done="picker.open = false"
          />
        </template>

        <template v-if="selectedRequest && drawerBadge" #badge>
          <v-chip size="x-small" variant="tonal" label>{{ drawerBadge }}</v-chip>
        </template>
        <template #status>
          <StatusPill v-if="selectedRequest" :status="outcomeLabel(selectedRequest.status || 'Pending')" />
        </template>
        <template #actions>
          <ExportMenu v-if="selectedRequest" icon type="request" :row="selectedRequest" />
        </template>

        <template v-if="selectedRequest">
          <v-alert v-if="apiError" type="error" variant="tonal" class="mb-4" density="compact">{{ apiError }}</v-alert>

          <section class="detail-section">
            <h3 class="sect-label">Request</h3>
            <dl class="kv">
              <dt>Service</dt><dd class="font-weight-bold">{{ selectedRequest.service?.service_name || 'Other' }}</dd>
              <dt>Transaction</dt><dd class="mono">{{ transactionNo(selectedRequest.request_id) }}</dd>
              <dt>Submitted</dt><dd>{{ formatDateTime(selectedRequest.created_at) }}</dd>
              <template v-if="selectedRequest.preferred_date">
                <dt>Preferred date</dt><dd>{{ formatPreferredDate(selectedRequest.preferred_date) }}</dd>
              </template>
              <template v-if="selectedRequest.landmark">
                <dt>Location</dt><dd>{{ selectedRequest.landmark }}</dd>
              </template>
              <dt>Phone</dt>
              <dd>
                <a v-if="requesterPhone(selectedRequest) !== 'N/A'" :href="`tel:${requesterPhone(selectedRequest)}`" class="phone-link">{{ requesterPhone(selectedRequest) }}</a>
                <span v-else class="text-medium-emphasis">N/A</span>
              </dd>
            </dl>
            <div class="description-box mt-4">
              <template v-if="descriptionLines.length > 0">
                <div v-for="(line, i) in descriptionLines" :key="i" class="description-line">{{ line }}</div>
              </template>
              <span v-else class="text-medium-emphasis">No description provided by the Head of the Family.</span>
            </div>
          </section>

          <section v-if="attachments.length > 0" class="detail-section">
            <h3 class="sect-label">Attachments</h3>
            <div class="d-flex flex-wrap gap-3 attachments-row">
              <div v-for="a in attachments" :key="a.key">
                <v-skeleton-loader v-if="a.state.loading" type="image" height="140" width="180" class="rounded-lg"></v-skeleton-loader>
                <v-alert v-else-if="a.state.error" type="error" variant="tonal" density="compact" class="attachment-error">{{ a.state.error }}</v-alert>
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
          </section>

          <section class="detail-section">
            <h3 class="sect-label">Assignment</h3>
            <!-- Closed before anyone was sent: one dashed line, nothing to show. -->
            <div v-if="isClosedUnassigned" class="assign-empty">
              {{ selectedRequest.status === 'Disapproved' ? 'Not assigned. The request was disapproved before anyone was sent.' : 'Not assigned.' }}
            </div>
            <template v-else>
              <div class="assign-box">
                <div class="assign-row">
                  <div class="min-width-0">
                    <div class="text-caption text-medium-emphasis">Responders</div>
                    <div v-if="selectedRequest.responders?.length" class="d-flex flex-wrap ga-2 mt-1">
                      <span v-for="r in selectedRequest.responders" :key="r.responder_id" class="person-chip">
                        <v-avatar color="primary" variant="tonal" size="24"><span class="chip-initials">{{ nameInitials(r.name) }}</span></v-avatar>
                        {{ r.name }}
                      </span>
                    </div>
                    <div v-else class="text-medium-emphasis">None assigned</div>
                  </div>
                  <v-btn v-if="isPending" color="primary-strong" variant="outlined" height="36" class="text-none font-weight-bold flex-shrink-0" @click="openPicker('responders')">
                    Select responders
                  </v-btn>
                </div>
                <div v-if="!isProgramRequest(selectedRequest)" class="assign-row">
                  <div class="min-width-0">
                    <div class="text-caption text-medium-emphasis">Vehicle</div>
                    <div :class="{ 'text-medium-emphasis': !drawerVehicle }">{{ drawerVehicle || 'None assigned' }}</div>
                  </div>
                  <v-btn v-if="isPending" color="primary-strong" variant="outlined" height="36" class="text-none font-weight-bold flex-shrink-0" @click="openPicker('vehicle')">
                    Select vehicle
                  </v-btn>
                </div>
              </div>
              <div v-if="isPending" class="text-caption text-medium-emphasis mt-2">
                Optional. Assigning a responder lets the Head of the Family know who will come.
              </div>
            </template>
          </section>

          <section v-if="closedLabel" class="detail-section">
            <h3 class="sect-label">Outcome</h3>
            <dl class="kv">
              <dt>{{ closedLabel }}</dt><dd>{{ formatDateTime(selectedRequest.resolved_at) || '—' }}</dd>
              <template v-if="selectedRequest.status === 'Resolved' && selectedRequest.resolved_at">
                <dt>Time to resolve</dt><dd>{{ elapsed(selectedRequest.created_at, selectedRequest.resolved_at) }} after filing</dd>
              </template>
              <template v-if="selectedRequest.status === 'Disapproved' && selectedRequest.remarks">
                <dt>Reason</dt><dd>{{ selectedRequest.remarks }}</dd>
              </template>
            </dl>
          </section>
        </template>

        <!-- Pending: Disapprove left, Approve & assign right. Responding: resolve. Closed: none. -->
        <template v-if="isPending" #footer>
          <v-btn color="error" variant="outlined" class="text-none font-weight-bold" height="40" :loading="loading" @click="openReason('disapprove')">
            Disapprove
          </v-btn>
          <v-spacer></v-spacer>
          <v-btn color="primary" variant="flat" class="text-none font-weight-bold" height="40" :loading="loading" @click="openReason('approve')">
            Approve &amp; assign
          </v-btn>
        </template>
        <template v-else-if="selectedRequest?.status === 'Responding'" #footer>
          <v-spacer></v-spacer>
          <v-btn color="primary" variant="flat" class="text-none font-weight-bold" height="40" :loading="loading" @click="openResolveConfirm">
            Mark as resolved
          </v-btn>
        </template>
      </DetailDrawer>
    </div>

    <v-dialog v-model="lightbox.open" max-width="900">
      <v-card rounded="lg" elevation="6">
        <v-card-title class="pa-4 border-b d-flex justify-space-between align-center">
          <span class="text-h6 font-weight-bold">{{ lightboxAttachment?.label }}</span>
          <v-btn icon="mdi-close" variant="tonal" rounded="circle" size="small" aria-label="Close" @click="lightbox.open = false"></v-btn>
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

    <v-dialog v-model="reasonDialog.open" max-width="440" persistent @after-leave="clearReason">
      <v-card rounded="lg">
        <v-card-title class="d-flex justify-space-between align-center text-subtitle-1 font-weight-bold pa-5 pb-2 text-high-emphasis">
          <span>{{ reasonCopy.title }}</span>
          <v-btn
            icon="mdi-close" variant="tonal" rounded="circle" size="small" aria-label="Close"
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
              variant="outlined" color="primary"
              size="small"
              density="compact"
              class="text-none"
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
          <v-btn variant="outlined" color="primary" class="text-none font-weight-bold" height="44" @click="reasonDialog.open = false">Cancel</v-btn>
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
          <p v-if="selectedRequest?.vehicle_id || selectedRequest?.responders?.length" class="mb-0">
            The assigned unit and responders are released.
          </p>
        </v-card-text>
        <v-card-actions class="px-5 pb-5 pt-0 justify-end gap-3">
          <v-btn variant="outlined" color="primary" class="text-none font-weight-bold" height="44" :disabled="loading" @click="resolveDialog.open = false">
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
          <v-btn icon="mdi-close" variant="tonal" rounded="circle" size="small" aria-label="Close" @click="createDialog.open = false"></v-btn>
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
            <v-row density="compact">
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model="createDialog.form.walk_in_first_name"
                  label="First name"
                  placeholder="e.g. Juan"
                  variant="outlined"
                  density="comfortable"
                  class="mb-2"
                  :rules="[required, nameFormat]"
                ></v-text-field>
              </v-col>
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model="createDialog.form.walk_in_last_name"
                  label="Last name"
                  placeholder="e.g. Dela Cruz"
                  variant="outlined"
                  density="comfortable"
                  class="mb-2"
                  :rules="[required, nameFormat]"
                ></v-text-field>
              </v-col>
            </v-row>
            <v-text-field
              v-model="createDialog.form.walk_in_contact_number"
              label="Contact number"
              placeholder="e.g. 09171234567"
              variant="outlined"
              density="comfortable"
              class="mb-2"
              :rules="[required, phoneFormat]"
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
          <v-btn variant="outlined" color="primary" class="text-none font-weight-bold" height="44" @click="createDialog.open = false">Cancel</v-btn>
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
import { outcomeLabel, authHeaders, openWaitDays, waitTone, pluralize } from '@/composables/adminUi'
import { API_BASE } from '@/config/api'
import PageHeader from '@/components/PageHeader.vue'
import DataTablePage from '@/components/DataTablePage.vue'
import StatusPill from '@/components/StatusPill.vue'
import PersonCell from '@/components/PersonCell.vue'
import DetailDrawer from '@/components/DetailDrawer.vue'
import PickerDrawer from '@/components/PickerDrawer.vue'
import '@/components/detail-dialog.css'
import RequestFiltersBar from '@/components/RequestFiltersBar.vue'
import ExportMenu from '@/components/ExportMenu.vue'
import BulkSelectionBar from '@/components/BulkSelectionBar.vue'
import { requesterName, requesterAccountType, isWalkIn, requesterInitials, requesterPhone, requesterBarangay, vehicleName, vehicleIcon, getVehicleNameById, useDescriptionLines, useSelection, transactionNo } from '@/composables/requestDisplay'
import { useRequestAttachments } from '@/composables/useRequestAttachments'
import { useRequestFetch, AMBULANCE_SERVICE_CODE, itemId } from '@/composables/useRequestFetch'
import { REFERENCE_TTL_MS, useCachedFetch } from '@/composables/useCachedFetch'
import { useFilteredRequestList } from '@/composables/useFilteredRequestList'
import { useUpdateStatus } from '@/composables/useUpdateStatus'
import { useResolveDialog } from '@/composables/useResolveDialog'
import { emptyReasonDialog, useReasonActions } from '@/composables/useReasonActions'

defineProps({
  standalone: { type: Boolean, default: true },
})

const route = useRoute()
const getHeaders = authHeaders

const search = ref('')
const initialLoad = ref(true)
const loading = ref(false)
const bulkLoading = ref(false)
const apiError = ref('')
const page = ref(1)
const itemsPerPage = ref(10)

const isProgramRequest = (r) => r?.service?.category === 'programs'

// The tabs own `status`; there is no separate Status select.
const filters = reactive({ status: 'All', barangay: 'All', unit: 'All' })
const responderModal = ref({ loading: false, error: '', selectedIds: new Set() })
// The picker opens inside the drawer, replacing its body.
const picker = reactive({ open: false, tab: 'responders' })
const selectedRequest = ref(null)
const selectedIds = reactive(new Set())

const formData = ref({ remarks: '', internal_notes: '', vehicle_id: null })

const required = (v) => (v !== null && v !== undefined && String(v).trim() !== '') || 'Required'
// Letters (incl. accented/Ñ), spaces, hyphens, apostrophes, periods — no digits.
const nameFormat = (v) => !v || /^[\p{L}.'-]+(?:\s[\p{L}.'-]+)*$/u.test(v.trim()) || 'Letters only'
// Same shapes ServiceRequestController's PhoneNumber::REGEX accepts.
const phoneFormat = (v) => !v || /^(?:09\d{9}|639\d{9}|\+639\d{9})$/.test(v.trim()) || 'Use 09XXXXXXXXX'

const emptyCreateForm = () => ({
  resident_id: null,
  walk_in_first_name: '',
  walk_in_last_name: '',
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

const { attachments, lightbox, lightboxAttachment, openLightbox, loadAttachments, releaseAttachments } =
  useRequestAttachments(selectedRequest, { itemId, getHeaders })

const { requests, vehicles, residents, services, responders, listAbortController, refreshing, fetchData, fetchRequests, selectRequest } =
  useRequestFetch({ isAmbulance: false, initialLoad, apiError, formData, selectedRequest, loadAttachments })

const reasonDialog = ref(emptyReasonDialog())

const { updateStatus } = useUpdateStatus({ selectedRequest, loading, apiError, formData, itemId, getHeaders, fetchRequests, reasonDialog })

const { resolveDialog, openResolveConfirm, confirmResolve } = useResolveDialog(selectedRequest, { requesterName, updateStatus, apiError })

const { noteExpanded, pendingSelected, openReason, clearReason, confirmReason } =
  useReasonActions(reasonDialog, { formData, apiError, bulkLoading, requests, selectedIds, itemId, getHeaders, updateStatus, fetchRequests })
const skipped = computed(() => selectedIds.size - pendingSelected.value.length)

const { isSelected, setSelected } = useSelection(selectedRequest, selectedIds, itemId)

const descriptionLines = useDescriptionLines(selectedRequest)

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
            body: `This approves the request for ${what} and marks it Responding. No vehicle is assigned — cancel and use Select vehicle first if one is going out.`,
            placeholder: 'e.g. Our team will visit your address this afternoon',
            confirm: 'Approve',
          }
    }
    case 'bulk':
      return {
        title: `Disapprove ${pendingSelected.value.length} request${pendingSelected.value.length === 1 ? '' : 's'}`,
        body: `Every pending request in the selection is declined with this same reason.${skipped.value ? ` ${skipped.value} other${skipped.value === 1 ? '' : 's'} in the selection ${skipped.value === 1 ? 'is' : 'are'} not pending and will be skipped.` : ''}`,
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

const formatPreferredDate = (value, month = 'long') => {
  const [y, m, d] = String(value).split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('en-PH', { year: 'numeric', month, day: 'numeric' })
}

const statusTabs = ['All', 'Pending', 'Responding', 'Resolved', 'Disapproved', 'Cancelled']

const statusTabItems = computed(() =>
  statusTabs.map((status) => ({ value: status, label: status, count: requestCounts.value[status] })),
)

const unitOptions = computed(() => {
  const pool = vehicles.value.filter(v => v.type !== 'Ambulance')
  return ['All', 'Unassigned', ...pool.map(v => v.unit_identifier)]
})

// The date columns are the part that changes per tab: Pending shows how long
// it has waited, Responding since when, the closed tabs when it closed.
const SUBMITTED = { title: 'Submitted', key: 'created_at', width: 11 }
const closedColumn = (title) => [SUBMITTED, { title, key: 'resolved_at', width: 11 }]
const DATE_COLUMNS = {
  All: [SUBMITTED],
  Pending: [{ title: 'Waiting', key: '_waitDays', width: 11 }],
  Responding: [SUBMITTED, { title: 'Responding since', key: 'first_responded_at', width: 13 }],
  Resolved: closedColumn('Resolved'),
  Disapproved: closedColumn('Disapproved'),
  Cancelled: closedColumn('Cancelled'),
}

const HEADER_WIDTH_TOTAL = 92
const tableHeaders = computed(() => {
  const columns = [
    { title: 'Txn no.', key: 'request_id', width: 11 },
    { title: 'Requester', key: '_requesterName', width: 20 },
    { title: 'Service', key: '_secondary', width: 15 },
    { title: 'Status', key: 'status', width: 10, sortable: false },
    { title: 'Assigned', key: '_unit', width: 12, sortable: false },
    ...DATE_COLUMNS[filters.status],
  ]
  const scale = HEADER_WIDTH_TOTAL / columns.reduce((sum, c) => sum + c.width, 0)
  return [
    ...columns.map(c => ({ ...c, width: `${Math.round(c.width * scale * 10) / 10}%` })),
    { title: '', key: 'chevron', sortable: false, width: '40px' },
  ]
})

// Every tab keeps the shared order: newest first, so a request just filed
// shows at the top. All also lifts Pending above the rest.
const sortCaption = computed(() => (filters.status === 'All' ? 'Sorted by: pending on top, then newest' : 'Sorted by: newest'))
const time = (d) => (d ? new Date(d).getTime() : 0)

if (statusTabs.includes(route.query.status)) filters.status = route.query.status

const availableVehicles = computed(() => {
  const allowed = vehicleTypesByService.value[selectedRequest.value?.service?.code] ?? []
  return vehicles.value.filter(v =>
    v.status === 'Available' && v.type !== 'Ambulance' && (allowed.length === 0 || allowed.includes(v.type)),
  )
})

// Available responders, plus whichever are already on this request (so an
// admin removing one sees who they are removing, and toggling a different
// row's checkbox never silently drops one just by being absent from the list).
const responderPickerList = computed(() => {
  const assignedIds = responderModal.value.selectedIds
  return responders.value.filter(r => r.status === 'available' || assignedIds.has(r.responder_id))
})

const openPicker = (tab) => {
  const assigned = selectedRequest.value?.responders || []
  responderModal.value = { loading: false, error: '', selectedIds: new Set(assigned.map(r => r.responder_id)) }
  picker.tab = tab
  picker.open = true
}

const closeDrawer = () => {
  picker.open = false
  selectedRequest.value = null
}

const RESPONDER_STATUS = { available: 'Available', deployed: 'Deployed', off_duty: 'Off duty' }
const pickerTabs = computed(() => [
  { value: 'responders', label: 'Responders', count: responderModal.value.selectedIds.size },
  // Programs send no vehicle, so they get no Vehicle tab.
  ...(isProgramRequest(selectedRequest.value) ? [] : [{ value: 'vehicle', label: 'Vehicle', count: formData.value.vehicle_id ? 1 : 0 }]),
])
const pickerSubtitle = computed(() => {
  const r = selectedRequest.value
  return r ? `${r.service?.service_name || 'Other'} · ${requesterName(r)} · ${transactionNo(r.request_id)}` : ''
})
const pickerItems = computed(() => (picker.tab === 'responders'
  ? responderPickerList.value.map((r) => ({
      id: r.responder_id,
      name: r.name,
      secondary: r.position,
      initials: nameInitials(r.name),
      status: RESPONDER_STATUS[r.status] || r.status,
      disabled: responderModal.value.loading || (r.status !== 'available' && !responderModal.value.selectedIds.has(r.responder_id)),
    }))
  : availableVehicles.value.map((v) => ({
      id: v.vehicle_id,
      name: vehicleName(v),
      secondary: [v.type, v.specification].filter(Boolean).join(' · '),
      icon: vehicleIcon(v.type),
      status: 'Available',
    }))))
const pickerSelected = computed(() => (picker.tab === 'responders'
  ? [...responderModal.value.selectedIds]
  : (formData.value.vehicle_id ? [formData.value.vehicle_id] : [])))
// Responders save on each tick, as before; the vehicle is held until Approve.
const onPick = (item) => {
  if (picker.tab === 'responders') toggleResponder({ responder_id: item.id })
  else formData.value.vehicle_id = formData.value.vehicle_id === item.id ? null : item.id
}

const toggleResponder = async (responder) => {
  const { selectedIds } = responderModal.value
  const next = new Set(selectedIds)
  if (next.has(responder.responder_id)) next.delete(responder.responder_id)
  else next.add(responder.responder_id)

  responderModal.value.loading = true
  responderModal.value.error = ''
  try {
    const res = await fetch(`${API_BASE}/service-requests/${itemId(selectedRequest.value)}/responders`, {
      method: 'PATCH',
      headers: getHeaders(),
      body: JSON.stringify({ responder_ids: Array.from(next) }),
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null
      throw new Error(firstError || data.message || 'Failed to update responders')
    }
    responderModal.value.selectedIds = next
    selectedRequest.value.responders = data.responders
    await fetchRequests()
  } catch (error) {
    responderModal.value.error = error.message
  } finally {
    responderModal.value.loading = false
  }
}

const vehicleTypesByService = ref({})
const { get } = useCachedFetch()
// Optional: without it the vehicle picker falls back to every non-ambulance unit.
const fetchVehicleTypes = async () => {
  try {
    await get('/service-vehicle-types', {
      ttl: REFERENCE_TTL_MS,
      onData: (body) => {
        if (listAbortController.signal.aborted) return
        vehicleTypesByService.value = Object.fromEntries((body.data || []).map(row => [row.code, row.vehicle_types]))
      },
    })
  } catch (error) {
    if (!listAbortController.signal.aborted) console.error('Failed to fetch vehicle types:', error)
  }
}

const residentOptions = computed(() => residents.value
  .map(r => ({
    title: `${r.last_name}, ${r.first_name}${r.barangay?.barangay_name ? ' — ' + r.barangay.barangay_name : ''}`,
    value: r.resident_id,
  }))
  .slice()
  .sort((a, b) => a.title.localeCompare(b.title)))

const serviceOptions = computed(() => services.value
  .filter(s => s.code !== AMBULANCE_SERVICE_CODE && s.is_active !== false)
  .map(s => ({ title: s.service_name, value: s.service_id })))

const selectedVehicle = computed(() =>
  vehicles.value.find(v => v.vehicle_id === formData.value.vehicle_id) || null
)

const { barangayOptions, requestCounts, filteredAndSortedRequests, emptyListMessage, activeFilters, clearFilter, clearAllFilters } =
  useFilteredRequestList(requests, filters, search, {
    requesterName,
    secondaryFn: (r) => r.service?.service_name || 'Other',
    decorate: (r) => ({
      _badge: requesterBadge(r),
      _where: isWalkIn(r) ? 'No account' : requesterBarangay(r),
      _waitDays: openWaitDays(r.status, r.created_at),
    }),
  })

// A Head of the Family is the default, so only the other kinds get a badge.
const requesterBadge = (r) => (isWalkIn(r) ? 'Walk-in' : r.resident?.account_type === 'head_of_family' ? null : requesterAccountType(r))
const drawerBadge = computed(() => (selectedRequest.value ? requesterBadge(selectedRequest.value) : null))
const drawerSecondary = computed(() => {
  const r = selectedRequest.value
  if (!r) return ''
  if (isWalkIn(r)) return 'No account'
  return drawerBadge.value ? requesterBarangay(r) : `Head of the Family · ${requesterBarangay(r)}`
})

const WAIT_CLASS = { muted: 'text-medium-emphasis', warning: 'text-warning-strong', error: 'text-error' }

const isPending = computed(() => !!selectedRequest.value && (selectedRequest.value.status || 'Pending') === 'Pending')
const waitDays = computed(() => (isPending.value ? openWaitDays('Pending', selectedRequest.value.created_at) : null))

const CLOSED_LABELS = { Resolved: 'Resolved on', Disapproved: 'Disapproved on', Cancelled: 'Cancelled on' }
const closedLabel = computed(() => CLOSED_LABELS[selectedRequest.value?.status] || null)

const drawerVehicle = computed(() => (isPending.value
  ? (selectedVehicle.value ? vehicleName(selectedVehicle.value) : null)
  : (selectedRequest.value?.vehicle ? vehicleName(selectedRequest.value.vehicle) : null)))
const isClosedUnassigned = computed(() =>
  !!closedLabel.value && !selectedRequest.value.responders?.length && !selectedRequest.value.vehicle)

// The one line under the name: the wait, the filing time, or when and how fast it closed.
const statusLine = computed(() => {
  const r = selectedRequest.value
  if (!r) return ''
  if (isPending.value) return `Waiting ${pluralize(waitDays.value ?? 0, 'day')}`
  if (r.status === 'Responding') return `Filed ${formatDateTime(r.created_at)}`
  if (!r.resolved_at) return ''
  return `${formatDateTime(r.resolved_at)} · ${r.status === 'Resolved' ? 'in' : 'after'} ${elapsed(r.created_at, r.resolved_at)}`
})

const subtitle = computed(() => {
  const c = requestCounts.value
  switch (filters.status) {
    case 'Cancelled': return `${c.Cancelled} cancelled by the requester, newest first`
    case 'All': return `${c.All} requests · ${c.Pending} pending`
    default: return `${c[filters.status]} ${filters.status.toLowerCase()}, newest first`
  }
})

const summary = computed(() => {
  const total = filteredAndSortedRequests.value.length
  const noun = filters.status === 'All' ? 'requests' : filters.status.toLowerCase()
  if (!total) return `No ${noun}`
  const from = (page.value - 1) * itemsPerPage.value + 1
  return `Showing ${from} to ${Math.min(total, page.value * itemsPerPage.value)} of ${total} ${noun}`
})

const formatDateTime = (dateStr) => dateStr ? new Date(dateStr).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''
const shortDate = (d) => (d ? new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : '')
const nameInitials = (name) => (name || '').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase()
const DAY = 864e5
const daysAgo = (d) => {
  const days = Math.floor((Date.now() - time(d)) / DAY)
  return days <= 0 ? 'today' : days === 1 ? 'yesterday' : `${days} days ago`
}
// "17 hours", "3 days": hours under a day, days after.
const elapsed = (from, to = Date.now()) => {
  const hours = Math.max(0, Math.floor((time(to) - time(from)) / 36e5))
  return hours < 24 ? pluralize(Math.max(hours, 1), 'hour') : pluralize(Math.floor(hours / 24), 'day')
}

const getSelectedVehicleName = () => getVehicleNameById(vehicles.value, formData.value.vehicle_id)

const submitWalkIn = async () => {
  const form = createDialog.value.form
  const isResident = createDialog.value.requesterType === 'resident'

  if (isResident && !form.resident_id) {
    createDialog.value.error = 'Pick a Head of the Family'
    return
  }
  if (!isResident) {
    if (!form.walk_in_first_name.trim() || !form.walk_in_last_name.trim() || !form.walk_in_contact_number.trim()) {
      createDialog.value.error = 'Name and contact number are required for someone with no account'
      return
    }
    if (phoneFormat(form.walk_in_contact_number) !== true) {
      createDialog.value.error = 'Enter a valid contact number (e.g. 09171234567)'
      return
    }
    if (nameFormat(form.walk_in_first_name) !== true || nameFormat(form.walk_in_last_name) !== true) {
      createDialog.value.error = 'Names may only contain letters'
      return
    }
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
      body.append('walk_in_name', `${form.walk_in_first_name.trim()} ${form.walk_in_last_name.trim()}`)
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

watch(() => filters.status, () => { page.value = 1; selectedIds.clear() })
watch(search, () => { page.value = 1 })
watch(() => filters.barangay, () => { page.value = 1 })
watch(() => filters.unit, () => { page.value = 1 })

onMounted(async () => {
  await fetchData()
  // Dashboard rows deep-link here with ?request=<request_id>.
  const target = requests.value.find((r) => r.request_id === Number(route.query.request))
  if (target) selectRequest(target)
})
onMounted(fetchVehicleTypes)
onUnmounted(releaseAttachments)
onUnmounted(() => listAbortController.abort())
</script>

<style scoped src="@/styles/request-table.css"></style>

<style scoped>
.dashboard-bg {
  background-color: rgb(var(--v-theme-background));
}
.gap-3 { gap: 12px; }
.min-width-0 { min-width: 0; }

.subtle-surface {
  background-color: rgba(var(--v-theme-on-surface), 0.05);
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
  transition: opacity var(--motion-fast) var(--ease-out);
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
  transition: opacity var(--motion-fast) var(--ease-out);
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

/* Board type: dates in tabular figures. */
.tabular { font-variant-numeric: tabular-nums; white-space: nowrap; }
.request-table :deep(tbody tr.row-selected) { background-color: rgba(var(--v-theme-primary), 0.08); }

/* Overlapping responder initials, the unit under them. */
.avatar-stack { display: flex; }
.avatar-stack .v-avatar { border: 2px solid rgb(var(--v-theme-surface)); margin-right: -8px; }
.avatar-stack .v-avatar .text-caption { font-size: 10px !important; }

.description-box {
  padding: 12px 16px;
  border-radius: 12px;
  background: rgba(var(--v-theme-on-surface), 0.05);
  font-size: 15px;
  line-height: 22px;
}
.phone-link { color: rgb(var(--v-theme-primary-strong)); font-weight: 600; text-decoration: none; }

.assign-box { border: 1px solid rgba(var(--v-theme-on-surface), 0.14); border-radius: 12px; }
.assign-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
}
.assign-row + .assign-row { border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08); }
.assign-empty {
  padding: 14px 16px;
  border: 1px dashed rgba(var(--v-theme-on-surface), 0.2);
  border-radius: 12px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.person-chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 4px 12px 4px 4px;
  border-radius: 999px;
  background: rgba(var(--v-theme-on-surface), 0.06);
  font-size: 14px;
  font-weight: 600;
}
.chip-initials { font-size: 10px; font-weight: 700; }
</style>
