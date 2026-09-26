<!--
  DetailDialogHeader.vue

  Shared header for the request/booking/borrowing detail dialogs: avatar,
  labelled requester, labelled status, then actions and close. One layout so
  the dialogs cannot drift apart again.
-->
<template>
  <div class="d-flex justify-space-between align-center pa-6 pb-4 flex-shrink-0">
    <div class="d-flex align-center gap-3 min-width-0">
      <v-avatar color="primary" variant="tonal" size="52" class="flex-shrink-0">
        <span class="text-h6 font-weight-black">{{ initials }}</span>
      </v-avatar>
      <div class="min-width-0">
        <div class="header-label">Requester</div>
        <div class="text-h6 font-weight-bold text-truncate header-name">{{ name }}</div>
        <div v-if="secondary" class="text-caption text-medium-emphasis text-truncate">{{ secondary }}</div>
      </div>
    </div>
    <div class="d-flex align-center gap-4 flex-shrink-0">
      <div>
        <div class="header-label">Status</div>
        <slot name="status"></slot>
        <div v-if="note" class="text-caption text-medium-emphasis mt-1">{{ note }}</div>
      </div>
      <slot name="actions"></slot>
      <v-btn icon="mdi-close" variant="text" density="comfortable" aria-label="Close" @click="$emit('close')"></v-btn>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  name: string
  initials: string
  secondary?: string | null
  /** "Nd waiting" line under the status; open requests only. */
  note?: string | null
}>()
defineEmits<{ close: [] }>()
</script>

<style scoped>
.min-width-0 { min-width: 0; }
.header-name { line-height: 1.2; }
.header-label {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
</style>
