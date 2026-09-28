<template>
  <div class="detail-panel">
    <!-- Same header as the request, ambulance and borrowing dialogs. The name and
         initials are the ones the table row shows (composables/accountName.ts),
         so the row and the dialog it opens agree. -->
    <DetailDialogHeader
      label="Account"
      :name="displayName"
      :initials="initials"
      :secondary="secondary"
      :photo-url="photoUrl"
      @close="$emit('close')"
    >
      <template v-slot:status>
        <span class="status-pill" :class="residentStatusPillClass(resident.status)">
          <span class="status-dot" :class="residentStatusDotClass(resident.status)"></span>
          {{ residentStatusLabel(resident.status) }}
        </span>
      </template>
    </DetailDialogHeader>
    <v-divider></v-divider>

    <!-- The open profile is not in the list behind this dialog. Stated rather
         than silently closed: the actions below are live, and Delete pointed
         at a record the current view says is not there is the one mistake
         this screen can make that cannot be undone. -->
    <div v-if="hiddenByFilter" class="mx-6 mt-4 hidden-note" role="status">
      <v-icon size="16" class="mr-2" aria-hidden="true">mdi-filter-off-outline</v-icon>
      <span class="flex-grow-1">
        Not in the current view — {{ barangayName }}
      </span>
      <v-btn
        variant="outlined" color="primary"
        size="small"
        class="text-none font-weight-bold"
        @click="$emit('clear-filters')"
      >Show</v-btn>
    </div>

    <!-- A self-registered organization cannot request anything until it is
         approved here. Approve activates it; Reject deactivates it. -->
    <div v-if="isPendingOrganization" class="pending-note mx-6 mt-4" role="status">
      <v-icon size="16" class="mr-2" aria-hidden="true">mdi-clock-outline</v-icon>
      <span>Awaiting your approval. This organization cannot request services until you approve it.</span>
    </div>

    <!-- Three sections. Contact and Registration sit side by side because they
         are the same size; Equipment returns takes the full width below so an
         account with no history is one short row, not a mostly empty half.
         Under 720px everything stacks. -->
    <div class="detail-body">
      <div class="detail-columns">
        <section class="detail-section" aria-labelledby="detail-contact">
          <h4 id="detail-contact" class="detail-heading">Contact</h4>

          <div class="detail-list">
            <div>
              <div class="detail-label">Phone number</div>
              <a :href="`tel:${displayPhone(resident.phone_number)}`" class="detail-link text-body-1 font-weight-medium">
                <v-icon size="18" class="mr-2 flex-none">mdi-phone</v-icon><span class="detail-link__text">{{ displayPhone(resident.phone_number) }}</span>
              </a>
            </div>

            <!-- Email is no longer collected (a phone number is the login), so newer
                 accounts have none. Shown only for the accounts that gave one. -->
            <div v-if="resident.email_address">
              <div class="detail-label">Email address</div>
              <a :href="`mailto:${resident.email_address}`" class="detail-link text-body-1 font-weight-medium">
                <v-icon size="18" class="mr-2 flex-none">mdi-email-outline</v-icon><span class="detail-link__text">{{ resident.email_address }}</span>
              </a>
            </div>

            <div>
              <div class="detail-label">SMS blasts</div>
              <span class="status-pill" :class="smsPillClass">
                <span class="status-dot" :class="smsDotClass"></span>
                {{ smsLabel }}
              </span>
              <!-- Said in words because the pill alone does not explain that this is
                   the account holder's own choice and not something the office
                   switched off. There is no admin control for it: it is written
                   from the mobile app through PATCH /me. -->
              <div v-if="!smsOptIn" class="text-body-2 text-medium-emphasis mt-2">
                This account holder turned MDRRMO text blasts off in the app. Only they can turn them back on.
              </div>
            </div>
          </div>
        </section>

        <section class="detail-section" aria-labelledby="detail-registration">
          <h4 id="detail-registration" class="detail-heading">Registration</h4>

          <div class="detail-list">
            <div>
              <div class="detail-label">Account type</div>
              <div class="detail-value">{{ accountTypeLabel(resident.account_type) }}</div>
            </div>

            <div>
              <div class="detail-label">Barangay</div>
              <div class="detail-value">{{ barangayName }}</div>
            </div>

            <div>
              <div class="detail-label">Registered on</div>
              <div class="detail-value">{{ registeredOn }}</div>
            </div>

            <div>
              <div class="detail-label">Account ID</div>
              <div class="detail-value">#{{ residentId }}</div>
            </div>
          </div>
        </section>
      </div>

      <!-- For reading before approving a new borrow request. Information only:
           nothing here blocks or flags a request. -->
      <section class="detail-section" aria-labelledby="detail-returns">
        <h4 id="detail-returns" class="detail-heading">Equipment returns</h4>
        <ResidentReturnHistory :resident-id="residentId" />
      </section>
    </div>

    <div class="detail-actions">
      <v-btn
        color="error"
        variant="outlined"
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
import DetailDialogHeader from '@/components/DetailDialogHeader.vue'
import ResidentReturnHistory from '@/components/ResidentReturnHistory.vue'
import { ACCOUNT_TYPE, accountTypeLabel } from '@/composables/accountType'
import { accountInitials, barangayOf, contactName, primaryName } from '@/composables/accountName'
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

// Read-only here. The switch belongs to the account holder and is written from
// the mobile app; the panel reports it so a short delivery report has an
// explanation on the same screen as the account.
const smsOptIn = computed(() => residentSmsOptIn(props.resident))
const smsLabel = computed(() => residentSmsLabel(smsOptIn.value))
const smsPillClass = computed(() => residentSmsPillClass(smsOptIn.value))
const smsDotClass = computed(() => residentSmsDotClass(smsOptIn.value))

// The panel is reused as the selection moves down the list, so the photo is
// keyed off the id and cleared first — otherwise the previous account's face
// stays on screen under the new account's name until the fetch returns.
const photoUrl = ref(null)
watch(
  // updated_at moves when staff replace the photo: has_photo stays true then.
  () => [residentId.value, props.resident.has_photo, props.resident.updated_at],
  ([id, hasPhoto]) => {
    photoUrl.value = null
    if (!hasPhoto || id == null) return

    residentPhotoUrl(id).then((url) => {
      if (residentId.value === id) photoUrl.value = url
    })
  },
  { immediate: true },
)
const displayName = computed(() => primaryName(props.resident))
const initials = computed(() => accountInitials(props.resident))
const barangayName = computed(() => barangayOf(props.resident))
// The person to call for an institution; where a head of the family lives for a person.
const secondary = computed(() => contactName(props.resident) ?? (barangayName.value === 'N/A' ? null : barangayName.value))
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

/* Spacing: 24px between sections, 16px from a heading's rule to its content,
   16px between fields. The fallback scroll is only for an account with a very
   long return history; an ordinary record fits without it. */
.detail-body {
  display: flex;
  flex-direction: column;
  gap: 24px;
  padding: 20px 24px 16px;
  max-height: calc(100vh - 260px);
  overflow-y: auto;
}
.detail-columns {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 24px 40px;
}
.detail-section { min-width: 0; }
.detail-list { display: flex; flex-direction: column; gap: 16px; }

/* A section heading outranks a field label by size, weight, colour and the rule
   under it; the two used to be the same 12px uppercase line. */
.detail-heading {
  margin-bottom: 16px;
  padding-bottom: 8px;
  font-size: 0.9375rem;
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity));
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
.detail-label {
  margin-bottom: 2px;
  font-size: 0.75rem;
  font-weight: 600;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.detail-value {
  min-width: 0;
  overflow-wrap: break-word;
  font-size: 1rem;
  font-weight: 500;
  color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity));
}

.detail-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  padding: 16px 24px;
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}

@media (max-width: 719px) {
  .detail-columns { grid-template-columns: minmax(0, 1fr); }
}
/* Under 600px the header stacks Status under the name and the buttons wrap to
   three rows, so the body gives up more height or the card itself scrolls and
   carries the actions off screen. */
@media (max-width: 599px) {
  .detail-body { max-height: calc(100vh - 400px); }
}

/* overflow-wrap, not word-break: break-all — that split every address at the
   line end mid-word; this only breaks a word too long to fit a line at all. */
.detail-link {
  display: flex;
  align-items: center;
  min-width: 0;
  color: rgb(var(--v-theme-primary));
  text-decoration: none;
}
.detail-link__text { min-width: 0; overflow-wrap: break-word; }
.detail-link:hover { text-decoration: underline; }
.detail-link:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
  border-radius: 4px;
}
</style>
