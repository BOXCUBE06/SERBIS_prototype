<template>
  <v-container fluid class="fill-height align-start bg-background">
    <div class="w-100">
      <PageHeader title="Accounts">
        <!-- The count is the subtitle line, as on the board. Says "of" only when
             something is being hidden: a permanent "N of N" read as a standing
             accusation that a filter was on. -->
        <template v-if="!initialLoad" v-slot:subtitle>
          <span role="status">
            <template v-if="filteredAndSortedResidents.length === residents.length">
              {{ residents.length }} {{ residents.length === 1 ? 'account' : 'accounts' }}
            </template>
            <template v-else>{{ filteredAndSortedResidents.length }} of {{ residents.length }} accounts</template>
          </span>
        </template>

        <template v-slot:actions>
          <v-btn color="primary" variant="flat" height="40" class="add-btn text-none font-weight-bold" @click="openAddModal">
            <v-icon start size="16">mdi-plus</v-icon> Add account
          </v-btn>
        </template>
      </PageHeader>

      <v-alert v-if="apiError" type="error" variant="tonal" density="comfortable" rounded="lg" class="mb-4">
        {{ apiError }}
        <template v-slot:append>
          <v-btn variant="outlined" color="primary" class="text-none font-weight-bold" @click="loadAll">Retry</v-btn>
        </template>
      </v-alert>

      <ListUpdateNotice :update="listUpdate" noun="account" @show="showNewAccounts" />

      <!-- The type tabs' counts reflect status/barangay/search, everything but
           the type itself, so switching tabs previews how many rows will show. -->
      <DataTablePage
        compact
        filter-bar
        collapse-mobile
        range-summary
        :row-height="56"
        class="accounts-table"
        :sort-by="defaultSort"
        :tabs="typeTabs"
        :status="filters.type"
        @update:status="filters.type = $event"
        :loading="initialLoad"
        v-model:search="search"
        search-placeholder="Search name or mobile number"
        :headers="headers"
        :items="filteredAndSortedResidents"
        item-value="resident_id"
        :no-data-text="residents.length > 0 ? 'No accounts match your filters' : 'No accounts registered yet'"
        :page="page"
        @update:page="page = $event"
        :items-per-page="itemsPerPage"
        @update:items-per-page="itemsPerPage = $event"
        result-noun="accounts"
        :active-filters="activeFilters"
        @clear-filter="clearFilter"
        @clear-all="clearFilters"
        :row-props="rowProps"
        @click:row="selectRow"
      >
        <template v-slot:filters>
          <FilterSelect v-model="filters.status" :items="RESIDENT_STATUS_FILTER_ITEMS" label="Status" />
          <!-- Replaces a row of 60+ barangay tabs that scrolled sideways. An
               autocomplete so 60+ names can be typed for, styled and defaulted
               ("All") like the Status select beside it. -->
          <v-autocomplete
            v-model="filters.barangay"
            :items="barangayItems"
            prefix="Barangay"
            aria-label="Filter by barangay"
            variant="outlined" density="compact" hide-details rounded="lg"
            class="filter-bar__select"
          ></v-autocomplete>
        </template>

        <!-- Bulk actions. Present only while rows are ticked, so the table is not
             pushed around by a bar nobody asked for. -->
        <template v-if="selectedRows.length > 0" v-slot:before-table>
          <div class="bulk-bar px-4 py-2 mb-3 rounded-lg d-flex align-center flex-wrap gap-3" role="region" aria-label="Bulk actions">
            <span class="font-weight-bold text-body-2" aria-live="polite">{{ selectedRows.length }} selected</span>
            <v-btn size="small" variant="tonal" color="primary" class="text-none font-weight-bold" @click="askBulk('activate')">
              <v-icon start size="18">mdi-account-check-outline</v-icon>Activate
            </v-btn>
            <v-btn size="small" variant="tonal" color="warning" class="text-none font-weight-bold" @click="askBulk('deactivate')">
              <v-icon start size="18">mdi-account-cancel-outline</v-icon>Deactivate
            </v-btn>
            <v-btn size="small" variant="text" class="text-none" @click="clearSelection">Clear selection</v-btn>
          </div>
        </template>

        <!-- Own checkboxes rather than the table's `show-select`, so the click can
             be stopped here: a row opens the profile on click and on Enter, and
             ticking a box must not. Select-all covers every row the filters show,
             across pages. -->
        <template v-slot:header.select>
          <v-checkbox-btn
            density="compact"
            :model-value="allSelected"
            :indeterminate="selectedRows.length > 0 && !allSelected"
            aria-label="Select all accounts"
            @update:model-value="toggleAll"
            @click.stop
          ></v-checkbox-btn>
        </template>

        <template v-slot:item.select="{ item }">
          <div class="d-flex justify-center" @click.stop @keydown.stop>
            <v-checkbox-btn
              density="compact"
              :model-value="selectedIds.has(idOf(item))"
              :aria-label="`Select ${primaryName(item)}`"
              @update:model-value="toggleRow(item)"
            ></v-checkbox-btn>
          </div>
        </template>

        <!-- One Name column: the person (the head of the family, or the contact
             for a barangay or organization) as "Last, First M.", their mobile
             number under it. The number is the login, stored as +639…, read as
             09…. The round mark is the account's (its photo, else its initials). -->
        <template v-slot:item.name="{ item }">
          <PersonCell
            v-intersect.once="() => ensurePhoto(item)"
            :name="nameWithInitial(item)"
            :secondary="displayPhone(item.phone_number)"
            :initials="initials(item)"
            :photo="photoUrls[idOf(item)]"
            size="36"
            tinted
          />
        </template>

        <!-- The type tag on every account, on one line. An organization adds its
             name, truncated with a tooltip; a barangay account's name would only
             repeat the Barangay column. -->
        <template v-slot:item.account="{ item }">
          <div class="account-cell">
            <StatusPill tag :label="accountTypeLabel(item.account_type)" class="flex-shrink-0" />
            <span v-if="item.account_type === ACCOUNT_TYPE.organization" class="cell-truncate" :title="item.organization_name">{{ item.organization_name || '—' }}</span>
          </div>
        </template>

        <template v-slot:item.barangay_name="{ item }">
          <span class="cell-truncate" :title="barangayOf(item)">{{ barangayOf(item) }}</span>
        </template>

        <template v-slot:item.status="{ item }">
          <StatusPill :status="residentStatusLabel(item.status)" />
        </template>

        <!-- An exception column: nearly every row is Receiving, so only the
             accounts that opted out draw anything. The words stay for screen
             readers. -->
        <template v-slot:item.sms_opt_in="{ item }">
          <StatusPill v-if="!residentSmsOptIn(item)" :status="residentSmsLabel(false)" />
          <span v-else class="sr-only">{{ residentSmsLabel(true) }}</span>
        </template>

        <!-- Edit, and the Activate/Deactivate toggle as the extra action. Delete
             lives in the profile, not on the row. -->
        <template v-slot:item.actions="{ item }">
          <RowActions
            :label="primaryName(item)"
            :deletable="false"
            :extra="statusExtra(item)"
            @edit="openExistingEditModal(item)"
            @extra="askToggleStatus(item)"
          />
        </template>
      </DataTablePage>
    </div>

    <!-- The profile is a centred dialog, not a rail beside the table: the table
         keeps the whole page, and everything about one account is on screen at
         once instead of in a narrow scrolling column. A dialog gives Esc, the
         scrim click and stacking for free, so Edit, Delete and Activate open
         over it without anything having to work out who owns the key.

         Rendered from `shownResident`, which outlives `selectedResident` by the
         length of the fade-out: clearing the selection would otherwise empty the
         card while it is still on screen. -->
    <v-dialog v-model="detailOpen" max-width="880">
      <v-card v-if="shownResident" rounded="xl" class="detail-card bg-surface">
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
    <EditDialog
      ref="form"
      v-model="modal.isOpen"
      :title="modal.isEditing ? 'Edit account' : 'New account'"
      :confirm-label="modal.isEditing ? 'Save changes' : 'Create account'"
      :fields="accountFields"
      :form="formData"
      :field-errors="fieldErrors"
      :error="modalError"
      :loading="loading"
      :width="600"
      :avatar="previewInitials"
      :photo="editPhotoUrl"
      :avatar-note="canEditPhoto ? 'JPG or PNG, up to 4 MB. The photo saves right away; Cancel does not undo it.' : ''"
      @save="saveUser"
    >
      <template v-slot:avatar-actions>
        <!-- Photo for a saved barangay or organization account only. A head
             of the family's photo is their own face and is set from the
             app; a new account has no id to attach a file to yet. The
             upload happens the moment a file is chosen, on its own
             request, so Cancel on this form does not undo it. -->
        <div v-if="canEditPhoto" class="min-w-0">
          <div class="d-flex flex-wrap ga-2">
            <v-btn
              variant="outlined"
              height="40"
              class="photo-btn text-none"
              :loading="photoBusy"
              @click="photoInput?.click()"
            >
              <v-icon start size="16">mdi-camera-outline</v-icon>
              {{ editHasPhoto ? 'Replace photo' : 'Add photo' }}
            </v-btn>
            <v-btn
              v-if="editHasPhoto"
              variant="text"
              height="40"
              color="error"
              class="text-none font-weight-bold"
              :disabled="photoBusy"
              @click="removePhoto"
            >
              Remove
            </v-btn>
          </div>
          <div v-if="photoError" class="text-caption text-error mt-1" role="alert">{{ photoError }}</div>
          <input
            ref="photoInput"
            type="file"
            accept="image/png,image/jpeg"
            class="d-none"
            @change="onPhotoPicked"
          />
        </div>
        <div v-else>
          <div class="text-subtitle-2 font-weight-bold text-high-emphasis">Initials</div>
        </div>
      </template>

      <template v-slot:field-street_address>
        <!-- Keyed on the dialog: the picker reads its value once, and this
             form is reused for every resident. -->
        <PurokSelect
          compact
          :key="`${modal.isOpen}-${modal.targetId ?? 'new'}`"
          v-model="formData.street_address"
          :error-messages="fieldErrors.street_address"
        />
      </template>

      <template v-slot:before-status>
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
      </template>
    </EditDialog>

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
          <v-btn variant="outlined" color="primary" rounded="lg" height="48" class="text-none font-weight-bold" :disabled="deleteDialog.loading" @click="deleteDialog.show = false">
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
          <v-btn variant="outlined" color="primary" rounded="lg" class="text-none" :disabled="statusDialog.loading" @click="statusDialog.show = false">
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

    <!-- Bulk Activate / Deactivate: confirm, run, then the result. One dialog for
         all three so the list of what was skipped is on screen when it is over. -->
    <v-dialog v-model="bulk.show" max-width="520" :persistent="bulk.phase === 'running'">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">
          <template v-if="bulk.phase === 'done'">Result</template>
          <template v-else>{{ bulk.action === 'activate' ? 'Activate' : 'Deactivate' }} {{ bulk.eligible.length }} {{ bulk.eligible.length === 1 ? 'account' : 'accounts' }}?</template>
        </v-card-title>
        <v-card-text class="px-6 py-4 text-body-2 text-medium-emphasis bulk-body">
          <template v-if="bulk.phase !== 'done'">
            <p v-if="bulk.eligible.length === 0">Nothing to do for this selection.</p>
            <p v-else-if="bulk.action === 'deactivate'">
              They will lose access to sign in and file requests, and stop receiving MDRRMO text blasts. They can be reactivated later.
            </p>
            <p v-else>They will be able to sign in and file requests again.</p>
          </template>

          <template v-else>
            <p v-if="bulk.done.length > 0">
              {{ bulk.action === 'activate' ? 'Activated' : 'Deactivated' }} {{ bulk.done.length }}
              {{ bulk.done.length === 1 ? 'account' : 'accounts' }}.
            </p>
            <div v-if="bulk.failed.length > 0" class="mb-2">
              <strong class="text-error">Failed ({{ bulk.failed.length }})</strong>
              <ul><li v-for="f in bulk.failed" :key="idOf(f.item)">{{ primaryName(f.item) }} — {{ f.message }}</li></ul>
            </div>
          </template>

          <div v-if="bulk.pending.length > 0" class="mb-2">
            <strong class="text-high-emphasis">Skipped (pending review) ({{ bulk.pending.length }})</strong>
            <ul><li v-for="p in bulk.pending" :key="idOf(p)">{{ primaryName(p) }}</li></ul>
            <span>Review each in its profile, then approve it there.</span>
          </div>
          <p v-if="bulk.already.length > 0 && bulk.phase !== 'done'" class="mb-0">
            {{ bulk.already.length }} already {{ bulk.action === 'activate' ? 'active' : 'deactivated' }}, left as they are.
          </p>
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <template v-if="bulk.phase === 'done'">
            <v-btn color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" @click="bulk.show = false">Close</v-btn>
          </template>
          <template v-else>
            <v-btn variant="outlined" color="primary" rounded="lg" class="text-none" :disabled="bulk.phase === 'running'" @click="bulk.show = false">Cancel</v-btn>
            <v-btn
              :color="bulk.action === 'activate' ? 'primary' : 'warning'"
              variant="flat" rounded="lg" class="px-6 text-none font-weight-bold"
              :disabled="bulk.eligible.length === 0"
              :loading="bulk.phase === 'running'"
              @click="runBulk"
            >
              {{ bulk.action === 'activate' ? 'Activate' : 'Deactivate' }} {{ bulk.eligible.length }}
            </v-btn>
          </template>
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
import { ref, computed, nextTick, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { authHeaders } from '@/composables/adminUi'
import {
  accountInitials,
  barangayOf,
  fullName,
  nameWithInitial,
  primaryName,
  wordInitials,
} from '@/composables/accountName'
import { getToken } from '@/composables/authToken'
import { displayPhone, isMobileNumber } from '@/composables/phoneNumber'
import { forgetResidentPhoto, residentPhotoUrl } from '@/composables/residentPhoto'
import {
  ACCOUNT_TYPE,
  ACCOUNT_TYPE_FILTER_ITEMS,
  ACCOUNT_TYPE_ITEMS,
  accountTypeLabel,
} from '@/composables/accountType'
import {
  RESIDENT_STATUS,
  RESIDENT_STATUS_FILTER_ITEMS,
  residentSmsLabel,
  residentSmsOptIn,
  residentStatusLabel,
} from '@/composables/residentStatus'
import { API_BASE } from '@/config/api'
import { REFERENCE_TTL_MS, invalidate, useCachedFetch } from '@/composables/useCachedFetch'
import ResidentDetailPanel from '@/components/ResidentDetailPanel.vue'
import PageHeader from '@/components/PageHeader.vue'
import PurokSelect from '@/components/PurokSelect.vue'
import DataTablePage from '@/components/DataTablePage.vue'
import PersonCell from '@/components/PersonCell.vue'
import StatusPill from '@/components/StatusPill.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import EditDialog from '@/components/EditDialog.vue'
import RowActions from '@/components/RowActions.vue'
import ListUpdateNotice from '@/components/ListUpdateNotice.vue'
import { pulse, refreshPulse } from '@/composables/usePulse'
import { pulseDiff } from '@/composables/pulseDiff'

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
const photoInput = ref(null)
const photoBusy = ref(false)
const photoError = ref('')
// Template ref for the edit dialog, which hands `validate()` and
// `resetValidation()` on from its <v-form>.
const form = ref(null)

const selectedResident = ref(null)
// ?status=Inactive opens the list on Pending: the dashboard card and the bell
// link here that way. Only a value the filter offers is taken.
const route = useRoute()
const linkedStatus = RESIDENT_STATUS_FILTER_ITEMS.some((i) => i.value === route.query.status) ? route.query.status : 'All'
const filters = ref({ status: linkedStatus, barangay: 'All', type: 'All' })

// Sorting is client-side: GET /residents returns every account in one response,
// so the server has nothing to add. Each header sorts on what its cell prints
// (`value`), not a raw column. The list itself arrives ordered by last name.
//
// Fixed-layout table: the checkbox, Name and Actions columns carry a width and
// the rest share what is left; the table has an 880px floor (see the style
// block) and a narrower card scrolls sideways. On a phone Account and SMS blasts
// are dropped (`dtp-hide-sm`). The checkbox column is 54px: 24px of card-edge
// padding, the box, and the 12px gutter.
const HIDE_SM = { class: 'dtp-hide-sm' }
const headers = [
  { title: '', key: 'select', sortable: false, align: 'center', width: '54px' },
  // Sorts by last name, then first name; the cell prints "Last, First M.".
  { title: 'Name', key: 'name', width: '28%', value: (item) => `${item.last_name || ''}, ${item.first_name || ''}` },
  { title: 'Account', key: 'account', value: (item) => `${accountTypeLabel(item.account_type)} ${item.organization_name || ''}`, headerProps: HIDE_SM, cellProps: HIDE_SM },
  { title: 'Barangay', key: 'barangay_name', value: (item) => barangayOf(item) },
  { title: 'Status', key: 'status', value: (item) => residentStatusLabel(item.status) },
  // 0 before 1, so ascending lists the opted-out accounts first.
  {
    title: 'SMS blasts',
    key: 'sms_opt_in',
    value: (item) => (residentSmsOptIn(item) ? 1 : 0),
    headerProps: { title: 'Blank means receiving text blasts. Only accounts that opted out show a pill.', class: 'dtp-hide-sm' },
    cellProps: HIDE_SM,
  },
  { title: 'Actions', key: 'actions', sortable: false, align: 'end', width: '120px' },
]

// The order the list already arrives in, shown as the header's sort arrow.
const defaultSort = [{ key: 'last_name', order: 'asc' }]
const page = ref(1)
const itemsPerPage = ref(10)

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
const barangayItems = computed(() => ['All', ...barangays.value.map((b) => b.barangay_name)])
const editingAccount = computed(() =>
  modal.value.isEditing ? residents.value.find((r) => idOf(r) === modal.value.targetId) : null,
)
const canEditPhoto = computed(() =>
  [ACCOUNT_TYPE.barangay, ACCOUNT_TYPE.organization].includes(editingAccount.value?.account_type),
)
const editHasPhoto = computed(() => Boolean(editingAccount.value?.has_photo))
const editPhotoUrl = computed(() => (editHasPhoto.value ? photoUrls.value[modal.value.targetId] : null))
const previewInitials = computed(() => {
  const f = formData.value
  if (f.account_type === ACCOUNT_TYPE.organization) return wordInitials(f.organization_name) || '?'
  if (f.account_type === ACCOUNT_TYPE.barangay) return wordInitials(barangays.value.find((b) => b.barangay_id === f.barangay_id)?.barangay_name) || '?'
  return `${f.first_name?.charAt(0) || '?'}${f.last_name?.charAt(0) || ''}`.toUpperCase()
})

// The edit dialog's fields (labels and layout from the Edit/New account boards).
// The rules and the server's 422 field errors stay the page's own.
const accountFields = computed(() => [
  { key: 'account_type', label: 'Account type', required: true, items: ACCOUNT_TYPE_ITEMS, half: isOrganization.value },
  ...(isOrganization.value
    ? [{ key: 'organization_name', label: 'Organization name', required: true, half: true, placeholder: 'Isabela State University', rules: [requiredRule('Organization name')] }]
    : []),
  { key: 'first_name', label: isHead.value ? 'First name' : 'Contact first name', required: true, third: true, placeholder: 'Juan', rules: [requiredRule('First name')], autocomplete: 'given-name' },
  { key: 'middle_name', label: 'Middle name', third: true, placeholder: 'Santos', autocomplete: 'additional-name' },
  { key: 'last_name', label: isHead.value ? 'Last name' : 'Contact last name', required: true, third: true, placeholder: 'Dela Cruz', rules: [requiredRule('Last name')], autocomplete: 'family-name' },
  // The resident logs in with this number, and no two accounts may share one. A
  // barangay or organization officer who is also a head of the family needs a
  // different number for this account; the server says so on the field.
  {
    key: 'phone_number', label: 'Mobile number (login)', required: true, half: !modal.value.isEditing, type: 'tel',
    placeholder: '09171234567', hint: 'They sign in with this number. It must be unique.',
    rules: [requiredRule('Mobile number'), phoneRule], autocomplete: 'tel',
  },
  ...(modal.value.isEditing
    ? []
    : [{
        key: 'password', label: 'Password', required: true, half: true, type: 'password',
        hint: 'At least 8 characters, with upper and lower case and a number.',
        rules: [requiredRule('Password'), passwordRule], autocomplete: 'new-password',
      }]),
  { key: 'barangay_id', label: 'Barangay', required: true, half: true, items: barangays.value, itemTitle: 'barangay_name', itemValue: 'barangay_id', rules: [requiredRule('Barangay')] },
  { key: 'street_address', label: 'Street / Purok (optional)', half: true, slot: true },
  { key: 'status', label: 'Account status', options: [{ title: 'Active', value: RESIDENT_STATUS.active }, { title: 'Deactivated', value: RESIDENT_STATUS.deactivated }] },
])

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

const idOf = (r) => r?.resident_id ?? r?.id
// "Activate" for Pending and Deactivated alike, as in the profile's own button.
const statusActionLabel = (r) => (r.status === RESIDENT_STATUS.active ? 'Deactivate account' : 'Activate account')
const initials = accountInitials

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

// Status, barangay and search — everything the type tabs sit above. Split out
// so typeCounts can read "how many would show if I picked this tab" without
// the type filter it is itself choosing between.
const residentsBeforeType = computed(() => {
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
        (r.organization_name || '').toLowerCase().includes(q) ||
        // Either spelling finds the number: staff type 0917… and the server
        // holds +63917….
        displayPhone(r.phone_number).includes(q) ||
        (r.phone_number || '').includes(q)
    })
  }
  return result
})

const typeCounts = computed(() => {
  const list = residentsBeforeType.value
  const counts = { All: list.length }
  for (const type of Object.values(ACCOUNT_TYPE)) {
    counts[type] = list.filter((r) => (r.account_type || ACCOUNT_TYPE.head) === type).length
  }
  return counts
})

const filteredAndSortedResidents = computed(() => {
  let result = residentsBeforeType.value
  if (filters.value.type !== 'All') result = result.filter((r) => (r.account_type || ACCOUNT_TYPE.head) === filters.value.type)
  // The table's default order: last name, then first name, A-Z.
  return result.slice().sort((a, b) =>
    (a.last_name || '').localeCompare(b.last_name || '') || (a.first_name || '').localeCompare(b.first_name || ''))
})

const typeTabs = computed(() => ACCOUNT_TYPE_FILTER_ITEMS.map((t) => ({
  value: t.value,
  label: t.value === 'All' ? 'All' : t.title,
  count: typeCounts.value[t.value] ?? 0,
})))

// Type is the tabs, so it is not a chip here.
const activeFilters = computed(() => [
  ...(filters.value.status === 'All' ? [] : [{ key: 'status', label: `Status: ${residentStatusLabel(filters.value.status)}` }]),
  ...(filters.value.barangay === 'All' ? [] : [{ key: 'barangay', label: `Barangay: ${filters.value.barangay}` }]),
])
const clearFilter = (key) => { filters.value[key] = 'All' }
watch([search, filters], () => { page.value = 1 }, { deep: true })

// The row's Activate / Deactivate as RowActions' extra action.
const statusExtra = (r) => {
  const active = r.status === RESIDENT_STATUS.active
  return {
    label: statusActionLabel(r),
    icon: active ? 'mdi-account-cancel-outline' : 'mdi-account-check-outline',
    color: active ? 'warning-strong' : 'primary',
    disabled: statusToggleLoading.value,
  }
}

// Ticked rows, by id. Only ever the rows the filters currently show: the watch
// below drops the rest, so an action never lands on a row nobody can see.
const selectedIds = ref(new Set())
const selectedRows = computed(() => filteredAndSortedResidents.value.filter((r) => selectedIds.value.has(idOf(r))))
const allSelected = computed(() => filteredAndSortedResidents.value.length > 0 && selectedRows.value.length === filteredAndSortedResidents.value.length)
const toggleAll = (on) => { selectedIds.value = on ? new Set(filteredAndSortedResidents.value.map((r) => idOf(r))) : new Set() }
const toggleRow = (item) => {
  const next = new Set(selectedIds.value)
  const id = idOf(item)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  selectedIds.value = next
}
const clearSelection = () => { selectedIds.value = new Set() }
watch(filteredAndSortedResidents, (rows) => {
  const visible = new Set(rows.map((r) => idOf(r)))
  const kept = [...selectedIds.value].filter((id) => visible.has(id))
  if (kept.length !== selectedIds.value.size) selectedIds.value = new Set(kept)
})

// "Clear all" in the filter row: search, status and barangay. The type tab stays.
const clearFilters = () => {
  search.value = ''
  filters.value = { ...filters.value, status: 'All', barangay: 'All' }
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
  return `${shown} of ${total} accounts shown`
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
    class: [isSelected ? 'selected-row' : '', selectedIds.value.has(idOf(item)) ? 'is-checked' : ''],
    tabindex: 0,
    'aria-selected': isSelected ? 'true' : 'false',
    // Without this a focused row reads as a run of cell text with no statement
    // of what Enter does. Same label the borrowing table's rows carry.
    'aria-label': `Open profile for ${fullName(item)}`,
    onKeydown: (e) => onRowKeydown(e, item),
  }
}

const { get } = useCachedFetch()

const applyResidents = (data) => {
  residents.value = data.data || data
  // Keep the open panel in step with the refreshed list. Residents are keyed
  // resident_id, never id — comparing on `id` silently left stale data on screen.
  if (selectedResident.value) {
    const id = idOf(selectedResident.value)
    selectedResident.value = residents.value.find((r) => idOf(r) === id) || null
  }
  refreshPhotos()
  initialLoad.value = false
}

const fetchResidents = () => get('/residents', { onData: applyResidents })

// After a write: drop the cached list, then fetch past it.
// The pulse is refreshed after, so this page's own write is not offered back to it.
const reloadResidents = async () => {
  invalidate('/residents')
  await fetchResidents()
  refreshPulse()
}

// Accounts registered or changed elsewhere since the list loaded (see usePulse.ts).
const listUpdate = computed(() => pulseDiff(initialLoad.value ? undefined : pulse.value?.residents, residents.value))
const showNewAccounts = () => reloadResidents().catch((error) => { apiError.value = error.message })

// Photos load for the rows on screen only (v-intersect on the avatar), and the
// blobs outlive the page (residentPhoto.ts). Not awaited: the table is useful the
// moment the rows land, and an avatar that arrives a beat later is not worth
// blocking it for.
const ensurePhoto = (item) => {
  if (!item.has_photo) return

  const id = idOf(item)
  residentPhotoUrl(id, item.updated_at).then((url) => {
    if (url && photoUrls.value[id] !== url) photoUrls.value = { ...photoUrls.value, [id]: url }
  })
}

// A refetch can bring a newer updated_at: re-check only the avatars already drawn.
const refreshPhotos = () => {
  for (const resident of residents.value) {
    if (photoUrls.value[idOf(resident)]) ensurePhoto(resident)
  }
}

const fetchBarangays = () => get('/barangays', { ttl: REFERENCE_TTL_MS, onData: (data) => { barangays.value = data.data || data } })

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
  formData.value = {
    first_name: '', middle_name: '', last_name: '', phone_number: '',
    password: '', barangay_id: null, street_address: '',
    status: RESIDENT_STATUS.active, account_type: ACCOUNT_TYPE.head, organization_name: '',
  }
  modal.value = { isOpen: true, isEditing: false, targetId: null }
  // The dialog's inputs mount on this same tick with :rules attached, and
  // Vuetify validates a freshly mounted field against its initial value —
  // resetValidation() has to run after that mount, not before, or the blank
  // required fields show red the instant the dialog opens.
  nextTick(() => form.value?.resetValidation())
}

const openExistingEditModal = (item) => {
  modalError.value = ''
  photoError.value = ''
  clearFieldErrors()
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
  nextTick(() => form.value?.resetValidation())
}

const closeModal = () => { modal.value.isOpen = false }

// Same ceiling and types as the server (ResidentController::savePhoto); checked
// here so a wrong file costs no upload.
const PHOTO_MAX_BYTES = 4 * 1024 * 1024

const changePhoto = async (method, file) => {
  const id = modal.value.targetId
  photoBusy.value = true
  photoError.value = ''
  try {
    let body
    if (file) {
      body = new FormData()
      body.append('photo', file)
    }
    const res = await fetch(`${API_BASE}/residents/${id}/photo`, { method, headers: authHeaders(false), body })
    if (!res.ok) {
      const data = await res.json().catch(() => ({}))
      throw new Error(data.errors?.photo?.[0] || data.message || 'Could not update the photo.')
    }
    // Drop the cached image first, or the row and the profile keep showing the old one.
    forgetResidentPhoto(id)
    delete photoUrls.value[id]
    await reloadResidents()
    notify(file ? 'Photo saved' : 'Photo removed')
  } catch (error) {
    photoError.value = error.message
  } finally {
    photoBusy.value = false
  }
}

const onPhotoPicked = (event) => {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  if (!['image/jpeg', 'image/png'].includes(file.type)) {
    photoError.value = 'Choose a JPG or PNG image.'
    return
  }
  if (file.size > PHOTO_MAX_BYTES) {
    photoError.value = 'That image is over 4 MB.'
    return
  }
  changePhoto('POST', file)
}

const removePhoto = () => changePhoto('DELETE')

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
    await reloadResidents()
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

// The one status write, for the row toggle and the bulk loop alike. There is no
// status-only endpoint: this is the full PUT with the row's own values.
const putStatus = async (item, next) => {
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
    await putStatus(item, next)
    await reloadResidents()
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


// Bulk Activate / Deactivate: a loop over the single-account write. The list is
// small and the admin limiter is 300 a minute, so a bulk endpoint would buy
// nothing. Activate leaves Pending accounts alone: they need review in the
// profile first. Deactivate takes them.
const bulk = ref({ show: false, action: '', phase: 'confirm', eligible: [], pending: [], already: [], done: [], failed: [] })

const askBulk = (action) => {
  const rows = selectedRows.value
  const activating = action === 'activate'
  const inState = (r, status) => r.status === status
  bulk.value = {
    show: true,
    action,
    phase: 'confirm',
    eligible: rows.filter((r) => (activating ? inState(r, RESIDENT_STATUS.deactivated) : !inState(r, RESIDENT_STATUS.deactivated))),
    pending: activating ? rows.filter((r) => inState(r, RESIDENT_STATUS.pending)) : [],
    already: rows.filter((r) => inState(r, activating ? RESIDENT_STATUS.active : RESIDENT_STATUS.deactivated)),
    done: [],
    failed: [],
  }
}

const runBulk = async () => {
  const b = bulk.value
  const next = b.action === 'activate' ? RESIDENT_STATUS.active : RESIDENT_STATUS.deactivated
  b.phase = 'running'
  for (const item of b.eligible) {
    try {
      await putStatus(item, next)
      b.done.push(item)
    } catch (error) {
      b.failed.push({ item, message: error.message })
    }
  }
  try {
    await reloadResidents()
  } catch (error) {
    notify(error.message, 'error')
  }
  clearSelection()
  b.phase = 'done'
  const failed = b.failed.length > 0
  notify(failed ? `${b.done.length} done, ${b.failed.length} failed` : `${b.done.length} ${b.done.length === 1 ? 'account' : 'accounts'} updated`, failed ? 'error' : 'success')
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
    await reloadResidents()
    deleteDialog.value.show = false
    notify('Account deleted')
  } catch (error) {
    notify(error.message, 'error')
  } finally {
    deleteDialog.value.loading = false
  }
}

onMounted(loadAll)
</script>

<style scoped>
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }

/* Header and dialog buttons, from the board: 40px, 12px radius, 14px/700,
   primary shadow. */
.add-btn {
  padding: 0 18px;
  border-radius: 12px;
  font-size: 14px;
  letter-spacing: 0;
  box-shadow: 0 8px 16px -4px rgba(var(--v-theme-primary), 0.28);
}
.photo-btn {
  border-radius: 10px;
  border-color: rgba(var(--v-theme-on-surface), 0.14);
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-weight: 600;
}
.detail-card { box-shadow: 0 28px 64px rgba(2, 20, 16, 0.32) !important; }

/* Search 320px and the 10px field radius, per the toolbar board. */
.accounts-table.dtp-board :deep(.dtp-search) { flex: 0 0 320px; width: 320px; }
.accounts-table :deep(.dtp-toolbar .v-field) { border-radius: 10px; }

/* Below this floor the table scrolls sideways instead of crushing a column.
   Phones drop two columns and lose the floor, see DataTablePage's collapseMobile. */
.accounts-table :deep(.dtp-table table) { min-width: 880px; }
@media (max-width: 599px) {
  .accounts-table :deep(.dtp-table table) { min-width: 0; }
}
/* 12px gutters, not Vuetify's 16px: the widths above are tight enough that a
   full gutter would clip a last name beside its avatar. */
.accounts-table :deep(.dtp-table th),
.accounts-table :deep(.dtp-table td) {
  padding-left: 12px !important;
  padding-right: 12px !important;
}
/* Headers never wrap, and a title too long for its column ellipsizes instead of
   running under the sort arrow into the next header. */
.accounts-table :deep(.v-data-table-header__content) { min-width: 0; }
.accounts-table :deep(.v-data-table-header__content > span) {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cell-truncate {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.account-cell { display: flex; align-items: center; gap: 8px; min-width: 0; }

/* Board table: 24px at the card's edges, a 1px .08 rule under each row. */
.accounts-table :deep(.dtp-table th:first-child),
.accounts-table :deep(.dtp-table td:first-child) { padding-left: 24px !important; }
.accounts-table :deep(.dtp-table th:last-child),
.accounts-table :deep(.dtp-table td:last-child) { padding-right: 24px !important; }
.accounts-table :deep(.dtp-table tbody td) { border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08); }
.accounts-table.dtp-compact :deep(.dtp-table tbody tr:not(.selected-row):hover) { background: rgba(var(--v-theme-on-surface), 0.025); }
/* Name cell: the round mark sits 12px from the text, the number line is 16px. */
.accounts-table :deep(.person-cell > .v-avatar) { margin-right: 12px !important; }
.accounts-table :deep(.person-cell .text-caption) { line-height: 16px; }
/* Row actions: 40px square buttons, 10px radius, 18px icons. */
.accounts-table :deep(.row-actions__inline .v-btn) { width: 40px; height: 40px; border-radius: 10px; }
.accounts-table :deep(.row-actions__inline .v-icon) { font-size: 18px; }
/* The footer reads at 14px here, not the compact lists' 13px. */
.accounts-table.dtp-compact :deep(.dtp-footer),
.accounts-table.dtp-compact :deep(.dtp-footer .text-body-2),
.accounts-table.dtp-compact :deep(.dtp-footer .v-field__input),
.accounts-table.dtp-compact :deep(.dtp-footer .v-btn) { font-size: 0.875rem !important; }

/* Selection — the open row, and ticked rows. */
.accounts-table :deep(tr.selected-row) {
  background: rgba(var(--v-theme-primary), 0.1) !important;
  box-shadow: inset 3px 0 0 0 rgb(var(--v-theme-primary));
}
.accounts-table :deep(tr.is-checked) { background: rgba(var(--v-theme-primary), 0.06); }

.bulk-bar { background: rgba(var(--v-theme-primary), 0.08); }
.bulk-body ul { margin: 4px 0 0; padding-left: 20px; }

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
</style>
