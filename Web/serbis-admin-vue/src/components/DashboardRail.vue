<!--
  DashboardRail.vue

  The dashboard's side lists: who to ring about overdue equipment, what is out
  on the road, and requests that look filed twice. All derived from the open
  rows the dashboard already holds, so nothing here fetches.
-->
<template>
  <div class="d-flex flex-column gap-4">
    <v-card elevation="0" rounded="xl" class="soft-card">
      <v-card-item>
        <v-card-title class="text-body-1 font-weight-bold pa-0">Overdue equipment</v-card-title>
        <v-card-subtitle class="pa-0">Call these households</v-card-subtitle>
      </v-card-item>
      <v-list v-if="overdue.length > 0" density="compact" class="pt-0">
        <v-list-item v-for="r in overdue" :key="r.key" class="px-4">
          <v-list-item-title class="text-body-2 font-weight-bold">{{ r.name }}</v-list-item-title>
          <v-list-item-subtitle class="text-caption">{{ r.type }} &bull; {{ r.phone }}</v-list-item-subtitle>
          <template #append>
            <span class="text-caption font-weight-bold text-error">{{ r.daysLate }}d</span>
          </template>
        </v-list-item>
      </v-list>
      <div v-else class="pa-4 pt-0 text-caption text-medium-emphasis">Nothing overdue</div>
    </v-card>

    <v-card elevation="0" rounded="xl" class="soft-card">
      <v-card-item>
        <v-card-title class="text-body-1 font-weight-bold pa-0">Active dispatches</v-card-title>
        <v-card-subtitle v-if="vehicles !== null" class="pa-0">
          {{ availableUnits.length }} {{ availableUnits.length === 1 ? 'unit' : 'units' }} available
        </v-card-subtitle>
      </v-card-item>
      <v-list v-if="dispatches.length > 0" density="compact" class="pt-0">
        <v-list-item v-for="r in dispatches" :key="r.key" class="px-4">
          <v-list-item-title class="text-body-2 font-weight-bold">{{ r.name }}</v-list-item-title>
          <v-list-item-subtitle class="text-caption">{{ r.unit || 'No unit assigned' }}</v-list-item-subtitle>
          <template #append><StatusPill :status="r.status" small /></template>
        </v-list-item>
      </v-list>
      <div v-else class="pa-4 pt-0 text-caption text-medium-emphasis">No ambulance booked or responding</div>
      <div v-if="availableUnits.length > 0" class="px-4 pb-4 d-flex flex-wrap gap-2">
        <v-chip v-for="v in availableUnits" :key="v.vehicle_id" size="small" variant="tonal" color="success">{{ vehicleName(v) }}</v-chip>
      </div>
    </v-card>

    <v-card elevation="0" rounded="xl" class="soft-card">
      <v-card-item>
        <v-card-title class="text-body-1 font-weight-bold pa-0">Possible duplicates</v-card-title>
        <v-card-subtitle class="pa-0">Same person, filed within {{ DUPLICATE_WINDOW_MIN }} minutes</v-card-subtitle>
      </v-card-item>
      <v-list v-if="duplicates.length > 0" density="compact" class="pt-0">
        <v-list-item v-for="g in duplicates" :key="g.key" class="px-4">
          <v-list-item-title class="text-body-2 font-weight-bold">{{ g.name }} &times;{{ g.rows.length }}</v-list-item-title>
          <v-list-item-subtitle class="text-caption">{{ g.rows.map((r) => r.type).join(', ') }}</v-list-item-subtitle>
        </v-list-item>
      </v-list>
      <div v-else class="pa-4 pt-0 text-caption text-medium-emphasis">None found</div>
    </v-card>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import StatusPill from '@/components/StatusPill.vue'
import { vehicleName } from '@/composables/requestDisplay'

const props = defineProps({
  rows: { type: Array, required: true },
  // null when the account cannot read vehicles.
  vehicles: { type: Array, default: null },
})

const DUPLICATE_WINDOW_MIN = 10

const overdue = computed(() => props.rows.filter((r) => r.overdue).toSorted((a, b) => b.daysLate - a.daysLate))
const dispatches = computed(() => props.rows.filter((r) => r.kind === 'ambulance' && ['Booked', 'Responding'].includes(r.status)))
const availableUnits = computed(() => (props.vehicles ?? []).filter((v) => v.status === 'Available'))

// Sorted by filing time, a burst is a run where each request lands within the
// window of the one before it.
const duplicates = computed(() => {
  const byResident = new Map()
  for (const r of props.rows) {
    if (r.residentId) byResident.set(r.residentId, [...(byResident.get(r.residentId) ?? []), r])
  }

  const groups = []
  for (const [id, list] of byResident) {
    const sorted = list.toSorted((a, b) => a.filedAt - b.filedAt)
    let run = [sorted[0]]
    for (const r of sorted.slice(1)) {
      if (r.filedAt - run.at(-1).filedAt <= DUPLICATE_WINDOW_MIN * 60_000) {
        run.push(r)
      } else {
        if (run.length > 1) groups.push({ key: `${id}-${run[0].filedAt}`, name: run[0].name, rows: run })
        run = [r]
      }
    }
    if (run.length > 1) groups.push({ key: `${id}-${run[0].filedAt}`, name: run[0].name, rows: run })
  }
  return groups
})
</script>

<style scoped>
.soft-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  box-shadow: 0 1px 2px rgba(var(--v-theme-on-surface), 0.04), 0 4px 14px rgba(var(--v-theme-on-surface), 0.08);
}
.gap-4 {
  gap: 16px;
}
</style>
