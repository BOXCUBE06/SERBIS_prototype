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
  <div class="segmented-tabs" :class="{ 'segmented-tabs--tonal': tonal }" role="tablist">
    <button
      v-for="item in items"
      :key="item.value"
      type="button"
      role="tab"
      :class="[
        'segmented-tabs__seg',
        `segmented-tabs__seg--${String(item.value).toLowerCase()}`,
        {'segmented-tabs__seg--active': item.value === modelValue}
     ]"
      :aria-selected="item.value === modelValue"
      @click="$emit('update:modelValue', item.value)"
    >
      {{ item.label }}
      <template v-if="item.count !== undefined">
        <span v-if="loading" class="skel skel-pill" aria-hidden="true"></span>
        <span v-else class="segmented-tabs__count">{{ item.count }}</span>
      </template>
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
  /** First load: counts are placeholders, never a misleading 0. */
  loading?: boolean
  /** The selected segment as a soft tint instead of a solid fill. */
  tonal?: boolean
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
  transition: background-color var(--motion-fast) var(--ease-out), color var(--motion-fast) var(--ease-out);
}
.segmented-tabs__seg:last-child {
  border-right: none;
}


.segmented-tabs__seg:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}
.segmented-tabs__seg--pending.segmented-tabs__seg--active {
  background: rgb(var(--v-theme-warning));
  color: #fff;
}
.segmented-tabs__seg--booked.segmented-tabs__seg--active {
  background: #6D28D9;
  color: #fff;
}
.segmented-tabs__seg--responding.segmented-tabs__seg--active {
  background: rgb(var(--v-theme-info));
  color: #fff;
}
.segmented-tabs__seg--resolved.segmented-tabs__seg--active {
  background: rgb(var(--v-theme-success));
  color: #fff;
}
.segmented-tabs__seg--disapproved.segmented-tabs__seg--active {
  background: rgb(var(--v-theme-error));
  color: #fff;
}
.segmented-tabs__seg--cancelled.segmented-tabs__seg--active {
  background: rgb(var(--v-theme-secondary));
  color: #fff;
}

.segmented-tabs__seg:hover:not(.segmented-tabs__seg--active) {
  background: rgba(var(--v-theme-on-surface), 0.05);
}
.segmented-tabs__seg--active {
  background: rgb(var(--v-theme-primary));
  color: #fff;
}
.segmented-tabs__seg--active .skel {
  --skel-bg: rgba(255, 255, 255, 0.3);
}
/* Tonal: primary-strong on a primary tint keeps AA in both themes. */
.segmented-tabs--tonal .segmented-tabs__seg--active {
  background: rgba(var(--v-theme-primary), 0.14);
  color: rgb(var(--v-theme-primary-strong));
}
.segmented-tabs__count {
  font-weight: 800;
  opacity: 0.85;
}

@media (prefers-reduced-motion: reduce) {
  .segmented-tabs__seg { transition: none; }
}
</style>
