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
    :class="{ 'tint-pill--sm': small }"
    :style="{ '--pill-accent': accent }"
  >
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
}>()

const accent = computed(() => pillAccent(props.status))
</script>

<style scoped>
/* Text is the accent darkened 25%: the plain accent on its own 12% tint
   drops to ~4.3:1 for the amber and fails AA at this size. */
.tint-pill {
  display: inline-flex;
  align-items: center;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
  line-height: 1.25;
  white-space: nowrap;
  background: color-mix(in srgb, var(--pill-accent) 12%, rgb(var(--v-theme-surface)));
  color: color-mix(in srgb, var(--pill-accent) 75%, #000);
}
.tint-pill--sm {
  padding: 1px 8px;
  font-size: 0.6875rem;
}
/* On a dark surface the darkened text would vanish; lighten it instead. */
:global(.v-theme--dark) .tint-pill {
  background: color-mix(in srgb, var(--pill-accent) 22%, rgb(var(--v-theme-surface)));
  color: color-mix(in srgb, var(--pill-accent) 45%, #fff);
}
</style>
