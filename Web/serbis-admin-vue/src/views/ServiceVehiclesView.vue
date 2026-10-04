<template>
  <v-container fluid class="fill-height align-start bg-background">
    <PageHeader title="Service Vehicles">
      <template v-slot:subtitle>
        Choose which vehicle types can be sent on each service. With none checked, any available non-ambulance unit can be assigned.
      </template>
    </PageHeader>

    <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6 w-100" density="compact" rounded="lg">
      {{ apiError }}
      <template v-slot:append>
        <v-btn variant="outlined" color="primary" size="small" class="text-none font-weight-bold" @click="load">Retry</v-btn>
      </template>
    </v-alert>

    <div class="w-100">
      <DataTablePage
        :refreshing="refreshing"
        compact
        class="choice-grid"
        :searchable="false"
        :loading="initialLoad"
        :headers="headers"
        :items="rows"
        item-value="code"
        :items-per-page="50"
        :items-per-page-options="[50]"
        result-noun="services"
        no-data-text="No services dispatch a unit"
      >
        <template v-slot:item.name="{ item }">
          <div class="choice-grid-name">
            <span class="font-weight-bold" :class="item.is_active ? 'text-high-emphasis' : 'text-medium-emphasis'">{{ item.name }}</span>
            <span v-if="!item.is_active" class="text-caption text-medium-emphasis ml-2">Switched off</span>
          </div>
        </template>

        <template v-for="(type, i) in types" :key="type" v-slot:[`item.type${i}`]="{ item }">
          <div class="d-flex justify-center">
            <v-checkbox-btn
              :model-value="item.vehicle_types.includes(type)"
              :disabled="saving === item.code"
              :aria-label="`${type} can be sent on ${item.name}`"
              color="primary"
              @update:model-value="(checked) => toggle(item, type, checked)"
            ></v-checkbox-btn>
          </div>
        </template>
      </DataTablePage>
    </div>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3500" location="bottom right">
      {{ snackbar.text }}
    </v-snackbar>
  </v-container>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { authHeaders, useSnackbar } from '@/composables/adminUi'
import { API_BASE } from '@/config/api'
import { REFERENCE_TTL_MS, invalidate, useCachedFetch } from '@/composables/useCachedFetch'
import PageHeader from '@/components/PageHeader.vue'
import DataTablePage from '@/components/DataTablePage.vue'

// One row per service that dispatches a unit. Ticking a box saves at once, like
// the Service Audience page: there is no Save button, because a half-saved grid
// is worse than a slow one.
const rows = ref([])
const types = ref([])
const initialLoad = ref(true)
const apiError = ref('')
// The code of the row whose save is in flight; its boxes are disabled meanwhile.
const saving = ref(null)
const { snackbar, notify } = useSnackbar()

// Column keys are positional: a unit type like "Rescue Vehicle" has a space,
// which a slot name cannot carry. The types arrive with the rows, so three
// blank columns hold the skeleton's shape until then.
// Service takes 40%; the unit types split the other 60% equally.
const headers = computed(() => {
  const cols = types.value.length > 0 ? types.value : ['', '', '']
  return [
    { title: 'Service', key: 'name', sortable: false, width: '40%' },
    ...cols.map((t, i) => ({ title: t, key: `type${i}`, sortable: false, align: 'center', width: `${60 / cols.length}%` })),
  ]
})

const { get, refreshing } = useCachedFetch()

const load = async () => {
  apiError.value = ''
  try {
    await get('/service-vehicle-types', {
      ttl: REFERENCE_TTL_MS,
      onData: (body) => { rows.value = body.data; types.value = body.types; initialLoad.value = false },
    })
  } catch {
    apiError.value = 'Could not load the service vehicles.'
  } finally {
    initialLoad.value = false
  }
}

const toggle = async (row, type, checked) => {
  const before = [...row.vehicle_types]
  const next = checked ? [...before, type] : before.filter((t) => t !== type)

  row.vehicle_types = next
  saving.value = row.code

  try {
    const res = await fetch(`${API_BASE}/service-vehicle-types/${encodeURIComponent(row.code)}`, {
      method: 'PUT',
      headers: authHeaders(),
      body: JSON.stringify({ vehicle_types: next }),
    })
    const body = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(body.message || 'Could not save that change.')
    row.vehicle_types = body.vehicle_types
    invalidate('/service-vehicle-types')
    notify(`Saved: ${row.name}`)
  } catch (error) {
    row.vehicle_types = before
    notify(error.message, 'error')
  } finally {
    saving.value = null
  }
}

onMounted(load)
</script>

<style scoped>
/* Rows here do nothing on click; only the boxes do. */
.choice-grid.data-table-page :deep(tbody tr) { cursor: default; }
.choice-grid.data-table-page.dtp-compact :deep(tbody tr:hover) { background: rgba(var(--v-theme-on-surface), 0.03); }
/* Option headers and boxes centred over their column. */
.choice-grid.data-table-page :deep(thead th:not(:first-child) .v-data-table-header__content) { justify-content: center; }
.choice-grid.data-table-page :deep(tbody td:not(:first-child)) { text-align: center; }
/* Vuetify's selection control grows to fill the cell (flex: 1 0 auto), which
   parks the box at the cell's left edge; size it to the box so the wrapper
   can centre it. */
.choice-grid.data-table-page :deep(tbody td .v-selection-control) { flex: 0 0 auto; }
.choice-grid-name {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
