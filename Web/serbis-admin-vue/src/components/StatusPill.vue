<!--
  StatusPill.vue

  Small, solid-fill, fully-rounded status badge — the one pill rendering
  used by every list/detail view (ServiceRequestQueue, ConductionRequestView,
  EquipmentBorrowingView), replacing three independent renderings: the
  tint-background .status-pill/.pill-* CSS classes, EquipmentBorrowingView's
  own inline-styled v-chip, and (nowhere yet, but the same shape) any future
  one-off. Color comes from composables/statusPill.ts's shared accent table,
  never a local palette.
-->
<template>
  <span
    class="status-pill-solid"
    :class="{ 'status-pill-solid--sm': small }"
    :style="{ backgroundColor: accent }"
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
.status-pill-solid {
  display: inline-flex;
  align-items: center;
  padding: 5px 12px;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  white-space: nowrap;
  color: #fff;
}
.status-pill-solid--sm {
  padding: 2px 10px;
  font-size: 0.6875rem;
  letter-spacing: 0.04em;
}
</style>
