<!--
  SegmentedTabs.vue

  One connected control — shared border, rounded OUTER corners only (the
  wrapper's own border-radius + overflow:hidden clips every inner divider
  square, so only the two end segments read as rounded) — with the active
  segment filled solid in the SERBIS accent, or on a status filter a tint of
  that status's pill colour (statusPill.ts's tabAccent). Replaces three
  independent status-filter controls: ServiceRequestQueue's v-chip-group,
  ConductionRequestView's plain status v-select, and EquipmentBorrowingView's
  outer v-tabs + inner stat-tile strip.

  Every item renders always, including a zero-count one — hiding an empty
  status used to read as "nothing of this kind exists" rather than "nothing
  right now" (the same reasoning ServiceRequestQueue's chip row already
  followed).
-->
<template>
  <div class="segmented-tabs" :class="{ 'segmented-tabs--tonal': tonal, 'segmented-tabs--switch': switchStyle, 'segmented-tabs--dense': dense }" role="tablist">
    <button
      v-for="item in items"
      :key="item.value"
      type="button"
      role="tab"
      :class="[
        'segmented-tabs__seg',
        {
          'segmented-tabs__seg--active': item.value === modelValue,
          'segmented-tabs__seg--status': accentOf(item),
        },
      ]"
      :style="accentOf(item) ? { '--tab-accent': accentOf(item) } : undefined"
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
import { tabAccent } from '@/composables/statusPill'

export interface SegmentedTabItem {
  value: string | number
  label: string
  count?: number
}

const props = defineProps<{
  modelValue: string | number
  items: SegmentedTabItem[]
  /** First load: counts are placeholders, never a misleading 0. */
  loading?: boolean
  /** The selected segment as a soft tint instead of a solid fill. */
  tonal?: boolean
  /** A page-level switch: grey track, white pill on the active segment. */
  switchStyle?: boolean
  /** The slimmer toggle inside a card (7px 14px). */
  dense?: boolean
}>()

defineEmits<{ (e: 'update:modelValue', value: string | number): void }>()

// The switch style is page navigation, never a status filter.
const accentOf = (item: SegmentedTabItem): string | undefined =>
  (props.switchStyle ? null : tabAccent(item.value)) ?? undefined
</script>

<style scoped>
.segmented-tabs {
  display: inline-flex;
  /* Sized to its tabs even inside a flex column, which would stretch it. */
  align-self: flex-start;
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


/* No ring after a pointer click; the keyboard ring below stays. */
.segmented-tabs__seg:focus:not(:focus-visible) { outline: none; }
.segmented-tabs__seg:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}
.segmented-tabs__seg:hover:not(.segmented-tabs__seg--active) {
  background: rgba(var(--v-theme-on-surface), 0.05);
}
.segmented-tabs__seg--active {
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
}
.segmented-tabs__seg--active .skel {
  --skel-bg: rgba(var(--v-theme-on-primary), 0.3);
}
/* Tonal: primary-strong on a primary tint keeps AA in both themes. */
.segmented-tabs--tonal .segmented-tabs__seg--active {
  background: rgba(var(--v-theme-primary), 0.14);
  color: rgb(var(--v-theme-primary-strong));
}
/* A selected status tab: StatusPill's own tint formula, so the tab matches the
   pills below it in both themes. Worst case is Booked in dark theme, 4.71:1;
   the count drops its 0.85 opacity here because at 0.85 that one fails AA. */
.segmented-tabs__seg--status.segmented-tabs__seg--active {
  background: color-mix(in srgb, var(--tab-accent) 16%, rgb(var(--v-theme-surface)));
  color: color-mix(in srgb, var(--tab-accent) 60%, rgb(var(--v-theme-on-surface)));
}
.segmented-tabs__seg--status.segmented-tabs__seg--active .segmented-tabs__count { opacity: 1; }
.segmented-tabs__seg--status.segmented-tabs__seg--active .skel {
  --skel-bg: color-mix(in srgb, var(--tab-accent) 30%, transparent);
}
.segmented-tabs__seg--status:hover:not(.segmented-tabs__seg--active) {
  background: color-mix(in srgb, var(--tab-accent) 6%, rgb(var(--v-theme-surface)));
}
/* Switch (canvas boards): 3px track padding, 2px gap, 11px / 8px radii. */
.segmented-tabs--switch {
  gap: 2px;
  padding: 3px;
  border: none;
  border-radius: 11px;
  background: rgba(var(--v-theme-on-surface), 0.06);
  overflow: visible;
}
.segmented-tabs--switch .segmented-tabs__seg {
  padding: 8px 18px;
  border: none;
  border-radius: 8px;
  font-size: 0.875rem;
  color: rgba(var(--v-theme-on-surface), 0.6);
}
.segmented-tabs--switch .segmented-tabs__seg:hover:not(.segmented-tabs__seg--active) { background: transparent; }
.segmented-tabs--switch .segmented-tabs__seg--active {
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-primary-strong));
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.14);
}
.segmented-tabs--dense .segmented-tabs__seg { padding: 7px 14px; }
.segmented-tabs__count {
  font-weight: 800;
  opacity: 0.85;
}

@media (prefers-reduced-motion: reduce) {
  .segmented-tabs__seg { transition: none; }
}
</style>
