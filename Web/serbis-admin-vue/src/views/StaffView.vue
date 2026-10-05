<template>
  <v-container fluid class="fill-height align-start bg-background">
    <v-row class="ma-0 w-100">
      <v-col cols="12" class="pa-0 w-100">

        <PageHeader title="Staff accounts">
          <template v-slot:subtitle>{{ pluralize(admins.length, 'account') }}</template>
          <template v-slot:actions>
            <v-btn color="primary" variant="flat" height="40" class="add-btn text-none font-weight-bold" @click="openAdd">
              <v-icon start size="16">mdi-plus</v-icon> Add staff account
            </v-btn>
          </template>
        </PageHeader>

        <v-alert
          v-if="apiError" type="error" variant="tonal" class="mb-6"
          density="compact" rounded="lg" role="alert"
        >
          {{ apiError }}
          <template #append>
            <v-btn variant="outlined" color="primary" size="small" class="text-none" @click="fetchAdmins">Try again</v-btn>
          </template>
        </v-alert>

        <!-- No mail is sent to a staff username, so a reset cannot be emailed.
             Saying so here is cheaper than an admin discovering it while a
             colleague is locked out. -->
        <NoticeBanner tone="info" class="mb-5">
          <p>
            Forgotten passwords are reset here, not by email. Choose “Reset password” from the
            account's More menu, then pass the temporary password on in person. They will be asked
            to set their own before they can use the panel.
          </p>
          <p>
            A new account starts with no access. Choose “Manage access” from its More menu to pick
            which sections it can open. Only a super admin sees this page or can change access.
          </p>
        </NoticeBanner>

        <!-- Two-step sign-in texts a code to this number (ADMIN_MFA_ENABLED).
             Listed by name so whoever turns it on knows who would be refused. -->
        <NoticeBanner v-if="missingPhone.length > 0" tone="warning" icon="mdi-cellphone-off" class="mb-5">
          <p>
            <span v-if="mfaEnabled">
              Two-step sign-in is on. These staff cannot sign in until a mobile number is added:
            </span>
            <span v-else>
              Two-step sign-in is off. Before it is turned on, add a mobile number for:
            </span>
            <strong>{{ missingPhone.map(fullName).join(', ') }}</strong>.
            Use “Edit” on each account.
          </p>
        </NoticeBanner>

        <!-- What a bulk action could not do, account by account. Those accounts stay selected. -->
        <v-alert
          v-if="bulkProblem" type="warning" variant="tonal" class="mb-4"
          density="compact" rounded="lg" closable role="alert" @click:close="bulkProblem = ''"
        >{{ bulkProblem }}</v-alert>

        <!-- The pager and page size only appear once the list outgrows one page. -->
        <DataTablePage
          v-model:page="page"
          compact
          filter-bar
          board-table
          class="staff-table"
          :row-height="64"
          :row-props="rowProps"
          :searchable="false"
          :loading="initialLoad"
          :headers="headers"
          :items="admins"
          item-value="admin_id"
          :items-per-page="PER_PAGE"
          :items-per-page-options="[PER_PAGE]"
          no-data-text="No staff accounts. This should be impossible while you are signed in."
        >
          <template v-slot:summary>{{ pluralize(admins.length, 'account') }}</template>

          <!-- Own checkboxes, as on Accounts, so your own row can be refused with a reason.
               Select-all covers the rows on this page. -->
          <template v-slot:header.select>
            <v-checkbox-btn
              density="compact"
              :model-value="pageAllSelected"
              :indeterminate="pageSomeSelected"
              :disabled="pageSelectable.length === 0"
              aria-label="Select all accounts on this page"
              @update:model-value="togglePage"
            ></v-checkbox-btn>
          </template>

          <!-- Column widths come from here, not the header row: the bulk bar replaces that
               row with two cells, and the fixed layout would otherwise re-split every column. -->
          <template v-slot:colgroup="{ columns }">
            <colgroup>
              <col v-for="column in columns" :key="column.key" :style="{ width: column.width }" />
            </colgroup>
          </template>

          <!-- While anything is ticked, the header row becomes the bulk bar, at the same height. -->
          <template v-if="selectedIds.length > 0" v-slot:headers="{ columns }">
            <tr class="bulk-row">
              <th class="bulk-check">
                <v-checkbox-btn
                  density="compact"
                  :model-value="pageAllSelected"
                  :indeterminate="!pageAllSelected"
                  aria-label="Select all accounts on this page"
                  @update:model-value="togglePage"
                ></v-checkbox-btn>
              </th>
              <th :colspan="columns.length - 1">
                <div class="bulk-bar" role="toolbar" aria-label="Actions for the selected accounts">
                  <span class="bulk-count" aria-live="polite">{{ selectedIds.length }} selected</span>
                  <div class="bulk-actions">
                    <v-btn variant="flat" height="32" class="row-btn text-none" :disabled="!!bulkBusy" @click="(e) => { rememberFocus(e); openBulkAccess() }">Change access</v-btn>
                    <v-btn
                      v-if="bulk.closed.length > 0" variant="flat" height="32" class="row-btn text-none"
                      :loading="bulkBusy === 'reactivate'" :disabled="!!bulkBusy" @click="bulkReactivate"
                    >Reactivate</v-btn>
                    <v-btn
                      v-if="bulk.open.length > 0" variant="flat" height="32" class="row-btn row-btn--danger text-none"
                      :disabled="!!bulkBusy" @click="(e) => { rememberFocus(e); askClose(bulk.open) }"
                    >Close accounts</v-btn>
                    <v-btn variant="text" height="32" class="text-none bulk-clear" :disabled="!!bulkBusy" @click="clearSelection">Clear</v-btn>
                  </div>
                </div>
              </th>
            </tr>
          </template>

          <template v-slot:item.select="{ item }">
            <v-tooltip v-if="!canSelect(item, myId)" text="You can't select your own account" location="top">
              <template v-slot:activator="{ props: tip }">
                <span v-bind="tip" class="select-cell" tabindex="0" aria-label="You can't select your own account">
                  <v-checkbox-btn density="compact" disabled :model-value="false" tabindex="-1" aria-hidden="true"></v-checkbox-btn>
                </span>
              </template>
            </v-tooltip>
            <span v-else class="select-cell">
              <v-checkbox-btn
                density="compact"
                :model-value="selectedSet.has(idOf(item))"
                :aria-label="`Select ${fullName(item)}`"
                @update:model-value="toggleRow(item)"
              ></v-checkbox-btn>
            </span>
          </template>

          <template #item.name="{ item }">
            <PersonCell :name="`${item.last_name}, ${item.first_name}`" :secondary="item.username" :initials="initials(item)" size="36" tinted>
              <template v-slot:badge><span v-if="isSelf(item)" class="you-tag">You</span></template>
            </PersonCell>
          </template>

          <template #item.phone="{ item }">
            <span v-if="item.phone_number" class="phone-cell">{{ localPhone(item.phone_number) }}</span>
            <StatusPill v-else status="Pending" label="No phone" />
          </template>

          <template #item.access="{ item }">
            <StatusPill v-bind="accessPill(item)" />
          </template>

          <template #item.status="{ item }">
            <StatusPill dot :status="isClosed(item) ? 'Denied' : 'Active'" :label="isClosed(item) ? 'Deactivated' : 'Active'" />
          </template>

          <!-- Edit, and the rest in a menu (rules in composables/staffActions.js). -->
          <template #item.actions="{ item }">
            <div class="row-buttons">
              <v-btn
                variant="flat" height="32" class="row-btn text-none"
                :aria-label="`Edit ${fullName(item)}`"
                @click="(e) => { rememberFocus(e); openEdit(item) }"
              >Edit</v-btn>
              <v-menu location="bottom end" offset="4">
                <template v-slot:activator="{ props: menu }">
                  <v-btn
                    v-bind="menu"
                    variant="flat" width="32" min-width="32" height="32" class="row-btn row-btn--more"
                    :aria-label="`More actions for ${fullName(item)}`"
                    :loading="busyId === idOf(item)"
                    @click="rememberFocus"
                  ><v-icon size="18" aria-hidden="true">mdi-dots-horizontal</v-icon></v-btn>
                </template>
                <v-list density="compact" min-width="232" class="row-menu" :aria-label="`Actions for ${fullName(item)}`">
                  <template v-for="entry in rowMenu(item, admins, myId)" :key="entry.key">
                    <v-divider v-if="entry.divider" class="my-1"></v-divider>
                    <v-list-item
                      v-else
                      :disabled="entry.disabled"
                      :class="{ 'row-menu__danger': entry.danger }"
                      @click="runRowAction(entry.key, item)"
                    >
                      <v-list-item-title>{{ entry.label }}</v-list-item-title>
                      <v-list-item-subtitle v-if="entry.hint" class="row-menu__hint">{{ entry.hint }}</v-list-item-subtitle>
                    </v-list-item>
                  </template>
                </v-list>
              </v-menu>
            </div>
          </template>
        </DataTablePage>

      </v-col>
    </v-row>

    <!-- Add / edit -->
    <EditDialog
      ref="formRef"
      v-model="modal.show"
      :title="modal.editing ? 'Edit staff account' : 'Add staff account'"
      :confirm-label="modal.editing ? 'Save' : 'Create account'"
      :width="520"
      :fields="staffFields"
      :form="form"
      :field-errors="fieldErrors"
      :error="modal.error"
      :loading="modal.loading"
      @save="save"
    >
      <!-- Changing a password ends that account's other sessions. Saying so
           before the click, because for the person being edited it looks
           like being logged out at random. -->
      <template v-slot:field-signout>
        <div class="notice subtle-surface">
          <v-icon size="16" class="mr-1 text-medium-emphasis" aria-hidden="true">mdi-logout-variant</v-icon>
          <span v-if="modal.editing && modal.targetId === myId">
            Your other devices will be signed out. This one stays signed in.
          </span>
          <span v-else>Signs this account out everywhere it is currently signed in.</span>
        </div>
      </template>
    </EditDialog>

    <!-- Access: which sections this account may open. A super admin sees every
         section whatever is ticked, so the list is disabled while the switch is
         on rather than left looking like it still decides something. -->
    <v-dialog :model-value="accessShown" max-width="640" persistent @update:model-value="(open) => (accessDialog.show = open)">
      <v-card rounded="xl" class="access-card">
        <div class="access-head">
          <h2 class="access-title">Access for {{ accessDialog.item ? fullName(accessDialog.item) : pluralize(accessDialog.ids.length, 'account') }}</h2>
          <v-btn icon="mdi-close" variant="flat" rounded="circle" class="access-close" aria-label="Close" @click="accessDialog.show = false"></v-btn>
        </div>

        <div class="access-body">
          <v-alert
            v-if="accessDialog.error" type="error" variant="tonal" density="compact"
            rounded="lg" role="alert"
          >{{ accessDialog.error }}</v-alert>
          <!-- Bulk, and the selected accounts do not all have the same access today. -->
          <v-alert v-if="accessDialog.mixed" type="info" variant="tonal" density="compact" rounded="lg">
            These accounts have different access now. Saving gives all of them the access chosen here.
          </v-alert>

          <div class="access-super">
            <button
              type="button" role="switch" class="access-switch" aria-label="Super admin"
              :aria-checked="accessDialog.superAdmin" :class="{ 'is-on': accessDialog.superAdmin }"
              @click="accessDialog.superAdmin = !accessDialog.superAdmin"
            ><span></span></button>
            <div>
              <div class="access-super__title">Super admin</div>
              <p class="access-super__text">
                Sees every section, is the only kind of account that can open this page, and decides
                everyone's access. The list below is ignored while this is on.
              </p>
            </div>
          </div>

          <div class="access-row">
            <h3 class="access-label">Sections this account can open</h3>
            <button type="button" class="access-clear" :disabled="accessDialog.superAdmin" @click="toggleAllSections">
              {{ allSectionsGranted ? 'Clear all' : 'Select all' }}
            </button>
          </div>

          <fieldset v-for="group in accessGroups" :key="group.label" class="access-group">
            <legend>{{ group.label }}</legend>
            <div class="access-grid">
              <label v-for="section in group.items" :key="section.key">
                <input v-model="accessDialog.granted" type="checkbox" :value="section.key" :disabled="accessDialog.superAdmin" />{{ section.title }}
              </label>
            </div>
          </fieldset>
        </div>

        <div class="access-foot">
          <v-btn variant="flat" height="40" class="dlg-btn dlg-cancel text-none" :disabled="accessDialog.loading" @click="accessDialog.show = false">Cancel</v-btn>
          <v-btn
            color="primary" variant="flat" height="40" class="dlg-btn dlg-confirm text-none" :class="{ 'is-busy': accessPhase !== 'idle' }"
            :disabled="accessPhase !== 'idle'" aria-live="polite" @click="saveAccess"
          >
            <template v-if="accessPhase === 'saving'"><span class="btn-spin" aria-hidden="true"></span>Saving</template>
            <template v-else-if="accessPhase === 'saved'"><v-icon size="16" aria-hidden="true">mdi-check</v-icon>Saved</template>
            <template v-else>Save access</template>
          </v-btn>
        </div>
      </v-card>
    </v-dialog>

    <!-- Close account, one or several. Closing only ever deactivates, so it can be undone. -->
    <v-dialog v-model="closeDialog.show" max-width="460">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis text-wrap">{{ closeTitle(closeDialog.accounts) }}</v-card-title>
        <v-card-text class="px-6 py-4 text-body-2 text-medium-emphasis">
          <!-- A refusal the page could not foresee (someone else changed the list meanwhile). -->
          <v-alert v-if="closeDialog.error" type="error" variant="tonal" density="compact" rounded="lg" role="alert" class="mb-3">{{ closeDialog.error }}</v-alert>
          They will no longer be able to sign in. You can reactivate the account later.
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="outlined" color="primary" rounded="lg" class="text-none" :disabled="closeDialog.loading" @click="closeDialog.show = false">
            Cancel
          </v-btn>
          <v-btn
            color="error" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold"
            :loading="closeDialog.loading" @click="confirmClose"
          >
            {{ closeLabel(closeDialog.accounts.length) }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Reset password: confirm first, because it signs the person out everywhere. -->
    <v-dialog v-model="resetDialog.show" max-width="460">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">Reset this password?</v-card-title>
        <v-card-text class="px-6 py-4 text-body-2 text-medium-emphasis">
          <strong class="text-high-emphasis">{{ fullName(resetDialog.item) }}</strong>
          will be signed out everywhere and given a temporary password. They must set
          their own the next time they sign in.
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="outlined" color="primary" rounded="lg" class="text-none" :disabled="resetDialog.loading" @click="resetDialog.show = false">
            Cancel
          </v-btn>
          <v-btn
            color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold"
            :loading="resetDialog.loading" @click="confirmReset"
          >
            Reset password
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- The temporary password, shown once. Persistent: it is not stored
         anywhere the panel can show it again, so an accidental click outside
         must not lose it. -->
    <v-dialog v-model="tempDialog.show" max-width="460" persistent>
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">Temporary password</v-card-title>
        <v-card-text class="px-6 py-4">
          <div class="text-body-2 text-medium-emphasis mb-3">
            Give this to <strong class="text-high-emphasis">{{ tempDialog.name }}</strong> in person.
            It is shown only once — closing this window discards it.
          </div>
          <div class="temp-password" data-testid="temporary-password">{{ tempDialog.password }}</div>
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="outlined" color="primary" rounded="lg" class="text-none" @click="copyTemporary">
            {{ tempDialog.copied ? 'Copied' : 'Copy' }}
          </v-btn>
          <v-btn
            color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold"
            @click="closeTemporary"
          >
            Done
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Announced, not just shown. The snackbar is mounted on show and is not
         reliably read out; this region is permanent and is blanked before each
         message so an unchanged string is still announced. -->
    <div class="visually-hidden" role="status" aria-live="polite">{{ liveMessage }}</div>
    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="bottom right" rounded="lg">
      {{ snackbar.text }}
    </v-snackbar>
  </v-container>
</template>

<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import { initials as computeInitials, pluralize } from '@/composables/adminUi'
import { ASSIGNABLE_SECTIONS, SECTION_GROUPS } from '@/composables/adminSections'
import { getToken } from '@/composables/authToken'
import { useSaveFeedback } from '@/composables/useSaveFeedback'
import {
  idOf, isClosed, fullName, isSelf as isSelfOf, rowMenu, canSelect, bulkSplit, sharedAccess, closeTitle, closeLabel, failureSummary,
} from '@/composables/staffActions'
import { loadCurrentAdmin, adminId as myId } from '@/composables/useCurrentAdmin'
import { API_BASE } from '@/config/api'
import PageHeader from '@/components/PageHeader.vue'
import DataTablePage from '@/components/DataTablePage.vue'
import PersonCell from '@/components/PersonCell.vue'
import StatusPill from '@/components/StatusPill.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import EditDialog from '@/components/EditDialog.vue'

const API = `${API_BASE}/admins`

const admins = ref([])
const initialLoad = ref(true)
const apiError = ref('')
const busyId = ref(null)
const liveMessage = ref('')

const modal = ref({ show: false, editing: false, loading: false, error: '', targetId: null })
const form = ref({ first_name: '', last_name: '', username: '', phone_number: '', password: '', password_confirmation: '' })
const mfaEnabled = ref(false)
// One account or several; `accounts` is who the confirm is about.
const closeDialog = ref({ show: false, accounts: [], loading: false, error: '' })
const resetDialog = ref({ show: false, item: null, loading: false })
const tempDialog = ref({ show: false, name: '', password: '', copied: false })
const snackbar = ref({ show: false, text: '', color: 'success' })

// Template ref for the Add/Edit <v-form> -- named formRef, not form, because
// `form` above is already the reactive object the fields are bound to.
const formRef = ref(null)

const requiredRule = (label) => (v) =>
  (v !== null && v !== undefined && String(v).trim() !== '') || `${label} is required.`

// A password is required only when creating an account; editing may leave
// both password fields blank to keep the current one.
const passwordRequiredRule = (v) =>
  modal.value.editing || (v && String(v).trim() !== '') || 'A password is required for a new account.'

// Same rule the server enforces (User::USERNAME_REGEX).
const usernameRule = (v) =>
  /^[a-z0-9._]{3,30}$/.test(String(v || '')) || 'Use 3 to 30 lowercase letters, digits, dots or underscores.'

// Lowercases and drops anything a username cannot hold as it is typed. A pasted
// email loses everything from the @ on.
const cleanUsername = (v) => String(v || '').toLowerCase().replace(/@.*$/, '').replace(/[^a-z0-9._]/g, '')

// Same shapes the server accepts (PhoneNumber::REGEX). Required on a new
// account; on an edit, blank keeps whatever is stored.
const phoneRule = (v) => {
  const value = String(v || '').trim()
  if (value === '') return modal.value.editing || 'Mobile number is required.'
  return /^(?:09\d{9}|639\d{9}|\+639\d{9})$/.test(value) || 'Use a mobile number like 09171234567.'
}

// Stored as +639…; staff read and type 09….
const localPhone = (p) => String(p || '').replace(/^\+63/, '0')

const passwordConfirmRule = (v) =>
  String(v || '') === String(form.value.password || '') || 'The two passwords do not match.'

// Server-side errors, keyed by field, so a 422 lands on the input it belongs
// to instead of being concatenated into the banner above the form.
const fieldErrors = ref({})
const clearFieldErrors = () => { fieldErrors.value = {} }

const headers = [
  { title: 'Select', key: 'select', sortable: false, width: '52px', headerProps: { 'aria-label': 'Select' } },
  { title: 'Name', key: 'name', sortable: false, width: '28%' },
  { title: 'Mobile', key: 'phone', sortable: false },
  { title: 'Access', key: 'access', sortable: false },
  { title: 'Status', key: 'status', sortable: false },
  // Sticky at the right edge, so it stays usable when a narrow window scrolls the table.
  { title: 'Actions', key: 'actions', sortable: false, align: 'end', width: '132px', headerProps: { class: 'col-actions' }, cellProps: { class: 'col-actions' } },
]

// The Access column's pill. NULL is the value every account that existed before
// permissions did keeps: unrestricted, which is not the same as an empty list
// and must not read as one. Partial access is outlined so it stands out.
const accessPill = (item) => {
  if (item?.is_super_admin) return { status: 'Approved', label: 'Super admin' }
  if (item?.permissions === null || item?.permissions === undefined) return { tag: true, label: 'All sections' }
  if (item.permissions.length === 0) return { status: 'Pending', label: 'No sections' }
  return { outline: true, class: 'access-partial', label: `${item.permissions.length} of ${ASSIGNABLE_SECTIONS.length} sections` }
}

// The checkboxes follow the sidebar, so choosing what an account can open reads
// like the menu it will get.
const accessGroups = SECTION_GROUPS
  .map((g) => ({ label: g.label, items: ASSIGNABLE_SECTIONS.filter((s) => s.group === g.key) }))
  .filter((g) => g.items.length > 0)

// `item` for one account; `ids` (and no item) when changing several at once.
const accessDialog = ref({ show: false, item: null, ids: [], mixed: false, loading: false, error: '', superAdmin: false, granted: [] })
// Spinner / "Saved" on Save access, as in EditDialog.
const { shown: accessShown, phase: accessPhase } = useSaveFeedback(() => accessDialog.value.show, () => accessDialog.value.loading, () => accessDialog.value.error)

const allSectionsGranted = computed(() => accessDialog.value.granted.length === ASSIGNABLE_SECTIONS.length)

const getHeaders = () => ({
  Authorization: `Bearer ${getToken()}`,
  'Content-Type': 'application/json',
  Accept: 'application/json',
})

const isSelf = (item) => isSelfOf(item, myId.value)
const initials = (item) => computeInitials(item)

// Selection, for the bulk bar. Your own row is never selectable; select-all
// covers the selectable rows on the page being shown.
const PER_PAGE = 10
const page = ref(1)
const selectedIds = ref([])
const selectedSet = computed(() => new Set(selectedIds.value))
const pageSelectable = computed(() => admins.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE).filter((a) => canSelect(a, myId.value)))
const pageAllSelected = computed(() => pageSelectable.value.length > 0 && pageSelectable.value.every((a) => selectedSet.value.has(idOf(a))))
const pageSomeSelected = computed(() => !pageAllSelected.value && pageSelectable.value.some((a) => selectedSet.value.has(idOf(a))))
const bulk = computed(() => bulkSplit(selectedIds.value, admins.value))
const bulkBusy = ref('')
const bulkProblem = ref('')

const toggleRow = (item) => {
  const id = idOf(item)
  selectedIds.value = selectedSet.value.has(id) ? selectedIds.value.filter((x) => x !== id) : [...selectedIds.value, id]
}
const togglePage = (on) => {
  const ids = pageSelectable.value.map((a) => idOf(a))
  selectedIds.value = on
    ? [...new Set([...selectedIds.value, ...ids])]
    : selectedIds.value.filter((id) => !ids.includes(id))
}
const clearSelection = () => { selectedIds.value = [] }
// After a bulk action: drop what it did, keep what it could not.
const deselect = (ids) => { selectedIds.value = selectedIds.value.filter((id) => !ids.includes(id)) }

const rowProps = ({ item }) => ({ class: { 'is-picked': selectedSet.value.has(idOf(item)), 'is-closed': isClosed(item) } })

// Focus goes back to the button that opened a menu or dialog once it closes:
// the dialogs here have no activator to do it for them.
let focusBack = null
const rememberFocus = (event) => { focusBack = event?.currentTarget ?? null }
const returnFocus = () => {
  const target = focusBack
  // After the dialog's leave transition, which otherwise takes focus to <body>.
  setTimeout(() => { if (target?.isConnected) target.focus() }, 320)
}
const anyDialogOpen = computed(() => modal.value.show || accessDialog.value.show || closeDialog.value.show || resetDialog.value.show || tempDialog.value.show)
watch(anyDialogOpen, (open, wasOpen) => { if (wasOpen && !open) returnFocus() })

const runRowAction = (key, item) => {
  if (key === 'access') openAccess(item)
  else if (key === 'reset') askReset(item)
  else if (key === 'close') askClose([item])
  else if (key === 'reactivate') reactivate(item)
}

// Open accounts only: a closed one cannot sign in either way.
const missingPhone = computed(() => admins.value.filter((a) => !a.phone_number && !isClosed(a)))

const willChangePassword = computed(() => !!form.value.password || !!form.value.password_confirmation)

// The Add / Edit dialog's fields (labels and layout from the Edit/Add staff boards).
// Staff sign in with the username; no email is collected. The mobile number is
// where the sign-in code is texted when two-step sign-in is on.
const staffFields = computed(() => {
  const editing = modal.value.editing
  return [
    { key: 'first_name', label: 'First name', required: true, half: true, placeholder: 'Juan', rules: [requiredRule('First name')], autocomplete: 'given-name' },
    { key: 'last_name', label: 'Last name', required: true, half: true, placeholder: 'Dela Cruz', rules: [requiredRule('Last name')], autocomplete: 'family-name' },
    {
      key: 'username', label: 'Username', required: true, placeholder: 'juan.delacruz', autocomplete: 'off', sanitize: cleanUsername,
      hint: '3 to 30 lowercase letters, digits, dots or underscores.', rules: [requiredRule('Username'), usernameRule],
    },
    {
      key: 'phone_number', label: 'Mobile number', required: !editing, type: 'tel', inputmode: 'tel', placeholder: '09171234567', autocomplete: 'off',
      hint: 'Sign-in codes are texted here.', rules: [phoneRule],
    },
    { key: 'password-note', text: editing ? 'Leave both password fields blank to keep the current password.' : 'At least 8 characters, with upper and lower case and a number.' },
    { key: 'password', label: editing ? 'New password' : 'Password', required: !editing, half: true, type: 'password', autocomplete: 'new-password', rules: [passwordRequiredRule] },
    { key: 'password_confirmation', label: editing ? 'Confirm new password' : 'Confirm password', required: !editing, half: true, type: 'password', autocomplete: 'new-password', rules: [passwordConfirmRule] },
    ...(willChangePassword.value ? [{ key: 'signout', slot: true }] : []),
  ]
})

const announce = async (text, color = 'success') => {
  snackbar.value = { show: true, text, color }
  liveMessage.value = ''
  await nextTick()
  liveMessage.value = text
}

/** Pulls the readable part out of a Laravel error body, validation included. */
const messageFrom = (data, fallback) => {
  if (data?.errors) return Object.values(data.errors).flat().join(' ')
  return data?.message || fallback
}

// Laravel answers `{errors: {field: [msg]}}`. Split it: known fields go to
// their input, anything unrecognised stays in the banner so nothing is
// silently swallowed. Mirrors VehiclesView's applyServerErrors.
const applyServerErrors = (data) => {
  if (data?.errors && typeof data.errors === 'object') {
    const mapped = {}
    const leftovers = []
    for (const [key, messages] of Object.entries(data.errors)) {
      const text = Array.isArray(messages) ? messages.join(' ') : String(messages)
      if (key in form.value) mapped[key] = text
      else leftovers.push(text)
    }
    fieldErrors.value = mapped
    return leftovers.length > 0 ? leftovers.join(' ') : 'Please correct the highlighted fields.'
  }
  return data?.message || 'Save failed'
}

const fetchAdmins = async () => {
  apiError.value = ''
  try {
    const res = await fetch(API, { headers: getHeaders() })
    const data = await res.json().catch(() => ({}))
    // Checked before assigning: an unchecked body assigned straight to the list
    // renders a 401 as an empty table, which is the bug the borrowing board had.
    if (!res.ok) throw new Error(messageFrom(data, 'Failed to load staff accounts'))
    const rows = data.data || data
    admins.value = Array.isArray(rows) ? rows : []
    mfaEnabled.value = !!data.admin_mfa_enabled
    // A selected account that is gone, or has become yours, is no longer selected.
    const selectable = new Set(admins.value.filter((a) => canSelect(a, myId.value)).map((a) => idOf(a)))
    selectedIds.value = selectedIds.value.filter((id) => selectable.has(id))
  } catch (error) {
    apiError.value = error.message
  } finally {
    initialLoad.value = false
  }
}

const openAdd = () => {
  form.value = { first_name: '', last_name: '', username: '', phone_number: '', password: '', password_confirmation: '' }
  modal.value = { show: true, editing: false, loading: false, error: '', targetId: null }
  clearFieldErrors()
  formRef.value?.resetValidation()
}

const openEdit = (item) => {
  form.value = {
    first_name: item.first_name,
    last_name: item.last_name,
    username: item.username ?? '',
    phone_number: localPhone(item.phone_number),
    password: '',
    password_confirmation: '',
  }
  modal.value = { show: true, editing: true, loading: false, error: '', targetId: idOf(item) }
  clearFieldErrors()
  formRef.value?.resetValidation()
}

const save = async () => {
  const editing = modal.value.editing
  modal.value.error = ''
  clearFieldErrors()

  // Validate before spending a round trip. Vuetify focuses the first invalid
  // field itself once the rules are attached.
  const { valid } = await formRef.value.validate()
  if (!valid) {
    modal.value.error = 'Please correct the highlighted fields.'
    return
  }

  const payload = {
    first_name: form.value.first_name.trim(),
    last_name: form.value.last_name.trim(),
    username: form.value.username.trim(),
  }
  // Omitted when blank, so an edit leaves the stored number alone.
  if (form.value.phone_number.trim()) payload.phone_number = form.value.phone_number.trim()
  if (form.value.password) {
    payload.password = form.value.password
    payload.password_confirmation = form.value.password_confirmation
  }

  modal.value.loading = true
  try {
    const res = await fetch(editing ? `${API}/${modal.value.targetId}` : API, {
      method: editing ? 'PUT' : 'POST',
      headers: getHeaders(),
      body: JSON.stringify(payload),
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(applyServerErrors(data))

    await fetchAdmins()
    modal.value.show = false
    announce(editing ? `${payload.first_name} ${payload.last_name} updated` : `${payload.first_name} ${payload.last_name} can now sign in`)
  } catch (error) {
    // Left on the dialog, which stays open: a duplicate username is fixed in the
    // field the message is about.
    modal.value.error = error.message
  } finally {
    modal.value.loading = false
  }
}

const openAccess = (item) => {
  // An unrestricted account (NULL) starts with everything ticked: that is what
  // it can open today, and saving turns it into an explicit list.
  const current = item.permissions === null || item.permissions === undefined
    ? ASSIGNABLE_SECTIONS.map((s) => s.key)
    : [...item.permissions]

  accessDialog.value = {
    show: true,
    item,
    ids: [],
    mixed: false,
    loading: false,
    error: '',
    superAdmin: !!item.is_super_admin,
    granted: current,
  }
}

// The same dialog for every selected account. It starts from their access when
// they all share it, and from nothing (with a note) when they do not.
const openBulkAccess = () => {
  const shared = sharedAccess(bulk.value.picked)
  let granted = []
  if (shared) {
    granted = shared.permissions === null || shared.permissions === undefined
      ? ASSIGNABLE_SECTIONS.map((s) => s.key)
      : [...shared.permissions]
  }

  accessDialog.value = {
    show: true,
    item: null,
    ids: bulk.value.picked.map((a) => idOf(a)),
    mixed: !shared,
    loading: false,
    error: '',
    superAdmin: !!shared?.is_super_admin,
    granted,
  }
}

const toggleAllSections = () => {
  accessDialog.value.granted = allSectionsGranted.value ? [] : ASSIGNABLE_SECTIONS.map((s) => s.key)
}

const saveAccess = async () => {
  const item = accessDialog.value.item
  accessDialog.value.error = ''
  accessDialog.value.loading = true

  if (!item) {
    await saveBulkAccess()
    return
  }

  try {
    const res = await fetch(`${API}/${idOf(item)}/permissions`, {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify({
        is_super_admin: accessDialog.value.superAdmin,
        permissions: accessDialog.value.granted,
      }),
    })
    const data = await res.json().catch(() => ({}))
    // Left on the dialog, which stays open: "the only active super admin" is
    // fixed by choosing someone else, not by dismissing the message.
    if (!res.ok) throw new Error(messageFrom(data, 'Could not save access'))

    await fetchAdmins()
    // Changing your own access changes your own menu.
    if (isSelf(item)) await loadCurrentAdmin(true)

    accessDialog.value.show = false
    announce(`Access saved for ${fullName(item)}`)
  } catch (error) {
    accessDialog.value.error = error.message
  } finally {
    accessDialog.value.loading = false
  }
}

/**
 * One request for every selected account (see AdminController::eachAdmin):
 * `done` ids are deselected, `failed` ones stay selected and are listed by name
 * with the server's reason.
 */
const runBulk = async (method, path, ids, body, verb) => {
  const res = await fetch(`${API}/bulk/${path}`, { method, headers: getHeaders(), body: JSON.stringify({ ids, ...body }) })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(messageFrom(data, `Could not ${verb} the accounts`))

  const done = data.done ?? []
  const failed = data.failed ?? []
  const before = admins.value
  await fetchAdmins()
  deselect(done)
  bulkProblem.value = failureSummary(failed, verb, before)

  return { done, failed }
}

const saveBulkAccess = async () => {
  try {
    const { done, failed } = await runBulk('PUT', 'permissions', accessDialog.value.ids, {
      is_super_admin: accessDialog.value.superAdmin,
      permissions: accessDialog.value.granted,
    }, 'change access for')
    // Every one refused: keep the dialog, with the reasons, rather than closing on nothing done.
    if (done.length === 0 && failed.length > 0) {
      accessDialog.value.error = bulkProblem.value
      bulkProblem.value = ''
      return
    }
    accessDialog.value.show = false
    announce(`Access saved for ${pluralize(done.length, 'account')}`)
  } catch (error) {
    accessDialog.value.error = error.message
  } finally {
    accessDialog.value.loading = false
  }
}

const askClose = (accounts) => { closeDialog.value = { show: true, accounts: [...accounts], loading: false, error: '' } }

const confirmClose = async () => {
  const accounts = closeDialog.value.accounts
  closeDialog.value.loading = true
  closeDialog.value.error = ''

  try {
    if (accounts.length === 1) {
      const res = await fetch(`${API}/${idOf(accounts[0])}`, { method: 'DELETE', headers: getHeaders() })
      const data = await res.json().catch(() => ({}))
      // A refusal stays in the dialog: the page could not have known (someone else changed the list).
      if (!res.ok) {
        closeDialog.value.error = messageFrom(data, 'Could not close the account')
        return
      }
      await fetchAdmins()
      deselect([idOf(accounts[0])])
      closeDialog.value.show = false
      announce(`${fullName(accounts[0])} can no longer sign in`)
      return
    }

    const { done } = await runBulk('POST', 'close', accounts.map((a) => idOf(a)), {}, 'close')
    closeDialog.value.show = false
    if (done.length > 0) announce(`${pluralize(done.length, 'account')} closed`)
  } catch (error) {
    closeDialog.value.error = error.message
  } finally {
    closeDialog.value.loading = false
  }
}

const bulkReactivate = async () => {
  bulkBusy.value = 'reactivate'
  try {
    const { done } = await runBulk('POST', 'reactivate', bulk.value.closed.map((a) => idOf(a)), {}, 'reactivate')
    if (done.length > 0) announce(`${pluralize(done.length, 'account')} can sign in again`)
  } catch (error) {
    announce(error.message, 'error')
  } finally {
    bulkBusy.value = ''
  }
}

const askReset = (item) => { resetDialog.value = { show: true, item, loading: false } }

const confirmReset = async () => {
  const item = resetDialog.value.item
  resetDialog.value.loading = true
  try {
    const res = await fetch(`${API}/${idOf(item)}/reset-password`, { method: 'POST', headers: getHeaders() })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(messageFrom(data, 'Could not reset the password'))

    resetDialog.value.show = false
    // Held only in this dialog's state. Nothing else keeps it.
    tempDialog.value = { show: true, name: fullName(item), password: data.temporary_password, copied: false }
  } catch (error) {
    resetDialog.value.show = false
    announce(error.message, 'error')
  } finally {
    resetDialog.value.loading = false
  }
}

const copyTemporary = async () => {
  try {
    await navigator.clipboard.writeText(tempDialog.value.password)
    tempDialog.value.copied = true
  } catch {
    // Clipboard blocked (insecure origin, permissions). The password is on
    // screen to read out or type, which is the fallback that always works.
    announce('Could not copy. Read the password from the window instead.', 'warning')
  }
}

const closeTemporary = () => {
  tempDialog.value = { show: false, name: '', password: '', copied: false }
}

const reactivate = async (item) => {
  busyId.value = idOf(item)
  try {
    const res = await fetch(`${API}/${idOf(item)}/reactivate`, { method: 'PATCH', headers: getHeaders() })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(messageFrom(data, 'Could not reactivate the account'))

    await fetchAdmins()
    announce(`${fullName(item)} can sign in again`)
  } catch (error) {
    announce(error.message, 'error')
  } finally {
    busyId.value = null
    // No dialog to close: straight back to the row's More button.
    returnFocus()
  }
}

onMounted(() => {
  // Which row is the signed-in admin: read once per session, not refetched here.
  loadCurrentAdmin()
  fetchAdmins()
})
</script>

<style scoped>
.gap-1 { gap: 4px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
.min-w-0 { min-width: 0; }

.add-btn {
  padding: 0 18px;
  border-radius: 12px;
  font-size: 14px;
  letter-spacing: 0;
  box-shadow: 0 8px 16px -4px rgba(var(--v-theme-primary), 0.28);
}

/* "You" tag after the signed-in account's name. */
.you-tag {
  margin-left: 8px;
  padding: 1px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  background: rgba(var(--v-theme-primary), 0.14);
  color: rgb(var(--v-theme-primary-strong));
}
.staff-table :deep(.person-cell > .v-avatar) { margin-right: 12px !important; }
.staff-table :deep(tbody tr) { cursor: default; }

/* Text buttons in the Actions column: 32px, 10px radius, 13px/700. */
.row-buttons { display: flex; justify-content: flex-end; flex-wrap: nowrap; gap: 8px; }
.row-btn {
  padding: 0 12px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.14);
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-primary-strong));
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0;
  white-space: nowrap;
}
.row-btn--danger { border-color: rgba(var(--v-theme-error), 0.5); color: rgb(var(--v-theme-error-strong)); }
.row-btn.v-btn--disabled { opacity: 0.45 !important; }

/* Access dialog, from the StaffAccess board. The body scrolls inside the card. */
.access-card {
  display: flex;
  flex-direction: column;
  max-height: min(840px, calc(100vh - 48px));
  box-shadow: 0 28px 64px rgba(2, 20, 16, 0.32) !important;
  overflow: hidden;
}
.access-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 24px 32px 12px; }
.access-title { margin: 0; font-size: 18.72px; line-height: 28px; font-weight: 600; }
.access-close { width: 36px; height: 36px; background: rgba(var(--v-theme-on-surface), 0.06); color: rgb(var(--v-theme-on-surface)); }
.access-body { flex: 1; overflow: auto; padding: 8px 32px 20px; display: flex; flex-direction: column; gap: 20px; }
.access-super { display: flex; gap: 14px; align-items: flex-start; padding: 14px 16px; border-radius: 12px; background: rgba(var(--v-theme-on-surface), 0.035); }
.access-switch {
  flex: none;
  position: relative;
  width: 44px;
  height: 24px;
  margin-top: 1px;
  border: 0;
  border-radius: 999px;
  background: #94a3b8;
  cursor: pointer;
}
.access-switch span {
  position: absolute;
  top: 3px;
  left: 3px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
  transition: transform var(--motion-fast) var(--ease-out);
}
.access-switch.is-on { background: rgb(var(--v-theme-primary)); }
.access-switch.is-on span { transform: translateX(20px); }
.access-super__title { font-size: 14px; line-height: 20px; font-weight: 700; }
.access-super__text { margin: 2px 0 0; font-size: 14px; line-height: 20px; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.access-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.access-label {
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.access-clear {
  height: 32px;
  padding: 0 12px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.14);
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-primary-strong));
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
}
.access-clear:disabled { opacity: 0.45; cursor: default; }
.access-group { margin: 0; padding: 0; border: 0; min-width: 0; }
.access-group legend { padding: 0; margin-bottom: 8px; font-size: 13px; line-height: 18px; font-weight: 700; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.access-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; }
.access-grid label { display: flex; align-items: center; gap: 10px; min-height: 36px; font-size: 14px; cursor: pointer; }
.access-grid input { width: 18px; height: 18px; margin: 0; accent-color: rgb(var(--v-theme-primary)); }
.access-foot { display: flex; justify-content: flex-end; gap: 12px; padding: 16px 32px; border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08); }
.dlg-btn { border-radius: 12px; font-size: 14px; font-weight: 700; letter-spacing: 0; }
.dlg-cancel {
  padding: 0 16px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.14);
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-primary-strong));
}
.dlg-confirm { padding: 0 20px; box-shadow: 0 8px 16px -4px rgba(var(--v-theme-primary), 0.28); }
.dlg-confirm.is-busy { display: inline-flex; gap: 8px; min-width: 104px; opacity: 1 !important; background: rgb(var(--v-theme-primary)) !important; color: #fff !important; }
@media (max-width: 599px) {
  .access-head, .access-body, .access-foot { padding-left: 20px; padding-right: 20px; }
  .access-grid { grid-template-columns: 1fr; }
}

.notice {
  display: flex;
  align-items: flex-start;
  padding: 10px 12px;
  border-radius: 10px;
  font-size: 12px;
  color: rgba(var(--v-theme-on-surface), 0.8);
}

.subtle-surface { background: rgba(var(--v-theme-on-surface), 0.04); }

/* Monospace and spaced so a password read aloud is not misheard. */
.temp-password {
  padding: 14px 16px;
  border-radius: 10px;
  text-align: center;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 1.5rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  user-select: all;
  background: rgba(var(--v-theme-on-surface), 0.06);
}

/* Read by a screen reader, invisible to everything else. clip-path rather than
   display:none, which removes it from the accessibility tree as well. */
.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  margin: -1px;
  padding: 0;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
  border: 0;
}
/* Fixed layout keeps the columns stable whatever the names are; the board's
   880px floor, below which the card scrolls. */
.staff-table :deep(.dtp-table table) { min-width: 880px; }

/* Selection. Tints are the primary token at low strength, so they follow the theme. */
.select-cell { display: inline-flex; border-radius: 8px; }
.select-cell:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 2px; }
.staff-table :deep(tbody tr.is-picked > td) { background: rgba(var(--v-theme-primary), 0.07); }
.staff-table :deep(.bulk-row th) {
  background: rgba(var(--v-theme-primary), 0.13);
  text-transform: none;
  letter-spacing: 0;
  font-size: 0.875rem !important;
}
.bulk-bar { display: flex; align-items: center; gap: 20px; }
.bulk-count { font-weight: 700; color: rgb(var(--v-theme-on-surface)); }
.bulk-actions { display: flex; align-items: center; gap: 8px; }
.bulk-clear { font-size: 13px; font-weight: 700; letter-spacing: 0; color: rgb(var(--v-theme-primary-strong)); }

/* A closed account reads as out of use: muted name, avatar and number. */
.staff-table :deep(tr.is-closed .person-cell .font-weight-bold),
.staff-table :deep(tr.is-closed .phone-cell) { color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.staff-table :deep(tr.is-closed .person-cell .v-avatar) { filter: grayscale(1); opacity: 0.7; }

/* Partial access is outlined on the surface colour, so limited access stands out from "All sections". */
.access-partial { background: rgb(var(--v-theme-surface)) !important; }

/* The More button and its menu. */
.row-btn--more { padding: 0; }
.row-menu__danger { color: rgb(var(--v-theme-error-strong)); }
/* A disabled item keeps its hint readable: only the label is dimmed. */
.row-menu .v-list-item--disabled { opacity: 1; }
.row-menu .v-list-item--disabled :deep(.v-list-item-title) { opacity: var(--v-disabled-opacity); }
.row-menu__hint { white-space: normal; font-size: 12px; line-height: 16px; opacity: 1 !important; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }

/* Actions stay in view when the table scrolls sideways; the cell carries the row's tint itself. */
.staff-table :deep(.col-actions) { position: sticky; right: 0; z-index: 1; background: rgb(var(--v-theme-surface)); }
.staff-table :deep(tbody tr.is-picked > td.col-actions) {
  background: linear-gradient(rgba(var(--v-theme-primary), 0.07), rgba(var(--v-theme-primary), 0.07)), rgb(var(--v-theme-surface));
}
@media (max-width: 1100px) {
  .staff-table :deep(.col-actions) { box-shadow: -8px 0 8px -8px rgba(0, 0, 0, 0.18); }
}
.row-btn:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 2px; }
</style>
