<!--
  DashboardToday.vue

  The dashboard's side rail: what happens or needs a call today. Built from
  what the dashboard already loaded, so nothing here fetches.
-->
<template>
  <v-card elevation="0" rounded="xl" class="soft-card">
    <v-card-item>
      <v-card-title class="text-body-1 font-weight-bold pa-0">Today</v-card-title>
    </v-card-item>

    <template v-for="s in sections" :key="s.title">
      <v-divider></v-divider>
      <div class="px-4 pt-3 d-flex align-center">
        <span class="text-caption font-weight-bold text-uppercase text-medium-emphasis">{{ s.title }}</span>
        <v-chip size="x-small" class="ml-2 font-weight-bold" variant="tonal">{{ s.items.length }}</v-chip>
        <v-spacer></v-spacer>
        <v-btn v-if="s.items.length > TOP" :to="s.route" size="x-small" variant="text" color="primary" class="text-none font-weight-bold px-0" append-icon="mdi-arrow-right">See all</v-btn>
      </div>
      <v-list v-if="s.items.length > 0" density="compact" class="py-1">
        <v-list-item v-for="item in s.items.slice(0, TOP)" :key="item.key" class="px-4">
          <v-list-item-title class="text-body-2 font-weight-bold">{{ item.title }}</v-list-item-title>
          <v-list-item-subtitle class="text-caption">{{ item.subtitle }}</v-list-item-subtitle>
          <template #append>
            <span class="text-caption font-weight-bold" :class="item.warn ? 'text-error' : 'text-medium-emphasis'">{{ item.badge }}</span>
          </template>
        </v-list-item>
      </v-list>
      <div v-else class="px-4 pt-1 pb-3 text-caption text-medium-emphasis">{{ s.empty }}</div>
    </template>
  </v-card>
</template>

<script setup>
import { computed } from 'vue'
import { DAY_MS } from '@/composables/dashboardTrends'

const props = defineProps({
  // The dashboard's open rows.
  rows: { type: Array, required: true },
  // { key, unit, departedAt } for each trip still out.
  trips: { type: Array, default: () => [] },
  // Request ids whose unit is double-booked, from /admin/dashboard.
  conflicts: { type: Array, default: () => [] },
  // Which lists the account could read. An unread one is left out rather than shown as empty.
  known: { type: Object, required: true },
})

const TOP = 5
const time = (ms) => new Date(ms).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })
const elapsed = (ms) => {
  const min = Math.max(0, Math.floor((Date.now() - ms) / 60_000))
  return min < 60 ? `${min}m` : `${Math.floor(min / 60)}h ${min % 60}m`
}

const bookings = computed(() => {
  const now = Date.now()
  return props.rows
    .filter((r) => r.status === 'Booked' && r.scheduledAt >= now && r.scheduledAt < now + DAY_MS)
    .toSorted((a, b) => a.scheduledAt - b.scheduledAt)
    .map((r) => {
      const clash = props.conflicts.includes(r.requestId)
      const warning = clash ? 'Unit double-booked' : (r.unit ? '' : 'No unit')
      return { key: r.key, title: `${time(r.scheduledAt)} · ${r.patient || r.name}`, subtitle: r.unit || 'No unit assigned', badge: warning, warn: !!warning }
    })
})

const tripsOut = computed(() => props.trips
  .toSorted((a, b) => a.departedAt - b.departedAt)
  .map((t) => ({ key: t.key, title: t.unit, subtitle: `Left ${time(t.departedAt)}`, badge: elapsed(t.departedAt) })))

const overdue = computed(() => props.rows
  .filter((r) => r.overdue)
  .toSorted((a, b) => b.daysLate - a.daysLate)
  .map((r) => ({ key: r.key, title: `${r.name} · ${r.phone}`, subtitle: r.type, badge: `${r.daysLate}d`, warn: true })))

const sections = computed(() => [
  props.known.bookings && { title: 'Bookings, next 24h', items: bookings.value, route: { path: '/conduction-requests', query: { status: 'Booked' } }, empty: 'No bookings in the next 24 hours' },
  props.known.trips && { title: 'Trips still out', items: tripsOut.value, route: '/conduction-requests', empty: 'Every unit is back' },
  props.known.overdue && { title: 'Overdue call list', items: overdue.value, route: { path: '/borrowings', query: { overdue: '1' } }, empty: 'Nothing overdue' },
].filter(Boolean))
</script>

<style scoped>
.soft-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  box-shadow: 0 1px 2px rgba(var(--v-theme-on-surface), 0.04), 0 4px 14px rgba(var(--v-theme-on-surface), 0.08);
}
</style>
