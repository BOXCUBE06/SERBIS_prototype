<template>
  <!-- Scrolls with the page like every other list: the split pane that
       needed a fixed-height canvas is gone (detail is a modal now). As a whole
       route the shell sets the padding (App.vue); pa-0 sits flush inside the
       Ambulance Dispatch tab it's embedded in. -->
  <v-container
    fluid
    class="dashboard-bg"
    :class="{ 'pa-0': !standalone }"
  >
    <div class="d-flex flex-column w-100">

      <PageHeader
        v-if="standalone"
        :title="scope === 'ambulance' ? 'Ambulance Bookings' : 'Resident Requests'"
      />

          <v-skeleton-loader v-if="initialLoad" type="table" class="rounded-lg"></v-skeleton-loader>

          <!-- Every status tab always renders, including a Disapproved or
               Cancelled reading zero — hiding an empty status read as "this
               queue has nothing named Disapproved" rather than "nothing is
               disapproved right now" (MDRRMO feedback, 2026-09-18). -->
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
              <!-- Cross-cutting narrows a dispatcher reaches for less often
                   than the status tabs above (MDRRMO feedback, 2026-09-18). -->
              <v-select
                v-model="filters.barangay"
                :items="barangayOptions"
                label="Barangay"
                variant="outlined"
                density="compact"
                hide-details
                rounded="lg"
                class="filter-field"
              ></v-select>
              <v-select
                v-model="filters.unit"
                :items="unitOptions"
                label="Unit"
                variant="outlined"
                density="compact"
                hide-details
                rounded="lg"
                class="filter-field"
              ></v-select>
            </template>

            <!-- Both scopes render their own actions now; the Ambulance
                 Dispatch page used to host Bookings' copy in its page header,
                 outside this panel. Log Service Request is the one decision
                 here, so it alone is filled; Day View and Export are
                 supporting views at text weight. Day View is ambulance-only:
                 the fleet schedule says nothing about a road-clearing queue. -->
            <template v-slot:actions>
              <v-btn
                v-if="scope === 'ambulance'"
                color="primary"
                variant="text"
                class="text-none font-weight-bold"
                height="40"
                @click="openDayView"
              >
                <v-icon start size="small">mdi-calendar-clock</v-icon>
                Day View
              </v-btn>
              <!-- Writes what the operator is looking at: current filter and
                   search, in the order shown. -->
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
              <!-- Someone who shows up at the office in person rather than
                   through the app, with or without an account. -->
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

            <!-- Bulk action bar. Count in the label — the button names what
                 it does and to how many — destructive action rightmost, the
                 same order the confirm dialog below uses. -->
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
              <StatusPill small :status="outcomeLabel(item.status || 'Pending', item.conduction_requests?.[0]?.no_arrival_reason)" />
            </template>

            <!-- A Booked row's own scheduled time is the date an operator
                 actually needs here, not when it was filed — created_at
                 stays as the fallback for every other status. -->
            <template v-slot:item.scheduled_at="{ item }">
              <template v-if="item.scheduled_at">
                <div class="d-flex align-center">
                  <v-icon size="12" class="mr-1 flex-shrink-0" :color="isBookingOverdue(item.status, item.scheduled_at) ? 'error' : undefined">mdi-calendar-clock</v-icon>
                  <span class="row-date" :class="{ 'text-error font-weight-bold': isBookingOverdue(item.status, item.scheduled_at) }">{{ formatDateTime(item.scheduled_at) }}</span>
                </div>
                <!-- One label, one pill — "Awaiting unit" / "Unit assigned" /
                     "…late — not dispatched" (MDRRMO feedback, 2026-09-18).
                     Overdue only changes which pill color this reuses, not a
                     separate branch. -->
                <StatusPill
                  v-if="bookingCountdownLabel(item.status, item.scheduled_at, item.approved_at)"
                  small
                  class="mt-1"
                  :status="isBookingOverdue(item.status, item.scheduled_at) ? 'Disapproved' : 'Booked'"
                  :label="bookingCountdownLabel(item.status, item.scheduled_at, item.approved_at)"
                />
              </template>
              <div v-else>
                <!-- A program (training, drill) is asked for a day, and that day
                     is what the office plans around, so it replaces the filed
                     date here. Certification has none and shows the filed date
                     like every other request. -->
                <template v-if="item.preferred_date">
                  <v-icon size="12" class="mr-1 flex-shrink-0">mdi-calendar-clock</v-icon>
                  <span class="row-date">{{ formatPreferredDate(item.preferred_date, 'short') }}</span>
                </template>
                <span v-else class="row-date">{{ formatDate(item.created_at) }}</span>
                <!-- The one status with no scheduled_at at all — an
                     untriaged call's own age is the signal here (MDRRMO
                     feedback, 2026-09-18). Still the filing age when the
                     date shown is a program's preferred day. -->
                <StatusPill
                  v-if="pendingWaitLabel(item.status, item.created_at)"
                  small
                  status="Pending"
                  :label="pendingWaitLabel(item.status, item.created_at)"
                  class="ml-2"
                />
              </div>
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

            <template v-slot:item.patient_name="{ item }">
              <span class="text-truncate d-block" :class="item.patient_name ? '' : 'text-medium-emphasis'">{{ item.patient_name || '—' }}</span>
            </template>

            <template v-slot:item._unit="{ item }">
              <span class="text-truncate d-block" :class="item._unit ? '' : 'text-medium-emphasis'">{{ item._unit || 'Unassigned' }}</span>
            </template>
          </DataTablePage>

      <!-- Detail modal, centred rather than a right-hand drawer (MDRRMO
           feedback, 2026-09-18) — the drawer was `temporary`, meaning it
           already blocked the list behind its own scrim while open, so
           centring it costs nothing the flush-right position was actually
           protecting. Closing it (X, ESC, backdrop) clears selectedRequest
           through the setter below; picking a different row just swaps this
           same modal's content via the same selectRequest() assignment as
           always, so the dispatcher's place in the list is never lost. -->
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
              <StatusPill :status="outcomeLabel(selectedRequest.status || 'Pending', respondingTrip?.no_arrival_reason)" />
              <v-btn icon="mdi-close" variant="text" density="comfortable" aria-label="Close" @click="selectedRequest = null"></v-btn>
            </div>
          </div>

          <v-divider></v-divider>

          <div class="pa-6 overflow-y-auto flex-grow-1">
            <v-alert v-if="apiError" type="error" variant="tonal" class="mb-4" density="compact">{{ apiError }}</v-alert>

            <!-- One alert, title from the same sub-label the row shows —
                 "Awaiting unit" / "Unit assigned" / "…late — not
                 dispatched" (MDRRMO feedback, 2026-09-18). Only the body
                 and the alert's own color differ between overdue and
                 upcoming. -->
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

            <!-- Pending's own age — no scheduled_at to build a countdown
                 from, so created_at is what says how long this call has
                 sat untouched (MDRRMO feedback, 2026-09-18). -->
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

            <!-- Section 1: Action. Time, unit, the decision itself — lead
                 with what the dispatcher acts on, in reading order, not
                 just visual weight (MDRRMO feedback, 2026-09-18). These
                 buttons used to be a pinned footer at the bottom of a
                 much longer scroll. -->
            <h3 class="section-title">Action</h3>

            <div v-if="selectedRequest.scheduled_at" class="detail-group">
              <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Scheduled</div>
              <div class="font-weight-medium text-body-2">
                {{ formatDateTime(selectedRequest.scheduled_at) }}
                <template v-if="selectedRequest.scheduled_end"> – {{ formatTime(selectedRequest.scheduled_end) }}</template>
              </div>
            </div>

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
                <!-- Requests dispatched before the server owned the fleet
                     carry no `vehicle_id` at all. Naming the absence beats
                     "Vehicle Unknown", which read as a lookup that failed. -->
                <div v-if="selectedRequest.vehicle" class="text-body-2">
                  {{ vehicleName(selectedRequest.vehicle) }} ({{ selectedRequest.vehicle.type || 'Unit' }})
                </div>
                <div v-else class="text-body-2">No unit is recorded against this request.</div>
              </v-alert>
            </div>

            <div v-if="showActions" class="detail-group d-flex align-center flex-wrap gap-3">
              <template v-if="selectedRequest.status === 'Pending' || !selectedRequest.status">
                <!-- The unit and the button it unlocks sit in one row,
                     right next to the decision itself now — they used to
                     be a scroll apart, the picker up in the body and the
                     action it gated pinned in a separate footer. -->
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
                      <template v-else-if="scope === 'ambulance'">Select one to enable dispatch.</template>
                      <template v-else>Optional — approving without one sends no unit.</template>
                    </div>
                  </div>
                </div>

                <!-- primary, not secondary. Secondary is #0A2620, a near-black
                     green that works as a fill under a white label and vanishes
                     as an outline on the dark theme's own dark surface. -->
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
                  :disabled="scope === 'ambulance' && !formData.vehicle_id"
                  :aria-describedby="scope === 'ambulance' && !formData.vehicle_id ? 'dispatch-gate' : undefined"
                  @click="openReason('approve')"
                >
                  {{ isProgramRequest(selectedRequest) ? 'Approve' : 'Approve & Dispatch' }}
                </v-btn>
                <span v-if="scope === 'ambulance' && !formData.vehicle_id" id="dispatch-gate" class="d-sr-only">
                  Disabled until a vehicle is chosen with the Select Vehicle button beside it.
                </span>
              </template>
              <template v-else-if="selectedRequest.status === 'Responding'">
                <!-- Confirmed, not immediate: resolving stamps a terminal
                     status the panel offers no way back from. -->
                <v-btn color="success" variant="flat" class="text-none font-weight-bold w-100" height="40" :loading="loading" @click="openResolveConfirm">
                  Mark as Resolved
                </v-btn>
              </template>
              <template v-else-if="selectedRequest.status === 'Booked'">
                <!-- Assigning a unit and approving used to be two separate
                     controls; both steps now live inside the one dialog
                     this button opens. Reject reuses the existing
                     Disapprove flow untouched. -->
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

                <!-- Already approved: the fleet decision is made, so what is
                     left is moving the time or actually sending the crew. -->
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

            <!-- Section 2: Route. Only for a request that actually has one
                 of these fields — a non-ambulance service has none of them
                 at all. -->
            <template v-if="selectedRequest.pickup_location || selectedRequest.landmark || selectedRequest.destination">
              <h3 class="section-title">Route</h3>
              <div class="detail-group">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Pickup</div>
                <div class="font-weight-medium text-body-1">{{ selectedRequest.pickup_location || selectedRequest.landmark || 'N/A' }}</div>
                <!-- Real de-dupe, not a relabel: ADDRESS, LANDMARK and
                     PICKUP used to show the same text three times under
                     three different labels (MDRRMO feedback, 2026-09-18).
                     A landmark only earns its own line when it says
                     something Pickup does not already say. -->
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

            <!-- Section 3: Patient & Requester. -->
            <h3 class="section-title">Patient &amp; Requester</h3>

            <!-- Structured ambulance intake (C3's columns) shown as its own
                 labeled fields when present, instead of only the
                 server-composed `description` text those exact columns
                 generate. -->
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

            <!-- Fallback: a non-ambulance service (still just typed as one
                 free-text description) or a pre-C3 ambulance record the
                 backfill couldn't fully read. -->
            <div v-else class="detail-group">
              <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-2">Description</div>
              <!-- One element per line. The description arrives newline-
                   separated and was rendered as a single interpolation, so
                   every break collapsed to a space and the whole thing read
                   as one run-on sentence. -->
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

            <!-- Section 4: Notes & Attachments. Lowest priority, reference
                 material rather than something the dispatcher acts on
                 first. -->
            <h3 class="section-title">Notes &amp; Attachments</h3>

            <!-- The trip record C5 creates the moment this request went
                 Responding (ServiceRequestController::createConductionStub)
                 — surfaced here rather than rebuilt here: "Open Trip
                 Record" jumps to the Trip Logs tab's own dialog, which
                 already has every field. Alert type is state-driven: a
                 completed trip is the request working as intended, not
                 something to flag. -->
            <div v-if="scope === 'ambulance'" class="detail-group">
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

            <!-- Staff-only scratch pad. Deliberately never pre-fills the
                 approve/decline dialog below (reasonDialog) — that field
                 goes to the requester, this one never does. Visible
                 regardless of status: a note about what happened is still
                 useful to read on a Resolved request, not just a Pending
                 one. -->
            <div class="detail-group">
              <v-textarea
                v-model="formData.internal_notes" label="Internal note (staff only)" variant="outlined" density="comfortable" rounded="lg" rows="2"
                placeholder="e.g. Called twice, no answer — retrying after lunch"
                hint="Never shown to the requester — for staff reading this request later."
                persistent-hint
              ></v-textarea>
              <!-- Its own save path, not the reasonDialog's: a terminal
                   request (Resolved, Disapproved, Cancelled) shows no
                   approve/decline action at all, so this is the only way a
                   note typed here ever reaches the server. -->
              <div class="d-flex align-center gap-3 mt-2">
                <v-btn
                  variant="outlined" color="primary" size="small" class="text-none font-weight-bold"
                  :loading="noteSaving"
                  @click="saveInternalNote"
                >Save note</v-btn>
                <span v-if="noteSaved" class="text-caption text-success">Saved</span>
              </div>
            </div>

            <!-- One group, two tiles, site photo first: it is what the
                 resident is reporting and what decides whether a unit is
                 sent, and the ID answers a different question after it. -->
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
                    <!-- Always drawn, not only on hover: a hover-only
                         affordance tells a touch user nothing, and this is
                         the only cue that the tile opens anything. -->
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

    <!-- Attachment lightbox. Same shape as the vehicle picker below: v-dialog,
         rounded card, title row with a close button.

         It reads the blob URL the panel already holds rather than building one.
         The image sits behind an authenticated route and its storage path is
         hidden on the model, so there is no address a plain image tag could
         load. -->
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

    <!-- Vehicle picker -->
    <v-dialog v-model="vehicleModal.isOpen" max-width="600" :persistent="selectedRequest?.status === 'Booked'">
      <v-card rounded="lg" elevation="6">
        <v-card-title class="pa-4 border-b d-flex justify-space-between align-center">
          <span class="text-h6 font-weight-bold">
            {{ selectedRequest?.status === 'Booked' ? 'Approve & Assign Unit' : 'Available Vehicles' }}
          </span>
          <v-btn icon="mdi-close" variant="text" density="comfortable" @click="vehicleModal.isOpen = false"></v-btn>
        </v-card-title>

        <!-- Rendered here, not left to the detail panel's own alert (:361) —
             that one sits behind this dialog's scrim, so a failed approve
             would report an error nobody could see without closing the
             dialog first. -->
        <v-alert v-if="selectedRequest?.status === 'Booked' && apiError" type="error" variant="tonal" density="compact" class="ma-4 mb-0">{{ apiError }}</v-alert>

        <!-- A two-across grid of cards for a list of identical units. Each tile
             carried three words and the eye had to travel in two directions to
             compare fourteen of them. One column, one unit per row, matching the
             fleet list this picker is a view of. -->
        <v-card-text class="pa-0 subtle-surface" style="max-height: 400px; overflow-y: auto;">
          <div v-if="scheduledAvailabilityLoading" class="pa-4">
            <v-skeleton-loader type="list-item-avatar-two-line" v-for="n in 3" :key="n" class="mb-1"></v-skeleton-loader>
          </div>

          <!-- Booked/approve: every Ambulance unit is listed, not just the
               free ones — an unavailable unit shows why instead of vanishing,
               so a dispatcher who expects to see AMB-02 can tell "already
               booked" from "the list failed to load" (MDRRMO feedback,
               2026-09-18). Picking one here does not close the dialog —
               Approve below does, once a free unit is actually chosen. -->
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

        <!-- The dialog this collapses: picking a unit and approving used to be
             a picker modal plus a separate always-visible footer button
             elsewhere on the page (MDRRMO feedback, 2026-09-18) — both steps
             now live here, next to each other. -->
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

    <!-- Every approve and decline now stops here first. The remarks field on the
         detail panel was optional and skipped, so a disapproved request reached
         the resident's phone as a red status with nothing under it. Declining
         requires a reason; approving only asks for one, since a dispatched unit
         is its own explanation. Bulk decline gets one reason for the whole
         selection, which is the only thing it could ever have written -- it used
         to resend each row's existing remarks, so it captured nothing at all. -->
    <!-- persistent: the field below is the text a Head of the Family is shown,
         and a stray click on the scrim used to discard it with no warning. -->
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
            <!-- Approve's note is optional and used to be a permanent 3-row
                 textarea dominating what is otherwise one line plus a
                 confirm button — collapsed behind a toggle so the default
                 view is just the decision (MDRRMO feedback, 2026-09-18). A
                 decline's reason is required, so it stays always visible;
                 kind !== 'approve' covers both 'disapprove' and 'bulk'. -->
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
          <!-- Approve on a walk-in with no account: the note is optional and
               there is no app to show it in, so there is nothing to type. -->
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

    <!-- Resolve confirmation. Same shape as the panel's delete confirms
         (EquipmentInventoryView, ServicesConfigView, VehiclesView): a short
         v-dialog, the consequence spelled out in the body, Cancel beside a
         filled confirm. Not folded into reasonDialog above — that one exists
         to collect a reason string, and this collects nothing. It is a
         speed bump, and the only thing it has to do is not be one click. -->
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

    <!-- Reschedule a Booked request. Its own dialog rather than folding into
         reasonDialog above: that one collects one reason string for a status
         flip, this collects two datetimes plus a reason, and remarks here is
         required unconditionally, not gated on `kind`. -->
    <!-- persistent for two reasons: it holds a typed reason, and both date
         fields open a teleported menu — clicking a date in it registers as a
         click outside the dialog, which used to close it mid-edit. -->
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

    <!-- Ambulance Day View — every unit against one day's booked windows, read
         straight from GET /ambulance-availability?date=. A picture of the
         fleet's schedule, not an action surface: nothing in here writes
         anything, which is why it is its own dialog rather than folded into
         the detail panel above. -->
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
            <!-- Hour scale, shared by every track below it. -->
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

    <!-- Walk-in request. Two shapes of walk-in: an existing resident who came
         to the office instead of using the app, and someone with no account
         at all -- the toggle decides which half of the form is live, and
         only one half is ever sent. -->
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

          <!-- Fixed to Ambulance/Medical Response on this board — this dialog
               only ever files here when scope is ambulance, so there is
               nothing for the resident to pick. -->
          <v-select
            v-if="scope !== 'ambulance'"
            v-model="createDialog.form.service_id"
            :items="serviceOptions"
            label="Service"
            variant="outlined"
            density="comfortable"
            class="mb-2"
            :rules="[required]"
          ></v-select>

          <v-textarea
            v-if="scope !== 'ambulance'"
            v-model="createDialog.form.description"
            label="Description"
            placeholder="e.g. Fallen tree blocking the road at Purok 3"
            variant="outlined"
            density="comfortable"
            rows="3"
            class="mb-2"
            :rules="[required]"
          ></v-textarea>

          <!-- Ambulance only. Replaces the free-text description above with
               the same fields the paper Conduction Request Form and the
               mobile app's own AmbulanceFormData ask for, so what the
               resident's own story ("transfer to another hospital, no
               vehicle at home") becomes is structured data from the moment
               it is taken, not a paragraph parsed back apart at dispatch
               time. Server composes `description` from these — see
               ServiceRequestController::adminStore(). -->
          <template v-else>
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

            <!-- Who is travelling with the patient, asked here rather than at
                 dispatch. The trip record these used to live on does not
                 exist until the request reaches Responding, so anyone named
                 at the counter had nowhere to be written down until now.
                 At least one is required (MDRRMO, 2026-09-20: the hospital
                 asks for a companion), and the server refuses more than
                 two. -->
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
          </template>

          <template v-if="scope === 'ambulance'">
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
import { displayPhone } from '@/composables/phoneNumber'
import { outcomeLabel, isBookingOverdue, bookingCountdownLabel, pendingWaitLabel } from '@/composables/adminUi'
import { API_BASE } from '@/config/api'
import DateTimePickerField from '@/components/DateTimePickerField.vue'
import PageHeader from '@/components/PageHeader.vue'
import DataTablePage from '@/components/DataTablePage.vue'
import StatusPill from '@/components/StatusPill.vue'
import PersonCell from '@/components/PersonCell.vue'

// 'ambulance': only Ambulance/Medical Response requests, rendered as the
// Bookings tab on the Ambulance Dispatch Requests page. 'other': every
// other service, rendered as the Resident Requests page. Booked is a status
// only an ambulance booking can ever reach, so every Booked-specific branch
// below (reschedule, assign-unit, the day view) is simply unreachable in the
// 'other' instance rather than needing its own guard.
const props = defineProps({
  scope: { type: String, required: true },
  // False when embedded as a tab rather than owning the whole route — see
  // the root v-container above, which needs a real height instead of the
  // 100%-of-an-unbounded-ancestor that `fill-height` resolves to there.
  standalone: { type: Boolean, default: true },
})

// Fired instead of navigating cross-page: when scope is 'ambulance' this
// board sits inside a tab on the same page as the trip-log form, so the
// parent just switches tabs and opens its own create dialog prefilled from
// this booking, rather than the old /conduction-requests?dispatch=<id> hop.
const emit = defineEmits(['dispatch-booking', 'open-trip-record', 'trip-record-created'])

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

// Same exact-code match as the mobile app's formKindForServiceCode: only this
// one service carries a scheduling concept server-side today, and it's the
// one thing that decides which of the two boards a request belongs on.
const AMBULANCE_SERVICE_CODE = 'ambulance-medical-response'
const isAmbulanceRequest = (r) => r.service?.code === AMBULANCE_SERVICE_CODE

// The MDRRMO programs (trainings, drills, certification): services whose
// category is Programs. No unit ever goes out for one, so the queue shows a
// plain Approve/Disapprove instead of the dispatch panel. Read off the service's
// category column, so the office can move a service in or out in Manage Services.
const isProgramRequest = (r) => r?.service?.category === 'programs'
const ambulanceServiceId = computed(() => services.value.find(s => s.code === AMBULANCE_SERVICE_CODE)?.service_id ?? null)

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
  // Ambulance only — see the v-else block in the template above.
  patient_name: '',
  patient_age: null,
  patient_address: '',
  pickup_location: '',
  destination: '',
  condition_notes: '',
  // One blank slot, matching the trip log form's own repeater: "Add relative"
  // covers the case that needs more, and starting at two pads the common
  // one-relative trip with a field nobody fills.
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

// The orange inline warning (template above) used to be advisory only —
// staff could click File Request anyway and get the same rejection back as
// a red banner stacked on top of the warning that already explained it.
// This is what stops the click; the warning text itself is what explains why.
const noAmbulanceFreeForWindow = computed(() =>
  props.scope === 'ambulance'
  && createDialog.value.scheduleForLater
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
    // On the ambulance board there is no service picker to set this — see
    // the v-select's v-if in the template above.
    form: { ...emptyCreateForm(), service_id: props.scope === 'ambulance' ? ambulanceServiceId.value : null },
  }
  walkInAvailability.value = emptyWalkInAvailability()
}

// emptyCreateForm() builds a fresh array literal on every call, so each open
// gets its own — the shallow spread above never shares one between dialogs.
const addRelative = () => { createDialog.value.form.patient_relatives.push('') }
const removeRelative = (idx) => {
  const list = createDialog.value.form.patient_relatives
  list.splice(idx, 1)
  // Never leave the group with no field at all: an empty repeater reads as a
  // broken section rather than an optional one, and "Add relative" becomes
  // the only way back to a state the form started in.
  if (list.length === 0) list.push('')
}

// Matches ServiceRequestController::MINIMUM_LEAD_TIME_HOURS — sized here only
// to grey out an unreachable pick, the server still decides for real.
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
    // The staffer may have changed the pick again while this was in flight.
    if (token !== walkInAvailabilityToken) return
    walkInAvailability.value = { checking: false, checked: true, freeCount: Array.isArray(units) ? units.length : 0 }
  } catch {
    if (token !== walkInAvailabilityToken) return
    walkInAvailability.value = emptyWalkInAvailability()
  }
}

// `kind` is the whole state machine: 'approve' and 'disapprove' act on the
// selected request, 'bulk' on every ticked row. Only 'approve' may fire with an
// empty reason.
const emptyReason = () => ({ open: false, kind: 'disapprove', reason: '', error: '' })
const reasonDialog = ref(emptyReason())

// Approve's note is optional (a dispatched unit is its own explanation —
// see reasonCopy) and used to sit as a permanent 3-row textarea dominating
// a dialog that is otherwise one line of context and a confirm button
// (MDRRMO feedback, 2026-09-18). Collapsed behind this toggle so the
// default view is just the decision; a decline's reason stays always
// visible below, since that one is required, not decoration.
const noteExpanded = ref(false)

// Resolve is terminal and the panel offers no way back, so it gets a
// confirmation rather than firing on the click. `label` is captured at open
// time purely so the dialog can name the request in its own copy; the resolve
// itself still reads selectedRequest, exactly as the bare click did.
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
  // updateStatus never throws — it catches and reports through apiError — so
  // that is the only honest success signal here. Checking the request's own
  // status instead would misread the case where resolving succeeds and the
  // row then leaves the active filter, leaving selectedRequest null.
  //
  // Left open on failure so the resolve gate's refusal ("Cannot resolve —
  // missing arrival time, a driver.") is read against the dialog that
  // explains what the click was for, rather than over a closed one.
  if (!apiError.value) {
    resolveDialog.value.open = false
  }
}

// Disapprove's reason is required at the API regardless of who asked — it is
// the audit record of why, even for a walk-in with no account to read it. So
// only 'approve' ever hides the field entirely; disapprove and bulk always
// show it, with an honest hint about where it actually goes.
const reasonCopy = computed(() => {
  const req = selectedRequest.value
  const who = req && requesterName(req) !== 'Unknown Head of the Family' && requesterName(req) !== 'Unknown requester' ? requesterName(req) : ''
  const what = selectedRequest.value?.service?.service_name || 'this service'
  const hasAccount = req ? !isWalkIn(req) : true
  const appHint = 'Shown to the Head of the Family in the mobile app.'
  const noAppHint = 'No linked account — kept as an internal record only, not shown to anyone.'
  switch (reasonDialog.value.kind) {
    case 'approve': {
      // Only a non-ambulance request reaches this with no unit — the
      // ambulance button stays disabled until one is picked — and saying a
      // unit "will be sent" there promised something nobody was sending.
      const unit = getSelectedVehicleName()
      const note = { label: 'Note for the Head of the Family (optional)', hint: appHint, showField: hasAccount }
      // A program has no unit to send, so it gets no "without a vehicle" wording.
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
    // Always starts blank. This used to pre-fill from the panel's own
    // "Admin remarks" box, which is why an operator's internal shorthand
    // could reach a resident's phone unedited — that box is now
    // formData.internal_notes, a separate column the API never returns to
    // a resident, and the two must not feed each other again.
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
  // The panel's own field is the source of truth for `updateStatus`, so it moves
  // with the dialog rather than the two drifting apart.
  formData.value.remarks = trimmed
  return updateStatus(kind === 'approve' ? 'Responding' : 'Disapproved')
}

// C5's bridge: adminIndex() eager-loads conductionRequests.people, so the
// stub created at Approve & Dispatch is already sitting on the row by the
// time this renders — no second round trip. `?.[0]` rather than a find: one
// service request has at most one trip in practice (createConductionStub is
// guarded on conductionRequests()->exists()), and the relation has no other
// row to prefer.
const respondingTrip = computed(() => selectedRequest.value?.conduction_requests?.[0] ?? null)
const tripDriverNames = computed(() =>
  (respondingTrip.value?.people || []).filter(p => p.role === 'driver').map(p => p.name).join(', ')
)
// trip_status is one of ConductionRequest::getTripStatusAttribute()'s three
// values ('Not dispatched' | 'In transit' | 'Completed'), always present —
// it's a model $appends, not conditionally selected. Only 'Completed' means
// the record is actually done; missing entirely, still open, or never
// started are all the same "needs attention" bucket the warning color is for.
// Only Responding-or-Resolved-with-nothing-on-file is an actual problem —
// not-yet-dispatched and closed-before-dispatch are the request working
// exactly as expected, not something to flag.
const tripRecordAlertType = computed(() => {
  if (respondingTrip.value) {
    return respondingTrip.value.trip_status === 'Completed' ? 'success' : 'warning'
  }
  return ['Responding', 'Resolved'].includes(selectedRequest.value?.status) ? 'warning' : 'info'
})

// Two attachments hang off a request now: the resident's ID and, optionally, a
// photo of the scene. Both live on the private disk and both are served only by
// an authenticated route, so both need the same fetch-as-a-blob treatment. One
// factory rather than a second hand-written copy — the copy is where the rule
// that every early return must release the previous blob gets forgotten.
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
      // The selection moved on while this was in flight; the blob belongs to a
      // request that is no longer on screen.
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

// preferred_date is a bare YYYY-MM-DD, so it is read as a local date rather than
// through Date's UTC parsing, which would show the day before in Manila.
const formatPreferredDate = (value, month = 'long') => {
  const [y, m, d] = String(value).split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('en-PH', { year: 'numeric', month, day: 'numeric' })
}

const validId = createAttachment('valid-id', 'Could not load the attached ID.')
// The route segment and the `site_photo` column keep their names -- this is a
// label change, not an API one.
const sitePhoto = createAttachment('site-photo', 'Could not load the landmark photo.')
// The request letter of a training or drill. A scan (image) or a PDF; a PDF
// opens in a new tab instead of the lightbox.
const letter = createAttachment('letter', 'Could not load the request letter.')

// Booked is a status only an ambulance booking can ever reach — showing the
// chip on the 'other' board would be a filter that always reads zero.
const statusTabs = computed(() => props.scope === 'ambulance'
  ? ['All', 'Pending', 'Booked', 'Responding', 'Resolved', 'Disapproved', 'Cancelled']
  : ['All', 'Pending', 'Responding', 'Resolved', 'Disapproved', 'Cancelled'])

// SegmentedTabs' own {value, label, count} shape, built off statusTabs and
// requestCounts (declared further down — safe to reference here since this
// only runs when the computed is first read, after the whole script has run).
const statusTabItems = computed(() =>
  statusTabs.value.map((status) => ({ value: status, label: status, count: requestCounts.value[status] })),
)

// Read off the requests actually on screen, not a barangay master list —
// this filter should only ever offer a value that narrows the result to
// something, never a barangay with zero requests sitting in the dropdown.
const barangayOptions = computed(() => {
  const names = new Set(requests.value.map(r => r.resident?.barangay?.barangay_name).filter(Boolean))
  return ['All', ...Array.from(names).sort()]
})

// Same fleet split requesterBarangay/availableVehicles already use: an
// ambulance board only ever assigns an Ambulance, the other board only ever
// assigns something else. 'Unassigned' is its own option, not folded into
// 'All' — "show me the ones nobody has dispatched yet" is a real question a
// dispatcher asks.
const unitOptions = computed(() => {
  const pool = props.scope === 'ambulance'
    ? vehicles.value.filter(v => v.type === 'Ambulance')
    : vehicles.value.filter(v => v.type !== 'Ambulance')
  return ['All', 'Unassigned', ...pool.map(v => v.unit_identifier)]
})

// Column keys point at plain string fields (added below in
// filteredAndSortedRequests's own map step) rather than accessor functions,
// so v-data-table's native sort-by can compare them directly without a
// Vuetify-version-specific function-value API.
// Every column carries a declared width, paired with DataTablePage's
// `table-layout: fixed` — without both, columns size off the visible text and
// jump on every filter or search (MDRRMO feedback, 2026-09-18). Percentages,
// not px: the px set summed to 1028px and overflowed the panel below ~1450px.
const tableHeaders = computed(() => [
  { title: '', key: 'select', sortable: false, width: '48px' },
  { title: 'Status', key: 'status', width: '13%' },
  { title: 'Scheduled', key: 'scheduled_at', width: '18%' },
  { title: 'Requester', key: '_requesterName', width: '22%' },
  { title: props.scope === 'ambulance' ? 'Barangay' : 'Service', key: '_secondary', width: '16%' },
  { title: 'Patient', key: 'patient_name', width: '15%' },
  { title: 'Unit', key: '_unit', width: '12%' },
])

// Dashboard KPI cards deep-link here with ?status=Pending — honor it once on
// arrival so the operator lands on the filtered view, not "All".
if (statusTabs.value.includes(route.query.status)) filters.status = route.query.status

const itemId = (item) => item.request_id || item.id

// One order for a person's name across both panes. The list used to invert it
// to "Last, First" while the detail beside it read "First Last" -- the same
// resident, written two ways, six inches apart.
const residentName = (resident) =>
  `${resident?.first_name || ''} ${resident?.last_name || ''}`.trim() || 'Unknown Head of the Family'

// A walk-in with no account carries no `resident` object at all — these read
// walk_in_name/walk_in_contact_number instead, so the list row, the detail
// panel, search and the CSV export all show the same person the same way
// regardless of which kind of request it is.
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

// A real comparison, not a relabel: ADDRESS/LANDMARK/PICKUP used to show
// the same text three times under three different labels (MDRRMO feedback,
// 2026-09-18). Whitespace-normalized equality or containment either way —
// "beside the barangay hall" typed as the whole pickup value and again as
// just the landmark note should still count as the same fact, not two.
const isLandmarkRedundant = (landmark, pickup) => {
  if (!landmark || !pickup) return false
  const normalize = (s) => s.trim().toLowerCase().replace(/\s+/g, ' ')
  const a = normalize(landmark)
  const b = normalize(pickup)
  return a === b || a.includes(b) || b.includes(a)
}

const requestCounts = computed(() => {
  const counts = { All: requests.value.length, Pending: 0, Booked: 0, Responding: 0, Resolved: 0, Disapproved: 0, Cancelled: 0 }
  requests.value.forEach(req => {
    const status = req.status || 'Pending'
    if (counts[status] !== undefined) counts[status]++
  })
  return counts
})

// A Booked request's picker is scoped to its own window, not to "Available"
// right now: a unit that is Dispatched on an unrelated trip today can still
// be free for a booking days out, and one sitting idle right now can already
// be booked for that same future window. GET /ambulance-availability answers
// that; the generic Available-status filter below is wrong for this case and
// stays only for the unscheduled, immediate-dispatch flow it was built for.
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
    // /ambulance-availability's window form returns {vehicle_id,
    // unit_identifier, specification} only — cross-referenced against the
    // fleet already on screen for `type`, which vehicleIcon() needs, rather
    // than growing a second vehicle shape this list has to render.
    return scheduledAvailability.value
      .map(u => vehicles.value.find(v => v.vehicle_id === u.vehicle_id))
      .filter(Boolean)
  }
  // Keyed off the same `scope` prop that already separates the two boards.
  // The ambulance board only ever assigns a medical unit — without this
  // filter every Rescue Vehicle/Fire Truck/Boat showed up as a valid pick
  // for a medical dispatch (impeccable critique, P0, 2026-08-30). The other
  // board is the mirror image: it dispatches everything BUT an ambulance,
  // since ambulance requests never reach this board at all (scope='ambulance'
  // handles those on their own).
  //
  // The other board also narrows to the unit types the office mapped to this
  // service (Service Vehicles page). No mapping, or a mapping that failed to
  // load, means every non-ambulance unit — the server enforces the same list, so
  // this only saves the dispatcher a refusal.
  if (props.scope === 'ambulance') {
    return vehicles.value.filter(v => v.status === 'Available' && v.type === 'Ambulance')
  }

  const allowed = vehicleTypesByService.value[selectedRequest.value?.service?.code] ?? []
  return vehicles.value.filter(v =>
    v.status === 'Available' && v.type !== 'Ambulance' && (allowed.length === 0 || allowed.includes(v.type)),
  )
})

// service code -> the unit types the office allows on it. Best effort: it only
// narrows the picker, and the server refuses a wrong unit either way.
const vehicleTypesByService = ref({})
const fetchVehicleTypes = async () => {
  if (props.scope === 'ambulance') return
  try {
    const res = await fetch(`${API_BASE}/service-vehicle-types`, { headers: getHeaders(), signal: listAbortController.signal })
    if (!res.ok) return
    const body = await res.json()
    vehicleTypesByService.value = Object.fromEntries((body.data || []).map(row => [row.code, row.vehicle_types]))
  } catch (error) {
    if (error.name !== 'AbortError') console.error('Failed to fetch vehicle types:', error)
  }
}

/**
 * The Booked/approve picker's own list — every Ambulance unit, not just the
 * free ones, each tagged with why it can or cannot take this window (MDRRMO
 * feedback, 2026-09-18). Naming why a known unit is missing beats silently
 * omitting it; a dispatcher who expects to see AMB-02 and does not has no
 * way to tell "already booked" from "the list failed to load" otherwise.
 * Free units sort first so the common case is never scrolled past.
 */
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
    .sort((a, b) => Number(b.available) - Number(a.available))
})

const residentOptions = computed(() => residents.value
  .map(r => ({
    title: `${r.last_name}, ${r.first_name}${r.barangay?.barangay_name ? ' — ' + r.barangay.barangay_name : ''}`,
    value: r.resident_id,
  }))
  .sort((a, b) => a.title.localeCompare(b.title)))

// Only rendered on the 'other' board (see the v-select's v-if) — Ambulance
// requests always file from the ambulance board instead, so it never belongs
// in this list.
const serviceOptions = computed(() => services.value
  .filter(s => s.code !== AMBULANCE_SERVICE_CODE && s.is_active !== false)
  .map(s => ({ title: s.service_name, value: s.service_id })))

const selectedVehicle = computed(() =>
  vehicles.value.find(v => v.vehicle_id === formData.value.vehicle_id) || null
)

// The two attachment slots a request can carry, in reading order. There are
// exactly these two and never more: `tbl_service_request` holds `valid_id` and
// `site_photo` as scalar columns, both hidden on the model, each surfaced only
// as a `has_*` boolean. This is not a collection that might grow at runtime.
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

// Resolved from the live list rather than copied into the dialog, so the open
// lightbox cannot outlive the blob it is showing.
const lightboxAttachment = computed(() =>
  attachments.value.find(a => a.key === lightbox.value.key) || null
)

const openLightbox = (a) => { lightbox.value = { open: true, key: a.key } }

// The description is not free prose. The mobile app builds it as
// `metaLines.join('\n')` (Mobile/lib/models/service_forms.dart), and all four
// forms open with the service name and close with `Contact:` and `Submitted`.
// Every one of those three is already a header field a few inches above this
// card, so the panel was printing each of them twice and burying the resident's
// actual words between the copies.
//
// Stripped here rather than in the Flutter form on purpose. A mobile-side fix
// would only ever reach requests filed after it shipped, leaving every existing
// row duplicated; and the same `metaLines` are rendered back to the resident on
// their own tracking screen, where the contact and timestamp are not duplicates
// of anything on screen.
const META_TAIL = /^(contact:|submitted\b)/i

const descriptionLines = computed(() => {
  const raw = selectedRequest.value?.description
  if (!raw) return []

  const lines = raw.split('\n').map(line => line.trim()).filter(Boolean)
  const service = (selectedRequest.value?.service?.service_name || '').trim().toLowerCase()

  const kept = [...lines]
  if (service && kept[0]?.toLowerCase() === service) kept.shift()

  // Only from the end. The resident's own words sit in the middle of the block
  // and can legitimately begin with either word -- matching anywhere would eat
  // a sentence that happens to start "Contact the barangay hall first".
  while (kept.length > 0 && META_TAIL.test(kept.at(-1))) kept.pop()

  // A form submitted with nothing typed into it reduces to exactly the meta
  // lines, and stripping all of them would leave a blank card where there was
  // text a moment ago. Show what there is rather than nothing.
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
    // Plain fields so the table's native column sort can compare them
    // directly — see tableHeaders.
    _requesterName: requesterName(r),
    _secondary: props.scope === 'ambulance' ? requesterBarangay(r) : (r.service?.service_name || 'Other'),
    _unit: r.vehicle?.unit_identifier || '',
  })).sort((a, b) => {
    const statusA = a.status || 'Pending', statusB = b.status || 'Pending'
    if (statusA === 'Pending' && statusB !== 'Pending') return -1
    if (statusB === 'Pending' && statusA !== 'Pending') return 1
    return new Date(b.created_at) - new Date(a.created_at)
  })
})

// Names which of the two reasons the list is empty. A status chip reading
// zero and a search with no hits are different facts: one says nothing of
// that kind exists yet, the other says try a different search.
const emptyListMessage = computed(() => {
  if (search.value) return `No requests match "${search.value}"`
  if (filters.status !== 'All') return `No ${filters.status.toLowerCase()} requests`
  return 'No requests yet'
})

// Search's own chip is DataTablePage's job. Status is included even though
// the active SegmentedTabs item already shows it — Clear all needs a single
// place that knows every filter this page has, and a status left off this
// list would silently survive a Clear all click.
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
    || selectedRequest.value.status === 'Booked'
  )
)

// Writes what is on screen: the current status filter and search, in the order
// shown. Exporting all 30 rows regardless of the filter would be a different
// feature wearing the same button.
//
// Everything is quoted and every embedded quote is doubled -- descriptions are
// free text typed by residents, and one comma in one of them silently shifts
// every later column. The BOM is what makes Excel read it as UTF-8 rather than
// mangling the barangay names.
const csvCell = (value) => `"${String(value ?? '').replace(/"/g, '""')}"`

const exportCsv = () => {
  const rows = filteredAndSortedRequests.value
  if (rows.length === 0) return

  const header = ['Request ID', 'Head of the Family', 'Barangay', 'Phone', 'Service', 'Status', 'Vehicle', 'Submitted', 'Remarks', 'Description']
  const body = rows.map(r => [
    itemId(r),
    requesterName(r),
    r.resident?.barangay?.barangay_name || (isWalkIn(r) ? 'Walk-in' : ''),
    requesterPhone(r),
    r.service?.service_name || '',
    r.status || 'Pending',
    r.vehicle ? vehicleName(r.vehicle) : '',
    formatDateTime(r.created_at),
    r.remarks || '',
    r.description || '',
  ])

  // The BOM is written as an escape, never as a literal character: a bare
  // BOM in the source is invisible, and the next formatter to touch this file
  // eats it silently, taking Excel's UTF-8 detection with it.
  const csv = '\uFEFF' + [header, ...body].map(row => row.map(csvCell).join(',')).join('\r\n')
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }))
  const stamp = new Date().toISOString().slice(0, 10)
  const scope = filters.status === 'All' ? 'all' : filters.status.toLowerCase()

  const link = document.createElement('a')
  link.href = url
  link.download = `serbis-requests-${scope}-${stamp}.csv`
  link.click()
  URL.revokeObjectURL(url)
}

const isSelected = (item) => selectedRequest.value && itemId(selectedRequest.value) === itemId(item)

const toggleSelect = (item) => {
  const id = itemId(item)
  if (selectedIds.has(id)) selectedIds.delete(id)
  else selectedIds.add(id)
}

// Empty rather than adminUi's em dash: exportCsv() feeds these too, and a dash
// would land in the spreadsheet as a literal cell value.
const formatDate = (dateStr) => dateStr ? new Date(dateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : ''
const formatDateTime = (dateStr) => dateStr ? new Date(dateStr).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''
// scheduled_end shares a day with scheduled_at on every booking this renders
// for, so only the time carries new information.
const formatTime = (dateStr) => new Date(dateStr).toLocaleTimeString(undefined, { timeStyle: 'short' })

// Mirrors the `row-${status}` pattern the list rows already use, so the pill and
// the row's left border are driven by the same string and cannot disagree.
// Replaces getStatusColor: a Vuetify colour name only ever fed v-chip, whose
// tonal variant is what made these unreadable in the first place.
// Moved to composables/adminUi.ts — item 5 of the layout redesign needs the
// exact same mapping in ConductionRequestView.vue too, for the shared
// status vocabulary between the two tabs.

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
  letter.load(item, !!item?.has_letter)
}

const releaseAttachments = () => {
  validId.release()
  sitePhoto.release()
  letter.release()
}

// Aborted on unmount (below) so a component torn down mid-request — a quick
// nav away from Resident Requests or Ambulance Dispatch — doesn't have its
// list-load resolve into a ref nobody reads anymore.
const listAbortController = new AbortController()

// Mount only. vehicles/residents/services barely change mid-session — every
// write path used to re-pull all four on every approve/reject/reschedule/
// walk-in/bulk-disapprove, which is most of where ordinary navigation on
// this page burned its share of the admin-api rate limit (P1 audit,
// 2026-09-15). fetchRequests() below is what those call now.
const fetchData = async () => {
  try {
    const [reqRes, vehRes, resRes, svcRes] = await Promise.all([
      fetch(`${API_BASE}/admin/service-requests`, { headers: getHeaders(), signal: listAbortController.signal }),
      fetch(`${API_BASE}/vehicles`, { headers: getHeaders(), signal: listAbortController.signal }),
      fetch(`${API_BASE}/residents`, { headers: getHeaders(), signal: listAbortController.signal }),
      fetch(`${API_BASE}/services`, { headers: getHeaders(), signal: listAbortController.signal })
    ])
    const reqData = await reqRes.json()
    const vehData = await vehRes.json()
    const resData = await resRes.json()
    const svcData = await svcRes.json()
    // The endpoint is shared between both boards — this is the one place the
    // split actually happens. Everything downstream (counts, the list, CSV
    // export) only ever sees its own half.
    const allRequests = reqData.data || reqData
    requests.value = allRequests.filter(r => isAmbulanceRequest(r) === (props.scope === 'ambulance'))
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

// Every write on this page only ever changes tbl_service_request rows —
// vehicles/residents/services are untouched by an approve, reject,
// reschedule, walk-in filing or bulk-disapprove, so re-pulling them on every
// one of those was three unnecessary requests per write.
const fetchRequests = async () => {
  try {
    const reqRes = await fetch(`${API_BASE}/admin/service-requests`, { headers: getHeaders(), signal: listAbortController.signal })
    const reqData = await reqRes.json()
    const allRequests = reqData.data || reqData
    requests.value = allRequests.filter(r => isAmbulanceRequest(r) === (props.scope === 'ambulance'))

    selectDefaultOrRefreshSelection()
  } catch (error) {
    if (error.name === 'AbortError') return
    console.error('Failed to fetch service requests:', error)
  }
}

// Only ever refreshes an already-open selection — never picks a row on its
// own. This used to auto-select filteredAndSortedRequests[0] whenever
// nothing was selected, which opened the detail drawer on every page load
// before anything was clicked (MDRRMO feedback, 2026-09-18).
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
    // Always fresh from the row — this is the operator's own note, not part
    // of the reason-dialog round trip remarks above is kept for.
    internal_notes: item.internal_notes || '',
    vehicle_id: item.vehicle_id || null
  }
  loadAttachments(item)
}

// Called from ConductionRequestView.vue's Trip Logs tab ("Open Booking" on a
// linked trip's detail dialog) — the reverse of dispatch-booking/
// open-trip-record above, so the link between a trip and its booking goes
// both ways instead of only out from Bookings (item 7 of the layout
// redesign). Resets search and the status filter so the target row is
// actually visible in the list too, not just the detail panel — a
// lingering filter from whatever the operator was doing on this tab before
// would otherwise hide the row while still selecting it underneath.
const selectRequestById = (id) => {
  const item = requests.value.find((r) => itemId(r) === id)
  if (!item) return
  search.value = ''
  filters.status = 'All'
  selectRequest(item)
}

const selectVehicle = (id) => {
  formData.value.vehicle_id = id
  // A Booked (ambulance) pick stays open — Approve now lives in this same
  // dialog's footer, so picking a unit is a selection, not a submit. Every
  // other caller (the Pending board's plain "Select Vehicle") keeps the old
  // one-click-and-close behavior; its own Approve & Dispatch is a separate
  // button outside this dialog entirely.
  if (selectedRequest.value?.status !== 'Booked') {
    vehicleModal.value.isOpen = false
  }
}

// `tbl_vehicles` has no `plate_number` column and never has -- the unit is
// named by `unit_identifier` (AMB-01, BOT-02). Reading the missing field
// rendered "undefined (Ambulance)" on the assignment card, "Vehicle Unknown"
// on every dispatched request, and a blank heading on each picker tile, none
// of which looked like a bug worth filing. This is a rename in the panel; no
// column is added and no response shape changes.
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
      // C5's resolution gate answers with a specific ValidationException
      // message ("Cannot resolve — missing arrival time, ...") in
      // errors.status, not in the generic top-level `message` Laravel
      // sends for a validation failure ("The given data was invalid.").
      // Same errors-first pattern submitWalkIn/submitCreate already use.
      const firstError = errData.errors ? Object.values(errData.errors)[0]?.[0] : null
      throw new Error(firstError || errData.message || 'Failed to update request')
    }

    // The vehicle's own status used to be flipped here, by a second request.
    // The server now owns it: PUT /service-requests/{id} attaches the unit and
    // moves it to Dispatched, and returns it to Available on a terminal status.
    // Doing it from here could only ever handle the dispatch half — nothing was
    // releasing the unit afterwards, so the fleet drained one vehicle at a time.
    await fetchRequests()

    // Responding is the one transition that makes the server create a trip
    // stub (ServiceRequestController's C5 bridge) — Trip Logs keeps its own
    // copy of the list, fetched independently, so without this the new row
    // was invisible there until a full page reload.
    if (newStatus === 'Responding') {
      emit('trip-record-created')
    }

    reasonDialog.value.open = false
  } catch (error) {
    // The dialog stays open on failure. Closing it would drop a typed reason on
    // the floor, and the operator would have to write it again from memory.
    apiError.value = error.message
    reasonDialog.value.error = error.message
  } finally {
    loading.value = false
  }
}

// The only save path for a note typed while the request has no visible
// action button at all (Booked, Resolved, Disapproved, Cancelled all hide
// the reasonDialog trigger). Sends internal_notes alone — no status, no
// vehicle_id — so update()'s partial-body support means nothing else on the
// request moves.
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

// Its own route, not update(): the server re-checks availability for the
// window under a lock before committing, since the picker above may already
// be stale by the time this fires.
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
    // Left open on failure — the error alert renders inside this same
    // dialog (below), and closing would hide it behind the scrim right as
    // it appears. Only a successful approve dismisses the dialog.
    vehicleModal.value.isOpen = false
  } catch (error) {
    apiError.value = error.message
  } finally {
    loading.value = false
  }
}

// Reschedule dialog — its own small form rather than folding into formData:
// remarks here is a required reason for THIS change, not the general-purpose
// admin note formData.remarks holds for update().
const emptyRescheduleForm = () => ({ scheduled_at: '', scheduled_end: '', remarks: '' })
const emptyRescheduleErrors = () => ({ scheduled_at: '', scheduled_end: '', remarks: '' })
// `errors` sits under the field the server named; `error` is the alert for
// anything with no field (a 422 message alone, a network failure).
const rescheduleDialog = ref({ open: false, form: emptyRescheduleForm(), errors: emptyRescheduleErrors(), error: '' })

// datetime-local wants "YYYY-MM DDTHH:mm" in whatever timezone the input is
// rendered in, which browsers treat as local — matching formatDateTime's own
// reliance on the browser's local time rather than a hardcoded Manila offset.
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

// Moving the start carries the end with it by the same amount, so the
// booking keeps its length — including a length the operator just typed into
// Ends. Left alone, Ends kept the old time and a later start failed as
// "scheduled_end must be after scheduled_at".
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

// Ambulance Day View — a read-only picture of one day's schedule, straight
// off GET /ambulance-availability?date=. Local-date arithmetic throughout,
// not UTC: the endpoint's own day boundary is Manila's, and this panel
// already assumes the office machine's local time IS Manila everywhere else
// (formatDateTime, formatDate) — matching that rather than hardcoding the
// offset a second way.
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

// The date field is its own trigger too — typing a date, not just the arrow
// buttons, has to refetch.
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

// Position and width as a percentage of the visible day, clamped to it — a
// window that starts before local midnight or ends after the next one still
// renders, just cut off at the track's own edges rather than overflowing it.
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

// A 2-hour segment is roughly 8% of the track — too narrow for
// "9:00 AM – 11:00 AM" at any legible size. Drops the minutes when both ends
// land on the hour (true for every booking this feature writes, since
// scheduled_end defaults to +2h) and the leading period when both ends share
// one, so a same-morning window reads "9–11 AM" instead of repeating it. The
// title attribute beside this still carries the full formatTime string for
// anything this still doesn't fit.
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
  if (!isResident && (!form.walk_in_name.trim() || !form.walk_in_contact_number.trim())) {
    createDialog.value.error = 'Name and contact number are required for someone with no account'
    return
  }
  if (!form.service_id) {
    createDialog.value.error = 'Pick a service'
    return
  }
  if (props.scope === 'ambulance') {
    if (!form.patient_name.trim() || !form.patient_address.trim() || !form.pickup_location.trim()
        || !form.destination.trim() || !form.condition_notes.trim()) {
      createDialog.value.error = 'Patient name, address, pickup, destination and condition are required'
      return
    }
    if (!form.patient_relatives.some(name => name.trim())) {
      createDialog.value.error = 'Name at least one relative or companion going with the patient'
      return
    }
  } else if (!form.description.trim()) {
    createDialog.value.error = 'Description is required'
    return
  }
  if (props.scope === 'ambulance' && createDialog.value.scheduleForLater && !form.scheduled_at) {
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
      body.append('walk_in_name', form.walk_in_name.trim())
      body.append('walk_in_contact_number', form.walk_in_contact_number.trim())
    }
    body.append('service_id', form.service_id)
    if (props.scope === 'ambulance') {
      // No 'description' — the server composes it from these, in the same
      // readable shape the mobile app's own AmbulanceFormData produces.
      // Dispatch itself now reads the structured columns directly
      // (ConductionRequestView.vue's openCreate) — this is for the request
      // detail panel's own "Description" display, not a parser anymore.
      body.append('patient_name', form.patient_name.trim())
      if (form.patient_age) body.append('patient_age', form.patient_age)
      body.append('patient_address', form.patient_address.trim())
      body.append('pickup_location', form.pickup_location.trim())
      body.append('destination', form.destination.trim())
      body.append('condition_notes', form.condition_notes.trim())
      // Blank slots are dropped here as well as server-side: an untouched
      // repeater must not post an empty name the backend then has to filter.
      form.patient_relatives
        .map(n => (n || '').trim())
        .filter(n => n !== '')
        .forEach(n => body.append('patient_relatives[]', n))
    } else {
      body.append('description', form.description.trim())
    }
    if (props.scope === 'ambulance' && createDialog.value.scheduleForLater && form.scheduled_at) {
      body.append('scheduled_at', form.scheduled_at.replace('T', ' ') + ':00')
    }

    // No 'Content-Type' — the browser sets the multipart boundary itself, and
    // overriding it with the JSON header used elsewhere in this file would
    // send a body no multipart parser can read.
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
    // `vehicle_id` is passed through untouched: the server releases the unit on
    // a terminal status, and sending null here would look like an unassignment.
    await Promise.all(targets.map(async (r) => {
      const res = await fetch(`${API_BASE}/service-requests/${itemId(r)}`, {
        method: 'PUT',
        headers: getHeaders(),
        body: JSON.stringify({ status: 'Disapproved', remarks: reason, vehicle_id: r.vehicle_id })
      })
      // `fetch` only rejects on a network failure, so without this a 422 on one
      // row resolved like a success and the whole batch reported as done.
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

// `load()` revokes the previous blob the moment the selection moves on, so an
// open lightbox would be left holding a dead blob: URL -- and if the next
// request carried the same kind of attachment it would quietly swap in a
// different resident's document under the same heading.
watch(selectedRequest, () => { lightbox.value = { open: false, key: null } })

watch(() => filters.status, () => { page.value = 1 })
watch(search, () => { page.value = 1 })
watch(() => filters.barangay, () => { page.value = 1 })
watch(() => filters.unit, () => { page.value = 1 })

onMounted(fetchData)
onMounted(fetchVehicleTypes)
onUnmounted(releaseAttachments)
onUnmounted(() => listAbortController.abort())

// Trip Logs' "Open Booking" reaches in through this. Last in setup because
// defineExpose runs inline, after every referenced const is declared.
defineExpose({ selectRequestById })
</script>

<style scoped>
.dashboard-bg {
  background-color: rgb(var(--v-theme-background));
}
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
.min-width-0 { min-width: 0; }

/* Centred modal, capped so it never exceeds the viewport — the body below
   (pa-6 overflow-y-auto flex-grow-1) is what actually scrolls. */
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

/* One unit per row in the picker, separated rather than floated. */
.vehicle-option {
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
.vehicle-option:last-child { border-bottom: none; }
.vehicle-option:hover { background-color: rgba(var(--v-theme-primary), 0.06); }

/* Rhythm for the detail column. A label sits 8px from the thing it labels; a
   group sits 28px from the next one. The old spacing used 8 and 16, and a
   group break only twice the size of a label break does not read as a break at
   all -- the whole panel came across as one column of text with some words in
   capitals. The rule before the action area gets the widest interval on the
   panel, because it is the only one separating what you read from what you
   decide. */
.detail-group { margin-bottom: 28px; }
.detail-group:last-child { margin-bottom: 0; }

/* Section headers (Action / Route / Patient & Requester / Notes &
   Attachments) — same treatment ConductionRequestView.vue's create/trip-log
   dialogs already use, reused rather than inventing a second convention
   (MDRRMO feedback, 2026-09-18). */
.section-title {
  font-size: 0.78rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgb(var(--v-theme-primary-strong));
  margin: 28px 0 12px;
}
.section-title:first-child { margin-top: 0; }

/* The unit summary holds the left end of the action row. It needs a floor so
   a long unit name truncates instead of squeezing the buttons, and a width it
   can claim once the row wraps on a narrow panel. */
.dispatch-state {
  flex: 1 1 200px;
}

.cursor-pointer {
  cursor: pointer;
}

/* The description is a short list of facts, not a paragraph — a little air
   between the lines so they read as separate ones. */
.description-line + .description-line {
  margin-top: 3px;
}

/* A real button, so it is in the tab order and announces itself, styled back
   down to a plain frame. The image fills it via v-img's `cover`. */
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

/* Drawn at rest. The scrim below only appears on hover or focus, which says
   nothing to a touch user, and this badge is then the only standing cue that
   the tile opens something. */
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

/* The scrim says the same thing louder, so the badge steps out from under it
   rather than sitting on top of its own replacement. */
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

/* Fixed column widths (tableHeaders' own width values) — DataTablePage's own
   .dtp-table :deep(table) rule now supplies table-layout: fixed. */

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
.request-row.row-booked { border-left-color: #6D28D9; }
.v-theme--dark .request-row.row-booked { border-left-color: #A78BFA; }
.request-row.row-responding { border-left-color: rgb(var(--v-theme-info)); }
.request-row.row-resolved { border-left-color: rgb(var(--v-theme-success)); }
.request-row.row-disapproved,
.request-row.row-cancelled { border-left-color: rgb(var(--v-theme-error)); }

/* The rows are now in the tab order, so they need a focus ring that is visible
   against both the hover tint and the selected tint. Inset, because an outline
   drawn outside the row is clipped by the scroll container. */
.request-row:focus-visible {
  outline: none;
  box-shadow: inset 0 0 0 2px rgb(var(--v-theme-primary));
  background-color: rgba(var(--v-theme-primary), 0.06);
}

/* The date earns a fixed column so truncation eats the service name and never
   the timestamp; without this the secondary line means different things on
   different rows. */
.row-date {
  flex: 0 0 auto;
  font-variant-numeric: tabular-nums;
  opacity: 0.85;
}

/* Status pills: .status-pill/.pill-* -- one definition now, in src/styles/settings.scss (was duplicated here and in ConductionRequestView.vue). */

/* Ambulance Day View. Booked segments reuse .pill-booked's exact violet — the
   same status already means "Booked" everywhere else on this page, so the
   track borrows its vocabulary rather than inventing a second color for the
   same fact. */
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
