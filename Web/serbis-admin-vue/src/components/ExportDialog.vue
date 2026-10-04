<!--
  ExportDialog.vue

  The customize step shared by every list and detail that prints or exports:
  columns, format, layout, photos, sort and (bulk only) a date range. It only
  gathers choices; the caller runs them. The last choice per list is remembered.
-->
<template>
  <v-dialog :model-value="modelValue" max-width="600" scrollable @update:model-value="$emit('update:modelValue', $event)">
    <v-card rounded="lg">
      <v-card-title class="d-flex justify-space-between align-center pa-6 border-b">
        <span class="text-h6 font-weight-bold">{{ heading }}</span>
        <v-btn icon="mdi-close" variant="tonal" rounded="circle" size="small" aria-label="Close" @click="$emit('update:modelValue', false)"></v-btn>
      </v-card-title>

      <v-card-text class="pa-6">
        <div class="field-label mb-2">Format</div>
        <v-btn-toggle v-model="prefs.format" mandatory color="primary" variant="outlined" density="comfortable" class="mb-5">
          <v-btn value="print" class="text-none">Print / PDF</v-btn>
          <v-btn value="csv" class="text-none">CSV</v-btn>
          <v-btn value="xlsx" class="text-none">XLSX</v-btn>
        </v-btn-toggle>

        <template v-if="isPrint && mode !== 'single'">
          <div class="field-label mb-2">Print layout</div>
          <v-btn-toggle v-model="prefs.layout" mandatory color="primary" variant="outlined" density="comfortable" class="mb-5">
            <v-btn value="table" class="text-none">Table (list)</v-btn>
            <v-btn value="detail" class="text-none">One per page</v-btn>
          </v-btn-toggle>
        </template>

        <div class="d-flex align-center justify-space-between mb-1">
          <div class="field-label">Columns</div>
          <div>
            <v-btn size="x-small" variant="outlined" color="primary" class="text-none" @click="prefs.columns = fields.map((x) => x.key)">All</v-btn>
            <v-btn size="x-small" variant="outlined" color="primary" class="text-none" @click="prefs.columns = defaultColumns(type)">Defaults</v-btn>
          </div>
        </div>
        <v-row density="compact" class="mb-2">
          <v-col v-for="x in fields" :key="x.key" cols="12" sm="6">
            <v-checkbox v-model="prefs.columns" :value="x.key" :label="x.label" density="compact" hide-details></v-checkbox>
          </v-col>
        </v-row>
        <div v-if="!prefs.columns.length" class="text-caption text-error mb-3">Pick at least one column.</div>

        <v-select
          v-model="prefs.sort"
          :items="SORT_OPTIONS"
          label="Sort order"
          variant="outlined"
          density="compact"
          hide-details
          class="mt-3 mb-4"
        ></v-select>

        <v-switch
          v-if="isPrint && (mode === 'single' || prefs.layout === 'detail')"
          v-model="prefs.photos"
          label="Include photos (IDs, landmark and handover photos)"
          color="primary"
          density="compact"
          hide-details
          class="mb-3"
        ></v-switch>

        <template v-if="mode === 'all'">
          <div class="field-label mb-2">Date range <span class="text-medium-emphasis">({{ rangeBasis }})</span></div>
          <div class="d-flex gap-3">
            <v-text-field v-model="from" type="date" label="From" variant="outlined" density="compact" hide-details></v-text-field>
            <v-text-field v-model="to" type="date" label="To" variant="outlined" density="compact" hide-details></v-text-field>
          </div>
        </template>
      </v-card-text>

      <v-card-actions class="pa-6 pt-0 justify-end gap-2">
        <v-btn variant="outlined" color="primary" class="text-none" @click="$emit('update:modelValue', false)">Cancel</v-btn>
        <v-btn color="primary" variant="flat" class="text-none font-weight-bold" :disabled="!prefs.columns.length" @click="confirm">
          {{ isPrint ? 'Print' : 'Export' }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { defaultColumns, EXPORT_TYPES, SORT_OPTIONS } from '@/composables/requestFields'
import { loadPrefs, savePrefs } from '@/composables/exportPrefs'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  // Key into EXPORT_TYPES.
  type: { type: String, required: true },
  // single: one record; selected: ticked rows; all: everything matching the current filters.
  mode: { type: String, default: 'all' },
  count: { type: Number, default: 0 },
  // The button that opened it: start on Print, or on the last file format.
  action: { type: String, default: 'export' },
})
const emit = defineEmits(['update:modelValue', 'confirm'])

const fields = computed(() => EXPORT_TYPES[props.type].fields)
const prefs = reactive(loadPrefs(props.type))
const from = ref('')
const to = ref('')

const isPrint = computed(() => prefs.format === 'print')
const rangeBasis = computed(() => (props.type === 'booking' ? 'scheduled date' : props.type === 'trip' ? 'departure time' : 'date submitted'))
const heading = computed(() => {
  const { title, noun } = EXPORT_TYPES[props.type]

  return props.mode === 'single' ? `Print or export this ${noun}` : `${title}: ${props.count} ${props.mode === 'selected' ? 'selected' : 'matching'}`
})

// Re-read on every open: the type can change under a reused dialog, and the
// last choice may have been saved by another tab.
watch(() => props.modelValue, (open) => {
  if (!open) { return }
  Object.assign(prefs, loadPrefs(props.type))
  if (props.action === 'print') { prefs.format = 'print' } else if (prefs.format === 'print') { prefs.format = 'xlsx' }
  from.value = ''
  to.value = ''
})

const confirm = () => {
  const { columns, format, layout, photos, sort } = prefs
  savePrefs(props.type, { columns: [...columns], format, layout, photos, sort })
  emit('confirm', {
    columns: [...columns],
    format,
    // A single record is always the one-per-page layout.
    layout: props.mode === 'single' ? 'detail' : layout,
    photos: format === 'print' && photos,
    sort,
    from: props.mode === 'all' ? from.value : '',
    to: props.mode === 'all' ? to.value : '',
  })
  emit('update:modelValue', false)
}
</script>

<style scoped>
.field-label {
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), 0.6);
}
</style>
