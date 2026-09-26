<template>
  <v-container fluid class="fill-height align-start bg-background">
    <v-row class="ma-0 w-100">
      <v-col cols="12" class="pa-0 w-100">

        <PageHeader
          title="Staff Accounts"
        >
          <template v-slot:actions>
            <v-btn
              color="primary" variant="flat" rounded="lg" height="48"
              class="px-6 text-none font-weight-bold btn-soft-shadow"
              @click="openAdd"
            >
              <v-icon start size="20">mdi-account-plus-outline</v-icon> Add staff account
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
        <v-alert
          type="info" variant="tonal" density="comfortable" rounded="lg" class="mb-6"
          icon="mdi-information-outline"
        >
          Forgotten passwords are reset here, not by email. Choose “Reset password” on the
          account, then pass the temporary password on in person. They will be asked to set
          their own before they can use the panel.
          <div class="mt-2">
            A new account starts with no access. Choose “Access” on it to pick which sections it
            can open. Only a super admin sees this page or can change access.
          </div>
        </v-alert>

        <v-card elevation="0" rounded="xl" class="group-card">
          <v-data-table
            :key="initialLoad ? 'loading' : 'ready'"
            :headers="headers"
            :items="admins"
            :items-per-page="10"
            item-value="admin_id"
            class="staff-table table-fade"
          >
            <template v-if="initialLoad" #body>
              <SkeletonRows :rows="10" :columns="headers.length" />
            </template>
            <template v-slot:item.rowNumber="{ item }">
              <span class="row-number text-medium-emphasis">{{ rowNumber(item) }}</span>
            </template>

            <template #item.name="{ item }">
              <div class="d-flex align-center gap-3 py-2">
                <v-avatar color="primary" variant="tonal" size="36">
                  <span class="font-weight-bold text-caption avatar-initials">{{ initials(item) }}</span>
                </v-avatar>
                <div class="min-w-0">
                  <div class="text-body-2 font-weight-bold text-high-emphasis text-truncate">
                    {{ item.last_name }}, {{ item.first_name }}
                    <span v-if="isSelf(item)" class="you-chip">you</span>
                  </div>
                  <div class="text-caption text-medium-emphasis text-truncate">{{ item.email_address }}</div>
                </div>
              </div>
            </template>

            <template #item.access="{ item }">
              <!-- Icon and word both, never colour alone. -->
              <v-chip
                :color="accessSummary(item).color"
                size="small" variant="outlined" class="font-weight-bold"
              >
                <v-icon start size="14" aria-hidden="true">{{ accessSummary(item).icon }}</v-icon>
                {{ accessSummary(item).text }}
              </v-chip>
            </template>

            <template #item.status="{ item }">
              <!-- Icon and word both, never colour alone. -->
              <v-chip
                :color="isClosed(item) ? 'error' : 'success'"
                size="small" variant="outlined" class="font-weight-bold"
              >
                <v-icon start size="14" aria-hidden="true">
                  {{ isClosed(item) ? 'mdi-account-cancel-outline' : 'mdi-account-check-outline' }}
                </v-icon>
                {{ isClosed(item) ? 'Deactivated' : 'Active' }}
              </v-chip>
            </template>

            <template #item.actions="{ item }">
              <div class="d-flex justify-end gap-1">
                <v-btn
                  variant="outlined" color="primary" size="small" class="text-none font-weight-bold"
                  :aria-label="`Edit ${fullName(item)}`"
                  @click="openEdit(item)"
                >
                  Edit
                </v-btn>
                <v-btn
                  variant="outlined" color="primary" size="small" class="text-none font-weight-bold"
                  :aria-label="`Choose which sections ${fullName(item)} can open`"
                  @click="openAccess(item)"
                >
                  Access
                </v-btn>
                <!-- Never on your own row, and never on a closed account: the
                     server refuses both, this only spares the round trip. -->
                <v-btn
                  v-if="!isClosed(item) && !isSelf(item)"
                  variant="outlined" color="primary" size="small" class="text-none font-weight-bold"
                  :aria-label="`Reset the password of ${fullName(item)}`"
                  @click="askReset(item)"
                >
                  Reset password
                </v-btn>
                <v-btn
                  v-if="isClosed(item)"
                  variant="outlined" size="small" color="success" class="text-none font-weight-bold"
                  :aria-label="`Reactivate ${fullName(item)}`"
                  :loading="busyId === idOf(item)"
                  @click="reactivate(item)"
                >
                  Reactivate
                </v-btn>
                <v-btn
                  v-else
                  variant="outlined" size="small" color="error" class="text-none font-weight-bold"
                  :aria-label="`Close the account of ${fullName(item)}`"
                  :disabled="isSelf(item)"
                  @click="askClose(item)"
                >
                  Close account
                </v-btn>
              </div>
            </template>

            <template #no-data>
              <div class="text-center py-10 text-medium-emphasis">
                No staff accounts. This should be impossible while you are signed in.
              </div>
            </template>
          </v-data-table>
        </v-card>

      </v-col>
    </v-row>

    <!-- Add / edit -->
    <v-dialog v-model="modal.show" max-width="520" persistent>
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="d-flex justify-space-between align-center pa-6 pb-2">
          <span class="text-h6 font-weight-bold text-high-emphasis">
            {{ modal.editing ? 'Edit staff account' : 'Add staff account' }}
          </span>
          <v-btn icon="mdi-close" variant="tonal" rounded="circle" size="small" aria-label="Close" @click="modal.show = false"></v-btn>
        </v-card-title>
        <v-card-text class="px-6 py-2">
          <v-alert
            v-if="modal.error" type="error" variant="tonal" density="compact"
            rounded="lg" class="mb-4" role="alert"
          >{{ modal.error }}</v-alert>

          <v-form ref="formRef">
            <v-row>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.first_name" label="First name *" placeholder="Juan" variant="outlined"
                  density="comfortable" rounded="lg" autocomplete="given-name"
                  :rules="[requiredRule('First name')]" :error-messages="fieldErrors.first_name"
                ></v-text-field>
              </v-col>
               <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.last_name" label="Last name *" placeholder="Dela Cruz" variant="outlined"
                  density="comfortable" rounded="lg" autocomplete="family-name"
                  :rules="[requiredRule('Last name')]" :error-messages="fieldErrors.last_name"
                ></v-text-field>
              </v-col>
            </v-row>

            <!-- Staff sign in with a made-up username on the office domain; no mail
                 is ever sent to it. Only the name part is typed. An account made
                 before this rule keeps its full address and shows it as-is. -->
            <v-text-field
              v-if="!legacyEmail"
              :model-value="form.email_address" label="Username *" placeholder="juan.delacruz" variant="outlined"
              density="comfortable" rounded="lg" autocomplete="off" class="mb-1"
              suffix="@serbis.com" hint="Lowercase letters, digits and dots." persistent-hint
              :rules="[requiredRule('Username'), usernameRule]" :error-messages="fieldErrors.email_address"
              @update:model-value="onUsernameInput"
            ></v-text-field>
            <v-text-field
              v-else
              v-model="form.email_address" label="Email address *" type="email" variant="outlined"
              density="comfortable" rounded="lg" autocomplete="off" class="mb-1"
              :rules="[requiredRule('Email address')]" :error-messages="fieldErrors.email_address"
            ></v-text-field>

            <div class="text-caption text-medium-emphasis mb-3">
              {{ modal.editing
                ? 'Leave both password fields blank to keep the current password.'
                : 'At least 8 characters, with upper and lower case and a number.' }}
            </div>

            <v-text-field
              v-model="form.password"
              :label="modal.editing ? 'New password' : 'Password *'"
              :placeholder="modal.editing ? 'Leave blank to keep the current password' : 'At least 8 characters'"
              :type="showPassword ? 'text' : 'password'"
              variant="outlined" density="comfortable" rounded="lg" autocomplete="new-password"
              class="mb-3"
              :rules="[passwordRequiredRule]" :error-messages="fieldErrors.password"
            >
              <template #append-inner>
                <v-btn
                  :icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
                  :aria-label="showPassword ? 'Hide password' : 'Show password'"
                  :aria-pressed="showPassword"
                  variant="text" density="comfortable" size="small"
                  @click="showPassword = !showPassword"
                ></v-btn>
              </template>
            </v-text-field>

            <v-text-field
              v-model="form.password_confirmation"
              :label="modal.editing ? 'Confirm new password' : 'Confirm password *'"
              :placeholder="modal.editing ? 'Leave blank to keep the current password' : 'Type the password again'"
              :type="showPassword ? 'text' : 'password'"
              variant="outlined" density="comfortable" rounded="lg" autocomplete="new-password"
              :rules="[passwordConfirmRule]" :error-messages="fieldErrors.password_confirmation"
            ></v-text-field>

            <!-- Changing a password ends that account's other sessions. Saying so
                 before the click, because for the person being edited it looks
                 like being logged out at random. -->
            <div v-if="willChangePassword" class="notice subtle-surface mt-3">
              <v-icon size="16" class="mr-1 text-medium-emphasis" aria-hidden="true">mdi-logout-variant</v-icon>
              <span v-if="modal.editing && modal.targetId === myId">
                Your other devices will be signed out. This one stays signed in.
              </span>
              <span v-else>Signs this account out everywhere it is currently signed in.</span>
            </div>
          </v-form>
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="outlined" color="primary" rounded="lg" class="text-none" :disabled="modal.loading" @click="modal.show = false">
            Cancel
          </v-btn>
          <v-btn
            color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold"
            :loading="modal.loading" @click="save"
          >
            {{ modal.editing ? 'Save' : 'Create account' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Access: which sections this account may open. A super admin sees every
         section whatever is ticked, so the list is disabled while the switch is
         on rather than left looking like it still decides something. -->
    <v-dialog v-model="accessDialog.show" max-width="560" scrollable persistent>
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="d-flex justify-space-between align-center pa-6 pb-2">
          <span class="text-h6 font-weight-bold text-high-emphasis">
            Access for {{ fullName(accessDialog.item) }}
          </span>
          <v-btn icon="mdi-close" variant="tonal" rounded="circle" size="small" aria-label="Close" @click="accessDialog.show = false"></v-btn>
        </v-card-title>
        <v-card-text class="px-6 py-2">
          <v-alert
            v-if="accessDialog.error" type="error" variant="tonal" density="compact"
            rounded="lg" class="mb-4" role="alert"
          >{{ accessDialog.error }}</v-alert>

          <v-switch
            v-model="accessDialog.superAdmin" color="primary" inset hide-details
            label="Super admin"
          ></v-switch>
          <div class="text-caption text-medium-emphasis mb-4">
            Sees every section, is the only kind of account that can open this page, and decides
            everyone's access. The list below is ignored while this is on.
          </div>

          <div class="d-flex justify-space-between align-center mb-1">
            <span class="text-caption text-uppercase font-weight-bold text-medium-emphasis">
              Sections this account can open
            </span>
            <v-btn
              variant="outlined" color="primary" size="small" class="text-none font-weight-bold"
              :disabled="accessDialog.superAdmin" @click="toggleAllSections"
            >
              {{ allSectionsGranted ? 'Clear all' : 'Select all' }}
            </v-btn>
          </div>

          <div v-for="group in accessGroups" :key="group.label" class="mb-3">
            <div class="text-caption text-medium-emphasis mb-0 mt-2">{{ group.label }}</div>
            <v-checkbox
              v-for="section in group.items" :key="section.key"
              v-model="accessDialog.granted" :value="section.key" :label="section.title"
              color="primary" density="compact" hide-details
              :disabled="accessDialog.superAdmin"
            ></v-checkbox>
          </div>
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="outlined" color="primary" rounded="lg" class="text-none" :disabled="accessDialog.loading" @click="accessDialog.show = false">
            Cancel
          </v-btn>
          <v-btn
            color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold"
            :loading="accessDialog.loading" @click="saveAccess"
          >
            Save access
          </v-btn>
        </v-card-actions>
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
import { initials as computeInitials } from '@/composables/adminUi'
import { ASSIGNABLE_SECTIONS, SECTION_GROUPS } from '@/composables/adminSections'
import { getToken } from '@/composables/authToken'
import { useRowNumbers } from '@/composables/rowNumber'
import { loadCurrentAdmin } from '@/composables/useCurrentAdmin'
import { API_BASE } from '@/config/api'
import PageHeader from '@/components/PageHeader.vue'
import SkeletonRows from '@/components/SkeletonRows.vue'

const API = `${API_BASE}/admins`

const admins = ref([])
const rowNumber = useRowNumbers(admins, 'admin_id')
const initialLoad = ref(true)
const apiError = ref('')
const busyId = ref(null)
const myId = ref(null)
const showPassword = ref(false)
const liveMessage = ref('')

const modal = ref({ show: false, editing: false, loading: false, error: '', targetId: null, legacyEmail: false })
const form = ref({ first_name: '', last_name: '', email_address: '', password: '', password_confirmation: '' })
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

const EMAIL_SUFFIX = '@serbis.com'

// Editing an account made before the @serbis.com rule: its address is kept and
// shown in full. Everything else types just the name part.
const legacyEmail = computed(() => modal.value.editing && !!modal.value.legacyEmail)

// Same shape the server enforces: letters, digits and single dots between them.
const usernameRule = (v) =>
  /^[a-z0-9]+(\.[a-z0-9]+)*$/.test(String(v || '')) || 'Use lowercase letters, digits and single dots only.'

// Lowercases and drops anything the address cannot hold as it is typed. A pasted
// full address loses everything from the @ on, so the suffix is never doubled.
const onUsernameInput = (v) => {
  form.value.email_address = String(v || '').toLowerCase().replace(/@.*$/, '').replace(/[^a-z0-9.]/g, '')
}

const passwordConfirmRule = (v) =>
  String(v || '') === String(form.value.password || '') || 'The two passwords do not match.'

// Server-side errors, keyed by field, so a 422 lands on the input it belongs
// to instead of being concatenated into the banner above the form.
const fieldErrors = ref({})
const clearFieldErrors = () => { fieldErrors.value = {} }

const headers = [
  { title: '#', key: 'rowNumber', sortable: false, align: 'center', width: '64px' },
  { title: 'Name', key: 'name', sortable: false, width: '40%' },
  { title: 'Access', key: 'access', sortable: false, width: '190px' },
  { title: 'Status', key: 'status', sortable: false, width: '160px' },
  { title: '', key: 'actions', sortable: false, align: 'end', width: '420px' },
]

// What the Access column says for an account. NULL is the value every account
// that existed before permissions did keeps: unrestricted, which is not the same
// as an empty list and must not read as one.
const accessSummary = (item) => {
  if (item?.is_super_admin) return { text: 'Super admin', color: 'primary', icon: 'mdi-shield-crown-outline' }
  if (item?.permissions === null || item?.permissions === undefined) {
    return { text: 'All sections', color: 'secondary', icon: 'mdi-lock-open-variant-outline' }
  }
  if (item.permissions.length === 0) return { text: 'No sections', color: 'warning', icon: 'mdi-lock-outline' }
  return {
    text: `${item.permissions.length} of ${ASSIGNABLE_SECTIONS.length} sections`,
    color: 'secondary',
    icon: 'mdi-lock-open-variant-outline',
  }
}

// The checkboxes follow the sidebar, so choosing what an account can open reads
// like the menu it will get.
const accessGroups = SECTION_GROUPS
  .map((g) => ({ label: g.label, items: ASSIGNABLE_SECTIONS.filter((s) => s.group === g.key) }))
  .filter((g) => g.items.length > 0)

const accessDialog = ref({ show: false, item: null, loading: false, error: '', superAdmin: false, granted: [] })

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

const willChangePassword = computed(() => !!form.value.password || !!form.value.password_confirmation)

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
  } catch (error) {
    apiError.value = error.message
  } finally {
    initialLoad.value = false
  }
}

/** Which row is the signed-in admin, so the panel can refuse to close it. */
const fetchMe = async () => {
  try {
    const res = await fetch(`${API_BASE}/me`, { headers: getHeaders() })
    if (!res.ok) return
    const data = await res.json()
    myId.value = data?.user?.admin_id ?? null
  } catch {
    // Not fatal: the server refuses a self-close regardless. This only decides
    // whether the button is disabled before the round trip.
  }
}

const openAdd = () => {
  form.value = { first_name: '', last_name: '', email_address: '', password: '', password_confirmation: '' }
  showPassword.value = false
  modal.value = { show: true, editing: false, loading: false, error: '', targetId: null, legacyEmail: false }
  clearFieldErrors()
  formRef.value?.resetValidation()
}

const openEdit = (item) => {
  const legacy = !String(item.email_address).endsWith(EMAIL_SUFFIX)
  form.value = {
    first_name: item.first_name,
    last_name: item.last_name,
    email_address: legacy ? item.email_address : item.email_address.slice(0, -EMAIL_SUFFIX.length),
    password: '',
    password_confirmation: '',
  }
  showPassword.value = false
  modal.value = { show: true, editing: true, loading: false, error: '', targetId: idOf(item), legacyEmail: legacy }
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
    email_address: legacyEmail.value
      ? form.value.email_address.trim()
      : `${form.value.email_address.trim()}${EMAIL_SUFFIX}`,
  }
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
    // Left on the dialog, which stays open: a duplicate email is fixed in the
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
  fetchMe()
  fetchAdmins()
})
</script>

<style scoped>
.gap-1 { gap: 4px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
.min-w-0 { min-width: 0; }

.group-card {
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08) !important;
}

/* primary on a 14% primary tint measures 4.25:1 at this size, under the AA
   floor. primary-strong is the token that pairing already uses elsewhere. */
.avatar-initials { color: rgb(var(--v-theme-primary-strong)); }

.you-chip {
  margin-left: 6px;
  padding: 1px 6px;
  border-radius: 6px;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: rgb(var(--v-theme-primary-strong));
  border: 1px solid rgba(var(--v-theme-primary), 0.4);
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
  font-size: 0.95rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

/* Fixed layout keeps the four columns stable regardless of name/email
   length. Was `.elegant-table` — the exact class name UsersView's table
   carries, but Vue's scoped styles don't cross files, so it inherited
   none of that table's CSS (dark-green header, 76px rows) and did
   nothing here. "elegant-table" also isn't a shared pattern to opt into:
   of the app's eight data tables, only UsersView carries that
   treatment — the other seven (this one included) use the plain
   uppercase-caps header below. Renamed to say what it actually is. */
.staff-table :deep(table) { table-layout: fixed !important; width: 100% !important; min-width: 520px; }
</style>
