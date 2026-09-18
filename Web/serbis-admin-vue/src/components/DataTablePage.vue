<!--
  DataTablePage.vue

  The list-page shell every data table in the admin panel now shares:
  toolbar (search left, filters slot, actions slot right) + segmented
  status tabs + a fixed-layout table card + a numbered-pagination footer
  with a 10/25/50 rows-per-page selector. Built from the ui-consistency
  audit (2026-09) that found five tables — Resident Requests, Bookings,
  Trip Logs, Equipment Borrowing's Active pipeline and History — each with
  their own toolbar, footer and status-filter control, no two alike.

  Column cells stay the caller's own: every `item.*`/`no-data`/etc. slot
  Vuetify's v-data-table recognizes is forwarded straight through via the
  `v-for="(_, slotName) in $slots"` loop below, so a page defines its cell
  markup exactly as it did with a bare v-data-table.

  Deliberately NOT included: search debouncing (none of the five source
  pages had it, and this is not the place to add new behavior), a "show
  everything" rows-per-page option (dropped on purpose — see the commit
  this shipped in), and any table-specific state (selection, sort) — those
  stay on the caller.
-->
<template>
  <div class="data-table-page d-flex flex-column" style="min-height: 0;">
    <div class="dtp-toolbar d-flex flex-wrap align-center gap-3 mb-3">
      <v-text-field
        v-if="searchable"
        :model-value="search"
        @update:model-value="$emit('update:search', $event)"
        prepend-inner-icon="mdi-magnify"
        :placeholder="searchPlaceholder"
        variant="outlined"
        density="compact"
        hide-details
        clearable
        rounded="lg"
        class="dtp-search"
      ></v-text-field>

      <div v-if="$slots.filters" class="d-flex flex-wrap align-center gap-3">
        <slot name="filters" />
      </div>

      <div v-if="$slots.actions" class="ml-auto d-flex align-center flex-wrap gap-3">
        <slot name="actions" />
      </div>
    </div>

    <SegmentedTabs
      v-if="tabs && tabs.length"
      :model-value="status"
      @update:model-value="$emit('update:status', $event)"
      :items="tabs"
      class="mb-3"
    />

    <slot name="before-table" />

    <v-card
      elevation="0"
      border
      rounded="lg"
      class="bg-surface overflow-hidden flex-grow-1 d-flex flex-column dtp-table-card"
      style="min-height: 0;"
    >
      <v-data-table
        :headers="headers"
        :items="items"
        :items-per-page="itemsPerPage"
        :page="page"
        :item-value="itemValue"
        hide-default-footer
        density="comfortable"
        :no-data-text="noDataText"
        :row-props="rowProps"
        class="dtp-table flex-grow-1"
        style="min-height: 0;"
        @click:row="(event, ctx) => $emit('click:row', event, ctx)"
        @update:page="$emit('update:page', $event)"
      >
        <!-- Named explicitly, ahead of the generic forwarding loop below, so
             a caller with a `#rowNumber` header gets numbering for free — the
             shared numbering every page used to reimplement via
             composables/rowNumber.ts's useRowNumbers (position in the full
             filtered list, not the page-local index v-data-table's own
             slot scope offers, which would restart at 1 on page 2). A
             caller that still provides its own `item.rowNumber` template
             overrides this fallback, same as any other Vue slot default. -->
        <template v-slot:item.rowNumber="{ item, index }">
          <slot name="item.rowNumber" :item="item" :index="index">
            <span class="text-medium-emphasis">{{ rowNumberOf(item) }}</span>
          </slot>
        </template>

        <template v-for="slotName in forwardSlotNames" :key="slotName" v-slot:[slotName]="slotProps">
          <slot :name="slotName" v-bind="slotProps ?? {}" />
        </template>
      </v-data-table>
    </v-card>

    <div class="dtp-footer d-flex align-center justify-space-between flex-wrap gap-3 pt-3">
      <div class="text-caption text-medium-emphasis">
        <slot name="summary">{{ defaultSummary }}</slot>
      </div>
      <div class="d-flex align-center flex-wrap gap-4">
        <v-select
          :model-value="itemsPerPage"
          @update:model-value="onItemsPerPage"
          :items="itemsPerPageOptions"
          label="Rows"
          variant="outlined"
          density="compact"
          hide-details
          rounded="lg"
          class="dtp-rows-select"
        ></v-select>
        <v-pagination
          v-if="pageCount > 1"
          :model-value="page"
          @update:model-value="$emit('update:page', $event)"
          :length="pageCount"
          :total-visible="5"
          density="comfortable"
          active-color="primary"
        ></v-pagination>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, useSlots } from 'vue'
import SegmentedTabs from '@/components/SegmentedTabs.vue'

const props = defineProps({
  // Toolbar
  searchable: { type: Boolean, default: true },
  search: { type: String, default: '' },
  searchPlaceholder: { type: String, default: 'Search' },

  // Segmented status tabs — omitted entirely when `tabs` is empty/undefined.
  tabs: { type: Array, default: () => [] },
  status: { type: [String, Number], default: '' },

  // Table
  headers: { type: Array, required: true },
  items: { type: Array, required: true },
  itemValue: { type: String, default: 'id' },
  rowProps: { type: [Function, Object], default: undefined },
  noDataText: { type: String, default: 'No results' },

  // Footer
  page: { type: Number, default: 1 },
  itemsPerPage: { type: Number, default: 10 },
  itemsPerPageOptions: { type: Array, default: () => [10, 25, 50] },
  resultNoun: { type: String, default: 'results' },
})

const emit = defineEmits(['update:search', 'update:status', 'update:page', 'update:itemsPerPage', 'click:row'])

const slots = useSlots()
// `item.rowNumber` gets its own explicit template above (with a built-in
// fallback) — forwarding it again here would register the same slot name on
// v-data-table twice.
const forwardSlotNames = computed(() => Object.keys(slots).filter((name) => name !== 'item.rowNumber'))

// Numbered by position in the full items list this instance was handed, not
// v-data-table's own per-page slot index — see the template comment above.
const rowNumberById = computed(() => {
  const map = new Map()
  props.items.forEach((item, i) => {
    const id = item?.[props.itemValue]
    if (id !== undefined && id !== null) map.set(id, i + 1)
  })
  return map
})
const rowNumberOf = (item) => rowNumberById.value.get(item?.[props.itemValue]) ?? ''

const pageCount = computed(() => Math.max(1, Math.ceil(props.items.length / props.itemsPerPage)))

// Changing the page size mid-list can strand the current page past the new
// last page (25 rows at 50/page = page 1 of 1; switch to 10/page and page 1
// is still valid, but the reverse isn't) — reset to page 1 rather than
// leaving the table showing an out-of-range page silently.
const onItemsPerPage = (value) => {
  emit('update:itemsPerPage', value)
  emit('update:page', 1)
}

const defaultSummary = computed(() => `${props.items.length} ${props.resultNoun}`)
</script>

<style scoped>
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }

.dtp-search {
  width: 320px;
  max-width: 100%;
}
.dtp-rows-select {
  width: 100px;
}

/* Fixed layout, same reasoning every table in this app already relies on
   (ServiceRequestQueue's own comment on this): without it the browser
   re-measures every column off whatever rows are in view and the table
   visibly jumps on every filter/search/page change. */
.dtp-table :deep(table) {
  table-layout: fixed;
  width: 100%;
}
.dtp-table :deep(tbody tr) {
  cursor: pointer;
}
.dtp-table :deep(tbody tr:focus-visible) {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}
.dtp-table :deep(thead th) {
  font-size: 0.72rem !important;
  font-weight: 700 !important;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  white-space: nowrap;
}
</style>
