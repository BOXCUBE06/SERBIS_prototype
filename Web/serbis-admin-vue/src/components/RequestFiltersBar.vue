<template>
  <v-select
    :model-value="barangay"
    @update:model-value="$emit('update:barangay', $event)"
    :items="barangayOptions"
    label="Barangay"
    variant="outlined"
    density="compact"
    hide-details
    rounded="lg"
    class="filter-field"
  ></v-select>
  <v-select
    :model-value="unit"
    @update:model-value="$emit('update:unit', $event)"
    :items="unitOptions"
    label="Unit"
    variant="outlined"
    density="compact"
    hide-details
    rounded="lg"
    class="filter-field"
  ></v-select>
</template>

<script setup>
defineProps({
  barangay: { type: String, required: true },
  unit: { type: String, required: true },
  barangayOptions: { type: Array, required: true },
  unitOptions: { type: Array, required: true },
})
defineEmits(['update:barangay', 'update:unit'])
</script>
