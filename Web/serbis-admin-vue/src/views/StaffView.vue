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
            Forgotten passwords are reset here, not by email. Choose “Reset password” on the
            account, then pass the temporary password on in person. They will be asked to set
            their own before they can use the panel.
          </p>
          <p>
            A new account starts with no access. Choose “Access” on it to pick which sections it
            can open. Only a super admin sees this page or can change access.
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

        <!-- The pager and page size only appear once the list outgrows one page. -->
        <DataTablePage
          compact
          filter-bar
          board-table
          class="staff-table"
          :row-height="56"
          :searchable="false"
          :loading="initialLoad"
          :headers="headers"
          :items="admins"
          item-value="admin_id"
          :items-per-page="10"
          :items-per-page-options="[10]"
          no-data-text="No staff accounts. This should be impossible while you are signed in."
        >
          <template v-slot:summary>{{ pluralize(admins.length, 'account') }}</template>

          <template v-slot:item.rowNumber="{ item }">
            <span class="row-number">{{ rowNumber(item) }}</span>
          </template>

          <template #item.name="{ item }">
            <PersonCell :name="`${item.last_name}, ${item.first_name}`" :secondary="item.username" :initials="initials(item)" size="36" tinted>
              <template v-slot:badge><span v-if="isSelf(item)" class="you-tag">You</span></template>
            </PersonCell>
          </template>

          <template #item.phone="{ item }">
            <span v-if="item.phone_number">{{ localPhone(item.phone_number) }}</span>
            <StatusPill v-else status="Pending" label="No phone" />
          </template>

          <template #item.access="{ item }">
            <StatusPill :status="accessSummary(item).status" :label="accessSummary(item).text" />
          </template>

          <template #item.status="{ item }">
            <StatusPill :status="isClosed(item) ? 'Denied' : 'Active'" :label="isClosed(item) ? 'Deactivated' : 'Active'" />
          </template>

          <template #item.actions="{ item }">
            <div class="row-buttons">
              <v-btn
                variant="flat" height="32" class="row-btn text-none"
                :aria-label="`Edit ${fullName(item)}`"
                @click="openEdit(item)"
              >Edit</v-btn>
              <v-btn
                variant="flat" height="32" class="row-btn text-none"
                :aria-label="`Choose which sections ${fullName(item)} can open`"
                @click="openAccess(item)"
              >Access</v-btn>
              <!-- Never on your own row, and never on a closed account: the
                   server refuses both, this only spares the round trip. -->
              <v-btn
                v-if="!isClosed(item) && !isSelf(item)"
                variant="flat" height="32" class="row-btn text-none"
                :aria-label="`Reset the password of ${fullName(item)}`"
                @click="askReset(item)"
              >Reset password</v-btn>
              <v-btn
                v-if="isClosed(item)"
                variant="flat" height="32" class="row-btn text-none"
                :aria-label="`Reactivate ${fullName(item)}`"
                :loading="busyId === idOf(item)"
                @click="reactivate(item)"
              >Reactivate</v-btn>
              <v-btn
                v-else
                variant="flat" height="32" class="row-btn row-btn--danger text-none"
                :aria-label="`Close the account of ${fullName(item)}`"
                :disabled="isSelf(item)"
                @click="askClose(item)"
              >Close account</v-btn>
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
          <h2 class="access-title">Access for {{ fullName(accessDialog.item) }}</h2>
          <v-btn icon="mdi-close" variant="flat" rounded="circle" class="access-close" aria-label="Close" @click="accessDialog.show = false"></v-btn>
        </div>

        <div class="access-body">
          <v-alert
            v-if="accessDialog.error" type="error" variant="tonal" density="compact"
            rounded="lg" role="alert"
          >{{ accessDialog.error }}</v-alert>

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

    <!-- Close account -->
    <v-dialog v-model="closeDialog.show" max-width="460">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">Close this account?</v-card-title>
        <v-card-text class="px-6 py-4 text-body-2 text-medium-emphasis">
          <strong class="text-high-emphasis">{{ fullName(closeDialog.item) }}</strong>
          will be signed out and will not be able to sign in again.
          <div class="mt-3">
            Their name stays on everything they have already done — an account with
            activity recorded against it is deactivated, not deleted, so the log
            keeps making sense. It can be reactivated later.
          </div>
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="outlined" color="primary" rounded="lg" class="text-none" :disabled="closeDialog.loading" @click="closeDialog.show = false">
            Cancel
          </v-btn>
          <v-btn
            color="error" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold"
            :loading="closeDialog.loading" @click="confirmClose"
          >
            Close account
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
import { ref, computed, onMounted, nextTick } from 'vue'
import { initials as computeInitials, pluralize } from '@/composables/adminUi'
import { ASSIGNABLE_SECTIONS, SECTION_GROUPS } from '@/composables/adminSections'
import { getToken } from '@/composables/authToken'
import { useRowNumbers } from '@/composables/rowNumber'
import { useSaveFeedback } from '@/composables/useSaveFeedback'
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
const rowNumber = useRowNumbers(admins, 'admin_id')
const initialLoad = ref(true)
const apiError = ref('')
const busyId = ref(null)
const liveMessage = ref('')

const modal = ref({ show: false, editing: false, loading: false, error: '', targetId: null })
const form = ref({ first_name: '', last_name: '', username: '', phone_number: '', password: '', password_confirmation: '' })
const mfaEnabled = ref(false)
const closeDialog = ref({ show: false, item: null, loading: false })
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
  { title: '#', key: 'rowNumber', sortable: false, width: '56px' },
  { title: 'Name', key: 'name', sortable: false, width: '28%' },
  { title: 'Mobile', key: 'phone', sortable: false },
  { title: 'Access', key: 'access', sortable: false },
  { title: 'Status', key: 'status', sortable: false },
  { title: 'Actions', key: 'actions', sortable: false, align: 'end' },
]

// What the Access column says for an account, and the StatusPill accent it
// wears. NULL is the value every account that existed before permissions did
// keeps: unrestricted, which is not the same as an empty list and must not read
// as one.
const accessSummary = (item) => {
  if (item?.is_super_admin) return { text: 'Super admin', status: 'Approved' }
  if (item?.permissions === null || item?.permissions === undefined) return { text: 'All sections', status: 'Cancelled' }
  if (item.permissions.length === 0) return { text: 'No sections', status: 'Pending' }
  return { text: `${item.permissions.length} of ${ASSIGNABLE_SECTIONS.length} sections`, status: 'Cancelled' }
}

// The checkboxes follow the sidebar, so choosing what an account can open reads
// like the menu it will get.
const accessGroups = SECTION_GROUPS
  .map((g) => ({ label: g.label, items: ASSIGNABLE_SECTIONS.filter((s) => s.group === g.key) }))
  .filter((g) => g.items.length > 0)

const accessDialog = ref({ show: false, item: null, loading: false, error: '', superAdmin: false, granted: [] })
// Spinner / "Saved" on Save access, as in EditDialog.
const { shown: accessShown, phase: accessPhase } = useSaveFeedback(() => accessDialog.value.show, () => accessDialog.value.loading, () => accessDialog.value.error)

const allSectionsGranted = computed(() => accessDialog.value.granted.length === ASSIGNABLE_SECTIONS.length)

const getHeaders = () => ({
  Authorization: `Bearer ${getToken()}`,
  'Content-Type': 'application/json',
  Accept: 'application/json',
})

const idOf = (item) => item?.admin_id ?? item?.id ?? null
const isSelf = (item) => idOf(item) !== null && idOf(item) === myId.value
const fullName = (item) => (item ? `${item.first_name} ${item.last_name}` : '')
const initials = (item) => computeInitials(item)

// Mirrors the server: an account is closed only when it says Inactive. A null
// status is a row someone inserted by hand, which is still the recovery path.
const isClosed = (item) => String(item?.status ?? '').toLowerCase() === 'inactive'

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
    loading: false,
    error: '',
    superAdmin: !!item.is_super_admin,
    granted: current,
  }
}

const toggleAllSections = () => {
  accessDialog.value.granted = allSectionsGranted.value ? [] : ASSIGNABLE_SECTIONS.map((s) => s.key)
}

const saveAccess = async () => {
  const item = accessDialog.value.item
  accessDialog.value.error = ''
  accessDialog.value.loading = true

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

const askClose = (item) => { closeDialog.value = { show: true, item, loading: false } }

const confirmClose = async () => {
  const item = closeDialog.value.item
  closeDialog.value.loading = true
  try {
    const res = await fetch(`${API}/${idOf(item)}`, { method: 'DELETE', headers: getHeaders() })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(messageFrom(data, 'Could not close the account'))

    await fetchAdmins()
    closeDialog.value.show = false
    announce(data.message || 'Account closed')
  } catch (error) {
    closeDialog.value.show = false
    announce(error.message, 'error')
  } finally {
    closeDialog.value.loading = false
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
.row-buttons { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 8px; }
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
.row-number {
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-variant-numeric: tabular-nums;
}

/* Fixed layout keeps the columns stable whatever the names are; the board's
   880px floor, below which the card scrolls. */
.staff-table :deep(.dtp-table table) { min-width: 880px; }
</style>
