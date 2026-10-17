<!--
  A crew member's name, picked in a dialog in the middle of the screen instead
  of a dropdown under the field. `items` are {title, value, position?} with
  title = value = the name; only the name is stored (crew rows are free text
  server-side). A driver is a strict pick from the list. With `allow-custom`
  (passengers) a typed name that matches nobody can be used as it is, for
  mutual aid from another LGU or a passenger who is not a responder.
-->
<template>
  <v-dialog v-model="open" max-width="460">
    <template v-slot:activator="{ props: dialogProps }">
      <v-text-field
        v-bind="{ ...$attrs, ...dialogProps }"
        :model-value="modelValue"
        :placeholder="placeholder"
        readonly
        variant="outlined"
        density="compact"
        append-inner-icon="mdi-account-search"
      ></v-text-field>
    </template>

    <v-card rounded="lg" class="pa-3 crew-card">
      <div class="crew-title">{{ title }}</div>
      <v-text-field
        v-model="search"
        autofocus
        clearable
        hide-details
        variant="outlined"
        density="comfortable"
        prepend-inner-icon="mdi-magnify"
        :placeholder="allowCustom ? 'Search or type a name' : 'Search by name'"
        class="mb-2"
        @keydown.enter.prevent="onEnter"
      ></v-text-field>

      <v-list class="crew-list" density="comfortable">
        <v-list-item v-if="customName" :title="`Use “${customName}”`" subtitle="Not on the responder list" prepend-icon="mdi-plus" @click="pick(customName)"></v-list-item>
        <v-list-item
          v-for="item in shown"
          :key="item.value"
          :title="item.title"
          :subtitle="item.position"
          :active="item.value === modelValue"
          color="primary"
          @click="pick(item.value)"
        ></v-list-item>
        <div v-if="shown.length === 0 && !customName" class="crew-empty">No one matches.</div>
      </v-list>

      <v-card-actions>
        <v-btn variant="outlined" color="primary" size="small" class="text-none" :disabled="!modelValue" @click="pick('')">Clear</v-btn>
        <v-spacer></v-spacer>
        <v-btn variant="outlined" color="primary" size="small" class="text-none" @click="open = false">Cancel</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

defineOptions({ inheritAttrs: false })

const props = defineProps({
  modelValue: { type: String, default: '' },
  items: { type: Array, default: () => [] },
  allowCustom: { type: Boolean, default: false },
  placeholder: { type: String, default: 'Select a responder' },
  title: { type: String, default: 'Select a responder' },
  // What clearing writes: the form keeps an empty driver slot as null, not ''.
  emptyValue: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const search = ref('')

// Reopening starts from a clean search, not whatever was typed last time.
watch(open, (isOpen) => { if (isOpen) search.value = '' })

const needle = computed(() => (search.value || '').trim().toLowerCase())
const shown = computed(() => props.items.filter((i) => i.title.toLowerCase().includes(needle.value)))
const customName = computed(() => {
  const typed = (search.value || '').trim()
  if (!props.allowCustom || !typed) return ''
  return props.items.some((i) => i.title.toLowerCase() === typed.toLowerCase()) ? '' : typed
})

const pick = (value) => {
  emit('update:modelValue', value || props.emptyValue)
  open.value = false
}

// Enter takes the only match, or the typed name when custom names are allowed.
const onEnter = () => {
  if (customName.value) return pick(customName.value)
  if (shown.value.length === 1) pick(shown.value[0].value)
}
</script>

<style scoped>
.crew-title {
  padding: 4px 8px 12px;
  font-size: 17px;
  font-weight: 700;
}

.crew-list {
  max-height: min(50vh, 360px);
  overflow-y: auto;
}

.crew-empty {
  padding: 16px;
  text-align: center;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
</style>
