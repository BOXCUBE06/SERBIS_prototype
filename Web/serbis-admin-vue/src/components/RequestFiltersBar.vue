<!--
  Barangay and Unit selects for the request lists. `compact` is the 36px
  filter bar (styles/filter-bar.css): the name moves into the field as a
  prefix, since a floating label does not fit 36px. The status filter is the
  tab strip above, so there is no Status select.
-->
<template>
  <v-select
    :model-value="barangay"
    @update:model-value="$emit('update:barangay', $event)"
    :items="barangayOptions"
    v-bind="field('Barangay')"
  ></v-select>
  <v-select
    :model-value="unit"
    @update:model-value="$emit('update:unit', $event)"
    :items="unitOptions"
    v-bind="field('Unit')"
  ></v-select>
</template>

<script setup>
const props = defineProps({
  barangay: { type: String, required: true },
  unit: { type: String, required: true },
  barangayOptions: { type: Array, required: true },
  unitOptions: { type: Array, required: true },
  compact: { type: Boolean, default: false },
})
defineEmits(['update:barangay', 'update:unit'])

const field = (name) => ({
  variant: 'outlined',
  density: 'compact',
  hideDetails: true,
  rounded: 'lg',
  'aria-label': name,
  ...(props.compact ? { prefix: name, class: 'filter-bar__select' } : { label: name, class: 'filter-field' }),
})
</script>
