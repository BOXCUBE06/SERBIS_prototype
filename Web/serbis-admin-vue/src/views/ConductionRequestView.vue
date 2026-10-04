<template>
  <!-- Scrolls with the page like every other list route. The header's actions
       follow the tab: the bookings queue's on Bookings, the trip log's own on Trip logs. -->
  <v-container fluid class="bg-background">
    <PageHeader title="Ambulance Dispatch" class="mb-5">
      <template #subtitle>{{ activeTab === 'bookings' ? bookingsQueueRef?.subtitle : tripSubtitle }}</template>
      <template #actions>
        <template v-if="activeTab === 'bookings'">
          <v-btn variant="outlined" color="primary" class="text-none font-weight-bold" height="40" prepend-icon="mdi-calendar-clock" @click="bookingsQueueRef?.openDayView()">
            Day view
          </v-btn>
          <ExportMenu type="booking" :rows="bookingsQueueRef?.rows ?? []" :selected-ids="bookingsQueueRef?.selectedIds ?? NO_IDS" />
          <v-btn color="primary" variant="flat" class="text-none font-weight-bold" height="40" prepend-icon="mdi-plus" @click="bookingsQueueRef?.openCreateDialog()">
            Log service request
          </v-btn>
        </template>
        <template v-else>
          <ExportMenu type="trip" :rows="filteredItems" :selected-ids="selectedIds" show-selection />
          <v-btn color="primary" variant="flat" class="text-none font-weight-bold" height="40" prepend-icon="mdi-plus" @click="openCreate()">
            New trip record
          </v-btn>
        </template>
      </template>
    </PageHeader>

    <!-- Bookings: the resident-facing request/approval flow, filtered to
         Ambulance/Medical Response — moved here from Resident Requests so
         staff have one place for everything ambulance. Trip Logs: the
         dispatch record itself, unchanged, for a unit that is actually
         rolling. -->
    <SegmentedTabs
      v-model="activeTab"
      switch-style
      :items="[{ value: 'bookings', label: 'Bookings' }, { value: 'trip-logs', label: 'Trip logs' }]"
      class="mb-5"
    />

    <v-window v-model="activeTab">
      <v-window-item value="bookings">
        <AmbulanceRequestQueue ref="bookingsQueueRef" @dispatch-booking="handleDispatchBooking" @open-trip-record="handleOpenTripRecord" @trip-record-created="fetchData" />
      </v-window-item>

      <v-window-item value="trip-logs">
        <v-alert v-if="apiError && !createDialog.open && !detail.open" type="error" variant="tonal" density="compact" closable class="mb-4" @click:close="apiError = ''">
          {{ apiError }}
        </v-alert>

        <v-card v-if="loadError" elevation="0" border rounded="lg" class="bg-surface">
          <div class="text-center py-12 px-6">
            <v-icon size="40" aria-hidden="true" class="text-error mb-2">mdi-cloud-off-outline</v-icon>
            <div class="text-body-1 font-weight-bold text-high-emphasis">Could not load ambulance trip records</div>
            <div class="text-body-2 text-medium-emphasis mb-4">{{ loadError }}</div>
            <v-btn color="primary" variant="flat" class="text-none font-weight-bold px-6" height="44" :loading="reloading" @click="fetchData">
              Try again
            </v-btn>
          </div>
        </v-card>

        <!-- One table at every width — it scrolls horizontally rather than
             switching to a separate card-list renderer below lgAndUp
             (dropped; see .conduction-table's min-width in this file's
             <style> for the scroll threshold). -->
        <DataTablePage
          v-else
          compact
          filter-bar
          selectable
          :selected="[...selectedIds]"
          @update:selected="setSelected"
          :loading="initialLoad"
          :refreshing="reloading"
          v-model:search="search"
          search-placeholder="Search patient, origin or destination"
          :tabs="statusTabItems"
          :status="statusFilter"
          @update:status="statusFilter = $event"
          :headers="headers"
          :items="filteredItems"
          item-value="conduction_request_id"
          :row-props="rowProps"
          no-data-text="No ambulance trip records yet"
          :page="page"
          @update:page="page = $event"
          :items-per-page="itemsPerPage"
          @update:items-per-page="itemsPerPage = $event"
          result-noun="trip records"
          class="conduction-table"
          :active-filters="activeFilters"
          @clear-filter="clearFilter"
          @clear-all="clearAllFilters"
          @click:row="(_e, { item }) => openDetail(item)"
        >
          <template v-slot:filters>
            <v-select v-model="vehicleFilter" :items="vehicleFilterOptions" prefix="Unit" aria-label="Unit" variant="outlined" density="compact" hide-details rounded="lg" class="filter-bar__select"></v-select>
            <v-select v-model="departedRange" :items="DEPARTED_RANGES" prefix="Departed" aria-label="Departed" variant="outlined" density="compact" hide-details rounded="lg" class="filter-bar__select"></v-select>
          </template>

          <template v-slot:item.patient="{ item }">
            <PersonCell
              :name="item.patient_name || 'Unnamed patient'"
              :initials="nameInitials(item.patient_name)"
              :secondary="item.patient_contact_number"
            />
          </template>

          <template v-slot:item.service_request_id="{ item }">
            <span class="mono txn">{{ item.service_request_id ? transactionNo(item.service_request_id) : '—' }}</span>
          </template>

          <template v-slot:item.vehicle_label="{ item }">
            <span class="text-body-2 cell-truncate">{{ tripVehicleLabel(item) }}</span>
          </template>

          <template v-slot:item.departed_office_at="{ item }">
            <span class="text-body-2 tabular" :class="{ 'text-medium-emphasis': !item.departed_office_at }">{{ fmtDateTime(item.departed_office_at) || 'Not yet' }}</span>
          </template>

          <template v-slot:item.returned_office_at="{ item }">
            <span class="text-body-2 tabular" :class="{ 'text-medium-emphasis': !item.returned_office_at }">{{ fmtDateTime(item.returned_office_at) || 'Not yet' }}</span>
          </template>

          <template v-slot:item.trip_status="{ item }">
            <StatusPill small :status="pillStatus(item)" :label="outcomeLabel(tripStatusLabel(item.trip_status), item.no_arrival_reason)" />
          </template>

          <template v-slot:item.chevron>
            <v-icon size="18" class="text-medium-emphasis">mdi-chevron-right</v-icon>
          </template>

          <template v-slot:no-data>
            <div class="text-center py-12">
              <v-icon size="40" class="text-medium-emphasis mb-2">mdi-ambulance</v-icon>
              <div class="text-body-2 font-weight-bold text-high-emphasis">No ambulance trip records yet</div>
            </div>
          </template>
        </DataTablePage>
      </v-window-item>
    </v-window>

    <!-- New trip record -->
    <DetailDrawer
      v-model="createDialog.open"
      persistent
      eyebrow="New trip record"
      name="Ambulance trip record"
      initials="+"
      secondary="Log a trip that was not booked through the app, or link one that was."
    >
      <v-alert v-if="apiError" type="error" variant="tonal" density="compact" class="mb-4">{{ apiError }}</v-alert>

      <v-form ref="createForm">
        <!-- C6: the one way this form ever links to a booking, whether
             reached by typing here or by the Dispatch button (which just
             pre-selects this same field — see openCreate). Left empty on
             purpose is the legitimate walk-up-emergency case: a trip
             record with no booking behind it at all. -->
        <v-alert v-if="bookingsError" type="warning" variant="tonal" border="start" density="compact" class="mb-2">
          Could not load approved requests to link: {{ bookingsError }}
          <template v-slot:append>
            <v-btn variant="outlined" color="primary" size="small" class="text-none font-weight-bold" :loading="bookingsLoading" @click="fetchBookings">Retry</v-btn>
          </template>
        </v-alert>
        <v-autocomplete
          :model-value="createDialog.form.service_request_id"
          @update:model-value="onLinkBooking"
          :items="bookingOptions"
          label="Link to an approved request (optional)"
          placeholder="Search by name or date"
          variant="outlined"
          density="comfortable"
          clearable
          class="mb-2"
        ></v-autocomplete>
        <!-- Prefilled from the booking's own structured columns
             (ServiceRequestController::adminStore()) — nothing here is
             locked, so this is a note to the operator, not a guarantee. -->
        <div v-if="createDialog.form.service_request_id" class="text-caption text-medium-emphasis mb-4">
          Linked to booking {{ transactionNo(createDialog.form.service_request_id) }}. Prefilled from the booking, so check every field before saving.
        </div>

        <section class="detail-section">
          <h3 class="sect-label">Patient</h3>
          <v-row density="compact">
            <v-col cols="12" sm="8">
              <v-text-field v-model="createDialog.form.patient_name" label="Name" placeholder="Juan Dela Cruz" variant="outlined" density="comfortable" :rules="[required]"></v-text-field>
            </v-col>
            <v-col cols="6" sm="4">
              <v-text-field v-model="createDialog.form.patient_age" label="Age" placeholder="45" type="number" min="0" max="150" variant="outlined" density="comfortable"></v-text-field>
            </v-col>
            <v-col cols="12">
              <v-text-field v-model="createDialog.form.patient_address" label="Address" placeholder="Purok 3, San Isidro" variant="outlined" density="comfortable" :rules="[required]"></v-text-field>
            </v-col>
            <v-col cols="12">
              <v-text-field v-model="createDialog.form.patient_contact_number" label="Contact number" placeholder="09171234567" variant="outlined" density="comfortable" :rules="[required]"></v-text-field>
            </v-col>
            <v-col cols="12">
              <v-textarea v-model="createDialog.form.medical_diagnosis" label="Medical diagnosis" placeholder="Suspected stroke" variant="outlined" density="comfortable" rows="2" :rules="[required]"></v-textarea>
            </v-col>
          </v-row>
        </section>

        <section class="detail-section">
          <h3 class="sect-label">Trip</h3>
          <v-row density="compact">
            <v-col cols="12" sm="6">
              <v-text-field v-model="createDialog.form.origin" label="From" placeholder="San Isidro" variant="outlined" density="comfortable" :rules="[required]"></v-text-field>
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="createDialog.form.destination" label="To" placeholder="Echague District Hospital" variant="outlined" density="comfortable" :rules="[required]"></v-text-field>
            </v-col>
            <v-col v-if="vehiclesError" cols="12">
              <v-alert type="warning" variant="tonal" border="start" density="compact">
                Could not load the fleet list: {{ vehiclesError }}
                <template v-slot:append>
                  <v-btn variant="outlined" color="primary" size="small" class="text-none font-weight-bold" :loading="vehiclesLoading" @click="fetchVehicles">Retry</v-btn>
                </template>
              </v-alert>
            </v-col>
            <!-- A linked booking runs on its own approved unit; the server
                 ignores any other, so it is shown, not picked. -->
            <v-col v-if="createDialog.form.service_request_id" cols="12">
              <v-text-field
                :model-value="tripVehicleLabel(createDialog.form)"
                label="Assigned unit"
                variant="outlined"
                density="comfortable"
                readonly
              ></v-text-field>
            </v-col>
            <v-col v-else cols="12">
              <v-select
                v-model="createDialog.form.vehicle_id"
                :items="vehicleOptions"
                label="Fleet unit"
                variant="outlined"
                density="comfortable"
                clearable
                @update:model-value="onSelectFleetVehicle"
              ></v-select>
            </v-col>
            <!-- Fallback only, shown while no fleet unit is picked above —
                 see the comment on onSelectFleetVehicle. Not the default:
                 the picker is, since it is what the double-booking guard
                 below can actually check. -->
            <v-col v-if="!createDialog.form.vehicle_id && !createDialog.form.service_request_id" cols="12">
              <v-text-field
                v-model="createDialog.form.vehicle"
                label="Other vehicle (not in the fleet)"
                placeholder="Alicia MDRRMO Ambulance"
                variant="outlined"
                density="comfortable"
              ></v-text-field>
            </v-col>
          </v-row>
        </section>

        <section class="detail-section">
          <h3 class="sect-label">Crew</h3>
          <div v-for="group in personnelGroups" :key="group.field" class="mb-4">
            <div class="d-flex align-center justify-space-between mb-1">
              <span class="text-caption font-weight-bold text-uppercase text-medium-emphasis">{{ group.label }}</span>
              <v-btn
                variant="outlined" color="primary"
                size="small"
                density="compact"
                class="text-none"
                prepend-icon="mdi-plus"
                :disabled="createDialog.form[group.field].length >= group.max"
                @click="addPerson(group.field)"
              >
                Add {{ group.singular }}
              </v-btn>
            </div>
            <div
              v-for="(_n, idx) in createDialog.form[group.field]"
              :key="idx"
              class="d-flex align-center gap-2 mb-2"
            >
              <v-autocomplete
                v-if="group.field === 'drivers'"
                v-model="createDialog.form[group.field][idx]"
                :items="driverOptionsFor(createDialog.form[group.field][idx])"
                item-title="title"
                item-value="value"
                :label="`${group.singular} ${idx + 1}`"
                placeholder="Select a responder"
                variant="outlined"
                density="compact"
                hide-details
                clearable
              >
                <template v-slot:item="{ item, props }">
                  <v-list-item v-bind="props" :title="item.title" :subtitle="item.position"></v-list-item>
                </template>
              </v-autocomplete>
              <ResponderCombobox
                v-else-if="group.field === 'authorized_passengers'"
                v-model="createDialog.form[group.field][idx]"
                :items="passengerOptionsFor(createDialog.form.drivers)"
                :label="`${group.singular} ${idx + 1}`"
              />
              <v-text-field
                v-else
                v-model="createDialog.form[group.field][idx]"
                :label="`${group.singular} ${idx + 1}`"
                placeholder="Full name"
                variant="outlined"
                density="compact"
                hide-details
              ></v-text-field>
              <v-btn
                v-if="idx > 0 || group.min < 1"
                icon="mdi-close"
                variant="outlined" color="error"
                size="small"
                :aria-label="`Remove ${group.singular} ${idx + 1}`"
                @click="removePerson(group.field, idx)"
              ></v-btn>
            </div>
            <div v-if="createDialog.form[group.field].length >= group.max" class="text-caption text-medium-emphasis">
              Up to {{ group.max }} {{ group.label.toLowerCase() }}.
            </div>
          </div>
        </section>
      </v-form>

      <template #footer>
        <v-spacer></v-spacer>
        <v-btn variant="outlined" color="primary" class="text-none font-weight-bold" height="40" @click="createDialog.open = false">Cancel</v-btn>
        <v-btn color="primary" variant="flat" class="text-none font-weight-bold" height="40" :loading="loading" @click="submitCreate">
          Save trip record
        </v-btn>
      </template>
    </DetailDrawer>

    <!-- Detail -->
    <DetailDrawer
      v-model="detail.open"
      eyebrow="Trip record"
      :name="selected?.patient_name || 'Unnamed patient'"
      :initials="nameInitials(selected?.patient_name)"
      secondary="Trip record"
      :status-text="selected?.departed_office_at ? `Left the office ${fmtDateTime(selected.departed_office_at)}` : 'Not departed yet'"
    >
      <template #status>
        <StatusPill v-if="selected" :status="pillStatus(selected)" :label="outcomeLabel(tripStatusLabel(selected.trip_status), selected.no_arrival_reason)" />
      </template>

      <template v-if="selected">
        <!-- Only ever present on a trip dispatched from a resident's own
             booking — a walk-in trip log, still the common case, carries no
             service_request_id and shows none of this. -->
        <section v-if="selected.service_request_id" class="detail-section">
          <h3 class="sect-label">Linked booking</h3>
          <div class="assign-box">
            <div class="assign-row">
              <div class="min-width-0">
                <div class="mono">{{ transactionNo(selected.service_request_id) }}</div>
                <div class="text-body-2 text-medium-emphasis">
                  <template v-if="selected.service_request?.scheduled_at">Scheduled {{ fmtDateTime(selected.service_request.scheduled_at) }}</template>
                  <template v-if="selected.service_request?.status"> {{ selected.service_request.status }}</template>
                </div>
              </div>
              <!-- The reverse of Bookings' own "Open trip record": this link used to only go one way. -->
              <v-btn
                color="primary-strong" variant="outlined" height="40" class="text-none font-weight-bold flex-shrink-0"
                @click="openBooking(selected.service_request_id)"
              >Open booking</v-btn>
            </div>
          </div>
        </section>

        <section class="detail-section">
          <h3 class="sect-label">Patient</h3>
          <dl class="kv">
            <dt>Contact</dt><dd>{{ selected.patient_contact_number || NOT_RECORDED }}</dd>
            <dt>Age</dt><dd>{{ selected.patient_age ?? NOT_RECORDED }}</dd>
            <dt>Address</dt><dd>{{ selected.patient_address || NOT_RECORDED }}</dd>
            <dt>Diagnosis</dt><dd>{{ selected.medical_diagnosis || NOT_RECORDED }}</dd>
          </dl>
        </section>

        <section class="detail-section">
          <h3 class="sect-label">Route and vehicle</h3>
          <dl class="kv">
            <dt>Pickup</dt><dd>{{ selected.origin || NOT_RECORDED }}</dd>
            <dt>Destination</dt><dd>{{ selected.destination || NOT_RECORDED }}</dd>
            <dt>Vehicle</dt>
            <dd>{{ selectedVehicleLabel }}<span v-if="selectedVehicleUnverified" class="text-caption text-medium-emphasis"> · fleet list unavailable</span></dd>
            <!-- The fleet has no plate column, so a fleet-linked trip has no
                 plate to show; the free-text one belongs to the unlinked case. -->
            <template v-if="!selected.vehicle_id"><dt>Plate no.</dt><dd>{{ selected.plate_no || NOT_RECORDED }}</dd></template>
          </dl>
        </section>

        <section class="detail-section">
          <h3 class="sect-label">Crew</h3>
          <dl class="kv">
            <template v-for="group in personnelGroups" :key="group.field">
              <dt>{{ group.label }}</dt>
              <dd :class="{ 'text-medium-emphasis': peopleByRole(group.role).length === 0 }">
                {{ peopleByRole(group.role).map(p => p.name).join(', ') || 'None recorded' }}
              </dd>
            </template>
          </dl>
        </section>

        <section class="detail-section">
          <h3 class="sect-label">Timeline</h3>
          <v-timeline density="compact" align="start" side="end" truncate-line="both" class="trip-timeline">
            <v-timeline-item
              v-for="step in timelineSteps"
              :key="step.label"
              :dot-color="step.at || step.done ? step.color : 'grey-lighten-1'"
              size="x-small"
            >
              <div class="font-weight-bold">{{ step.label }}</div>
              <div v-if="step.at || !step.done" :class="{ 'text-medium-emphasis': !step.at }">{{ step.at ? fmtDateTime(step.at) : 'Pending' }}</div>
              <div v-if="step.note" class="text-body-2 text-medium-emphasis">{{ step.note }}</div>
            </v-timeline-item>
          </v-timeline>
        </section>

        <section class="detail-section">
          <h3 class="sect-label">Odometer and notes</h3>
          <dl class="kv">
            <dt>At departure</dt><dd>{{ selected.odometer_start ?? NOT_RECORDED }}</dd>
            <dt>On return</dt><dd>{{ selected.odometer_end ?? NOT_RECORDED }}</dd>
            <template v-if="selected.others"><dt>Others</dt><dd>{{ selected.others }}</dd></template>
          </dl>
        </section>
      </template>

      <template v-if="selected" #footer>
        <v-btn variant="outlined" color="primary-strong" class="text-none font-weight-bold" height="40" prepend-icon="mdi-printer-outline" @click="printTrip(selected)">
          Print
        </v-btn>
        <v-spacer></v-spacer>
        <v-btn color="primary" variant="flat" class="text-none font-weight-bold" height="40" @click="openTripLog(selected)">
          {{ tripLogAction(selected) }}
        </v-btn>
      </template>
    </DetailDrawer>

    <!-- Trip log -->
    <DetailDrawer
      v-model="tripLog.open"
      persistent
      :eyebrow="tripLog.title"
      :name="tripLog.target?.patient_name || 'Unnamed patient'"
      :initials="nameInitials(tripLog.target?.patient_name)"
      secondary="Trip record"
      :status-text="tripLog.target?.service_request_id ? transactionNo(tripLog.target.service_request_id) : ''"
    >
      <template #status>
        <StatusPill v-if="tripLog.target" :status="pillStatus(tripLog.target)" :label="outcomeLabel(tripStatusLabel(tripLog.target.trip_status), tripLog.target.no_arrival_reason)" />
      </template>

      <v-alert v-if="tripLog.error" type="error" variant="tonal" density="compact" class="mb-4">{{ tripLog.error }}</v-alert>

      <!-- Chronological: leave, arrive (or turn back), leave, return. Each
           checkpoint has a Now button so a time is one click while the
           crew is on the radio. -->
      <section class="detail-section">
        <h3 class="sect-label">Departure</h3>
        <div v-for="[field, label] in checkpointFields(['departed_office_at'])" :key="field" class="d-flex align-center gap-2 mb-3">
          <DateTimePickerField v-model="tripLog.form[field]" type="datetime-local" :label="label" variant="outlined" density="comfortable" class="flex-grow-1"></DateTimePickerField>
          <v-btn variant="tonal" size="small" class="text-none" @click="setNow(field)">Now</v-btn>
        </div>
        <v-text-field v-model="tripLog.form.odometer_start" type="number" min="0" label="Odometer at departure" placeholder="10000" variant="outlined" density="comfortable"></v-text-field>
      </section>

      <section class="detail-section">
        <h3 class="sect-label">Destination</h3>
        <!-- Ticking clears both destination checkpoints (the server refuses
             them beside a reason) and asks for the reason instead. -->
        <v-checkbox
          :model-value="tripLog.form.did_not_arrive"
          @update:model-value="onDidNotArrive"
          label="Did not reach destination"
          density="compact"
          hide-details
        ></v-checkbox>
        <v-textarea
          v-if="tripLog.form.did_not_arrive"
          v-model="tripLog.form.no_arrival_reason"
          label="Reason (required)"
          placeholder="e.g. Patient had already been taken by a relative"
          variant="outlined"
          density="comfortable"
          rows="2"
          class="mt-2"
        ></v-textarea>
        <div v-for="[field, label] in checkpointFields(tripLog.form.did_not_arrive ? [] : ['arrived_destination_at', 'departed_destination_at'])" :key="field" class="d-flex align-center gap-2 mt-3">
          <DateTimePickerField v-model="tripLog.form[field]" type="datetime-local" :label="label" variant="outlined" density="comfortable" class="flex-grow-1"></DateTimePickerField>
          <v-btn variant="tonal" size="small" class="text-none" @click="setNow(field)">Now</v-btn>
        </div>
      </section>

      <section class="detail-section">
        <h3 class="sect-label">Return</h3>
        <div v-for="[field, label] in checkpointFields(['returned_office_at'])" :key="field" class="d-flex align-center gap-2 mb-3">
          <DateTimePickerField v-model="tripLog.form[field]" type="datetime-local" :label="label" variant="outlined" density="comfortable" class="flex-grow-1"></DateTimePickerField>
          <v-btn variant="tonal" size="small" class="text-none" @click="setNow(field)">Now</v-btn>
        </div>
        <v-text-field v-model="tripLog.form.odometer_end" type="number" min="0" label="Odometer on return" placeholder="10042" variant="outlined" density="comfortable"></v-text-field>
      </section>

      <!-- All three PEOPLE_FIELDS roles, same pattern as the new-record
           drawer's Crew section (personnelGroups) — see
           ConductionRequestController::tripLog(). A stub created by
           Approve & Dispatch (C5's bridge) always starts with none of
           them, and a driver is required before this request can
           resolve. -->
      <section class="detail-section">
        <h3 class="sect-label">Crew</h3>
        <div v-for="group in personnelGroups" :key="group.field" class="mb-3">
          <div class="d-flex align-center justify-space-between mb-1">
            <span class="text-caption font-weight-bold text-uppercase text-medium-emphasis">{{ group.label }}</span>
            <v-btn
              variant="outlined" color="primary"
              size="small"
              density="compact"
              class="text-none"
              prepend-icon="mdi-plus"
              :disabled="tripLog.form[group.field].length >= group.max"
              @click="addTripPerson(group.field)"
            >
              Add {{ group.singular }}
            </v-btn>
          </div>
          <div
            v-for="(_n, idx) in tripLog.form[group.field]"
            :key="idx"
            class="d-flex align-center gap-2 mb-2"
          >
            <v-autocomplete
              v-if="group.field === 'drivers'"
              v-model="tripLog.form[group.field][idx]"
              :items="driverOptionsFor(tripLog.form[group.field][idx])"
              item-title="title"
              item-value="value"
              :label="`${group.singular} ${idx + 1}`"
              placeholder="Select a responder"
              variant="outlined"
              density="compact"
              hide-details
              clearable
            >
              <template v-slot:item="{ item, props }">
                <v-list-item v-bind="props" :title="item.title" :subtitle="item.position"></v-list-item>
              </template>
            </v-autocomplete>
            <ResponderCombobox
              v-else-if="group.field === 'authorized_passengers'"
              v-model="tripLog.form[group.field][idx]"
              :items="passengerOptionsFor(tripLog.form.drivers)"
              :label="`${group.singular} ${idx + 1}`"
            />
            <v-text-field
              v-else
              v-model="tripLog.form[group.field][idx]"
              :label="`${group.singular} ${idx + 1}`"
              placeholder="Full name"
              variant="outlined"
              density="compact"
              hide-details
            ></v-text-field>
            <v-btn
              v-if="idx > 0 || group.min < 1"
              icon="mdi-close"
              variant="outlined" color="error"
              size="small"
              :aria-label="`Remove ${group.singular} ${idx + 1}`"
              @click="removeTripPerson(group.field, idx)"
            ></v-btn>
          </div>
          <div v-if="tripLog.form[group.field].length >= group.max" class="text-caption text-medium-emphasis">
            Up to {{ group.max }} {{ group.label.toLowerCase() }}.
          </div>
        </div>
      </section>

      <section class="detail-section">
        <h3 class="sect-label">Notes</h3>
        <v-textarea v-model="tripLog.form.others" label="Others" placeholder="Anything else worth recording about the trip" variant="outlined" density="comfortable" rows="2"></v-textarea>
      </section>

      <template #footer>
        <v-spacer></v-spacer>
        <v-btn variant="outlined" color="primary" class="text-none font-weight-bold" height="40" @click="closeTripLog">Cancel</v-btn>
        <v-btn color="primary" variant="flat" class="text-none font-weight-bold" height="40" :loading="loading" @click="submitTripLog">Save trip log</v-btn>
      </template>
    </DetailDrawer>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3500" location="bottom right" rounded="lg">
      {{ snackbar.text }}
    </v-snackbar>
  </v-container>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, nextTick } from 'vue'
import { getToken } from '@/composables/authToken'
import { displayPhone } from '@/composables/phoneNumber'
import { sharedStatusLabel, tripStatusLabel, outcomeLabel } from '@/composables/adminUi'
import { API_BASE } from '@/config/api'
import { REFERENCE_TTL_MS, invalidate, useCachedFetch } from '@/composables/useCachedFetch'
import AmbulanceRequestQueue from '@/components/AmbulanceRequestQueue.vue'
import DateTimePickerField from '@/components/DateTimePickerField.vue'
import ResponderCombobox from '@/components/ResponderCombobox.vue'
import PageHeader from '@/components/PageHeader.vue'
import DataTablePage from '@/components/DataTablePage.vue'
import SegmentedTabs from '@/components/SegmentedTabs.vue'
import StatusPill from '@/components/StatusPill.vue'
import PersonCell from '@/components/PersonCell.vue'
import DetailDrawer from '@/components/DetailDrawer.vue'
import '@/components/detail-dialog.css'
import ExportMenu from '@/components/ExportMenu.vue'
import { useSelection, transactionNo } from '@/composables/requestDisplay'

// 'bookings' first: a staffer arriving on this page is more often checking on
// a resident's request than filling in a trip log by hand.
const activeTab = ref('bookings')

const ALL_STATUS = 'All'
const NOT_RECORDED = 'Not recorded'
const NO_IDS = new Set()
// Filtering still compares the raw trip_status value (matchesStatus below is
// unchanged) -- only the label shown on the tab/badge moves to
// tripStatusLabel() (adminUi.ts), so the underlying value stays exactly what
// the API sends. Pill color still comes from sharedStatusLabel() separately
// -- the two only disagree on the 'Not dispatched' text.
const NO_ARRIVAL = 'No arrival'
// Not a trip_status: a trip that turned back, whatever stage it is at.
const RAW_TRIP_STATUSES = ['Not dispatched', 'In transit', 'Completed', NO_ARRIVAL]
const tabLabel = (s) => (s === NO_ARRIVAL ? s : tripStatusLabel(s))

// `max` mirrors ConductionRequestController's per-role limits (MAX_PEOPLE_PER_ROLE
// for drivers, MAX_AUTHORIZED_PASSENGERS, MAX_PATIENT_RELATIVES). This only stops
// the Add button and shows the "up to N" cap message; the server-side rule is the
// actual gate, and a request that gets past this still fails validation there.
//
// `min` is a driver-only rule (MDRRMO feedback, 2026-09-18): a trip cannot be
// filed or updated without at least one real crew member, so index 0 never
// shows a remove button for this group — see the remove-button v-if in both
// the create dialog and the trip log dialog below. Passengers and relatives
// have no minimum; either can go to zero.
const personnelGroups = [
  { field: 'drivers', role: 'driver', label: 'Drivers', singular: 'driver', min: 1, max: 20 },
  { field: 'authorized_passengers', role: 'passenger', label: 'Passengers', singular: 'passenger', min: 0, max: 2 },
  { field: 'patient_relatives', role: 'relative', label: 'Relatives', singular: 'relative', min: 0, max: 2 },
]
// An empty driver slot is null, not '' — v-autocomplete treats '' as a picked
// value. The other two fields are free-text and keep ''.
const blankPerson = (field) => (field === 'drivers' ? null : '')

const required = (v) => (v !== null && v !== undefined && String(v).trim() !== '') || 'Required'

const items = ref([])
const search = ref('')
const statusFilter = ref(ALL_STATUS)
const page = ref(1)
const itemsPerPage = ref(10)
const initialLoad = ref(true)
const reloading = ref(false)
const loading = ref(false)
const apiError = ref('')
const loadError = ref('')
const snackbar = ref({ show: false, text: '', color: 'success' })

// patient_name is one free-text field, not first/last like a resident.
const nameInitials = (name) => {
  const parts = (name || '').trim().split(/\s+/).filter(Boolean)
  return parts.length ? `${parts[0][0]}${parts.length > 1 ? parts.at(-1)[0] : ''}`.toUpperCase() : '?'
}

// `value` gives the composite columns something to sort on; the key still
// names the cell slot.
const headers = [
  { title: 'Booking no.', key: 'service_request_id', width: '14%' },
  { title: 'Patient', key: 'patient', value: 'patient_name', width: '26%' },
  { title: 'Vehicle', key: 'vehicle_label', value: (r) => tripVehicleLabel(r), width: '14%' },
  { title: 'Departed', key: 'departed_office_at', width: '16%' },
  { title: 'Returned', key: 'returned_office_at', width: '16%' },
  { title: 'Status', key: 'trip_status', width: '10%' },
  { title: '', key: 'chevron', sortable: false, width: '40px' },
]

const notify = (text, color = 'success') => { snackbar.value = { show: true, text, color } }
// One path for every timestamp on this page — created_at and all four trip log
// checkpoints. The checkpoints used to arrive without an offset, which new Date()
// reads as local time; that happened to render correctly only because the column
// held office wall clock. They are real UTC instants now and carry a 'Z', so the
// same conversion is right for all five and there is no special case to keep.
const fmtDateTime = (iso) => iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''

const matchesSearch = (r) => {
  const q = (search.value || '').trim().toLowerCase()
  if (!q) return true
  return [r.patient_name, r.origin, r.destination].some((v) => (v || '').toLowerCase().includes(q))
}
// The one status tab a trip belongs to, so it is counted exactly once.
const tabOf = (r) => (r.no_arrival_reason ? NO_ARRIVAL : r.trip_status)
const matchesStatus = (r) => statusFilter.value === ALL_STATUS || tabOf(r) === statusFilter.value

// Presets on the day the unit left (filed day if it has not left yet), and
// one unit. Both compare in the office's local day, which is the viewer's.
const DEPARTED_RANGES = ['Any time', 'Today', 'Last 7 days', 'Last 30 days']
const RANGE_DAYS = { 'Today': 0, 'Last 7 days': 6, 'Last 30 days': 29 }
const departedRange = ref(DEPARTED_RANGES[0])
const vehicleFilter = ref(ALL_STATUS)
const localDay = (iso) => toInputValue(iso).slice(0, 10)
const rangeStart = computed(() => {
  const back = RANGE_DAYS[departedRange.value]
  if (back === undefined) return ''
  const d = new Date()
  d.setDate(d.getDate() - back)
  return toInputValue(d).slice(0, 10)
})
const matchesDateAndVehicle = (r) => {
  if (rangeStart.value && localDay(r.departed_office_at || r.created_at) < rangeStart.value) return false
  return vehicleFilter.value === ALL_STATUS || r.vehicle_id === vehicleFilter.value
}
const filteredItems = computed(() => items.value.filter((r) => matchesSearch(r) && matchesStatus(r) && matchesDateAndVehicle(r)))

// SegmentedTabs' {value, label, count} shape. Counts are off the search
// match only, same as AmbulanceRequestQueue's own requestCounts — a status
// tab's count should not move just because a different status tab is
// selected.
const statusTabItems = computed(() => {
  const searched = items.value.filter((i) => matchesSearch(i) && matchesDateAndVehicle(i))
  return [
    { value: ALL_STATUS, label: ALL_STATUS, count: searched.length },
    ...RAW_TRIP_STATUSES.map((s) => ({
      value: s,
      label: tabLabel(s),
      count: searched.filter((r) => tabOf(r) === s).length,
    })),
  ]
})

// Search's own chip is DataTablePage's job. Status is the only other filter
// this page has, but it still needs its own chip + Clear all target — the
// active SegmentedTabs item shows the selection, not a way to jump back to
// All in one click alongside a cleared search.
const activeFilters = computed(() => {
  const out = []
  if (statusFilter.value !== ALL_STATUS) out.push({ key: 'status', label: `Status: ${tabLabel(statusFilter.value)}` })
  if (departedRange.value !== DEPARTED_RANGES[0]) out.push({ key: 'departed', label: `Departed: ${departedRange.value}` })
  if (vehicleFilter.value !== ALL_STATUS) out.push({ key: 'vehicle', label: `Unit: ${vehicleFilterOptions.value.find((o) => o.value === vehicleFilter.value)?.title}` })
  return out
})
const clearFilter = (key) => {
  if (key === 'status') statusFilter.value = ALL_STATUS
  if (key === 'departed') departedRange.value = DEPARTED_RANGES[0]
  if (key === 'vehicle') vehicleFilter.value = ALL_STATUS
}
const clearAllFilters = () => {
  for (const key of ['status', 'departed', 'vehicle']) clearFilter(key)
}

// The page header's line on the Trip logs tab.
const tripSubtitle = computed(() => {
  const rolling = items.value.filter((r) => r.trip_status === 'In transit').length
  return `${rolling} in progress · ${items.value.length} trip records in total`
})

// StatusPill's :status prop wants an accent-table key (Booked/Responding/
// Resolved/'Resolved — no arrival') -- sharedStatusLabel() already maps a
// raw trip_status onto that same vocabulary; only the no-arrival split needs
// adding here, same condition outcomeLabel() uses for the label text.
const pillStatus = (item) => {
  const shared = sharedStatusLabel(item.trip_status)
  return shared === 'Resolved' && item.no_arrival_reason ? 'Resolved — no arrival' : shared
}

watch(search, () => { page.value = 1 })
watch([statusFilter, departedRange, vehicleFilter], () => { page.value = 1 })

const getHeaders = () => ({
  Authorization: `Bearer ${getToken()}`,
  'Content-Type': 'application/json',
  Accept: 'application/json',
})

const { get } = useCachedFetch()

// A trip write also moves the booking it links to and the unit it uses.
const invalidateTrips = () => {
  invalidate('/conduction-requests')
  invalidate('/admin/service-requests')
  invalidate('/vehicles')
}

const fetchData = async () => {
  reloading.value = true
  try {
    await get('/conduction-requests', {
      onData: (data) => {
        if (!Array.isArray(data)) throw new Error('The server returned an unexpected response')
        items.value = data
        loadError.value = ''
        initialLoad.value = false
      },
    })
  } catch (error) {
    // Full-pane loadError card below is the only notification here — a
    // snackbar on top of it duplicated the same message (ui-audit finding #3).
    loadError.value = error.message || 'Could not reach the server'
  } finally {
    initialLoad.value = false
    reloading.value = false
  }
}

const rowProps = ({ item }) => ({
  tabindex: 0,
  'aria-label': `Open details for ${item.patient_name}`,
  onKeydown: (e) => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDetail(item) }
  },
})

// C6: the create dialog's own "Link to approved service request" search —
// same source AmbulanceRequestQueue.vue reads, fetched independently here
// since that component keeps its own list private. Same exact-code match
// as its AMBULANCE_SERVICE_CODE (duplicated rather than shared — see the
// OFFICE_TIMEZONE precedent in ServiceRequestController).
const AMBULANCE_SERVICE_CODE = 'ambulance-medical-response'
const bookings = ref([])
const bookingsError = ref('')
const bookingsLoading = ref(false)
const fetchBookings = async () => {
  bookingsLoading.value = true
  try {
    await get('/admin/service-requests', {
      onData: (data) => {
        bookings.value = (data.data || data).filter(r => r.service?.code === AMBULANCE_SERVICE_CODE)
        bookingsError.value = ''
      },
    })
  } catch (error) {
    // Shown above the booking search, never blocking: filing standalone must
    // still work. Silent, an empty search read as "nothing to link".
    bookingsError.value = error.message || 'Could not reach the server'
  } finally {
    bookingsLoading.value = false
  }
}

// A booking is linkable once it is an approved, scheduled dispatch with no
// trip filed against it yet — exactly what the Dispatch button already
// targets (see handleDispatchBooking). Excludes Pending: C5's bridge means
// an instant approval already has its own stub the moment it exists, so
// there is nothing left here for a search to find.
const linkableBookings = computed(() => bookings.value.filter(r =>
  r.status === 'Booked' && r.vehicle_id && (r.conduction_requests || []).length === 0
))
const bookingLabel = (r) => {
  const who = r.resident ? `${r.resident.first_name} ${r.resident.last_name}` : (r.walk_in_name || 'Walk-in')
  return `${who} — ${fmtDateTime(r.scheduled_at)}`
}
const bookingOptions = computed(() => linkableBookings.value.map(r => ({ title: bookingLabel(r), value: r.request_id })))

// C7: the fleet picker. Own fetch rather than sharing AmbulanceRequestQueue's —
// that component keeps its vehicle list private, same as bookings above.
const vehicles = ref([])
const vehiclesError = ref('')
const vehiclesLoading = ref(false)
const fetchVehicles = async () => {
  vehiclesLoading.value = true
  try {
    await get('/vehicles', {
      onData: (data) => { vehicles.value = data.data || data; vehiclesError.value = '' },
    })
  } catch (error) {
    // Shown above the fleet picker, never blocking: the free-text name still
    // files. Silent, staff typed a name the double-booking guard cannot check.
    vehiclesError.value = error.message || 'Could not reach the server'
  } finally {
    vehiclesLoading.value = false
  }
}
const fleetUnitLabel = (v) => `${v.unit_identifier}${v.specification ? ` (${v.specification})` : ''}`
const ambulanceVehicles = computed(() => vehicles.value.filter(v => v.type === 'Ambulance'))
const vehicleOptions = computed(() => ambulanceVehicles.value.map(v => ({
  title: fleetUnitLabel(v),
  value: v.vehicle_id,
})))
const vehicleFilterOptions = computed(() => [{ title: 'All', value: ALL_STATUS }, ...vehicleOptions.value])
// Table cell: the unit's short name, else the free-text one for a unit outside the fleet.
const tripVehicleLabel = (t) => {
  if (!t.vehicle_id) return t.vehicle || '—'
  return vehicles.value.find(v => v.vehicle_id === t.vehicle_id)?.unit_identifier || t.vehicle || `Unit #${t.vehicle_id}`
}
// vehicle_id is the trip's real link; the free-text `vehicle` column is only
// what was typed or copied at filing and goes stale, so it is shown only for a
// trip with no fleet unit. Not an eager-loaded relation: it would serialise
// under the same `vehicle` key and overwrite that column in the JSON.
const selectedFleetUnit = computed(() => {
  const id = selected.value?.vehicle_id
  return id ? vehicles.value.find(v => v.vehicle_id === id) : null
})
const selectedVehicleLabel = computed(() => {
  const trip = selected.value
  if (!trip) return NOT_RECORDED
  if (!trip.vehicle_id) return trip.vehicle || NOT_RECORDED
  const unit = selectedFleetUnit.value
  return unit ? fleetUnitLabel(unit) : (trip.vehicle || `Unit #${trip.vehicle_id}`)
})
// The label above fell back only because the fleet list never loaded.
const selectedVehicleUnverified = computed(() =>
  !!(vehiclesError.value && selected.value?.vehicle_id && !selectedFleetUnit.value)
)
// The free-text `vehicle` name column has no fleet equivalent to leave blank
// and derive later — unlike a booking's own fields, this has to be written
// at selection time. There is no plate to derive here any more — the input was
// removed and tbl_vehicles carries no plate column; see emptyCreateForm.
const onSelectFleetVehicle = (vehicleId) => {
  const form = createDialog.value.form
  const vehicle = ambulanceVehicles.value.find(v => v.vehicle_id === vehicleId)
  form.vehicle = vehicle ? fleetUnitLabel(vehicle) : ''
}

// Create dialog
const emptyCreateForm = () => ({
  // Set only when this dialog was opened by dispatching an approved booking
  // (handleDispatchBooking below); a plain "Ambulance Trip Record"
  // leaves both null, exactly as before this feature existed.
  service_request_id: null, vehicle_id: null,
  patient_name: '', patient_age: null, patient_address: '',
  // No plate_no. The input was removed 2026-09-03: tbl_vehicles has had no
  // plate column since 2026_09_02_100000 dropped the one added the day before,
  // so nothing could prefill it, and tripLog() does not validate plate_no — a
  // blank or mistyped plate could never be corrected afterwards. Every
  // auto-dispatched trip already had it null, since createConductionStub() does
  // not set it either. The column stays and the detail view still shows what
  // historical rows recorded.
  patient_contact_number: '', vehicle: '', medical_diagnosis: '',
  origin: '', destination: '',
  // One blank slot each, not two — "Add {label}" already covers the case
  // that needs more, and starting at two padded the common one-driver,
  // zero-passenger trip with a field nobody was going to fill (impeccable
  // polish, 2026-08-30).
  drivers: [null], authorized_passengers: [''], patient_relatives: [''],
})
const createDialog = ref({ open: false, form: emptyCreateForm() })
const createForm = ref(null)

// `booking` is the tbl_service_request row this dispatch fulfils — absent
// for the plain "Ambulance Trip Record" button, which behaves exactly as it
// always has. Reads the structured columns ServiceRequestController's
// adminStore() writes and the backfill migration populated for historical
// rows (2026_08_31_085924) directly — no more regex over `description`.
// A booking the backfill could not parse, or one filed before either
// existed, simply has these columns null: the fields come up blank, same as
// the old parser's own fallback, and staff types them in from the paper
// form same as any other new request.
// C6: one prefill implementation, reached two ways — the Dispatch button
// below and the create dialog's own "Link to approved service request"
// autocomplete. Structural, not parsed out of prose.
const applyBooking = (booking, form = createDialog.value.form) => {
  // Mirrors ServiceRequestController::createConductionStub() field for field.
  // The two dispatch paths write the same trip from the same booking, and every
  // field one fills and the other does not is a trip that reads differently
  // depending on which button was pressed — the drift docs/dispatch-audit.md
  // flagged. The two exceptions are named where they occur below.
  //
  // The stub's own last-resort literals ('Not specified', 'See resident
  // profile') are deliberately NOT carried across. There they are written
  // straight to a NOT NULL column with nobody left to ask; here the field is
  // about to be shown to a staff member who can read the paper form, and
  // seeding an editable input with placeholder prose gets it submitted verbatim.
  // Blank is the honest starting point for a person; a literal is the honest
  // fallback for a column.
  const fromAccount = booking.resident
    ? `${booking.resident.first_name ?? ''} ${booking.resident.last_name ?? ''}`.trim()
    : (booking.walk_in_name || '')

  form.patient_name = booking.patient_name || fromAccount || ''
  // `??`, not `||`: an age of 0 is a real value on this form. Neonate transport
  // is why the server's rule is min:0, and `||` would blank it.
  form.patient_age = booking.patient_age ?? null
  form.patient_address = booking.patient_address || ''
  form.origin = booking.pickup_location || ''
  form.destination = booking.destination || ''
  form.medical_diagnosis = booking.condition_notes || ''
  // Order matters and matches the stub: the patient's own number first. A head
  // of the family files for whoever in the household is actually travelling, so
  // the account number is the fallback, not the answer. This prefill previously
  // skipped booking.patient_contact_number entirely and opened at the filer's
  // number.
  form.patient_contact_number = booking.patient_contact_number
    || displayPhone(booking.resident?.phone_number)
    || booking.walk_in_contact_number
    || ''
  // patient_relatives is deliberately NOT prefilled, and must not be added.
  // ConductionRequestController::store() writes the submitted patient_relatives
  // AND then calls ServiceRequestController::copyRelativesToTrip(), which
  // APPENDS the booking's relatives rather than replacing them. Prefilling here
  // would file every relative twice — once from this form, once from the copy.
  form.service_request_id = booking.request_id
  form.vehicle_id = booking.vehicle_id ?? null
  // Best-effort: the fleet list this reads may not have loaded yet (see
  // fetchVehicles below). If not, vehicle_id is still correctly linked —
  // only the display name text is left for the operator to see once it
  // arrives, or to type by hand.
  const fleetUnit = ambulanceVehicles.value.find(v => v.vehicle_id === form.vehicle_id)
  if (fleetUnit) form.vehicle = fleetUnitLabel(fleetUnit)
}

const openCreate = (booking = null) => {
  apiError.value = ''
  const form = emptyCreateForm()
  if (booking) applyBooking(booking, form)
  createDialog.value = { open: true, form }
  // Background refresh for both lists. The booking already in hand (if any)
  // was applied synchronously above, so there is no dialog-opens-then-
  // fields-pop-in flash to wait out for that one; the fleet name derived
  // just above may still fill in a moment after open if this is the fetch
  // that populates it.
  fetchBookings()
  fetchVehicles()
}

// Fired by the create dialog's own "Link to approved service request"
// autocomplete. Clearing it (id undefined) only drops the linkage — it does
// not blank fields staff may already have typed, since the standalone,
// no-booking case is exactly what clearing this field means to choose.
const onLinkBooking = (id) => {
  if (!id) {
    createDialog.value.form.service_request_id = null
    return
  }
  const booking = linkableBookings.value.find(r => r.request_id === id)
  if (booking) applyBooking(booking)
}

// Fired by the Bookings tab's "Dispatch" button (AmbulanceRequestQueue,
// scope="ambulance") on an approved booking. Both tabs live on this one page
// now, so this is a tab switch plus the same prefill openCreate has always
// done — no more round trip through a /conduction-requests?dispatch=<id>
// query param and a second fetch for a booking the caller already has.
const handleDispatchBooking = (booking) => {
  activeTab.value = 'trip-logs'
  openCreate(booking)
}

// Fired by the Bookings tab's "Open Trip Record" button (C5's bridge — a
// Responding row's stub, created at Approve & Dispatch). Refetches first:
// the stub may have been created moments ago by this same click chain, and
// `items` is this view's own copy, last loaded independently of whatever
// AmbulanceRequestQueue.vue just did.
const handleOpenTripRecord = async (conductionRequestId) => {
  if (!conductionRequestId) return
  invalidateTrips()
  await fetchData()
  const record = items.value.find(i => i.conduction_request_id === conductionRequestId)
  if (!record) return
  activeTab.value = 'trip-logs'
  openTripLog(record)
}

// The reverse of the above — a trip's own detail dialog linking back to the
// booking that dispatched it (item 7 of the layout redesign: this link only
// ever went one way before). AmbulanceRequestQueue.vue keeps its own request
// list and selection state private, so this reaches in via defineExpose
// rather than duplicating that state here. v-window keeps both tabs
// mounted (confirmed while building the sticky footer for item 1 — inactive
// tab content is hidden, not destroyed), so the ref is already valid the
// instant the tab switches; nextTick is just to let that switch paint
// before the child's own scroll/selection work runs.
const bookingsQueueRef = ref(null)
const openBooking = (requestId) => {
  detail.value.open = false
  activeTab.value = 'bookings'
  nextTick(() => bookingsQueueRef.value?.selectRequestById(requestId))
}

const printTrip = async (record) => {
  try {
    const res = await fetch(`${API_BASE}/conduction-requests/${record.conduction_request_id}/print`, {
      headers: { Authorization: `Bearer ${getToken()}` },
    })
    if (!res.ok) throw new Error('Failed to load print view')
    const html = await res.text()
    const blob = new Blob([html], { type: 'text/html' })
    window.open(URL.createObjectURL(blob), '_blank')
  } catch (error) {
    notify(error.message || 'Failed to print', 'error')
  }
}

const addPerson = (field) => { createDialog.value.form[field].push(blankPerson(field)) }
const removePerson = (field, idx) => { createDialog.value.form[field].splice(idx, 1) }

const submitCreate = async () => {
  const { valid } = await createForm.value.validate()
  if (!valid) return

  loading.value = true
  apiError.value = ''
  try {
    const res = await fetch(`${API_BASE}/conduction-requests`, {
      method: 'POST',
      headers: getHeaders(),
      body: JSON.stringify(createDialog.value.form),
    })
    if (!res.ok) {
      const errData = await res.json().catch(() => ({}))
      // 409 (unit busy / already on a trip): the server's message, unchanged.
      if (res.status === 409) throw new Error(errData.message || 'This unit is not available.')
      const firstError = errData.errors ? Object.values(errData.errors)[0]?.[0] : null
      throw new Error(firstError || errData.message || 'Failed to file the request')
    }
    invalidateTrips()
    await fetchData()
    createDialog.value.open = false
    notify('Ambulance trip record filed')
  } catch (error) {
    apiError.value = error.message
  } finally {
    loading.value = false
  }
}

// Detail dialog
const detail = ref({ open: false })
const selected = ref(null)
// Ticked trip records, for bulk print/export.
const selectedIds = reactive(new Set())
const { setSelected } = useSelection(selected, selectedIds, (r) => r.conduction_request_id)

const openDetail = (item) => {
  apiError.value = ''
  selected.value = item
  detail.value.open = true
}

const peopleByRole = (role) => (selected.value?.people || []).filter((p) => p.role === role)

// A trip that turned back has no destination steps; it shows the reason instead.
const timelineSteps = computed(() => {
  const t = selected.value
  if (!t) return []
  return [
    { label: 'Left the office', at: t.departed_office_at, color: 'primary' },
    ...(t.no_arrival_reason
      ? [{ label: 'Did not reach destination', done: true, note: t.no_arrival_reason, color: 'warning' }]
      : [
          { label: 'Arrived at destination', at: t.arrived_destination_at, color: 'primary' },
          { label: 'Left destination', at: t.departed_destination_at, color: 'primary' },
        ]),
    { label: 'Back at the office', at: t.returned_office_at, color: 'success' },
  ]
})

// Trip log dialog
const emptyTripLogForm = () => ({
  departed_office_at: '', arrived_destination_at: '', no_arrival_reason: '', did_not_arrive: false, departed_destination_at: '', returned_office_at: '',
  odometer_start: null, odometer_end: null, others: '',
  // All three PEOPLE_FIELDS roles — see ConductionRequestController::
  // tripLog(). A stub created by C5's bridge (openTripRecord below) always
  // starts with none of them.
  drivers: [null], authorized_passengers: [''], patient_relatives: [''],
})
const addTripPerson = (field) => { tripLog.value.form[field].push(blankPerson(field)) }
const removeTripPerson = (field, idx) => { tripLog.value.form[field].splice(idx, 1) }
const tripLog = ref({ open: false, form: emptyTripLogForm(), error: '', target: null, title: '', fromDetail: false })

// The button that opens the dialog and the dialog's own title read the same
// record, so they come from one place rather than two copies that can drift.
const tripLogAction = (record) => (record?.departed_office_at ? 'Update trip log' : 'Complete trip log')

// The API sends an ISO instant with an offset; <input type="datetime-local">
// wants 'YYYY-MM-DDTHH:mm' with none. new Date() resolves the offset and the
// local getters below render it in the viewer's zone, which is the office's.
const toInputValue = (iso) => {
  if (!iso) return ''
  const d = new Date(iso)
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

const openTripLog = (record) => {
  // Opened from the detail drawer: swap it out, and come back to it on close.
  const fromDetail = detail.value.open
  detail.value.open = false
  tripLog.value = {
    open: true,
    fromDetail,
    error: '',
    target: record,
    // Captured at open time so the title stays put while the form is edited.
    title: tripLogAction(record),
    form: {
      departed_office_at: toInputValue(record.departed_office_at),
      arrived_destination_at: toInputValue(record.arrived_destination_at),
      no_arrival_reason: record.no_arrival_reason || '',
      did_not_arrive: !!record.no_arrival_reason,
      departed_destination_at: toInputValue(record.departed_destination_at),
      returned_office_at: toInputValue(record.returned_office_at),
      odometer_start: record.odometer_start,
      odometer_end: record.odometer_end,
      others: record.others || '',
      ...Object.fromEntries(personnelGroups.map(({ field, role }) => {
        const names = (record.people || []).filter(p => p.role === role).map(p => p.name)
        return [field, names.length > 0 ? names : [blankPerson(field)]]
      })),
    },
  }
}

const closeTripLog = () => {
  tripLog.value.open = false
  if (tripLog.value.fromDetail) detail.value.open = true
}

const CHECKPOINTS = [
  ['departed_office_at', 'Left the office'],
  ['arrived_destination_at', 'Arrived at destination'],
  ['departed_destination_at', 'Left destination'],
  ['returned_office_at', 'Back at the office'],
]
const checkpointFields = (fields) => CHECKPOINTS.filter(([field]) => fields.includes(field))
const setNow = (field) => { tripLog.value.form[field] = toInputValue(new Date()) }

// A trip either reached its destination or it did not: ticking drops both
// destination checkpoints (the server refuses them beside a reason), unticking
// drops the reason.
const onDidNotArrive = (ticked) => {
  const form = tripLog.value.form
  form.did_not_arrive = !!ticked
  if (ticked) {
    form.arrived_destination_at = ''
    form.departed_destination_at = ''
  } else {
    form.no_arrival_reason = ''
  }
}

// Driver/passenger picker source. The API's crew rows are free text server-
// side either way — this only shapes what the two pickers offer.
const responders = ref([]) // [{name, position}]
const fetchResponders = async () => {
  // driver list stays empty on failure; passenger combobox still allows free typing
  await get('/responder-names', { ttl: REFERENCE_TTL_MS, onData: (data) => { responders.value = data } }).catch(() => null)
}

// Driver is a strict pick from Responders (MDRRMO feedback: a trip's driver
// must be a real responder, not whatever was typed) — Ambulance Driver first,
// then the rest, each labelled with its position.
const AMBULANCE_DRIVER_POSITION = 'ambulance driver' // compared lowercased; positions are typed free-form
// title = value = the name, so the field shows just the name once picked;
// position rides along only for the dropdown's #item subtitle (see the
// template above and ResponderCombobox.vue).
const driverBaseOptions = computed(() => [...responders.value]
  .sort((a, b) => {
    const aFirst = (a.position || '').trim().toLowerCase() === AMBULANCE_DRIVER_POSITION
    const bFirst = (b.position || '').trim().toLowerCase() === AMBULANCE_DRIVER_POSITION
    if (aFirst !== bFirst) return aFirst ? -1 : 1
    return a.name.localeCompare(b.name)
  })
  .map((r) => ({ title: r.name, value: r.name, position: r.position })))
// An older trip's driver may be free text from before this field was locked
// down, or a responder since removed — add it as its own option so the
// select still shows the saved value instead of going blank.
const driverOptionsFor = (value) => {
  if (!value || responders.value.some((r) => r.name === value)) return driverBaseOptions.value
  return [...driverBaseOptions.value, { title: value, value, position: null }]
}

// Passenger stays a combobox (list or free typed name) — just excludes
// whoever is already picked as a driver on this same form.
const passengerOptionsFor = (drivers) => {
  const excluded = new Set((drivers || []).filter(Boolean))
  return responders.value
    .filter((r) => !excluded.has(r.name))
    .map((r) => ({ title: r.name, value: r.name, position: r.position }))
}

// Same three rules the server enforces, checked client-side first so a mistake
// shows next to the field instead of round-tripping to the API to find out.
const validateTripLog = (form) => {
  const start = form.odometer_start
  const end = form.odometer_end
  if (start !== null && start !== '' && end !== null && end !== '' && Number(end) < Number(start)) {
    return 'Odometer reading on return must be at or after the reading at departure.'
  }
  // Same two refusals the server added with the reduced sequence below. Checked
  // here first for the same reason the rest of this function exists — a mistake
  // belongs next to the field, not after a round trip.
  const noArrival = form.did_not_arrive

  if (noArrival && !(form.no_arrival_reason || '').trim()) {
    return 'Enter why the trip did not reach its destination.'
  }
  if (noArrival && form.arrived_destination_at) {
    return 'This trip has an arrival time recorded. A trip either arrived or it did not — clear the arrival time, or clear the no-arrival reason.'
  }
  if (noArrival && form.departed_destination_at) {
    return 'This trip never arrived, so there is no departure from the destination to record. Clear the reason if it did arrive.'
  }

  // A trip that never arrived runs office -> back to office. Without this the
  // client refused the very checkpoints the server now accepts, so the crew's
  // return still could not be recorded — the block would simply have moved from
  // the API to the form.
  const sequence = noArrival
    ? CHECKPOINTS.filter(([field]) => field === 'departed_office_at' || field === 'returned_office_at')
    : CHECKPOINTS

  const checkpoints = sequence
    .map(([field, label]) => ({ field, label, at: form[field] ? new Date(form[field]) : null }))
  for (let i = 1; i < checkpoints.length; i++) {
    if (checkpoints[i].at && !checkpoints[i - 1].at) {
      return `${checkpoints[i].label} cannot be recorded while ${checkpoints[i - 1].label} is still blank.`
    }
  }
  const filled = checkpoints.filter((c) => c.at)
  // Same 5-minute grace as the server, for a clock a little ahead.
  const future = filled.find((c) => c.at.getTime() > Date.now() + 5 * 60_000)
  if (future) return `${future.label} cannot be in the future.`
  for (let i = 1; i < filled.length; i++) {
    if (filled[i].at < filled[i - 1].at) {
      return `${filled[i].label} cannot be earlier than ${filled[i - 1].label}.`
    }
  }
  return ''
}

const submitTripLog = async () => {
  const error = validateTripLog(tripLog.value.form)
  if (error) { tripLog.value.error = error; return }

  loading.value = true
  tripLog.value.error = ''
  try {
    const form = tripLog.value.form
    // Sent back naive, exactly as the input holds it. The server reads a
    // checkpoint with no offset as Asia/Manila and converts — see
    // ConductionRequestController::OFFICE_TIMEZONE — so this round-trips what
    // the staffer typed without the browser having to name a zone.
    const body = {
      departed_office_at: form.departed_office_at ? form.departed_office_at.replace('T', ' ') + ':00' : null,
      arrived_destination_at: form.arrived_destination_at ? form.arrived_destination_at.replace('T', ' ') + ':00' : null,
      no_arrival_reason: form.did_not_arrive ? form.no_arrival_reason.trim() : null,
      departed_destination_at: form.departed_destination_at ? form.departed_destination_at.replace('T', ' ') + ':00' : null,
      returned_office_at: form.returned_office_at ? form.returned_office_at.replace('T', ' ') + ':00' : null,
      odometer_start: form.odometer_start === '' ? null : form.odometer_start,
      odometer_end: form.odometer_end === '' ? null : form.odometer_end,
      others: form.others || null,
      drivers: form.drivers,
      authorized_passengers: form.authorized_passengers,
      patient_relatives: form.patient_relatives,
    }
    const id = tripLog.value.target.conduction_request_id
    const res = await fetch(`${API_BASE}/conduction-requests/${id}/trip-log`, {
      method: 'PATCH',
      headers: getHeaders(),
      body: JSON.stringify(body),
    })
    if (!res.ok) {
      const errData = await res.json().catch(() => ({}))
      const firstError = errData.errors ? Object.values(errData.errors)[0]?.[0] : null
      throw new Error(firstError || errData.message || 'Failed to save the trip log')
    }
    const updated = await res.json()
    invalidateTrips()
    await fetchData()
    selected.value = updated
    closeTripLog()
    notify('Trip log saved')
  } catch (error) {
    tripLog.value.error = error.message
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchData()
  fetchVehicles()
  fetchResponders()
})
</script>

<style scoped>
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }

.tabular { font-variant-numeric: tabular-nums; white-space: nowrap; }

.assign-box { border: 1px solid rgba(var(--v-theme-on-surface), 0.14); border-radius: 12px; }
.assign-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
}
.min-width-0 { min-width: 0; }

/* Status pills: components/StatusPill.vue now, driven by pillStatus() above via composables/statusPill.ts's shared accent table -- was a locally duplicated .status-pill/.pill-* CSS block. */

/* One table at every width now (the card-list branch below lgAndUp is
   gone) — this min-width is what makes that honest: below it the table
   scrolls horizontally (Vuetify's own .v-table__wrapper overflow-x) rather
   than crushing a column unreadable. DataTablePage's own .dtp-table rule
   already sets table-layout: fixed and the header/row styling; this only
   adds the page-specific floor. */
.conduction-table :deep(.dtp-table table) { min-width: 704px; }
.cell-truncate {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
