<!--
  ChoiceMatrix.vue

  The checkbox matrix on Service audience and Service vehicles (the ResMatrix
  board): a service per row, one column per option, in a 24px-radius card with a
  count underneath. Presentational: the page owns what a tick does, and answers
  with the new row state (the boxes are controlled, so a refused tick simply does
  not take). Each box is labelled "<row>, <column>".

  Rows are `{ key, name, tag?, dim?, checks: boolean[], busy? }`; `tag` is the
  small label after the name ("App feature", "Switched off"), `dim` greys the
  name, `busy` disables that row's boxes while its save is in flight.
-->
<template>
  <div class="choice-matrix" :class="{ 'is-refreshing': refreshing }">
    <div class="choice-matrix__scroll">
      <table>
        <thead>
          <tr>
            <th scope="col" class="choice-matrix__name">Service</th>
            <th v-for="c in columns" :key="c" scope="col" class="choice-matrix__col">{{ c }}</th>
          </tr>
        </thead>
        <tbody>
          <template v-if="loading">
            <tr v-for="n in 6" :key="n" aria-hidden="true">
              <th scope="row" class="choice-matrix__row-name"><span class="choice-matrix__skel"></span></th>
              <td v-for="c in columns.length || 3" :key="c"></td>
            </tr>
          </template>
          <tr v-else-if="rows.length === 0">
            <td :colspan="columns.length + 1" class="choice-matrix__empty">{{ emptyText }}</td>
          </tr>
          <template v-else>
            <tr v-for="r in rows" :key="r.key">
              <th scope="row" class="choice-matrix__row-name" :class="{ 'is-dim': r.dim }">
                {{ r.name }}
                <StatusPill v-if="r.tag" tag class="choice-matrix__tag" :label="r.tag" />
              </th>
              <td v-for="(c, i) in columns" :key="c">
                <input
                  type="checkbox"
                  :checked="r.checks[i]"
                  :disabled="r.busy"
                  :aria-label="`${r.name}, ${c}`"
                  @change="(e: Event) => { const box = e.target as HTMLInputElement; emit('toggle', r, i, box.checked); box.checked = r.checks[i] }"
                />
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
    <div class="choice-matrix__footer">{{ footer }}</div>
  </div>
</template>

<script setup lang="ts">
import StatusPill from '@/components/StatusPill.vue'

export interface MatrixRow {
  key: string
  name: string
  tag?: string
  dim?: boolean
  checks: boolean[]
  busy?: boolean
}

withDefaults(defineProps<{
  columns: string[]
  rows: MatrixRow[]
  footer?: string
  loading?: boolean
  refreshing?: boolean
  emptyText?: string
}>(), { footer: '', loading: false, refreshing: false, emptyText: 'Nothing to show' })

const emit = defineEmits<{ toggle: [row: MatrixRow, column: number, checked: boolean] }>()
</script>

<style scoped>
.choice-matrix {
  border-radius: 24px;
  background: rgb(var(--v-theme-surface));
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04), 0 4px 14px rgba(0, 0, 0, 0.08);
  overflow: hidden;
}
.choice-matrix.is-refreshing { opacity: 0.6; }
.choice-matrix__scroll { overflow-x: auto; }
table { width: 100%; min-width: 720px; border-collapse: collapse; font-size: 14px; }
th, td { border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08); }
thead th {
  padding: 14px 12px;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.12);
}
thead .choice-matrix__name { text-align: left; padding: 14px 12px 14px 24px; }
thead .choice-matrix__col { width: 22%; text-align: center; }
.choice-matrix__row-name { height: 48px; padding: 0 12px 0 24px; text-align: left; font-weight: 700; }
.choice-matrix__row-name.is-dim { color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.choice-matrix__tag { margin-left: 8px; }
tbody tr:hover { background: rgba(var(--v-theme-on-surface), 0.025); }
td { padding: 0 12px; text-align: center; }
input[type='checkbox'] {
  width: 20px;
  height: 20px;
  margin: 0;
  vertical-align: middle;
  accent-color: rgb(var(--v-theme-primary));
  cursor: pointer;
}
input[type='checkbox']:disabled { cursor: default; }
.choice-matrix__empty { height: 96px; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.choice-matrix__skel {
  display: inline-block;
  width: 40%;
  height: 14px;
  border-radius: 4px;
  background: rgba(var(--v-theme-on-surface), 0.08);
}
.choice-matrix__footer {
  padding: 14px 24px;
  font-size: 14px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
</style>
