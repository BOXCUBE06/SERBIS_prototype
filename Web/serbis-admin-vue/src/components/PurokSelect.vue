<!--
  Purok picker: the listed puroks plus a free-text "Other" (MDRRMO feedback,
  2026-09-19). The same list and behaviour as the mobile app's PurokField. The
  value is plain text, so PUROK_OPTIONS can change without a migration; a saved
  value that is not on the list opens as Other with its text kept.

  Reads the value once, when it mounts: a form that is reused between residents
  must give this a new :key so it starts from the resident now open.
-->
<template>
  <div>
    <v-select
      :model-value="choice"
      :items="CHOICES"
      label="Street / Purok (optional)"
      variant="outlined"
      density="comfortable"
      rounded="lg"
      :error-messages="errorMessages"
      @update:model-value="onChoice"
    ></v-select>

    <v-text-field
      v-if="typing"
      :model-value="modelValue"
      label="Street / purok"
      placeholder="e.g. Zone 2, Sitio Malaki"
      variant="outlined"
      density="comfortable"
      rounded="lg"
      class="mt-1"
      @update:model-value="(value: string) => emit('update:modelValue', value ?? '')"
    ></v-text-field>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'

const PUROK_OPTIONS = ['Purok 1', 'Purok 2', 'Purok 3', 'Purok 4', 'Purok 5', 'Purok 6']
const NOT_SPECIFIED = 'Not specified'
const OTHER = 'Other (type it in)'
const CHOICES = [NOT_SPECIFIED, ...PUROK_OPTIONS, OTHER]

const props = defineProps<{
  modelValue: string
  errorMessages?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const isListed = (value: string) => PUROK_OPTIONS.includes(value.trim())

// Whether "Other" is chosen. Starts true for a saved value that is not on the
// list, so an address typed before this picker existed is shown, not lost.
const typing = ref(props.modelValue.trim() !== '' && !isListed(props.modelValue))

const choice = computed(() => {
  if (typing.value) return OTHER
  return isListed(props.modelValue) ? props.modelValue.trim() : NOT_SPECIFIED
})

const onChoice = (next: string) => {
  if (next === OTHER) {
    // Start the box empty rather than leaving "Purok 3" in it to edit.
    if (isListed(props.modelValue)) emit('update:modelValue', '')
    typing.value = true
    return
  }

  typing.value = false
  emit('update:modelValue', next === NOT_SPECIFIED ? '' : next)
}
</script>
