<template>
  <v-container fluid class="dashboard-bg pa-0">
    <div class="d-flex flex-column w-100">

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

        <!-- The pill, then one line on the wait (Pending) or the countdown (Booked). -->
        <template v-slot:item.status="{ item }">
          <div class="status-col-pill d-flex flex-column align-start ga-1">
            <StatusPill small :status="outcomeLabel(item.status || 'Pending', item.conduction_requests?.[0]?.no_arrival_reason)" />
            <span v-if="timelineLine(item)" class="text-caption" :class="timelineLine(item).class">{{ timelineLine(item).text }}</span>
          </div>
        </template>

        <template v-slot:item.scheduled_at="{ item }">
          <div v-if="item.scheduled_at" class="d-flex align-center tabular" :class="{ 'text-error font-weight-bold': isBookingOverdue(item.status, item.scheduled_at) }">
            <v-icon size="12" class="mr-1 flex-shrink-0">mdi-calendar-clock</v-icon>{{ formatDateTime(item.scheduled_at) }}
          </div>
          <span v-else class="text-medium-emphasis">—</span>
        </template>

        <template v-slot:item._requesterName="{ item }">
          <PersonCell
            :name="item._requesterName"
            :secondary="item._phone"
            :initials="requesterInitials(item)"
            :title="item._requesterName"
          />
        </template>

        <template v-slot:item._secondary="{ item }">
          <span class="text-medium-emphasis text-truncate d-block" :title="item._secondary">{{ item._secondary }}</span>
        </template>

        <template v-slot:item.patient_name="{ item }">
          <span class="text-truncate d-block" :class="item.patient_name ? '' : 'text-medium-emphasis'" :title="item.patient_name">{{ item.patient_name || '—' }}</span>
        </template>

        <template v-slot:item._unit="{ item }">
          <div class="d-flex flex-column ga-1">
            <span v-if="item._unit" class="text-truncate d-block">{{ item._unit }}</span>
            <span v-else class="text-medium-emphasis">Unassigned</span>
            <v-chip
              v-if="needsNewUnit(item)"
              size="x-small" color="warning" variant="tonal" label
              :title="needsNewUnitTooltip(item)"
            >Needs new unit</v-chip>
          </div>
        </template>

        <template v-slot:item.chevron>
          <v-icon size="18" class="text-medium-emphasis">mdi-chevron-right</v-icon>
        </template>
      </DataTablePage>

      <DetailDrawer
        :model-value="!!selectedRequest"
        @update:model-value="(v) => { if (!v) closeDrawer() }"
        eyebrow="Ambulance request"
        :name="selectedRequest ? requesterName(selectedRequest) : ''"
        :initials="selectedRequest ? requesterInitials(selectedRequest) : ''"
        :secondary="drawerSecondary"
        :status-text="statusLine.text"
        :status-text-class="statusLine.class"
      >
        <template v-if="picker.open" #panel>
          <PickerDrawer
            single
            title="Select a unit"
            :subtitle="pickerSubtitle"
            :items="pickerItems"
            :selected="formData.vehicle_id ? [formData.vehicle_id] : []"
            empty-text="No free unit. Units are on a trip, booked at the same time, under maintenance, or set aside for other services."
            :error="apiError"
            @toggle="(item) => { formData.vehicle_id = item.id }"
            @back="closePicker"
            @done="donePicker"
          />
        </template>

        <template #status>
          <StatusPill v-if="selectedRequest" :status="outcomeLabel(selectedRequest.status || 'Pending', respondingTrip?.no_arrival_reason)" />
        </template>
        <template #actions>
          <ExportMenu v-if="selectedRequest" icon type="booking" :row="selectedRequest" />
        </template>

        <template v-if="selectedRequest">
          <v-alert v-if="apiError" type="error" variant="tonal" class="mb-4" density="compact" closable @click:close="apiError = ''">{{ apiError }}</v-alert>

          <section class="detail-section">
            <h3 class="sect-label">Request</h3>
            <dl class="kv">
              <dt>Service</dt><dd class="font-weight-bold">{{ selectedRequest.service?.service_name || 'Other' }}</dd>
              <dt>Transaction</dt><dd class="mono">{{ transactionNo(selectedRequest.request_id) }}</dd>
              <dt>Submitted</dt><dd>{{ formatDateTime(selectedRequest.created_at) }}</dd>
              <template v-if="selectedRequest.scheduled_at">
                <dt>Scheduled</dt>
                <dd>
                  {{ formatDateTime(selectedRequest.scheduled_at) }}
                  <template v-if="selectedRequest.scheduled_end"> to {{ formatTime(selectedRequest.scheduled_end) }}</template>
                </dd>
              </template>
              <template v-if="selectedRequest.approved_at">
                <dt>Approved</dt><dd>{{ formatDateTime(selectedRequest.approved_at) }}</dd>
              </template>
              <dt>Phone</dt>
              <dd>
                <a v-if="requesterPhone(selectedRequest) !== 'N/A'" :href="`tel:${requesterPhone(selectedRequest)}`" class="phone-link">{{ requesterPhone(selectedRequest) }}</a>
                <span v-else class="text-medium-emphasis">{{ NOT_RECORDED }}</span>
              </dd>
            </dl>
          </section>

          <section v-if="selectedRequest.pickup_location || selectedRequest.landmark || selectedRequest.destination" class="detail-section">
            <h3 class="sect-label">Route</h3>
            <dl class="kv">
              <dt>Pickup</dt><dd>{{ selectedRequest.pickup_location || selectedRequest.landmark || NOT_RECORDED }}</dd>
              <template v-if="selectedRequest.landmark && selectedRequest.pickup_location && !isLandmarkRedundant(selectedRequest.landmark, selectedRequest.pickup_location)">
                <dt>Landmark</dt><dd>{{ selectedRequest.landmark }}</dd>
              </template>
              <dt>Destination</dt><dd>{{ selectedRequest.destination || NOT_RECORDED }}</dd>
            </dl>
          </section>

          <section v-if="selectedRequest.patient_name" class="detail-section">
            <h3 class="sect-label">Patient</h3>
            <dl class="kv">
              <dt>Name</dt><dd class="font-weight-bold">{{ selectedRequest.patient_name }}</dd>
              <dt>Age</dt><dd>{{ selectedRequest.patient_age ?? NOT_RECORDED }}</dd>
              <dt>Address</dt><dd>{{ selectedRequest.patient_address || NOT_RECORDED }}</dd>
              <dt>Condition</dt><dd>{{ selectedRequest.condition_notes || NOT_RECORDED }}</dd>
            </dl>
          </section>

          <section v-else class="detail-section">
            <h3 class="sect-label">Description</h3>
            <div class="description-box">
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
            <div class="d-flex justify-space-between align-center mb-2">
              <h3 class="sect-label mb-0">Assignment</h3>
              <!-- Booked: the link in the heading. Pending has its button in the card. -->
              <v-btn
                v-if="isBooked"
                variant="text" color="primary-strong" density="comfortable" class="text-none font-weight-bold px-1"
                :loading="scheduledAvailabilityLoading" @click="openAssignUnitModal"
              >{{ selectedRequest.vehicle_id ? 'Reassign' : 'Select unit' }}</v-btn>
            </div>
            <!-- Closed before a unit was sent: one dashed line, nothing to show. -->
            <div v-if="isClosedUnassigned" class="assign-empty">
              {{ selectedRequest.status === 'Disapproved' ? 'Not assigned. Disapproved before a unit was sent.' : 'Not assigned.' }}
            </div>
            <template v-else>
              <div class="assign-box">
                <div class="assign-row">
                  <div class="min-width-0">
                    <div class="text-caption text-medium-emphasis">Unit</div>
                    <div :class="{ 'text-medium-emphasis': !assignedUnit }">
                      {{ assignedUnit ? `${vehicleName(assignedUnit)} · ${assignedUnit.type || 'Unit'}` : 'None assigned' }}
                    </div>
                  </div>
                  <v-btn
                    v-if="isPendingRequest"
                    color="primary-strong" variant="outlined" height="40" class="text-none font-weight-bold flex-shrink-0"
                    @click="picker.open = true"
                  >{{ formData.vehicle_id ? 'Change' : 'Select unit' }}</v-btn>
                </div>
              </div>
              <div v-if="needsNewUnit(selectedRequest)" class="text-caption text-warning-strong mt-2">{{ needsNewUnitTooltip(selectedRequest) }}</div>
              <div v-if="needsUnitToDispatch" id="dispatch-gate" class="text-caption text-medium-emphasis mt-2">
                {{ isPendingRequest ? 'Pick a unit to enable Approve &amp; dispatch.' : 'Pick a unit to enable Dispatch.' }}
              </div>
            </template>
          </section>

          <section v-if="closedLabel" class="detail-section">
            <h3 class="sect-label">Outcome</h3>
            <dl class="kv">
              <dt>{{ closedLabel }}</dt><dd>{{ formatDateTime(selectedRequest.resolved_at) || NOT_RECORDED }}</dd>
              <template v-if="selectedRequest.status === 'Disapproved' && selectedRequest.remarks">
                <dt>Reason</dt><dd>{{ selectedRequest.remarks }}</dd>
              </template>
            </dl>
          </section>

          <section class="detail-section">
            <h3 class="sect-label">Trip record</h3>
            <v-alert v-if="respondingTrip" :type="tripRecordAlertType" variant="tonal" border="start" rounded="lg" density="compact">
              <div class="text-body-2 font-weight-bold">
                {{ tripDriverNames || 'No driver recorded' }}
                <template v-if="respondingTrip.arrived_destination_at"> &middot; arrived {{ formatDateTime(respondingTrip.arrived_destination_at) }}</template>
              </div>
              <div v-if="respondingTrip.no_arrival_reason" class="text-body-2">No arrival: {{ respondingTrip.no_arrival_reason }}</div>
              <div class="text-body-2 mb-2">{{ odometerNote }}</div>
              <v-btn
                variant="outlined" color="primary-strong" height="40" class="text-none font-weight-bold"
                @click="emit('open-trip-record', respondingTrip.conduction_request_id)"
              >Open trip record</v-btn>
            </v-alert>
            <div v-else class="assign-empty">{{ noTripText }}</div>
          </section>

          <!-- Booked only: Pending has Disapprove in the footer, closed requests are done. -->
          <div v-if="isBooked" class="pt-2">
            <v-btn variant="text" color="error" class="text-none font-weight-bold px-1" :loading="loading" @click="openReason('disapprove')">
              Disapprove this booking
            </v-btn>
          </div>
        </template>

        <!-- Pending: Disapprove, Approve & dispatch. Booked: Reschedule, Dispatch. Responding: resolve. Closed: none. -->
        <template v-if="isPendingRequest" #footer>
          <v-btn color="error" variant="outlined" class="text-none font-weight-bold" height="40" :loading="loading" @click="openReason('disapprove')">
            Disapprove
          </v-btn>
          <v-spacer></v-spacer>
          <v-btn
            color="primary" variant="flat" class="text-none font-weight-bold" height="40"
            :loading="loading"
            :disabled="!formData.vehicle_id"
            :aria-describedby="!formData.vehicle_id ? 'dispatch-gate' : undefined"
            @click="openReason('approve')"
          >
            Approve &amp; dispatch
          </v-btn>
        </template>
        <template v-else-if="isBooked" #footer>
          <v-btn variant="outlined" color="primary-strong" class="text-none font-weight-bold" height="40" @click="openReschedule">
            Reschedule
          </v-btn>
          <v-spacer></v-spacer>
          <!-- UI-only gate: the booking write path does not require a unit to dispatch. -->
          <v-btn
            color="primary" variant="flat" class="text-none font-weight-bold" height="40"
            :disabled="!selectedRequest.vehicle_id"
            :aria-describedby="!selectedRequest.vehicle_id ? 'dispatch-gate' : undefined"
            @click="emit('dispatch-booking', selectedRequest)"
          >
            Dispatch
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
          <p class="mb-0">
            The trip log stays editable — return timestamps and odometer readings
            can still be filled in after this. It is the status that is permanent,
            not the record.
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

    <v-dialog v-model="rescheduleDialog.open" max-width="440" persistent>
      <v-card rounded="lg">
        <v-card-title class="d-flex justify-space-between align-center text-subtitle-1 font-weight-bold pa-5 pb-2 text-high-emphasis">
          <span class="text-h6 font-weight-bold">Reschedule booking</span>
          <v-btn
            icon="mdi-close" variant="tonal" rounded="circle" size="small" aria-label="Close"
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
            label="Starts"
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
            placeholder="The Head of the Family sees this message"
            hint="Required. The Head of the Family sees this and the activity log records it."
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
          <v-btn variant="outlined" color="primary" class="text-none font-weight-bold" height="44" @click="rescheduleDialog.open = false">Cancel</v-btn>
          <v-btn
            color="primary"
            variant="flat"
            class="px-6 text-none font-weight-bold"
            height="44"
            :loading="loading"
            @click="submitReschedule"
          >Reschedule</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <AmbulanceScheduleDialog v-model="scheduleOpen" />

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

          <v-row density="compact">
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
          <v-row density="compact">
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
              <v-btn variant="outlined" color="primary" size="small" density="compact" class="text-none" prepend-icon="mdi-plus" @click="addRelative">
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
                variant="tonal" rounded="circle"
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
          <v-btn variant="outlined" color="primary" class="text-none font-weight-bold" height="44" @click="createDialog.open = false">Cancel</v-btn>
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
import { useRoute, useRouter } from 'vue-router'
import { getToken } from '@/composables/authToken'
import { outcomeLabel, isBookingOverdue, bookingCountdownLabel, pendingWaitLabel, openWaitDays, waitTone, authHeaders, pluralize } from '@/composables/adminUi'
import { API_BASE } from '@/config/api'
import DateTimePickerField from '@/components/DateTimePickerField.vue'
import AmbulanceScheduleDialog from '@/components/AmbulanceScheduleDialog.vue'
import DataTablePage from '@/components/DataTablePage.vue'
import StatusPill from '@/components/StatusPill.vue'
import PersonCell from '@/components/PersonCell.vue'
import DetailDrawer from '@/components/DetailDrawer.vue'
import PickerDrawer from '@/components/PickerDrawer.vue'
import '@/components/detail-dialog.css'
import RequestFiltersBar from '@/components/RequestFiltersBar.vue'
import ExportMenu from '@/components/ExportMenu.vue'
import BulkSelectionBar from '@/components/BulkSelectionBar.vue'
import { requesterName, isWalkIn, requesterInitials, requesterPhone, requesterBarangay, vehicleName, vehicleIcon, getVehicleNameById, useDescriptionLines, useSelection, transactionNo } from '@/composables/requestDisplay'
import { useRequestAttachments } from '@/composables/useRequestAttachments'
import { useRequestFetch, AMBULANCE_SERVICE_CODE, itemId } from '@/composables/useRequestFetch'
import { useFilteredRequestList } from '@/composables/useFilteredRequestList'
import { useUpdateStatus } from '@/composables/useUpdateStatus'
import { useResolveDialog } from '@/composables/useResolveDialog'
import { emptyReasonDialog, useReasonActions } from '@/composables/useReasonActions'

const emit = defineEmits(['dispatch-booking', 'open-trip-record', 'trip-record-created'])

const route = useRoute()
const router = useRouter()
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
// The unit picker opens inside the drawer, replacing its body.
const picker = reactive({ open: false })
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

const { requests, vehicles, residents, services, listAbortController, refreshing, fetchData, fetchRequests, selectRequest } =
  useRequestFetch({ isAmbulance: true, initialLoad, apiError, formData, selectedRequest, loadAttachments })

const reasonDialog = ref(emptyReasonDialog())

const { updateStatus } = useUpdateStatus({
  selectedRequest, loading, apiError, formData, itemId, getHeaders, fetchRequests, reasonDialog,
  onResponding: () => emit('trip-record-created'),
})

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
            body: `This approves the request for ${what} and marks it Responding. No vehicle is assigned — cancel and use Select unit first if one is going out.`,
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

const CLOSED_LABELS = { Resolved: 'Resolved on', Disapproved: 'Disapproved on', Cancelled: 'Cancelled on' }
const closedLabel = computed(() => CLOSED_LABELS[selectedRequest.value?.status] || null)

const NOT_RECORDED = 'Not recorded'
const WAIT_CLASS = { muted: 'text-medium-emphasis', warning: 'text-warning-strong', error: 'text-error' }
const time = (d) => (d ? new Date(d).getTime() : 0)
// "17 hours", "3 days": hours under a day, days after.
const elapsed = (from, to = Date.now()) => {
  const hours = Math.max(0, Math.floor((time(to) - time(from)) / 36e5))
  return hours < 24 ? pluralize(Math.max(hours, 1), 'hour') : pluralize(Math.floor(hours / 24), 'day')
}
const shortDateTime = (d) => (d ? new Date(d).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '')
const respondingTrip = computed(() => selectedRequest.value?.conduction_requests?.[0] ?? null)

const isPendingRequest = computed(() => !selectedRequest.value?.status || selectedRequest.value.status === 'Pending')
// While Pending this is the unit picked so far (a draft until Approve & Dispatch saves it); otherwise the saved one.
const assignedUnit = computed(() => (isPendingRequest.value ? selectedVehicle.value : null) || selectedRequest.value?.vehicle || null)
// A stale server error should not outlive the choice that may have caused it.
watch(() => formData.value.vehicle_id, () => { apiError.value = '' })
const tripDriverNames = computed(() =>
  (respondingTrip.value?.people || []).filter(p => p.role === 'driver').map(p => p.name).join(', ')
)
const tripRecordAlertType = computed(() => (respondingTrip.value?.trip_status === 'Completed' ? 'success' : 'warning'))
// The odometer is optional on the trip log, so a blank is a fact to show, not an error.
const odometerNote = computed(() => {
  const t = respondingTrip.value
  if (t?.odometer_start == null && t?.odometer_end == null) return 'Odometer not recorded.'
  return `Odometer ${t.odometer_start ?? NOT_RECORDED} to ${t.odometer_end ?? NOT_RECORDED}`
})
const NO_TRIP_TEXT = {
  Responding: 'No trip record found for this request.',
  Resolved: 'Resolved with no trip record on file. Likely older data.',
  Disapproved: 'Closed before a trip was started.',
  Cancelled: 'Closed before a trip was started.',
}
const noTripText = computed(() => NO_TRIP_TEXT[selectedRequest.value?.status] || 'No trip record yet. One is created when you dispatch.')

const isBooked = computed(() => selectedRequest.value?.status === 'Booked')
const isClosedUnassigned = computed(() => !!closedLabel.value && !selectedRequest.value.vehicle)
const needsUnitToDispatch = computed(() =>
  (isPendingRequest.value && !formData.value.vehicle_id) || (isBooked.value && !selectedRequest.value.vehicle_id))

const drawerSecondary = computed(() => {
  const r = selectedRequest.value
  if (!r) return ''
  return isWalkIn(r) ? 'No account' : `Requester · ${requesterBarangay(r)}`
})

// The one line under the status pill in the drawer.
const statusLine = computed(() => {
  const r = selectedRequest.value
  const none = { text: '', class: null }
  if (!r) return none
  const status = r.status || 'Pending'
  if (status === 'Pending') {
    return { text: `Waiting ${elapsed(r.created_at)}`, class: WAIT_CLASS[waitTone(openWaitDays(status, r.created_at) ?? 0)] }
  }
  if (status === 'Booked') {
    if (!r.scheduled_at) return none
    const when = `Scheduled ${shortDateTime(r.scheduled_at)}`
    return isBookingOverdue(status, r.scheduled_at)
      ? { text: `${when} · ${elapsed(r.scheduled_at)} late, not dispatched`, class: 'text-error' }
      : { text: `${when} · in ${elapsed(Date.now(), r.scheduled_at)}`, class: null }
  }
  if (status === 'Responding') return { text: r.first_responded_at ? `Responding since ${shortDateTime(r.first_responded_at)}` : `Filed ${shortDateTime(r.created_at)}`, class: null }
  return r.resolved_at ? { text: `${status} ${shortDateTime(r.resolved_at)}`, class: null } : none
})

// The same idea for a list row: the wait while Pending, the countdown while Booked.
const timelineLine = (item) => {
  const wait = pendingWaitLabel(item.status, item.created_at)
  if (wait) return { text: wait, class: WAIT_CLASS[waitTone(openWaitDays(item.status, item.created_at) ?? 0)] }
  const eta = bookingCountdownLabel(item.status, item.scheduled_at, item.approved_at)
  return eta ? { text: eta, class: isBookingOverdue(item.status, item.scheduled_at) ? 'text-error' : 'text-medium-emphasis' } : null
}

const closeDrawer = () => {
  picker.open = false
  selectedRequest.value = null
}

const pickerItems = computed(() => availableVehicles.value.map((v) => ({
  id: v.vehicle_id,
  name: vehicleName(v),
  secondary: [v.type, v.specification].filter(Boolean).join(' · '),
  icon: vehicleIcon(v.type),
  status: 'Available',
})))
const pickerSubtitle = computed(() => `${pluralize(pickerItems.value.length, 'unit')} free for this request. Busy units are hidden.`)
// A Booked pick is saved on Done (it re-approves with the new unit); a Pending one is a draft until Approve.
const donePicker = () => {
  if (isBooked.value && formData.value.vehicle_id && formData.value.vehicle_id !== selectedRequest.value.vehicle_id) return approveBooking()
  picker.open = false
}
const closePicker = () => {
  if (isBooked.value) formData.value.vehicle_id = selectedRequest.value.vehicle_id || null
  picker.open = false
}

// Every tab keeps the shared order: Pending first on All, then newest first.
const sortCaption = computed(() => (filters.status === 'All' ? 'Sorted by: pending on top, then newest' : 'Sorted by: newest'))

const statusTabs = ['All', 'Pending', 'Booked', 'Responding', 'Resolved', 'Disapproved', 'Cancelled']

const statusTabItems = computed(() =>
  statusTabs.map((status) => ({ value: status, label: status, count: requestCounts.value[status] })),
)

const unitOptions = computed(() => {
  const pool = vehicles.value.filter(v => v.type === 'Ambulance')
  return ['All', 'Unassigned', ...pool.map(v => v.unit_identifier)]
})

const HEADER_WIDTH_TOTAL = 92
const tableHeaders = computed(() => {
  const columns = [
    // minWidth fits "TXN-000000" in the mono face plus sort icon, so the ID never ellipsizes.
    { title: 'Txn no.', key: 'request_id', width: 10, minWidth: '150px' },
    { title: 'Patient', key: 'patient_name', width: 12 },
    { title: 'Requester', key: '_requesterName', width: 16 },
    { title: 'Barangay', key: '_secondary', width: 11 },
    { title: 'Scheduled', key: 'scheduled_at', width: 15 },
    { title: 'Unit', key: '_unit', width: 9, sortable: false },
    { title: 'Status', key: 'status', width: 12, sortable: false },
  ]
  const scale = HEADER_WIDTH_TOTAL / columns.reduce((sum, c) => sum + c.width, 0)
  return [
    ...columns.map(c => ({ ...c, width: `${Math.round(c.width * scale * 10) / 10}%` })),
    { title: '', key: 'chevron', sortable: false, width: '40px' },
  ]
})

if (statusTabs.includes(route.query.status)) filters.status = route.query.status

// A Booked request's own vehicle went unavailable out from under it — pulled
// for another emergency, sent to Maintenance, etc. Informational only: this
// never blocks anything, it just tells staff a swap may be needed before the
// scheduled time.
const needsNewUnit = (item) => item.status === 'Booked' && !!item.vehicle && item.vehicle.status !== 'Available'
const needsNewUnitTooltip = (item) => `Assigned unit is currently ${item.vehicle.status}. Reassign or confirm it will be back in time.`

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
  picker.open = true
}

const availableVehicles = computed(() => {
  if (selectedRequest.value?.status === 'Booked') {
    return scheduledAvailability.value
      .map(u => vehicles.value.find(v => v.vehicle_id === u.vehicle_id))
      .filter(Boolean)
  }
  return vehicles.value.filter(v => v.status === 'Available' && v.type === 'Ambulance')
})

const residentOptions = computed(() => residents.value
  .map(r => ({
    title: `${r.last_name}, ${r.first_name}${r.barangay?.barangay_name ? ' — ' + r.barangay.barangay_name : ''}`,
    value: r.resident_id,
  }))
  .slice()
  .sort((a, b) => a.title.localeCompare(b.title)))

const selectedVehicle = computed(() =>
  vehicles.value.find(v => v.vehicle_id === formData.value.vehicle_id) || null
)

const { barangayOptions, requestCounts, filteredAndSortedRequests, emptyListMessage, activeFilters, clearFilter, clearAllFilters } =
  useFilteredRequestList(requests, filters, search, {
    requesterName,
    secondaryFn: requesterBarangay,
    decorate: (r) => ({
      _phone: requesterPhone(r),
    }),
  })

const summary = computed(() => {
  const total = filteredAndSortedRequests.value.length
  const noun = filters.status === 'All' ? 'requests' : filters.status.toLowerCase()
  if (!total) return `No ${noun}`
  const from = (page.value - 1) * itemsPerPage.value + 1
  return `Showing ${from} to ${Math.min(total, page.value * itemsPerPage.value)} of ${total} ${noun}`
})

// The page header's line: how many wait, and how long the oldest has.
const subtitle = computed(() => {
  const pending = requestCounts.value.Pending
  if (!pending) return 'No pending requests'
  const longest = Math.max(...requests.value.filter((r) => (r.status || 'Pending') === 'Pending').map((r) => openWaitDays('Pending', r.created_at) ?? 0))
  return `${pending} pending${longest ? ` · the longest has waited ${pluralize(longest, 'day')}` : ''}`
})

const formatDateTime = (dateStr) => dateStr ? new Date(dateStr).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''
const formatTime = (dateStr) => new Date(dateStr).toLocaleTimeString(undefined, { timeStyle: 'short' })

const selectRequestById = (id) => {
  const item = requests.value.find((r) => itemId(r) === id)
  if (!item) return
  search.value = ''
  filters.status = 'All'
  selectRequest(item)
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
    picker.open = false
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

// The Ambulance schedule popup (Day view button) loads its own data.
const scheduleOpen = ref(false)
const openDayView = () => { scheduleOpen.value = true }

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

watch(() => filters.status, () => { page.value = 1; selectedIds.clear() })
watch(search, () => { page.value = 1 })
watch(() => filters.barangay, () => { page.value = 1 })
watch(() => filters.unit, () => { page.value = 1 })

// ?open=<request_id>, from a link on another record (a trip's "Open booking").
// An ambulance booking opens here; any other request goes to its own board.
// The query is dropped once used, so a refresh does not reopen it.
const openLinked = () => {
  const id = Number(route.query.open)
  if (!id) return
  if (requests.value.some((r) => itemId(r) === id)) {
    const { open: _open, ...rest } = route.query
    selectRequestById(id)
    router.replace({ query: rest })
  } else {
    router.push({ path: '/manage-requests', query: { request: id } })
  }
}
watch(() => route.query.open, () => { if (!initialLoad.value) openLinked() })

onMounted(async () => {
  await fetchData()
  // Dashboard rows deep-link here with ?request=<request_id>.
  selectRequestById(Number(route.query.request))
  openLinked()
})
onUnmounted(releaseAttachments)
onUnmounted(() => listAbortController.abort())

// The page header (ConductionRequestView) draws these actions and the subtitle.
defineExpose({ selectRequestById, rows: filteredAndSortedRequests, selectedIds, openDayView, openCreateDialog, subtitle })
</script>

<style scoped src="@/styles/request-table.css"></style>

<style scoped>
.dashboard-bg {
  background-color: rgb(var(--v-theme-background));
}
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
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

/* Board type: dates in tabular figures. */
.tabular { font-variant-numeric: tabular-nums; white-space: nowrap; }
.request-table :deep(tbody tr.row-selected) { background-color: rgba(var(--v-theme-primary), 0.08); }

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
.assign-empty {
  padding: 14px 16px;
  border: 1px dashed rgba(var(--v-theme-on-surface), 0.2);
  border-radius: 12px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
</style>
