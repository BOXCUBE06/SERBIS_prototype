<template>
  <v-container fluid class="fill-height align-start pa-6 bg-background">
    <div class="residents-layout" :style="rowStyle">

      <div class="residents-main">
        <v-card elevation="3" rounded="lg" class="bg-surface w-100 h-100 d-flex flex-column">

          <div class="residents-toolbar px-6 py-3 border-b d-flex flex-wrap align-center justify-space-between gap-4 flex-shrink-0">
            <div>
              <h2 class="text-h5 font-weight-bold text-high-emphasis">Residents</h2>
              <!-- Says "of" only when something is being hidden. The permanent
                   "N of N" read as a standing accusation that a filter was on.
                   ("residents" here is deliberate and ruled on; the heading
                   above it is the page/nav title.) -->
              <div class="text-body-2 text-medium-emphasis">
                <template v-if="filteredAndSortedResidents.length === residents.length">
                  <strong class="text-high-emphasis">{{ residents.length }}</strong>
                  {{ residents.length === 1 ? 'resident' : 'residents' }}
                </template>
                <template v-else>
                  <strong class="text-high-emphasis">{{ filteredAndSortedResidents.length }}</strong>
                  of {{ residents.length }} residents
                </template>
              </div>
            </div>

            <div class="d-flex flex-wrap gap-3 align-center">
              <v-text-field
                v-model="search"
                prepend-inner-icon="mdi-magnify"
                label="Search residents"
                placeholder="Name or email"
                clearable
                variant="outlined"
                density="comfortable"
                hide-details
                rounded="lg"
                class="search-field"
              ></v-text-field>

              <v-select
                v-model="filters.status"
                :items="RESIDENT_STATUS_FILTER_ITEMS"
                label="Status"
                variant="outlined"
                density="comfortable"
                hide-details
                rounded="lg"
                class="status-field"
              ></v-select>

              <v-btn
                color="#0f4c3a"
                elevation="0"
                rounded="lg"
                height="48"
                class="px-5 text-none font-weight-bold text-white transition-btn"
                @click="openAddModal"
              >
                <v-icon start>mdi-plus</v-icon> Add Head of the Family
              </v-btn>
            </div>
          </div>

          <!-- `aria-pressed` is what makes the active filter perceivable at
               all without sight: the selected barangay was carried by colour
               and a 3px underline alone, and the group had no accessible name
               saying what these buttons even filter. -->
          <div
            class="residents-toolbar px-6 py-2 border-b subtle-surface d-flex align-center gap-2 overflow-x-auto flex-shrink-0"
            role="group"
            aria-label="Filter by barangay"
          >
            <v-btn
              variant="text"
              :aria-pressed="filters.barangay === 'All'"
              :class="['tab-btn text-none px-4 rounded-0', filters.barangay === 'All' ? 'active-tab font-weight-black' : 'text-medium-emphasis font-weight-bold']"
              @click="filters.barangay = 'All'"
            >
              All Barangays
            </v-btn>
            <v-btn
              v-for="b in barangays"
              :key="b.barangay_id"
              variant="text"
              :aria-pressed="filters.barangay === b.barangay_name"
              :class="['tab-btn text-none px-4 rounded-0', filters.barangay === b.barangay_name ? 'active-tab font-weight-black' : 'text-medium-emphasis font-weight-bold']"
              @click="filters.barangay = b.barangay_name"
            >
              {{ b.barangay_name }}
            </v-btn>
          </div>

          <!-- Error -->
          <v-alert
            v-if="apiError"
            type="error"
            variant="tonal"
            density="comfortable"
            rounded="0"
            class="flex-shrink-0"
          >
            {{ apiError }}
            <template v-slot:append>
              <v-btn variant="text" class="text-none font-weight-bold" @click="loadAll">Retry</v-btn>
            </template>
          </v-alert>

          <!-- Loading -->
          <div v-if="initialLoad" class="pa-6 flex-grow-1">
            <!-- `table`, not list rows: an avatar-two-line skeleton promises
                 the shape of a list and then a seven-column table arrives,
                 which is a guaranteed layout shift on every load. -->
            <v-skeleton-loader type="table" class="mb-1"></v-skeleton-loader>
          </div>

          <!-- Empty -->
          <div v-else-if="filteredAndSortedResidents.length === 0" class="empty-state flex-grow-1">
            <v-icon size="56" class="text-medium-emphasis mb-4">mdi-account-off-outline</v-icon>
            <div class="text-h6 font-weight-bold text-high-emphasis mb-1">
              {{ residents.length > 0 ? 'No heads of the family match your filters' : 'No heads of the family registered yet' }}
            </div>
            <div class="text-body-1 text-medium-emphasis mb-5">
              {{ residents.length > 0
                ? 'Try a different keyword, status, or barangay.'
                : 'Add the first head of the family account to get started.' }}
            </div>
            <v-btn
              v-if="residents.length > 0"
              color="primary"
              variant="flat"
              rounded="lg"
              height="48"
              class="px-6 text-none font-weight-bold"
              @click="clearFilters"
            >
              Clear filters
            </v-btn>
          </div>

          <v-data-table
            v-else
            :headers="headers"
            :items="filteredAndSortedResidents"
            :items-per-page="-1"
            fixed-header
            :height="tableHeight"
            hover
            class="elegant-table flex-grow-1"
            item-value="resident_id"
            @click:row="selectRow"
            :row-props="rowProps"
          >
            <template v-slot:bottom></template>

            <template v-slot:item.rowNumber="{ item }">
              <span class="row-number text-medium-emphasis">{{ rowNumber(item) }}</span>
            </template>

            <template v-slot:item.photo="{ item }">
              <v-avatar :color="undefined" size="42" class="my-2 avatar-tint">
                <v-img
                  v-if="photoUrls[idOf(item)]"
                  :src="photoUrls[idOf(item)]"
                  :alt="`Photo of ${item.first_name} ${item.last_name}`"
                ></v-img>
                <span v-else class="avatar-initials">
                  {{ initials(item) }}
                </span>
              </v-avatar>
            </template>

            <template v-slot:item.fullName="{ item }">
              <v-tooltip :text="fullName(item)" location="top">
                <template v-slot:activator="{ props }">
                  <div v-bind="props" class="font-weight-bold text-high-emphasis text-body-1 cell-truncate">
                    {{ fullName(item) }}
                  </div>
                </template>
              </v-tooltip>
            </template>

            <template v-slot:item.barangay_name="{ item }">
              <span class="font-weight-medium text-body-1 text-high-emphasis cell-truncate">
                {{ barangayOf(item) }}
              </span>
            </template>

            <template v-slot:item.phone_number="{ item }">
              <span class="text-body-1 text-medium-emphasis cell-truncate">{{ item.phone_number }}</span>
            </template>

            <template v-slot:item.email_address="{ item }">
              <v-tooltip :text="item.email_address" location="top">
                <template v-slot:activator="{ props }">
                  <span v-bind="props" class="text-body-1 text-medium-emphasis cell-truncate">
                    {{ item.email_address }}
                  </span>
                </template>
              </v-tooltip>
            </template>

            <template v-slot:item.status="{ item }">
              <!-- Custom pill rather than a Vuetify chip: the flat grey chip Vuetify
                   renders for "Deactivated" pairs white on #9E9E9E (2.68:1) in both
                   themes. These tint the surface token instead, so all three states
                   pass AA in light and dark. -->
              <span class="status-pill" :class="residentStatusPillClass(item.status)">
                <span class="status-dot" :class="residentStatusDotClass(item.status)"></span>
                {{ residentStatusLabel(item.status) }}
              </span>
            </template>

            <template v-slot:item.sms_opt_in="{ item }">
              <!-- Same pill as Status, on purpose: it is the second half of the
                   same question. A blast needs an Active account, a phone
                   number and this switch, so an operator counting a short
                   delivery report reads both columns, not one. -->
              <span class="status-pill" :class="residentSmsPillClass(residentSmsOptIn(item))">
                <span class="status-dot" :class="residentSmsDotClass(residentSmsOptIn(item))"></span>
                {{ residentSmsLabel(residentSmsOptIn(item)) }}
              </span>
            </template>
          </v-data-table>
        </v-card>
      </div>

      <!-- The profile is a rail beside the table, not an overlay on top of it.
           It has no width until a resident is opened; opening one animates it
           out to 460 and the table narrows into what is left, which is the
           split the page used to hold permanently — the difference being that
           it is now only there while it is being read. Closing gives the width
           back the same way.

           `overflow: hidden` on the rail plus a fixed-width child is what makes
           that a slide rather than a reflow: the panel is drawn at its full
           460 the whole time and the rail uncovers it, so no text re-wraps on
           any frame of the animation. -->
      <aside
        class="detail-rail"
        :class="{ 'detail-rail--open': detailOpen }"
        :aria-hidden="detailOpen ? undefined : 'true'"
        :inert="detailOpen ? undefined : true"
        aria-label="Head of the family profile"
      >
        <div class="detail-rail__inner">
          <v-card
            v-if="selectedResident"
            elevation="3"
            rounded="lg"
            class="bg-surface h-100 d-flex flex-column"
          >
            <ResidentDetailPanel
              :resident="selectedResident"
              :status-loading="statusToggleLoading"
              :hidden-by-filter="selectionHidden"
              @close="closeDetail"
              @edit="openExistingEditModal"
              @toggle-status="askToggleStatus"
              @delete="askDelete"
              @clear-filters="clearFilters"
            />
          </v-card>
        </div>
      </aside>

    </div>

    <!-- Add / Edit -->
    <v-dialog v-model="modal.isOpen" max-width="680" persistent>
      <v-card rounded="lg" elevation="10">
        <v-card-title class="d-flex justify-space-between align-center pa-6 border-b bg-surface">
          <span class="text-h6 font-weight-bold text-high-emphasis">
            {{ modal.isEditing ? 'Edit Head of the Family' : 'New Head of the Family' }}
          </span>
          <v-btn icon="mdi-close" variant="text" size="small" aria-label="Close dialog" @click="closeModal"></v-btn>
        </v-card-title>

        <v-card-text class="pa-6">
          <v-alert v-if="modalError" type="error" variant="tonal" class="mb-6" density="comfortable" rounded="lg" role="alert">
            {{ modalError }}
          </v-alert>

          <v-form ref="form" @submit.prevent="saveUser">
            <v-row>
              <v-col cols="12" class="d-flex align-center gap-4 mb-2">
                <v-avatar size="70" class="avatar-tint">
                  <span class="avatar-initials text-h5">
                    {{ (formData.first_name?.charAt(0) || '?') }}{{ (formData.last_name?.charAt(0) || '') }}
                  </span>
                </v-avatar>
                <!-- "Change Photo" lived here with no handler behind it, and it
                     could never have had one: POST /api/residents ignores a
                     submitted photo on purpose, because the photo is the
                     resident's own face and theirs to set. The avatar draws
                     initials from the name being typed, so it is a preview, not
                     a picture that was ever uploadable from this form. -->
                <div>
                  <div class="text-subtitle-2 font-weight-bold text-high-emphasis mb-1">Initials</div>
                  <div class="text-caption text-medium-emphasis" style="max-width: 34ch;">
                    Heads of the family add their own photo from the mobile app. It appears here once they do.
                  </div>
                </div>
              </v-col>

              <v-col cols="12" md="4">
                <v-text-field v-model="formData.first_name" label="First Name *" placeholder="Juan" :rules="[requiredRule('First name')]" :error-messages="fieldErrors.first_name" variant="outlined" density="comfortable" rounded="lg" autocomplete="given-name"></v-text-field>
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field v-model="formData.middle_name" label="Middle Name" placeholder="Santos" :error-messages="fieldErrors.middle_name" variant="outlined" density="comfortable" rounded="lg" autocomplete="additional-name"></v-text-field>
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field v-model="formData.last_name" label="Last Name *" placeholder="Dela Cruz" :rules="[requiredRule('Last name')]" :error-messages="fieldErrors.last_name" variant="outlined" density="comfortable" rounded="lg" autocomplete="family-name"></v-text-field>
              </v-col>

              <v-col cols="12" md="6">
                <v-text-field v-model="formData.phone_number" label="Phone Number *" placeholder="09171234567" :rules="[requiredRule('Phone number'), phoneRule]" :error-messages="fieldErrors.phone_number" type="tel" variant="outlined" density="comfortable" rounded="lg" autocomplete="tel"></v-text-field>
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field v-model="formData.email_address" label="Email Address *" placeholder="juan.delacruz@gmail.com" :rules="[requiredRule('Email address'), emailRule]" :error-messages="fieldErrors.email_address" type="email" variant="outlined" density="comfortable" rounded="lg" autocomplete="email"></v-text-field>
              </v-col>

              <v-col cols="12" md="6" v-if="!modal.isEditing">
                <!-- The hint/error overlap this field used to hit on blank
                     submit (finding #5, docs/ui-audit/findings.md) is now
                     fixed globally in src/styles/settings.scss, not locally
                     here — see that file's comment for the root cause. -->
                <v-text-field
                  v-model="formData.password"
                  label="Password *"
                  :type="showPassword ? 'text' : 'password'"
                  :append-inner-icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
                  hint="At least 8 characters, with upper and lower case and a number"
                  persistent-hint
                  :rules="[requiredRule('Password'), passwordRule]"
                  :error-messages="fieldErrors.password"
                  variant="outlined"
                  density="comfortable"
                  rounded="lg"
                  autocomplete="new-password"
                  @click:append-inner="showPassword = !showPassword"
                ></v-text-field>
              </v-col>

              <v-col cols="12" :md="modal.isEditing ? 12 : 6">
                <v-select v-model="formData.barangay_id" :items="barangays" item-title="barangay_name" item-value="barangay_id" label="Barangay *" :rules="[requiredRule('Barangay')]" :error-messages="fieldErrors.barangay_id" variant="outlined" density="comfortable" rounded="lg"></v-select>
              </v-col>

              <v-col cols="12">
                <div class="text-subtitle-2 font-weight-bold text-high-emphasis mb-2">Account Status</div>
                <!-- Active and Deactivated only. 'Inactive' — shown elsewhere as
                     "Pending" — is still a real stored value and still what
                     AuthController::register() writes for a self-registered
                     resident. It is simply not something staff set by hand: they
                     activate or deactivate.

                     A Pending resident therefore opens this dialog with no radio
                     selected, and that is intended. formData.status keeps the
                     stored 'Inactive' (a radio group writes to its v-model only
                     on selection, never on absence), saveUser spreads it into the
                     payload unchanged, and update() still accepts it — the rule
                     is in:Active,Inactive,Deactivated. Saving other fields leaves
                     the status alone; only clicking a radio changes it.

                     The banner says so, because an empty radio group reads as a
                     form that has lost a value rather than one deliberately not
                     offering it. -->
                <v-alert
                  v-if="modal.isEditing && formData.status === RESIDENT_STATUS.pending"
                  type="info"
                  variant="tonal"
                  density="compact"
                  rounded="lg"
                  class="mb-3"
                >
                  <span class="text-body-2">
                    This account is <strong>Pending</strong> — self-registered and not yet activated.
                    Saving leaves it pending; choose Active to activate it.
                  </span>
                </v-alert>
                <v-radio-group v-model="formData.status" inline hide-details color="#0f4c3a">
                  <v-radio label="Active" :value="RESIDENT_STATUS.active"></v-radio>
                  <v-radio label="Deactivated" :value="RESIDENT_STATUS.deactivated"></v-radio>
                </v-radio-group>
              </v-col>
            </v-row>
          </v-form>
        </v-card-text>

        <v-card-actions class="pa-6 pt-0 d-flex justify-end gap-3 bg-surface">
          <v-btn variant="text" rounded="lg" height="48" class="px-4 text-none font-weight-bold" :disabled="loading" @click="closeModal">
            Cancel
          </v-btn>
          <v-btn
            color="#0f4c3a"
            variant="flat"
            rounded="lg"
            class="px-6 text-none font-weight-bold text-white"
            height="48"
            :loading="loading"
            @click="saveUser"
          >
            {{ modal.isEditing ? 'Save Changes' : 'Create Account' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete confirm -->
    <v-dialog v-model="deleteDialog.show" max-width="470">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">Delete this account?</v-card-title>
        <v-card-text class="px-6 py-4 text-body-1 text-medium-emphasis">
          <strong class="text-high-emphasis">{{ deleteDialog.item ? fullName(deleteDialog.item) : '' }}</strong>
          will be permanently removed, along with their ability to sign in and file requests.
          This cannot be undone — deactivate the account instead if you only want to suspend access.
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="text" rounded="lg" height="48" class="text-none font-weight-bold" :disabled="deleteDialog.loading" @click="deleteDialog.show = false">
            Cancel
          </v-btn>
          <v-btn
            color="error"
            variant="flat"
            rounded="lg"
            height="48"
            class="px-6 text-none font-weight-bold"
            :loading="deleteDialog.loading"
            @click="confirmDelete"
          >
            Delete account
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Deactivate confirm -->
    <v-dialog v-model="statusDialog.show" max-width="460">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">Deactivate this account?</v-card-title>
        <v-card-text class="px-6 py-4 text-body-2 text-medium-emphasis">
          <strong class="text-high-emphasis">{{ statusDialog.item ? fullName(statusDialog.item) : '' }}</strong>
          will lose access to sign in and file requests, and will stop receiving MDRRMO text blasts.
          It can be reactivated later.
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="text" rounded="lg" class="text-none" :disabled="statusDialog.loading" @click="statusDialog.show = false">
            Cancel
          </v-btn>
          <v-btn
            color="warning" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold"
            :loading="statusDialog.loading" @click="confirmDeactivate"
          >
            Deactivate account
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="bottom right" rounded="lg">
      {{ snackbar.text }}
    </v-snackbar>

    <!-- Always in the DOM, so an account being activated or deleted is spoken
         rather than happening in silence. See notify() for why the snackbar
         cannot do this job itself. -->
    <span class="sr-only" role="status" aria-live="polite">{{ liveMessage }}</span>
    <!-- Separate region: how many rows the filters left is a different fact
         from the outcome of an action, and the two must not overwrite each
         other mid-announcement. -->
    <span class="sr-only" aria-live="polite">{{ resultAnnouncement }}</span>
  </v-container>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useDisplay } from 'vuetify'
import { initials as computeInitials } from '@/composables/adminUi'
import { getToken } from '@/composables/authToken'
import { useRowNumbers } from '@/composables/rowNumber'
import {
  forgetResidentPhoto,
  releaseResidentPhotos,
  residentPhotoUrl,
} from '@/composables/residentPhoto'
import {
  RESIDENT_STATUS,
  RESIDENT_STATUS_FILTER_ITEMS,
  residentSmsDotClass,
  residentSmsLabel,
  residentSmsOptIn,
  residentSmsPillClass,
  residentStatusDotClass,
  residentStatusLabel,
  residentStatusPillClass,
} from '@/composables/residentStatus'
import { API_BASE } from '@/config/api'
import ResidentDetailPanel from '@/components/ResidentDetailPanel.vue'

const { mdAndUp } = useDisplay()

// Four columns are fixed px and four are percentages, and the percentages add
// to 51 rather than to what is left of 100. The table is `table-layout: fixed`,
// so a percentage is taken from the full table width, not from the space the
// px columns leave — the two have to be budgeted together or they overlap.
//
// The pill columns are px because their content does not vary: they were 10%
// and 8%, and the row-number column taking its 64px shrank SMS Blasts to 82px,
// which is narrower than the 110px "RECEIVING" pill it has to print. The pill
// spilled out of the cell and scrolled the whole card sideways. Both are now
// their longest pill plus the 16px cell padding either side — "DEACTIVATED"
// 128 + 32, "RECEIVING" 110 + 32 — measured, not guessed.
//
// Width otherwise follows variance: the columns that differ per row get the
// percentages. 55% + 442px still fits the 1000px min-width with room to spare,
// and `table-layout: fixed` hands the slack back to every column in proportion.
const headers = [
  { title: '#', key: 'rowNumber', sortable: false, align: 'center', width: '64px' },
  { title: '', key: 'photo', sortable: false, align: 'center', width: '76px' },
  { title: 'Full Name', key: 'fullName', width: '17%' },
  // The longest real barangay name in the data is "San Antonio Ugad", which
  // was still clipping when this column was 15% of a narrower table.
  { title: 'Barangay', key: 'barangay_name', width: '14%' },
  { title: 'Phone Number', key: 'phone_number', width: '10%' },
  { title: 'Email', key: 'email_address', width: '14%' },
  { title: 'Status', key: 'status', align: 'center', width: '160px' },
  { title: 'SMS Blasts', key: 'sms_opt_in', align: 'center', width: '142px' },
]

const residents = ref([])
// resident_id -> object URL. Only rows the server says have a photo are ever
// fetched; the rest fall through to initials without a request.
const photoUrls = ref({})
const barangays = ref([])
const search = ref('')
const initialLoad = ref(true)
const loading = ref(false)
const apiError = ref('')
const modalError = ref('')
const showPassword = ref(false)
// Template ref for <v-form>. The markup carried `ref="form"` all along, but
// nothing declared it in <script setup>, so it silently resolved to nothing —
// which is consistent with the form's rules never having been run.
const form = ref(null)

const selectedResident = ref(null)
const filters = ref({ status: 'All', barangay: 'All' })
const modal = ref({ isOpen: false, isEditing: false, targetId: null })
const deleteDialog = ref({ show: false, item: null, loading: false })
const statusDialog = ref({ show: false, item: null, loading: false })
const snackbar = ref({ show: false, text: '', color: 'success' })
const statusToggleLoading = ref(false)

const formData = ref({
  first_name: '', middle_name: '', last_name: '', phone_number: '',
  email_address: '', password: '', barangay_id: null, status: RESIDENT_STATUS.active,
})

// The rail is open exactly when a resident is selected. There is no second
// piece of state that can disagree with the first.
const detailOpen = computed(() => Boolean(selectedResident.value))
const closeDetail = () => { selectedResident.value = null }

// Esc closes it. Nothing else is listening — the rail is ordinary layout, not
// an overlay, so it has none of the dismissal a v-dialog gets for free.
//
// Not while a dialog is up, though. Edit and Delete both open over the page,
// and a window-level listener cannot see that something nearer the user owns
// the key: pressing Esc in the edit form closed the profile *behind* the form —
// and that form is `persistent`, so it stayed open over a panel that was no
// longer there.
//
// Derived from what is actually on screen rather than from a list of this
// file's dialogs by name: the list was three long and a fourth dialog would
// not have been in it. Vuetify puts `.v-dialog.v-overlay--active` on every open
// dialog, and only on dialogs — a snackbar, a tooltip or a select's menu does
// not match, so those keep behaving as they did.
const aDialogIsOpen = () => !!document.querySelector('.v-dialog.v-overlay--active')

const onEscape = (event) => {
  if (event.key !== 'Escape') return
  if (aDialogIsOpen()) return
  if (selectedResident.value) closeDetail()
}

// Clicking away closes it. Without a scrim there is nothing that "outside" is
// automatically, so it has to be said, and three things are explicitly not
// outside: the panel itself; a table row, which switches the profile rather
// than dismissing it; and the card's own toolbar, because searching or
// filtering while reading a profile is not a request to close it. Anything
// Vuetify teleports to the body — dialogs, menus, the status select's list —
// is excluded too, or picking a status would shut the panel behind it.
const onDocumentClick = (event) => {
  if (!selectedResident.value) return
  if (aDialogIsOpen()) return
  const target = event.target
  if (!(target instanceof Element)) return
  if (target.closest('.detail-rail')) return
  if (target.closest('.v-overlay')) return
  if (target.closest('tbody tr')) return
  if (target.closest('.residents-toolbar')) return
  closeDetail()
}

// The table shares the row with the rail, so its height is the page less the
// container's padding, and the table body is that less the card's header, the
// barangay tabs and the table's own header.
const rowStyle = computed(() => (mdAndUp.value ? 'height: calc(100vh - 96px);' : ''))
const tableHeight = computed(() => (mdAndUp.value ? 'calc(100vh - 292px)' : '60vh'))

const idOf = (r) => r?.resident_id ?? r?.id
const fullName = (r) => [r.last_name, [r.first_name, r.middle_name].filter(Boolean).join(' ')].filter(Boolean).join(', ')
const initials = (r) => computeInitials(r)
const barangayOf = (r) => r.barangay?.barangay_name || r.barangay_name || 'N/A'

const liveMessage = ref('')

const notify = (text, color = 'success') => {
  snackbar.value = { show: true, text, color }
  // The snackbar is not a live region — Vuetify mounts it on show, and a
  // region that appears at the same moment as its text is not reliably
  // announced. Re-cleared first so deleting two accounts in a row is two
  // events, not one unchanged string.
  liveMessage.value = ''
  requestAnimationFrame(() => { liveMessage.value = text })
}
const getHeaders = () => ({ Authorization: `Bearer ${getToken()}`, 'Content-Type': 'application/json', Accept: 'application/json' })

const filteredAndSortedResidents = computed(() => {
  let result = residents.value
  if (filters.value.status !== 'All') result = result.filter((r) => r.status === filters.value.status)
  if (filters.value.barangay !== 'All') result = result.filter((r) => barangayOf(r) === filters.value.barangay)
  // Trimmed: a leading space is trivially common when pasting from a list, and
  // it used to return zero rows with no explanation.
  const q = (search.value || '').trim().toLowerCase()
  if (q) {
    result = result.filter((r) => {
      // Both orders. The table renders "Ferrer, Jilmar", so matching only
      // "first last" meant typing back the name being read off the screen
      // found nothing — the operator had to mentally invert it first.
      const first = (r.first_name || '').toLowerCase()
      const last = (r.last_name || '').toLowerCase()
      return `${first} ${last}`.includes(q) ||
        `${last}, ${first}`.includes(q) ||
        `${last} ${first}`.includes(q) ||
        (r.middle_name || '').toLowerCase().includes(q) ||
        (r.email_address || '').toLowerCase().includes(q) ||
        (r.phone_number || '').toLowerCase().includes(q)
    })
  }
  return [...result].sort((a, b) => `${a.last_name} ${a.first_name}`.localeCompare(`${b.last_name} ${b.first_name}`))
})

const rowNumber = useRowNumbers(filteredAndSortedResidents, 'resident_id')

const clearFilters = () => {
  search.value = ''
  filters.value = { status: 'All', barangay: 'All' }
}

// True when the open profile is not in the list behind it — filter to one
// barangay while a resident from another is selected and the panel keeps
// showing them, with Edit/Activate/Delete live. The record stays open on
// purpose (clearing it would lose the operator's place mid-task), but the
// panel has to say so: a destructive action must never sit unlabelled against
// a row the current view denies exists.
// Spoken when filtering changes the row count. Silent on the first load —
// announcing "23 of 23" before anyone has filtered anything is noise.
const resultAnnouncement = computed(() => {
  if (initialLoad.value) return ''
  const shown = filteredAndSortedResidents.value.length
  const total = residents.value.length
  if (shown === total) return ''
  return `${shown} of ${total} heads of the family shown`
})

const selectionHidden = computed(() => {
  const selected = selectedResident.value
  if (!selected) return false
  const id = idOf(selected)
  return !filteredAndSortedResidents.value.some((r) => idOf(r) === id)
})

const selectRow = (event, { item }) => { selectedResident.value = item }

// Rows are focusable and respond to Enter/Space, so a resident can be opened
// without a mouse; arrows walk the list the way a native listbox would.
const onRowKeydown = (event, item) => {
  const row = event.currentTarget
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault()
    selectedResident.value = item
    return
  }
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    const next = event.key === 'ArrowDown' ? row.nextElementSibling : row.previousElementSibling
    if (next && next.tagName === 'TR') next.focus()
  }
}

const rowProps = ({ item }) => {
  const isSelected = selectedResident.value && idOf(item) === idOf(selectedResident.value)
  return {
    class: isSelected ? 'selected-row' : '',
    tabindex: 0,
    'aria-selected': isSelected ? 'true' : 'false',
    // Without this a focused row reads as a run of cell text with no statement
    // of what Enter does. Same label the borrowing table's rows carry.
    'aria-label': `Open profile for ${fullName(item)}`,
    onKeydown: (e) => onRowKeydown(e, item),
  }
}

const fetchResidents = async () => {
  const res = await fetch(`${API_BASE}/residents`, { headers: getHeaders() })
  const data = await res.json()
  if (!res.ok) throw new Error(data.message || 'Failed to load residents')
  residents.value = data.data || data
  // Keep the open panel in step with the refreshed list. Residents are keyed
  // resident_id, never id — comparing on `id` silently left stale data on screen.
  if (selectedResident.value) {
    const id = idOf(selectedResident.value)
    selectedResident.value = residents.value.find((r) => idOf(r) === id) || null
  }
  loadPhotos()
}

// Not awaited by fetchResidents: the table is useful the moment the rows land,
// and an avatar that arrives a beat later is not worth blocking it for.
const loadPhotos = () => {
  for (const resident of residents.value) {
    if (!resident.has_photo) continue

    const id = idOf(resident)
    residentPhotoUrl(id).then((url) => {
      if (url) photoUrls.value = { ...photoUrls.value, [id]: url }
    })
  }
}

const fetchBarangays = async () => {
  const res = await fetch(`${API_BASE}/barangays`, { headers: getHeaders() })
  const data = await res.json()
  if (!res.ok) throw new Error(data.message || 'Failed to load barangays')
  barangays.value = data.data || data
}

const loadAll = async () => {
  apiError.value = ''
  try {
    await Promise.all([fetchBarangays(), fetchResidents()])
  } catch (error) {
    apiError.value = error.message
  } finally {
    initialLoad.value = false
  }
}

const openAddModal = () => {
  modalError.value = ''
  clearFieldErrors()
  // Rules fire on a pristine form otherwise: reopening after a failed save
  // would show the previous attempt's red before anything was typed.
  form.value?.resetValidation()
  showPassword.value = false
  formData.value = {
    first_name: '', middle_name: '', last_name: '', phone_number: '',
    email_address: '', password: '', barangay_id: null, status: RESIDENT_STATUS.active,
  }
  modal.value = { isOpen: true, isEditing: false, targetId: null }
}

const openExistingEditModal = (item) => {
  modalError.value = ''
  clearFieldErrors()
  form.value?.resetValidation()
  formData.value = {
    first_name: item.first_name,
    middle_name: item.middle_name,
    last_name: item.last_name,
    phone_number: item.phone_number,
    email_address: item.email_address,
    password: '',
    barangay_id: item.barangay_id,
    status: item.status,
  }
  modal.value = { isOpen: true, isEditing: true, targetId: idOf(item) }
}

const closeModal = () => { modal.value.isOpen = false }

// Client-side rules. The asterisks in the labels used to be decoration: no
// field carried a rule and `saveUser` never called `form.validate()`, so an
// empty form cost a network round trip and came back as six server sentences
// in one paragraph, naming columns ("barangay id") rather than fields.
const requiredRule = (label) => (v) =>
  (v !== null && v !== undefined && String(v).trim() !== '') || `${label} is required.`

const emailRule = (v) =>
  !v || /^[^\s@]+@[^\s@][^\s.@]*\.[^\s@]+$/.test(v) || 'Enter a valid email address, like juan@example.com.'

// Deliberately loose: 09xx, +639xx and landlines all reach residents here, and
// a strict pattern would refuse numbers the office actually holds.
const phoneRule = (v) =>
  !v || v.replace(/\D/g, '').length >= 7 || 'Enter a full phone number.'

// Mirrors the hint already printed under the field, and the backend's own rule.
const passwordRule = (v) =>
  !v || (v.length >= 8 && /[a-z]/.test(v) && /[A-Z]/.test(v) && /\d/.test(v)) ||
  'At least 8 characters, with upper and lower case and a number.'

// Server-side errors, keyed by field, so a 422 lands on the input it belongs
// to instead of being concatenated into a blob above the form.
const fieldErrors = ref({})

const clearFieldErrors = () => { fieldErrors.value = {} }

// Laravel answers `{errors: {field: [msg]}}`. Split it: known fields go to
// their input, anything unrecognised stays in the summary alert so nothing is
// silently swallowed.
const applyServerErrors = async (res) => {
  const data = await res.json().catch(() => ({}))
  if (data.errors && typeof data.errors === 'object') {
    const mapped = {}
    const leftovers = []
    for (const [key, messages] of Object.entries(data.errors)) {
      const text = Array.isArray(messages) ? messages.join(' ') : String(messages)
      if (key in formData.value) mapped[key] = text
      else leftovers.push(text)
    }
    fieldErrors.value = mapped
    return leftovers.length > 0 ? leftovers.join(' ') : 'Please correct the highlighted fields.'
  }
  return data.message || 'Request failed'
}

// The plain-message counterpart to applyServerErrors above, for the actions with
// no form behind them — the list's status toggle and the delete dialog. Both
// were already calling this name; it had never been written, so every failure on
// either path surfaced as "errorFrom is not defined" rather than the server's
// reason.
//
// Deliberately NOT applyServerErrors: that one populates fieldErrors for inputs
// that are on screen, and neither caller has any. The message here is
// load-bearing rather than decorative — destroy() answers 422 with "Cannot
// delete — N service request(s) still reference this resident", which is the
// entire explanation for a refused delete.
const errorFrom = async (res) => {
  const data = await res.json().catch(() => ({}))
  // The status code is in the fallback because a bare "Request failed" gives an
  // operator nothing to act on or report.
  return data.message || `Request failed (${res.status})`
}

const saveUser = async () => {
  modalError.value = ''
  clearFieldErrors()

  // Validate before spending a round trip. Vuetify focuses the first invalid
  // field itself once the rules are attached.
  const { valid } = await form.value.validate()
  if (!valid) {
    modalError.value = 'Please correct the highlighted fields.'
    return
  }

  loading.value = true
  const editing = modal.value.isEditing
  const payload = { ...formData.value }
  if (editing && !payload.password) delete payload.password
  try {
    const res = await fetch(
      editing ? `${API_BASE}/residents/${modal.value.targetId}` : `${API_BASE}/residents`,
      { method: editing ? 'PUT' : 'POST', headers: getHeaders(), body: JSON.stringify(payload) },
    )
    if (!res.ok) throw new Error(await applyServerErrors(res))
    await fetchResidents()
    closeModal()
    notify(editing ? 'Profile updated' : 'Account created')
  } catch (error) {
    modalError.value = error.message
  } finally {
    loading.value = false
  }
}

// Deactivating cuts the account's sign-in and SMS/app access, so it gets the
// same one-more-step confirm Staff Accounts already has for "Close account".
// Activating (Pending or Deactivated -> Active) is the safe direction and
// still fires immediately, matching Staff's own asymmetry: Reactivate there
// has no confirm dialog either.
const askToggleStatus = (item) => {
  if (item.status === RESIDENT_STATUS.active) {
    statusDialog.value = { show: true, item, loading: false }
    return
  }
  toggleStatus(item)
}

const confirmDeactivate = async () => {
  const item = statusDialog.value.item
  statusDialog.value.loading = true
  await toggleStatus(item)
  statusDialog.value = { show: false, item: null, loading: false }
}

const toggleStatus = async (item) => {
  // Pending and Deactivated both toggle to Active — activating a new signup and
  // re-enabling a suspended account are the same write.
  const next = item.status === RESIDENT_STATUS.active
    ? RESIDENT_STATUS.deactivated
    : RESIDENT_STATUS.active
  statusToggleLoading.value = true
  try {
    const res = await fetch(`${API_BASE}/residents/${idOf(item)}`, {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify({
        first_name: item.first_name,
        middle_name: item.middle_name,
        last_name: item.last_name,
        phone_number: item.phone_number,
        email_address: item.email_address,
        barangay_id: item.barangay_id,
        status: next,
      }),
    })
    if (!res.ok) throw new Error(await errorFrom(res))
    await fetchResidents()
    notify(next === RESIDENT_STATUS.active ? 'Account activated' : 'Account deactivated')
  } catch (error) {
    notify(error.message, 'error')
  } finally {
    statusToggleLoading.value = false
  }
}

const askDelete = (item) => { deleteDialog.value = { show: true, item, loading: false } }

const confirmDelete = async () => {
  const item = deleteDialog.value.item
  deleteDialog.value.loading = true
  try {
    const res = await fetch(`${API_BASE}/residents/${idOf(item)}`, { method: 'DELETE', headers: getHeaders() })
    if (!res.ok) throw new Error(await errorFrom(res))
    // The row is gone; keeping its blob alive would hand the next resident to
    // take that id someone else's face.
    forgetResidentPhoto(idOf(item))
    delete photoUrls.value[idOf(item)]
    selectedResident.value = null
    await fetchResidents()
    deleteDialog.value.show = false
    notify('Account deleted')
  } catch (error) {
    notify(error.message, 'error')
  } finally {
    deleteDialog.value.loading = false
  }
}

onMounted(() => {
  loadAll()
  window.addEventListener('keydown', onEscape)
  document.addEventListener('click', onDocumentClick)
})
// One blob per resident would otherwise survive every visit to this view for
// the life of the tab.
onUnmounted(() => {
  releaseResidentPhotos()
  window.removeEventListener('keydown', onEscape)
  document.removeEventListener('click', onDocumentClick)
})
</script>

<style scoped>
/* Table and profile rail side by side. The table is the flexible half and
   carries `min-width: 0`, without which a flex child refuses to shrink below
   its content and the rail would push it off the page instead of compressing
   it. */
.residents-layout {
  display: flex;
  align-items: stretch;
  width: 100%;
}
.residents-main {
  flex: 1 1 auto;
  min-width: 0;
  height: 100%;
}

/* Width is the animated property, and it animates from zero — the rail is in
   the layout at all times, just with nothing to show. Transitioning width is
   normally the wrong instinct, but the thing being resized here is an empty
   clipping box: the panel inside it is a fixed 460 and never reflows, so no
   frame of this costs a text layout. */
.detail-rail {
  flex: 0 0 auto;
  width: 0;
  height: 100%;
  overflow: hidden;
  /* An even curve, not the expo `cubic-bezier(0.16, 1, 0.3, 1)` this file uses
     for hovers and fades. Over 476px that one puts ~93% of the travel into the
     first 30ms and reads as a snap with a long tail — fine for a 4px lift, not
     for the table changing width under the reader. */
  transition: width 280ms cubic-bezier(0.4, 0, 0.2, 1);
}
.detail-rail--open {
  width: 476px; /* 460 panel + the 16px gutter that appears with it */
}
.detail-rail__inner {
  width: 460px;
  margin-left: 16px;
  height: 100%;
}

/* Under 960 there is no room to split anything — the table is already at its
   min-width and the sidebar has taken 260px — so the rail takes the row and
   the table yields it entirely rather than the two sharing a width neither can
   use. */
@media (max-width: 959px) {
  .detail-rail--open {
    width: 100%;
  }
  .detail-rail--open + .residents-main,
  .residents-layout:has(.detail-rail--open) .residents-main {
    flex: 0 0 0;
    width: 0;
    overflow: hidden;
  }
  .detail-rail__inner {
    width: 100%;
    margin-left: 0;
  }
}

.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
.search-field { width: 260px; max-width: 100%; }
.status-field { width: 150px; max-width: 100%; }

.tab-btn {
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  border-bottom: 3px solid transparent;
}
/* Deep brand green reads well on the light surface (9.9:1) but vanishes on the
   dark one, so each theme gets the version that stays legible. The primary
   token alone is not enough: it is mint in dark (good) but only 5.15:1 on
   white, and the underline needs the heavier weight. */
.active-tab {
  border-bottom: 3px solid #0f4c3a !important;
  color: #0f4c3a !important;
}
.v-theme--dark .active-tab {
  border-bottom-color: rgb(var(--v-theme-primary)) !important;
  color: rgb(var(--v-theme-primary)) !important;
}

.transition-btn { transition: transform 0.2s ease, opacity 0.2s ease; }
.transition-btn:hover { transform: translateY(-2px); opacity: 0.95; }

/* Avatars — the old blue-on-light-blue pairing measured 3.28:1. Tinting the
   primary token instead keeps the same soft look and passes AA in both themes. */
.avatar-tint {
  background: rgba(var(--v-theme-primary), 0.14) !important;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
}
.avatar-initials {
  /* Not primary: the table avatar draws these at body size, where primary on
     the 14% tint is 4.25:1 and fails AA. See the token in plugins/vuetify.ts. */
  color: rgb(var(--v-theme-primary-strong));
  font-weight: 800;
  letter-spacing: 0.02em;
}

/* Table.
   The 1000px min-width is load-bearing (860 before the row-number column and
   the two fixed pill columns were budgeted). Below it the percentage columns
   squeeze the pill columns under the width of the pill they print. `table-layout: fixed` with percentage
   columns and no floor lets a narrow wrapper crush every column proportionally
   instead of scrolling: measured at 430px the six data columns collapsed to
   1px each and only the avatars rendered. Same bug class, same fix, as the
   borrowing table (see EquipmentBorrowingView's own min-width comment). */
.elegant-table :deep(table) {
  table-layout: fixed !important;
  width: 100% !important;
  min-width: 1000px;
}
.row-number {
  font-size: 0.95rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
/* 16px, not 24px: seven columns share the card once SMS Blasts is in, and the
   two pill columns need their width for the pill rather than for gutters. */
.elegant-table :deep(td) {
  padding: 18px 16px !important;
  height: 76px !important;
  font-size: 0.95rem;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08) !important;
  cursor: pointer;
}
.elegant-table :deep(th) {
  font-size: 0.85rem !important;
  font-weight: 700 !important;
  color: #ffffff !important;
  padding: 0 16px !important;
  height: 56px !important;
  border-bottom: 2px solid rgba(var(--v-theme-on-surface), 0.12) !important;
  /* Fixed brand green, not the primary token: this header carries white text,
     and primary lightens to mint in the dark theme (white-on-mint ~2.2:1). */
  background-color: #0f4c3a !important;
  white-space: nowrap !important;
}
.cell-truncate {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Selection — previously these classes were applied but never styled, so the
   chosen row was indistinguishable from the rest. */
.elegant-table :deep(tr.selected-row) {
  background: rgba(var(--v-theme-primary), 0.1) !important;
  box-shadow: inset 4px 0 0 0 rgb(var(--v-theme-primary));
}
.elegant-table :deep(tr.selected-row td) { font-weight: 600; }
.elegant-table :deep(tbody tr:focus-visible) {
  outline: 3px solid rgb(var(--v-theme-primary));
  outline-offset: -3px;
}
/* The scrollbar was hidden outright. With a min-width on the table the wrapper
   is now the only thing that scrolls sideways, so hiding it would leave the
   clipped columns with no cue that they exist at all — thin and tinted, not
   absent. */
.elegant-table :deep(.v-table__wrapper) {
  scrollbar-width: thin;
  scrollbar-color: rgba(var(--v-theme-on-surface), 0.25) transparent;
}
.elegant-table :deep(.v-table__wrapper)::-webkit-scrollbar { height: 8px; }
.elegant-table :deep(.v-table__wrapper)::-webkit-scrollbar-thumb {
  background: rgba(var(--v-theme-on-surface), 0.25);
  border-radius: 4px;
}

/* Status pills — replace the flat grey chip (white on #9E9E9E, 2.68:1). */
.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 5px 12px;
  border-radius: 8px;
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  white-space: nowrap;
}
/* primary-strong, not primary. Measured in the browser: primary on its own
   14% tint is 4.28:1 in the light theme, and at 12px/700 this is not WCAG
   large text, so 4.5:1 applies — it was 0.22 short. The strong token is the
   same fix the avatar initials already carry, and in dark the two tokens are
   the same value, so nothing changes there. */
.pill-active { background: rgba(var(--v-theme-primary), 0.14); color: rgb(var(--v-theme-primary-strong)); }
.pill-inactive {
  background: rgba(var(--v-theme-on-surface), 0.1);
  color: rgba(var(--v-theme-on-surface), 0.82);
}
/* Pending. The warning token itself is #F57C00 in light, which is 3.0:1 on
   white — the pill text is 12px bold, so it needs 4.5:1, not the large-text
   3:1. The darker amber (5.94:1 over the tint) is now the `warning-strong`
   theme token rather than a hex hardcoded here; same value, one source, and
   ManageRequestView's pills use it too. In dark the token is light enough to
   use directly, which is what warning-strong aliases to there. */
.pill-pending { background: rgba(var(--v-theme-warning), 0.14); color: rgb(var(--v-theme-warning-strong)); }
.v-theme--dark .pill-pending {
  background: rgba(var(--v-theme-warning), 0.1);
  color: rgb(var(--v-theme-warning));
}
.status-dot { width: 8px; height: 8px; border-radius: 50%; flex: none; }
.dot-active { background: rgb(var(--v-theme-primary)); }
.dot-inactive { background: rgba(var(--v-theme-on-surface), 0.5); }
.dot-pending { background: rgb(var(--v-theme-warning)); }

.empty-state {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center; padding: 64px 24px;
}

/* Visible to a screen reader, to nothing else. clip rather than display:none,
   which would remove the node from the accessibility tree and silence the
   live regions entirely. */
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}


@media (prefers-reduced-motion: reduce) {
  .tab-btn, .transition-btn, .detail-rail { transition: none; }
  .transition-btn:hover { transform: none; }
}
</style>
