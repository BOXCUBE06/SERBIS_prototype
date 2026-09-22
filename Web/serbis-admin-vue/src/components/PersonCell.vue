<!--
  PersonCell.vue

  Avatar + name + secondary line, in one cell — the shape Equipment
  Borrowing's avatar column and ResidentRequestQueue's/AmbulanceRequestQueue's
  requester column each had half of (an avatar+name pairing with no secondary line under it,
  secondary info split into its own column instead). Trip Logs' patient
  cell (name + contact number stacked) is the closest existing match to
  this full shape.
-->
<template>
  <div class="person-cell d-flex align-center min-width-0">
    <v-avatar color="primary" variant="tonal" :size="size" class="mr-2 flex-shrink-0">
      <span class="font-weight-bold text-caption">{{ initials }}</span>
    </v-avatar>
    <div class="min-width-0">
      <div class="text-body-2 font-weight-bold text-truncate">{{ name }}</div>
      <div v-if="secondary" class="text-caption text-medium-emphasis text-truncate">{{ secondary }}</div>
    </div>
  </div>
</template>

<script setup lang="ts">
withDefaults(
  defineProps<{
    name: string
    secondary?: string | null
    initials: string
    size?: number | string
  }>(),
  { size: 32, secondary: null },
)
</script>

<style scoped>
.min-width-0 { min-width: 0; }
</style>
