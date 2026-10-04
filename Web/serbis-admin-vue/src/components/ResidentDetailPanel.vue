<template>
  <div class="detail-panel">
    <!-- The Account detail board's header: mark, name, contact, status pill and
         close on one row. The name and initials are the ones the table row
         shows (composables/accountName.ts), so the row and the dialog it opens
         agree. Own markup rather than DetailDialogHeader, which has a labelled
         Status column the other detail dialogs use and this board does not. -->
    <div class="detail-head">
      <v-avatar size="52" color="primary" variant="tonal" class="flex-none">
        <v-img v-if="photoUrl" :src="photoUrl" alt="" cover></v-img>
        <span v-else class="head-mark">{{ initials }}</span>
      </v-avatar>
      <div class="head-text">
        <div class="detail-eyebrow">Account</div>
        <div class="head-name text-truncate">{{ displayName }}</div>
        <div v-if="secondary" class="head-sub text-truncate">{{ secondary }}</div>
      </div>
      <StatusPill dot class="flex-none" :status="residentStatusLabel(resident.status)" />
      <v-btn icon="mdi-close" variant="flat" rounded="circle" class="head-close flex-none" aria-label="Close" @click="$emit('close')"></v-btn>
    </div>

    <!-- The open profile is not in the list behind this dialog. Stated rather
         than silently closed: the actions below are live, and Delete pointed
         at a record the current view says is not there is the one mistake
         this screen can make that cannot be undone. -->
    <div v-if="hiddenByFilter" class="mx-8 mt-4 hidden-note" role="status">
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
    <div v-if="isPendingOrganization" class="pending-note mx-8 mt-4" role="status">
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
          <h3 id="detail-contact" class="detail-eyebrow">Contact</h3>

          <div>
            <div class="detail-label">Phone number</div>
            <a :href="`tel:${displayPhone(resident.phone_number)}`" class="detail-link">
              <v-icon size="16" class="flex-none">mdi-phone-outline</v-icon><span class="detail-link__text">{{ displayPhone(resident.phone_number) }}</span>
            </a>
          </div>

          <!-- Email is no longer collected (a phone number is the login), so newer
               accounts have none. Shown only for the accounts that gave one. -->
          <div v-if="resident.email_address">
            <div class="detail-label">Email address</div>
            <a :href="`mailto:${resident.email_address}`" class="detail-link">
              <v-icon size="16" class="flex-none">mdi-email-outline</v-icon><span class="detail-link__text">{{ resident.email_address }}</span>
            </a>
          </div>

          <div>
            <div class="detail-label">SMS blasts</div>
            <StatusPill dot class="sms-pill" :status="smsLabel" />
            <!-- Said in words because the pill alone does not explain that this is
                 the account holder's own choice and not something the office
                 switched off. There is no admin control for it: it is written
                 from the mobile app through PATCH /me. -->
            <p v-if="!smsOptIn" class="sms-note">
              This account holder turned MDRRMO text blasts off in the app. Only they can turn them back on.
            </p>
          </div>
        </section>

        <section class="detail-section" aria-labelledby="detail-registration">
          <h3 id="detail-registration" class="detail-eyebrow">Registration</h3>

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
            <div class="detail-mono">#{{ residentId }}</div>
          </div>
        </section>
      </div>

      <!-- For reading before approving a new borrow request. Information only:
           nothing here blocks or flags a request. -->
      <section aria-label="Equipment returns">
        <ResidentReturnHistory :resident-id="residentId" />
      </section>
    </div>

    <div class="detail-actions">
      <v-btn
        variant="flat"
        height="40"
        class="act-btn act-delete mr-auto"
        @click="$emit('delete', resident)"
      >
        <v-icon start size="16">mdi-delete-outline</v-icon> Delete account
      </v-btn>

      <template v-if="isPendingOrganization">
        <v-btn
          variant="flat"
          height="40"
          class="act-btn act-quiet act-warn"
          :disabled="statusLoading"
          @click="$emit('reject', resident)"
        >
          <v-icon start size="16">mdi-close-circle-outline</v-icon> Reject organization
        </v-btn>
        <v-btn
          variant="flat"
          height="40"
          class="act-btn act-quiet"
          :loading="statusLoading"
          @click="$emit('toggle-status', resident)"
        >
          <v-icon start size="16">mdi-check-circle-outline</v-icon> Approve organization
        </v-btn>
      </template>

      <v-btn
        v-else
        variant="flat"
        height="40"
        class="act-btn act-quiet"
        :class="{ 'act-warn': isActive }"
        :loading="statusLoading"
        @click="$emit('toggle-status', resident)"
      >
        <v-icon start size="16">{{ isActive ? 'mdi-account-cancel-outline' : 'mdi-account-check-outline' }}</v-icon>
        {{ isActive ? 'Deactivate account' : 'Activate account' }}
      </v-btn>

      <v-btn
        color="primary"
        variant="flat"
        height="40"
        class="act-btn act-primary"
        @click="$emit('edit', resident)"
      >
        <v-icon start size="16">mdi-pencil-outline</v-icon> Edit profile
      </v-btn>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import ResidentReturnHistory from '@/components/ResidentReturnHistory.vue'
import StatusPill from '@/components/StatusPill.vue'
import { ACCOUNT_TYPE, accountTypeLabel } from '@/composables/accountType'
import { accountInitials, barangayOf, contactName, primaryName } from '@/composables/accountName'
import { displayPhone } from '@/composables/phoneNumber'
import { residentPhotoUrl } from '@/composables/residentPhoto'
import {
  RESIDENT_STATUS,
  residentSmsLabel,
  residentSmsOptIn,
  residentStatusLabel,
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
.flex-none { flex: none; }

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
  color: rgb(var(--v-theme-warning-strong));
}

/* Header: 52px mark, the stacked name, the pill, a 36px close. */
.detail-head {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 24px 32px;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
.head-mark { font-size: 14px; font-weight: 700; color: rgb(var(--v-theme-primary-strong)); }
.head-text { flex: 1; min-width: 0; }
.head-name { font-size: 18.72px; line-height: 28px; font-weight: 600; }
.head-sub {
  font-size: 14px;
  line-height: 20px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.head-close {
  width: 36px;
  height: 36px;
  background: rgba(var(--v-theme-on-surface), 0.06);
  color: rgb(var(--v-theme-on-surface));
}
.detail-eyebrow {
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

/* Body: 28px between the columns and the returns, 32px between the columns,
   16px between fields. The fallback scroll is only for an account with a very
   long return history; an ordinary record fits without it. */
.detail-body {
  display: flex;
  flex-direction: column;
  gap: 28px;
  padding: 24px 32px;
  max-height: calc(100vh - 260px);
  overflow-y: auto;
}
.detail-columns {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 32px;
}
.detail-section { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
.detail-label {
  font-size: 12px;
  line-height: 16px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.detail-value {
  min-width: 0;
  overflow-wrap: break-word;
  font-size: 15px;
  line-height: 22px;
}
.detail-mono { font: 500 14px 'JetBrains Mono', ui-monospace, monospace; }
.sms-pill { margin-top: 4px; }
.sms-note {
  margin: 8px 0 0;
  font-size: 14px;
  line-height: 20px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

/* Footer buttons: 40px, 12px radius, 14px/700, 8px between icon and label. */
.detail-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  padding: 16px 32px;
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
.act-btn {
  padding: 0 16px;
  border-radius: 12px;
  font-size: 14px;
  font-weight: 700;
  letter-spacing: 0;
  text-transform: none;
}
.act-btn :deep(.v-icon--start) { margin-inline-end: 8px; }
.act-delete {
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-theme-error), 0.5);
  color: rgb(var(--v-theme-error-strong));
}
.act-quiet {
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.14);
  color: rgb(var(--v-theme-primary-strong));
}
.act-warn { color: rgb(var(--v-theme-warning-strong)); }
.act-primary {
  padding: 0 18px;
  box-shadow: 0 8px 16px -4px rgba(var(--v-theme-primary), 0.28);
}

@media (max-width: 719px) {
  .detail-columns { grid-template-columns: minmax(0, 1fr); }
}
/* Under 600px the buttons wrap to three rows, so the body gives up more height
   or the card itself scrolls and carries the actions off screen. */
@media (max-width: 599px) {
  .detail-head, .detail-body, .detail-actions { padding-left: 20px; padding-right: 20px; }
  .detail-body { max-height: calc(100vh - 400px); }
}

/* overflow-wrap, not word-break: break-all — that split every address at the
   line end mid-word; this only breaks a word too long to fit a line at all. */
.detail-link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  max-width: 100%;
  min-width: 0;
  font-size: 15px;
  line-height: 22px;
  font-weight: 600;
  color: rgb(var(--v-theme-primary-strong));
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
