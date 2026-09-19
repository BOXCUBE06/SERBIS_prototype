<!--
  Free-text purok/street field with type-ahead suggestions (MDRRMO feedback,
  2026-09-19) — the admin-panel half of the same idea the mobile app's
  PurokAutocompleteField implements. No seed data: suggestions are whatever
  street_address values residents of the selected barangay have already
  entered, via GET /barangays/{id}/puroks. A combobox, not a select: the
  value is always free text, the list is only ever a shortcut for retyping
  what someone else already did.
-->
<template>
  <v-combobox
    :model-value="modelValue"
    @update:model-value="(value) => emit('update:modelValue', value ?? '')"
    :items="suggestions"
    :loading="loading"
    label="Street / Purok (optional)"
    placeholder="e.g. Purok 3"
    variant="outlined"
    density="comfortable"
    rounded="lg"
    :error-messages="errorMessages"
    clearable
  ></v-combobox>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { authHeaders } from '@/composables/adminUi'
import { API_BASE } from '@/config/api'

const props = defineProps<{
  modelValue: string
  barangayId: number | null
  errorMessages?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const suggestions = ref<string[]>([])
const loading = ref(false)

// Re-fetched whenever the barangay changes — the old barangay's suggestions
// are somebody else's purok and must not linger as an option for the new
// one. `immediate` covers the edit form, which opens with a barangay already
// picked.
watch(
  () => props.barangayId,
  async (barangayId) => {
    suggestions.value = []
    if (!barangayId) return

    loading.value = true
    try {
      const res = await fetch(`${API_BASE}/barangays/${barangayId}/puroks`, {
        headers: authHeaders(false),
      })
      if (!res.ok) return
      const data = await res.json()
      suggestions.value = data.data ?? []
    } catch {
      // Best-effort, same as the mobile field: a failed fetch just means no
      // suggestions this time. The combobox still accepts free text.
    } finally {
      loading.value = false
    }
  },
  { immediate: true },
)
</script>
