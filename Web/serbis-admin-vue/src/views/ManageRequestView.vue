<template>
  <v-container fluid class="pa-5 fill-height overflow-hidden dashboard-bg">
    <div class="d-flex flex-column w-100 h-100">

      <!-- Toolbar -->
      <div class="d-flex justify-space-between align-center w-100 mb-3 flex-wrap gap-3">
        <div>
          <h2 class="text-h5 font-weight-bold" style="line-height: 1; margin-bottom: 4px;">Resident Requests</h2>
          <div class="text-body-2 text-medium-emphasis" style="line-height: 1;">{{ requestCounts.All }} requests across all barangays</div>
        </div>
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
          Export {{ filteredAndSortedRequests.length }}
          <span class="d-sr-only">requests as CSV</span>
        </v-btn>
      </div>

      <!-- Split view: list + detail panel.
           Side by side on a desk, which is where this screen is used. Below the
           md breakpoint the two stop competing for one narrow column and become
           one surface at a time: the list until a request is picked, the detail
           with a way back after. -->
      <div class="d-flex flex-grow-1 gap-4 overflow-hidden" :class="twoUp ? 'flex-row' : 'flex-column'" style="min-height: 0;">

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
              placeholder="Search resident, service, barangay..."
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

          <div class="flex-grow-1 overflow-y-auto">
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
                :aria-label="`${residentName(item.resident)}, ${item.service?.service_name || 'service'}, ${item.status || 'Pending'}`"
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
                  :aria-label="`Select ${residentName(item.resident)}'s request`"
                  @click.stop="toggleSelect(item)"
                ></v-checkbox-btn>
                <v-avatar color="primary" variant="tonal" size="36" class="mr-3 flex-shrink-0">
                  <span class="font-weight-bold text-caption">
                    {{ item.resident?.first_name?.charAt(0) }}{{ item.resident?.last_name?.charAt(0) }}
                  </span>
                </v-avatar>
                <!-- The date is the half of this line that survives truncation
                     worst, and it is the half that decides what is urgent, so
                     it gets its own column instead of trailing the service
                     name off the end of the row. -->
                <div class="flex-grow-1 min-width-0">
                  <div class="text-body-2 font-weight-bold text-truncate">{{ residentName(item.resident) }}</div>
                  <div class="d-flex align-center text-caption text-medium-emphasis">
                    <span class="text-truncate">{{ item.service?.service_name || 'N/A' }}</span>
                    <span class="row-date ms-2">{{ formatDate(item.created_at) }}</span>
                  </div>
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
                    {{ selectedRequest.resident?.first_name?.charAt(0) }}{{ selectedRequest.resident?.last_name?.charAt(0) }}
                  </span>
                </v-avatar>
                <div>
                  <div class="text-h6 font-weight-bold" style="line-height: 1.2;">{{ residentName(selectedRequest.resident) }}</div>
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

              <v-row class="detail-group">
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
                  <template v-else>No description provided by resident.</template>
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

              <v-textarea
                v-if="selectedRequest.status === 'Pending' || selectedRequest.status === 'Responding' || !selectedRequest.status"
                v-model="formData.remarks" label="Admin remarks" variant="outlined" density="comfortable" rounded="lg" rows="2"
                hint="Carried into the approve and decline dialogs. Declining asks for one if this is empty."
                persistent-hint
              ></v-textarea>
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
                <span id="dispatch-gate" class="d-sr-only">
                  Disabled until a vehicle is chosen with the Select Vehicle button beside it.
                </span>
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
          <span class="text-h6 font-weight-bold">Available Vehicles</span>
          <v-btn icon="mdi-close" variant="text" density="comfortable" @click="vehicleModal.isOpen = false"></v-btn>
        </v-card-title>

        <!-- A two-across grid of cards for a list of identical units. Each tile
             carried three words and the eye had to travel in two directions to
             compare fourteen of them. One column, one unit per row, matching the
             fleet list this picker is a view of. -->
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
            <div class="text-body-2">All fleet vehicles are currently dispatched or under maintenance.</div>
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
            v-model="reasonDialog.reason"
            :label="reasonCopy.label"
            hint="This is shown with the request in the mobile app."
            persistent-hint
            variant="outlined"
            rows="3"
            counter="255"
            maxlength="255"
            autofocus
            :error-messages="reasonDialog.error"
            @update:model-value="reasonDialog.error = ''"
          ></v-textarea>
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
  </v-container>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue'
import { useDisplay } from 'vuetify'
import { useRoute } from 'vue-router'
import { getToken } from '@/composables/authToken'
import { API_BASE } from '@/config/api'

const route = useRoute()

// The split view needs a real breakpoint, not a media query in CSS: below it
// the two panes are rendered one at a time rather than merely restyled, so the
// list is not sitting offscreen holding focusable rows.
const { mdAndUp, height: windowHeight } = useDisplay()
const twoUp = mdAndUp

const requests = ref([])
const vehicles = ref([])
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

const itemsPerPage = computed(() => {
  const rowsFit = Math.floor((windowHeight.value - LIST_CHROME) / ROW_HEIGHT)
  return Math.min(20, Math.max(5, rowsFit))
})

const filters = reactive({ status: 'All' })
const vehicleModal = ref({ isOpen: false })
const selectedRequest = ref(null)
const selectedIds = reactive(new Set())

const formData = ref({ remarks: '', vehicle_id: null })

// `kind` is the whole state machine: 'approve' and 'disapprove' act on the
// selected request, 'bulk' on every ticked row. Only 'approve' may fire with an
// empty reason.
const emptyReason = () => ({ open: false, kind: 'disapprove', reason: '', error: '' })
const reasonDialog = ref(emptyReason())

const reasonCopy = computed(() => {
  const who = `${selectedRequest.value?.resident?.first_name || ''} ${selectedRequest.value?.resident?.last_name || ''}`.trim()
  const what = selectedRequest.value?.service?.service_name || 'this service'
  switch (reasonDialog.value.kind) {
    case 'approve':
      return {
        title: 'Approve and dispatch',
        body: `${getSelectedVehicleName() || 'The selected unit'} will be sent for ${what}.`,
        label: 'Note for the resident (optional)',
        confirm: 'Approve & dispatch',
      }
    case 'bulk':
      return {
        title: `Disapprove ${selectedIds.size} request${selectedIds.size === 1 ? '' : 's'}`,
        body: 'Every selected request is declined with this same reason.',
        label: 'Reason for declining',
        confirm: 'Disapprove all',
      }
    default:
      return {
        title: 'Disapprove this request',
        body: who ? `${who} asked for ${what}.` : `A request for ${what}.`,
        label: 'Reason for declining',
        confirm: 'Disapprove request',
      }
  }
})

const openReason = (kind) => {
  reasonDialog.value = {
    ...emptyReason(),
    open: true,
    kind,
    // A remark already typed on the panel is the operator's own words; making
    // them retype it in the dialog is how a required field turns into a "."
    reason: kind === 'bulk' ? '' : (formData.value.remarks || ''),
  }
}

const clearReason = () => { reasonDialog.value = emptyReason() }

const confirmReason = () => {
  const { kind, reason } = reasonDialog.value
  const trimmed = reason.trim()
  if (kind !== 'approve' && !trimmed) {
    reasonDialog.value.error = 'Give a reason — the resident is shown this'
    return
  }
  if (kind === 'bulk') return bulkDisapprove(trimmed)
  // The panel's own field is the source of truth for `updateStatus`, so it moves
  // with the dialog rather than the two drifting apart.
  formData.value.remarks = trimmed
  return updateStatus(kind === 'approve' ? 'Responding' : 'Disapproved')
}

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

const statusTabs = ['All', 'Pending', 'Responding', 'Resolved', 'Disapproved', 'Cancelled']

// Dashboard KPI cards deep-link here with ?status=Pending — honor it once on
// arrival so the operator lands on the filtered view, not "All".
if (statusTabs.includes(route.query.status)) filters.status = route.query.status

const itemId = (item) => item.request_id || item.id

// One order for a person's name across both panes. The list used to invert it
// to "Last, First" while the detail beside it read "First Last" -- the same
// resident, written two ways, six inches apart.
const residentName = (resident) =>
  `${resident?.first_name || ''} ${resident?.last_name || ''}`.trim() || 'Unknown resident'

const requestCounts = computed(() => {
  const counts = { All: requests.value.length, Pending: 0, Responding: 0, Resolved: 0, Disapproved: 0, Cancelled: 0 }
  requests.value.forEach(req => {
    const status = req.status || 'Pending'
    if (counts[status] !== undefined) counts[status]++
  })
  return counts
})

const availableVehicles = computed(() => vehicles.value.filter(v => v.status === 'Available'))

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
      alt: 'Landmark photo attached by the resident',
    },
    {
      key: 'valid-id',
      label: 'Valid ID',
      present: !!req.has_valid_id,
      state: validId.state,
      alt: 'Valid ID attached by the resident',
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
  selectedRequest.value && (selectedRequest.value.status === 'Pending' || !selectedRequest.value.status || selectedRequest.value.status === 'Responding')
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

  const header = ['Request ID', 'Resident', 'Barangay', 'Phone', 'Service', 'Status', 'Vehicle', 'Submitted', 'Remarks', 'Description']
  const body = rows.map(r => [
    itemId(r),
    residentName(r.resident),
    r.resident?.barangay?.barangay_name || '',
    r.resident?.phone_number || '',
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
</style>
