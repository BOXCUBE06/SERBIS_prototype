<template>
  <v-container fluid class="fill-height align-start bg-background">
    <PageHeader title="Service Audience">
      <template v-slot:subtitle>
        Choose which account types can request each service. An unchecked service is hidden from that account type in the mobile app and refused if filed.
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
        no-data-text="No services"
      >
        <template v-slot:item.name="{ item }">
          <div class="choice-grid-name">
            <span class="font-weight-bold" :class="item.is_active ? 'text-high-emphasis' : 'text-medium-emphasis'">{{ item.name }}</span>
            <span v-if="!item.is_service" class="text-caption text-medium-emphasis ml-2">App feature</span>
            <span v-else-if="!item.is_active" class="text-caption text-medium-emphasis ml-2">Switched off</span>
          </div>
        </template>

        <template v-for="type in ACCOUNT_TYPE_ITEMS" :key="type.value" v-slot:[`item.${type.value}`]="{ item }">
          <div class="d-flex justify-center">
            <v-checkbox-btn
              :model-value="item.account_types.includes(type.value)"
              :disabled="saving === item.code"
              :aria-label="`${type.title} may request ${item.name}`"
              color="primary"
              @update:model-value="(checked) => toggle(item, type.value, checked)"
            ></v-checkbox-btn>
          </div>
        </template>

        <template v-slot:summary>{{ summary }}</template>
      </DataTablePage>
    </div>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3500" location="bottom right">
      {{ snackbar.text }}
    </v-snackbar>
  </v-container>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { ACCOUNT_TYPE_ITEMS } from '@/composables/accountType'
import { authHeaders, useSnackbar } from '@/composables/adminUi'
import { API_BASE } from '@/config/api'
import PageHeader from '@/components/PageHeader.vue'
import DataTablePage from '@/components/DataTablePage.vue'

// One row per thing a resident can ask for, including the two that are not
// services (Equipment Borrowing, Others). Ticking a box saves at once: there is
// no Save button, because a half-saved grid is worse than a slow one.
const headers = [
  // Service takes 40%; the account types split the other 60% equally.
  { title: 'Service', key: 'name', sortable: false, width: '40%' },
  ...ACCOUNT_TYPE_ITEMS.map((t) => ({ title: t.title, key: t.value, sortable: false, align: 'center', width: `${60 / ACCOUNT_TYPE_ITEMS.length}%` })),
]

const rows = ref([])
const initialLoad = ref(true)
const apiError = ref('')
// The code of the row whose save is in flight; its boxes are disabled meanwhile.
const saving = ref(null)
const { snackbar, notify } = useSnackbar()

// Services and the two app features (Equipment Borrowing, Others) counted apart.
const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`
const summary = computed(() => {
  const services = rows.value.filter((r) => r.is_service).length
  return `${plural(services, 'service')} · ${plural(rows.value.length - services, 'app feature')}`
})

const load = async () => {
  apiError.value = ''
  try {
    const res = await fetch(`${API_BASE}/service-audience`, { headers: authHeaders() })
    if (!res.ok) throw new Error('Could not load the service audience.')
    rows.value = (await res.json()).data
  } catch (error) {
    apiError.value = error.message || 'Could not load the service audience.'
  } finally {
    initialLoad.value = false
  }
}

const toggle = async (row, type, checked) => {
  const before = [...row.account_types]
  const next = checked ? [...before, type] : before.filter((t) => t !== type)

  // An empty set would read as "open to everyone" on the server, the opposite
  // of an empty row of boxes, so it is refused here and there. The boxes are
  // controlled, so the tick simply does not take.
  if (next.length === 0) {
    notify('Keep at least one account type. To stop a service being requested, switch it off in Manage Services.', 'warning')
    return
  }

  row.account_types = next
  saving.value = row.code

  try {
    const res = await fetch(`${API_BASE}/service-audience/${encodeURIComponent(row.code)}`, {
      method: 'PUT',
      headers: authHeaders(),
      body: JSON.stringify({ account_types: next }),
    })
    const body = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(body.errors?.account_types?.[0] || body.message || 'Could not save that change.')
    row.account_types = body.account_types
    notify(`Saved: ${row.name}`)
  } catch (error) {
    row.account_types = before
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
