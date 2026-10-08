<!--
  SubmittedCell.vue

  A list's request-time cell: when it was filed, and how long ago. Shared by
  Resident Requests, Ambulance bookings and Equipment Borrowing so the column
  reads the same everywhere.
-->
<template>
  <template v-if="submittedAt(at)">
    <div class="tabular-nums text-no-wrap">{{ submittedAt(at) }}</div>
    <div class="text-caption text-medium-emphasis text-no-wrap">{{ timeAgo(at) }}</div>
  </template>
  <span v-else class="text-medium-emphasis">Not recorded</span>
</template>

<script setup lang="ts">
import { submittedAt, timeAgo } from '@/composables/submittedTime'

defineProps<{ at?: string | null }>()
</script>

<style scoped>
.tabular-nums { font-variant-numeric: tabular-nums; }
</style>
