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
    <!-- `icon` or `square` swaps the round avatar for a rounded-square tile (a
         vehicle, an item): an icon, or the initials as a short text mark. -->
    <div v-if="icon || square" class="icon-tile mr-2 flex-shrink-0" :class="{ 'icon-tile--tinted': tinted }" :style="{ width: `${size}px`, height: `${size}px` }">
      <v-icon v-if="icon" :size="Math.round(Number(size) * 0.55)" :class="tinted ? 'text-primary-strong' : 'text-medium-emphasis'">{{ icon }}</v-icon>
      <span v-else class="icon-tile__mark" :class="tinted ? 'text-primary-strong' : 'text-medium-emphasis'">{{ initials }}</span>
    </div>
    <v-avatar v-else color="primary" variant="tonal" :size="size" class="mr-2 flex-shrink-0">
      <v-img v-if="photo" :src="photo" :alt="name" cover></v-img>
      <span v-else class="font-weight-bold text-caption" :class="{ 'text-primary-strong': tinted }">{{ initials }}</span>
    </v-avatar>
    <div class="min-width-0">
      <div class="d-flex align-center">
        <div class="text-body-2 font-weight-bold text-truncate">{{ name }}</div>
        <slot name="badge" />
      </div>
      <div v-if="secondary" class="text-caption text-medium-emphasis text-truncate">{{ secondary }}</div>
    </div>
  </div>
</template>

<script setup lang="ts">
withDefaults(
  defineProps<{
    name: string
    secondary?: string | null
    initials?: string
    icon?: string | null
    /** A picture in place of the initials, when the person has one. */
    photo?: string | null
    /** Icon tile in the brand tint instead of neutral (one tone, never per type). */
    tinted?: boolean
    /** Rounded-square tile showing `initials` as a text mark, no icon. */
    square?: boolean
    size?: number | string
  }>(),
  { size: 32, secondary: null, initials: '', icon: null, photo: null, tinted: false, square: false },
)
</script>

<style scoped>
.min-width-0 { min-width: 0; }
.icon-tile {
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  background: rgba(var(--v-theme-on-surface), 0.06);
}
.icon-tile--tinted { background: rgba(var(--v-theme-primary), 0.12); }
.icon-tile__mark { font-size: 11px; font-weight: 700; letter-spacing: 0.02em; }
</style>
