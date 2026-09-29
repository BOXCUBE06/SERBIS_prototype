<!--
  DataTablePage.vue

  The one list panel shared by Resident Requests, Bookings, Trip Logs and
  Equipment Borrowing's Active pipeline and History. Top to bottom, inside a
  single bordered card: segmented status tabs, toolbar (search, filters,
  the page's own actions), active-filter chips, table, footer (result count
  left; rows-per-page and numbered pagination right).

  Everything sits at the card's one inner padding, so the toolbar and the
  table share the same left and right edges on every page — callers must
  not wrap this in a card or padding of their own.

  Column cells stay the caller's own: every `item.*`/`no-data`/`header.*`
  slot is forwarded straight to v-data-table.
-->
<template>
  <v-card
    elevation="0"
    border
    rounded="lg"
    class="data-table-page bg-surface pa-4"
    :class="{ 'dtp-compact': compact, 'dtp-collapse': collapseMobile }"
    :style="{
      '--dtp-row-height': ROW_HEIGHT + 'px',
      '--dtp-header-height': HEADER_HEIGHT + 'px',
      '--dtp-body-height': tableBodyHeight + 'px',
    }"
  >
    <SegmentedTabs
      v-if="tabs && tabs.length"
      :model-value="status"
      @update:model-value="$emit('update:status', $event)"
      :items="tabs"
      :loading="loading"
      :tonal="compact"
      class="mb-4"
    />

    <div class="dtp-toolbar d-flex align-center gap-3 mb-3">
      <v-text-field
        v-if="searchable"
        :model-value="search"
        @update:model-value="$emit('update:search', $event ?? '')"
        prepend-inner-icon="mdi-magnify"
        :placeholder="searchPlaceholder"
        variant="outlined"
        density="compact"
        hide-details
        clearable
        rounded="lg"
        class="dtp-search"
      ></v-text-field>

      <div v-if="$slots.filters" class="dtp-filters d-flex flex-wrap align-center gap-3">
        <slot name="filters" />
      </div>

      <div v-if="$slots.actions" class="dtp-actions ml-auto d-flex align-center gap-2">
        <slot name="actions" />
      </div>
    </div>

    <!-- Always rendered at one chip-row's height, active filters or not, so
         applying the first filter never pushes the table down. Search's chip
         comes from `search` itself; every other filter from the caller. -->
    <div v-if="!compact || allFilters.length" class="dtp-filter-row d-flex align-center flex-wrap gap-2 mb-3">
      <template v-if="allFilters.length">
        <span class="text-caption font-weight-bold text-medium-emphasis">Filtered by</span>
        <v-chip
          v-for="f in allFilters"
          :key="f.key"
          size="small"
          variant="outlined"
          closable
          class="dtp-filter-chip font-weight-medium"
          :close-label="`Remove filter: ${f.label}`"
          @click:close="clearOne(f.key)"
        >{{ f.label }}</v-chip>
        <v-btn
          variant="outlined" color="primary"
          size="small"
          class="text-none font-weight-bold"
          @click="clearAll"
        >Clear all</v-btn>
      </template>
    </div>

    <slot name="before-table" />

    <!-- `content` replaces the table (a grid view, say) and keeps the toolbar,
         tabs, filter chips and footer around it; the caller renders the page's
         rows itself from the same page / items-per-page. -->
    <slot v-if="$slots.content" name="content" />

    <div v-else class="dtp-table-wrap" :style="{ minHeight: tableMinHeight + 'px' }">
      <!-- Keyed on first load, so the body that replaces the skeleton mounts
           fresh and fades in (.table-fade). -->
      <v-data-table
        :key="loading ? 'loading' : 'ready'"
        :headers="headers"
        :items="items"
        :items-per-page="itemsPerPage"
        :page="page"
        :item-value="itemValue"
        hide-default-footer
        :sort-by="sortBy"
        :show-select="selectable"
        select-strategy="page"
        :model-value="selected"
        @update:model-value="$emit('update:selected', $event)"
        :no-data-text="noDataText"
        :row-props="rowProps"
        class="dtp-table table-fade"
        :class="{ 'is-refreshing': refreshing && !loading }"
        :loading="refreshing && !loading"
        @click:row="(event, ctx) => $emit('click:row', event, ctx)"
        @update:page="$emit('update:page', $event)"
      >
        <template v-if="loading" #body>
          <SkeletonRows :rows="itemsPerPage" :columns="headers.length" />
        </template>
        <template v-for="slotName in forwardSlotNames()" :key="slotName" v-slot:[slotName]="slotProps">
          <slot :name="slotName" v-bind="slotProps ?? {}" />
        </template>
      </v-data-table>
    </div>

    <div class="dtp-footer d-flex align-center justify-space-between flex-wrap gap-3 pt-3">
      <div class="text-body-2 text-medium-emphasis">
        <slot name="summary">{{ defaultSummary }}</slot>
      </div>
      <div class="d-flex align-center flex-wrap gap-4">
        <div v-if="showRowsPerPage" class="d-flex align-center gap-2">
          <span class="text-body-2 text-medium-emphasis">Rows per page</span>
          <v-select
            :model-value="itemsPerPage"
            @update:model-value="onItemsPerPage"
            :items="itemsPerPageOptions"
            aria-label="Rows per page"
            variant="outlined"
            density="compact"
            hide-details
            rounded="lg"
            class="dtp-rows-select"
          ></v-select>
        </div>
        <v-pagination
          v-if="!compact || pageCount > 1"
          :model-value="page"
          @update:model-value="$emit('update:page', $event)"
          :length="pageCount"
          :total-visible="5"
          density="comfortable"
          active-color="primary"
        ></v-pagination>
      </div>
    </div>
  </v-card>
</template>

<script setup>
import { computed, useSlots } from 'vue'
import SegmentedTabs from '@/components/SegmentedTabs.vue'
import SkeletonRows from '@/components/SkeletonRows.vue'

const props = defineProps({
  searchable: { type: Boolean, default: true },
  search: { type: String, default: '' },
  searchPlaceholder: { type: String, default: 'Search' },

  // Segmented status tabs — omitted entirely when `tabs` is empty.
  tabs: { type: Array, default: () => [] },
  status: { type: [String, Number], default: '' },

  headers: { type: Array, required: true },
  items: { type: Array, required: true },
  itemValue: { type: String, default: 'id' },
  rowProps: { type: [Function, Object], default: undefined },
  noDataText: { type: String, default: 'No results' },
  // First load: skeleton rows. A later refetch: current rows dimmed instead.
  loading: { type: Boolean, default: false },
  refreshing: { type: Boolean, default: false },

  page: { type: Number, default: 1 },
  itemsPerPage: { type: Number, default: 10 },
  itemsPerPageOptions: { type: Array, default: () => [10, 25, 50] },
  resultNoun: { type: String, default: 'results' },

  // `{ key, label }` per active filter, search excluded (added here). Closing
  // a chip or Clear all only tells the caller which key to reset.
  activeFilters: { type: Array, default: () => [] },

  // Opt-in row selection: Vuetify's own checkbox column, with the header box
  // selecting (and indeterminate over) the rows on the current page. `selected`
  // is the array of ticked item-value ids. Callers with their own select slot
  // leave this off.
  selectable: { type: Boolean, default: false },
  selected: { type: Array, default: () => [] },

  // The header sort shown on first render, e.g. [{ key: 'last_name', order: 'asc' }].
  // Only the starting point: no update listener is bound, so the table still
  // sorts itself when a header is clicked.
  sortBy: { type: Array, default: () => [] },

  // The resource lists' quieter shape: the filter-chip row appears only while a
  // filter is active, the table has no border of its own (the card is the one
  // border), and the footer is a step smaller. Off, every other list is as before.
  compact: { type: Boolean, default: false },

  // Phone widths (<600px) for a list whose first column is the identity: the
  // fixed layout is dropped so that column keeps its width, and any header
  // carrying `headerProps/cellProps: { class: 'dtp-hide-sm' }` is hidden.
  collapseMobile: { type: Boolean, default: false },
})

const emit = defineEmits([
  'update:search',
  'update:status',
  'update:page',
  'update:selected',
  'update:items-per-page',
  'click:row',
  'clear-filter',
  'clear-all',
])

// This component's own slots are not v-data-table's; forwarding `actions` or
// `summary` down would collide with any same-named table slot.
const OWN_SLOTS = new Set(['filters', 'actions', 'summary', 'before-table', 'content'])
const slots = useSlots()
// A plain function, not computed: `slots` is not reactive, so a computed would
// freeze on the slots present at first render and drop any a caller adds later
// (Service Vehicles' columns arrive with its data).
const forwardSlotNames = () => Object.keys(slots).filter((name) => !OWN_SLOTS.has(name))

const pageCount = computed(() => Math.max(1, Math.ceil(props.items.length / props.itemsPerPage)))
// Compact lists hide the pager and page size while everything fits the smallest
// page size, so a short list has no controls that do nothing.
const showRowsPerPage = computed(() => !props.compact || props.items.length > Math.min(...props.itemsPerPageOptions))

// One fixed height for every row, never density — a row sized off its own
// content is the layout shift this component exists to remove. The table
// area reserves a full page of rows even when the page is partial or empty,
// so switching tabs or filters never moves the footer.
const ROW_HEIGHT = 48
const HEADER_HEIGHT = 44
// Compact lists reserve only the rows they show (at least one, for the empty
// message), so a short list has no dead space; the footer still holds still
// while paging because every full page is the same height.
const rowsShown = computed(() => {
  if (!props.compact) return props.itemsPerPage
  const onPage = props.items.length - (props.page - 1) * props.itemsPerPage
  return Math.min(props.itemsPerPage, Math.max(onPage, 1))
})
const tableBodyHeight = computed(() => rowsShown.value * ROW_HEIGHT)
const tableMinHeight = computed(() => tableBodyHeight.value + HEADER_HEIGHT)

// A smaller page size can strand the current page past the new last page.
const onItemsPerPage = (value) => {
  emit('update:items-per-page', value)
  emit('update:page', 1)
}

const defaultSummary = computed(() => `${props.items.length} ${props.resultNoun}`)

// Internal key for the search chip; never collides with a caller's own keys.
const SEARCH_FILTER_KEY = '__search'
const allFilters = computed(() => {
  const q = (props.search || '').trim()
  const searchChip = props.searchable && q ? [{ key: SEARCH_FILTER_KEY, label: `Search: "${q}"` }] : []
  return [...searchChip, ...props.activeFilters]
})
const clearOne = (key) => {
  if (key === SEARCH_FILTER_KEY) emit('update:search', '')
  else emit('clear-filter', key)
}
const clearAll = () => {
  if (props.searchable) emit('update:search', '')
  emit('clear-all')
}
</script>

<style scoped>
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }

/* One toolbar row at 1280px and up, with Bookings' three buttons the widest
   case (they need 422px of an 888px row). nowrap, because a wrapping row
   breaks items onto a new line at full width before it ever shrinks them.
   Buttons never shrink; search and the selects give way, each down to a
   floor, and grow back toward full width on wider screens. The filter group
   is display:contents so each select is its own flex item. */
.data-table-page .dtp-toolbar {
  flex-wrap: nowrap;
}
.data-table-page .dtp-actions {
  flex: 0 0 auto;
}
.data-table-page .dtp-search {
  flex: 1 1 320px;
  min-width: 150px;
  max-width: 320px;
}
.data-table-page .dtp-filters {
  display: contents;
}
/* 128px still fits a full "Barangay" label; a long selected value ellipsizes. */
.data-table-page .dtp-filters :deep(.v-input) {
  flex: 1 1 200px;
  min-width: 128px;
  max-width: 200px;
}
.data-table-page .dtp-rows-select {
  flex: 0 0 88px;
  width: 88px;
}
/* Below 1280 the floors no longer fit one row; wrap rather than overflow. */
@media (max-width: 1279px) {
  .data-table-page .dtp-toolbar {
    flex-wrap: wrap;
  }
}
@media (max-width: 599px) {
  .data-table-page .dtp-search,
  .data-table-page .dtp-filters :deep(.v-input) {
    flex: 1 1 100%;
    max-width: 100%;
  }
}

.dtp-filter-row {
  min-height: 32px;
}
/* Outlined, so the label sits on the surface; -strong for AA at this weight. */
.dtp-filter-chip {
  color: rgb(var(--v-theme-primary-strong));
  border-color: rgba(var(--v-theme-primary), 0.45);
}

.dtp-table-wrap {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 8px;
  overflow: hidden;
}
.dtp-compact .dtp-table-wrap {
  border: none;
  border-radius: 0;
}
/* Footer text at table-body size, muted; the select and pager follow. */
.dtp-compact .dtp-footer,
.dtp-compact .dtp-footer :deep(.text-body-2),
.dtp-compact .dtp-footer :deep(.v-field__input),
.dtp-compact .dtp-footer :deep(.v-btn) {
  font-size: 0.8125rem !important;
}
.dtp-compact .dtp-footer {
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
/* These rows open Edit on click, so they say so on hover. */
.dtp-compact .dtp-table :deep(tbody tr:hover) {
  background: rgba(var(--v-theme-on-surface), 0.05);
}

/* Phones, for lists that opt in (collapseMobile): auto layout so the identity
   column keeps room, the secondary column gone, and Status/Actions sized to
   their content. The tab strip scrolls inside its own box. */
@media (max-width: 599px) {
  .dtp-collapse .dtp-table :deep(table) {
    table-layout: auto;
    min-width: 0;
  }
  .dtp-collapse .dtp-table :deep(th),
  .dtp-collapse .dtp-table :deep(td) {
    width: auto !important;
  }
  .dtp-collapse .dtp-table :deep(th:first-child),
  .dtp-collapse .dtp-table :deep(td:first-child) {
    min-width: 150px;
  }
  .dtp-collapse .dtp-table :deep(.dtp-hide-sm) {
    display: none;
  }
  .dtp-collapse :deep(.segmented-tabs__seg) {
    padding: 8px 10px;
  }
}

/* Fixed layout: otherwise columns re-measure off the visible rows and the
   table jumps on every filter, search or page change. */
.dtp-table :deep(table) {
  table-layout: fixed;
  width: 100%;
}
.dtp-table :deep(thead th) {
  height: var(--dtp-header-height) !important;
  font-size: 0.72rem !important;
  font-weight: 700 !important;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  white-space: nowrap;
}
.dtp-table :deep(tbody tr) {
  cursor: pointer;
  height: var(--dtp-row-height);
}
.dtp-table :deep(tbody td) {
  height: var(--dtp-row-height) !important;
  overflow: hidden;
}
/* Empty result: the lone no-data cell fills the whole reserved body. */
.dtp-table :deep(tr.v-data-table-rows-no-data td) {
  height: var(--dtp-body-height) !important;
  text-align: center;
  vertical-align: middle;
}
.dtp-table :deep(tbody tr:focus-visible) {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}
/* Vuetify hides the sort arrow until hover; keep it visible on every sortable
   column so sortability is discoverable, full strength once sorted. */
.dtp-table :deep(.v-data-table-header__sort-icon) {
  opacity: 0.35 !important;
}
.dtp-table :deep(.v-data-table__th--sorted .v-data-table-header__sort-icon) {
  opacity: 1 !important;
}
</style>
