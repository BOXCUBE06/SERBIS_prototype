<template>
  <v-container fluid class="fill-height align-start bg-background">
    <v-row class="ma-0 w-100">
      <v-col cols="12" class="pa-0 w-100">

        <PageHeader title="Service Vehicles" />

        <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6" density="comfortable" rounded="lg">
          {{ apiError }}
          <template v-slot:append>
            <v-btn variant="text" class="text-none font-weight-bold" @click="load">Retry</v-btn>
          </template>
        </v-alert>

        <v-card elevation="0" rounded="xl" class="checkbox-grid-card">
          <v-skeleton-loader v-if="initialLoad" type="table" class="pa-4"></v-skeleton-loader>

          <v-table v-else class="checkbox-grid">
            <thead>
              <tr>
                <th scope="col" class="text-left">Service</th>
                <th v-for="type in types" :key="type" scope="col" class="checkbox-grid-check">{{ type }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.code" :data-code="row.code">
                <th scope="row" class="text-left font-weight-bold checkbox-grid-service">
                  <span :class="{ 'text-disabled': !row.is_active }">{{ row.name }}</span>
                  <span v-if="!row.is_active" class="text-caption text-medium-emphasis ml-2">Switched off</span>
                  <span v-if="row.vehicle_types.length === 0" class="text-caption text-medium-emphasis ml-2">Any unit</span>
                </th>
                <td v-for="type in types" :key="type" class="checkbox-grid-check">
                  <v-checkbox-btn
                    :model-value="row.vehicle_types.includes(type)"
                    :disabled="saving === row.code"
                    :aria-label="`${type} can be sent on ${row.name}`"
                    color="primary"
                    @update:model-value="(checked) => toggle(row, type, checked)"
                  ></v-checkbox-btn>
                </td>
              </tr>
            </tbody>
          </v-table>
        </v-card>
      </v-col>
    </v-row>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3500" location="bottom right">
      {{ snackbar.text }}
    </v-snackbar>
  </v-container>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { authHeaders, useSnackbar } from '@/composables/adminUi'
import { API_BASE } from '@/config/api'
import PageHeader from '@/components/PageHeader.vue'
import '@/styles/checkbox-grid.css'

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

const load = async () => {
  apiError.value = ''
  try {
    const res = await fetch(`${API_BASE}/service-vehicle-types`, { headers: authHeaders() })
    if (!res.ok) throw new Error('Could not load the service vehicles.')
    const body = await res.json()
    rows.value = body.data
    types.value = body.types
  } catch (error) {
    apiError.value = error.message || 'Could not load the service vehicles.'
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

