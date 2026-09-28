<!--
  StatusChip.vue

  The one status badge for the resource lists: a 24px tonal pill, 12px medium
  text, no outline. Read-only it is just the pill; with `items` it is the same
  pill as a button that opens a menu of the other statuses (the caller confirms
  and saves, see `select`). The chevron shows on hover and keyboard focus only
  (always on touch, which has no hover). Colours come from statusPill.ts.
-->
<template>
  <v-menu v-if="items" location="bottom">
    <template v-slot:activator="{ props: menu }">
      <!-- click.stop: a row click opens Edit, opening this menu must not. -->
      <button type="button" class="status-chip status-chip--button" v-bind="menu" :aria-label="ariaLabel" @click.stop>
        <StatusPill small :status="status" :label="label" />
        <v-icon size="14" class="status-chip__chevron text-medium-emphasis">mdi-chevron-down</v-icon>
      </button>
    </template>
    <v-list density="compact" rounded="lg">
      <v-list-item
        v-for="item in items"
        :key="item.value"
        :disabled="item.value === current"
        @click="emit('select', item.value)"
      >
        <template v-slot:prepend><span class="menu-dot" :style="{ background: pillAccent(item.status) }"></span></template>
        <v-list-item-title>{{ item.label }}</v-list-item-title>
      </v-list-item>
    </v-list>
  </v-menu>
  <span v-else class="status-chip"><StatusPill small :status="status" :label="label" /></span>
</template>

<script setup lang="ts">
import StatusPill from '@/components/StatusPill.vue'
import { pillAccent } from '@/composables/statusPill'

export interface StatusChoice {
  value: string
  label: string
  /** Pill key (statusPill.ts) that colours this choice. */
  status: string
}

defineProps<{
  /** Pill key (statusPill.ts). */
  status: string
  label?: string
  /** With these, the chip opens a change menu. */
  items?: StatusChoice[]
  /** The choice currently in effect, disabled in the menu. */
  current?: string
  ariaLabel?: string
}>()
const emit = defineEmits<{ select: [value: string] }>()
</script>

<style scoped>
.status-chip {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  border-radius: 999px;
}
/* A <button> brings the browser's own face, border and padding; without this
   it draws a grey capsule around the pill. */
.status-chip--button {
  cursor: pointer;
  appearance: none;
  background: none;
  border: 0;
  padding: 0;
  margin: 0;
  font: inherit;
  color: inherit;
}
.status-chip--button:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 2px; }
.status-chip :deep(.tint-pill--sm) {
  height: 24px;
  padding: 0 8px;
  font-size: 0.75rem;
  font-weight: 500;
}
/* Space stays reserved, so the row does not shift when the chevron appears. */
.status-chip__chevron { opacity: 0; transition: opacity var(--motion-fast) var(--ease-out); }
.status-chip--button:hover .status-chip__chevron,
.status-chip--button:focus-visible .status-chip__chevron { opacity: 1; }
@media (hover: none) { .status-chip__chevron { opacity: 1; } }
.menu-dot { width: 8px; height: 8px; border-radius: 50%; margin-right: 12px; flex: none; }
</style>
