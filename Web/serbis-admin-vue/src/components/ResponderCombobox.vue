<!--
  A driver's name: pick a responder or type any name (mutual aid from another
  LGU). Only the name is stored — crew rows are free text server-side.

  v-combobox commits typed text on Enter, selection or blur; here every
  keystroke is written to the model as well, so the name is never lost to a
  Save click that beats the blur. The empty string is ignored unless the field
  is focused: the combobox resets its search to '' when it loses focus, and
  that reset must not wipe the name just typed.
-->
<template>
  <v-combobox
    :model-value="modelValue"
    :items="items"
    placeholder="Pick a responder or type a name"
    variant="outlined"
    density="compact"
    hide-details
    hide-no-data
    @update:model-value="emit('update:modelValue', $event ?? '')"
    @update:search="onSearch"
    @update:focused="focused = $event"
  ></v-combobox>
</template>

<script setup>
import { ref } from 'vue'

defineProps({
  modelValue: { type: String, default: '' },
  items: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue'])

const focused = ref(false)
const onSearch = (text) => {
  if (typeof text === 'string' && (text !== '' || focused.value)) emit('update:modelValue', text)
}
</script>
