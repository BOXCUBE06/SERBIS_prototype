<template>
  <!-- Scrolls with the page like every other list route. Each tab's actions
       live in its own DataTablePage panel, not this header. -->
  <v-container fluid class="bg-background">
    <PageHeader
      title="Ambulance Dispatch Requests"
    />

    <!-- Bookings: the resident-facing request/approval flow, filtered to
         Ambulance/Medical Response — moved here from Resident Requests so
         staff have one place for everything ambulance. Trip Logs: the
         dispatch record itself, unchanged, for a unit that is actually
         rolling. -->
    <v-tabs v-model="activeTab" color="primary" class="mb-5">
      <v-tab value="bookings" class="text-none font-weight-bold">Bookings</v-tab>
      <v-tab value="trip-logs" class="text-none font-weight-bold">Trip Logs</v-tab>
    </v-tabs>

    <v-window v-model="activeTab">
      <v-window-item value="bookings">
        <AmbulanceRequestQueue ref="bookingsQueueRef" :standalone="false" @dispatch-booking="handleDispatchBooking" @open-trip-record="handleOpenTripRecord" @trip-record-created="fetchData" />
      </v-window-item>

      <v-window-item value="trip-logs">
        <v-alert v-if="apiError && !createDialog.open && !detail.open" type="error" variant="tonal" density="compact" closable class="mb-4" @click:close="apiError = ''">
          {{ apiError }}
        </v-alert>

        <v-skeleton-loader v-if="initialLoad" type="table" class="rounded-lg"></v-skeleton-loader>

        <v-card v-else-if="loadError" elevation="0" border rounded="lg" class="bg-surface">
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
          v-model:search="search"
          search-placeholder="Patient, origin or destination"
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
          <template v-slot:actions>
            <v-btn
              color="primary"
              variant="flat"
              class="text-none font-weight-bold"
              height="40"
              @click="openCreate()"
            >
              <v-icon start size="small">mdi-plus</v-icon>
              Ambulance Trip Record
            </v-btn>
          </template>

          <template v-slot:item.patient="{ item }">
            <PersonCell
              :name="item.patient_name || 'Unnamed patient'"
              :initials="nameInitials(item.patient_name)"
              :secondary="item.patient_contact_number"
            />
          </template>

          <template v-slot:item.trip="{ item }">
            <span class="text-body-2 cell-truncate">{{ item.origin }} <v-icon size="12" class="mx-1">mdi-arrow-right</v-icon> {{ item.destination }}</span>
          </template>

          <template v-slot:item.trip_status="{ item }">
            <StatusPill small :status="pillStatus(item)" :label="outcomeLabel(tripStatusLabel(item.trip_status), item.no_arrival_reason)" />
          </template>

          <template v-slot:item.created_at="{ item }">
            {{ fmtDateTime(item.created_at) }}
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

    <!-- Create -->
    <v-dialog v-model="createDialog.open" max-width="720" scrollable persistent>
      <v-card rounded="lg">
        <v-card-title class="d-flex justify-space-between align-center pa-6 border-b bg-surface">
          <div>
            <span class="text-h6 font-weight-bold text-high-emphasis">Ambulance Trip Record</span>
            <!-- Prefilled from the booking's own structured columns
                 (ServiceRequestController::adminStore()) — nothing here is
                 locked, so this is a note to the operator, not a guarantee. -->
            <div v-if="createDialog.form.service_request_id" class="text-caption text-medium-emphasis">
              Linked to booking #{{ createDialog.form.service_request_id }} — prefilled from the booking, check every field before filing.
            </div>
          </div>
          <v-btn icon="mdi-close" variant="text" size="small" aria-label="Close" @click="createDialog.open = false"></v-btn>
        </v-card-title>
        <v-card-text class="pa-6" style="max-height: 70vh;">
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
                <v-btn variant="text" size="small" class="text-none font-weight-bold" :loading="bookingsLoading" @click="fetchBookings">Retry</v-btn>
              </template>
            </v-alert>
            <v-autocomplete
              :model-value="createDialog.form.service_request_id"
              @update:model-value="onLinkBooking"
              :items="bookingOptions"
              label="Link to approved service request (optional)"
              placeholder="Search by name or date"
              variant="outlined"
              density="comfortable"
              clearable
              class="mb-4"
            ></v-autocomplete>

            <h3 class="section-title">Patient</h3>
            <v-row dense>
              <v-col cols="12" sm="8">
                <v-text-field v-model="createDialog.form.patient_name" label="Patient name" placeholder="Juan Dela Cruz" variant="outlined" density="comfortable" :rules="[required]"></v-text-field>
              </v-col>
              <v-col cols="6" sm="4">
                <v-text-field v-model="createDialog.form.patient_age" label="Age" placeholder="45" type="number" min="0" max="150" variant="outlined" density="comfortable"></v-text-field>
              </v-col>
              <v-col cols="12" sm="8">
                <v-text-field v-model="createDialog.form.patient_address" label="Patient address" placeholder="Purok 3, San Isidro" variant="outlined" density="comfortable" :rules="[required]"></v-text-field>
              </v-col>
              <v-col cols="12" sm="4">
                <v-text-field v-model="createDialog.form.patient_contact_number" label="Contact number" placeholder="09171234567" variant="outlined" density="comfortable" :rules="[required]"></v-text-field>
              </v-col>
              <v-col cols="12">
                <v-textarea v-model="createDialog.form.medical_diagnosis" label="Medical diagnosis" placeholder="Suspected stroke" variant="outlined" density="comfortable" rows="2" :rules="[required]"></v-textarea>
              </v-col>
            </v-row>

            <h3 class="section-title">Trip</h3>
            <v-row dense>
              <v-col cols="12" sm="6">
                <v-text-field v-model="createDialog.form.origin" label="From:" placeholder="San Isidro" variant="outlined" density="comfortable" :rules="[required]"></v-text-field>
              </v-col>
              <v-col cols="12" sm="6">
                <v-text-field v-model="createDialog.form.destination" label="To:" placeholder="Echague District Hospital" variant="outlined" density="comfortable" :rules="[required]"></v-text-field>
              </v-col>
              <v-col v-if="vehiclesError" cols="12">
                <v-alert type="warning" variant="tonal" border="start" density="compact">
                  Could not load the fleet list: {{ vehiclesError }}
                  <template v-slot:append>
                    <v-btn variant="text" size="small" class="text-none font-weight-bold" :loading="vehiclesLoading" @click="fetchVehicles">Retry</v-btn>
                  </template>
                </v-alert>
              </v-col>
              <v-col cols="12" sm="6">
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
              <v-col v-if="!createDialog.form.vehicle_id" cols="12">
                <v-text-field
                  v-model="createDialog.form.vehicle"
                  label="Vehicle name (not in the fleet — e.g. mutual aid)"
                  placeholder="Alicia MDRRMO Ambulance"
                  variant="outlined"
                  density="comfortable"
                ></v-text-field>
              </v-col>
            </v-row>

            <!-- C7's double-booking guard. A soft block: filing anyway is
                 always possible, but only with a reason, and that reason is
                 what lands in the audit trail (ConductionRequestController::
                 store(), vehicle_override_reason). -->
            <v-alert v-if="createDialog.conflict" type="warning" variant="tonal" border="start" density="compact" class="mb-4">
              This unit is already on a trip — heading to {{ createDialog.conflict.destination }}
              (trip #{{ createDialog.conflict.conduction_request_id }}).
            </v-alert>
            <v-textarea
              v-if="createDialog.conflict"
              v-model="createDialog.form.override_reason"
              label="Reason to file anyway (required)"
              placeholder="Why this unit is being sent despite the conflict"
              variant="outlined"
              density="comfortable"
              rows="2"
              class="mb-4"
              :rules="[required]"
            ></v-textarea>

            <h3 class="section-title">Personnel</h3>
            <div v-for="group in personnelGroups" :key="group.field" class="mb-4">
              <div class="d-flex align-center justify-space-between mb-1">
                <span class="text-caption font-weight-bold text-uppercase text-medium-emphasis">{{ group.label }}</span>
                <v-btn
                  variant="text"
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
                <v-text-field
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
                  variant="text"
                  size="small"
                  :aria-label="`Remove ${group.singular} ${idx + 1}`"
                  @click="removePerson(group.field, idx)"
                ></v-btn>
              </div>
              <div v-if="createDialog.form[group.field].length >= group.max" class="text-caption text-medium-emphasis">
                Up to {{ group.max }} {{ group.label.toLowerCase() }}.
              </div>
            </div>
          </v-form>
        </v-card-text>
        <v-card-actions class="pa-6 pt-0 d-flex justify-end gap-3 border-t">
          <v-btn variant="text" class="text-none font-weight-bold" height="44" @click="createDialog.open = false">Cancel</v-btn>
          <v-btn color="primary" variant="flat" class="px-6 text-none font-weight-bold" height="44" :loading="loading" @click="submitCreate">
            {{ createDialog.conflict ? 'File anyway' : 'File request' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Detail -->
    <v-dialog v-model="detail.open" max-width="800" scrollable>
      <v-card rounded="lg" v-if="selected">
        <v-card-title class="d-flex justify-space-between align-center pa-6 border-b bg-surface">
          <div class="d-flex align-center gap-3">
            <span class="text-h6 font-weight-bold text-high-emphasis">{{ selected.patient_name }}</span>
            <StatusPill :status="pillStatus(selected)" :label="outcomeLabel(tripStatusLabel(selected.trip_status), selected.no_arrival_reason)" />
          </div>
          <v-btn icon="mdi-close" variant="text" size="small" aria-label="Close details" @click="detail.open = false"></v-btn>
        </v-card-title>
        <v-card-text class="pa-6" style="max-height: 65vh;">
          <!-- Only ever present on a trip dispatched from a resident's own
               booking — a walk-in trip log, still the common case, carries no
               service_request_id and shows none of this. -->
          <v-alert
            v-if="selected.service_request_id"
            type="info"
            variant="tonal"
            density="compact"
            border="start"
            class="mb-4"
          >
            <div class="d-flex align-center justify-space-between gap-3 flex-wrap">
              <div>
                <div class="text-caption text-uppercase font-weight-bold">Linked booking</div>
                <div class="text-body-2">
                  Booking #{{ selected.service_request_id }}
                  <template v-if="selected.service_request?.scheduled_at">
                    — scheduled {{ fmtDateTime(selected.service_request.scheduled_at) }}
                  </template>
                  <template v-if="selected.service_request?.status"> ({{ selected.service_request.status }})</template>
                </div>
              </div>
              <!-- The reverse of Bookings' own "Open Trip Record" (item 7 of
                   the layout redesign) — this link used to only go one way.
                   Text was there to read, nothing to click. -->
              <v-btn
                variant="outlined" size="small" class="text-none font-weight-bold flex-shrink-0"
                @click="openBooking(selected.service_request_id)"
              >Open Booking</v-btn>
            </div>
          </v-alert>

          <v-row class="mb-2">
            <v-col cols="6"><div class="field-label">Age</div><div class="field-value">{{ selected.patient_age ?? 'N/A' }}</div></v-col>
            <v-col cols="12"><div class="field-label">Address</div><div class="field-value">{{ selected.patient_address || 'N/A' }}</div></v-col>
            <v-col cols="6"><div class="field-label">Contact number</div><div class="field-value">{{ selected.patient_contact_number }}</div></v-col>
            <v-col cols="12"><div class="field-label">Medical diagnosis</div><div class="field-value">{{ selected.medical_diagnosis || 'N/A' }}</div></v-col>
            <v-col cols="6"><div class="field-label">From</div><div class="field-value">{{ selected.origin || 'N/A' }}</div></v-col>
            <v-col cols="6"><div class="field-label">To</div><div class="field-value">{{ selected.destination || 'N/A' }}</div></v-col>
            <v-col cols="6"><div class="field-label">Vehicle</div><div class="field-value">{{ selectedVehicleLabel }}<span v-if="selectedVehicleUnverified" class="text-caption text-medium-emphasis"> · fleet list unavailable</span></div></v-col>
            <!-- The fleet has no plate column, so a fleet-linked trip has no
                 plate to show; the free-text one belongs to the unlinked case. -->
            <v-col v-if="!selected.vehicle_id" cols="6"><div class="field-label">Plate no.</div><div class="field-value">{{ selected.plate_no || 'N/A' }}</div></v-col>
          </v-row>

          <v-divider class="my-4"></v-divider>

          <div v-for="group in personnelGroups" :key="group.field" class="mb-3">
            <div class="field-label">{{ group.label }}</div>
            <div v-if="peopleByRole(group.role).length > 0" class="field-value">
              {{ peopleByRole(group.role).map(p => p.name).join(', ') }}
            </div>
            <div v-else class="text-caption text-medium-emphasis">None recorded</div>
          </div>

          <v-divider class="my-4"></v-divider>

          <h3 class="section-title">Trip log</h3>
          <v-row>
            <v-col cols="6"><div class="field-label">Departed office</div><div class="field-value">{{ fmtDateTime(selected.departed_office_at) || '—' }}</div></v-col>
            <v-col cols="6"><div class="field-label">Arrived at destination</div><div class="field-value">{{ fmtDateTime(selected.arrived_destination_at) || '—' }}</div></v-col>
            <v-col cols="12" v-if="selected.no_arrival_reason"><div class="field-label">No-arrival reason</div><div class="field-value">{{ selected.no_arrival_reason }}</div></v-col>
            <v-col cols="6"><div class="field-label">Departed destination</div><div class="field-value">{{ fmtDateTime(selected.departed_destination_at) || '—' }}</div></v-col>
            <v-col cols="6"><div class="field-label">Returned to office</div><div class="field-value">{{ fmtDateTime(selected.returned_office_at) || '—' }}</div></v-col>
            <v-col cols="6"><div class="field-label">Odometer start</div><div class="field-value">{{ selected.odometer_start ?? '—' }}</div></v-col>
            <v-col cols="6"><div class="field-label">Odometer end</div><div class="field-value">{{ selected.odometer_end ?? '—' }}</div></v-col>
            <v-col cols="12" v-if="selected.others"><div class="field-label">Others</div><div class="field-value">{{ selected.others }}</div></v-col>
          </v-row>
        </v-card-text>
        <v-card-actions class="pa-6 pt-0 d-flex justify-end border-t">
          <v-btn variant="outlined" class="px-6 text-none font-weight-bold" height="44" prepend-icon="mdi-printer-outline" @click="printTrip(selected)">
            Print
          </v-btn>
          <v-btn color="primary" variant="flat" class="px-6 text-none font-weight-bold" height="44" @click="openTripLog(selected)">
            {{ tripLogAction(selected) }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Trip log -->
    <v-dialog v-model="tripLog.open" max-width="960" scrollable persistent>
      <v-card rounded="lg">
        <v-card-title class="pa-6 pb-2 text-subtitle-1 font-weight-bold text-high-emphasis border-b">
          {{ tripLog.title }}
        </v-card-title>
        <v-card-text class="pa-6" style="max-height: 70vh;">
          <v-alert v-if="tripLog.error" type="error" variant="tonal" density="compact" class="mb-4">{{ tripLog.error }}</v-alert>

          <!-- Two columns at md+: checkpoints/odometer on the left, personnel
               on the right — the same width this content needed to stop
               scrolling on a 600px-wide dialog (MDRRMO feedback,
               2026-09-18). Stacks to one column below md. -->
          <v-row>
            <v-col cols="12" md="6">
              <h3 class="section-title">Departure &amp; arrival</h3>
              <v-row dense>
                <v-col cols="12">
                  <DateTimePickerField v-model="tripLog.form.departed_office_at" type="datetime-local" label="Departed office" variant="outlined" density="comfortable"></DateTimePickerField>
                </v-col>
                <v-col cols="12">
                  <DateTimePickerField v-model="tripLog.form.arrived_destination_at" type="datetime-local" label="Arrived at destination" variant="outlined" density="comfortable"></DateTimePickerField>
                </v-col>
                <v-col cols="12">
                  <v-textarea
                    v-model="tripLog.form.no_arrival_reason"
                    label="No-arrival reason"
                    placeholder="e.g. Patient had already been taken by a relative"
                    hint="Only if the trip never reached its destination — an alternative to Arrived at destination, not an extra requirement."
                    persistent-hint
                    variant="outlined"
                    density="comfortable"
                    rows="2"
                  ></v-textarea>
                </v-col>
              </v-row>

              <h3 class="section-title">Return &amp; odometer</h3>
              <v-row dense>
                <v-col cols="12" sm="6">
                  <!-- Disabled rather than left typeable and refused afterwards. A
                       trip that never arrived has no departure from a destination
                       it never reached, and a 422 explaining that after the fact is
                       worse than a field that cannot be filled in the first place.
                       The hint says why, so the control does not just look broken. -->
                  <DateTimePickerField
                    v-model="tripLog.form.departed_destination_at"
                    type="datetime-local"
                    label="Departed destination"
                    variant="outlined"
                    density="comfortable"
                    :disabled="hasNoArrivalReason"
                    :hint="hasNoArrivalReason ? 'Not applicable — this trip never reached its destination.' : undefined"
                    :persistent-hint="hasNoArrivalReason"
                  ></DateTimePickerField>
                </v-col>
                <v-col cols="12" sm="6">
                  <DateTimePickerField v-model="tripLog.form.returned_office_at" type="datetime-local" label="Returned to office" variant="outlined" density="comfortable"></DateTimePickerField>
                </v-col>
                <v-col cols="12" sm="6">
                  <v-text-field v-model="tripLog.form.odometer_start" type="number" min="0" label="Odometer at departure" placeholder="10000" variant="outlined" density="comfortable"></v-text-field>
                </v-col>
                <v-col cols="12" sm="6">
                  <v-text-field v-model="tripLog.form.odometer_end" type="number" min="0" label="Odometer on return" placeholder="10042" variant="outlined" density="comfortable"></v-text-field>
                </v-col>
                <v-col cols="12">
                  <v-textarea v-model="tripLog.form.others" label="Others" placeholder="Anything else worth recording about the trip" variant="outlined" density="comfortable" rows="2"></v-textarea>
                </v-col>
              </v-row>
            </v-col>

            <v-col cols="12" md="6">
              <!-- All three PEOPLE_FIELDS roles, same pattern as the create
                   dialog's own Personnel section (personnelGroups) — see
                   ConductionRequestController::tripLog(). A stub created by
                   Approve & Dispatch (C5's bridge) always starts with none of
                   them, and a driver is required before this request can
                   resolve. -->
              <h3 class="section-title">Personnel</h3>
              <div v-for="group in personnelGroups" :key="group.field" class="mb-3">
                <div class="d-flex align-center justify-space-between mb-1">
                  <span class="text-caption font-weight-bold text-uppercase text-medium-emphasis">{{ group.label }}</span>
                  <v-btn
                    variant="text"
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
                  <v-text-field
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
                    variant="text"
                    size="small"
                    :aria-label="`Remove ${group.singular} ${idx + 1}`"
                    @click="removeTripPerson(group.field, idx)"
                  ></v-btn>
                </div>
                <div v-if="tripLog.form[group.field].length >= group.max" class="text-caption text-medium-emphasis">
                  Up to {{ group.max }} {{ group.label.toLowerCase() }}.
                </div>
              </div>
            </v-col>
          </v-row>
        </v-card-text>
        <v-card-actions class="px-6 pb-6 pt-0 d-flex justify-end gap-3">
          <v-btn variant="text" class="text-none font-weight-bold" height="44" @click="tripLog.open = false">Cancel</v-btn>
          <v-btn color="primary" variant="flat" class="px-6 text-none font-weight-bold" height="44" :loading="loading" @click="submitTripLog">Save trip log</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3500" location="bottom right" rounded="lg">
      {{ snackbar.text }}
    </v-snackbar>
  </v-container>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { getToken } from '@/composables/authToken'
import { displayPhone } from '@/composables/phoneNumber'
import { sharedStatusLabel, tripStatusLabel, outcomeLabel } from '@/composables/adminUi'
import { API_BASE } from '@/config/api'
import AmbulanceRequestQueue from '@/components/AmbulanceRequestQueue.vue'
import DateTimePickerField from '@/components/DateTimePickerField.vue'
import PageHeader from '@/components/PageHeader.vue'
import DataTablePage from '@/components/DataTablePage.vue'
import StatusPill from '@/components/StatusPill.vue'
import PersonCell from '@/components/PersonCell.vue'

// 'bookings' first: a staffer arriving on this page is more often checking on
// a resident's request than filling in a trip log by hand.
const activeTab = ref('bookings')

const ALL_STATUS = 'All'
// Filtering still compares the raw trip_status value (matchesStatus below is
// unchanged) -- only the label shown on the tab/badge moves to
// tripStatusLabel() (adminUi.ts), so the underlying value stays exactly what
// the API sends. Pill color still comes from sharedStatusLabel() separately
// -- the two only disagree on the 'Not dispatched' text.
const RAW_TRIP_STATUSES = ['Not dispatched', 'In transit', 'Completed']

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
  { field: 'authorized_passengers', role: 'passenger', label: 'Authorized Passengers', singular: 'passenger', min: 0, max: 2 },
  { field: 'patient_relatives', role: 'relative', label: 'Patient / Relatives', singular: 'relative', min: 0, max: 2 },
]

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
  { title: 'Patient', key: 'patient', value: 'patient_name', width: '28%' },
  { title: 'Status', key: 'trip_status', width: '18%' },
  { title: 'From → To', key: 'trip', value: (r) => `${r.origin || ''} ${r.destination || ''}`, width: '34%' },
  { title: 'Filed', key: 'created_at', width: '20%' },
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
const matchesStatus = (r) => statusFilter.value === ALL_STATUS || r.trip_status === statusFilter.value
const filteredItems = computed(() => items.value.filter((r) => matchesSearch(r) && matchesStatus(r)))

// SegmentedTabs' {value, label, count} shape. Counts are off the search
// match only, same as AmbulanceRequestQueue's own requestCounts — a status
// tab's count should not move just because a different status tab is
// selected.
const statusTabItems = computed(() => {
  const searched = items.value.filter(matchesSearch)
  return [
    { value: ALL_STATUS, label: ALL_STATUS, count: searched.length },
    ...RAW_TRIP_STATUSES.map((s) => ({
      value: s,
      label: tripStatusLabel(s),
      count: searched.filter((r) => r.trip_status === s).length,
    })),
  ]
})

// Search's own chip is DataTablePage's job. Status is the only other filter
// this page has, but it still needs its own chip + Clear all target — the
// active SegmentedTabs item shows the selection, not a way to jump back to
// All in one click alongside a cleared search.
const activeFilters = computed(() => (
  statusFilter.value === ALL_STATUS ? [] : [{ key: 'status', label: `Status: ${tripStatusLabel(statusFilter.value)}` }]
))
const clearFilter = (key) => { if (key === 'status') statusFilter.value = ALL_STATUS }
const clearAllFilters = () => { statusFilter.value = ALL_STATUS }

// StatusPill's :status prop wants an accent-table key (Booked/Responding/
// Resolved/'Resolved — no arrival') -- sharedStatusLabel() already maps a
// raw trip_status onto that same vocabulary; only the no-arrival split needs
// adding here, same condition outcomeLabel() uses for the label text.
const pillStatus = (item) => {
  const shared = sharedStatusLabel(item.trip_status)
  return shared === 'Resolved' && item.no_arrival_reason ? 'Resolved — no arrival' : shared
}

watch(search, () => { page.value = 1 })
watch(statusFilter, () => { page.value = 1 })

const getHeaders = () => ({
  Authorization: `Bearer ${getToken()}`,
  'Content-Type': 'application/json',
  Accept: 'application/json',
})

const fetchData = async () => {
  reloading.value = true
  try {
    const res = await fetch(`${API_BASE}/conduction-requests`, { headers: getHeaders() })
    if (!res.ok) {
      const errData = await res.json().catch(() => ({}))
      throw new Error(errData.message || `Request failed (${res.status})`)
    }
    const data = await res.json()
    if (!Array.isArray(data)) throw new Error('The server returned an unexpected response')
    items.value = data
    loadError.value = ''
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
    const res = await fetch(`${API_BASE}/admin/service-requests`, { headers: getHeaders() })
    if (!res.ok) {
      const errData = await res.json().catch(() => ({}))
      throw new Error(errData.message || `Request failed (${res.status})`)
    }
    const data = await res.json()
    bookings.value = (data.data || data).filter(r => r.service?.code === AMBULANCE_SERVICE_CODE)
    bookingsError.value = ''
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
    const res = await fetch(`${API_BASE}/vehicles`, { headers: getHeaders() })
    if (!res.ok) {
      const errData = await res.json().catch(() => ({}))
      throw new Error(errData.message || `Request failed (${res.status})`)
    }
    const data = await res.json()
    vehicles.value = data.data || data
    vehiclesError.value = ''
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
  if (!trip) return 'N/A'
  if (!trip.vehicle_id) return trip.vehicle || 'N/A'
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
  // A new pick clears any conflict the previous one raised — it may not
  // apply to this unit at all.
  createDialog.value.conflict = null
}

// Create dialog
const emptyCreateForm = () => ({
  // Set only when this dialog was opened by dispatching an approved booking
  // (handleDispatchBooking below); a plain "Ambulance Trip Record"
  // leaves both null, exactly as before this feature existed.
  service_request_id: null, vehicle_id: null, override_reason: '',
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
  drivers: [''], authorized_passengers: [''], patient_relatives: [''],
})
const createDialog = ref({ open: false, form: emptyCreateForm(), conflict: null })
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
  createDialog.value.conflict = null
}

const openCreate = (booking = null) => {
  apiError.value = ''
  const form = emptyCreateForm()
  if (booking) applyBooking(booking, form)
  createDialog.value = { open: true, form, conflict: null }
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

const addPerson = (field) => { createDialog.value.form[field].push('') }
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
      // 409: the double-booking guard, not a validation failure — surfaced
      // as its own alert plus the required override field (see the
      // template) rather than the generic apiError text, since "already on
      // a trip" needs the conflicting trip named, not just stated.
      if (res.status === 409 && errData.conflict) {
        createDialog.value.conflict = errData.conflict
        return
      }
      const firstError = errData.errors ? Object.values(errData.errors)[0]?.[0] : null
      throw new Error(firstError || errData.message || 'Failed to file the request')
    }
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

const openDetail = (item) => {
  apiError.value = ''
  selected.value = item
  detail.value.open = true
}

const peopleByRole = (role) => (selected.value?.people || []).filter((p) => p.role === role)

// Trip log dialog
const emptyTripLogForm = () => ({
  departed_office_at: '', arrived_destination_at: '', no_arrival_reason: '', departed_destination_at: '', returned_office_at: '',
  odometer_start: null, odometer_end: null, others: '',
  // All three PEOPLE_FIELDS roles — see ConductionRequestController::
  // tripLog(). A stub created by C5's bridge (openTripRecord below) always
  // starts with none of them.
  drivers: [''], authorized_passengers: [''], patient_relatives: [''],
})
const addTripPerson = (field) => { tripLog.value.form[field].push('') }
const removeTripPerson = (field, idx) => { tripLog.value.form[field].splice(idx, 1) }
const tripLog = ref({ open: false, form: emptyTripLogForm(), error: '', target: null, title: '' })

// The button that opens the dialog and the dialog's own title read the same
// record, so they come from one place rather than two copies that can drift.
const tripLogAction = (record) => (record?.departed_office_at ? 'Update trip log' : 'Complete Trip Log')

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
  tripLog.value = {
    open: true,
    error: '',
    target: record,
    // Captured at open time so the title stays put while the form is edited.
    title: tripLogAction(record),
    form: {
      departed_office_at: toInputValue(record.departed_office_at),
      arrived_destination_at: toInputValue(record.arrived_destination_at),
      no_arrival_reason: record.no_arrival_reason || '',
      departed_destination_at: toInputValue(record.departed_destination_at),
      returned_office_at: toInputValue(record.returned_office_at),
      odometer_start: record.odometer_start,
      odometer_end: record.odometer_end,
      others: record.others || '',
      ...Object.fromEntries(personnelGroups.map(({ field, role }) => {
        const names = (record.people || []).filter(p => p.role === role).map(p => p.name)
        return [field, names.length > 0 ? names : ['']]
      })),
    },
  }
}

// Drives the Departed destination field's disabled state. Reads the form rather
// than the saved record: the field must go dead as soon as staff type a reason,
// not only after the log has been saved with one.
const hasNoArrivalReason = computed(
  () => (tripLog.value.form.no_arrival_reason || '').trim() !== '',
)

const CHECKPOINTS = [
  ['departed_office_at', 'Departed office'],
  ['arrived_destination_at', 'Arrived at destination'],
  ['departed_destination_at', 'Departed destination'],
  ['returned_office_at', 'Returned to office'],
]

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
  const noArrival = (form.no_arrival_reason || '').trim() !== ''

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
      no_arrival_reason: form.no_arrival_reason || null,
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
    await fetchData()
    selected.value = updated
    tripLog.value.open = false
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
})
</script>

<style scoped>
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }

.section-title {
  font-size: 0.78rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgb(var(--v-theme-primary-strong));
  margin: 20px 0 10px;
}
.section-title:first-child { margin-top: 0; }

.field-label {
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), 0.6);
}
.field-value {
  font-size: 0.95rem;
  font-weight: 500;
  color: rgb(var(--v-theme-on-surface));
  margin-bottom: 8px;
}


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
