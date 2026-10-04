<!--
  The bar above a list once rows are ticked. "Clear" shows for any selection;
  the destructive action (Disapprove / Deny) only when `actionable` rows, the
  Pending ones, are in it, and its count is theirs, not the whole selection's.
-->
<template>
  <div class="d-flex align-center justify-space-between px-4 py-2 subtle-surface rounded-lg mb-3">
    <span class="text-caption font-weight-bold" aria-live="polite">{{ selected }} selected</span>
    <div class="d-flex align-center gap-2">
      <v-btn variant="outlined" height="40" class="bulk-btn bulk-btn--clear" @click="$emit('clear')">Clear</v-btn>
      <v-btn
        v-if="actionable"
        color="error"
        variant="flat"
        height="40"
        class="bulk-btn bulk-btn--action"
        :loading="loading"
        @click="$emit('action')"
      >
        {{ actionLabel }} {{ actionable }}
        <span class="d-sr-only">selected requests</span>
      </v-btn>
    </div>
  </div>
</template>

<script setup>
defineProps({
  selected: { type: Number, required: true },
  actionable: { type: Number, default: 0 },
  actionLabel: { type: String, default: 'Disapprove' },
  loading: { type: Boolean, default: false },
})
defineEmits(['clear', 'action'])
</script>

<style scoped>
.gap-2 { gap: 8px; }
.bulk-btn {
  padding: 0 16px;
  border-radius: 12px;
  font-size: 14px;
  font-weight: 700;
  letter-spacing: 0;
  text-transform: none;
}
.bulk-btn--clear {
  background: #fff;
  border: 1px solid rgba(0, 0, 0, 0.14);
  color: #1b5b4b;
}
.bulk-btn--action {
  color: #fff;
  box-shadow: 0 8px 16px -4px rgba(211, 47, 47, 0.28);
}
</style>
