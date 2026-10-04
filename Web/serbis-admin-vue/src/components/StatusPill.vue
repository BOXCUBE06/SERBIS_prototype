<!--
  StatusPill.vue

  Small, fully-rounded status badge used by every list/detail view. A light
  tint of the status accent with darkened accent text, normal case — the
  earlier solid uppercase fill read heavier than the data around it. Color
  comes from composables/statusPill.ts's shared accent table.
-->
<template>
  <span
    class="tint-pill"
    :class="{ 'tint-pill--sm': small, 'tint-pill--solid': solid, 'tint-pill--tag': tag, 'tint-pill--outline': outline }"
    :style="{ '--pill-accent': accent }"
  >
    <span v-if="dot" class="tint-pill__dot"></span>
    <v-icon v-if="icon" start :size="small ? 12 : 14">{{ icon }}</v-icon>
    <slot>{{ label ?? status }}</slot>
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { pillAccent } from '@/composables/statusPill'

const props = defineProps<{
  status?: string | null
  label?: string | null
  icon?: string | null
  small?: boolean
  /** Filled with the accent and white text, for tables read at a glance. */
  solid?: boolean
  /** A 6px dot before the label, for the pill in a dialog header. */
  dot?: boolean
  /** The neutral slate tag (account type, counts): not a state, so no accent. */
  tag?: boolean
  /** A zero count: no tint, a hairline outline. */
  outline?: boolean
}>()

const accent = computed(() => pillAccent(props.status))
</script>

<style scoped>
/* Text is the accent pulled 40% toward the theme's own text color: darker on
   light surfaces, lighter on dark ones, from one rule. The plain accent on its
   own tint fails AA for amber in light theme and vanishes in dark. (A
   `:global(.v-theme--dark)` override was tried; Vue's scoped compiler drops
   everything after `:global(...)`, so it never matched.) */
.tint-pill {
  display: inline-flex;
  align-items: center;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
  line-height: 1.25;
  white-space: nowrap;
  background: color-mix(in srgb, var(--pill-accent) 16%, rgb(var(--v-theme-surface)));
  color: color-mix(in srgb, var(--pill-accent) 60%, rgb(var(--v-theme-on-surface)));
}
.tint-pill--solid {
  background: var(--pill-accent);
  color: #fff;
}
.tint-pill__dot {
  width: 6px;
  height: 6px;
  margin-right: 6px;
  border-radius: 50%;
  background: currentColor;
}
.tint-pill--tag {
  padding: 2px 10px;
  background: rgba(71, 85, 105, 0.12);
  color: #334155;
}
.v-theme--dark .tint-pill--tag { color: rgba(255, 255, 255, 0.82); }
.tint-pill--outline {
  background: transparent;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.14);
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.tint-pill--sm {
  padding: 1px 8px;
  font-size: 0.6875rem;
}
</style>
