<!--
  PickerDrawer.vue

  A picker that fills DetailDrawer's `panel` slot: back arrow, title, optional
  tabs (Responders / Vehicle), a 36px search, a checkbox list and a sticky
  "N selected · Done" footer. Responders today, a unit for a booking later.

  The caller owns the selection: `toggle` reports a click, `selected` says what
  is ticked. Items are { id, name, secondary?, initials?, icon?, status?,
  disabled? }; `status` draws a StatusPill on the row's right.

  `single` is for a one-of-many choice (a unit for a booking): radio marks, no
  search, and a footer that names the pick. The caller still owns the selection.
-->
<template>
  <div class="d-flex flex-column h-100">
    <header class="pd-header">
      <div class="d-flex align-center ga-3">
        <v-btn icon="mdi-chevron-left" variant="tonal" size="36" rounded="lg" aria-label="Back to request" @click="$emit('back')"></v-btn>
        <div class="min-w-0">
          <div class="pd-title">{{ title }}</div>
          <div v-if="subtitle" class="text-caption text-medium-emphasis text-truncate">{{ subtitle }}</div>
        </div>
      </div>
      <v-tabs
        v-if="tabs?.length"
        :model-value="tab"
        @update:model-value="$emit('update:tab', $event)"
        color="primary-strong"
        density="compact"
        class="mt-4 pd-tabs"
      >
        <v-tab v-for="t in tabs" :key="t.value" :value="t.value" class="text-none font-weight-bold">
          {{ t.label }} <span class="ml-1 font-weight-black">{{ t.count }}</span>
        </v-tab>
      </v-tabs>
    </header>

    <div v-if="!single" class="px-6 pt-4 filter-bar">
      <v-text-field
        v-model="query"
        prepend-inner-icon="mdi-magnify"
        :placeholder="searchPlaceholder"
        :aria-label="searchPlaceholder"
        variant="outlined"
        density="compact"
        hide-details
        clearable
        rounded="lg"
        class="w-100"
      ></v-text-field>
    </div>

    <div class="pd-list">
      <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">{{ error }}</v-alert>
      <label
        v-for="item in shown"
        :key="item.id"
        class="pd-row"
        :class="{ 'pd-row--on': isOn(item), 'pd-row--off': item.disabled }"
      >
        <v-checkbox-btn
          :model-value="isOn(item)"
          :disabled="item.disabled"
          :true-icon="single ? 'mdi-radiobox-marked' : undefined"
          :false-icon="single ? 'mdi-radiobox-blank' : undefined"
          density="compact"
          class="flex-grow-0"
          @update:model-value="$emit('toggle', item)"
        ></v-checkbox-btn>
        <PersonCell :name="item.name" :secondary="item.secondary" :initials="item.initials" :icon="item.icon" size="36" tinted class="flex-grow-1" />
        <StatusPill v-if="item.status" :status="item.status" />
      </label>
      <div v-if="!shown.length" class="pa-6 text-center text-body-2 text-medium-emphasis">
        {{ query ? `Nothing matches "${query}"` : emptyText }}
      </div>
    </div>

    <footer class="detail-footer d-flex align-center justify-space-between ga-3 px-6 py-4">
      <div v-if="single" class="text-body-2">{{ pickedName ? `${pickedName} selected` : 'No unit selected' }}</div>
      <div v-else class="text-body-2"><strong>{{ selected.length }}</strong> selected</div>
      <v-btn color="primary" variant="flat" height="40" class="px-6 text-none font-weight-bold" @click="$emit('done')">Done</v-btn>
    </footer>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import PersonCell from '@/components/PersonCell.vue'
import StatusPill from '@/components/StatusPill.vue'
import '@/components/detail-dialog.css'

export interface PickerItem {
  id: string | number
  name: string
  secondary?: string | null
  initials?: string
  icon?: string | null
  status?: string | null
  disabled?: boolean
}

const props = withDefaults(defineProps<{
  title: string
  subtitle?: string | null
  tabs?: { value: string; label: string; count?: number }[]
  tab?: string
  items: PickerItem[]
  selected: (string | number)[]
  searchPlaceholder?: string
  emptyText?: string
  error?: string | null
  single?: boolean
}>(), { searchPlaceholder: 'Search name', emptyText: 'Nothing to pick from.' })
defineEmits<{ back: []; done: []; toggle: [item: PickerItem]; 'update:tab': [value: string] }>()

const query = ref('')
// A new tab is a new list; carrying the old search over would hide it.
watch(() => props.tab, () => { query.value = '' })

const shown = computed(() => {
  const q = (query.value || '').toLowerCase()
  return q ? props.items.filter((i) => `${i.name} ${i.secondary || ''}`.toLowerCase().includes(q)) : props.items
})
const isOn = (item: PickerItem) => props.selected.includes(item.id)
const pickedName = computed(() => props.items.find((i) => isOn(i))?.name)
</script>

<style scoped>
.pd-header {
  flex-shrink: 0;
  padding: 20px 24px 0;
}
.pd-title { font-size: 1.17rem; line-height: 28px; font-weight: 600; }
.pd-tabs { border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.12); }
.pd-list {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  padding: 12px 24px;
}
.pd-row {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 60px;
  padding: 8px 12px;
  margin-bottom: 4px;
  border-radius: 12px;
  cursor: pointer;
}
.pd-row:hover { background: rgba(var(--v-theme-on-surface), 0.04); }
.pd-row--on { background: rgba(var(--v-theme-primary), 0.08); }
.pd-row--off { cursor: default; opacity: 0.6; }
.min-w-0 { min-width: 0; }
</style>
