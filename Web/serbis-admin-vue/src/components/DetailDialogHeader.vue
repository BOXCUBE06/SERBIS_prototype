<!--
  DetailDialogHeader.vue

  Shared header for the request/booking/borrowing detail dialogs: avatar,
  labelled requester, labelled status, then actions and close. One layout so
  the dialogs cannot drift apart again.

  Four direct grid children. Requester and Status are stacks on the same
  three row sizes (label / value / secondary), so their lines align.
-->
<template>
  <div class="detail-header">
    <v-avatar color="primary" variant="tonal" size="44" class="h-avatar">
      <span class="text-subtitle-1 font-weight-black">{{ initials }}</span>
    </v-avatar>

    <div class="h-stack h-requester">
      <div class="header-label">Requester</div>
      <div class="h-value header-name text-truncate">{{ name }}</div>
      <div class="h-sub text-medium-emphasis text-truncate">{{ secondary }}</div>
    </div>

    <div class="h-stack h-statusbox">
      <div class="header-label">Status</div>
      <div class="h-value"><slot name="status"></slot></div>
      <div class="h-sub" :class="waitDays == null ? '' : WAIT_CLASS[waitTone(waitDays)]">
        <template v-if="waitDays != null">{{ waitDays }}d waiting</template>
      </div>
    </div>

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
  grid-template-columns: 44px auto auto 1fr;
  padding: 20px 24px;
  flex-shrink: 0;
}
.h-avatar { grid-column: 1; margin-top: 20px; }  /* label row 16 + value row 28 centre */
.h-requester { grid-column: 2; margin-left: 16px; }
.h-statusbox { grid-column: 3; margin-left: 48px; }
.h-actions {
  grid-column: 4;
  justify-self: end;
  align-self: center;
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: 16px;
}
.h-stack {
  display: grid;
  grid-template-rows: 16px 28px 20px;
  min-width: 0;
}
.header-label {
  font-size: 12px;
  font-weight: 700;
  line-height: 16px;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.header-name { font-size: 18px; font-weight: 600; line-height: 28px; }
.h-value { display: flex; align-items: center; min-width: 0; }
.h-sub { font-size: 14px; line-height: 20px; min-width: 0; }
/* Both action buttons are 36px tall, the export menu's included. */
.h-actions :deep(.v-btn) { height: 36px; }
.h-actions :deep(.v-btn--icon) { width: 36px; }

/* Narrow: status drops under the requester on the same left edge. */
@media (max-width: 599px) {
  .detail-header { grid-template-columns: 44px 1fr auto; }
  .h-actions { grid-column: 3; grid-row: 1; align-self: start; margin-left: 8px; }
  .h-requester { grid-row: 1; }
  .h-statusbox { grid-column: 2; grid-row: 2; margin: 12px 0 0 16px; }
  .h-avatar { grid-row: 1; }
}
</style>
