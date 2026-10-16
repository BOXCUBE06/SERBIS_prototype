<!--
  Typable, multi-select barangay field. v-model is an array of item values;
  [] means every barangay, so there is no "All" entry. Shows the first
  `maxChips` as closable chips and the rest as "+N more", so the field keeps its
  width. Anything else (placeholder, loading, rules, error-messages, class,
  item-title/item-value) falls through to the v-autocomplete underneath.
-->
<template>
  <v-autocomplete
    :model-value="modelValue"
    @update:model-value="emit('update:modelValue', $event)"
    multiple
    chips
    closable-chips
    clearable
    placeholder="All barangays"
    persistent-placeholder
    no-data-text="No barangay matches"
    aria-label="Barangay"
    variant="outlined"
    density="compact"
    hide-details
    rounded="lg"
    class="barangay-filter"
  >
    <template #selection="{ item, index }">
      <v-chip
        v-if="index < maxChips"
        size="small"
        closable
        class="barangay-filter__chip"
        @click:close="emit('update:modelValue', modelValue.filter((_, i) => i !== index))"
        @mousedown.stop
      >{{ item.title }}</v-chip>
      <v-chip
        v-else-if="index === maxChips"
        size="small"
        class="barangay-filter__chip barangay-filter__chip--more"
      >+{{ modelValue.length - maxChips }} more</v-chip>
    </template>
    <template v-if="$slots['prepend-item']" #prepend-item><slot name="prepend-item" /></template>
  </v-autocomplete>
</template>

<script setup lang="ts">
withDefaults(defineProps<{ modelValue: (string | number)[]; maxChips?: number }>(), { maxChips: 2 })
const emit = defineEmits<{ (e: 'update:modelValue', value: (string | number)[]): void }>()
</script>

<style scoped>
.barangay-filter__chip {
  max-width: 150px;
  border-radius: 999px;
  background: rgba(var(--v-theme-on-surface), 0.06);
}
.barangay-filter__chip :deep(.v-chip__content) { overflow: hidden; text-overflow: ellipsis; }
.barangay-filter__chip--more {
  background: rgba(var(--v-theme-primary), 0.14);
  color: rgb(var(--v-theme-primary-strong));
  font-weight: 700;
}
</style>
