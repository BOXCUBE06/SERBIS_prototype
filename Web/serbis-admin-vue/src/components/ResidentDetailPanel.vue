<template>
  <div class="detail-panel">
    <div class="detail-head">
      <span class="text-caption text-uppercase font-weight-bold text-medium-emphasis">{{ accountTypeLabel(resident.account_type) }} profile</span>
      <v-btn icon="mdi-close" variant="text" size="small" aria-label="Close profile" @click="$emit('close')"></v-btn>
    </div>

    <!-- The open profile is not in the list behind this dialog. Stated rather
         than silently closed: the actions below are live, and Delete pointed
         at a record the current view says is not there is the one mistake
         this screen can make that cannot be undone. -->
    <div v-if="hiddenByFilter" class="mx-6 mb-2 hidden-note" role="status">
      <v-icon size="16" class="mr-2" aria-hidden="true">mdi-filter-off-outline</v-icon>
      <span class="flex-grow-1">
        Not in the current view — {{ barangayName }}
      </span>
      <v-btn
        variant="text"
        size="small"
        class="text-none font-weight-bold"
        @click="$emit('clear-filters')"
      >Show</v-btn>
    </div>

    <!-- Two columns so the whole record is on screen at once: who they are and
         how to reach them on the left, how their equipment came back on the
         right. Under 720px they stack. -->
    <div class="detail-body">
      <section class="detail-main" aria-label="Account details">
        <div class="d-flex align-center ga-4 mb-4">
          <v-avatar size="80" class="avatar-tint flex-shrink-0">
            <v-img v-if="photoUrl" :src="photoUrl" :alt="`Photo of ${resident.first_name} ${resident.last_name}`"></v-img>
            <span v-else class="avatar-initials text-h5">{{ initials }}</span>
          </v-avatar>

          <div class="min-w-0">
            <h3 class="text-h5 font-weight-bold text-high-emphasis detail-name">
              {{ resident.first_name }} {{ resident.last_name }}
            </h3>

            <span class="status-pill mt-2" :class="residentStatusPillClass(resident.status)">
              <span class="status-dot" :class="residentStatusDotClass(resident.status)"></span>
              {{ residentStatusLabel(resident.status) }}
            </span>
          </div>
        </div>

        <!-- A self-registered organization cannot request anything until it is
             approved here. Approve activates it; Reject deactivates it. -->
        <div v-if="isPendingOrganization" class="pending-note mb-4" role="status">
          <v-icon size="16" class="mr-2" aria-hidden="true">mdi-clock-outline</v-icon>
          <span>Awaiting your approval. This organization cannot request services until you approve it.</span>
        </div>

        <div class="detail-fields">
          <h4 class="detail-heading detail-fields__full">Contact</h4>

          <div>
            <div class="detail-label">Phone number</div>
            <a :href="`tel:${displayPhone(resident.phone_number)}`" class="detail-link text-body-1 font-weight-medium">
              <v-icon size="18" class="mr-2">mdi-phone</v-icon>{{ displayPhone(resident.phone_number) }}
            </a>
          </div>

          <!-- Email is no longer collected (a phone number is the login), so newer
               accounts have none. Shown only for the accounts that gave one. -->
          <div v-if="resident.email_address">
            <div class="detail-label">Email address</div>
            <a :href="`mailto:${resident.email_address}`" class="detail-link text-body-1 font-weight-medium">
              <v-icon size="18" class="mr-2">mdi-email-outline</v-icon>{{ resident.email_address }}
            </a>
          </div>

          <div :class="{ 'detail-fields__full': !smsOptIn }">
            <div class="detail-label">SMS blasts</div>
            <span class="status-pill" :class="smsPillClass">
              <span class="status-dot" :class="smsDotClass"></span>
              {{ smsLabel }}
            </span>
            <!-- Said in words because the pill alone does not explain that this is
                 the resident's own choice and not something the office switched
                 off. There is no admin control for it: it is written from the
                 mobile app through PATCH /me. -->
            <div v-if="!smsOptIn" class="text-body-2 text-medium-emphasis mt-2">
              This resident turned MDRRMO text blasts off in the app. Only they can turn them back on.
            </div>
          </div>

          <h4 class="detail-heading detail-fields__full">Registration</h4>

          <div>
            <div class="detail-label">Account type</div>
            <div class="text-body-1 font-weight-medium text-high-emphasis">
              {{ accountTypeLabel(resident.account_type) }}<template v-if="resident.organization_name"> · {{ resident.organization_name }}</template>
            </div>
          </div>

          <div>
            <div class="detail-label">Barangay</div>
            <div class="text-body-1 font-weight-medium text-high-emphasis">{{ barangayName }}</div>
          </div>

          <div>
            <div class="detail-label">Registered on</div>
            <div class="text-body-1 font-weight-medium text-high-emphasis">{{ registeredOn }}</div>
          </div>

          <div>
            <div class="detail-label">Resident ID</div>
            <div class="text-body-1 font-weight-medium text-high-emphasis">#{{ residentId }}</div>
          </div>
        </div>
      </section>

      <!-- For reading before approving a new borrow request. Information only:
           nothing here blocks or flags a request. -->
      <section class="detail-returns" aria-label="Equipment returns">
        <h4 class="detail-heading">Equipment returns</h4>
        <ResidentReturnHistory :resident-id="residentId" />
      </section>
    </div>

    <div class="detail-actions">
      <v-btn
        color="error"
        variant="text"
        height="44"
        rounded="lg"
        class="text-none font-weight-bold mr-auto"
        @click="$emit('delete', resident)"
      >
        <v-icon start>mdi-delete-outline</v-icon> Delete account
      </v-btn>

      <v-btn
        color="primary"
        variant="flat"
        height="44"
        rounded="lg"
        class="text-none font-weight-bold"
        @click="$emit('edit', resident)"
      >
        <v-icon start>mdi-pencil</v-icon> Edit profile
      </v-btn>

      <template v-if="isPendingOrganization">
        <v-btn
          color="warning"
          variant="tonal"
          height="44"
          rounded="lg"
          class="text-none font-weight-bold"
          :disabled="statusLoading"
          @click="$emit('reject', resident)"
        >
          <v-icon start>mdi-close-circle-outline</v-icon> Reject organization
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          height="44"
          rounded="lg"
          class="text-none font-weight-bold"
          :loading="statusLoading"
          @click="$emit('toggle-status', resident)"
        >
          <v-icon start>mdi-check-circle-outline</v-icon> Approve organization
        </v-btn>
      </template>

      <v-btn
        v-else
        :color="isActive ? 'warning' : 'primary'"
        variant="tonal"
        height="44"
        rounded="lg"
        class="text-none font-weight-bold"
        :loading="statusLoading"
        @click="$emit('toggle-status', resident)"
      >
        <v-icon start>{{ isActive ? 'mdi-account-cancel-outline' : 'mdi-account-check-outline' }}</v-icon>
        {{ isActive ? 'Deactivate account' : 'Activate account' }}
      </v-btn>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import ResidentReturnHistory from '@/components/ResidentReturnHistory.vue'
import { ACCOUNT_TYPE, accountTypeLabel } from '@/composables/accountType'
import { initials as computeInitials } from '@/composables/adminUi'
import { displayPhone } from '@/composables/phoneNumber'
import { residentPhotoUrl } from '@/composables/residentPhoto'
import {
  RESIDENT_STATUS,
  residentSmsDotClass,
  residentSmsLabel,
  residentSmsOptIn,
  residentSmsPillClass,
  residentStatusDotClass,
  residentStatusLabel,
  residentStatusPillClass,
} from '@/composables/residentStatus'

const props = defineProps({
  resident: { type: Object, required: true },
  statusLoading: { type: Boolean, default: false },
  // True when this profile is not in the filtered list behind the panel.
  hiddenByFilter: { type: Boolean, default: false },
})

defineEmits(['close', 'edit', 'toggle-status', 'reject', 'delete', 'clear-filters'])

// Pending and Deactivated share the action: both offer "Activate account".
const isActive = computed(() => props.resident.status === RESIDENT_STATUS.active)

// An organization that signed itself up and has not been activated. Individuals
// in the same status keep the plain Activate button: they can already file.
const isPendingOrganization = computed(() =>
  props.resident.account_type === ACCOUNT_TYPE.organization &&
  props.resident.status === RESIDENT_STATUS.pending
)

const residentId = computed(() => props.resident.resident_id ?? props.resident.id)

// Read-only here. The switch belongs to the resident and is written from the
// mobile app; the panel reports it so a short delivery report has an
// explanation on the same screen as the account.
const smsOptIn = computed(() => residentSmsOptIn(props.resident))
const smsLabel = computed(() => residentSmsLabel(smsOptIn.value))
const smsPillClass = computed(() => residentSmsPillClass(smsOptIn.value))
const smsDotClass = computed(() => residentSmsDotClass(smsOptIn.value))

// The panel is reused as the selection moves down the list, so the photo is
// keyed off the id and cleared first — otherwise the previous resident's face
// stays on screen under the new resident's name until the fetch returns.
const photoUrl = ref(null)
watch(
  () => [residentId.value, props.resident.has_photo],
  ([id, hasPhoto]) => {
    photoUrl.value = null
    if (!hasPhoto || id == null) return

    residentPhotoUrl(id).then((url) => {
      if (residentId.value === id) photoUrl.value = url
    })
  },
  { immediate: true },
)
const initials = computed(() => computeInitials(props.resident))
const barangayName = computed(
  () => props.resident.barangay?.barangay_name || props.resident.barangay_name || 'N/A',
)
const registeredOn = computed(() => {
  const v = props.resident.created_at
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' })
})
</script>

<style scoped>
/* Pending organization: amber, like the Pending status pill, with the text in
   the strong token so it stays readable on its own tint. */
.pending-note {
  display: flex;
  align-items: flex-start;
  padding: 10px 12px;
  border-radius: 10px;
  font-size: 0.8125rem;
  text-align: left;
  background: rgba(var(--v-theme-warning), 0.14);
  color: rgb(var(--v-theme-warning-strong));
}
/* Avatar — the old blue-on-light-blue pairing measured 3.28:1. Tinting the
   primary token keeps the soft look and passes AA in both themes. */
.avatar-tint { background: rgba(var(--v-theme-primary), 0.14) !important; }
.avatar-initials { color: rgb(var(--v-theme-primary-strong)); font-weight: 800; letter-spacing: 0.02em; }

/* Status pill — replaces the flat grey chip, which rendered white on #9E9E9E (2.68:1). */
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
/* primary-strong — primary on its own 14% tint measures 4.28:1 in light and
   fails AA at this size. See the matching comment in UsersView. */
.pill-active { background: rgba(var(--v-theme-primary), 0.14); color: rgb(var(--v-theme-primary-strong)); }
.pill-inactive { background: rgba(var(--v-theme-on-surface), 0.1); color: rgba(var(--v-theme-on-surface), 0.82); }
/* Pending — see UsersView for why the light-theme text colour is hardcoded. */
.pill-pending { background: rgba(var(--v-theme-warning), 0.14); color: #8A4B00; }
.v-theme--dark .pill-pending {
  background: rgba(var(--v-theme-warning), 0.1);
  color: rgb(var(--v-theme-warning));
}
.status-dot { width: 8px; height: 8px; border-radius: 50%; flex: none; }
.dot-active { background: rgb(var(--v-theme-primary)); }
.dot-inactive { background: rgba(var(--v-theme-on-surface), 0.5); }
.dot-pending { background: rgb(var(--v-theme-warning)); }

/* Warning-tinted, not error-tinted: nothing has gone wrong, the view simply
   disagrees with the panel. warning-strong text keeps it AA on the tint —
   the raw warning token is #F57C00, 3.0:1 on white. */
.hidden-note {
  display: flex;
  align-items: center;
  padding: 6px 8px 6px 12px;
  border-radius: 10px;
  font-size: 0.8rem;
  font-weight: 600;
  background: rgba(var(--v-theme-warning), 0.14);
  color: #8A4B00;
}
.v-theme--dark .hidden-note { color: rgb(var(--v-theme-warning)); }

.detail-head {
  padding: 16px 24px 4px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

/* Two columns: account on the left, equipment returns on the right. The
   fallback scroll is only for an account with a very long return history; an
   ordinary record fits without it. */
.detail-body {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
  gap: 8px 40px;
  padding: 8px 24px 16px;
  max-height: calc(100vh - 220px);
  overflow-y: auto;
}
.min-w-0 { min-width: 0; }
.detail-name { word-break: break-word; }

.detail-fields {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px 24px;
}
.detail-fields__full { grid-column: 1 / -1; }
.detail-heading {
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.detail-fields .detail-heading { margin-top: 4px; }
.detail-label {
  margin-bottom: 2px;
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.detail-returns { min-width: 0; }
.detail-returns .detail-heading { margin-bottom: 12px; }

.detail-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  padding: 16px 24px;
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}

@media (max-width: 719px) {
  .detail-body { grid-template-columns: minmax(0, 1fr); }
}

.detail-link {
  display: inline-flex;
  align-items: center;
  color: rgb(var(--v-theme-primary));
  text-decoration: none;
  word-break: break-all;
}
.detail-link:hover { text-decoration: underline; }
.detail-link:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
  border-radius: 4px;
}
</style>
