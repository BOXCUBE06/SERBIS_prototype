<template>
  <v-container fluid class="dashboard-bg" :class="{ 'pa-0': !standalone }">
    <div class="d-flex flex-column w-100">

      <PageHeader v-if="standalone" title="Ambulance Bookings" />

      <DataTablePage
        :loading="initialLoad"
        class="request-table"
        v-model:search="search"
        search-placeholder="Search by transaction number, name, barangay..."
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
            @click="openDayView"
          >
            <v-icon start size="small">mdi-calendar-clock</v-icon>
            Day View
          </v-btn>
          <ExportMenu type="booking" :rows="filteredAndSortedRequests" :selected-ids="selectedIds" />
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

        <template v-slot:item.request_id="{ item }">
          <span class="text-truncate d-block row-date mono">{{ transactionNo(item.request_id) }}</span>
        </template>

        <template v-slot:item._dateSubmitted="{ item }">
          <span class="text-truncate d-block row-date">{{ item._dateSubmitted }}</span>
        </template>

        <template v-slot:item.status="{ item }">
          <span class="status-col-pill"><StatusPill small :status="outcomeLabel(item.status || 'Pending', item.conduction_requests?.[0]?.no_arrival_reason)" /></span>
        </template>

        <template v-slot:item.scheduled_at="{ item }">
          <div class="scheduled-cell">
            <template v-if="item.scheduled_at">
              <div class="d-flex align-center">
                <v-icon size="12" class="mr-1 flex-shrink-0" :color="isBookingOverdue(item.status, item.scheduled_at) ? 'error' : undefined">mdi-calendar-clock</v-icon>
                <span class="row-date" :class="{ 'text-error font-weight-bold': isBookingOverdue(item.status, item.scheduled_at) }">{{ formatDateTime(item.scheduled_at) }}</span>
              </div>
              <StatusPill
                v-if="bookingCountdownLabel(item.status, item.scheduled_at, item.approved_at)"
                small
                class="mt-1"
                :status="isBookingOverdue(item.status, item.scheduled_at) ? 'Disapproved' : 'Booked'"
                :label="bookingCountdownLabel(item.status, item.scheduled_at, item.approved_at)"
              />
            </template>
            <template v-else>
              <span class="row-date">{{ item._dateSubmitted }}</span>
              <StatusPill
                v-if="pendingWaitLabel(item.status, item.created_at)"
                small
                status="Pending"
                :label="pendingWaitLabel(item.status, item.created_at)"
                class="ml-2"
              />
            </template>
          </div>
        </template>

        <template v-slot:item._requesterName="{ item }">
          <PersonCell
            :name="item._requesterName"
            :initials="requesterInitials(item)"
            :title="item._requesterName"
          />
        </template>

        <template v-slot:item._phone="{ item }">
          <span class="text-truncate d-block" :title="item._phone">{{ item._phone }}</span>
        </template>

        <template v-slot:item._secondary="{ item }">
          <span class="text-medium-emphasis text-truncate d-block" :title="item._secondary">{{ item._secondary }}</span>
        </template>

        <template v-slot:item.patient_name="{ item }">
          <span class="text-truncate d-block" :class="item.patient_name ? '' : 'text-medium-emphasis'" :title="item.patient_name">{{ item.patient_name || '—' }}</span>
        </template>

        <template v-slot:item._unit="{ item }">
          <span class="text-truncate d-block" :class="item._unit ? '' : 'text-medium-emphasis'">{{ item._unit || 'Unassigned' }}</span>
        </template>

        <template v-slot:item._dateApproved="{ item }">
          <span class="text-truncate d-block row-date" :class="item._dateApproved ? '' : 'text-medium-emphasis'">{{ item._dateApproved || '—' }}</span>
        </template>

        <template v-slot:item._resolvedAt="{ item }">
          <span class="text-truncate d-block row-date" :class="item._resolvedAt ? '' : 'text-medium-emphasis'">{{ item._resolvedAt || '—' }}</span>
        </template>
      </DataTablePage>

      <v-dialog
        :model-value="!!selectedRequest"
        @update:model-value="(v) => { if (!v) selectedRequest = null }"
        max-width="min(820px, 95vw)"
        class="detail-modal"
      >
        <v-card v-if="selectedRequest" rounded="lg" elevation="6" class="d-flex flex-column detail-modal-card">
          <DetailDialogHeader
            :name="requesterName(selectedRequest)"
            :initials="requesterInitials(selectedRequest)"
            :secondary="requesterBarangay(selectedRequest)"
            :wait-days="waitDays"
            @close="selectedRequest = null"
          >
            <template v-slot:status>
              <StatusPill :status="outcomeLabel(selectedRequest.status || 'Pending', respondingTrip?.no_arrival_reason)" />
            </template>
            <template v-slot:actions><ExportMenu type="booking" :row="selectedRequest" /></template>
          </DetailDialogHeader>

          <v-divider></v-divider>

          <div class="pa-6 overflow-y-auto flex-grow-1">
            <v-alert v-if="apiError" type="error" variant="tonal" class="mb-4" density="compact">{{ apiError }}</v-alert>

            <v-alert
              v-if="bookingCountdownLabel(selectedRequest.status, selectedRequest.scheduled_at, selectedRequest.approved_at)"
              :type="isBookingOverdue(selectedRequest.status, selectedRequest.scheduled_at) ? 'warning' : 'info'"
              variant="tonal"
              class="mb-4"
              density="compact"
              :title="bookingCountdownLabel(selectedRequest.status, selectedRequest.scheduled_at, selectedRequest.approved_at)"
            >
              {{ isBookingOverdue(selectedRequest.status, selectedRequest.scheduled_at)
                ? 'Scheduled time has passed and this booking is still open. Dispatch, reschedule, or resolve it.'
                : `Scheduled for ${formatDateTime(selectedRequest.scheduled_at)}.` }}
            </v-alert>

            <h3 class="section-title">Action</h3>

            <div v-if="selectedRequest.scheduled_at" class="detail-group">
              <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Scheduled</div>
              <div class="font-weight-medium text-body-2">
                {{ formatDateTime(selectedRequest.scheduled_at) }}
                <template v-if="selectedRequest.scheduled_end"> – {{ formatTime(selectedRequest.scheduled_end) }}</template>
              </div>
            </div>

            <div v-if="selectedRequest.status === 'Responding'" class="detail-group">
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
                <div class="d-flex align-center gap-3 min-width-0 mr-auto dispatch-state">
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
                      <template v-else>Select one to enable dispatch.</template>
                    </div>
                  </div>
                </div>

                <v-btn
                  color="primary"
                  variant="outlined"
                  class="text-none font-weight-bold"
                  height="40"
                  @click="vehicleModal.isOpen = true"
                >
                  {{ formData.vehicle_id ? 'Change Vehicle' : 'Select Vehicle' }}
                </v-btn>
                <v-btn color="error" variant="text" class="text-none font-weight-bold" height="40" :loading="loading" @click="openReason('disapprove')">
                  Disapprove
                </v-btn>
                <v-btn
                  color="secondary"
                  variant="flat"
                  class="text-none font-weight-bold text-white"
                  height="40"
                  :loading="loading"
                  :disabled="!formData.vehicle_id"
                  :aria-describedby="!formData.vehicle_id ? 'dispatch-gate' : undefined"
                  @click="openReason('approve')"
                >
                  Approve & Dispatch
                </v-btn>
                <span v-if="!formData.vehicle_id" id="dispatch-gate" class="d-sr-only">
                  Disabled until a vehicle is chosen with the Select Vehicle button beside it.
                </span>
              </template>
              <template v-else-if="selectedRequest.status === 'Responding'">
                <v-btn color="success" variant="flat" class="text-none font-weight-bold w-100" height="40" :loading="loading" @click="openResolveConfirm">
                  Mark as Resolved
                </v-btn>
              </template>
              <template v-else-if="selectedRequest.status === 'Booked'">
                <template v-if="!selectedRequest.vehicle_id">
                  <v-btn
                    color="secondary"
                    variant="flat"
                    class="text-none font-weight-bold text-white mr-auto"
                    height="40"
                    :loading="scheduledAvailabilityLoading"
                    @click="openAssignUnitModal"
                  >
                    Approve &amp; Assign Unit
                  </v-btn>
                  <v-btn variant="text" class="text-none font-weight-bold" height="40" @click="openReschedule">
                    Reschedule
                  </v-btn>
                  <v-btn color="error" variant="text" class="text-none font-weight-bold" height="40" :loading="loading" @click="openReason('disapprove')">
                    Reject
                  </v-btn>
                </template>

                <template v-else>
                  <v-btn variant="text" class="text-none font-weight-bold" height="40" @click="openReschedule">
                    Reschedule
                  </v-btn>
                  <v-btn color="secondary" variant="flat" class="text-none font-weight-bold text-white" height="40" @click="emit('dispatch-booking', selectedRequest)">
                    Dispatch
                  </v-btn>
                </template>
              </template>
            </div>

            <div v-if="!showActions && selectedRequest.status !== 'Responding' && !selectedRequest.scheduled_at" class="text-caption text-medium-emphasis detail-group">
              This request is closed — no action needed.
            </div>

            <template v-if="selectedRequest.pickup_location || selectedRequest.landmark || selectedRequest.destination">
              <h3 class="section-title">Route</h3>
              <div class="detail-group">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Pickup</div>
                <div class="font-weight-medium text-body-1">{{ selectedRequest.pickup_location || selectedRequest.landmark || 'N/A' }}</div>
                <div
                  v-if="selectedRequest.landmark && selectedRequest.pickup_location && !isLandmarkRedundant(selectedRequest.landmark, selectedRequest.pickup_location)"
                  class="text-caption text-medium-emphasis mt-1"
                >Landmark: {{ selectedRequest.landmark }}</div>
                <div class="mt-3">
                  <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Destination</div>
                  <div class="font-weight-medium text-body-2">{{ selectedRequest.destination || 'N/A' }}</div>
                </div>
              </div>
            </template>

            <h3 class="section-title">Patient &amp; Requester</h3>

            <v-row v-if="selectedRequest.patient_name" class="detail-group">
              <v-col cols="12" sm="6" md="4">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Patient</div>
                <div class="font-weight-medium text-body-2">{{ selectedRequest.patient_name }}</div>
              </v-col>
              <v-col cols="6" sm="3" md="4">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Age</div>
                <div class="font-weight-medium text-body-2">{{ selectedRequest.patient_age ?? 'N/A' }}</div>
              </v-col>
              <v-col cols="12" sm="6" md="4">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Address</div>
                <div class="font-weight-medium text-body-2">{{ selectedRequest.patient_address || 'N/A' }}</div>
              </v-col>
              <v-col cols="12">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Condition</div>
                <div class="font-weight-medium text-body-2">{{ selectedRequest.condition_notes || 'N/A' }}</div>
              </v-col>
            </v-row>

            <div v-else class="detail-group">
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
              <v-col cols="12" sm="4">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Transaction No.</div>
                <div class="font-weight-medium text-body-2 mono">{{ transactionNo(selectedRequest.request_id) }}</div>
              </v-col>
              <v-col cols="12" sm="4">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Approved</div>
                <div class="font-weight-medium text-body-2">{{ selectedRequest.approved_at ? formatDateTime(selectedRequest.approved_at) : '—' }}</div>
              </v-col>
              <v-col cols="12" sm="4">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Unit</div>
                <div class="font-weight-medium text-body-2">{{ selectedRequest.vehicle ? vehicleName(selectedRequest.vehicle) : 'Unassigned' }}</div>
              </v-col>
              <v-col cols="12" sm="4">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Resolved / Disapproved</div>
                <div class="font-weight-medium text-body-2">{{ selectedRequest.resolved_at ? formatDateTime(selectedRequest.resolved_at) : '—' }}</div>
              </v-col>
            </v-row>

            <h3 class="section-title">Notes &amp; Attachments</h3>

            <div class="detail-group">
              <v-alert :type="tripRecordAlertType" variant="tonal" border="start" rounded="lg" density="compact">
                <div class="text-subtitle-2 font-weight-bold mb-1">Trip record</div>
                <template v-if="respondingTrip">
                  <div class="text-body-2">
                    {{ tripDriverNames || 'No driver recorded yet' }}
                    <template v-if="respondingTrip.arrived_destination_at"> &bull; arrived {{ formatDateTime(respondingTrip.arrived_destination_at) }}</template>
                  </div>
                  <div v-if="respondingTrip.no_arrival_reason" class="text-body-2 text-warning">
                    <v-icon size="14" class="mr-1">mdi-alert-circle-outline</v-icon>No arrival: {{ respondingTrip.no_arrival_reason }}
                  </div>
                  <div class="text-caption text-medium-emphasis mb-2">
                    Odometer: {{ respondingTrip.odometer_start ?? '—' }} → {{ respondingTrip.odometer_end ?? '—' }}
                  </div>
                  <v-btn
                    variant="outlined" size="small" class="text-none font-weight-bold"
                    @click="emit('open-trip-record', respondingTrip.conduction_request_id)"
                  >Open Trip Record</v-btn>
                </template>
                <div v-else-if="selectedRequest.status === 'Responding'" class="text-body-2">
                  No trip record found for this request.
                </div>
                <div v-else-if="selectedRequest.status === 'Resolved'" class="text-body-2">
                  This request resolved with no trip record on file — likely older data.
                </div>
                <div v-else-if="['Disapproved', 'Cancelled'].includes(selectedRequest.status)" class="text-body-2">
                  Closed before a trip was ever started.
                </div>
                <div v-else class="text-body-2">
                  No trip record yet — one is created automatically once this request is dispatched.
                </div>
              </v-alert>
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

    <v-dialog v-model="vehicleModal.isOpen" max-width="600" :persistent="selectedRequest?.status === 'Booked'">
      <v-card rounded="lg" elevation="6">
        <v-card-title class="pa-4 border-b d-flex justify-space-between align-center">
          <span class="text-h6 font-weight-bold">
            {{ selectedRequest?.status === 'Booked' ? 'Approve & Assign Unit' : 'Available Vehicles' }}
          </span>
          <v-btn icon="mdi-close" variant="text" density="comfortable" @click="vehicleModal.isOpen = false"></v-btn>
        </v-card-title>

        <v-alert v-if="selectedRequest?.status === 'Booked' && apiError" type="error" variant="tonal" density="compact" class="ma-4 mb-0">{{ apiError }}</v-alert>

        <v-card-text class="pa-0 subtle-surface" style="max-height: 400px; overflow-y: auto;">
          <div v-if="scheduledAvailabilityLoading" class="pa-4">
            <v-skeleton-loader type="list-item-avatar-two-line" v-for="n in 3" :key="n" class="mb-1"></v-skeleton-loader>
          </div>

          <v-list v-else-if="selectedRequest?.status === 'Booked'" bg-color="transparent" class="py-0">
            <template v-if="bookingUnitOptions.length > 0">
              <v-list-item
                v-for="opt in bookingUnitOptions"
                :key="opt.vehicle.vehicle_id"
                class="vehicle-option px-4 py-3"
                :active="formData.vehicle_id === opt.vehicle.vehicle_id"
                :disabled="!opt.available"
                @click="selectVehicle(opt.vehicle.vehicle_id)"
              >
                <template v-slot:prepend>
                  <v-avatar
                    :color="formData.vehicle_id === opt.vehicle.vehicle_id ? 'success' : undefined"
                    :variant="formData.vehicle_id === opt.vehicle.vehicle_id ? 'flat' : 'tonal'"
                    size="42"
                    class="mr-3"
                  >
                    <v-icon :color="formData.vehicle_id === opt.vehicle.vehicle_id ? 'white' : undefined">{{ vehicleIcon(opt.vehicle.type) }}</v-icon>
                  </v-avatar>
                </template>

                <v-list-item-title class="font-weight-bold text-body-1">{{ vehicleName(opt.vehicle) }}</v-list-item-title>
                <v-list-item-subtitle class="text-caption text-uppercase font-weight-bold">
                  <template v-if="opt.available">
                    {{ opt.vehicle.type }}<template v-if="opt.vehicle.specification"> &bull; {{ opt.vehicle.specification }}</template>
                  </template>
                  <template v-else>{{ opt.reason }}</template>
                </v-list-item-subtitle>

                <template v-slot:append>
                  <v-icon v-if="formData.vehicle_id === opt.vehicle.vehicle_id" color="success">mdi-check-circle</v-icon>
                </template>
              </v-list-item>
            </template>
            <div v-else class="pa-6 text-center text-medium-emphasis">
              <v-icon size="48" class="mb-3">mdi-car-off</v-icon>
              <div class="text-h6 font-weight-bold">No Ambulance Units</div>
              <div class="text-body-2">The fleet has no Ambulance unit at all yet.</div>
            </div>
          </v-list>

          <v-list v-else-if="availableVehicles.length > 0" bg-color="transparent" class="py-0">
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

        <v-card-actions v-if="selectedRequest?.status === 'Booked'" class="pa-4 border-t d-flex justify-end gap-3">
          <v-btn variant="text" class="text-none font-weight-bold" :disabled="loading" @click="vehicleModal.isOpen = false">Cancel</v-btn>
          <v-btn
            color="secondary"
            variant="flat"
            class="text-none font-weight-bold text-white"
            :disabled="!formData.vehicle_id"
            :loading="loading"
            @click="approveBooking"
          >
            Approve
          </v-btn>
        </v-card-actions>
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

    <v-dialog v-model="rescheduleDialog.open" max-width="440" persistent>
      <v-card rounded="lg">
        <v-card-title class="d-flex justify-space-between align-center text-subtitle-1 font-weight-bold pa-5 pb-2 text-high-emphasis">
          <span>Reschedule booking</span>
          <v-btn
            icon="mdi-close" variant="text" size="small" aria-label="Close"
            :disabled="loading" @click="rescheduleDialog.open = false"
          ></v-btn>
        </v-card-title>
        <v-card-text class="px-5 pt-2">
          <v-alert
            v-if="rescheduleDialog.error"
            type="error" variant="tonal" density="compact" class="mb-4"
          >{{ rescheduleDialog.error }}</v-alert>
          <DateTimePickerField
            :model-value="rescheduleDialog.form.scheduled_at"
            type="datetime-local"
            label="New scheduled time"
            variant="outlined"
            density="comfortable"
            class="mb-3"
            :error-messages="rescheduleDialog.errors.scheduled_at"
            @update:model-value="setRescheduleStart"
          ></DateTimePickerField>
          <DateTimePickerField
            v-model="rescheduleDialog.form.scheduled_end"
            type="datetime-local"
            label="Ends"
            variant="outlined"
            density="comfortable"
            class="mb-3"
            :error-messages="rescheduleDialog.errors.scheduled_end"
            @update:model-value="rescheduleDialog.errors.scheduled_end = ''"
          ></DateTimePickerField>
          <v-textarea
            v-model="rescheduleDialog.form.remarks"
            label="Reason for the change"
            placeholder="e.g. Unit committed to an earlier transport"
            hint="Required — this is what the Head of the Family sees, and what the log records."
            persistent-hint
            variant="outlined"
            rows="2"
            counter="255"
            maxlength="255"
            :error-messages="rescheduleDialog.errors.remarks"
            @update:model-value="rescheduleDialog.errors.remarks = ''"
          ></v-textarea>
        </v-card-text>
        <v-card-actions class="px-5 pb-5 pt-0 justify-end gap-3">
          <v-btn variant="text" class="text-none font-weight-bold" height="44" @click="rescheduleDialog.open = false">Cancel</v-btn>
          <v-btn
            color="secondary"
            variant="flat"
            class="px-6 text-none font-weight-bold text-white"
            height="44"
            :loading="loading"
            @click="submitReschedule"
          >Reschedule</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="dayView.open" max-width="820" scrollable>
      <v-card rounded="lg">
        <v-card-title class="d-flex justify-space-between align-center pa-6 pb-4 border-b bg-surface">
          <div class="d-flex align-center gap-2">
            <v-btn icon="mdi-chevron-left" variant="text" density="comfortable" aria-label="Previous day" @click="shiftDayViewDate(-1)"></v-btn>
            <div>
              <div class="text-h6 font-weight-bold text-high-emphasis">Ambulance Day View</div>
              <div class="text-caption text-medium-emphasis">{{ dayViewDateLabel }}</div>
            </div>
            <v-btn icon="mdi-chevron-right" variant="text" density="comfortable" aria-label="Next day" @click="shiftDayViewDate(1)"></v-btn>
          </div>
          <div class="d-flex align-center gap-2">
            <DateTimePickerField
              v-model="dayView.date"
              type="date"
              variant="outlined"
              density="compact"
              hide-details
              style="max-width: 170px;"
            ></DateTimePickerField>
            <v-btn icon="mdi-close" variant="text" size="small" aria-label="Close" @click="dayView.open = false"></v-btn>
          </div>
        </v-card-title>

        <v-card-text class="pa-6">
          <div v-if="dayView.loading">
            <v-skeleton-loader v-for="n in 4" :key="n" type="list-item-two-line" class="mb-3"></v-skeleton-loader>
          </div>

          <template v-else-if="dayView.units.length > 0">
            <div class="day-view-scale">
              <span v-for="mark in dayViewHourMarks" :key="mark.hour" class="day-view-scale-label" :style="{ left: mark.left }">{{ mark.label }}</span>
            </div>

            <div v-for="unit in dayView.units" :key="unit.vehicle_id" class="day-view-row">
              <div class="day-view-unit">
                <div class="font-weight-bold text-body-2 text-truncate">{{ unit.unit_identifier }}</div>
                <div class="text-caption text-medium-emphasis text-truncate">{{ unit.specification || '&nbsp;' }}</div>
                <StatusPill v-if="unit.is_maintenance" small status="Disapproved" label="Maintenance" class="mt-1" />
              </div>
              <div class="day-view-track" :class="{ 'day-view-track--maintenance': unit.is_maintenance }">
                <div v-for="mark in dayViewHourMarks" :key="mark.hour" class="day-view-hourline" :style="{ left: mark.left }"></div>
                <div
                  v-for="(w, i) in unit.booked_windows"
                  :key="i"
                  class="day-view-segment"
                  :style="dayViewSegmentStyle(w)"
                  :title="`${formatTime(w.scheduled_at)} – ${formatTime(w.scheduled_end)}`"
                >{{ dayViewSegmentLabel(w) }}</div>
              </div>
            </div>
          </template>

          <div v-else class="pa-6 text-center text-medium-emphasis">
            <v-icon size="40" class="mb-2">mdi-ambulance</v-icon>
            <div class="text-body-2">No Ambulance units to show.</div>
          </div>
        </v-card-text>
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
            <v-row dense>
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

          <v-row dense>
            <v-col cols="12" sm="8">
              <v-text-field
                v-model="createDialog.form.patient_name"
                label="Patient name" placeholder="e.g. Maria Santos" variant="outlined" density="comfortable" class="mb-2"
                :rules="[required]"
              ></v-text-field>
            </v-col>
            <v-col cols="6" sm="4">
              <v-text-field
                v-model="createDialog.form.patient_age"
                label="Age" placeholder="e.g. 54" type="number" min="0" max="150" variant="outlined" density="comfortable" class="mb-2"
              ></v-text-field>
            </v-col>
          </v-row>
          <v-text-field
            v-model="createDialog.form.patient_address"
            label="Patient address" placeholder="e.g. Purok 2, San Fabian" variant="outlined" density="comfortable" class="mb-2"
            :rules="[required]"
          ></v-text-field>
          <v-row dense>
            <v-col cols="12" sm="6">
              <v-text-field
                v-model="createDialog.form.pickup_location"
                label="Pickup location" placeholder="e.g. Barangay Hall, San Fabian" variant="outlined" density="comfortable" class="mb-2"
                :rules="[required]"
              ></v-text-field>
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field
                v-model="createDialog.form.destination"
                label="Destination" placeholder="e.g. Echague District Hospital" variant="outlined" density="comfortable" class="mb-2"
                :rules="[required]"
              ></v-text-field>
            </v-col>
          </v-row>
          <v-textarea
            v-model="createDialog.form.condition_notes"
            label="Condition" placeholder="e.g. Chest pains since morning, conscious and breathing" variant="outlined" density="comfortable" rows="2" class="mb-2"
            :rules="[required]"
          ></v-textarea>

          <div class="mb-2">
            <div class="d-flex align-center justify-space-between mb-1">
              <span class="text-caption font-weight-bold text-uppercase text-medium-emphasis">Patient / Relatives</span>
              <v-btn variant="text" size="small" density="compact" class="text-none" prepend-icon="mdi-plus" @click="addRelative">
                Add relative
              </v-btn>
            </div>
            <div
              v-for="(_n, idx) in createDialog.form.patient_relatives"
              :key="idx"
              class="d-flex align-center gap-2 mb-2"
            >
              <v-text-field
                v-model="createDialog.form.patient_relatives[idx]"
                :label="idx === 0 ? 'Relative 1 *' : `Relative ${idx + 1}`"
                placeholder="e.g. Ana Santos"
                variant="outlined"
                density="compact"
                hide-details
              ></v-text-field>
              <v-btn
                icon="mdi-close"
                variant="text"
                size="small"
                :aria-label="`Remove relative ${idx + 1}`"
                @click="removeRelative(idx)"
              ></v-btn>
            </div>
          </div>

          <v-divider class="mb-4"></v-divider>
          <v-switch
            v-model="createDialog.scheduleForLater"
            color="secondary"
            density="comfortable"
            hide-details
            class="mb-2 flex-grow-0"
            label="Schedule for a later time"
          ></v-switch>

          <template v-if="createDialog.scheduleForLater">
            <DateTimePickerField
              v-model="createDialog.form.scheduled_at"
              type="datetime-local"
              :min="minScheduleValue"
              label="Scheduled time"
              hint="At least 1 hour from now — for anything sooner, dispatch now instead."
              persistent-hint
              variant="outlined"
              density="comfortable"
              class="mb-2"
              @update:model-value="checkWalkInAvailability"
            ></DateTimePickerField>

            <div v-if="walkInAvailability.checking" class="d-flex align-center gap-2 text-caption text-medium-emphasis mb-2">
              <v-progress-circular indeterminate size="14" width="2"></v-progress-circular>
              Checking availability…
            </div>
            <div
              v-else-if="walkInAvailability.checked"
              class="d-flex align-center gap-2 text-caption mb-2"
              :class="walkInAvailability.freeCount > 0 ? 'text-success' : 'text-warning'"
            >
              <v-icon size="16">{{ walkInAvailability.freeCount > 0 ? 'mdi-check-circle-outline' : 'mdi-alert-circle-outline' }}</v-icon>
              {{ walkInAvailability.freeCount > 0
                ? `${walkInAvailability.freeCount} unit${walkInAvailability.freeCount === 1 ? '' : 's'} free for this window`
                : 'No ambulance free for this window — try a different time.' }}
            </div>
          </template>
        </v-card-text>
        <v-card-actions class="pa-6 pt-0 d-flex justify-end gap-3 border-t">
          <v-btn variant="text" class="text-none font-weight-bold" height="44" @click="createDialog.open = false">Cancel</v-btn>
          <v-btn
            color="secondary"
            variant="flat"
            class="px-6 text-none font-weight-bold text-white"
            height="44"
            :loading="createDialog.loading"
            :disabled="noAmbulanceFreeForWindow"
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
import { outcomeLabel, isBookingOverdue, bookingCountdownLabel, pendingWaitLabel, openWaitDays, authHeaders } from '@/composables/adminUi'
import { API_BASE } from '@/config/api'
import DateTimePickerField from '@/components/DateTimePickerField.vue'
import PageHeader from '@/components/PageHeader.vue'
import DataTablePage from '@/components/DataTablePage.vue'
import StatusPill from '@/components/StatusPill.vue'
import PersonCell from '@/components/PersonCell.vue'
import DetailDialogHeader from '@/components/DetailDialogHeader.vue'
import RequestFiltersBar from '@/components/RequestFiltersBar.vue'
import ExportMenu from '@/components/ExportMenu.vue'
import { requesterName, isWalkIn, requesterInitials, requesterPhone, requesterBarangay, vehicleName, vehicleIcon, getVehicleNameById, useDescriptionLines, useSelection, transactionNo } from '@/composables/requestDisplay'
import { useRequestAttachments } from '@/composables/useRequestAttachments'
import { useRequestFetch, AMBULANCE_SERVICE_CODE, itemId } from '@/composables/useRequestFetch'
import { useFilteredRequestList } from '@/composables/useFilteredRequestList'
import { useUpdateStatus } from '@/composables/useUpdateStatus'
import { useResolveDialog } from '@/composables/useResolveDialog'
import { emptyReasonDialog, useReasonActions } from '@/composables/useReasonActions'

defineProps({
  standalone: { type: Boolean, default: true },
})

const emit = defineEmits(['dispatch-booking', 'open-trip-record', 'trip-record-created'])

const route = useRoute()
const getHeaders = authHeaders

const search = ref('')
const initialLoad = ref(true)
const loading = ref(false)
const bulkLoading = ref(false)
const apiError = ref('')
const page = ref(1)
const itemsPerPage = ref(10)

const ambulanceServiceId = computed(() => services.value.find(s => s.code === AMBULANCE_SERVICE_CODE)?.service_id ?? null)

const filters = reactive({ status: 'All', barangay: 'All', unit: 'All' })
const vehicleModal = ref({ isOpen: false })
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
  patient_name: '',
  patient_age: null,
  patient_address: '',
  pickup_location: '',
  destination: '',
  condition_notes: '',
  patient_relatives: [''],
  scheduled_at: '',
})
const emptyWalkInAvailability = () => ({ checking: false, checked: false, freeCount: 0 })
const createDialog = ref({
  open: false,
  loading: false,
  error: '',
  requesterType: 'resident',
  scheduleForLater: false,
  form: emptyCreateForm(),
})
const walkInAvailability = ref(emptyWalkInAvailability())

const noAmbulanceFreeForWindow = computed(() =>
  createDialog.value.scheduleForLater
  && walkInAvailability.value.checked
  && walkInAvailability.value.freeCount === 0
)

const openCreateDialog = () => {
  createDialog.value = {
    open: true,
    loading: false,
    error: '',
    requesterType: 'resident',
    scheduleForLater: false,
    form: { ...emptyCreateForm(), service_id: ambulanceServiceId.value },
  }
  walkInAvailability.value = emptyWalkInAvailability()
}

const addRelative = () => { createDialog.value.form.patient_relatives.push('') }
const removeRelative = (idx) => {
  const list = createDialog.value.form.patient_relatives
  list.splice(idx, 1)
  if (list.length === 0) list.push('')
}

const minScheduleValue = computed(() => toDateTimeLocal(new Date(Date.now() + 60 * 60 * 1000)))

let walkInAvailabilityToken = 0
const checkWalkInAvailability = async () => {
  const raw = createDialog.value.form.scheduled_at
  walkInAvailabilityToken += 1
  const token = walkInAvailabilityToken

  if (!raw) {
    walkInAvailability.value = emptyWalkInAvailability()
    return
  }
  const start = new Date(raw)
  if (Number.isNaN(start.getTime())) {
    walkInAvailability.value = emptyWalkInAvailability()
    return
  }

  walkInAvailability.value = { checking: true, checked: false, freeCount: 0 }
  try {
    const end = new Date(start.getTime() + 2 * 60 * 60 * 1000)
    const params = new URLSearchParams({ start: start.toISOString(), end: end.toISOString() })
    const res = await fetch(`${API_BASE}/ambulance-availability?${params}`, { headers: getHeaders() })
    const units = res.ok ? await res.json() : []
    if (token !== walkInAvailabilityToken) return
    walkInAvailability.value = { checking: false, checked: true, freeCount: Array.isArray(units) ? units.length : 0 }
  } catch {
    if (token !== walkInAvailabilityToken) return
    walkInAvailability.value = emptyWalkInAvailability()
  }
}

const { attachments, lightbox, lightboxAttachment, openLightbox, loadAttachments, releaseAttachments } =
  useRequestAttachments(selectedRequest, { itemId, getHeaders })

const { requests, vehicles, residents, services, listAbortController, fetchData, fetchRequests, selectRequest } =
  useRequestFetch({ isAmbulance: true, getHeaders, initialLoad, apiError, formData, selectedRequest, loadAttachments })

const reasonDialog = ref(emptyReasonDialog())

const { updateStatus } = useUpdateStatus({
  selectedRequest, loading, apiError, formData, itemId, getHeaders, fetchRequests, reasonDialog,
  onResponding: () => emit('trip-record-created'),
})

const { resolveDialog, openResolveConfirm, confirmResolve } = useResolveDialog(selectedRequest, { requesterName, updateStatus, apiError })

const { noteExpanded, openReason, clearReason, confirmReason } =
  useReasonActions(reasonDialog, { formData, apiError, bulkLoading, requests, selectedIds, itemId, getHeaders, updateStatus, fetchRequests })

const { isSelected, toggleSelect } = useSelection(selectedRequest, selectedIds, itemId)

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

const waitDays = computed(() => (selectedRequest.value?.status === 'Pending' ? openWaitDays(selectedRequest.value?.status, selectedRequest.value?.created_at) : null))
const respondingTrip = computed(() => selectedRequest.value?.conduction_requests?.[0] ?? null)
const tripDriverNames = computed(() =>
  (respondingTrip.value?.people || []).filter(p => p.role === 'driver').map(p => p.name).join(', ')
)
const tripRecordAlertType = computed(() => {
  if (respondingTrip.value) {
    return respondingTrip.value.trip_status === 'Completed' ? 'success' : 'warning'
  }
  return ['Responding', 'Resolved'].includes(selectedRequest.value?.status) ? 'warning' : 'info'
})

const statusTabs = ['All', 'Pending', 'Booked', 'Responding', 'Resolved', 'Disapproved', 'Cancelled']

const statusTabItems = computed(() =>
  statusTabs.map((status) => ({ value: status, label: status, count: requestCounts.value[status] })),
)

const unitOptions = computed(() => {
  const pool = vehicles.value.filter(v => v.type === 'Ambulance')
  return ['All', 'Unassigned', ...pool.map(v => v.unit_identifier)]
})

const HEADER_WIDTH_TOTAL = 96
const tableHeaders = computed(() => {
  const columns = [
    { title: 'Transaction No.', key: 'request_id', width: 8 },
    { title: 'Submitted', key: '_dateSubmitted', width: 11 },
    { title: 'Status', key: 'status', width: 7, sortable: false },
    { title: 'Scheduled', key: 'scheduled_at', width: 15 },
    { title: 'Requester', key: '_requesterName', width: 15 },
    { title: 'Phone', key: '_phone', width: 10 },
    { title: 'Barangay', key: '_secondary', width: 10 },
    { title: 'Patient', key: 'patient_name', width: 11 },
    { title: 'Unit', key: '_unit', width: 7, sortable: false },
    { title: 'Approved', key: '_dateApproved', width: 10 },
    { title: 'Resolved / Disapproved', key: '_resolvedAt', width: 13 },
  ]
  const scale = HEADER_WIDTH_TOTAL / columns.reduce((sum, c) => sum + c.width, 0)
  return [
    { title: '', key: 'select', sortable: false, width: '48px' },
    ...columns.map(c => ({ ...c, width: `${Math.round(c.width * scale * 10) / 10}%` })),
  ]
})

if (statusTabs.includes(route.query.status)) filters.status = route.query.status

const isLandmarkRedundant = (landmark, pickup) => {
  if (!landmark || !pickup) return false
  const normalize = (s) => s.trim().toLowerCase().replace(/\s+/g, ' ')
  const a = normalize(landmark)
  const b = normalize(pickup)
  return a === b || a.includes(b) || b.includes(a)
}

const scheduledAvailability = ref([])
const scheduledAvailabilityLoading = ref(false)

const fetchScheduledAvailability = async (req) => {
  scheduledAvailability.value = []
  if (!req?.scheduled_at) return

  scheduledAvailabilityLoading.value = true
  try {
    const start = new Date(req.scheduled_at)
    const end = req.scheduled_end ? new Date(req.scheduled_end) : new Date(start.getTime() + 2 * 60 * 60 * 1000)
    const params = new URLSearchParams({ start: start.toISOString(), end: end.toISOString() })
    const res = await fetch(`${API_BASE}/ambulance-availability?${params}`, { headers: getHeaders() })
    scheduledAvailability.value = res.ok ? await res.json() : []
  } catch {
    scheduledAvailability.value = []
  } finally {
    scheduledAvailabilityLoading.value = false
  }
}

const openAssignUnitModal = async () => {
  apiError.value = ''
  await fetchScheduledAvailability(selectedRequest.value)
  vehicleModal.value.isOpen = true
}

const availableVehicles = computed(() => {
  if (selectedRequest.value?.status === 'Booked') {
    return scheduledAvailability.value
      .map(u => vehicles.value.find(v => v.vehicle_id === u.vehicle_id))
      .filter(Boolean)
  }
  return vehicles.value.filter(v => v.status === 'Available' && v.type === 'Ambulance')
})

const bookingUnitOptions = computed(() => {
  const freeIds = new Set(scheduledAvailability.value.map(u => u.vehicle_id))

  return vehicles.value
    .filter(v => v.type === 'Ambulance')
    .map(v => ({
      vehicle: v,
      available: freeIds.has(v.vehicle_id),
      reason: freeIds.has(v.vehicle_id)
        ? null
        : v.status === 'Maintenance'
          ? 'Under maintenance'
          : 'Already booked for this window',
    }))
    .toSorted((a, b) => Number(b.available) - Number(a.available))
})

const residentOptions = computed(() => residents.value
  .map(r => ({
    title: `${r.last_name}, ${r.first_name}${r.barangay?.barangay_name ? ' — ' + r.barangay.barangay_name : ''}`,
    value: r.resident_id,
  }))
  .toSorted((a, b) => a.title.localeCompare(b.title)))

const selectedVehicle = computed(() =>
  vehicles.value.find(v => v.vehicle_id === formData.value.vehicle_id) || null
)

const { barangayOptions, requestCounts, filteredAndSortedRequests, emptyListMessage, activeFilters, clearFilter, clearAllFilters } =
  useFilteredRequestList(requests, filters, search, {
    requesterName,
    secondaryFn: requesterBarangay,
    decorate: (r) => ({
      _dateSubmitted: formatDate(r.created_at),
      _phone: requesterPhone(r),
      _dateApproved: r.approved_at ? formatDate(r.approved_at) : '',
      _resolvedAt: r.resolved_at ? formatDate(r.resolved_at) : '',
    }),
  })

const showActions = computed(() =>
  selectedRequest.value && (
    selectedRequest.value.status === 'Pending'
    || !selectedRequest.value.status
    || selectedRequest.value.status === 'Responding'
    || selectedRequest.value.status === 'Booked'
  )
)

const formatDate = (dateStr) => dateStr ? new Date(dateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : ''
const formatDateTime = (dateStr) => dateStr ? new Date(dateStr).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''
const formatTime = (dateStr) => new Date(dateStr).toLocaleTimeString(undefined, { timeStyle: 'short' })

const selectRequestById = (id) => {
  const item = requests.value.find((r) => itemId(r) === id)
  if (!item) return
  search.value = ''
  filters.status = 'All'
  selectRequest(item)
}

const selectVehicle = (id) => {
  formData.value.vehicle_id = id
  if (selectedRequest.value?.status !== 'Booked') {
    vehicleModal.value.isOpen = false
  }
}

const getSelectedVehicleName = () => getVehicleNameById(vehicles.value, formData.value.vehicle_id)

const approveBooking = async () => {
  if (!formData.value.vehicle_id) return

  loading.value = true
  apiError.value = ''
  const id = itemId(selectedRequest.value)

  try {
    const res = await fetch(`${API_BASE}/service-requests/${id}/approve`, {
      method: 'PATCH',
      headers: getHeaders(),
      body: JSON.stringify({ vehicle_id: formData.value.vehicle_id }),
    })

    if (!res.ok) {
      const errData = await res.json().catch(() => ({}))
      const firstError = errData.errors ? Object.values(errData.errors)[0]?.[0] : null
      throw new Error(firstError || errData.message || 'Failed to approve the booking')
    }

    await fetchRequests()
    vehicleModal.value.isOpen = false
  } catch (error) {
    apiError.value = error.message
  } finally {
    loading.value = false
  }
}

const emptyRescheduleForm = () => ({ scheduled_at: '', scheduled_end: '', remarks: '' })
const emptyRescheduleErrors = () => ({ scheduled_at: '', scheduled_end: '', remarks: '' })
const rescheduleDialog = ref({ open: false, form: emptyRescheduleForm(), errors: emptyRescheduleErrors(), error: '' })

const toDateTimeLocal = (iso) => {
  if (!iso) return ''
  const d = new Date(iso)
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

const openReschedule = () => {
  apiError.value = ''
  const req = selectedRequest.value
  rescheduleDialog.value = {
    open: true,
    error: '',
    errors: emptyRescheduleErrors(),
    form: {
      scheduled_at: toDateTimeLocal(req.scheduled_at),
      scheduled_end: toDateTimeLocal(req.scheduled_end) || toDateTimeLocal(new Date(new Date(req.scheduled_at).getTime() + 2 * 60 * 60 * 1000)),
      remarks: '',
    },
  }
}

const setRescheduleStart = (value) => {
  const { form, errors } = rescheduleDialog.value
  const shift = new Date(value).getTime() - new Date(form.scheduled_at).getTime()
  const end = new Date(form.scheduled_end).getTime()
  form.scheduled_at = value
  errors.scheduled_at = ''
  if (Number.isNaN(shift) || Number.isNaN(end)) return
  form.scheduled_end = toDateTimeLocal(new Date(end + shift))
  errors.scheduled_end = ''
}

const submitReschedule = async () => {
  const { form } = rescheduleDialog.value
  const errors = emptyRescheduleErrors()
  if (!form.scheduled_at) errors.scheduled_at = 'Pick the new time.'
  if (!form.scheduled_end) errors.scheduled_end = 'Pick when it ends.'
  if (!form.remarks.trim()) errors.remarks = 'Give a reason — the Head of the Family is shown this.'
  rescheduleDialog.value.errors = errors
  rescheduleDialog.value.error = ''
  if (Object.values(errors).some(Boolean)) return

  loading.value = true
  const id = itemId(selectedRequest.value)

  try {
    const res = await fetch(`${API_BASE}/service-requests/${id}/reschedule`, {
      method: 'PATCH',
      headers: getHeaders(),
      body: JSON.stringify({
        scheduled_at: form.scheduled_at.replace('T', ' ') + ':00',
        scheduled_end: form.scheduled_end.replace('T', ' ') + ':00',
        remarks: form.remarks,
      }),
    })

    if (!res.ok) {
      const errData = await res.json().catch(() => ({}))
      const fieldErrors = errData.errors || {}
      const errors = rescheduleDialog.value.errors
      const unplaced = []
      for (const [field, messages] of Object.entries(fieldErrors)) {
        if (field in errors) errors[field] = messages[0]
        else unplaced.push(messages[0])
      }
      if (unplaced.length || !Object.keys(fieldErrors).length) {
        rescheduleDialog.value.error = unplaced[0] || errData.message || 'Failed to reschedule the booking'
      }
      return
    }

    await fetchRequests()
    rescheduleDialog.value.open = false
  } catch (error) {
    rescheduleDialog.value.error = error.message
  } finally {
    loading.value = false
  }
}

const todayLocalDate = () => {
  const d = new Date()
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

const dayView = ref({ open: false, date: todayLocalDate(), loading: false, units: [] })

const fetchDayView = async () => {
  dayView.value.loading = true
  try {
    const res = await fetch(`${API_BASE}/ambulance-availability?date=${dayView.value.date}`, { headers: getHeaders() })
    dayView.value.units = res.ok ? await res.json() : []
  } catch {
    dayView.value.units = []
  } finally {
    dayView.value.loading = false
  }
}

const openDayView = () => {
  dayView.value.open = true
  fetchDayView()
}

const shiftDayViewDate = (deltaDays) => {
  const [y, m, d] = dayView.value.date.split('-').map(Number)
  const next = new Date(y, m - 1, d + deltaDays)
  const pad = (n) => String(n).padStart(2, '0')
  dayView.value.date = `${next.getFullYear()}-${pad(next.getMonth() + 1)}-${pad(next.getDate())}`
}

watch(() => dayView.value.date, () => { if (dayView.value.open) fetchDayView() })

const dayViewDateLabel = computed(() => {
  const [y, m, d] = dayView.value.date.split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })
})

const dayViewHourMarks = [0, 6, 12, 18, 24].map((hour) => ({
  hour,
  left: `${(hour / 24) * 100}%`,
  label: hour === 0 || hour === 24 ? '12 AM' : hour === 12 ? '12 PM' : hour < 12 ? `${hour} AM` : `${hour - 12} PM`,
}))

const dayViewSegmentStyle = (window) => {
  const [y, m, d] = dayView.value.date.split('-').map(Number)
  const dayStart = new Date(y, m - 1, d)
  const minutesInDay = 24 * 60

  const startMin = Math.min(minutesInDay, Math.max(0, (new Date(window.scheduled_at) - dayStart) / 60_000))
  const endMin = Math.min(minutesInDay, Math.max(0, (new Date(window.scheduled_end) - dayStart) / 60_000))

  return {
    left: `${(startMin / minutesInDay) * 100}%`,
    width: `${Math.max(0.75, ((endMin - startMin) / minutesInDay) * 100)}%`,
  }
}

const dayViewSegmentLabel = (window) => {
  const start = new Date(window.scheduled_at)
  const end = new Date(window.scheduled_end)

  const hourLabel = (d) => {
    const h12 = d.getHours() % 12 === 0 ? 12 : d.getHours() % 12
    const mins = d.getMinutes()
    return mins === 0 ? `${h12}` : `${h12}:${String(mins).padStart(2, '0')}`
  }
  const period = (d) => (d.getHours() < 12 ? 'AM' : 'PM')

  return period(start) === period(end)
    ? `${hourLabel(start)}–${hourLabel(end)} ${period(end)}`
    : `${hourLabel(start)} ${period(start)}–${hourLabel(end)} ${period(end)}`
}

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
  if (!form.patient_name.trim() || !form.patient_address.trim() || !form.pickup_location.trim()
      || !form.destination.trim() || !form.condition_notes.trim()) {
    createDialog.value.error = 'Patient name, address, pickup, destination and condition are required'
    return
  }
  if (!form.patient_relatives.some(name => name.trim())) {
    createDialog.value.error = 'Name at least one relative or companion going with the patient'
    return
  }
  if (createDialog.value.scheduleForLater && !form.scheduled_at) {
    createDialog.value.error = 'Pick a date and time, or turn off scheduling to dispatch now'
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
    body.append('patient_name', form.patient_name.trim())
    if (form.patient_age) body.append('patient_age', form.patient_age)
    body.append('patient_address', form.patient_address.trim())
    body.append('pickup_location', form.pickup_location.trim())
    body.append('destination', form.destination.trim())
    body.append('condition_notes', form.condition_notes.trim())
    form.patient_relatives
      .map(n => (n || '').trim())
      .filter(n => n !== '')
      .forEach(n => body.append('patient_relatives[]', n))
    if (createDialog.value.scheduleForLater && form.scheduled_at) {
      body.append('scheduled_at', form.scheduled_at.replace('T', ' ') + ':00')
    }

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

watch(() => filters.status, () => { page.value = 1 })
watch(search, () => { page.value = 1 })
watch(() => filters.barangay, () => { page.value = 1 })
watch(() => filters.unit, () => { page.value = 1 })

onMounted(async () => {
  await fetchData()
  // Dashboard rows deep-link here with ?request=<request_id>.
  selectRequestById(Number(route.query.request))
})
onUnmounted(releaseAttachments)
onUnmounted(() => listAbortController.abort())

defineExpose({ selectRequestById })
</script>

<style scoped src="@/styles/request-table.css"></style>

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

.request-row {
  cursor: pointer;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.06);
  border-left: 3px solid transparent;
  transition: background-color var(--motion-fast) var(--ease-out);
}
.request-row:hover {
  background-color: rgba(var(--v-theme-on-surface), 0.04);
}
.request-row.row-selected {
  background-color: rgba(var(--v-theme-primary), 0.08);
}
.request-row.row-pending { border-left-color: rgb(var(--v-theme-warning)); }
.request-row.row-booked { border-left-color: #6D28D9; }
.v-theme--dark .request-row.row-booked { border-left-color: #A78BFA; }
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

.day-view-scale {
  position: relative;
  height: 20px;
  margin-left: 152px;
}
.day-view-scale-label {
  position: absolute;
  transform: translateX(-50%);
  font-size: 0.6875rem;
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), 0.5);
}
.day-view-row {
  display: flex;
  align-items: stretch;
  gap: 16px;
  padding: 10px 0;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.06);
}
.day-view-row:last-child {
  border-bottom: none;
}
.day-view-unit {
  flex: 0 0 136px;
  display: flex;
  flex-direction: column;
  justify-content: center;
}
.day-view-track {
  position: relative;
  flex: 1 1 auto;
  min-height: 40px;
  border-radius: 8px;
  background: rgba(var(--v-theme-on-surface), 0.04);
}
.day-view-track--maintenance {
  background: repeating-linear-gradient(
    135deg,
    rgba(var(--v-theme-on-surface), 0.04),
    rgba(var(--v-theme-on-surface), 0.04) 8px,
    rgba(var(--v-theme-on-surface), 0.07) 8px,
    rgba(var(--v-theme-on-surface), 0.07) 16px
  );
}
.day-view-hourline {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 1px;
  background: rgba(var(--v-theme-on-surface), 0.08);
}
.day-view-segment {
  position: absolute;
  top: 4px;
  bottom: 4px;
  border-radius: 6px;
  background: rgba(109, 40, 217, 0.14);
  color: #5B21B6;
  font-size: 0.6875rem;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  padding: 0 4px;
}
.v-theme--dark .day-view-segment {
  background: rgba(167, 139, 250, 0.18);
  color: #A78BFA;
}
</style>
