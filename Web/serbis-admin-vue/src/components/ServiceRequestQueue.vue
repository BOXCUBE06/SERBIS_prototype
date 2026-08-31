<template>
  <!-- Embedded (standalone=false) drops the fixed calc(100vh - 300px): that
       number assumed this component owned a whole route's worth of vertical
       space below a fixed page chrome, which is not true on the Ambulance
       Dispatch Requests tab — its own header and tab bar sit above this, at
       a height this component has no way to know. Forcing a height here
       anyway is what produced the internal list/detail scrollbars: the page
       already scrolls, so this must stop competing with it (layout
       redesign, impeccable review 2026-08-31, item 2). -->
  <v-container
    fluid
    class="overflow-hidden dashboard-bg"
    :class="standalone ? 'pa-5 fill-height' : 'pa-0'"
    :style="standalone ? {} : { minHeight: '480px' }"
  >
    <div class="d-flex flex-column w-100" :class="{ 'h-100': standalone }">

      <!-- Toolbar -->
      <div class="d-flex justify-space-between align-center w-100 mb-3 flex-wrap gap-3">
        <div>
          <h2 class="text-h5 font-weight-bold" style="line-height: 1; margin-bottom: 4px;">{{ scope === 'ambulance' ? 'Ambulance Bookings' : 'Resident Requests' }}</h2>
          <div class="text-body-2 text-medium-emphasis" style="line-height: 1;">{{ requestCounts.All }} {{ scope === 'ambulance' ? 'ambulance bookings' : 'requests across all barangays' }}</div>
        </div>
        <div class="d-flex align-center gap-3">
        <!-- The adviser's ask: someone who shows up at the office in person
             rather than through the app, with or without an account. A
             separate button rather than folding this into the export/filter
             row, since filing a request is a different kind of action from
             everything else up here. -->
        <v-btn
          color="secondary"
          variant="flat"
          class="text-none font-weight-bold px-6 text-white"
          height="40"
          @click="openCreateDialog"
        >
          <v-icon start size="small">mdi-account-plus-outline</v-icon>
          Log Service Request
        </v-btn>
        <!-- Same outlined-primary treatment as Export below: a supporting
             view, not the page's one decision. Ambulance-only: the fleet
             schedule this shows has nothing to say about a road-clearing
             crew's queue. -->
        <v-btn
          v-if="scope === 'ambulance'"
          color="primary"
          variant="outlined"
          class="text-none font-weight-bold px-6"
          height="40"
          @click="openDayView"
        >
          <v-icon start size="small">mdi-calendar-clock</v-icon>
          Ambulance Day View
        </v-btn>
        <!-- This used to be a button with no handler and no export function
             behind it, styled larger than either real action on the page. It
             now writes what the operator is actually looking at: the current
             filter and search, in the order shown, not all 30 rows. -->
        <!-- Outlined, not filled. Squinting at this screen, the heaviest mark
             on it was this button: a near-black secondary fill on a pale page,
             out-weighing "Approve & Dispatch" from the other end of the layout.
             Exporting a CSV is a side errand. Same outlined-primary treatment
             the vehicle picker uses, so the panel has one language for a
             supporting action and keeps the fill for the decision. -->
        <v-btn
          color="primary"
          variant="outlined"
          class="text-none font-weight-bold px-6"
          height="40"
          :disabled="!filteredAndSortedRequests.length"
          @click="exportCsv"
        >
          <v-icon start size="small">mdi-tray-arrow-down</v-icon>
          {{ filteredAndSortedRequests.length ? `Export ${filteredAndSortedRequests.length}` : 'Nothing to export' }}
          <span v-if="filteredAndSortedRequests.length" class="d-sr-only">requests as CSV</span>
        </v-btn>
        </div>
      </div>

      <!-- Split view: list + detail panel.
           Side by side on a desk, which is where this screen is used. Below the
           md breakpoint the two stop competing for one narrow column and become
           one surface at a time: the list until a request is picked, the detail
           with a way back after. -->
      <!-- overflow-hidden/min-height:0 only apply standalone: that pairing is
           what bounds this row to the root container's fixed height and
           forces the list/detail panes below to scroll internally instead of
           growing — exactly what standalone=false must not do (see the root
           v-container's own comment above). -->
      <div
        class="d-flex flex-grow-1 gap-4"
        :class="[twoUp ? 'flex-row' : 'flex-column', standalone ? 'overflow-hidden' : '']"
        :style="standalone ? 'min-height: 0;' : ''"
      >

        <!-- LEFT: request list -->
        <v-card
          v-if="twoUp || !selectedRequest"
          elevation="0"
          rounded="xl"
          class="soft-card d-flex flex-column overflow-hidden request-list"
          :class="twoUp ? 'request-list--rail' : 'flex-grow-1'"
        >
          <div class="pa-4 pb-2" style="flex-shrink: 0;">
            <v-text-field
              v-model="search"
              prepend-inner-icon="mdi-magnify"
              placeholder="Search by name, service, barangay..."
              variant="outlined"
              density="compact"
              hide-details
              clearable
              class="mb-3"
            ></v-text-field>

            <!-- Typing narrows the list but leaves the status counts alone, so
                 without this the operator cannot tell an empty result from a
                 filter that is hiding it. -->
            <div v-if="search" class="text-caption text-medium-emphasis mb-2" aria-live="polite">
              {{ filteredAndSortedRequests.length }}
              {{ filteredAndSortedRequests.length === 1 ? 'request matches' : 'requests match' }} "{{ search }}"
            </div>

            <!-- Every status, always — including a Disapproved or Cancelled
                 reading zero. Hiding an empty status read as "this queue has
                 nothing named Disapproved" rather than "nothing is disapproved
                 right now"; the chip staying put and the list explaining the
                 zero is the honest version. -->
            <!-- The group owns the selection, via v-model + :value on each chip.
                 It used to have neither: the chips drove `filters.status` from
                 their own @click while VChipGroup ran a second, independent
                 useGroup selection that nothing ever set. VChip only applies its
                 `color` when the GROUP considers it selected, so the active chip
                 got `variant="flat"` from the ternary and then no `bg-primary`
                 to go with it — it rendered plain grey, telling active from
                 inactive by lightness alone. `mandatory` keeps one always on, so
                 clicking the selected chip cannot clear the filter to nothing. -->
            <v-chip-group v-if="!initialLoad" v-model="filters.status" mandatory column>
              <v-chip
                v-for="status in statusTabs" :key="status"
                :value="status"
                size="small" class="font-weight-bold"
                color="primary"
                :variant="status === filters.status ? 'flat' : 'tonal'"
              >
                {{ status }} <span class="ml-1 font-weight-black">{{ requestCounts[status] }}</span>
              </v-chip>
            </v-chip-group>
            <v-skeleton-loader v-else type="chip" width="100%" height="32"></v-skeleton-loader>
          </div>

          <!-- Bulk action bar. This was already wired to bulkDisapprove(), but
               both controls were text buttons at the same weight, so the one
               action the checkboxes exist for read as a caption sitting beside
               "3 selected" rather than as the thing to press. The count moves
               into the label — the button now names what it does and to how
               many — and the destructive action takes the rightmost slot, the
               same order the confirm dialog below uses. -->
          <div v-if="selectedIds.size > 0" class="d-flex align-center justify-space-between px-4 py-2 subtle-surface" style="flex-shrink: 0;">
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

          <v-divider></v-divider>

          <!-- Scrolls only standalone. Embedded, the page itself scrolls —
               see the root v-container comment — and itemsPerPage below
               already bounds how many rows this ever holds at once. -->
          <div class="flex-grow-1" :class="{ 'overflow-y-auto': standalone }">
            <v-skeleton-loader v-if="initialLoad" type="list-item-avatar-two-line@6"></v-skeleton-loader>

            <div v-else-if="!pagedRequests.length" class="text-center text-caption text-medium-emphasis py-10">
              {{ emptyListMessage }}
            </div>

            <div v-else>
              <!-- Selecting a request is the entry point to every other action
                   on this page, and it was a bare div with a click handler: not
                   in the tab order, not announced as interactive, unreachable
                   without a mouse. Space is prevented explicitly or it scrolls
                   the list instead of opening the row. -->
              <div
                v-for="item in pagedRequests" :key="item.request_id || item.id"
                class="d-flex align-center px-4 py-3 request-row"
                :class="[`row-${(item.status || 'Pending').toLowerCase()}`, { 'row-selected': isSelected(item) }]"
                role="button"
                tabindex="0"
                :aria-current="isSelected(item) ? 'true' : undefined"
                :aria-label="`${requesterName(item)}, ${item.service?.service_name || 'service'}, ${item.status || 'Pending'}`"
                @click="selectRequest(item)"
                @keydown.enter.prevent="selectRequest(item)"
                @keydown.space.prevent="selectRequest(item)"
              >
                <!-- flex-shrink-0 alone let this GROW into whatever space the
                     row had left, which is why the avatars beside it sat at a
                     different x on every row. It is a fixed-size control. -->
                <v-checkbox-btn
                  :model-value="selectedIds.has(itemId(item))"
                  class="mr-1"
                  style="flex: 0 0 auto;"
                  density="compact"
                  :aria-label="`Select ${requesterName(item)}'s request`"
                  @click.stop="toggleSelect(item)"
                ></v-checkbox-btn>
                <v-avatar color="primary" variant="tonal" size="36" class="mr-3 flex-shrink-0">
                  <span class="font-weight-bold text-caption">
                    {{ requesterInitials(item) }}
                  </span>
                </v-avatar>
                <!-- The date is the half of this line that survives truncation
                     worst, and it is the half that decides what is urgent, so
                     it gets its own column instead of trailing the service
                     name off the end of the row. -->
                <div class="flex-grow-1 min-width-0">
                  <div class="text-body-2 font-weight-bold text-truncate">{{ requesterName(item) }}</div>
                  <div class="d-flex align-center text-caption text-medium-emphasis">
                    <span class="text-truncate">{{ item.service?.service_name || 'N/A' }}</span>
                    <!-- A Booked row's own scheduled time is the date an operator
                         actually needs here, not when it was filed — created_at
                         stays as the fallback for every other status. -->
                    <template v-if="item.scheduled_at">
                      <v-icon size="12" class="ml-2 mr-1 flex-shrink-0">mdi-calendar-clock</v-icon>
                      <span class="row-date">{{ formatDateTime(item.scheduled_at) }}</span>
                    </template>
                    <span v-else class="row-date ms-2">{{ formatDate(item.created_at) }}</span>
                  </div>
                </div>
                <span class="status-pill status-pill--sm ml-2 flex-shrink-0" :class="statusPillClass(item.status)">{{ item.status || 'Pending' }}</span>
              </div>

              <!-- itemsPerPage is sized off windowHeight so a full page fills
                   the panel with no gap (see the computed above) — that
                   leaves this blank whenever a filter/search genuinely has
                   fewer results than a page holds, which reads as broken
                   rather than as "this is everything" (impeccable ui-audit,
                   2026-08-30). pagedRequests.length < itemsPerPage only ever
                   true on the last page, so this can't appear mid-list. -->
              <div
                v-if="pagedRequests.length < itemsPerPage"
                class="text-center text-caption text-medium-emphasis py-6"
              >
                Showing all {{ filteredAndSortedRequests.length }} {{ filteredAndSortedRequests.length === 1 ? 'result' : 'results' }}
              </div>
            </div>
          </div>

          <div class="d-flex justify-center pa-2" style="flex-shrink: 0;">
            <!-- active-color was `secondary` (#0A2620), which is 1.07:1 on the
                 dark surface — the current page number simply was not there. -->
            <v-pagination v-model="page" :length="pageCount" :total-visible="4" density="compact" active-color="primary"></v-pagination>
          </div>
        </v-card>

        <!-- RIGHT: detail panel -->
        <v-card
          v-if="twoUp || selectedRequest"
          elevation="0"
          rounded="xl"
          class="soft-card d-flex flex-column overflow-hidden flex-grow-1"
        >
          <div v-if="!selectedRequest" class="d-flex flex-column align-center justify-center h-100 text-medium-emphasis pa-6 text-center">
            <v-icon size="48" class="mb-3">mdi-clipboard-text-outline</v-icon>
            <div class="text-body-1">Select a request to view details</div>
            <div class="text-caption mt-1">Its description, attachments and dispatch options open here.</div>
          </div>

          <template v-else>
            <div class="d-flex justify-space-between align-center pa-6 pb-4" style="flex-shrink: 0;">
              <div class="d-flex align-center gap-3">
                <!-- Stacked, the list is gone from the screen; without this the
                     only way back to it is the browser's own back button. -->
                <v-btn
                  v-if="!twoUp"
                  icon="mdi-arrow-left"
                  variant="text"
                  density="comfortable"
                  aria-label="Back to the request list"
                  @click="selectedRequest = null"
                ></v-btn>
                <v-avatar color="primary" variant="tonal" size="52">
                  <span class="text-h6 font-weight-black">
                    {{ requesterInitials(selectedRequest) }}
                  </span>
                </v-avatar>
                <div>
                  <div class="text-h6 font-weight-bold" style="line-height: 1.2;">{{ requesterName(selectedRequest) }}</div>
                  <div class="text-caption text-medium-emphasis">{{ requesterBarangay(selectedRequest) }}</div>
                </div>
              </div>
              <span class="status-pill" :class="statusPillClass(selectedRequest.status)">
                {{ selectedRequest.status || 'Pending' }}
              </span>
            </div>

            <v-divider></v-divider>

            <!-- flex-grow-1 forced this to fill all remaining panel height
                 regardless of content, pinning the footer at the fixed
                 bottom edge with dead space above it (impeccable ui-audit,
                 2026-08-30). Dropped: short content now hugs its own size
                 and the footer follows directly after it — verified via
                 exact pixel offsets, not just visually. Verified separately
                 with a synthetic several-thousand-character description:
                 unusually long content grows the card (bounded by the
                 outer container's own fixed max height and overflow:hidden,
                 unchanged by this edit) rather than triggering an internal
                 scrollbar here — that is pre-existing overflow-y-auto
                 behavior this change did not alter, and real admin remarks
                 are short operational notes, not thousands of characters. -->
            <!-- Scrolls only standalone, same reasoning as the list's own
                 scroll region above. Embedded, this grows to its natural
                 content height and the footer below follows directly after
                 it in normal page flow rather than in its own clipped
                 region. -->
            <div class="pa-6" :class="{ 'overflow-y-auto': standalone }">
              <v-alert v-if="apiError" type="error" variant="tonal" class="mb-4" density="compact">{{ apiError }}</v-alert>

              <v-row class="detail-group">
                <v-col cols="12" sm="4" :md="selectedRequest.scheduled_at ? 3 : 4">
                  <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Service</div>
                  <div class="font-weight-bold text-body-1">{{ selectedRequest.service?.service_name || 'N/A' }}</div>
                </v-col>
                <v-col cols="12" sm="4" :md="selectedRequest.scheduled_at ? 3 : 4">
                  <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Submitted</div>
                  <div class="font-weight-medium text-body-2">{{ formatDateTime(selectedRequest.created_at) }}</div>
                </v-col>
                <v-col v-if="selectedRequest.scheduled_at" cols="12" sm="4" md="3">
                  <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Scheduled</div>
                  <div class="font-weight-medium text-body-2">
                    {{ formatDateTime(selectedRequest.scheduled_at) }}
                    <template v-if="selectedRequest.scheduled_end"> – {{ formatTime(selectedRequest.scheduled_end) }}</template>
                  </div>
                </v-col>
                <v-col cols="12" sm="4" :md="selectedRequest.scheduled_at ? 3 : 4">
                  <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Phone</div>
                  <div class="font-weight-medium text-body-2">{{ requesterPhone(selectedRequest) }}</div>
                </v-col>
              </v-row>

              <div class="detail-group">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-2">Description</div>
                <!-- One element per line. The description arrives newline-
                     separated and was rendered as a single interpolation, so
                     every break collapsed to a space and the whole thing read
                     as one run-on sentence. -->
                <v-card variant="outlined" class="pa-4 text-body-2 rounded-lg subtle-surface" style="border-color: rgba(var(--v-theme-on-surface), 0.08);">
                  <template v-if="descriptionLines.length">
                    <div v-for="(line, i) in descriptionLines" :key="i" class="description-line">{{ line }}</div>
                  </template>
                  <template v-else>No description provided by the Head of the Family.</template>
                </v-card>
              </div>

              <!-- One group, two tiles, site photo first: it is what the
                   resident is reporting and what decides whether a unit is
                   sent, and the ID answers a different question after it.
                   `attachments` keeps that order.

                   These were full-width boxes holding a contained image. A
                   square 240px source in a 1005px-wide frame painted at
                   198x198 with roughly 400px of flat tint either side, which
                   reads as a broken image rather than a small one. Fixed
                   180x140 tiles, filled with `cover`, and the full picture is
                   a click away. -->
              <div class="detail-group" v-if="attachments.length">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-2">Attachments</div>

                <div class="d-flex flex-wrap gap-3">
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

              <v-divider class="detail-rule"></v-divider>

              <!-- The Dispatch Assignment card used to live here, and its only
                   control was the button that enables the action pinned to the
                   footer. The two now share the footer row, so the card would
                   be a heading over a sentence. Its unit display went with the
                   button rather than being left behind. -->

              <div v-if="selectedRequest.status === 'Responding'" class="detail-group">
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

              <!-- The trip record C5 creates the moment this request went
                   Responding (ServiceRequestController::createConductionStub)
                   — surfaced here rather than rebuilt here: "Open Trip
                   Record" jumps to the Trip Logs tab's own dialog, which
                   already has every field (checkpoints, odometer, drivers,
                   passengers, relatives) rather than a second, thinner copy
                   of the same form living in this panel. Resolving is
                   refused server-side without an arrival time, both
                   odometer readings and a driver — the error surfaces
                   through the alert above the fields, same as any other
                   apiError, naming exactly what's missing. -->
              <div v-if="scope === 'ambulance'" class="detail-group">
                <v-alert type="warning" variant="tonal" border="start" rounded="lg">
                  <div class="text-subtitle-2 font-weight-bold mb-1">Trip record</div>
                  <template v-if="respondingTrip">
                    <div class="text-body-2">
                      {{ tripDriverNames || 'No driver recorded yet' }}
                      <template v-if="respondingTrip.arrived_destination_at"> &bull; arrived {{ formatDateTime(respondingTrip.arrived_destination_at) }}</template>
                    </div>
                    <div class="text-caption text-medium-emphasis mb-2">
                      Odometer: {{ respondingTrip.odometer_start ?? '—' }} → {{ respondingTrip.odometer_end ?? '—' }}
                    </div>
                    <v-btn
                      variant="outlined" size="small" class="text-none font-weight-bold"
                      @click="emit('open-trip-record', respondingTrip.conduction_request_id)"
                    >Open Trip Record</v-btn>
                  </template>
                  <div v-else class="text-body-2">
                    No trip record found for this request — Mark as Resolved will explain what's missing.
                  </div>
                </v-alert>
              </div>

              <!-- Staff-only scratch pad. Deliberately never pre-fills the
                   approve/decline dialog below (reasonDialog) — that field
                   goes to the requester, this one never does, and the two
                   sharing a column used to mean an internal shorthand could
                   reach a resident's phone unedited. Visible regardless of
                   status: a note about what happened is still useful to read
                   on a Resolved request, not just a Pending one. -->
              <v-textarea
                v-model="formData.internal_notes" label="Internal note (staff only)" variant="outlined" density="comfortable" rounded="lg" rows="2"
                hint="Never shown to the requester — for staff reading this request later."
                persistent-hint
              ></v-textarea>
              <!-- Its own save path, not the reasonDialog's: a terminal
                   request (Resolved, Disapproved, Cancelled) shows no
                   approve/decline action at all, so this is the only way a
                   note typed here ever reaches the server. update() already
                   accepts a partial body — no status, no vehicle_id — so this
                   PATCH touches nothing else on the request. -->
              <div class="d-flex align-center gap-3 mb-2">
                <v-btn
                  variant="outlined" color="primary" size="small" class="text-none font-weight-bold"
                  :loading="noteSaving"
                  @click="saveInternalNote"
                >Save note</v-btn>
                <span v-if="noteSaved" class="text-caption text-success">Saved</span>
              </div>
            </div>

            <v-divider v-if="showActions"></v-divider>
            <div v-if="showActions" class="d-flex justify-end align-center pa-4 gap-3 flex-wrap" style="flex-shrink: 0;">
              <template v-if="selectedRequest.status === 'Pending' || !selectedRequest.status">
                <!-- The unit and the button it unlocks now sit in one row. They
                     used to be a scroll apart — the picker was a card up in the
                     body, the action it gated was pinned down here — so an
                     operator reading a greyed-out "Approve & Dispatch" had to go
                     hunting for the reason. The gate itself has always been
                     real; only the distance was the problem. -->
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

                <!-- primary, not secondary. Secondary is #0A2620, a near-black
                     green that works as a fill under a white label and vanishes
                     as an outline on the dark theme's own dark surface. -->
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
                  Approve &amp; Dispatch
                </v-btn>
                <span v-if="!formData.vehicle_id" id="dispatch-gate" class="d-sr-only">
                  Disabled until a vehicle is chosen with the Select Vehicle button beside it.
                </span>
              </template>
              <template v-else-if="selectedRequest.status === 'Responding'">
                <v-btn color="success" variant="flat" class="text-none font-weight-bold w-100" height="40" :loading="loading" @click="updateStatus('Resolved')">
                  Mark as Resolved
                </v-btn>
              </template>
              <template v-else-if="selectedRequest.status === 'Booked'">
                <!-- Not yet approved: same unit-and-button row as the Pending
                     branch above, same gating, same picker dialog — only the
                     data source differs (see openAssignUnitModal), and Reject
                     reuses the existing Disapprove flow untouched. -->
                <template v-if="!selectedRequest.vehicle_id">
                  <div class="d-flex align-center gap-3 min-width-0 mr-auto dispatch-state">
                    <v-avatar :color="formData.vehicle_id ? 'success' : undefined" variant="tonal" size="36">
                      <v-icon size="20" :color="formData.vehicle_id ? 'success' : undefined">
                        {{ formData.vehicle_id ? vehicleIcon(selectedVehicle?.type) : 'mdi-car-off' }}
                      </v-icon>
                    </v-avatar>
                    <div class="min-width-0">
                      <div class="text-body-2 font-weight-bold text-truncate">
                        {{ formData.vehicle_id ? getSelectedVehicleName() : 'No unit selected' }}
                      </div>
                      <div class="text-caption text-medium-emphasis text-truncate">
                        <template v-if="formData.vehicle_id">
                          Free for this window<template v-if="selectedVehicle?.specification"> &bull; {{ selectedVehicle.specification }}</template>
                        </template>
                        <template v-else>Select a unit free for the scheduled window.</template>
                      </div>
                    </div>
                  </div>

                  <v-btn
                    color="primary"
                    variant="outlined"
                    class="text-none font-weight-bold"
                    height="40"
                    :loading="scheduledAvailabilityLoading"
                    @click="openAssignUnitModal"
                  >
                    {{ formData.vehicle_id ? 'Change Unit' : 'Assign Unit' }}
                  </v-btn>
                  <v-btn variant="text" class="text-none font-weight-bold" height="40" @click="openReschedule">
                    Reschedule
                  </v-btn>
                  <v-btn color="error" variant="text" class="text-none font-weight-bold" height="40" :loading="loading" @click="openReason('disapprove')">
                    Reject
                  </v-btn>
                  <v-btn
                    color="secondary"
                    variant="flat"
                    class="text-none font-weight-bold text-white"
                    height="40"
                    :loading="loading"
                    :disabled="!formData.vehicle_id"
                    :aria-describedby="!formData.vehicle_id ? 'approve-gate' : undefined"
                    @click="approveBooking"
                  >
                    Approve
                  </v-btn>
                  <span v-if="!formData.vehicle_id" id="approve-gate" class="d-sr-only">
                    Disabled until a unit is chosen with the Assign Unit button beside it.
                  </span>
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
          </template>
        </v-card>
      </div>
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
    <v-dialog v-model="vehicleModal.isOpen" max-width="600">
      <v-card rounded="lg" elevation="6">
        <v-card-title class="pa-4 border-b d-flex justify-space-between align-center">
          <span class="text-h6 font-weight-bold">
            {{ selectedRequest?.status === 'Booked' ? 'Units Free for This Window' : 'Available Vehicles' }}
          </span>
          <v-btn icon="mdi-close" variant="text" density="comfortable" @click="vehicleModal.isOpen = false"></v-btn>
        </v-card-title>

        <!-- A two-across grid of cards for a list of identical units. Each tile
             carried three words and the eye had to travel in two directions to
             compare fourteen of them. One column, one unit per row, matching the
             fleet list this picker is a view of. -->
        <v-card-text class="pa-0 subtle-surface" style="max-height: 400px; overflow-y: auto;">
          <div v-if="scheduledAvailabilityLoading" class="pa-4">
            <v-skeleton-loader type="list-item-avatar-two-line" v-for="n in 3" :key="n" class="mb-1"></v-skeleton-loader>
          </div>
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
              {{ selectedRequest?.status === 'Booked'
                ? 'No Ambulance unit is free for the scheduled window. Try Reschedule instead.'
                : 'All fleet vehicles are currently dispatched or under maintenance.' }}
            </div>
          </div>
        </v-card-text>
      </v-card>
    </v-dialog>

    <!-- Every approve and decline now stops here first. The remarks field on the
         detail panel was optional and skipped, so a disapproved request reached
         the resident's phone as a red status with nothing under it. Declining
         requires a reason; approving only asks for one, since a dispatched unit
         is its own explanation. Bulk decline gets one reason for the whole
         selection, which is the only thing it could ever have written -- it used
         to resend each row's existing remarks, so it captured nothing at all. -->
    <v-dialog v-model="reasonDialog.open" max-width="440" @after-leave="clearReason">
      <v-card rounded="lg">
        <v-card-title class="text-subtitle-1 font-weight-bold pa-5 pb-2 text-high-emphasis">
          {{ reasonCopy.title }}
        </v-card-title>
        <v-card-text class="px-5 pt-2">
          <div class="text-body-2 text-medium-emphasis mb-4">{{ reasonCopy.body }}</div>
          <v-textarea
            v-if="reasonCopy.showField"
            v-model="reasonDialog.reason"
            :label="reasonCopy.label"
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

    <!-- Reschedule a Booked request. Its own dialog rather than folding into
         reasonDialog above: that one collects one reason string for a status
         flip, this collects two datetimes plus a reason, and remarks here is
         required unconditionally, not gated on `kind`. -->
    <v-dialog v-model="rescheduleDialog.open" max-width="440">
      <v-card rounded="lg">
        <v-card-title class="text-subtitle-1 font-weight-bold pa-5 pb-2 text-high-emphasis">
          Reschedule booking
        </v-card-title>
        <v-card-text class="px-5 pt-2">
          <v-text-field
            v-model="rescheduleDialog.form.scheduled_at"
            type="datetime-local"
            label="New scheduled time"
            variant="outlined"
            density="comfortable"
            class="mb-3"
          ></v-text-field>
          <v-text-field
            v-model="rescheduleDialog.form.scheduled_end"
            type="datetime-local"
            label="Ends"
            variant="outlined"
            density="comfortable"
            class="mb-3"
          ></v-text-field>
          <v-textarea
            v-model="rescheduleDialog.form.remarks"
            label="Reason for the change"
            hint="Required — this is what the Head of the Family sees, and what the log records."
            persistent-hint
            variant="outlined"
            rows="2"
            counter="255"
            maxlength="255"
            :error-messages="rescheduleDialog.error"
            @update:model-value="rescheduleDialog.error = ''"
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
            <v-text-field
              v-model="dayView.date"
              type="date"
              variant="outlined"
              density="compact"
              hide-details
              style="max-width: 170px;"
            ></v-text-field>
            <v-btn icon="mdi-close" variant="text" size="small" aria-label="Close" @click="dayView.open = false"></v-btn>
          </div>
        </v-card-title>

        <v-card-text class="pa-6">
          <div v-if="dayView.loading">
            <v-skeleton-loader v-for="n in 4" :key="n" type="list-item-two-line" class="mb-3"></v-skeleton-loader>
          </div>

          <template v-else-if="dayView.units.length">
            <!-- Hour scale, shared by every track below it. -->
            <div class="day-view-scale">
              <span v-for="mark in dayViewHourMarks" :key="mark.hour" class="day-view-scale-label" :style="{ left: mark.left }">{{ mark.label }}</span>
            </div>

            <div v-for="unit in dayView.units" :key="unit.vehicle_id" class="day-view-row">
              <div class="day-view-unit">
                <div class="font-weight-bold text-body-2 text-truncate">{{ unit.unit_identifier }}</div>
                <div class="text-caption text-medium-emphasis text-truncate">{{ unit.specification || '&nbsp;' }}</div>
                <span v-if="unit.is_maintenance" class="status-pill status-pill--sm pill-disapproved mt-1">Maintenance</span>
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
              variant="outlined"
              density="comfortable"
              class="mb-2"
              :rules="[required]"
            ></v-text-field>
            <v-text-field
              v-model="createDialog.form.walk_in_contact_number"
              label="Contact number"
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
                  label="Patient name" variant="outlined" density="comfortable" class="mb-2"
                  :rules="[required]"
                ></v-text-field>
              </v-col>
              <v-col cols="6" sm="2">
                <v-text-field
                  v-model="createDialog.form.patient_age"
                  label="Age" type="number" min="0" max="150" variant="outlined" density="comfortable" class="mb-2"
                ></v-text-field>
              </v-col>
              <v-col cols="6" sm="2">
                <v-select
                  v-model="createDialog.form.patient_sex"
                  :items="sexOptions"
                  label="Sex" variant="outlined" density="comfortable" clearable class="mb-2"
                ></v-select>
              </v-col>
            </v-row>
            <v-text-field
              v-model="createDialog.form.patient_address"
              label="Patient address" variant="outlined" density="comfortable" class="mb-2"
              :rules="[required]"
            ></v-text-field>
            <v-row dense>
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model="createDialog.form.pickup_location"
                  label="Pickup location" variant="outlined" density="comfortable" class="mb-2"
                  :rules="[required]"
                ></v-text-field>
              </v-col>
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model="createDialog.form.destination"
                  label="Destination" variant="outlined" density="comfortable" class="mb-2"
                  :rules="[required]"
                ></v-text-field>
              </v-col>
            </v-row>
            <v-textarea
              v-model="createDialog.form.condition_notes"
              label="Condition" variant="outlined" density="comfortable" rows="2" class="mb-2"
              :rules="[required]"
            ></v-textarea>
          </template>

          <!-- Same reasoning as the service picker above: a unit type other
               than Ambulance has no meaning on this board. -->
          <v-select
            v-if="scope !== 'ambulance'"
            v-model="createDialog.form.required_vehicle_type"
            :items="vehicleTypeOptions"
            label="Required vehicle type (optional)"
            variant="outlined"
            density="comfortable"
            clearable
            class="mb-2"
          ></v-select>

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
              <v-text-field
                v-model="createDialog.form.scheduled_at"
                type="datetime-local"
                :min="minScheduleValue"
                label="Scheduled time"
                hint="At least 1 hour from now — sooner is an emergency, dispatch now instead."
                persistent-hint
                variant="outlined"
                density="comfortable"
                class="mb-2"
                @update:model-value="checkWalkInAvailability"
              ></v-text-field>

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

          <v-file-input
            v-model="createDialog.form.valid_id"
            label="Valid ID (optional — already checked in person)"
            variant="outlined"
            density="comfortable"
            accept="image/jpeg,image/png"
            prepend-icon=""
            prepend-inner-icon="mdi-card-account-details-outline"
            class="mb-2"
          ></v-file-input>

          <v-file-input
            v-model="createDialog.form.site_photo"
            label="Site photo (optional)"
            variant="outlined"
            density="comfortable"
            accept="image/jpeg,image/png"
            prepend-icon=""
            prepend-inner-icon="mdi-camera-outline"
          ></v-file-input>
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
import { useDisplay } from 'vuetify'
import { useRoute } from 'vue-router'
import { getToken } from '@/composables/authToken'
import { API_BASE } from '@/config/api'

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
const emit = defineEmits(['dispatch-booking', 'open-trip-record'])

const route = useRoute()

// The split view needs a real breakpoint, not a media query in CSS: below it
// the two panes are rendered one at a time rather than merely restyled, so the
// list is not sitting offscreen holding focusable rows.
//
// `lg`, not `md`. Vuetify 4 moved the breakpoints (display.js: sm 600, md 840,
// lg 1145 — v3 was 960/1280), so mdAndUp turned the split on at an 840px
// viewport. The drawer is `permanent` at 260px and the shell adds 24px of
// padding, so that left ~556px for two columns: the list rail's clamp(360px…)
// floor lost to `flex-shrink` and the rail rendered 175px wide against a
// scrollWidth of 218. lgAndUp leaves ~861px, which fits the 360px rail and a
// detail pane that can still show a full field row.
const { lgAndUp, height: windowHeight } = useDisplay()
const twoUp = lgAndUp

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
// Ten was a fixed number against a variable amount of room, so a 1080px screen
// showed ten rows and a band of empty card below them, with pagination under
// that. Fill the space that exists.
//
// Both constants are measured off the rendered panel, not guessed: a row is
// 73px (two lines of text plus 12px of vertical padding and a hairline), and
// the search field, the wrapped status chips and the pager take 348px between
// them. The old 58/360 pair overshot by two rows, which put a scrollbar inside
// a list that also paginates -- the one arrangement this computed exists to
// prevent. At 1080 this now yields exactly the 10 rows that fit.
// The floor is 5, not 8. Eight rows need 584px and a 860px-tall window leaves
// 512px, so the old floor put the scrollbar straight back on any laptop screen
// -- it was defending against a uselessly short list and instead guaranteed the
// thing the whole computed exists to avoid. Five still reads as a list, and
// below roughly a 713px window the list scrolls, which is the honest trade.
const ROW_HEIGHT = 73
const LIST_CHROME = 348

// windowHeight only means "room this list has to fill" in the standalone
// case, where this component owns the whole viewport-height container it is
// measured against. Embedded (standalone=false) the page scrolls instead —
// see the root v-container's comment — so a fixed page size matching Trip
// Logs' own v-data-table (:items-per-page="10" in ConductionRequestView.vue)
// is the right number here, not a window-height measurement with no
// relationship to this component's actual, unbounded-by-design height.
const itemsPerPage = computed(() => {
  if (!props.standalone) return 10

  const rowsFit = Math.floor((windowHeight.value - LIST_CHROME) / ROW_HEIGHT)
  return Math.min(20, Math.max(5, rowsFit))
})

// Same exact-code match as the mobile app's formKindForServiceCode: only this
// one service carries a scheduling concept server-side today, and it's the
// one thing that decides which of the two boards a request belongs on.
const AMBULANCE_SERVICE_CODE = 'ambulance-medical-response'
const isAmbulanceRequest = (r) => r.service?.code === AMBULANCE_SERVICE_CODE
const ambulanceServiceId = computed(() => services.value.find(s => s.code === AMBULANCE_SERVICE_CODE)?.service_id ?? null)

const filters = reactive({ status: 'All' })
const vehicleModal = ref({ isOpen: false })
const selectedRequest = ref(null)
const selectedIds = reactive(new Set())

const formData = ref({ remarks: '', internal_notes: '', vehicle_id: null })
const noteSaving = ref(false)
const noteSaved = ref(false)
let noteSavedTimer = null

const required = (v) => (v !== null && v !== undefined && String(v).trim() !== '') || 'Required'
// Same two values ConductionRequestView.vue's own create form offers, for
// the same patient.
const sexOptions = [
  { title: 'Male', value: 'male' },
  { title: 'Female', value: 'female' },
]

const emptyCreateForm = () => ({
  resident_id: null,
  walk_in_name: '',
  walk_in_contact_number: '',
  service_id: null,
  description: '',
  // Ambulance only — see the v-else block in the template above.
  patient_name: '',
  patient_age: null,
  patient_sex: null,
  patient_address: '',
  pickup_location: '',
  destination: '',
  condition_notes: '',
  required_vehicle_type: null,
  valid_id: null,
  site_photo: null,
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
    case 'approve':
      return {
        title: 'Approve and dispatch',
        body: `${getSelectedVehicleName() || 'The selected unit'} will be sent for ${what}.`,
        label: 'Note for the Head of the Family (optional)',
        hint: appHint,
        showField: hasAccount,
        confirm: 'Approve & dispatch',
      }
    case 'bulk':
      return {
        title: `Disapprove ${selectedIds.size} request${selectedIds.size === 1 ? '' : 's'}`,
        body: 'Every selected request is declined with this same reason.',
        label: 'Reason for declining',
        hint: 'Shown to any Head of the Family in the selection with a linked account; kept as an internal record for a walk-in with none.',
        showField: true,
        confirm: 'Disapprove all',
      }
    default:
      return {
        title: 'Disapprove this request',
        body: who ? `${who} asked for ${what}.` : `A request for ${what}.`,
        label: 'Reason for declining',
        hint: hasAccount ? appHint : noAppHint,
        showField: true,
        confirm: 'Disapprove request',
      }
  }
})

const openReason = (kind) => {
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

const clearReason = () => { reasonDialog.value = emptyReason() }

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

const lightbox = ref({ open: false, key: null })

const validId = createAttachment('valid-id', 'Could not load the attached ID.')
// The route segment and the `site_photo` column keep their names -- this is a
// label change, not an API one.
const sitePhoto = createAttachment('site-photo', 'Could not load the landmark photo.')

// Booked is a status only an ambulance booking can ever reach — showing the
// chip on the 'other' board would be a filter that always reads zero.
const statusTabs = computed(() => props.scope === 'ambulance'
  ? ['All', 'Pending', 'Booked', 'Responding', 'Resolved', 'Disapproved', 'Cancelled']
  : ['All', 'Pending', 'Responding', 'Resolved', 'Disapproved', 'Cancelled'])

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
  return parts.length ? `${parts[0][0]}${parts[1]?.[0] || ''}`.toUpperCase() : 'W'
}
const requesterPhone = (item) => item?.resident?.phone_number || item?.walk_in_contact_number || 'N/A'
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
  // Ambulance is the only service this modal ever assigns a vehicle for, but
  // the fleet also holds Rescue Vehicles, Fire Trucks and Boats — without
  // this filter every one of those showed up as a valid pick for a medical
  // dispatch (impeccable critique, P0, 2026-08-30).
  return vehicles.value.filter(v => v.status === 'Available' && v.type === 'Ambulance')
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
  .filter(s => s.code !== AMBULANCE_SERVICE_CODE)
  .map(s => ({ title: s.service_name, value: s.service_id })))

// Pulled from the fleet already on screen rather than hardcoded, so a vehicle
// type added in Fleet Management shows up here without a second edit.
const vehicleTypeOptions = computed(() => [...new Set(vehicles.value.map(v => v.type).filter(Boolean))])

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
  while (kept.length && META_TAIL.test(kept[kept.length - 1])) kept.pop()

  // A form submitted with nothing typed into it reduces to exactly the meta
  // lines, and stripping all of them would leave a blank card where there was
  // text a moment ago. Show what there is rather than nothing.
  return kept.length ? kept : lines
})

const filteredAndSortedRequests = computed(() => {
  const searchLower = search.value.toLowerCase()
  const currentStatus = filters.status

  return requests.value.filter(r => {
    if (currentStatus !== 'All' && (r.status || 'Pending') !== currentStatus) return false

    if (!searchLower) return true
    return requesterName(r).toLowerCase().includes(searchLower) ||
           (r.service?.service_name || '').toLowerCase().includes(searchLower) ||
           (r.resident?.barangay?.barangay_name || '').toLowerCase().includes(searchLower)
  }).sort((a, b) => {
    const statusA = a.status || 'Pending', statusB = b.status || 'Pending'
    if (statusA === 'Pending' && statusB !== 'Pending') return -1
    if (statusB === 'Pending' && statusA !== 'Pending') return 1
    return new Date(b.created_at) - new Date(a.created_at)
  })
})

const pageCount = computed(() => Math.max(1, Math.ceil(filteredAndSortedRequests.value.length / itemsPerPage.value)))

const pagedRequests = computed(() => {
  const start = (page.value - 1) * itemsPerPage.value
  return filteredAndSortedRequests.value.slice(start, start + itemsPerPage.value)
})

// Names which of the two reasons the list is empty. A status chip reading
// zero and a search with no hits are different facts: one says nothing of
// that kind exists yet, the other says try a different search.
const emptyListMessage = computed(() => {
  if (search.value) return `No requests match "${search.value}"`
  if (filters.status !== 'All') return `No ${filters.status.toLowerCase()} requests`
  return 'No requests yet'
})

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
  if (!rows.length) return

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

const formatDate = (dateStr) => new Date(dateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
const formatDateTime = (dateStr) => new Date(dateStr).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
// scheduled_end shares a day with scheduled_at on every booking this renders
// for, so only the time carries new information.
const formatTime = (dateStr) => new Date(dateStr).toLocaleTimeString(undefined, { timeStyle: 'short' })

// Mirrors the `row-${status}` pattern the list rows already use, so the pill and
// the row's left border are driven by the same string and cannot disagree.
// Replaces getStatusColor: a Vuetify colour name only ever fed v-chip, whose
// tonal variant is what made these unreadable in the first place.
const statusPillClass = (status) => `pill-${(status || 'Pending').toLowerCase()}`

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
    const [reqRes, vehRes, resRes, svcRes] = await Promise.all([
      fetch(`${API_BASE}/admin/service-requests`, { headers: getHeaders() }),
      fetch(`${API_BASE}/vehicles`, { headers: getHeaders() }),
      fetch(`${API_BASE}/residents`, { headers: getHeaders() }),
      fetch(`${API_BASE}/services`, { headers: getHeaders() })
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
    // Always fresh from the row — this is the operator's own note, not part
    // of the reason-dialog round trip remarks above is kept for.
    internal_notes: item.internal_notes || '',
    vehicle_id: item.vehicle_id || null
  }
  loadAttachments(item)
}

const selectVehicle = (id) => {
  formData.value.vehicle_id = id
  vehicleModal.value.isOpen = false
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
    await fetchData()
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
    await fetchData()
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

    await fetchData()
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
const rescheduleDialog = ref({ open: false, form: emptyRescheduleForm(), error: '' })

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
    form: {
      scheduled_at: toDateTimeLocal(req.scheduled_at),
      scheduled_end: toDateTimeLocal(req.scheduled_end) || toDateTimeLocal(new Date(new Date(req.scheduled_at).getTime() + 2 * 60 * 60 * 1000)),
      remarks: '',
    },
  }
}

const submitReschedule = async () => {
  const form = rescheduleDialog.value.form
  if (!form.scheduled_at || !form.scheduled_end || !form.remarks.trim()) {
    rescheduleDialog.value.error = 'Every field here is required.'
    return
  }

  loading.value = true
  rescheduleDialog.value.error = ''
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
      const firstError = errData.errors ? Object.values(errData.errors)[0]?.[0] : null
      throw new Error(firstError || errData.message || 'Failed to reschedule the booking')
    }

    await fetchData()
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

  const startMin = Math.min(minutesInDay, Math.max(0, (new Date(window.scheduled_at) - dayStart) / 60000))
  const endMin = Math.min(minutesInDay, Math.max(0, (new Date(window.scheduled_end) - dayStart) / 60000))

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

// v-file-input's v-model is always an array in this Vuetify version, single
// file or not.
const singleFile = (v) => (Array.isArray(v) ? v[0] : v) || null

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
      if (form.patient_sex) body.append('patient_sex', form.patient_sex)
      body.append('patient_address', form.patient_address.trim())
      body.append('pickup_location', form.pickup_location.trim())
      body.append('destination', form.destination.trim())
      body.append('condition_notes', form.condition_notes.trim())
    } else {
      body.append('description', form.description.trim())
    }
    if (form.required_vehicle_type) body.append('required_vehicle_type', form.required_vehicle_type)
    if (props.scope === 'ambulance' && createDialog.value.scheduleForLater && form.scheduled_at) {
      body.append('scheduled_at', form.scheduled_at.replace('T', ' ') + ':00')
    }
    const validIdFile = singleFile(form.valid_id)
    if (validIdFile) body.append('valid_id', validIdFile)
    const sitePhotoFile = singleFile(form.site_photo)
    if (sitePhotoFile) body.append('site_photo', sitePhotoFile)

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
    await fetchData()
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
    await fetchData()
    reasonDialog.value.open = false
  } catch (error) {
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

/* 40, and it has to exceed 28 rather than merely differ from it: adjacent
   margins collapse to the larger of the two, so anything under the group
   spacing above it renders as that group spacing and the break disappears. */
.detail-rule {
  margin-top: 40px;
  margin-bottom: 40px;
}

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

/* A rail, not a fixed 400px. The old width truncated service names mid-word on
   every screen while the pane beside it ran mostly empty. */
.request-list--rail {
  flex: 0 1 clamp(360px, 26vw, 560px);
  min-width: 0;
}

/* Status pills — replacing two v-chips that could not be read.
   v-chip's default variant is `tonal` (VChip.js:85), whose underlay is
   `background: currentColor`. The detail-panel chip also carried `.text-white`,
   and the utilities layer beats the components layer, so it repainted the label
   AND the underlay white: white on white, ~1.0:1. The list chips were legible
   but failed AA on every status (warning 2.36:1, info 3.84:1, success 4.27:1,
   error 4.03:1, all measured on their own tint over white).
   Same shape as UsersView's .status-pill so the two pages agree. Text uses the
   -strong tokens; the tint keeps the plain token. */
.status-pill {
  display: inline-flex;
  align-items: center;
  padding: 5px 12px;
  border-radius: 8px;
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  white-space: nowrap;
}
/* The list row is a denser context than the detail header — one step smaller,
   nothing else changes. */
.status-pill--sm {
  padding: 2px 8px;
  font-size: 0.6875rem;
  letter-spacing: 0.04em;
}
.pill-pending {
  background: rgba(var(--v-theme-warning), 0.14);
  color: rgb(var(--v-theme-warning-strong));
}
/* Booked is the one status with no semantic token behind it -- the theme
   carries five hues and all five are spoken for, and Booked has to be told
   apart from Responding at a glance. Literal violet, measured the same way the
   tokens in plugins/vuetify.ts were: #5B21B6 on rgba(#6D28D9, 0.14) over white
   is 7.14:1, and #A78BFA on its own 10% tint over #131B2E is 5.42:1. Both
   clear AA. Promote to a token pair if a second component ever needs it. */
.pill-booked {
  background: rgba(109, 40, 217, 0.14);
  color: #5B21B6;
}
.pill-responding {
  background: rgba(var(--v-theme-info), 0.14);
  color: rgb(var(--v-theme-info-strong));
}
.pill-resolved {
  background: rgba(var(--v-theme-success), 0.14);
  color: rgb(var(--v-theme-success-strong));
}
.pill-disapproved,
.pill-cancelled {
  background: rgba(var(--v-theme-error), 0.14);
  color: rgb(var(--v-theme-error-strong));
}
/* The dark tokens are already bright enough to use as text, but they need the
   lighter 10% tint the measurements were taken against — 14% of a bright token
   over #131B2E lifts the background far enough to eat the margin. Keep each
   status on its own hue; only the alpha changes. */
.v-theme--dark .pill-pending { background-color: rgba(var(--v-theme-warning), 0.10); }
.v-theme--dark .pill-booked { background-color: rgba(167, 139, 250, 0.10); color: #A78BFA; }
.v-theme--dark .pill-responding { background-color: rgba(var(--v-theme-info), 0.10); }
.v-theme--dark .pill-resolved { background-color: rgba(var(--v-theme-success), 0.10); }
.v-theme--dark .pill-disapproved,
.v-theme--dark .pill-cancelled { background-color: rgba(var(--v-theme-error), 0.10); }

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
