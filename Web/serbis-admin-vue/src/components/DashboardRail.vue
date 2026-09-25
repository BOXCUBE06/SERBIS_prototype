<!--
  DashboardRail.vue

  The dashboard's one side card, "Needs attention": equipment to chase, requests
  that look filed twice, and requests that have sat too long after staff took
  them on. All derived from the open rows the dashboard already holds, so
  nothing here fetches.
-->
<template>
  <v-card elevation="0" rounded="xl" class="soft-card">
    <v-card-item>
      <v-card-title class="text-body-1 font-weight-bold pa-0">Needs attention</v-card-title>
    </v-card-item>

    <v-tabs v-model="tab" color="primary" density="compact" grow>
      <v-tab v-for="t in TABS" :key="t.value" :value="t.value" class="text-none font-weight-bold">
        {{ t.title }}
        <v-chip size="x-small" class="ml-2 font-weight-bold" :color="lists[t.value].length > 0 ? 'error' : undefined" variant="tonal">
          {{ lists[t.value].length }}
        </v-chip>
      </v-tab>
    </v-tabs>
    <v-divider></v-divider>

    <p class="px-4 pt-3 text-caption text-medium-emphasis">{{ current.hint }}</p>

    <v-list v-if="shown.length > 0" density="compact" class="pt-0">
      <v-list-item v-for="item in shown" :key="item.key" class="px-4">
        <v-list-item-title class="text-body-2 font-weight-bold">{{ item.title }}</v-list-item-title>
        <v-list-item-subtitle class="text-caption">{{ item.subtitle }}</v-list-item-subtitle>
        <template #append>
          <StatusPill v-if="item.status" :status="item.status" small class="mr-2" />
          <span class="text-caption font-weight-bold text-error">{{ item.badge }}</span>
        </template>
      </v-list-item>
    </v-list>
    <div v-else class="pa-4 pt-0 text-caption text-medium-emphasis">{{ current.empty }}</div>

    <div v-if="lists[tab].length > 0" class="px-4 pb-3">
      <v-btn :to="current.route" size="small" variant="text" color="primary" class="text-none font-weight-bold px-0" append-icon="mdi-arrow-right">
        See all {{ lists[tab].length }}
      </v-btn>
    </div>
  </v-card>
</template>

<script setup>
import { computed, ref } from 'vue'
import StatusPill from '@/components/StatusPill.vue'
import { DAY_MS } from '@/composables/dashboardTrends'

const props = defineProps({
  rows: { type: Array, required: true },
})

const DUPLICATE_WINDOW_MIN = 10
const STALE_DAYS = 3
const TOP = 5

const TABS = [
  { value: 'overdue', title: 'Overdue' },
  { value: 'duplicates', title: 'Duplicates' },
  { value: 'stale', title: 'Stale' },
]
const tab = ref('overdue')

const overdue = computed(() => props.rows
  .filter((r) => r.overdue)
  .toSorted((a, b) => b.daysLate - a.daysLate)
  .map((r) => ({ key: r.key, title: r.name, subtitle: `${r.type} • ${r.phone}`, badge: `${r.daysLate}d` })))

// Sorted by filing time, a burst is a run where each request lands within the
// window of the one before it.
const duplicates = computed(() => {
  const byResident = new Map()
  for (const r of props.rows) {
    if (r.residentId) byResident.set(r.residentId, [...(byResident.get(r.residentId) ?? []), r])
  }

  const groups = []
  const close = (id, run) => {
    if (run.length > 1) {
      groups.push({ key: `${id}-${run[0].filedAt}`, title: `${run[0].name} ×${run.length}`, subtitle: run.map((r) => r.type).join(', ') })
    }
  }
  for (const [id, list] of byResident) {
    const sorted = list.toSorted((a, b) => a.filedAt - b.filedAt)
    let run = [sorted[0]]
    for (const r of sorted.slice(1)) {
      if (r.filedAt - run.at(-1).filedAt <= DUPLICATE_WINDOW_MIN * 60_000) {
        run.push(r)
      } else {
        close(id, run)
        run = [r]
      }
    }
    close(id, run)
  }
  return groups
})

// Staff took these on and then nothing closed them. Pending is not here: that
// is the queue's job, and it already shows how long each has waited.
const stale = computed(() => props.rows
  .filter((r) => r.kind !== 'borrow' && ['Responding', 'Booked'].includes(r.status) && Date.now() - r.filedAt > STALE_DAYS * DAY_MS)
  .toSorted((a, b) => a.filedAt - b.filedAt)
  .map((r) => ({ key: r.key, title: r.name, subtitle: r.type, status: r.status, badge: `${Math.floor((Date.now() - r.filedAt) / DAY_MS)}d` })))

const lists = computed(() => ({ overdue: overdue.value, duplicates: duplicates.value, stale: stale.value }))

const PANES = {
  overdue: { hint: 'Call these households', empty: 'Nothing overdue', route: '/borrowings' },
  duplicates: { hint: `Same person, filed within ${DUPLICATE_WINDOW_MIN} minutes`, empty: 'None found', route: '/manage-requests' },
  stale: { hint: `Responding or Booked for more than ${STALE_DAYS} days`, empty: 'Nothing stale', route: '/manage-requests' },
}
const current = computed(() => PANES[tab.value])
const shown = computed(() => lists.value[tab.value].slice(0, TOP))
</script>

<style scoped>
.soft-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  box-shadow: 0 1px 2px rgba(var(--v-theme-on-surface), 0.04), 0 4px 14px rgba(var(--v-theme-on-surface), 0.08);
}
</style>
