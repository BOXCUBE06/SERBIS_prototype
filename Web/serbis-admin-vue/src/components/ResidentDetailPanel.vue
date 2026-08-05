<template>
  <div class="d-flex flex-column h-100">
    <div class="pa-6 pb-0 d-flex justify-space-between align-start">
      <span class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Resident profile</span>
      <v-btn icon="mdi-close" variant="text" size="small" aria-label="Close profile panel" @click="$emit('close')"></v-btn>
    </div>

    <div class="px-6 pt-2 d-flex flex-column align-center text-center">
      <v-avatar size="96" class="avatar-tint mb-4">
        <v-img v-if="photoUrl" :src="photoUrl" :alt="`Photo of ${resident.first_name} ${resident.last_name}`"></v-img>
        <span v-else class="avatar-initials text-h4">{{ initials }}</span>
      </v-avatar>

      <h3 class="text-h5 font-weight-bold text-high-emphasis">
        {{ resident.first_name }} {{ resident.last_name }}
      </h3>

      <span class="status-pill mt-3" :class="residentStatusPillClass(resident.status)">
        <span class="status-dot" :class="residentStatusDotClass(resident.status)"></span>
        {{ residentStatusLabel(resident.status) }}
      </span>
    </div>

    <div class="px-6 py-4 flex-grow-1 detail-scroll">
      <h4 class="text-subtitle-2 font-weight-bold text-medium-emphasis text-uppercase mb-4">Contact</h4>

      <div class="mb-4">
        <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-1">Phone number</div>
        <a :href="`tel:${resident.phone_number}`" class="detail-link text-body-1 font-weight-medium">
          <v-icon size="18" class="mr-2">mdi-phone</v-icon>{{ resident.phone_number }}
        </a>
      </div>

      <div class="mb-4">
        <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-1">Email address</div>
        <a :href="`mailto:${resident.email_address}`" class="detail-link text-body-1 font-weight-medium">
          <v-icon size="18" class="mr-2">mdi-email-outline</v-icon>{{ resident.email_address }}
        </a>
      </div>

      <h4 class="text-subtitle-2 font-weight-bold text-medium-emphasis text-uppercase mb-4 mt-6">Registration</h4>

      <div class="mb-4">
        <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-1">Barangay</div>
        <div class="text-body-1 font-weight-medium text-high-emphasis">{{ barangayName }}</div>
      </div>

      <div class="mb-4">
        <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-1">Registered on</div>
        <div class="text-body-1 font-weight-medium text-high-emphasis">{{ registeredOn }}</div>
      </div>

      <div class="mb-4">
        <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-1">Resident ID</div>
        <div class="text-body-1 font-weight-medium text-high-emphasis">#{{ residentId }}</div>
      </div>
    </div>

    <div class="pa-6 pt-4 detail-actions">
      <v-btn
        color="#0f4c3a"
        variant="flat"
        height="48"
        rounded="lg"
        block
        class="text-none font-weight-bold text-white mb-3"
        @click="$emit('edit', resident)"
      >
        <v-icon start>mdi-pencil</v-icon> Edit profile
      </v-btn>

      <v-btn
        :color="isActive ? 'warning' : 'primary'"
        variant="tonal"
        height="48"
        rounded="lg"
        block
        class="text-none font-weight-bold mb-3"
        :loading="statusLoading"
        @click="$emit('toggle-status', resident)"
      >
        <v-icon start>{{ isActive ? 'mdi-account-cancel-outline' : 'mdi-account-check-outline' }}</v-icon>
        {{ isActive ? 'Deactivate account' : 'Activate account' }}
      </v-btn>

      <v-btn
        color="error"
        variant="text"
        height="44"
        rounded="lg"
        block
        class="text-none font-weight-bold"
        @click="$emit('delete', resident)"
      >
        <v-icon start>mdi-delete-outline</v-icon> Delete account
      </v-btn>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { residentPhotoUrl } from '@/composables/residentPhoto'
import {
  RESIDENT_STATUS,
  residentStatusDotClass,
  residentStatusLabel,
  residentStatusPillClass,
} from '@/composables/residentStatus'

const props = defineProps({
  resident: { type: Object, required: true },
  statusLoading: { type: Boolean, default: false },
})

defineEmits(['close', 'edit', 'toggle-status', 'delete'])

// Pending and Deactivated share the action: both offer "Activate account".
const isActive = computed(() => props.resident.status === RESIDENT_STATUS.active)

const residentId = computed(() => props.resident.resident_id ?? props.resident.id)

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
const initials = computed(() =>
  `${(props.resident.first_name || '').charAt(0)}${(props.resident.last_name || '').charAt(0)}`.toUpperCase(),
)
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
.pill-active { background: rgba(var(--v-theme-primary), 0.14); color: rgb(var(--v-theme-primary)); }
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

.detail-scroll { overflow-y: auto; }
.detail-actions { border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08); }

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
