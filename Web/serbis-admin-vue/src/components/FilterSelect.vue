<!--
  FilterSelect.vue

  One labelled select for the 36px filter bar (styles/filter-bar.css): the name
  is the field's prefix, since a floating label does not fit 36px. Vehicles,
  Responders and Procurement use it in DataTablePage's `filters` slot.
-->
<template>
  <v-select
    :model-value="modelValue"
    @update:model-value="$emit('update:modelValue', $event)"
    :items="items"
    :prefix="label"
    :aria-label="label"
    variant="outlined"
    density="compact"
    hide-details
    rounded="lg"
    class="filter-bar__select"
  ></v-select>
</template>

<script setup lang="ts">
defineProps<{
  modelValue: string
  items: (string | { title: string; value: string })[]
  label: string
}>()
defineEmits<{ (e: 'update:modelValue', value: string): void }>()
</script>
