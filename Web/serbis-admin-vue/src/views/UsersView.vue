<template>
  <v-container fluid class="fill-height align-start bg-background">
    <div class="w-100">
      <PageHeader title="Residents">
            <!-- On the title's own line, as a chip: a count that sat below the
                 title in grey read as a caption, not as a number worth
                 noticing. Says "of" only when something is being hidden. The
                 permanent "N of N" read as a standing accusation that a filter
                 was on. ("residents" here is deliberate and ruled on; the
                 heading beside it is the page/nav title.) -->
            <template v-slot:badge>
              <span v-if="!initialLoad" class="count-chip" role="status">
                <template v-if="filteredAndSortedResidents.length === residents.length">
                  <strong>{{ residents.length }}</strong>
                  {{ residents.length === 1 ? 'account' : 'accounts' }}
                </template>
                <template v-else>
                  <strong>{{ filteredAndSortedResidents.length }}</strong>
                  of {{ residents.length }} accounts
                </template>
              </span>
            </template>

            <template v-slot:actions>
              <v-btn
                color="primary"
                elevation="0"
                rounded="lg"
                height="48"
                class="px-5 text-none font-weight-bold text-white transition-btn"
                @click="openAddModal"
              >
                <v-icon start>mdi-plus</v-icon> Add account
              </v-btn>
            </template>
      </PageHeader>

    <div class="residents-layout" :style="rowStyle">

      <div class="residents-main">
        <v-card elevation="3" rounded="lg" class="bg-surface w-100 h-100 d-flex flex-column">

          <div class="px-6 py-2 border-b d-flex align-center flex-wrap gap-3 flex-shrink-0">
            <v-text-field
              v-model="search"
              prepend-inner-icon="mdi-magnify"
              label="Search residents"
              placeholder="Name or mobile number"
              clearable
              variant="outlined"
              density="compact"
              hide-details
              rounded="lg"
              class="search-field"
            ></v-text-field>

            <v-select
              v-model="filters.status"
              :items="RESIDENT_STATUS_FILTER_ITEMS"
              label="Status"
              variant="outlined"
              density="compact"
              hide-details
              rounded="lg"
              class="status-field"
            ></v-select>

            <v-select
              v-model="filters.type"
              :items="ACCOUNT_TYPE_FILTER_ITEMS"
              label="Account type"
              variant="outlined"
              density="compact"
              hide-details
              rounded="lg"
              class="type-field"
            ></v-select>
          </div>

          <!-- `aria-pressed` is what makes the active filter perceivable at
               all without sight: the selected barangay was carried by colour
               and a 3px underline alone, and the group had no accessible name
               saying what these buttons even filter. -->
          <div
            class="px-6 py-1 border-b subtle-surface d-flex align-center gap-2 overflow-x-auto flex-shrink-0"
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

          <!-- Empty -->
          <div v-if="!initialLoad && filteredAndSortedResidents.length === 0" class="empty-state flex-grow-1">
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
            :key="initialLoad ? 'loading' : 'ready'"
            :headers="headers"
            :items="filteredAndSortedResidents"
            :items-per-page="-1"
            fixed-header
            :height="tableHeight"
            hover
            class="elegant-table flex-grow-1 table-fade"
            item-value="resident_id"
            @click:row="selectRow"
            :row-props="rowProps"
          >
            <template v-slot:bottom></template>
            <template v-if="initialLoad" #body>
              <SkeletonRows :rows="10" :columns="headers.length" />
            </template>

            <template v-slot:item.rowNumber="{ item }">
              <span class="row-number text-medium-emphasis">{{ rowNumber(item) }}</span>
            </template>

            <template v-slot:item.photo="{ item }">
              <v-avatar :color="undefined" size="36" class="my-1 avatar-tint">
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

            <template v-slot:item.account_type="{ item }">
              <span class="type-pill" :class="accountTypePillClass(item.account_type)">
                {{ item.account_type === ACCOUNT_TYPE.organization && item.organization_name ? item.organization_name : accountTypeLabel(item.account_type) }}
              </span>
            </template>

            <template v-slot:item.barangay_name="{ item }">
              <span class="font-weight-medium text-body-1 text-high-emphasis cell-truncate">
                {{ barangayOf(item) }}
              </span>
            </template>

            <!-- The number is the resident's login. Stored as +639…, read as 09…. -->
            <template v-slot:item.phone_number="{ item }">
              <span class="text-body-1 text-medium-emphasis cell-truncate">{{ displayPhone(item.phone_number) }}</span>
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

    </div>
    </div>

    <!-- The profile is a centred dialog, not a rail beside the table: the table
         keeps the whole page, and everything about one account is on screen at
         once instead of in a narrow scrolling column. A dialog gives Esc, the
         scrim click and stacking for free, so Edit, Delete and Activate open
         over it without anything having to work out who owns the key.

         Rendered from `shownResident`, which outlives `selectedResident` by the
         length of the fade-out: clearing the selection would otherwise empty the
         card while it is still on screen. -->
    <v-dialog v-model="detailOpen" max-width="960">
      <v-card v-if="shownResident" rounded="lg" elevation="10" class="bg-surface">
        <ResidentDetailPanel
          :resident="shownResident"
          :status-loading="statusToggleLoading"
          :hidden-by-filter="selectionHidden"
          @close="closeDetail"
          @edit="openExistingEditModal"
          @toggle-status="askToggleStatus"
          @reject="askReject"
          @delete="askDelete"
          @clear-filters="clearFilters"
        />
      </v-card>
    </v-dialog>

    <!-- Add / Edit -->
    <v-dialog v-model="modal.isOpen" max-width="680" persistent>
      <v-card rounded="lg" elevation="10">
        <v-card-title class="d-flex justify-space-between align-center pa-6 border-b bg-surface">
          <span class="text-h6 font-weight-bold text-high-emphasis">
            {{ modal.isEditing ? 'Edit account' : 'New account' }}
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
                  <div class="text-subtitle-2 font-weight-bold text-high-emphasis">Initials</div>
                </div>
              </v-col>

              <v-col cols="12" :md="isOrganization ? 5 : 12">
                <v-select
                  v-model="formData.account_type"
                  :items="ACCOUNT_TYPE_ITEMS"
                  label="Account type *"
                  :error-messages="fieldErrors.account_type"
                  variant="outlined"
                  density="comfortable"
                  rounded="lg"
                ></v-select>
              </v-col>

              <v-col v-if="isOrganization" cols="12" md="7">
                <v-text-field
                  v-model="formData.organization_name"
                  label="Organization name *"
                  placeholder="Isabela State University"
                  :rules="[requiredRule('Organization name')]"
                  :error-messages="fieldErrors.organization_name"
                  variant="outlined"
                  density="comfortable"
                  rounded="lg"
                ></v-text-field>
              </v-col>

              <v-col cols="12" md="4">
                <v-text-field v-model="formData.first_name" :label="isHead ? 'First Name *' : 'Contact first name *'" placeholder="Juan" :rules="[requiredRule('First name')]" :error-messages="fieldErrors.first_name" variant="outlined" density="comfortable" rounded="lg" autocomplete="given-name"></v-text-field>
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field v-model="formData.middle_name" label="Middle Name" placeholder="Santos" :error-messages="fieldErrors.middle_name" variant="outlined" density="comfortable" rounded="lg" autocomplete="additional-name"></v-text-field>
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field v-model="formData.last_name" :label="isHead ? 'Last Name *' : 'Contact last name *'" placeholder="Dela Cruz" :rules="[requiredRule('Last name')]" :error-messages="fieldErrors.last_name" variant="outlined" density="comfortable" rounded="lg" autocomplete="family-name"></v-text-field>
              </v-col>

              <v-col cols="12" md="6">
                <!-- The resident logs in with this number, and no two accounts may
                     share one. A barangay or organization officer who is also a
                     head of the family needs a different number for this account;
                     the server says so on the field when it is taken. -->
                <v-text-field v-model="formData.phone_number" label="Mobile Number (login) *" placeholder="09171234567" hint="They sign in with this number. It must be unique." persistent-hint :rules="[requiredRule('Mobile number'), phoneRule]" :error-messages="fieldErrors.phone_number" type="tel" variant="outlined" density="comfortable" rounded="lg" autocomplete="tel"></v-text-field>
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
                <!-- Keyed on the dialog: the picker reads its value once, and this
                     form is reused for every resident. -->
                <PurokSelect
                  :key="`${modal.isOpen}-${modal.targetId ?? 'new'}`"
                  v-model="formData.street_address"
                  :error-messages="fieldErrors.street_address"
                />
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
                <v-radio-group v-model="formData.status" inline hide-details color="primary">
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
            color="primary"
            variant="flat"
            rounded="lg"
            class="px-6 text-none font-weight-bold"
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
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">
          {{ statusDialog.reject ? 'Reject this organization?' : 'Deactivate this account?' }}
        </v-card-title>
        <v-card-text class="px-6 py-4 text-body-2 text-medium-emphasis">
          <strong class="text-high-emphasis">{{ statusDialog.item ? (statusDialog.item.organization_name || fullName(statusDialog.item)) : '' }}</strong>
          <template v-if="statusDialog.reject">
            will not be able to sign in or request services. You can approve it later from this page.
          </template>
          <template v-else>
            will lose access to sign in and file requests, and will stop receiving MDRRMO text blasts.
            It can be reactivated later.
          </template>
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="text" rounded="lg" class="text-none" :disabled="statusDialog.loading" @click="statusDialog.show = false">
            Cancel
          </v-btn>
          <v-btn
            color="warning" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold"
            :loading="statusDialog.loading" @click="confirmDeactivate"
          >
            {{ statusDialog.reject ? 'Reject organization' : 'Deactivate account' }}
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
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue'
import { useDisplay } from 'vuetify'
import { initials as computeInitials } from '@/composables/adminUi'
import { getToken } from '@/composables/authToken'
import { displayPhone, isMobileNumber } from '@/composables/phoneNumber'
import { useRowNumbers } from '@/composables/rowNumber'
import {
  forgetResidentPhoto,
  releaseResidentPhotos,
  residentPhotoUrl,
} from '@/composables/residentPhoto'
import {
  ACCOUNT_TYPE,
  ACCOUNT_TYPE_FILTER_ITEMS,
  ACCOUNT_TYPE_ITEMS,
  accountTypeLabel,
  accountTypePillClass,
} from '@/composables/accountType'
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
import PageHeader from '@/components/PageHeader.vue'
import PurokSelect from '@/components/PurokSelect.vue'
import SkeletonRows from '@/components/SkeletonRows.vue'

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
  { title: 'Last Name', key: 'last_name', width: '9%' },
  { title: 'First Name', key: 'first_name', width: '9%' },
  { title: 'Type', key: 'account_type', width: '230px' },
  // The longest real barangay name in the data is "San Antonio Ugad", which
  // was still clipping when this column was 15% of a narrower table.
  { title: 'Barangay', key: 'barangay_name', width: '14%' },
  { title: 'Mobile Number', key: 'phone_number', width: '14%' },
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
const filters = ref({ status: 'All', barangay: 'All', type: 'All' })
const modal = ref({ isOpen: false, isEditing: false, targetId: null })
const deleteDialog = ref({ show: false, item: null, loading: false })
// `reject` is set when the dialog is refusing a pending organization rather than
// deactivating an active account; both end in Deactivated.
const statusDialog = ref({ show: false, item: null, loading: false, reject: false })
const snackbar = ref({ show: false, text: '', color: 'success' })
const statusToggleLoading = ref(false)

const formData = ref({
  first_name: '', middle_name: '', last_name: '', phone_number: '',
  password: '', barangay_id: null, street_address: '',
  status: RESIDENT_STATUS.active, account_type: ACCOUNT_TYPE.head, organization_name: '',
})

const isHead = computed(() => formData.value.account_type === ACCOUNT_TYPE.head)
const isOrganization = computed(() => formData.value.account_type === ACCOUNT_TYPE.organization)

// The profile dialog is open exactly when a resident is selected. There is no
// second piece of state that can disagree with the first; the setter is what
// lets the dialog's own Esc and scrim click clear the selection.
const detailOpen = computed({
  get: () => Boolean(selectedResident.value),
  set: (open) => { if (!open) selectedResident.value = null },
})
const closeDetail = () => { selectedResident.value = null }

// What the dialog draws. It keeps the last resident through the fade-out, so
// clearing the selection does not empty the card while it is still visible.
const shownResident = ref(null)
watch(selectedResident, (resident) => { if (resident) shownResident.value = resident })

// The row that opened the profile. Rows are keyboard-focusable and open on
// Enter, so closing the dialog puts focus back on the row rather than dropping
// it on the page, which would send the next Tab back to the top of the table.
let lastRow = null
watch(detailOpen, (open) => {
  if (!open) nextTick(() => lastRow?.focus?.())
})

// Heights are worked out by hand from the pieces above the table, not measured
// in a browser: the shell and container padding and the page header (72px: a
// 48px title-and-actions row plus its 24px margin) take 168px in all; the card's
// search toolbar (56px), the barangay tabs (44px) and the table's own header
// (44px) take another 144. Change any of those heights and this changes with it.
const rowStyle = computed(() => (mdAndUp.value ? 'height: calc(100vh - 168px);' : ''))
const tableHeight = computed(() => (mdAndUp.value ? 'calc(100vh - 312px)' : '60vh'))

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
  if (filters.value.type !== 'All') result = result.filter((r) => (r.account_type || ACCOUNT_TYPE.head) === filters.value.type)
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
        // Either spelling finds the number: staff type 0917… and the server
        // holds +63917….
        displayPhone(r.phone_number).includes(q) ||
        (r.phone_number || '').includes(q)
    })
  }
  return [...result].sort((a, b) => `${a.last_name} ${a.first_name}`.localeCompare(`${b.last_name} ${b.first_name}`))
})

const rowNumber = useRowNumbers(filteredAndSortedResidents, 'resident_id')

const clearFilters = () => {
  search.value = ''
  filters.value = { status: 'All', barangay: 'All', type: 'All' }
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

const selectRow = (event, { item }) => {
  lastRow = event?.currentTarget ?? null
  selectedResident.value = item
}

// Rows are focusable and respond to Enter/Space, so a resident can be opened
// without a mouse; arrows walk the list the way a native listbox would.
const onRowKeydown = (event, item) => {
  const row = event.currentTarget
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault()
    lastRow = row
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
    password: '', barangay_id: null, street_address: '',
    status: RESIDENT_STATUS.active, account_type: ACCOUNT_TYPE.head, organization_name: '',
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
    // As staff read it (09…); the server accepts either spelling and stores +63….
    phone_number: displayPhone(item.phone_number),
    password: '',
    barangay_id: item.barangay_id,
    street_address: item.street_address ?? '',
    status: item.status,
    account_type: item.account_type || ACCOUNT_TYPE.head,
    organization_name: item.organization_name ?? '',
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

// The number is the login and the SMS destination, so it has to be a real
// Philippine mobile number — the same rule the server applies, and no landline.
const phoneRule = (v) =>
  !v || isMobileNumber(v) || 'Enter a mobile number like 09171234567.'

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

const askReject = (item) => {
  statusDialog.value = { show: true, item, loading: false, reject: true }
}

const confirmDeactivate = async () => {
  const { item, reject } = statusDialog.value
  statusDialog.value.loading = true
  await toggleStatus(item, reject ? RESIDENT_STATUS.deactivated : null)
  statusDialog.value = { show: false, item: null, loading: false, reject: false }
}

const toggleStatus = async (item, forcedNext = null) => {
  // Pending and Deactivated both toggle to Active — activating a new signup and
  // re-enabling a suspended account are the same write. `forcedNext` is for
  // rejecting a pending organization, which goes straight to Deactivated.
  const next = forcedNext ?? (item.status === RESIDENT_STATUS.active
    ? RESIDENT_STATUS.deactivated
    : RESIDENT_STATUS.active)
  const isOrganization = item.account_type === ACCOUNT_TYPE.organization
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
        barangay_id: item.barangay_id,
        // Omitted, ResidentController::update() would default this back to
        // null — a status toggle must not silently wipe the resident's
        // street address (MDRRMO feedback, 2026-09-19).
        street_address: item.street_address ?? null,
        status: next,
      }),
    })
    if (!res.ok) throw new Error(await errorFrom(res))
    await fetchResidents()
    if (next === RESIDENT_STATUS.active) {
      notify(isOrganization ? 'Organization approved' : 'Account activated')
    } else {
      notify(forcedNext ? 'Organization rejected' : 'Account deactivated')
    }
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

onMounted(loadAll)
// One blob per resident would otherwise survive every visit to this view for
// the life of the tab.
onUnmounted(releaseResidentPhotos)
</script>

<style scoped>
/* The table takes the whole page; the profile is a dialog (see the template).
   `min-width: 0` is what lets a flex child shrink below its content instead of
   pushing the card off the page. */
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

/* The account count beside the title. primary-strong text on the 14% primary
   tint, the same AA-safe pairing the status pills use: primary itself is 4.28:1
   there and this is small type. */
.count-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 14px;
  border-radius: 999px;
  font-size: 0.875rem;
  font-weight: 600;
  line-height: 1.4;
  white-space: nowrap;
  background: rgba(var(--v-theme-primary), 0.14);
  color: rgb(var(--v-theme-primary-strong));
}
.count-chip strong { font-weight: 800; }

.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
.search-field { width: 260px; max-width: 100%; }
.status-field { width: 150px; max-width: 100%; }

.tab-btn {
  transition: all var(--motion-base) var(--ease-in-out);
  border-bottom: 3px solid transparent;
}
/* primary-strong exists for exactly this: primary alone is only 5.15:1 on a
   light surface, not enough for the underline's weight. primary-strong
   reaches 7.94:1 on white and is already aliased to primary in the dark
   theme (6.00:1 there), so one rule now covers both. */
.active-tab {
  border-bottom: 3px solid rgb(var(--v-theme-primary-strong)) !important;
  color: rgb(var(--v-theme-primary-strong)) !important;
}

.transition-btn { transition: transform var(--motion-base) var(--ease-out), opacity var(--motion-base) var(--ease-out); }
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
  padding: 6px 16px !important;
  height: 56px !important;
  font-size: 0.95rem;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08) !important;
  cursor: pointer;
}
.elegant-table :deep(th) {
  font-size: 0.85rem !important;
  font-weight: 700 !important;
  color: #ffffff !important;
  padding: 0 16px !important;
  height: 44px !important;
  border-bottom: 2px solid rgba(var(--v-theme-on-surface), 0.12) !important;
  /* secondary, not primary: this header carries white text, and primary
     lightens to mint in the dark theme (white-on-mint ~2.2:1, fails AA).
     secondary is the same #0A2620 in both themes on purpose (see
     plugins/vuetify.ts) -- 16.02:1 with white, so the header stays legible
     without needing its own per-theme override. */
  background-color: rgb(var(--v-theme-secondary)) !important;
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

/* Account-type pill. Barangay and organization accounts are the exceptions
   worth spotting in a list of households, so they get the tint; a head of the
   family stays plain text. */
.type-pill {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 8px;
  font-size: 0.8125rem;
  font-weight: 600;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.type-pill--institution { background: rgba(var(--v-theme-primary), 0.14); color: rgb(var(--v-theme-primary-strong)); }
.type-pill--plain { color: rgba(var(--v-theme-on-surface), 0.82); padding-left: 0; }
.type-field { width: 170px; max-width: 100%; }

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
  .tab-btn, .transition-btn { transition: none; }
  .transition-btn:hover { transform: none; }
}
</style>
