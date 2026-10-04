<!--
  TableFooter.vue

  The board's table footer for a list the server pages: "Showing 1 to N of M
  entries" on the left, a compact rows-per-page select and numbered page
  buttons on the right (32px, 8px radius, the current page tinted). It only
  reports `page` and `perPage`; the page fetches. Hides the controls while one
  page holds everything.
-->
<template>
  <div class="table-footer">
    <div class="table-footer__count">{{ summary }}</div>
    <div v-if="total > 0" class="table-footer__controls">
      <label class="table-footer__per">
        <span>Rows per page</span>
        <select :value="perPage" aria-label="Rows per page" @change="(e: Event) => emit('update:per-page', Number((e.target as HTMLSelectElement).value))">
          <option v-for="n in options" :key="n" :value="n">{{ n }}</option>
        </select>
      </label>
      <nav v-if="pageCount > 1" class="table-footer__pages" aria-label="Pages">
        <template v-for="p in pages" :key="p">
          <span v-if="p === '…'" class="table-footer__gap">…</span>
          <button
            v-else
            type="button"
            :class="{ 'is-current': p === page }"
            :aria-current="p === page ? 'page' : undefined"
            @click="emit('update:page', Number(p))"
          >{{ p }}</button>
        </template>
      </nav>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  page: number
  perPage: number
  total: number
  options?: number[]
}>(), { options: () => [10, 25, 50] })

const emit = defineEmits<{ 'update:page': [page: number]; 'update:per-page': [perPage: number] }>()

const pageCount = computed(() => Math.max(1, Math.ceil(props.total / props.perPage)))
const summary = computed(() => {
  if (props.total === 0) return 'No entries'
  const from = (props.page - 1) * props.perPage + 1
  const to = Math.min(props.total, props.page * props.perPage)
  return `Showing ${from} to ${to} of ${props.total} ${props.total === 1 ? 'entry' : 'entries'}`
})
// First, last and the neighbours of the current page, with gaps between.
const pages = computed(() => {
  const keep = new Set([1, pageCount.value, props.page - 1, props.page, props.page + 1])
  const list: (number | string)[] = []
  let last = 0
  for (let p = 1; p <= pageCount.value; p++) {
    if (!keep.has(p)) continue
    if (p - last > 1) list.push('…')
    list.push(p)
    last = p
  }
  return list
})
</script>

<style scoped>
.table-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  padding: 14px 24px;
  font-size: 14px;
}
.table-footer__count { color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.table-footer__controls { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.table-footer__per { display: flex; align-items: center; gap: 8px; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.table-footer__per select {
  height: 32px;
  padding: 0 8px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.14);
  border-radius: 8px;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  font-family: inherit; font-size: 14px; font-weight: 600;
}
.table-footer__pages { display: flex; align-items: center; gap: 8px; }
.table-footer__pages button {
  min-width: 32px;
  height: 32px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: rgb(var(--v-theme-on-surface));
  font-family: inherit; font-size: 14px; font-weight: 600;
  cursor: pointer;
}
.table-footer__pages button.is-current {
  background: rgba(var(--v-theme-primary), 0.14);
  color: rgb(var(--v-theme-primary-strong));
  font-weight: 700;
}
.table-footer__gap { color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
</style>
