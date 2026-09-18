<!--
  SegmentedTabs.vue

  One connected control — shared border, rounded OUTER corners only (the
  wrapper's own border-radius + overflow:hidden clips every inner divider
  square, so only the two end segments read as rounded) — with the active
  segment filled solid in the SERBIS accent. Replaces three independent
  status-filter controls: ServiceRequestQueue's v-chip-group,
  ConductionRequestView's plain status v-select, and EquipmentBorrowingView's
  outer v-tabs + inner stat-tile strip.

  Every item renders always, including a zero-count one — hiding an empty
  status used to read as "nothing of this kind exists" rather than "nothing
  right now" (the same reasoning ServiceRequestQueue's chip row already
  followed).
-->
<template>
  <div class="segmented-tabs" role="tablist">
    <button
      v-for="item in items"
      :key="item.value"
      type="button"
      role="tab"
      class="segmented-tabs__seg"
      :class="{ 'segmented-tabs__seg--active': item.value === modelValue }"
      :aria-selected="item.value === modelValue"
      @click="$emit('update:modelValue', item.value)"
    >
      {{ item.label }}
      <span v-if="item.count !== undefined" class="segmented-tabs__count">{{ item.count }}</span>
    </button>
  </div>
</template>

<script setup lang="ts">
export interface SegmentedTabItem {
  value: string | number
  label: string
  count?: number
}

defineProps<{
  modelValue: string | number
  items: SegmentedTabItem[]
}>()

defineEmits<{ (e: 'update:modelValue', value: string | number): void }>()
</script>

<style scoped>
.segmented-tabs {
  display: inline-flex;
  max-width: 100%;
  overflow-x: auto;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.14);
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
}
.segmented-tabs__seg {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 9px 16px;
  font-size: 0.8125rem;
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), 0.7);
  background: transparent;
  border: none;
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.14);
  cursor: pointer;
  white-space: nowrap;
  transition: background-color 150ms ease, color 150ms ease;
}
.segmented-tabs__seg:last-child {
  border-right: none;
}
.segmented-tabs__seg:hover:not(.segmented-tabs__seg--active) {
  background: rgba(var(--v-theme-on-surface), 0.05);
}
.segmented-tabs__seg--active {
  background: rgb(var(--v-theme-primary));
  color: #fff;
}
.segmented-tabs__count {
  font-weight: 800;
  opacity: 0.85;
}
.segmented-tabs__seg:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}
@media (prefers-reduced-motion: reduce) {
  .segmented-tabs__seg { transition: none; }
}
</style>
