<!--
  DetailDialogHeader.vue

  Shared header for the request/booking/borrowing detail dialogs: avatar,
  labelled requester, labelled status, then actions and close. One layout so
  the dialogs cannot drift apart again.

  Requester and Status share the same three grid rows (label / value /
  secondary), so their lines align without any per-dialog tuning.
-->
<template>
  <div class="detail-header">
    <v-avatar color="primary" variant="tonal" size="44" class="h-avatar">
      <span class="text-subtitle-1 font-weight-black">{{ initials }}</span>
    </v-avatar>

    <div class="header-label h-rlabel">Requester</div>
    <div class="h-value header-name text-truncate">{{ name }}</div>
    <div class="h-sub h-rsub text-medium-emphasis text-truncate">{{ secondary }}</div>

    <div class="header-label h-slabel">Status</div>
    <div class="h-value h-status"><slot name="status"></slot></div>
    <div v-if="waitDays !== null && waitDays !== undefined" class="h-sub h-note" :class="WAIT_CLASS[waitTone(waitDays)]">{{ waitDays }}d waiting</div>

    <div class="h-actions">
      <slot name="actions"></slot>
      <v-btn icon="mdi-close" variant="tonal" rounded="circle" aria-label="Close" @click="$emit('close')"></v-btn>
    </div>
  </div>
</template>

<script setup lang="ts">
import { waitTone } from '@/composables/adminUi'

// warning-strong: the base amber fails AA as text.
const WAIT_CLASS = { muted: 'text-medium-emphasis', warning: 'text-warning-strong', error: 'text-error' }

defineProps<{
  name: string
  initials: string
  secondary?: string | null
  /** Days waiting, shown under the status; open requests only. */
  waitDays?: number | null
}>()
defineEmits<{ close: [] }>()
</script>

<style scoped>
.detail-header {
  display: grid;
  grid-template-columns: 44px auto auto 1fr auto;
  grid-template-rows: auto 28px auto;
  padding: 20px 24px;
  flex-shrink: 0;
}
.header-label {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.header-name { font-size: 18px; font-weight: 600; line-height: 28px; }
.h-value { display: flex; align-items: center; min-width: 0; }
.h-sub { font-size: 14px; line-height: 20px; min-width: 0; }

.h-avatar { grid-column: 1; grid-row: 2; align-self: center; }
.h-rlabel { grid-column: 2; grid-row: 1; margin-left: 16px; }
.h-name { grid-column: 2; grid-row: 2; margin-left: 16px; }
.h-rsub { grid-column: 2; grid-row: 3; margin-left: 16px; }
.h-slabel { grid-column: 3; grid-row: 1; margin-left: 48px; }
.h-status { grid-column: 3; grid-row: 2; margin-left: 48px; }
.h-note { grid-column: 3; grid-row: 3; margin-left: 48px; }
.h-actions {
  grid-column: 5;
  grid-row: 1 / span 3;
  align-self: center;
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: 16px;
}
/* Both action buttons are 36px tall, the export menu's included. */
.h-actions :deep(.v-btn) { height: 36px; }
.h-actions :deep(.v-btn--icon) { width: 36px; }

/* Narrow: status drops under the requester on the same left edge. */
@media (max-width: 599px) {
  .detail-header { grid-template-columns: 44px 1fr auto; grid-template-rows: auto 28px auto auto 28px auto; }
  .h-actions { grid-column: 3; grid-row: 1; align-self: start; }
  .h-slabel { grid-column: 2; grid-row: 4; margin: 12px 0 0 16px; }
  .h-status { grid-column: 2; grid-row: 5; margin-left: 16px; }
  .h-note { grid-column: 2; grid-row: 6; margin-left: 16px; }
}
</style>
