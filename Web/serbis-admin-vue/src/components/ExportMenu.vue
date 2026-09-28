<!--
  ExportMenu.vue

  One print/export control for every list and detail.
    - `row`:    a single record (detail views): Print, Export.
    - `rows`:   a list's filtered rows: Print/Export the ticked rows, or
                everything matching the current filters.
    - `plain`:  no customize step, just CSV or XLSX of every column (Vehicles).
  The customize dialog does the asking; printRows/exportRows do the work.
-->
<template>
  <div class="d-inline-flex align-center gap-2">
    <v-chip v-if="showSelection && selectedRows.length" size="small" closable variant="tonal" @click:close="selectedIds.clear()">
      {{ selectedRows.length }} selected
    </v-chip>

    <v-menu location="bottom end">
      <template v-slot:activator="{ props: menu }">
        <v-btn
          v-bind="menu"
          color="primary"
          variant="outlined"
          class="text-none font-weight-bold"
          :height="height"
          :loading="busy"
          :disabled="!row && !rows.length"
        >
          <v-icon start size="small">mdi-printer-outline</v-icon>
          {{ plain ? 'Export' : 'Print / Export' }}
          <v-icon end size="small">mdi-menu-down</v-icon>
        </v-btn>
      </template>

      <v-list density="compact">
        <template v-if="plain">
          <v-list-item title="Export as CSV" @click="quick('csv')"></v-list-item>
          <v-list-item title="Export as XLSX" @click="quick('xlsx')"></v-list-item>
        </template>
        <template v-else-if="row">
          <v-list-item title="Print" prepend-icon="mdi-printer-outline" @click="open('single', 'print')"></v-list-item>
          <v-list-item title="Export" prepend-icon="mdi-tray-arrow-down" @click="open('single', 'export')"></v-list-item>
        </template>
        <template v-else>
          <v-list-item :title="`Print selected (${selectedRows.length})`" :disabled="!selectedRows.length" @click="open('selected', 'print')"></v-list-item>
          <v-list-item :title="`Export selected (${selectedRows.length})`" :disabled="!selectedRows.length" @click="open('selected', 'export')"></v-list-item>
          <v-divider></v-divider>
          <v-list-item :title="`Print all ${rows.length} matching`" @click="open('all', 'print')"></v-list-item>
          <v-list-item :title="`Export all ${rows.length} matching`" @click="open('all', 'export')"></v-list-item>
        </template>
      </v-list>
    </v-menu>

    <ExportDialog v-model="dialog.open" :type="type" :mode="dialog.mode" :action="dialog.action" :count="targetRows.length" @confirm="run" />

    <v-snackbar v-model="notice.open" :color="notice.color" timeout="4000">{{ notice.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import ExportDialog from '@/components/ExportDialog.vue'
import { EXPORT_TYPES, prepareRows } from '@/composables/requestFields'
import { logExport } from '@/composables/exportLog'
import { exportRows } from '@/composables/requestExport'
import { printRows } from '@/composables/requestPrint'

const props = defineProps({
  type: { type: String, required: true },
  row: { type: Object, default: null },
  rows: { type: Array, default: () => [] },
  selectedIds: { type: Set, default: () => new Set() },
  // Lists that have no selection bar of their own show the count here.
  showSelection: { type: Boolean, default: false },
  plain: { type: Boolean, default: false },
  height: { type: [Number, String], default: 40 },
})

const busy = ref(false)
const dialog = reactive({ open: false, mode: 'all', action: 'export' })
const notice = reactive({ open: false, text: '', color: 'error' })

const selectedRows = computed(() => props.rows.filter((r) => props.selectedIds.has(EXPORT_TYPES[props.type].idOf(r))))
const targetRows = computed(() => (dialog.mode === 'single' ? [props.row] : dialog.mode === 'selected' ? selectedRows.value : props.rows))

const open = (mode, action) => {
  dialog.mode = mode
  dialog.action = action
  dialog.open = true
}

const tell = (text, color = 'error') => Object.assign(notice, { open: true, text, color })

// The dialog's choices, applied to what it was opened for.
async function run(options, scope = dialog.mode) {
  const rows = prepareRows(props.type, targetRows.value, options)
  if (!rows.length) { return tell('Nothing to export in that date range.') }

  busy.value = true
  try {
    if (options.format === 'print') { await printRows(props.type, rows, options) } else { await exportRows(props.type, rows, options) }
    logExport(props.type, {
      action: options.format === 'print' ? 'print' : 'export',
      format: options.format,
      scope,
      count: rows.length,
      ids: rows.map((r) => EXPORT_TYPES[props.type].idOf(r)),
      from: options.from,
      to: options.to,
    })
  } catch (error) {
    tell(error.message || 'Could not prepare the file.')
  } finally {
    busy.value = false
  }
}

// No customize step: every column, current order.
const quick = (format) => run({ columns: EXPORT_TYPES[props.type].fields.map((x) => x.key), format, sort: 'none' }, 'all')
</script>
